<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Xivi\ControlPlane\Controller;

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Pricing\ModulePrice;
use App\Registry\Pricing\ModulePricing;
use App\Registry\Pricing\PriceCurrency;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\View\ModuleListing;

/**
 * What this deployment charges for each module, and the screen an operator sets
 * it on (XIV-101).
 *
 * ## Why a screen, and why an operator
 *
 * Modules are free today and §6.3 says so. They should be sellable, and **what
 * they cost is not the code's business**: the company deploying Xivi decides what
 * its customers pay. So the price is a row in the control plane, and this is the
 * page that writes it — the same surface as the tenant list (§8.10) and the usage
 * figures (§8.11), reached by the same operator identity (§8.9).
 *
 * Two alternatives were ruled out by the ticket and both are worth writing down,
 * because both are what somebody reaches for first:
 *
 * * **An environment variable.** A price in `.env` is a price nobody can change
 *   without a deploy, and being able to change one without a deploy is the whole
 *   reason this exists. (The *currency* is an environment variable, and
 *   {@see PriceCurrency} explains at length why that argument does not transfer
 *   to it.)
 * * **A field on `ModuleBlueprint`.** A blueprint is code and ships identically
 *   to every deployment, so a price in `packages/invoice/` is a price every
 *   installation inherits and none of them chose. That is [XIV-7]'s argument
 *   about `ModuleState` word for word (§6.2).
 *
 * ## The boundary [XIV-58] keeps, kept
 *
 * **This page opens no tenant connection**, exactly like the one next door. Every
 * value on it is a `module` row of the control-plane database crossed with the
 * blueprints this build compiled in, and neither of those is a customer's data. A
 * control-plane request resolves no tenant at all (§8.9), so anything reaching
 * for the `tenant` connection here does not quietly get the previous customer's
 * database — it throws and the page 500s. `ModulePriceTest` asserts it the same
 * way `TenantListTest` does.
 *
 * ## The [XIV-96] split runs straight through this feature, on purpose
 *
 * Reading a price and setting one are on opposite sides of it. `App\Registry` —
 * including `ModuleCatalog`, `ModulePrice` and the two new columns — stays in
 * `src/`, because it is what a customer's own request needs in order to be served
 * at all, and it is therefore compiled into the customer-facing image. **This
 * controller is not**, because `packages/control-plane` is not: §4.4's builder
 * stage refuses to finish if the namespace survives anywhere under `/app`.
 *
 * And the guarantee underneath that is not the routing. §4.4 grants the
 * customer-facing instance's database role `SELECT` on the registry tables and
 * nothing else, so an `UPDATE module SET price_amount = …` arriving from the
 * process facing the internet is refused by PostgreSQL whatever a controller
 * there does. `ModuleCatalog::priceAt()` therefore joins `moveTo()` on the list
 * of writers that live in `src/` and are only ever called from the package —
 * §4.4 names that list and now names two.
 *
 * ## No `#[IsGranted]`, and nothing to grant
 *
 * `access_control` requires `ROLE_OPERATOR` for everything under
 * {@see ControlPlaneHost::PATH_PREFIX}, `ControlPlaneRequestListener` makes these
 * paths not exist on a customer's hostname, and the control-plane firewall is
 * what answers a credential here. An operator holds that role and only that role.
 * Inventing a "may set prices" permission before there is a second kind of
 * operator would be modelling a guess (§8.9).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePricingController extends AbstractController
{
    /**
     * One token for the page rather than one per module.
     *
     * The rows are all the same kind of thing and all posted from the same page
     * by the same person; a per-module token would be a different string with no
     * different property, since CSRF is about *this* browser having loaded *this*
     * page and not about which row on it was submitted.
     */
    private const string CSRF = 'module-price';

    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly PriceCurrency $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/modules', name: 'control_plane_modules', methods: ['GET'])]
    public function __invoke(): Response
    {
        $modules = array_map(
            fn (CatalogEntry $entry): ModuleListing => ModuleListing::of($entry, $this->translator),
            $this->catalog->entries(),
        );

        return $this->render('@XiviControlPlane/modules.html.twig', [
            'modules' => $modules,

            // The code, never a symbol and never a formatted string — §8.6's rule
            // for a customer's currency, and there is no reason for the platform's
            // own to be held differently. Null when nobody has set PRICE_CURRENCY,
            // which the page says out loud rather than papering over.
            'currency' => $this->currency->code(),

            // Passed separately rather than filtered in the template, because it
            // is the page's headline: a module that is published and unpriced is
            // invisible in every customer's store, and the reason is nowhere on
            // the customer's screen. Drawn only when the count is not zero — a
            // banner that always says "0" is furniture, which is XIV-58's
            // argument for the same shape one page over.
            'unpriced' => array_values(array_filter(
                $modules,
                static fn (ModuleListing $module): bool => $module->isPublishedButUnpriced(),
            )),
        ]);
    }

    /**
     * Sets one module's price.
     *
     * **POST and a redirect**, never a rendered response: this writes, and a
     * write that answers with a page is a write somebody repeats by pressing
     * reload. The flash carries what happened to the redirected page.
     *
     * The amount is read as a string all the way through — the request parameter
     * is a string, `ModulePrice::of()` parses it with `brick/math`, and the column
     * is `NUMERIC`. There is no `(float)` anywhere on that path, which is §5.9's
     * rule and is the one thing about this method worth guarding in review.
     */
    #[Route(
        ControlPlaneHost::PATH_PREFIX . '/modules/{key}/price',
        name: 'control_plane_module_price',
        requirements: ['key' => '[a-z0-9_]+'],
        methods: ['POST'],
    )]
    public function price(Request $request, string $key): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('control_plane.module_price_stale'));

            return $this->redirectToRoute('control_plane_modules');
        }

        $pricing = ModulePricing::tryFrom((string) $request->request->get('pricing'));

        if ($pricing === null) {
            // Not a validation message anybody should ever read: the form offers
            // four radios and nothing else. It exists because a hand-made POST is
            // a thing, and "the price silently did not change" is the worst
            // available answer to one.
            $this->addFlash('error', $this->translator->trans('control_plane.module_price_unknown_pricing'));

            return $this->redirectToRoute('control_plane_modules');
        }

        try {
            $price = $pricing->needsAmount()
                ? ModulePrice::of(trim((string) $request->request->get('amount')))
                : ModulePrice::fromStorage($pricing, null);

            $this->catalog->priceAt($key, $price);
        } catch (\InvalidArgumentException $e) {
            // Both refusals reach here and both are worth showing verbatim: "that
            // is not a number", "a priced module has to cost more than nothing",
            // and "no module by that key in this build" are all sentences that
            // tell an operator what to do next. They are written in the domain
            // rather than in the catalogue, so they are already in the language a
            // person can act on — and they are not translated, for the same
            // reason `module:state`'s refusals are not: an argument exception is
            // an operator-facing diagnostic, not page copy.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('control_plane_modules');
        }

        $this->addFlash('success', $this->translator->trans(
            'control_plane.module_price_saved',
            ['%module%' => $key],
        ));

        return $this->redirectToRoute('control_plane_modules');
    }
}

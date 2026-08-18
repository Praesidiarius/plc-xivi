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

namespace App\Controller;

use App\Registry\Pricing\PriceCurrency;
use App\Store\ModuleStore;
use App\Store\PurchaseRefused;
use App\Store\StoreInstallRefused;
use App\Store\StoreOffer;
use App\Tenant\Entity\User;
use App\Tenant\Security\StoreAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The store: four screens that let a customer add a module without anybody
 * running a command against their database — and, for a module that costs money,
 * ask for one instead (XIV-6, XIV-102).
 *
 * That last clause is the whole feature. A customer who signs up lands in an
 * empty installation, and until this existed the only way to put anything in it
 * was `tenant:module:install` — which is the operator's shell, not theirs. The
 * command has not gone anywhere: a headless deployment keeps its path, and both
 * go through the same `ModuleInstaller` so that a module installed here and a
 * module installed there are the same module.
 *
 * **Granted on the store axis, not on a module's** (§8.4.3). Browsing is about no
 * module at all and installing is about a module the customer does *not* have, so
 * there is nothing for a per-module grant to attach to. The `store` argument comes
 * out of the route's own defaults purely so `#[IsGranted]` has a subject to name,
 * exactly as {@see TenantProfileController} does with its area — the check has to
 * happen before the action runs. Every action therefore takes a `$store` it never
 * reads; the attribute resolves its subject from the controller's arguments, so
 * the parameter has to be there for the check to have anything to point at.
 *
 * `{module}` in the path is a *catalogue* key rather than an installed module, so
 * these routes carry a store grant where everything else under `{module}` carries
 * a ModuleAction one. `PermissionCoverageTest` knows about both and still fails
 * the build for a route that names neither.
 *
 * **The fourth screen takes no payment and never will** (XIV-102). There is no
 * gateway in this system — that is a decision with compliance weight attached and
 * it is not this one's — so what {@see buy()} does is write down that somebody
 * asked and tell them plainly that this is what happened. It carries
 * {@see StoreAction::Buy}, which is a grant of its own and not `install`'s: the
 * authority to decide what this installation consists of and the authority to
 * commit the company to a payment are different authorities, and the enum case
 * has the argument.
 *
 * **Hand-rolled POST and CSRF rather than a FormType**, matching
 * {@see ModuleController}, {@see DocumentController}, {@see EmailTemplateController}
 * and {@see PermissionGroupController}. A deliberate departure from the Symfony
 * default, and the neighbours' one: the wizard is a radio group and a button, and
 * a FormType here would be the only one in this application.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/store', defaults: ['store' => StoreAction::SUBJECT])]
final class ModuleStoreController extends AbstractController
{
    private const string CSRF = 'install-module';

    /**
     * A token of its own for the purchase request, unlike the pricing screen
     * which uses one for the whole page.
     *
     * Not symmetry with `CSRF` above for its own sake: the two forms do genuinely
     * different things and live on different screens, and a shared token would
     * mean a page that only offers one of them still carrying a token that
     * validates the other. Cheap, and the property is worth having on the pair of
     * routes where one installs and one does not.
     */
    private const string CSRF_BUY = 'buy-module';

    public function __construct(
        private readonly ModuleStore $store,
        private readonly TranslatorInterface $translator,
        /**
         * The deployment's own selling currency, and deliberately **not** the
         * tenant profile's (§6.5). A customer who invoices in EUR is still quoted
         * whatever this installation sells in; rendering a price list through the
         * reader's currency would relabel francs as euros, which is the same
         * digits making a different claim.
         */
        private readonly PriceCurrency $currency,
    ) {
    }

    /** Everything this build offers, each saying whether it is theirs already. */
    #[Route('', name: 'store_index', methods: ['GET'])]
    #[IsGranted(StoreAction::Browse->value, subject: 'store')]
    public function index(string $store): Response
    {
        return $this->render('store/index.html.twig', [
            'offers' => $this->store->offers(),
            'currency' => $this->currency->code(),
        ]);
    }

    /** What one module is, what it can be installed as, and what it needs. */
    #[Route('/{module}', name: 'store_module', requirements: ['module' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    #[IsGranted(StoreAction::Browse->value, subject: 'store')]
    public function show(string $module, string $store): Response
    {
        return $this->render('store/show.html.twig', [
            'offer' => $this->offer($module),
            'currency' => $this->currency->code(),
        ]);
    }

    /**
     * The wizard: choose a preset, see what it contains, confirm.
     *
     * GET and POST on one route, as the neighbours do. The refusals are handled
     * the same way on both — a store page kept open while somebody else installs
     * the same module is a real sequence, so the state the wizard was drawn from
     * is never guaranteed to be the state the install happens in.
     */
    #[Route('/{module}/install', name: 'store_install', requirements: ['module' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    #[IsGranted(StoreAction::Install->value, subject: 'store')]
    public function install(string $module, string $store, Request $request): Response
    {
        $offer = $this->offer($module);

        // A submitted install always goes to the store and is refused there when
        // it has to be, rather than being turned away here on the strength of the
        // same page's own reading. The two differ by whatever happened in
        // between — a colleague installing the same module in the next tab — and
        // the refusal is a sentence somebody should read, not a silent redirect.
        if ($this->submitted($request)) {
            try {
                $this->store->install(
                    $offer,
                    $this->chosenPreset($request, $offer),
                    $request->getLocale(),
                    $this->wantsFollowUps($request),
                );

                $this->addFlash('success', $this->translator->trans('flash.module_installed', ['%module%' => $offer->label]));
            } catch (StoreInstallRefused $refused) {
                $this->addFlash('warning', $refused->translatable()->trans($this->translator));
            }

            // Back to the module's page either way, which now says they have it.
            // Not to the module itself: nobody holds a permission on a module
            // that did not exist a moment ago, so sending them there would be an
            // invitation to a 403 (§8.4.3).
            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        // Nothing to choose and nothing to confirm: the module is theirs already,
        // or it needs something they have not got. The module's own page is where
        // both of those are written out, so the wizard sends them to read it.
        if (!$offer->isInstallable()) {
            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        return $this->render('store/install.html.twig', [
            'offer' => $offer,
            'chosen' => $this->chosenPreset($request, $offer),
        ]);
    }

    /**
     * The placeholder: what a module costs, what pressing the button does, and
     * what it emphatically does not do (XIV-102).
     *
     * **This page is not a checkout and must never grow into one.** It collects
     * no card details, shows no total, has no "processing" state and makes no
     * claim that anything has been charged, and each of those is a deliberate
     * absence rather than a feature not built yet. A form that looks like payment
     * and quietly does nothing is worse than a sentence saying what is actually
     * going on, because it teaches people to type card numbers into a page that
     * does not take them — which is a habit worth not creating in somebody's
     * business software.
     *
     * What it does is what §8.15 argues for: record an intent, install nothing,
     * and say so. `POST` writes one row into the customer's own database, and the
     * redirect lands them back on the module's page, which then says they have
     * asked.
     *
     * GET and POST on one route, as the wizard next door does, and the refusals
     * are handled identically on both — a page kept open while an operator moves
     * a module to free, or while a colleague installs it, is an ordinary sequence
     * and deserves a sentence rather than a 404.
     */
    #[Route('/{module}/buy', name: 'store_buy', requirements: ['module' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    #[IsGranted(StoreAction::Buy->value, subject: 'store')]
    public function buy(string $module, string $store, Request $request): Response
    {
        $offer = $this->offer($module);

        if ($this->submitted($request, self::CSRF_BUY)) {
            try {
                // `getUser()` rather than a `#[CurrentUser]` argument, because
                // null is a state this method has to survive: the firewall makes
                // it unreachable in practice, and `PurchaseRequests` writes a
                // dash rather than crashing if that ever stops being true.
                $user = $this->getUser();

                $this->store->requestPurchase($offer, $user instanceof User ? $user : null);

                $this->addFlash('success', $this->translator->trans(
                    'flash.purchase_requested',
                    ['%module%' => $offer->label],
                ));
            } catch (PurchaseRefused $refused) {
                $this->addFlash('warning', $refused->translatable()->trans($this->translator));
            }

            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        // Nothing to ask for: they have it, it does not cost money, or it needs
        // something they have not got. The module's own page writes all three out
        // properly, so this sends them there rather than drawing a page whose
        // only content is a refusal.
        if (!$offer->isBuyable()) {
            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        return $this->render('store/buy.html.twig', [
            'offer' => $offer,
            // The code, never a symbol and never a locale-formatted string — see
            // the template, which has the argument for why a price list is drawn
            // the way it is stored. Null when this deployment has never set
            // PRICE_CURRENCY, which the page handles by showing a bare number.
            'currency' => $this->currency->code(),
        ]);
    }

    /**
     * Which preset the form is asking for, or null for "the module's own
     * default".
     *
     * Null is a real answer rather than a missing one: a module shipping no
     * presets installs every field it has, which is what the command does with no
     * `--preset`. An unrecognised value is passed through rather than corrected —
     * {@see ModuleStore::install()} refuses it, because quietly installing a
     * different shape from the one somebody asked for is the worst outcome of a
     * decision nothing can undo.
     */
    private function chosenPreset(Request $request, StoreOffer $offer): ?string
    {
        $chosen = trim((string) $request->request->get('preset', $request->query->get('preset', '')));

        if ($chosen !== '') {
            return $chosen;
        }

        return $offer->presets === [] ? null : $offer->blueprint->defaultPreset;
    }

    /**
     * Whether the wizard is asking for follow-ups on this module (XIV-80).
     *
     * **An unticked checkbox and a form that never asked look identical on the
     * wire** — both send nothing at all — and they have to mean opposite things
     * here, because the default is on. So the wizard posts a hidden marker beside
     * the box: with the marker, silence is an unticked box; without it, this is a
     * request that predates the question and gets the default. Reading the
     * checkbox alone would quietly install every module without the feature the
     * first time somebody posted the form by hand.
     *
     * Unlike the preset, nothing here is permanent: this is a boolean on the
     * customer's module definition and the toggle outlives the wizard.
     */
    private function wantsFollowUps(Request $request): bool
    {
        if (!$request->request->has('follow_ups_asked')) {
            return true;
        }

        return $request->request->getBoolean('follow_ups');
    }

    private function offer(string $module): StoreOffer
    {
        return $this->store->offer($module) ?? throw $this->createNotFoundException();
    }

    private function submitted(Request $request, string $token = self::CSRF): bool
    {
        return $request->isMethod('POST')
            && $this->isCsrfTokenValid($token, (string) $request->request->get('_token'));
    }
}

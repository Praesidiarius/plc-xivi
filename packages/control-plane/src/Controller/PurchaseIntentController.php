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

use App\Registry\Pricing\PriceCurrency;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\PurchaseIntent;
use Xivi\ControlPlane\Repository\PurchaseIntentRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\View\PurchaseIntentListing;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Who has asked to buy what, and nobody has done anything about it yet
 * (XIV-102).
 *
 * ## The screen the whole ticket exists for
 *
 * [XIV-101] gave a module a price. This is where the consequence lands: a
 * customer presses a button in their store, the store installs **nothing**, and
 * the request turns up here for a person to act on. That separation is
 * [XIV-64]'s — *"anyone may ask" and "the thing happens" are deliberately not the
 * same event* — and reusing it means the day a real payment gateway arrives it
 * slots in where this operator currently stands, rather than replacing a flow
 * built around pretending to take money.
 *
 * **There is no button on this page.** An operator fulfils a request by
 * installing the module (`tenant:module:install`), and the next collection
 * observes that and marks the row. A "mark as done" control would be a second
 * copy of a fact the customer's own database already holds — [XIV-98]'s argument
 * against a `provisioned` status on a signup, and it is the same argument.
 *
 * ## Where the rows come from, which is the interesting half
 *
 * Not from here. §4.4 grants the customer-facing instance's database role
 * `SELECT` on the registry tables and no write privilege anywhere, so a
 * customer's own request **cannot** write a control-plane row — which means the
 * request itself lives in their database and `tenant:purchase:collect` copies it
 * across. {@see \Xivi\ControlPlane\Purchase\PurchaseIntentCollector} has the
 * argument and the alternatives that were rejected.
 *
 * The consequence for this page is that **every row is as old as the last
 * collection**, and it says so beside every row rather than presenting the list
 * as live. That is §8.11's rule about the usage figures, which are here for the
 * same reason and got it for the same reason.
 *
 * ## The boundary [XIV-58] keeps, kept
 *
 * **This page opens no tenant connection.** Every value on it is a
 * `purchase_intent` row crossed with the blueprints this build compiled in, and a
 * control-plane request resolves no tenant at all (§8.9) — so anything reaching
 * for the tenant connection here does not quietly get the previous customer's
 * database, it throws and the page 500s. `PurchaseIntentTest` asserts it the same
 * way `TenantListTest` and `ModulePriceTest` do.
 *
 * And the `Tenant` entity does not reach the template, for §8.10's reason: a
 * tenant row carries the customer's encrypted database credential, and the
 * defence against that reaching a page is a type. {@see PurchaseIntentListing} is
 * that type.
 *
 * ## No `#[IsGranted]`, and nothing to grant
 *
 * `access_control` requires `ROLE_OPERATOR` for everything under
 * {@see ControlPlaneHost::PATH_PREFIX}, `ControlPlaneRequestListener` makes these
 * paths not exist on a customer's hostname, and an operator holds that role and
 * only that role. Inventing a "may see purchase requests" permission before there
 * is a second kind of operator would be modelling a guess (§8.9) — which is the
 * same sentence {@see ModulePricingController} carries, and the same answer.
 *
 * Worth noticing that the *customer's* side of this feature does have a
 * permission of its own ({@see \App\Tenant\Security\StoreAction::Buy}), and the
 * asymmetry is not an inconsistency: a tenant has many users with different
 * authority over the company's money, and this installation has operators, all
 * of whom are the company running Xivi.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PurchaseIntentController extends AbstractController
{
    public function __construct(
        private readonly PurchaseIntentRepository $intents,
        private readonly ModuleRegistry $registry,
        private readonly PriceCurrency $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(ControlPlaneHost::PATH_PREFIX . '/purchases', name: 'control_plane_purchases', methods: ['GET'])]
    public function __invoke(): Response
    {
        $requests = array_map(
            fn (PurchaseIntent $intent): PurchaseIntentListing => PurchaseIntentListing::of(
                $intent,
                $this->registry,
                $this->translator,
            ),
            $this->intents->allOutstandingFirst(),
        );

        return $this->render('@XiviControlPlane/purchases.html.twig', [
            'requests' => $requests,

            // Passed separately rather than filtered in the template, because it
            // is the page's headline: these are the people waiting. Drawn only
            // when it is not zero — a banner permanently reading "0 outstanding"
            // is furniture, which is [XIV-58]'s argument for the same shape two
            // pages over.
            'outstanding' => array_values(array_filter(
                $requests,
                static fn (PurchaseIntentListing $request): bool => $request->isOutstanding(),
            )),

            // The code, never a symbol and never a formatted string — §8.6's rule
            // for a customer's currency, and the platform's own is held the same
            // way. Null when nobody has set PRICE_CURRENCY, which the page says
            // out loud here because an operator is somebody who can go and set it.
            'currency' => $this->currency->code(),
        ]);
    }
}

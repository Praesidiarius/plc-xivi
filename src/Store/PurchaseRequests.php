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

namespace App\Store;

use App\Registry\Pricing\PriceCurrency;
use App\Tenant\Entity\ModulePurchaseIntent;
use App\Tenant\Entity\User;
use App\Tenant\Repository\ModulePurchaseIntentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing down that somebody wants to buy a module, and doing nothing else
 * whatsoever (XIV-102).
 *
 * **The "nothing else" is the class.** [XIV-64] answered the same question one
 * layer up — a public surface records an intent and performs no privileged act,
 * and a non-public process is what acts — and the reason it gave was not that the
 * privileged act was dangerous but that *"anyone may ask" and "the thing happens"
 * are deliberately not the same event*. That sentence is why this exists as its
 * own service rather than as a branch inside {@see ModuleStore::install()}: a
 * method that installs a module in one arm and records a wish in the other is one
 * refactor away from doing both, and the whole value of this ticket is that it
 * does not.
 *
 * So: one row, in the customer's own database, and no installer anywhere in the
 * constructor graph. {@see ModulePurchaseIntent} has the argument for why that
 * database and not the control plane's — §4.4's grant leaves no alternative, and
 * it turns out to be where the row belonged anyway.
 *
 * ## What it copies, and why copying rather than pointing
 *
 * The price, at the moment of asking, as a decimal string and a currency code.
 * [XIV-101] left that as an instruction rather than a suggestion (§6.5): *nothing
 * about a sale is ever recomputed from the module row afterwards*, the same rule
 * an invoice follows for its own totals (§5.9) and its own due date (§5.16).
 *
 * The currency comes from {@see PriceCurrency}, which is the deployment's and
 * emphatically not the tenant profile's — a customer who invoices in EUR is still
 * quoted in whatever this installation sells in, and §6.5 has the two ways that
 * confusion fails. When the deployment has never said, the copy is null and the
 * figure was shown bare. Guessing a currency here would be inventing one on the
 * single row somebody might later read as a commitment.
 *
 * ## Asking twice
 *
 * Rewrites the row rather than adding one, and refreshes the copied price with
 * it. See {@see ModulePurchaseIntent::reissue()}; the short version is that a
 * second press is somebody asking again because nobody replied, and an operator's
 * queue full of duplicates is a queue that stops being read.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PurchaseRequests
{
    public function __construct(
        private ModulePurchaseIntentRepository $intents,
        private PriceCurrency $currency,
        /**
         * **The customer's own database, named rather than autowired.**.
         *
         * The default entity manager is the control plane's, so a bare
         * `EntityManagerInterface` here would persist this row into the
         * *registry* — and on a customer-facing instance §4.4's grant would then
         * refuse it, which is the good outcome, and on the internal one it would
         * quietly succeed, which is not. Every writer in `src/Tenant` says which
         * manager it means for exactly this reason.
         */
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every request this customer has outstanding, keyed by module.
     *
     * Read once per page by {@see ModuleStore} rather than once per tile: the
     * store draws every offer it has and asking per module would be a query per
     * module, on a page whose entire content is already one array.
     *
     * @return array<string, ModulePurchaseIntent>
     */
    public function byModule(): array
    {
        return $this->intents->allByModule();
    }

    /**
     * Records that this customer wants the module, at the price they were shown.
     *
     * Refuses anything the placeholder screen would not have offered, on its own
     * account rather than on the screen's — the same relationship
     * {@see StoreInstallRefused} describes between the wizard and the install.
     * A GET kept open while a colleague installs the module, or an operator moves
     * it to free, or a retyped POST, all arrive here and all get an answer that
     * is a sentence rather than a row.
     *
     * @throws PurchaseRefused when the module is not one that can be bought right
     *                         now — already installed, not actually priced, or
     *                         missing something it needs
     */
    public function record(StoreOffer $offer, ?User $requester): ModulePurchaseIntent
    {
        if ($offer->installed) {
            throw PurchaseRefused::alreadyInstalled($offer->label);
        }

        if (!$offer->price->costsMoney()) {
            // Free, unpriced or withdrawn from sale. Free is the interesting one
            // and it is a real sequence rather than a hand-typed request: a
            // customer opens the page on a module that costs money, an operator
            // makes it free while they are reading, and the button they press is
            // now asking to buy something that is being given away. Refusing and
            // saying so sends them back to a page with an install button on it,
            // which is the answer they wanted.
            throw PurchaseRefused::notForSale($offer->label);
        }

        $missing = $offer->missingRequirements();

        if ($missing !== []) {
            // Checked here as well as on the install path, and for a reason that
            // is not symmetry: an operator who fulfils this request by installing
            // the module would hit the engine's own refusal (XIV-23) at that
            // moment instead, having already had the conversation about money.
            // Better that the customer is told now, on a page that names what is
            // missing and links to it.
            throw PurchaseRefused::requirementsMissing($offer->label, array_map(
                static fn (Requirement $requirement): string => $requirement->label,
                $missing,
            ));
        }

        $intent = $this->intents->findOneByModule($offer->key());

        if ($intent === null) {
            $intent = new ModulePurchaseIntent(
                $offer->key(),
                $offer->price,
                $this->currency->code(),
                $requester?->getId(),
                self::labelOf($requester),
            );
            $this->entityManager->persist($intent);
        } else {
            $intent->reissue(
                $offer->price,
                $this->currency->code(),
                $requester?->getId(),
                self::labelOf($requester),
            );
        }

        $this->entityManager->flush();

        return $intent;
    }

    /**
     * What to write down about who asked.
     *
     * Copied rather than joined, which is {@see \App\Tenant\Entity\FollowUp}'s
     * decision and is right here for the same reason: the name on a request is
     * the name it was made under, and somebody who has since been renamed or has
     * left should not silently rewrite or erase their own request. Null is
     * possible in principle — a console caller, a future automation — and gets a
     * word rather than an empty cell, because a blank in this column reads as a
     * bug rather than as an answer.
     */
    private static function labelOf(?User $requester): string
    {
        return $requester?->getName() ?? '—';
    }
}

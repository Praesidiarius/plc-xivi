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

namespace Xivi\ControlPlane\View;

use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\PurchaseIntent;
use Xivi\Core\Module\ModuleRegistry;

/**
 * One purchase request as the operator's screen draws it (XIV-102).
 *
 * A view model for {@see TenantSummary}'s reason, which is the alarming one
 * rather than {@see ModuleListing}'s: a {@see PurchaseIntent} holds a `Tenant`,
 * and a `Tenant` holds the customer's **encrypted database credential**. §8.10's
 * whole defence is that such a row cannot reach an HTML page, and the way that is
 * guaranteed is that the template is never handed one. So the slug crosses and
 * the entity does not.
 *
 * It also answers the awkward question once, in PHP, where the answer can carry a
 * comment: **what is this module called?** A control-plane request resolves no
 * tenant (§8.9), so there are no customer definitions to read a label out of, and
 * a blueprint's label is a translation key. `ListModulesCommand` worked out that
 * the platform's default language is the only honest thing to render one in, and
 * {@see ModuleListing} does the same thing one page over.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PurchaseIntentListing
{
    public function __construct(
        public string $tenantSlug,
        public string $tenantName,
        public string $moduleKey,
        /** The blueprint's label in the platform's default language, or the key when it has none. */
        public string $moduleName,
        /** The decimal string the customer was shown, copied from their row (§5.9). */
        public string $amount,
        /** The ISO 4217 code it was shown in, or null when this deployment has never set one. */
        public ?string $currency,
        public \DateTimeImmutable $requestedAt,
        public \DateTimeImmutable $firstRequestedAt,
        /** Whether the customer had the module when this collection ran — observed, not reported. */
        public bool $installed,
        public \DateTimeImmutable $collectedAt,
    ) {
    }

    public static function of(
        PurchaseIntent $intent,
        ModuleRegistry $registry,
        TranslatorInterface $translator,
    ): self {
        $key = $intent->getModuleKey();

        // A module this build no longer ships can still be sitting in somebody's
        // queue — they asked when it was there — so the key stands in for the
        // name rather than an em dash. It is visibly a key and therefore visibly
        // worth asking about, which is the state an operator is here to find.
        $blueprint = $registry->has($key) ? $registry->get($key) : null;

        return new self(
            $intent->getTenant()->getSlug(),
            $intent->getTenant()->getName(),
            $key,
            $blueprint === null ? $key : $translator->trans($blueprint->label, [], $blueprint->domain()),
            $intent->getPriceAmount(),
            $intent->getPriceCurrency(),
            $intent->getRequestedAt(),
            $intent->getFirstRequestedAt(),
            $intent->isInstalled(),
            $intent->getCollectedAt(),
        );
    }

    /**
     * Whether somebody is still waiting on this.
     *
     * The whole state machine, and it has no column: a request whose module is
     * installed has been answered, and nothing here uninstalls anything (§6.2).
     * See {@see PurchaseIntent} for why a status would have been a second copy of
     * a fact the customer's own database already holds.
     */
    public function isOutstanding(): bool
    {
        return !$this->installed;
    }
}

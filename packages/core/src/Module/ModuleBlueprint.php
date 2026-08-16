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

namespace Xivi\Core\Module;

use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Mail\MailRecipient;
use Xivi\Core\Money\LineTotals;
use Xivi\Core\Payment\PaymentTerms;
use Xivi\Core\Seed\Seed;

/**
 * What a module declares about itself in code, before any customer has it.
 *
 * A blueprint is not the definition — it is the seed the installer writes into a
 * tenant's database once. From then on the customer's copy is what counts, and
 * it may have grown fields the blueprint never mentioned (§6). That separation
 * is what lets two customers run the same module with different shapes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleBlueprint
{
    /**
     * @param list<FieldBlueprint>      $fields
     * @param list<CollectionBlueprint> $collections   child rows this module owns,
     *                                                 such as a contact's addresses
     * @param list<ModulePreset>        $presets       named subsets of `fields` a
     *                                                 customer can be installed with (§6.1)
     * @param string|null               $defaultPreset which one applies when nobody
     *                                                 chooses; null installs every field
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $table,
        public array $fields,
        public array $collections = [],
        public array $presets = [],
        public ?string $defaultPreset = null,
        /** Bootstrap Icons name, without the `bi-` prefix. */
        public ?string $icon = null,
        /**
         * The key of the choice field deciding which variant a record is (§5.5).
         * Null for a module whose records are all the same thing.
         */
        public ?string $variantField = null,
        /**
         * The catalogue its labels are keys in, read once at install time
         * (XIV-8). Null uses the module's own key, so a module that ships
         * `contact.en.yaml` beside its bundle needs to say nothing here.
         */
        public ?string $translationDomain = null,
        /**
         * The states its records move through, and the moves allowed between
         * them (XIV-14). Null for a module whose records simply are.
         */
        public ?Lifecycle $lifecycle = null,
        /**
         * Where the money is, for a module whose records have lines (XIV-19).
         * Null for one that sells nothing.
         */
        public ?LineTotals $lineTotals = null,
        /**
         * That a record of this one is made from a record of another (XIV-19) —
         * an invoice from an order. Null for a module somebody starts from
         * nothing, which is most of them.
         */
        public ?Seed $seed = null,
        /**
         * Where a mail about one of these records goes (XIV-39) — a contact's
         * own address, or the address of the contact an invoice names. Null for
         * a module nobody writes to, which an articles list is.
         *
         * The engine cannot work this out and does not try: which field holds an
         * address is a fact about this module's shape, the same way `$seed` and
         * `$lineTotals` are, so it is declared here beside them.
         */
        public ?MailRecipient $mailRecipient = null,
        /**
         * When one of these records falls due, and where the number of days
         * comes from (XIV-67). Null for a module nobody is billed by, which is
         * every one of them but the invoice.
         *
         * Declared here beside `$lineTotals` on purpose: a due date is a derived
         * value stored on the document, for the reason §5.9 gives about totals,
         * so it is described the same way and materialised by the same seam.
         */
        public ?PaymentTerms $paymentTerms = null,
        /**
         * Modules this one cannot work without (XIV-23).
         *
         * A *runtime* requirement, not the code dependency §3 forbids: these are
         * keys, and this package imports nothing from the packages behind them.
         * An order cannot exist without a contact to name, so installing it into
         * a customer who has no contacts is refused rather than allowed to
         * produce a module nobody can save a record in.
         *
         * @var list<string>
         */
        public array $requires = [],
        /**
         * Modules this one works better with, and works without.
         *
         * A service business sells custom lines and never opens an Articles
         * module. Installing is allowed; the parts that need the missing module
         * — a kind of row whose required link points at it — are not offered.
         *
         * @var list<string>
         */
        public array $uses = [],
    ) {
    }

    /** @see $translationDomain */
    public function domain(): string
    {
        return $this->translationDomain ?? $this->key;
    }

    public function preset(string $key): ?ModulePreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function presetKeys(): array
    {
        return array_map(static fn (ModulePreset $p): string => $p->key, $this->presets);
    }
}

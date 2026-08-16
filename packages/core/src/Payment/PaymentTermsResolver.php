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

namespace Xivi\Core\Payment;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordRepository;

/**
 * How many days this particular document gets, out of the three layers that
 * could say (XIV-67).
 *
 * The reading half of {@see PaymentTerms}, the way {@see
 * \Xivi\Core\Mail\RecipientResolver} is the reading half of `MailRecipient` and
 * {@see \Xivi\Core\Money\DerivesTotals} is the reading half of `LineTotals`: the
 * declaration ships in the module's own package and everything that knows how to
 * follow it is here.
 *
 * ### Three layers, defaulting downward
 *
 * The customer's own term wins; the installation's applies to everybody else;
 * and when nobody has said anything at all the answer is **null**, which is not
 * an error — see {@see DefaultPaymentTerms::days()} for why a guess would be the
 * worse failure.
 *
 * That is the shape XIV-50 already uses for language and region, arrived at a
 * third time. It is the project's pattern rather than a new idea, so it is worth
 * saying what makes it one: the layer above always *overrides* rather than
 * *combines*, so reading the effective value is a `??` chain and never an
 * arithmetic nobody can reproduce from the screens they were typed on.
 *
 * ### Following the hop is a read of the other module, and it is unscoped
 *
 * The same split XIV-42 made and `RecipientResolver` inherited. Whoever may send
 * an invoice may know when it falls due, or "may send invoices" would quietly be
 * two permissions with the second one unnameable. The number never appears
 * anywhere the reader could not already have reached: what is shown is the
 * *invoice's own* date, on the invoice's own page, which the view grant is what
 * opens.
 *
 * There is also nothing to leak in the other direction. The contact's term is
 * read once, at the moment the document is sent, and what is stored afterwards is
 * a date on the invoice — so a customer's payment terms are not restated on every
 * document that was ever addressed to them.
 *
 * ### The contact is read by key, never by class
 *
 * `invoice` declares `requires: [order, contact]`, which is a *metadata*
 * requirement (XIV-23) and not a code dependency — deptrac forbids one module
 * package importing another. So the hop lands on whatever module the reference
 * field names in the customer's own definitions, and this reads a field key off a
 * `ModuleDefinition`. Nothing here knows that a contact exists, and the invoice
 * package names it in the same string it already names it in for its mail
 * recipient and its seed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PaymentTermsResolver
{
    public function __construct(
        private ModuleRegistry $modules,
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private DefaultPaymentTerms $default,
    ) {
    }

    /**
     * What this module declares about when its documents fall due, if anything
     * it can still act on.
     *
     * Null covers three cases that are one case to a caller: a build without the
     * module, a module that never declared terms, and a customer who has deleted
     * one of the two fields the declaration is *about* — the due date itself or
     * the date it counts from. None of them is a fault; all three mean this shape
     * has no due dates, which §5.4 explicitly allows somebody to arrange.
     *
     * The field holding the *terms* is deliberately not checked here: it may be
     * missing on the other module and the installation default still applies, so
     * its absence narrows the answer rather than removing it.
     */
    public function declaredFor(ModuleDefinition $module): ?PaymentTerms
    {
        $key = $module->getKey();

        if (!$this->modules->has($key)) {
            return null;
        }

        $declared = $this->modules->get($key)->paymentTerms;

        if ($declared === null) {
            return null;
        }

        return $module->getField($declared->dueDate) !== null && $module->getField($declared->from) !== null
            ? $declared
            : null;
    }

    /**
     * Which lifecycle field carries the state the money is owed in.
     *
     * Read off the module's own {@see \Xivi\Core\Lifecycle\Lifecycle} rather than
     * repeated on the declaration, so the two cannot name different fields. A
     * module with terms and no lifecycle has no moment at which a document
     * becomes owed, and gets null.
     */
    public function stateFieldOf(ModuleDefinition $module): ?string
    {
        $key = $module->getKey();

        if (!$this->modules->has($key)) {
            return null;
        }

        $lifecycle = $this->modules->get($key)->lifecycle;

        return $lifecycle !== null && $module->getField($lifecycle->field) !== null ? $lifecycle->field : null;
    }

    /**
     * The days that apply to this record right now: the customer's own, else the
     * installation's, else none.
     *
     * Takes values rather than a `Record` because its caller is a save that has
     * not written one yet — {@see \Xivi\Core\Record\Derivation} is fields and
     * rows, and handing it a record would mean building one to be read once.
     *
     * @param array<string, mixed> $values the record's own values
     */
    public function daysFor(ModuleDefinition $module, array $values): ?int
    {
        $declared = $this->declaredFor($module);

        if ($declared === null) {
            return null;
        }

        return $this->overrideFor($module, $values, $declared) ?? $this->default->days();
    }

    /**
     * What the customer on this document has agreed to, or null when nothing
     * says.
     *
     * Null here means "nobody overrode anything", which is the ordinary state:
     * most customers pay on the installation's terms and the field on them is
     * empty. A zero is *not* null and is a real term — payable on receipt.
     *
     * @param array<string, mixed> $values
     */
    private function overrideFor(ModuleDefinition $module, array $values, PaymentTerms $declared): ?int
    {
        if ($declared->through === null) {
            return self::daysIn($values[$declared->terms] ?? null);
        }

        $link = $module->getField($declared->through);

        if ($link === null || $link->getType() !== 'reference') {
            return null;
        }

        $target = $this->targetOf($link->getOption(ReferenceFieldType::MODULE));

        // A link into a module the customer does not have matches nothing
        // everywhere else (§7.6), and a target that no longer carries the field
        // is §5.4 letting somebody delete it. Both mean the same thing here: no
        // override, so the installation's own terms apply.
        if ($target === null || $target->getField($declared->terms) === null) {
            return null;
        }

        $id = $values[$declared->through] ?? null;

        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        // Unscoped, and soft-deleted targets stay out — see the class docblock.
        // A deleted customer is a stale link (§7.6) and their terms are not a
        // deadline anybody agreed to twice.
        $linked = $this->records->find($target, (int) $id);

        return $linked === null ? null : self::daysIn($linked->get($declared->terms));
    }

    /**
     * A whole number of days, or null for anything that is not one.
     *
     * Negative is not one: a document due before it was issued is a typo
     * somebody made in a field, not a term, and the honest answer to it is to
     * fall through to the layer below rather than to print a date in the past on
     * a bill. The field type's own `min` keeps it from being typed; this catches
     * what an import wrote.
     */
    private static function daysIn(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (\is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function targetOf(mixed $moduleKey): ?ModuleDefinition
    {
        return \is_string($moduleKey) && $moduleKey !== '' ? $this->metadata->find($moduleKey) : null;
    }
}

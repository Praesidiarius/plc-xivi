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

namespace Xivi\Core\Mail;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Turning a module's {@see MailRecipient} declaration into one address (XIV-39).
 *
 * The reading half, the way {@see \Xivi\Core\Seed\Seeder} is the reading half of
 * `Seed`: the declaration is in the module's own package and everything that
 * knows how to follow it is here.
 *
 * ### The declaration is read from the blueprint, not from the customer's rows
 *
 * Which field holds an address is the module's, and the module ships in code —
 * so this asks {@see ModuleRegistry}, exactly as `Seeder` does for `Seed`. What
 * *is* read from the customer's definitions is whether those fields still exist:
 * §5.4 lets somebody delete a field, and a shape with no email field on it is a
 * shape that does not send mail. That is {@see self::declaredFor()} answering
 * null rather than a per-record problem, because it is true of every record of
 * that module and an explanation repeated on all of them is noise.
 *
 * ### Following the hop is a read of the other module, and it is unscoped
 *
 * XIV-42 already split this question in two and this is the same split arriving
 * one ticket later. *The name* of a linked record is read unscoped, because an
 * order whose customer reads `#14` is an order nobody can use; *the link* is only
 * offered where the reader could open the target. An address is the first half:
 * whoever may send an invoice may reach the address that invoice is for, or the
 * permission to send it would quietly be two permissions and the second one
 * unnameable — "may send invoices" would mean nothing without "may read
 * contacts" beside it. So the target record is fetched by id, and what protects
 * it is that the *send* grant is on the module holding the link (§8.4).
 *
 * The address is not shown anywhere this person could not already have reached
 * it: it appears on the send screen, which the send grant is what opens.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecipientResolver
{
    public function __construct(
        private ModuleRegistry $modules,
        private MetadataRepository $metadata,
        private RecordRepository $records,
    ) {
    }

    /**
     * What this module declares about where its mail goes, if anything it can
     * still act on.
     *
     * Null covers three cases that are one case to a caller: a build without the
     * module, a module that never declared a recipient, and a customer whose own
     * shape no longer has the fields the declaration names. None of them is a
     * fault and all three mean the same thing — this module does not send mail
     * here.
     */
    public function declaredFor(ModuleDefinition $module): ?MailRecipient
    {
        $key = $module->getKey();

        if (!$this->modules->has($key)) {
            return null;
        }

        $declared = $this->modules->get($key)->mailRecipient;

        if ($declared === null) {
            return null;
        }

        if ($declared->through === null) {
            return $module->getField($declared->field) === null ? null : $declared;
        }

        $link = $module->getField($declared->through);

        if ($link === null || $link->getType() !== 'reference') {
            return null;
        }

        // The module it points at has to be installed here, and has to still have
        // the field the address was promised in. A link into a module the customer
        // does not have matches nothing everywhere else (§7.6); here it means this
        // shape cannot send mail, rather than that every one of its records is
        // broken.
        $target = $this->targetOf($link->getOption(ReferenceFieldType::MODULE));

        return $target !== null && $target->getField($declared->field) !== null ? $declared : null;
    }

    /**
     * Where a mail about this record would go, or why nowhere.
     *
     * Never throws. A record without an address is the ordinary state of half a
     * CRM, and this is called while drawing a page.
     */
    public function for(ModuleDefinition $module, Record $record): Recipient
    {
        $declared = $this->declaredFor($module);

        if ($declared === null) {
            return Recipient::missing(RecipientProblem::NotDeclared);
        }

        if ($declared->through === null) {
            $field = $module->getField($declared->field);
            \assert($field !== null); // declaredFor() has already looked.

            return self::readFrom($record, $declared->field, $field->getLabel(), null);
        }

        $link = $module->getField($declared->through);
        \assert($link !== null);

        $via = $link->getLabel();
        $targetKey = $link->getOption(ReferenceFieldType::MODULE);
        $target = $this->targetOf($targetKey);
        \assert($target !== null);

        $field = $target->getField($declared->field);
        \assert($field !== null);

        $id = $record->get($declared->through);

        if (!is_numeric($id) || (int) $id <= 0) {
            return Recipient::missing(RecipientProblem::NoLink, $field->getLabel(), $via);
        }

        // Unscoped and deliberately so — see the class docblock. Soft-deleted
        // targets stay out: a contact somebody deleted is a stale link (§7.6),
        // and a mail to one is the shape of an accident rather than a send.
        $linked = $this->records->find($target, (int) $id);

        if ($linked === null) {
            return Recipient::missing(RecipientProblem::LinkGone, $field->getLabel(), $via);
        }

        return self::readFrom($linked, $declared->field, $field->getLabel(), $via);
    }

    /** One field of one record, as an address or as the reason it is not one. */
    private static function readFrom(Record $record, string $key, string $label, ?string $via): Recipient
    {
        $value = $record->get($key);

        if (!\is_string($value) || trim($value) === '') {
            return Recipient::missing(RecipientProblem::NoAddress, $label, $via);
        }

        $value = trim($value);

        // Checked here rather than left to the message being built, because the
        // ticket's rule is that a record which cannot be sent to offers no send:
        // a field holding "call him" has to be known about while the page is
        // being drawn, not when somebody has already pressed the button. The
        // field type validates on the way in (§5), so this catches the values
        // that arrived before the field was an email field, and the ones an
        // import wrote.
        if (filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
            return Recipient::missing(RecipientProblem::NotAnAddress, $label, $via, $value);
        }

        return Recipient::at($value, $label, $via);
    }

    private function targetOf(mixed $moduleKey): ?ModuleDefinition
    {
        return \is_string($moduleKey) && $moduleKey !== '' ? $this->metadata->find($moduleKey) : null;
    }
}

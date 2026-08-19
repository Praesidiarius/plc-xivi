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

namespace Xivi\Voucher\Discount;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\VoucherModule;

/**
 * Which field on somebody else's module names one of ours, and what it names
 * (XIV-104).
 *
 * ### Found rather than declared, and that is the whole of the module boundary
 *
 * The order module may not import this package and this package may not import
 * that one (§3), so neither can be told the other's field key by anybody. It does
 * not need to be: a link between modules is a `reference` field carrying the
 * *key* of the module it points at (XIV-13), and that key is in the customer's
 * own definitions. So the question "does this document name a voucher" is
 * answered by reading the document's shape and looking for a reference into
 * `voucher` — the same trick the record page plays in reverse when it lists the
 * orders naming a contact, and the reason neither module has a line of code about
 * the other in it.
 *
 * It also means this works for **any** module a customer points at vouchers,
 * including one they made themselves in the metadata editor, without a single
 * entry in a list here that would have had to be kept in step with it.
 *
 * **The first such field wins.** A shape with two references into vouchers is not
 * a shape this feature has an opinion about, and picking the first is at least
 * stable: fields come back in the order the definitions hold them, which is the
 * order the module's author wrote and the customer has since arranged.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class VoucherReference
{
    public function __construct(
        private MetadataRepository $metadata,
        private RecordRepository $records,
    ) {
    }

    /**
     * The key of the field on this module that names a voucher, or null.
     *
     * Null covers three states worth keeping distinct in the reader's head and
     * not in the code: a module that has nothing to do with vouchers, a customer
     * whose order module was installed before they bought them
     * ({@see \Xivi\Core\Module\AvailableFields}), and a customer who deleted the
     * field afterwards, which §5.4 says is theirs to do. All three mean the same
     * thing here — nothing on this document names a voucher — and none of them is
     * an error.
     */
    public function fieldOn(ModuleDefinition $module): ?string
    {
        foreach ($module->getFields() as $field) {
            if ($field->getType() !== 'reference') {
                continue;
            }

            if (ReferenceFieldType::targetModule($field) === VoucherModule::KEY) {
                return $field->getKey();
            }
        }

        return null;
    }

    /**
     * Which voucher a set of a document's values names, if it names one.
     *
     * Takes the values rather than a record because both callers have values and
     * only one of them has a record: a deriver is handed what is *about* to be
     * written (§5.9) and a subscriber is handed what was.
     *
     * @param array<string, mixed> $fields
     */
    public function idIn(ModuleDefinition $module, array $fields): ?int
    {
        $key = $this->fieldOn($module);
        $value = $key === null ? null : ($fields[$key] ?? null);

        // A reference stores an id and a form submits a string; both arrive here
        // and neither is worth a branch at the call sites.
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The voucher itself, or null if it cannot be read.
     *
     * **Two absences, one answer, and it is the safe one.** The voucher module may
     * have been uninstalled since, and the record may have been deleted since
     * (§5.4 keeps the values behind either). Neither is a state anything here can
     * repair, and neither may be read as "there is no discount": a document that
     * quietly lost the discount somebody agreed to is the failure §5.9 exists to
     * prevent. So the callers treat null as *say nothing and change nothing* —
     * the deriver leaves the lines that are already there alone, and the
     * subscriber refuses a save that is trying to take a use of it.
     */
    public function record(int $id): ?Record
    {
        $module = $this->metadata->find(VoucherModule::KEY);

        return $module === null ? null : $this->records->find($module, $id);
    }

    /** The voucher module's own definitions, for reading a record's title off. */
    public function module(): ?ModuleDefinition
    {
        return $this->metadata->find(VoucherModule::KEY);
    }

    /**
     * What the article a free-article voucher gives away is called, or null if
     * nothing can be read.
     *
     * **The target module comes from the customer's own field definition** rather
     * than from a constant here, which is the same reading {@see fieldOn()} does
     * in the other direction. It costs nothing and it is honest: which module that
     * link points at is a fact about their installation, and a customer who
     * re-pointed it is not somebody this package should be second-guessing.
     *
     * Null for every way that can fail — no such field, no such module, no such
     * record — because the caller has exactly one thing to do about all of them.
     */
    public function articleNamed(int $id): ?string
    {
        $field = $this->module()?->getField(VoucherModule::ARTICLE);
        $module = $field === null ? null : $this->metadata->find(ReferenceFieldType::targetModule($field));
        $article = $module === null ? null : $this->records->find($module, $id);

        return $article === null ? null : RecordTitle::of($module, $article);
    }
}

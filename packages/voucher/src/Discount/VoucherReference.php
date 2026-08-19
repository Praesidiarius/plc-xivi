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
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\VoucherModule;

/**
 * Which field on somebody else's shape names one of ours, and what it names
 * (XIV-104, XIV-122).
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
 * ### A shape, not a module (XIV-122)
 *
 * [XIV-104] asked this about a module because a voucher was named on the header
 * and nowhere else. A **line** voucher is named on a line, so the same question
 * has to be askable of a collection — and it is exactly the same question, because
 * §5.1's claim is that a collection *is* a shape. So the parameter widened from
 * `ModuleDefinition` to {@see ShapeDefinition} and not one line of the body
 * changed, which is the kind of evidence that claim is worth having.
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
     * The key of the field on this shape that names a voucher, or null.
     *
     * Null covers three states worth keeping distinct in the reader's head and
     * not in the code: a shape that has nothing to do with vouchers, a customer
     * whose order module was installed before they bought them
     * ({@see \Xivi\Core\Module\AvailableFields}), and a customer who deleted the
     * field afterwards, which §5.4 says is theirs to do. All three mean the same
     * thing here — nothing here names a voucher — and none of them is an error.
     */
    public function fieldOn(ShapeDefinition $shape): ?string
    {
        foreach ($shape->getFields() as $field) {
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
     * Which voucher a set of values names, if it names one.
     *
     * Takes the values rather than a record because the callers have values and
     * only some of them have a record: a deriver is handed what is *about* to be
     * written (§5.9), a subscriber is handed what was, and a line is a row of
     * data with no identity of its own at all.
     *
     * @param array<string, mixed> $fields
     */
    public function idIn(ShapeDefinition $shape, array $fields): ?int
    {
        $key = $this->fieldOn($shape);

        return $key === null ? null : self::idOf($fields[$key] ?? null);
    }

    /**
     * A stored reference as a record id, or null for anything that is not one.
     *
     * A reference stores an id and a form submits a string; both arrive here and
     * neither is worth a branch at the call sites.
     */
    public static function idOf(mixed $value): ?int
    {
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
     * Which article a voucher is restricted to, or null for one that is not
     * restricted at all (XIV-122).
     *
     * Read off the voucher rather than resolved to a name, because what the
     * caller does with it is compare it to the article a line sells — and a
     * comparison of ids is a comparison that cannot be wrong about two articles
     * with the same title.
     *
     * Null also for a customer with no article module, whose vouchers have no such
     * field at all ({@see \Xivi\Core\Module\AvailableFields}), which is the same
     * answer for the same reason: nothing restricts these vouchers.
     */
    public function restrictionOf(Record $voucher): ?int
    {
        return self::idOf($voucher->get(VoucherModule::ARTICLE));
    }

    /**
     * Which article a *line* sells, if it sells one.
     *
     * **The line's own reference into the article module, found the way this class
     * finds everything** — by reading the customer's definitions for a reference
     * pointing there, rather than by knowing what an order calls its article
     * column. §3 forbids the import and XIV-13 makes the import unnecessary.
     *
     * The target module comes from the voucher's *own* restriction field, so a
     * customer who re-pointed that link is not being second-guessed: whatever
     * module the restriction points at is the module a line has to be selling from
     * for the two to be about the same thing.
     *
     * @param array<string, mixed> $data
     */
    public function articleIn(ShapeDefinition $lines, array $data): ?int
    {
        $restriction = $this->module()?->getField(VoucherModule::ARTICLE);
        $target = $restriction === null ? null : ReferenceFieldType::targetModule($restriction);

        if ($target === null) {
            return null;
        }

        foreach ($lines->getFields() as $field) {
            if ($field->getType() === 'reference' && ReferenceFieldType::targetModule($field) === $target) {
                return self::idOf($data[$field->getKey()] ?? null);
            }
        }

        return null;
    }

    /**
     * What the article behind an id is called, for a refusal that can name it.
     *
     * Only ever reached on a path that has already failed, which is why it may be
     * a second read: the sentence "this voucher only works on a line for X" is of
     * no use without X, and X is a title in the customer's own words.
     */
    public function articleNamed(int $id): ?string
    {
        $field = $this->module()?->getField(VoucherModule::ARTICLE);
        $module = $field === null ? null : $this->metadata->find(ReferenceFieldType::targetModule($field));
        $article = $module === null ? null : $this->records->find($module, $id);

        return $article === null ? null : RecordTitle::of($module, $article);
    }
}

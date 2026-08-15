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

namespace Xivi\Core\Field\Type;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Form\RecordReferenceType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * A link to another record, stored as its id (§7.6).
 *
 * §7.6 asked whether a link should be a field type. This is the answer: yes,
 * because then the widget, the display and the filter behaviour all come from
 * the type, exactly like every other kind of value, and nothing above has to
 * learn what a link is.
 *
 * The id is a plain integer in the payload, so a reference is a real value
 * pointing at a real primary key — not a type/id pair. That is only possible
 * because a contact is one module whose records may be people or companies
 * (§5.5); two modules would have made this polymorphic, which is the shape that
 * cannot carry a key and the reason the old history table rotted.
 *
 * `options` say where it points:
 *
 *     ['module' => 'contact', 'variant' => 'company']
 *
 * The variant is optional and narrows the candidates, so a person's employer
 * offers companies rather than every contact in the database.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ReferenceFieldType implements FieldType
{
    public const string MODULE = 'module';
    public const string VARIANT = 'variant';

    /**
     * How many existing records a generated link picks between. Enough for the
     * links to look spread out, few enough to be one query.
     */
    private const int CANDIDATES = 200;

    /**
     * Titles already resolved this request.
     *
     * A list of fifty records each showing a link would otherwise be fifty
     * lookups of a handful of ids. This is not a cache in any durable sense —
     * it lives and dies with the request, so it cannot serve one tenant's data
     * to another (§7.4).
     *
     * @var array<string, string>
     */
    private array $titles = [];

    /**
     * Ids a generated link may point at, per field. Same lifetime as the titles
     * above and for the same reason (§7.4): it dies with the request.
     *
     * @var array<string, list<int>>
     */
    private array $candidates = [];

    /**
     * The record repository arrives as a closure, and that is not fussiness.
     *
     * This type reads records to name them; reading records goes through
     * RecordRepository, which needs the field type registry to hydrate values —
     * which builds this type. A real cycle, and the container recurses until it
     * gives up. Deferring one edge of it until the moment a title is actually
     * wanted breaks the loop without pretending the dependency is not there.
     *
     * @param \Closure(): RecordRepository $records
     */
    public function __construct(
        private readonly MetadataRepository $metadata,
        #[AutowireServiceClosure(RecordRepository::class)]
        private readonly \Closure $records,
    ) {
    }

    public function key(): string
    {
        return 'reference';
    }

    public function label(): string
    {
        return 'Link to a record';
    }

    public function constraints(FieldDefinition $field): array
    {
        // Deliberately none beyond the type: whether the id exists is a question
        // about another table, and answering it here would validate on every
        // save what a foreign key should be answering once (§7.6, still open).
        return [];
    }

    /**
     * The id of a record that actually exists, or nothing.
     *
     * A generated link has to point somewhere real or the demo data is a page
     * full of broken references — which is exactly the thing this type exists to
     * render nicely, so a demo that only exercised the broken case would be
     * testing the wrong half.
     *
     * The candidates are read once and reused for the rest of the run. Picking
     * randomly from the whole table per record would be a query each time, and
     * the point of the generator is to produce a million rows.
     */
    public function sample(FieldDefinition $field, int $sequence): ?int
    {
        // Half of optional links left empty: a person may have no employer, and
        // a column that is always filled never shows what an empty one looks like.
        if (!$field->isRequired() && mt_rand(1, 2) === 1) {
            return null;
        }

        $candidates = $this->candidates[$field->getKey()] ??= $this->findCandidates($field);

        return $candidates === [] ? null : $candidates[mt_rand(0, \count($candidates) - 1)];
    }

    /**
     * Ids this field could point at: records of its target module, narrowed to
     * its target variant when it has one.
     *
     * @return list<int>
     */
    private function findCandidates(FieldDefinition $field): array
    {
        try {
            $module = $this->metadata->get(self::targetModule($field));
        } catch (ModuleNotInstalled) {
            return [];
        }

        $filters = [];
        $variant = self::targetVariant($field);
        $variantField = $module->getVariantField();

        if ($variant !== null && $variantField !== null) {
            $filters[] = new Filter($variantField, Operator::Equals, $variant);
        }

        // Unrestricted, and deliberately not scoped like the picker is (XIV-13):
        // this feeds the demo generator, which runs from a console command with
        // nobody signed in. Scoping it would mean generated links that always
        // point nowhere.
        $records = ($this->records)()->findBy(
            $module,
            new RecordQuery($filters, [], 1, self::CANDIDATES),
            RecordAccess::unrestricted(),
        );

        return array_map(static fn (Record $record): int => (int) $record->id, $records);
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?int
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        return \is_numeric($value) ? (int) $value : null;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?int
    {
        return \is_numeric($value) ? (int) $value : null;
    }

    public function formType(): string
    {
        return RecordReferenceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            'target_module' => self::targetModule($field),
            'target_variant' => self::targetVariant($field),
            'required' => $field->isRequired(),
        ];
    }

    /**
     * The record's own name, from its module's title fields (§5.4) — which is
     * what those exist for, and why a company with no first name still reads as
     * something.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        $id = $this->fromStorage($value, $field);

        if ($id === null) {
            return '';
        }

        $module = self::targetModule($field);
        $key = $module . '#' . $id;

        return $this->titles[$key] ??= $this->titleOf($module, $id);
    }

    public function operators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /**
     * Compared as the stored id. Filtering by the *name* of the linked record
     * would be a join, and §7.3 does not reach across a reference yet.
     */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    public static function targetModule(FieldDefinition $field): string
    {
        $module = $field->getOption(self::MODULE);

        return \is_string($module) ? $module : '';
    }

    public static function targetVariant(FieldDefinition $field): ?string
    {
        $variant = $field->getOption(self::VARIANT);

        return \is_string($variant) && $variant !== '' ? $variant : null;
    }

    private function titleOf(string $moduleKey, int $id): string
    {
        try {
            $module = $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            // A link whose target module this customer does not have. §7.6 lists
            // that as open; until it is answered, saying so beats a stack trace.
            return sprintf('#%d', $id);
        }

        $record = ($this->records)()->find($module, $id);

        if ($record === null) {
            // Soft-deleted or gone. The link is stale rather than broken, and a
            // page that renders is more useful than one that does not.
            return sprintf('#%d', $id);
        }

        $parts = [];
        foreach ($module->getTitleFields() as $titleField) {
            $shown = trim($this->titleOfField($titleField, $record->get($titleField->getKey())));

            if ($shown !== '') {
                $parts[] = $shown;
            }
        }

        return $parts === [] ? sprintf('%s #%d', $module->getLabel(), $id) : implode(' ', $parts);
    }

    /**
     * Scalars only, and never another reference — which would recurse, and is
     * not what anybody names a record by anyway. Asking each field's own type to
     * render itself would mean holding the registry this type lives in, so a
     * date used as a title reads as its stored form here rather than not at all.
     */
    private function titleOfField(FieldDefinition $field, mixed $value): string
    {
        if ($field->getType() === $this->key() || !\is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}

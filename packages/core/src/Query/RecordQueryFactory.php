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

namespace Xivi\Core\Query;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

/**
 * Turns a query string into a RecordQuery, and says what may be asked about.
 *
 * It takes a plain array rather than a Request on purpose: core has no opinion
 * about HTTP, and reading a query is the same job whether it arrives from a URL,
 * a saved view, or an API later. It also means this is testable with arrays
 * instead of with a kernel.
 *
 * Read leniently, trusted not at all. A row that names no field, or a comparison
 * this build does not have, is dropped rather than argued with — a URL is typed,
 * pasted and truncated. What it produces still goes to the compiler, which
 * resolves every name against the customer's definitions and refuses whatever it
 * does not recognise.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordQueryFactory
{
    private const string REFERENCE = 'reference';

    /** Only for offering paths through a link — see filterablePaths() (XIV-13). */
    public function __construct(private MetadataRepository $metadata)
    {
    }

    /**
     * @param array<string, mixed> $parameters the query string, as an array
     */
    public function fromQueryParameters(array $parameters): RecordQuery
    {
        return new RecordQuery(
            self::filters($parameters['filter'] ?? null),
            self::sorts($parameters['sort'] ?? null, $parameters['dir'] ?? null),
            self::page($parameters['page'] ?? null),
        );
    }

    /**
     * The paths this module can be filtered on, module fields first and then
     * each collection's, ready for a select.
     *
     * `filterable` is the customer's own flag on the definition row, so a filter
     * bar grows a field the moment they mark one — no code, which is §5's claim
     * applied to searching rather than to storing.
     *
     * @return array<string, string> path => label
     */
    public function filterablePaths(ModuleDefinition $module): array
    {
        $paths = [];

        foreach ($module->getFields() as $field) {
            if ($field->isFilterable()) {
                $paths[$field->getKey()] = $field->getLabel();
            }
        }

        foreach ($module->getCollections() as $collection) {
            foreach ($collection->getFields() as $field) {
                if ($field->isFilterable()) {
                    $paths[$collection->getKey() . '.' . $field->getKey()]
                        = sprintf('%s: %s', $collection->getLabel(), $field->getLabel());
                }
            }
        }

        // One hop through a link, and one only (XIV-13): "orders whose contact
        // is in Zürich" is a question people ask, and
        // "orders whose contact's company's owner…" is one nobody can reason
        // about the cost of.
        foreach ($module->getFields() as $field) {
            if ($field->getType() !== self::REFERENCE || !$field->isFilterable()) {
                continue;
            }

            try {
                $target = $this->metadata->get(ReferenceFieldType::targetModule($field));
            } catch (ModuleNotInstalled) {
                // A link into a module this customer does not have: nothing to
                // offer, and not an error (§3).
                continue;
            }

            foreach ($target->getFields() as $linked) {
                // Not another reference: two hops, by a different door.
                if ($linked->isFilterable() && $linked->getType() !== self::REFERENCE) {
                    $paths[$field->getKey() . '.' . $linked->getKey()]
                        = sprintf('%s: %s', $field->getLabel(), $linked->getLabel());
                }
            }
        }

        return $paths;
    }

    /** @return list<Filter> */
    private static function filters(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }

        $filters = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $path = trim(self::text($row['path'] ?? null));
            $operator = Operator::tryFrom(self::text($row['op'] ?? null));

            if ($path === '' || $operator === null) {
                continue;
            }

            $value = trim(self::text($row['value'] ?? null));

            // A comparison that needs a value and has not got one is an empty
            // filter box, not a filter for the empty string.
            if ($value === '' && $operator->needsValue()) {
                continue;
            }

            $filters[] = Filter::fromPath($path, $operator, $value);
        }

        return $filters;
    }

    /**
     * @return list<Sort>
     *
     * Comma-separated, because one visible column can rest on more than one
     * field: with variants a record's name is its company name or its first
     * name, never both (§5.5), so "sort by name" is an ordering over the lot.
     * They share a direction — a column header is one control.
     */
    private static function sorts(mixed $field, mixed $direction): array
    {
        $keys = array_values(array_filter(array_map(
            trim(...),
            explode(',', self::text($field)),
        ), static fn (string $key): bool => $key !== ''));

        // An unreadable direction is ascending rather than an error: the field is
        // the part somebody meant.
        $order = Direction::tryFrom(self::text($direction)) ?? Direction::Ascending;

        return array_map(static fn (string $key): Sort => new Sort($key, $order), $keys);
    }

    private static function page(mixed $page): int
    {
        return max(1, (int) self::text($page));
    }

    /** Query parameters arrive as strings, arrays, or missing. Only the first is a value. */
    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}

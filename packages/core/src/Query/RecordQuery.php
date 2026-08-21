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

/**
 * What to look for: conditions, ordering, and how much of it (§7.3).
 *
 * A value object, built from a request by the application and handed to the
 * compiler. Nothing in it is SQL, and nothing that comes from a user reaches SQL
 * except as a bound parameter — field names are looked up against the customer's
 * definitions, and comparisons are a closed enum.
 *
 * Filters are combined with AND. OR needs a tree rather than a list and a UI to
 * build one, and inventing that shape before anything asks for it is the
 * speculative generalisation §1 warns about. The list is the honest 90%.
 *
 * **The variants are a narrowing rather than a filter** (XIV-172), which is the
 * same distinction {@see Search} draws and for the same reason. A reference
 * picker offers the kinds of record its field says it points at: a person's
 * employer offers companies, an order's voucher offers the two kinds that apply
 * to a document. Asking for two of a module's four kinds is a disjunction
 * the filter list cannot express, because two filters mean *and*.
 *
 * It stays out of `filters` rather than arriving as an operator over a list,
 * which §5.3 refused (`Operator::Includes` says so in its own docblock): what is
 * here is a closed shape with nothing composable in it. One column, the module's
 * own variant field, resolved by the compiler rather than named by the caller;
 * values bound like every other value; ANDed with everything else. A caller
 * cannot say *which* field this narrows, so this cannot become a way to write an
 * OR over anything else.
 *
 * Empty means every variant, which is `FieldBlueprint::variants`' rule one level
 * out (§5.5) and the answer for the modules that have no variant field at all.
 *
 * Paging is LIMIT/OFFSET, which is correct and gets slower the deeper it goes.
 * Keyset paging is the upgrade when someone is on page 400; until then it costs
 * a sort key in the URL that nobody wants to look at.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordQuery
{
    public const int DEFAULT_PER_PAGE = 25;

    /**
     * @param list<Filter> $filters
     * @param list<Sort>   $sorts
     * @param ?Search      $search   one string across several fields (XIV-36),
     *                               ANDed with the filters like any other
     *                               condition. Null is the ordinary case and the
     *                               one every caller before the picker takes.
     * @param list<string> $variants which kinds of record this is about (§5.5),
     *                               empty for all of them. See the class docblock
     *                               for why it is not a filter
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?Search $search = null,
        public array $variants = [],
    ) {
    }

    public function offset(): int
    {
        return max(0, $this->page - 1) * $this->perPage;
    }

    public function withSorts(Sort ...$sorts): self
    {
        return new self($this->filters, array_values($sorts), $this->page, $this->perPage, $this->search, $this->variants);
    }

    public function withPage(int $page): self
    {
        return new self($this->filters, $this->sorts, $page, $this->perPage, $this->search, $this->variants);
    }

    public function sortOn(string $field): ?Sort
    {
        foreach ($this->sorts as $sort) {
            if ($sort->field === $field) {
                return $sort;
            }
        }

        return null;
    }

    public function isFiltered(): bool
    {
        return $this->filters !== [];
    }
}

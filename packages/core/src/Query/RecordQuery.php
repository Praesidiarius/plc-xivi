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
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    public function offset(): int
    {
        return max(0, $this->page - 1) * $this->perPage;
    }

    public function withSorts(Sort ...$sorts): self
    {
        return new self($this->filters, array_values($sorts), $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->filters, $this->sorts, $page, $this->perPage);
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

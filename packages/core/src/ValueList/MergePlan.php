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

namespace Xivi\Core\ValueList;

use Xivi\Core\Entity\ValueList;
use Xivi\Core\Entity\ValueListEntry;

/**
 * What merging one list entry into another would do, said before it is done
 * (XIV-127).
 *
 * **The sharp part of this ticket, and it is not a UI question.** Merging
 * "Zurich" into "Zürich" rewrites a value on every record holding it, across
 * every module whose fields point at this list, and there is no way back: after
 * it, nothing anywhere remembers which of the records saying "Zürich" used to
 * say something else. That is XIV-91's backfill exactly, and its answer
 * transfers rather than being re-derived — **say what will happen, how many
 * records it touches, and that it cannot be undone, before doing it** — with the
 * confirmation required in the controller rather than as an attribute, because
 * an attribute is a courtesy to somebody using the page and nothing at all to a
 * form posted around it.
 *
 * This object is what that page says. It is deliberately a plan rather than a
 * summary: {@see MergeCount} keeps the fields with nothing to rewrite, because
 * "this also reaches Orders, and there is nothing there today" is a fact about
 * the change and "it only touches contacts" is a fact about this afternoon.
 *
 * **What it does not promise.** The counts are read before the merge and the
 * merge is what actually writes, so a record saved between the two is one more
 * record rewritten. The flash afterwards therefore reports what the merge
 * *did*, not what this predicted — the same split XIV-91 made for the same
 * reason, and the honest one: a page that agreed to 84 and did 85 has done the
 * right thing, and saying 84 afterwards would be the only lie available.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MergePlan
{
    /** @param list<MergeCount> $counts */
    public function __construct(
        public ValueList $list,
        /** The value that goes. Every record holding it will hold {@see self::$into} instead. */
        public string $from,
        /** The value that stays. */
        public string $into,
        public array $counts = [],
    ) {
    }

    public function entry(): ?ValueListEntry
    {
        return $this->list->getEntry($this->from);
    }

    public function target(): ?ValueListEntry
    {
        return $this->list->getEntry($this->into);
    }

    /** How many records this would rewrite, everywhere. */
    public function records(): int
    {
        $total = 0;

        foreach ($this->counts as $count) {
            $total += $count->records;
        }

        return $total;
    }

    /**
     * Whether anything at all would be rewritten.
     *
     * Worth asking because the answer changes what the page says and not what it
     * does: merging an entry nothing holds is still a merge — the entry goes,
     * its children move — and telling somebody "this will change 0 records"
     * beside a warning about irreversibility would be a warning about nothing.
     */
    public function touchesRecords(): bool
    {
        return $this->records() > 0;
    }

    /**
     * How many entries would be moved up under the surviving one.
     *
     * A merge takes an entry away, so anything sitting under it has to go
     * somewhere, and the only place that is not a decision nobody asked for is
     * under the entry that replaced it. Said on the confirmation page for the
     * same reason the record count is: it is a consequence somebody would
     * otherwise discover afterwards.
     */
    public function children(): int
    {
        $entry = $this->entry();

        if ($entry === null) {
            return 0;
        }

        $children = 0;

        foreach ($this->list->getEntries() as $candidate) {
            if ($candidate->getParent() === $entry) {
                ++$children;
            }
        }

        return $children;
    }
}

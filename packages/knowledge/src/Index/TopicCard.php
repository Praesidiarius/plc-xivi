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

namespace Xivi\Knowledge\Index;

use Xivi\Core\Record\Record;

/**
 * One card of the knowledge index: the entries filed under one topic (XIV-168,
 * moved here by XIV-177).
 *
 * **It was `Xivi\Core\Record\RecordGroup` and it should not have been.** XIV-168
 * built the card as an engine concept, a group of records under a value of any
 * field any module declared a grouping on, with exactly one consumer. §1's rule
 * is that an abstraction is earned by a second concrete use case rather than by
 * an imaginable one. Everything below is a decision about **what a card
 * of written-down knowledge should show**, which is this module's judgement
 * about its own page, so it lives here now with the template it feeds.
 *
 * ## Two numbers, not one
 *
 * The same pair {@see \App\View\LinkedRecords} carries, for the same reason.
 * **What the card shows and how many there are are two facts.** A card is a
 * glance, so it stops at a ceiling; the count is the answer to "how many entries
 * are filed under this", which somebody reads off the page and believes.
 * Counting the array would answer the second question with the first question's
 * number, and be wrong by exactly what the ceiling left out.
 *
 * Both are read under the same access predicate (§8.4), so a count on a card can
 * never be larger than what this reader would find by opening the list. A total
 * counted without the predicate would give away how many records exist one
 * integer at a time, which is the inference channel §8.4 is careful about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TopicCard
{
    /**
     * @param string       $value   the stored topic these entries share, or the
     *                              empty string for the entries filed under no
     *                              topic at all
     * @param ?string      $label   the customer's word for that topic, or null
     *                              when there is none to have. The unfiled card
     *                              has no option behind it, and neither has a
     *                              value left over from an option that is gone;
     *                              the template words both, because wording is a
     *                              translator's job and this package's own
     *                              catalogue is where its words live
     * @param list<Record> $records the first few, in the page's order, capped by
     *                              {@see TopicCards::PER_CARD}
     * @param int          $total   how many are filed under this topic, counted
     *                              under the same access predicate and the same
     *                              filters as the records
     * @param string       $listing where the rest of them are: this same index,
     *                              narrowed to this topic and asked for as rows.
     *                              Built by {@see TopicCards} through
     *                              {@see \Xivi\Core\Record\RecordListUrl},
     *                              because a module may not name an application
     *                              route and a module's template may not build
     *                              one
     */
    public function __construct(
        public string $value,
        public ?string $label,
        public array $records,
        public int $total,
        public string $listing,
    ) {
    }

    /**
     * Whether this is the card for entries that were filed under no topic.
     *
     * It is drawn whenever anything is in it, and that is the decision this
     * class is quietest about and the one worth stating. **The topic field is
     * not required**, and §5.22 says why in as many words: *writing at half past
     * five should not be stopped by a dropdown*. So entries with no topic are
     * ordinary rather than broken, and a page that drew only the field's own
     * options would make them invisible on their own index.
     */
    public function isUnfiled(): bool
    {
        return $this->value === '';
    }

    /** Whether the card is showing less than it counted. */
    public function isTruncated(): bool
    {
        return \count($this->records) < $this->total;
    }

    public function shown(): int
    {
        return \count($this->records);
    }
}

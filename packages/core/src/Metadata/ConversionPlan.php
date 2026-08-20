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

namespace Xivi\Core\Metadata;

/**
 * What changing a field's type would do to the values already in it
 * ([XIV-146], §7.2).
 *
 * The same object twice, on {@see NumberingPlan}'s terms and for the same
 * reason: {@see FieldTypeConversion::plan()} builds it and the confirmation
 * page is rendered from it, and {@see FieldTypeConversion::convert()} builds it
 * again inside the transaction that does the work and returns what actually
 * happened. A record saved between the two changes the figures, and the second
 * one is the truth.
 *
 * What is different here, and it is the whole of §7.2's argument, is that this
 * object is not a description of a rule. **Legality is the tenant's data's to
 * decide, not a table of type pairs.** There is no list anywhere of which type
 * may become which; every value in the column is read by the type it is moving
 * to, and the answer comes out of that. The same change is therefore allowed on
 * one customer's database and refused on another's, because their contacts were
 * typed in by different people.
 *
 * Every count in here is counted and every value in here was read, so nothing on
 * the page it feeds is a warning about what might happen.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ConversionPlan
{
    /**
     * How many values each of the two lists names before it gives up and says
     * "…".
     *
     * Five, exactly as {@see MetadataEditor::DUPLICATES_NAMED} is five and for
     * the argument made there: enough to recognise a pattern, short enough to be
     * read rather than skimmed. A column that refuses four hundred distinct
     * values is a column somebody has to go and look at, and printing all four
     * hundred of them is a refusal nobody finishes.
     */
    public const int VALUES_NAMED = 5;

    /**
     * @param string                $from       the type key the field has now
     * @param string                $to         the type key it would have
     * @param int                   $records    live records holding a value in this field
     * @param int                   $converts   how many of those the new type reads
     * @param int                   $refuses    how many of those it cannot read, which is
     *                                          how many would be emptied if the customer
     *                                          asked for that and how many refuse the whole
     *                                          change if they do not
     * @param int                   $changes    how many records' stored value actually moves.
     *                                          A conversion where this is zero is a change of
     *                                          what the column *means* and of nothing that is
     *                                          in it, which is worth being able to say
     * @param array<string, string> $rewritten  a sample of what becomes what: the value as it is
     *                                          stored today => the value that would replace it,
     *                                          most-held first and capped at {@see self::VALUES_NAMED}
     *                                          plus one, so that a page can tell "these five" from
     *                                          "at least these five"
     * @param array<string, int>    $refusing   the values the new type cannot read => how many
     *                                          records hold each, on the same terms
     * @param array<string, int>    $shared     the converted values more than one record would
     *                                          then hold => how many records that is, when the
     *                                          field is `unique`. Non-empty means the change is
     *                                          refused before it is attempted rather than rolled
     *                                          back after the index says no
     * @param bool                  $reversible whether converting straight back would give every
     *                                          record exactly the value it holds today
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $records,
        public int $converts,
        public int $refuses,
        public int $changes,
        public array $rewritten,
        public array $refusing,
        public array $shared,
        public bool $reversible,
    ) {
    }

    /**
     * Whether any row would have to be emptied for this to happen at all.
     *
     * The question the confirmation page is really about. §7.2 is explicit that
     * emptying is only ever the customer's second choice, made with the report in
     * front of them, so this is what decides whether the page offers one button
     * or two.
     */
    public function refused(): bool
    {
        return $this->refuses > 0;
    }

    /**
     * Whether the `unique` index would refuse this whatever the customer says.
     *
     * Its own question rather than part of {@see self::refused()}, because there
     * is nothing to offer against it. Emptying answers a value the new type
     * cannot read; nothing answers two records that would end up holding one
     * value on a field that promises they cannot, except editing those records,
     * which is why the values are named.
     */
    public function blocked(): bool
    {
        return $this->shared !== [];
    }
}

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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A change to a customer's definitions that the engine will not make (§5.4).
 *
 * Every one of these is a refusal to do something that would leave data the
 * application can no longer read, save, or explain. They carry the reason in
 * full, because the person reading it is a customer changing their own module,
 * not a developer with the source open.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MetadataChangeRefused extends \RuntimeException
{
    /**
     * What to show the person who caused it, in their language (XIV-8).
     *
     * The exception's own message stays English and goes to the log, where the
     * reader is a developer; this is the half a customer sees. Two audiences,
     * two sentences, and neither has to be a compromise for the other.
     */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters, string $domain = 'xivi'): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, $domain);

        return $refusal;
    }

    public static function badKey(string $key): self
    {
        return self::of(
            sprintf(
                'A field name must start with a letter and contain only lowercase letters, numbers and '
                . 'underscores. "%s" does not.',
                $key,
            ),
            'metadata.bad_key',
            ['%key%' => $key],
        );
    }

    public static function emptyLabel(): self
    {
        return self::of(
            'A shape needs a label: it is what the navigation and every page heading call it.',
            'metadata.empty_label',
            [],
        );
    }

    public static function keyTaken(string $key, string $shape): self
    {
        return self::of(
            sprintf('"%s" already has a field named "%s".', $shape, $key),
            'metadata.key_taken',
            ['%key%' => $key, '%shape%' => $shape],
        );
    }

    public static function systemField(string $key): self
    {
        return self::of(
            sprintf(
                'The field "%s" came with the module and cannot be removed. Fields you added yourself can be '
                . '(docs/architecture.md §7.2).',
                $key,
            ),
            'metadata.system_field',
            ['%key%' => $key],
        );
    }

    /**
     * A numbering pattern that would number nothing (XIV-27).
     *
     * {@see \Xivi\Core\Numbering\NumberFormat} treats a pattern without
     * `{number}` in it as "this field is not a sequence", which is the right
     * answer for a blueprint and the wrong one for a form: somebody who has just
     * typed a pattern into the metadata editor and been told nothing would have
     * no way of telling silence from success, and would find out when their
     * first invoice came out blank.
     *
     * An emptied box lands here too, and still does after XIV-91. Turning
     * numbering *off* is a real thing now, and it is deliberately **not** this:
     * it is a page of its own that says what happens to the numbers already on
     * records before it happens ({@see MetadataEditor::setNumbering()} with
     * null). Blanking a text box is not that conversation, and reading it as
     * "off" would make the most consequential change here the one that takes the
     * least typing.
     */
    public static function patternNumbersNothing(string $pattern): self
    {
        return self::of(
            sprintf(
                'A numbering pattern has to say where the counter goes: it needs {number} in it, as in '
                . 'ORD-{year}-{number:4}. "%s" would leave this field numbering nothing.',
                $pattern,
            ),
            'metadata.pattern_numbers_nothing',
            ['%pattern%' => $pattern],
        );
    }

    /**
     * The unique half of the rule above, refused with the values named
     * (XIV-109).
     *
     * **Because a count is not actionable and this is.** "That rule would make 4
     * existing records invalid" is true and leaves somebody scrolling six
     * hundred contacts looking for four they cannot describe. The values that
     * are actually shared are the search terms — paste one into the filter bar
     * and the colliding records are on the screen — so the refusal hands them
     * over rather than making the customer derive them.
     *
     * **Refuse rather than fix, and that is the decision.** The alternatives
     * were to make the field unique anyway and leave the duplicates unsaveable —
     * which is the trap §5.4 refuses in general terms, records nobody can save
     * until they work out why — or to have the engine pick a winner and clear
     * the losers, which is data loss on a tick box. So the answer is no, with
     * enough in the sentence to make it a yes next time.
     *
     * There is no plural to handle: a value cannot be *shared* by fewer than two
     * records, so the count is always at least two.
     *
     * @param array<string, int> $duplicates value => how many records hold it, worst first.
     *                                       The caller is expected to have asked for one *more*
     *                                       than it wants shown, which is how this can tell
     *                                       "there are exactly five" from "there are at least
     *                                       five" without a second query
     * @param int                $shown      how many of them the message lists; the rest become
     *                                       an ellipsis, because a column duplicated a thousand
     *                                       ways is a column that was never meant to be unique
     *                                       and printing all of it is a refusal nobody reads
     */
    public static function valuesAreShared(string $key, int $records, array $duplicates, int $shown): self
    {
        $named = \array_slice(array_keys($duplicates), 0, $shown);

        $values = implode(', ', array_map(
            static fn (string $value): string => sprintf('"%s"', $value),
            $named,
        ));

        if (\count($duplicates) > $shown) {
            // An ellipsis rather than "and 37 others": how many *more* distinct
            // values there are would need a second query over the whole column,
            // and the reader's next action — go and fix these — is the same
            // either way.
            $values .= ' …';
        }

        return self::of(
            sprintf(
                '%d existing records already share a value in "%s", so it cannot be made unique. On more '
                . 'than one record: %s. Fix those records first, or leave "%s" as it is.',
                $records,
                $key,
                $values,
                $key,
            ),
            'metadata.values_are_shared',
            ['%count%' => $records, '%key%' => $key, '%values%' => $values],
        );
    }

    public static function wouldInvalidateRecords(string $key, int $records): self
    {
        return self::of(
            sprintf(
                'That rule would make %d existing record%s invalid, and they could not be saved again until '
                . 'somebody fixed them. Fix the records first, or leave "%s" as it is.',
                $records,
                $records === 1 ? '' : 's',
                $key,
            ),
            'metadata.would_invalidate',
            ['%count%' => $records, '%key%' => $key],
        );
    }
}

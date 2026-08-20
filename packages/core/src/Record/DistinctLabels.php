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

namespace Xivi\Core\Record;

/**
 * Telling two records apart when they are called the same thing (XIV-167).
 *
 * **One rule, because there are two places that need it and they must spell the
 * same pair of records the same way.** {@see RecordCandidates::named()} has
 * carried this since the picker was written: two records called the same thing
 * are two options a reader cannot tell apart, and in a `<select>` they were
 * worse than that, because an array keyed by label collapsed them and the second
 * became unpickable. What that guard did not cover is the *other* way a choice
 * list gets filled: {@see \Xivi\Core\Form\RecordChoiceLoader}, which is handed
 * one already-stored id at a time and so has nothing to disambiguate each of
 * them against. Two links with one title collapsed there exactly as they used to
 * collapse in the select, and an edit form showed one of the two records the
 * record actually names. Both callers now ask this, rather than one of them
 * asking and the other not.
 *
 * The id is ugly and is the only thing guaranteed to differ.
 *
 * ## Both get the suffix, and the asymmetry it replaces was not survivable
 *
 * The older rule left the *first* of a colliding pair spelled plainly and
 * suffixed the ones after it. Read on its own that is defensible: it adds the
 * smallest amount of noise that makes the pair pickable. Read across the two
 * callers it cannot work, and the reason is not taste but the order each of them
 * happens to read records in:
 *
 * - The picker reads a page of {@see RecordCandidates::find()}, and
 *   {@see \Xivi\Core\Query\QueryCompiler} ends every ordering in `id DESC` so
 *   that a paged list has a total order. Among two records with one title, the
 *   picker therefore meets the **higher** id first.
 * - The edit form walks the ids the record already stores, and §5.29 keeps that
 *   array de-duplicated and sorted **ascending**. It meets the **lower** id
 *   first.
 *
 * So under the asymmetric rule the same two records get opposite spellings on
 * the two halves of one field: the dropdown offers `Aktenregal Basis` for #48
 * and the box above it, holding the same catalogue, calls #47 that instead.
 * Nobody can be told which one they picked.
 *
 * Suffixing both takes the order out of the answer entirely. A record's label
 * depends on whether something shown beside it is called the same thing, and not
 * on which of them was read first, so the two callers agree wherever they are
 * looking at the same pair. It also reads better where it is read: `Aktenregal
 * Basis` beside `Aktenregal Basis (#47)` says that one of them is the article
 * and the other is a stray, which is a claim nobody made, and in an edit form,
 * where the reader may see only the survivors of a set rather than the whole
 * catalogue, it is the only thing they have to go on. Two suffixed labels say
 * what is true: there are two, and the id is the difference.
 *
 * The cost is that a plain select which today shows one plain label beside one
 * suffixed one now shows two suffixed ones. That is the deliberate half of the
 * change, and the alternative to paying it is a second rule for the second
 * caller, which is the shape of the bug this closes.
 *
 * ## What it still cannot do
 *
 * A record genuinely titled `Aktenregal Basis (#47)` will collide with what this
 * makes of record 47, and nothing here notices. That hole predates this class
 * and is left alone on purpose: closing it means a label that is no longer
 * explainable to the person reading it, for a title somebody would have to type
 * on purpose.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DistinctLabels
{
    /**
     * What to show for each of these records, made distinct among themselves.
     *
     * **Among themselves is the whole scope**, and it is not a compromise: the
     * suffix means "there is another one of these here", not "this record is
     * number 47". A record whose twin is not on the page has nothing to be told
     * apart from and is spelled plainly, which is what somebody scanning one
     * dropdown or one edit form wants. Disambiguating against the whole module
     * instead would put an id beside a name on forms where no two names clash,
     * which is every form in the application.
     *
     * Counted before anything is labelled, rather than as the list is walked,
     * because that is what makes the answer independent of the order it was
     * handed the records in. See the class docblock: the order was the bug.
     *
     * @param array<int, string> $titles what each record is called, by id
     *
     * @return array<int, string> what to show for it, by the same id
     */
    public static function among(array $titles): array
    {
        // Keyed by title, so a title held by more than one of them is one
        // lookup rather than a second pass per record.
        $held = array_count_values($titles);
        $labels = [];

        foreach ($titles as $id => $title) {
            $labels[$id] = ($held[$title] ?? 1) > 1
                ? sprintf('%s (#%d)', $title, $id)
                : $title;
        }

        return $labels;
    }
}

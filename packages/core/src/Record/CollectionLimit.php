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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * How many rows one collection may hold, and how that is said to somebody
 * (XIV-68).
 *
 * **Four hundred is the supported size of a collection**, and it is a decision
 * rather than a measurement — the measurement is in §5.1 and says the edit form
 * of a four-hundred-line order needs about 141 MB, on a per-row constant of
 * roughly 0.34 MB over a 1.6 MB base. Orders and invoices are usually well under
 * a hundred lines, so four hundred is ample; what it buys is that the page above
 * it is a sentence somebody can read instead of a 500 out of the middle of Twig.
 *
 * **The number lives here and nowhere else.** Three paths write collection rows
 * — the record form, the importer (XIV-26) and anything calling
 * {@see RecordWriter::save()} directly — and each says it differently: the form
 * puts a message on the form, the importer collects a problem against a sheet,
 * and the writer throws. What must not differ between them is the number and the
 * sentence, so both are here and all three ask.
 *
 * **Refused at write time rather than truncated at render time.** A record page
 * that quietly drew four hundred of five hundred lines would be a document lying
 * about itself, and an order that says 400 lines when the customer typed 500 is
 * worse than an order that refuses to be saved.
 *
 * **The read view has no bound of its own**, deliberately. It is 18 queries flat
 * at every measured size (XIV-54) and about 15 KB per row, so it survives to
 * roughly 9 500 rows — with writes capped here it is never within an order of
 * magnitude of trouble, and a second limit would be a number somebody has to keep
 * in step with this one for no benefit.
 *
 * **Nothing is rejected retroactively.** This is a rule about writes. A record
 * that somehow holds more than the cap still reads, and nothing can have produced
 * one, because until the memory limit went up (see `frankenphp/conf.d/10-app.ini`)
 * the genuine ceiling was below the cap.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionLimit
{
    /**
     * The supported number of rows in one collection.
     *
     * Per collection rather than per record: it is the length of one list that
     * the edit form's cost is linear in, and a contact with four hundred
     * addresses and four hundred of something else is two lists, each drawn at a
     * size that renders.
     */
    public const int MAX_ROWS = 400;

    /**
     * The key of the sentence, so that a caller with its own way of carrying a
     * message — {@see \Xivi\Core\Import\ImportProblem} — can hold the same one.
     */
    public const string MESSAGE = 'record.collection_too_long';

    public static function allows(int $rows): bool
    {
        return $rows <= self::MAX_ROWS;
    }

    /**
     * @param string $collection what the customer calls it — its label, not its
     *                           key, because the person reading this named it
     *                           (§5)
     *
     * @throws CollectionTooLong
     */
    public static function guard(string $collection, int $rows): void
    {
        if (!self::allows($rows)) {
            throw CollectionTooLong::holding($collection, $rows);
        }
    }

    /** The refusal, in the reader's language once somebody translates it. */
    public static function refusal(string $collection, int $rows): TranslatableMessage
    {
        return new TranslatableMessage(self::MESSAGE, self::parameters($collection, $rows), 'xivi');
    }

    /**
     * The placeholders that sentence takes.
     *
     * Exposed on their own for the importer, which carries a key and its
     * parameters rather than a `TranslatableMessage` — it wraps the problem in
     * the sheet and row it came from, and can only do that by nesting one
     * translatable inside another.
     *
     * @return array<string, mixed>
     */
    public static function parameters(string $collection, int $rows): array
    {
        return ['%collection%' => $collection, '%limit%' => self::MAX_ROWS, '%count%' => $rows];
    }
}

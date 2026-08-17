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
 * A collection was handed more rows than {@see CollectionLimit::MAX_ROWS}
 * (XIV-68).
 *
 * The same shape {@see \Xivi\Core\Lifecycle\TransitionRefused} has, for the same
 * reason: the engine has no translator and no business holding one, so a refusal
 * carries an English sentence for a log and a `TranslatableMessage` for a person.
 *
 * **It is the backstop rather than the thing a customer meets.** The form and the
 * importer both ask {@see CollectionLimit} before they write, so a person typing
 * or uploading gets the refusal on the page they are looking at. What reaches
 * here is a caller that did not ask — the demo generator, a console command, an
 * API nobody has written yet — and for those an exception is exactly right: the
 * record is not written, and nothing pretends it was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionTooLong extends \RuntimeException
{
    private TranslatableMessage $translatable;

    /** @param string $collection the customer's own label for it */
    public static function holding(string $collection, int $rows): self
    {
        $refusal = new self(sprintf(
            'Collection "%s" holds at most %d rows; %d were given.',
            $collection,
            CollectionLimit::MAX_ROWS,
            $rows,
        ));

        $refusal->translatable = CollectionLimit::refusal($collection, $rows);

        return $refusal;
    }

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }
}

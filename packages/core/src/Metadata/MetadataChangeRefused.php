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
     * An emptied box lands here too, and that is deliberate rather than
     * incidental. Turning numbering *off* on a field that has it is not a
     * shorter version of changing the pattern — every record already carries a
     * number that nothing would maintain — so it is the same follow-up question
     * as turning it on (§5.10), and until that is answered the honest response
     * to an empty pattern is this sentence.
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

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

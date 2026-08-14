<?php

declare(strict_types=1);

namespace Xivi\Core\Metadata;

/**
 * A change to a customer's definitions that the engine will not make (§5.4).
 *
 * Every one of these is a refusal to do something that would leave data the
 * application can no longer read, save, or explain. They carry the reason in
 * full, because the person reading it is a customer changing their own module,
 * not a developer with the source open.
 */
final class MetadataChangeRefused extends \RuntimeException
{
    public static function badKey(string $key): self
    {
        return new self(sprintf(
            'A field name must start with a letter and contain only lowercase letters, numbers and '
            . 'underscores. "%s" does not.',
            $key,
        ));
    }

    public static function keyTaken(string $key, string $shape): self
    {
        return new self(sprintf('"%s" already has a field named "%s".', $shape, $key));
    }

    public static function systemField(string $key): self
    {
        return new self(sprintf(
            'The field "%s" came with the module and cannot be removed. Fields you added yourself can be '
            . '(docs/architecture.md §7.2).',
            $key,
        ));
    }

    public static function wouldInvalidateRecords(string $key, int $records): self
    {
        return new self(sprintf(
            'That rule would make %d existing record%s invalid, and they could not be saved again until '
            . 'somebody fixed them. Fix the records first, or leave "%s" as it is.',
            $records,
            $records === 1 ? '' : 's',
            $key,
        ));
    }
}

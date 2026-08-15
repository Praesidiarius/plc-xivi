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

namespace Xivi\Core\Module;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * This module needs another one the customer has not got (XIV-23).
 *
 * Refused at install rather than discovered later: a module whose required link
 * has nowhere to point is one nobody can save a record in, and finding that out
 * from a validation error on an empty picker is a worse way to learn it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleRequirementMissing extends \RuntimeException
{
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param list<string> $missing */
    public static function of(string $moduleKey, array $missing): self
    {
        $names = implode(', ', $missing);

        $refusal = new self(sprintf(
            'Module "%s" needs %s, which this tenant does not have. Install %s first.',
            $moduleKey,
            $names,
            \count($missing) === 1 ? 'it' : 'them',
        ));

        $refusal->translatable = new TranslatableMessage(
            'module.requires_missing',
            ['%module%' => $moduleKey, '%missing%' => $names],
            'xivi',
        );

        return $refusal;
    }
}

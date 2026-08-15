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

namespace App\Tenant\Security;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A change to a permission group that the application will not make.
 *
 * Separate from UserChangeRefused, which is about lock-out: none of these can
 * strand an administrator, because ROLE_ADMIN is a bypass rather than a group
 * (§8.4.1). These are refusals about the group model staying comprehensible —
 * two groups with the same name is a screen where nobody can tell which one they
 * are editing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GroupChangeRefused extends \RuntimeException
{
    /**
     * What to show the person who caused it, in their language (XIV-8).
     *
     * The exception's own message stays English for the log, where the reader is
     * a developer. Two audiences, two sentences.
     */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters = []): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, 'messages');

        return $refusal;
    }

    public static function noName(): self
    {
        return self::of('A group needs a name: it is what people pick it by.', 'refusal.group_no_name');
    }

    public static function nameTaken(string $label): self
    {
        return self::of(sprintf('There is already a group called "%s".', $label), 'refusal.group_name_taken', ['%label%' => $label]);
    }
}

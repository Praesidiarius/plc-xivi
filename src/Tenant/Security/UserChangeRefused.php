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
 * A change to a user that the application will not make.
 *
 * Every one of these is a way of locking somebody — usually the person clicking —
 * out of the installation they administer. There is no support desk to phone;
 * getting back in would mean a console command against the customer's database,
 * so these are refused at the point of asking rather than regretted afterwards.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UserChangeRefused extends \RuntimeException
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

    public static function lastAdmin(): self
    {
        return self::of('This is the only active administrator. Make somebody else an administrator first.', 'refusal.last_admin');
    }

    public static function ownAdminRole(): self
    {
        return self::of('You cannot take administrator away from yourself. Ask another administrator to do it.', 'refusal.own_admin_role');
    }

    public static function ownAccount(): self
    {
        return self::of('You cannot deactivate your own account.', 'refusal.own_account');
    }

    public static function emailTaken(string $email): self
    {
        return self::of(sprintf('Somebody here already signs in as "%s".', $email), 'refusal.email_taken', ['%email%' => $email]);
    }

    public static function noEmail(): self
    {
        return self::of('A user needs an email address: it is the login.', 'refusal.no_email');
    }

    public static function wrongPassword(): self
    {
        return self::of('That is not your current password.', 'refusal.wrong_password');
    }
}

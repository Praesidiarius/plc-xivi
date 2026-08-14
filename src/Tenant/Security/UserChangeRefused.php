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
    public static function lastAdmin(): self
    {
        return new self('This is the only active administrator. Make somebody else an administrator first.');
    }

    public static function ownAdminRole(): self
    {
        return new self('You cannot take administrator away from yourself. Ask another administrator to do it.');
    }

    public static function ownAccount(): self
    {
        return new self('You cannot deactivate your own account.');
    }

    public static function emailTaken(string $email): self
    {
        return new self(sprintf('Somebody here already signs in as "%s".', $email));
    }

    public static function noEmail(): self
    {
        return new self('A user needs an email address: it is the login.');
    }

    public static function wrongPassword(): self
    {
        return new self('That is not your current password.');
    }
}

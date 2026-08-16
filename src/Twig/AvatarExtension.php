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

namespace App\Twig;

use App\Tenant\Entity\User;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `avatar(app.user)` in a template (XIV-77).
 *
 * A function taking the person rather than a property on {@see AppChrome},
 * because an avatar is about *a* user and the chrome is about *this* installation
 * and the person signed into it. The top bar is the only caller today; the user
 * list and a record's owner are the obvious next ones, and they are about
 * somebody else entirely.
 *
 * The derivation itself lives in {@see Avatar}, so it can be tested without a
 * kernel — which is where the interesting behaviour is, and this class has none.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AvatarExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('avatar', $this->avatar(...))];
    }

    public function avatar(User $user): Avatar
    {
        return Avatar::for($user->getName(), $user->getEmail());
    }
}

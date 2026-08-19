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

namespace App\Tenant\Settings;

use App\Tenant\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Xivi\Core\Time\ReaderTimezone;

/**
 * Core's question about who is reading, answered by the class that already knows
 * (XIV-136, [XIV-83]).
 *
 * The same shape {@see ProfileRegion} has, and written for the same reason: this
 * **delegates** to {@see DisplayTimezone} rather than walking the chain a second
 * time. §8.4.4's chain has four links — the person, the installation, whatever
 * their region implies, then UTC — and a second implementation of it would be
 * right on the day it was written and wrong the first time somebody changes what
 * a missing answer falls back to.
 *
 * It is also, deliberately, the *same object* {@see \App\Tenant\EventListener\DisplayTimezoneListener}
 * asks. Twig renders every loose moment on the page in the zone that listener
 * sets; a period is rendered by a field type in PHP, through this. Two answers to
 * "which zone" would show a booking's own times in one zone and the "changed at"
 * beside it in another, on the same screen, and nothing would look wrong enough
 * for anybody to check.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ReadersTimezone implements ReaderTimezone
{
    public function __construct(
        private Security $security,
        private DisplayTimezone $timezones,
    ) {
    }

    public function zone(): \DateTimeZone
    {
        // Null in a console command and on the login page, which is the ordinary
        // condition rather than a failure: the chain runs out of things to ask
        // and lands on the installation's answer, or on UTC.
        $user = $this->security->getUser();

        return $this->timezones->of($user instanceof User ? $user : null);
    }
}

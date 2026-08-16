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

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Renders every moment on the page in the zone the reader resolves to (XIV-83).
 *
 * **One setting on Twig rather than a filter on every template.** Twig's `date`
 * filter already converts whatever it is given into a configured zone before
 * formatting it — that is what `twig.date.timezone` in the bundle configuration
 * sets, and it is the reason this needed no new Twig extension and no `|date(…,
 * timezone)` argument threaded through a dozen templates. The only thing static
 * configuration cannot do is answer per reader, which is precisely what this
 * listener adds: the same knob, turned once a request, by somebody who has asked
 * who is reading.
 *
 * The alternative was `date_default_timezone_set()`, and it is rejected on
 * purpose. It would also work, and it would work on far more than display: every
 * `new \DateTimeImmutable()` in the process would start producing local-clock
 * objects, including the ones that get *written*. Those are absolute instants and
 * would still store correctly, so the damage would be quiet rather than loud —
 * which is the worst kind available. The application runs in UTC (§8.4.4) and
 * keeps running in UTC; this changes what is rendered and nothing else.
 *
 * **Below the firewall**, at a priority under Symfony's authentication listener,
 * for the same reason `UserLocaleListener` sits there: before it there is no user
 * to ask, and every page would be drawn in UTC while the setting looked broken
 * and was stored perfectly.
 *
 * **The zone outlives the request on the service, and that is watched rather
 * than fixed.** The Twig environment is shared, so what this writes is still
 * there afterwards — the same shape as the translator's locale, which
 * `UserLocaleListener` already accepts (§7.4). It is harmless because every
 * request that renders anything passes through here first and overwrites it, and
 * because a console command renders in whatever the last one left, which is UTC
 * on a process that never served a request. A worker runtime would make this
 * worth revisiting; this one is deliberately not a worker.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class DisplayTimezoneListener
{
    public function __construct(
        private Security $security,
        private DisplayTimezone $timezones,
        private Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        $this->twig->getExtension(CoreExtension::class)->setTimezone(
            $this->timezones->of($user instanceof User ? $user : null),
        );
    }
}

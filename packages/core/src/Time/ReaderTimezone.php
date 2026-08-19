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

namespace Xivi\Core\Time;

/**
 * Which zone the person currently reading the page reads moments in (XIV-136,
 * [XIV-83]).
 *
 * The same seam {@see \Xivi\Core\Region\InstanceRegion} keeps for the country and
 * {@see \Xivi\Core\Money\InstanceCurrency} for the currency: core declares the
 * question, the application answers it, and the engine goes on not knowing what a
 * user is (§5.2). One method and a total answer — there is no null here, because
 * UTC is a real zone and the end of [XIV-83]'s chain already lands on it.
 *
 * ### Why a field type needs this when nothing else did
 *
 * [XIV-83] rendered every moment on every page by turning one knob on Twig, which
 * covered `created_at`, history entries and everything else that reaches a
 * template as a `\DateTimeInterface`. A period does not reach a template that
 * way. It is *one value* that a field type turns into a sentence — "1.8.2026,
 * 09:00 – 11:00" — inside {@see \Xivi\Core\Field\FieldType::display()}, in PHP,
 * before Twig sees anything but a string. So the zone has to arrive at the type.
 *
 * **Delegation rather than a second resolver.** The application's implementation
 * asks `DisplayTimezone` — the same object the request listener asks — so there
 * is one answer to "who is reading and where are they", and the moment shown
 * inside a period and the moment shown beside it in a `|date` cannot disagree.
 * §8.4.4 makes exactly this point about `ProfileRegion` delegating to
 * `FormattingLocale`: a fourth *reader* of one setting is the same mistake in
 * cheaper clothes.
 *
 * **Display only.** Nothing here influences what is stored. A period is written
 * in UTC whoever types it, for the reason §8.4.4 gives about
 * `date_default_timezone_set()` and [XIV-114] gives about which country a phone
 * number was dialled in: storage is a function of the value, never of the
 * session.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface ReaderTimezone
{
    /**
     * A console command has no reader and lands on UTC, which is the honest
     * answer for output nobody is looking at over somebody's shoulder — and the
     * same fall-through `FormattingLocale::instanceRegion()` already makes.
     */
    public function zone(): \DateTimeZone;
}

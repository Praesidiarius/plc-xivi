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

use Xivi\Core\Region\InstanceRegion;

/**
 * The engine's "which country is this installation in" question (XIV-114),
 * answered from the region §8.6 already stores.
 *
 * The half of the boundary that belongs to the application, and deliberately the
 * same shape as {@see ProfileCurrency}, {@see ProfilePaymentTerms} and
 * {@see ProfileVatMode} beside it: core declares the interface and never learns
 * what a tenant is, so nothing in the engine can reach into a customer's settings
 * table on its own.
 *
 * **It delegates rather than reads**, which is the only thing that makes it worth
 * having as a class. `FormattingLocale::instanceRegion()` is where "which country
 * is this installation in" has been answered since [XIV-50], and a second class
 * asking the profile repository the same question would be two implementations of
 * one fact — right on the day it was written and drifting on the day somebody
 * changes what "the installation's region" means. The whole ticket was about not
 * building a fourth country setting; building a fourth *reader* of the same
 * setting would have been the same mistake in cheaper clothes.
 *
 * **The person is deliberately not in this chain.** `FormattingLocale::of()`
 * starts with the reader's own region and this starts one link down, because the
 * two are used for opposite things: how a number is *shown* is about who is
 * looking, and how it is *stored* must not be. See {@see InstanceRegion} for the
 * full argument — a French colleague at a Swiss company typing a local number is
 * typing a Swiss number.
 *
 * **Nothing is remembered between requests.** The profile is read when asked, so
 * a customer who fills in their country gets it on the next number they type,
 * and a process serving several tenants in sequence cannot hand one customer's
 * answer to the next (§7.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ProfileRegion implements InstanceRegion
{
    public function __construct(private FormattingLocale $locale)
    {
    }

    public function region(): ?string
    {
        // Null on the login page in deployments where no tenant is resolved yet,
        // and null in every console command. Both are ordinary conditions rather
        // than failures, and what they cost a phone field is that a number
        // without a country code cannot be read — which is the honest answer
        // when nobody knows which country it is from.
        return $this->locale->instanceRegion();
    }
}

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

namespace App\Store;

/**
 * One module this customer already has, as the store names it back to them
 * (XIV-140).
 *
 * **Not a {@see StoreOffer} with a flag on it, and the difference is the point
 * of the class.** An offer is composed from a blueprint the build ships and a
 * decision the control plane made; both of those can go away while a customer
 * carries on using the module, because §6.2 is emphatic that leaving the store
 * never uninstalls anything. An operator moves a module back to `development`,
 * or prices it `not_for_sale`, or a deploy stops carrying it, and the customer
 * who installed it last year still has every record in it. If "what you have"
 * were built out of what the store offers, that module would silently vanish
 * from a list whose whole promise is to say what they have, which is a section
 * that lies rather than a section that is incomplete.
 *
 * So this is read from the customer's **own** definitions and from nothing else,
 * the same source `AppChrome::getModules()` draws the navigation from. Two
 * consequences follow, and both are wanted:
 *
 * * **The label is theirs.** §6.1 stops the blueprint having a say the moment a
 *   module is installed, so a customer who renamed Contacts to Klienten reads
 *   Klienten here, exactly as they do in the bar at the top of the page. The
 *   available half of the same screen uses the blueprint's label, because
 *   nothing there is theirs to have renamed yet.
 * * **Nothing about a price or a state appears on it.** They own it. What it
 *   would cost now is not an answer to any question they are asking, and §6.5
 *   already settled that a price says what may be obtained from now on and never
 *   what is taken away.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OwnedModule
{
    public function __construct(
        public string $key,
        /** Their word for it, out of their own definitions, never the blueprint's. */
        public string $label,
        public string $icon,
        /**
         * Whether the store still has a page for it.
         *
         * False is an ordinary state rather than an error: a module withdrawn
         * from the store, or dropped from this build, is still installed and
         * still works. The card simply stops being a link, because
         * `store_module` answers 404 for a module the store does not offer and a
         * link to a page that refuses is worse than a name that sits still. Same
         * reasoning, same shape, as {@see Requirement::$offered} one file over.
         */
        public bool $offered,
    ) {
    }
}

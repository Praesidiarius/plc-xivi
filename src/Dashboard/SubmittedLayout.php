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

namespace App\Dashboard;

use Symfony\Component\HttpFoundation\Request;

/**
 * A layout as the picker posted it: which boxes are ticked, and in what order
 * (XIV-66).
 *
 * Two screens submit this form — your own dashboard on the account page and the
 * installation's default on the profile page — and they are the same form doing
 * the same arithmetic on different rows. One place to read it, because two would
 * be two chances to disagree about what an empty submission means, and *that* is
 * the question with a sharp edge on it.
 *
 * **Nothing is offered that is not available, and nothing is stored that was not
 * offered.** The keys accepted here are the ones the page drew, so a hand-edited
 * request naming a widget nobody has cannot put a key into somebody's column. It
 * would degrade harmlessly if it did — `Dashboard` drops a key nothing answers to
 * — but "harmless when it happens" is a worse property than "cannot happen", and
 * this costs one `in_array`.
 *
 * **Position is a number the reader types, not a drag handle.** Arranging with
 * numbers is unfashionable and it works with the keyboard, works without
 * JavaScript, and survives a page of eight cards without a library. Ties keep the
 * order the page drew them in, which is the priority the code declares, so a
 * reader who ticks three boxes and touches nothing else gets the default
 * arrangement of those three rather than an arbitrary one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SubmittedLayout
{
    /**
     * The keys that were ticked, in the order their positions put them.
     *
     * **An empty result is an empty layout and not a null one.** Somebody who
     * unticks everything has said they want a bare dashboard; turning that into
     * "follow the default" would silently hand them back the page they had just
     * cleared, and the checkbox would appear not to have worked. Going back to the
     * default is its own button, on both screens, precisely so that these two
     * answers stay distinguishable — which is the same reason the columns are
     * nullable rather than defaulting to a list.
     *
     * @param list<string> $available the keys the picker actually drew
     *
     * @return list<string>
     */
    public static function fromRequest(Request $request, array $available): array
    {
        /** @var array<array-key, mixed> $ticked */
        $ticked = $request->request->all('widgets');
        /** @var array<array-key, mixed> $positions */
        $positions = $request->request->all('positions');

        $chosen = [];
        $order = [];

        foreach ($available as $rank => $key) {
            if (!\in_array($key, array_map(strval(...), array_values($ticked)), true)) {
                continue;
            }

            $chosen[] = $key;

            // The position box, falling back to where the page drew it. A blank
            // or unreadable one is "leave it where it was" rather than "put it
            // first", which is what casting an empty string to an integer would
            // have quietly meant.
            $typed = isset($positions[$key]) && is_numeric($positions[$key])
                ? (int) $positions[$key]
                : $rank;

            // The rank breaks ties, so equal numbers keep the declared order
            // instead of whichever one PHP's sort happened to reach first.
            $order[$key] = [$typed, $rank];
        }

        usort($chosen, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $chosen;
    }
}

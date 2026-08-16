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

use App\Tenant\Entity\FollowUpPriority;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `follow_up_tone(priority)` in a template: which Bootstrap context a priority is
 * drawn in (XIV-82).
 *
 * ### Why the mapping is written out and not interpolated
 *
 * **Two of the three names happen to match Bootstrap's and the third does not,
 * which is the whole reason this exists.** `info` and `warning` are words this
 * application and Bootstrap both use, so a template could get them right by
 * accident — by printing `border-{{ priority.value }}` and never noticing it had
 * made the model responsible for the stylesheet. `important` is where that
 * breaks: Bootstrap has no `important` context, so the loudest priority would
 * render with no colour at all, which is precisely the one that must not go
 * quiet. It maps to `danger`.
 *
 * So the `match` below is written out in full, including the two identities.
 * Writing those down is not redundancy: it is what makes the arrow from
 * `important` to `danger` read as a decision rather than as a special case, and
 * it is what fails to compile the day a fourth priority is added.
 *
 * ### Why it is here rather than on the enum
 *
 * {@see FollowUpPriority} says so itself, at length: it is what the database
 * holds and what the write path validates, and a value object that knew about
 * `text-bg-*` would be the model reaching into the template's vocabulary — the
 * same split {@see \Xivi\Core\Permission\ModuleAction} makes when it hands out a
 * label *key* instead of a label. A Twig extension is the other side of that
 * line, and it is how this codebase already gives a template a computed answer:
 * `can()` (§8.4), `display()`, `record_title()` and `is_overdue()` are all
 * functions rather than methods somebody added to an entity.
 *
 * ### Why one function rather than two templates agreeing
 *
 * Two screens draw priorities — the record page's panel (XIV-82) and the
 * dashboard widget (XIV-81) — and the widget shipped first, with a `{% set %}` of
 * its own as an explicit stopgap. Two copies of a mapping whose entire point is
 * that it is *not* an identity is how they drift, and the drift had already
 * started: that copy read `info → secondary` where this one reads `info → info`,
 * which is a visible difference between two screens describing the same three
 * words. One function, one answer.
 *
 * ### A tone, not a class name
 *
 * What comes back is the Bootstrap *context* word, so the caller composes
 * `border-info`, `text-bg-danger` or `text-warning` from it as its own control
 * needs — the panel wants a border and a badge, the widget wants a badge.
 * Returning a finished class would mean one function per shape of control and a
 * new one every time somebody drew a priority differently.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('follow_up_tone', $this->tone(...))];
    }

    public function tone(FollowUpPriority $priority): string
    {
        return match ($priority) {
            // Identities, written out rather than assumed — see the class
            // docblock for why the two that agree still get a line each.
            FollowUpPriority::Info => 'info',
            FollowUpPriority::Warning => 'warning',
            // And the one that does not. Bootstrap's loudest context is named
            // after the situation rather than after the feeling, so "important"
            // arrives here and leaves as "danger".
            FollowUpPriority::Important => 'danger',
        };
    }
}

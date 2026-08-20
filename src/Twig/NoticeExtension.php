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

use App\Registry\Entity\NoticePriority;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `notice_tone(priority)` in a template: which Bootstrap context an every-page
 * notice is drawn in (XIV-166).
 *
 * ### Why a mapping exists at all when all four arms are identities
 *
 * This is {@see FollowUpExtension}'s argument with the awkward case removed, and
 * removing it makes the argument *more* necessary rather than less. There, one of
 * three priorities was called `important`, Bootstrap had no such context, and the
 * mapping visibly had to exist. Here every one of the four priorities is spelled
 * exactly like the context it lands in, so a template could print
 * `alert-{{ notice.priority.value }}` and be right today, for ever, until the
 * afternoon somebody adds a fifth case with a name of its own and every banner
 * carrying it loses its colour on a page nobody was testing.
 *
 * The identity is the trap, not the exemption. Interpolating the enum's stored
 * value into a class attribute makes {@see NoticePriority} responsible for
 * Bootstrap's vocabulary: rename a case and the stylesheet breaks; add one and
 * nothing tells you. Writing the four arms out means the day a fifth arrives, PHP
 * refuses to compile this file, which is the earliest and loudest place that news
 * can possibly land.
 *
 * ### Why here rather than on the enum
 *
 * The same split {@see FollowUpExtension} makes and for the same reason:
 * {@see NoticePriority} is what the database holds and what the publish path
 * validates, and an entity that knew about `alert-*` would be the model reaching
 * into the template's vocabulary. `ValueTone::label()` draws the same line one
 * package over by handing out a translation key instead of a word.
 *
 * ### A tone, not a class name, and `text-bg-*` is not it
 *
 * What comes back is Bootstrap's context word, so the caller composes what its
 * own control needs. The banner composes `alert alert-{tone}`, and that choice is
 * §5.26's rule rather than a preference: Bootstrap 5.3 builds `.alert-info` out of
 * `--bs-info-text-emphasis`, `--bs-info-bg-subtle` and `--bs-info-border-subtle`,
 * and redefines all three under `[data-bs-theme=dark]`, so an alert follows the
 * theme without this application knowing there are two of them. `text-bg-*` pins a
 * foreground against a *fixed* brand colour and is not redefined there, which is
 * how you get a banner that is legible on a light page and wrong on a dark one.
 * Nothing this ticket added uses it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('notice_tone', $this->tone(...))];
    }

    public function tone(NoticePriority $priority): string
    {
        return match ($priority) {
            // Four identities, written out one line each rather than assumed.
            // See the class docblock: the point of writing down a mapping that
            // happens to be the identity is that it stops being the model's job
            // to keep agreeing with a stylesheet, and it is what fails loudly
            // when a fifth priority arrives.
            NoticePriority::Info => 'info',
            NoticePriority::Warning => 'warning',
            NoticePriority::Success => 'success',
            NoticePriority::Danger => 'danger',
        };
    }
}

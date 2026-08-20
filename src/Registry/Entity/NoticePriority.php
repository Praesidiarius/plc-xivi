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

namespace App\Registry\Entity;

/**
 * How loud a notice on the every-page channel is drawn (XIV-166,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * ## §8.16 said no to this, and the sentence it said no to was a good one
 *
 * The bullet read *"no severity, no colour, no icon per notice"*, and the
 * argument behind it was that an operator writing a notice does not know how loud
 * it needs to be in somebody else's day, so one weight is honest and four
 * weights invite everything to become a warning. That is still true of the
 * dashboard, and the dashboard has not changed: every card in the notices widget
 * is drawn the same way it was on the day XIV-120 shipped.
 *
 * **What changed is that there are now two channels.** Once an operator is
 * choosing between *"on their dashboard"* and *"on every page they open"*, the
 * hard question the old bullet was refusing to answer has already been answered
 * by {@see NoticeReach}: how much of somebody's attention this is worth is the
 * reach, and picking the loud one is the loud decision. Priority is then a much
 * smaller question, and a different one: given that this is going in front of
 * somebody on every page, **what kind of thing is it?** A planned maintenance
 * window and a failed payment both belong on the loud channel and are not the
 * same news, and drawing them identically is not restraint, it is withholding the
 * one fact the reader needs first.
 *
 * ## Which is why priority draws nothing on the dashboard
 *
 * A dashboard notice carries a priority, because the column is on the row and an
 * operator may move a notice between channels by publishing a different one. It
 * is simply not drawn there: the widget's cards stay plain, and that is the half
 * of §8.16's bullet that survives intact. The operator's form says so, so that
 * choosing `Danger` on a dashboard notice is a choice somebody makes knowing
 * where it lands rather than a colour they expected and did not get.
 *
 * ## Four, and they are Bootstrap's words on purpose
 *
 * The four cases are named after the four contexts the banner is drawn in, and
 * that is exactly the trap {@see \App\Twig\FollowUpExtension} was written to
 * avoid: when the model's words and the stylesheet's words agree, a template can
 * print `alert-{{ priority.value }}` and be right by accident, at which point the
 * enum has quietly become responsible for the CSS. So the mapping is written out
 * in full in {@see \App\Twig\NoticeExtension::tone()}, all four arms including the
 * identities, and the day a fifth priority arrives it is a compile error rather
 * than a colourless banner.
 *
 * **No icon.** The third clause of §8.16's bullet is kept: the banner names its
 * priority in words beside the colour, which is what makes it readable to
 * somebody who cannot see the colour, and an icon would be the same fact drawn a
 * third time on a band that is already interrupting somebody.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum NoticePriority: string
{
    /** Something is happening and there is nothing to do about it. The default. */
    case Info = 'info';

    /** Something is about to happen that the reader may want to act before. */
    case Warning = 'warning';

    /**
     * Something that was wrong is now right.
     *
     * The odd one of the four, and it is here because the alternative is worse:
     * an installation that has been carrying a red banner for two days needs a
     * way to say *"that is over"* that is as visible as the thing it ends, and
     * withdrawing the red one silently leaves everybody who saw it wondering.
     */
    case Success = 'success';

    /** Something is wrong now. A failed payment, a lock coming on Friday. */
    case Danger = 'danger';

    /**
     * A key in the `messages` domain.
     *
     * A key rather than a word, {@see NoticeAudience} and `ValueTone::label()`'s
     * line: what a priority is called is the template's business and the enum is
     * what the database holds. It matters more here than usual, because this
     * string is drawn on a customer's page in the customer's own language while
     * the notice's title and body are in whatever language the operator wrote
     * them in.
     */
    public function labelKey(): string
    {
        return 'notice.priority.' . $this->value;
    }
}

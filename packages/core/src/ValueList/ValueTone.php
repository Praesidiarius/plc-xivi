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

namespace Xivi\Core\ValueList;

/**
 * The colour a list entry may carry (XIV-127).
 *
 * **Named, not free, and the boundary is not a matter of taste.** The obvious
 * alternative is a colour picker writing a hex value into the row, and it fails
 * on one fact about this application: §8.3 renders every page in Bootstrap 5.3,
 * which has two themes, and a hex a customer chose in the light one is a hex the
 * dark one still has to read. `#f5f5f5` picked for "archived" against a white
 * page is invisible against a dark one, and nothing anywhere would report it —
 * the customer who picked it is not the customer who reads it at night.
 *
 * So the palette is exactly **the colours the theme has a dark answer for**.
 * Bootstrap 5.3 defines, for each of these eight and for nothing else, the trio
 * a chip needs — `--bs-{tone}-bg-subtle`, `--bs-{tone}-text-emphasis` and
 * `--bs-{tone}-border-subtle` — and **redefines all three under
 * `[data-bs-theme=dark]`**. A badge composed of those three follows the theme
 * without anything here knowing there are two, which is the same trick
 * `.follow-up-priority` already plays with `--bs-danger` in `app.css` (XIV-84).
 * Eight is therefore an answer rather than a round number: a ninth would be a
 * colour with no dark counterpart, which is the thing being avoided.
 *
 * **Also the reason this is an enum rather than a free string column.** What
 * comes out of here is interpolated into a class name in a template. A free
 * string would be a customer's typing in the page's markup, and a *wrong* free
 * string would be a class Bootstrap has never heard of: a chip that silently
 * renders with no colour at all, which is §8.3.1's failure — a control that
 * appears to work and does nothing.
 *
 * **A tone, not a class name**, on {@see \App\Twig\FollowUpExtension}'s
 * reasoning: what comes back is the context word, and the caller composes
 * `bg-{tone}-subtle` or `text-{tone}-emphasis` from it as its own control needs.
 * Returning a finished class would mean one enum per shape of control.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ValueTone: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Success = 'success';
    case Danger = 'danger';
    case Warning = 'warning';
    case Info = 'info';
    case Light = 'light';
    case Dark = 'dark';

    /**
     * The tone named by a stored value, or null for "no colour".
     *
     * Null rather than a default, and an unrecognised string reads as null too:
     * a row hand-edited to say `chartreuse` should draw a plain label, not throw
     * on a page somebody is reading. The colour is decoration on top of a value
     * that means the same thing without it.
     */
    public static function tryOf(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * The translation key naming this colour to a customer, in the `xivi`
     * domain.
     *
     * A key rather than a word, on {@see \Xivi\Core\Permission\ModuleAction}'s
     * line: what a colour is called is the template's business, and the enum is
     * what the database holds.
     */
    public function label(): string
    {
        return 'value_list.tone.' . $this->value;
    }

    /** @return array<string, string> stored value => translation key, for a select */
    public static function settable(): array
    {
        $tones = [];

        foreach (self::cases() as $tone) {
            $tones[$tone->value] = $tone->label();
        }

        return $tones;
    }
}

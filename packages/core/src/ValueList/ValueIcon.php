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
 * The little picture a list entry may carry (XIV-127).
 *
 * **Bounded for the same two reasons {@see ValueTone} is, and one more.** The
 * name goes into `class="bi bi-{icon}"`, so a free string is a customer's typing
 * in the page's markup; and a *wrong* free string is worse here than it is for a
 * colour, because Bootstrap Icons has no fallback glyph — `bi-widget` renders
 * nothing at all, an empty box of whitespace where the customer expected a
 * picture, with nothing on any screen saying why.
 *
 * The extra reason is that the icons are a **font**, and this installation ships
 * one file of it (`bootstrap-icons.min.css`, `importmap.php`). Every name here
 * is one that file actually defines. An unbounded picker would be a picker over
 * two thousand names of which the customer can only usefully tell twelve apart
 * anyway, and picking from twelve is a decision somebody makes in a second.
 *
 * **They take `currentColor`**, which is why nothing about dark mode is said
 * here and everything about it is said in {@see ValueTone}: a glyph inherits the
 * colour of the text it sits in, so an icon inside a chip drawn in
 * `text-{tone}-emphasis` is drawn in that colour too, in both themes, without a
 * second decision.
 *
 * The twelve are deliberately *generic*. A shared list is "our regions", "our
 * payment terms", "our topics" — nothing here knows what a customer's list is
 * about, so what is offered is shapes and a few plain nouns rather than an
 * attempt to guess the domain.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ValueIcon: string
{
    case Circle = 'circle-fill';
    case Square = 'square-fill';
    case Star = 'star-fill';
    case Flag = 'flag-fill';
    case Place = 'geo-alt-fill';
    case Building = 'building';
    case Person = 'person-fill';
    case Box = 'box-seam';
    case Delivery = 'truck';
    case Tools = 'tools';
    case Done = 'check-circle-fill';
    case Attention = 'exclamation-triangle-fill';

    /** The icon named by a stored value, or null — see {@see ValueTone::tryOf()}. */
    public static function tryOf(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** The translation key naming this icon to a customer, in the `xivi` domain. */
    public function label(): string
    {
        return 'value_list.icon.' . $this->name;
    }

    /** @return array<string, string> stored value => translation key, for a select */
    public static function settable(): array
    {
        $icons = [];

        foreach (self::cases() as $icon) {
            $icons[$icon->value] = $icon->label();
        }

        return $icons;
    }
}

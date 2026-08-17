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

namespace Xivi\Core\Field;

use Xivi\Core\Entity\FieldDefinition;

/**
 * Whether somebody types to narrow a field's candidates (XIV-36).
 *
 * **An option, not a field type**, and that is the whole argument this class
 * exists to hold. A field type owns what a value *means*: how it is stored, what
 * validates it, which operators it filters with, how it prints. XIV-22 drew that
 * line when the engine grew `decimal` — `integer`, `decimal` and `currency` are
 * the same string in the database and differ in what they print, and the
 * difference that earned a new type was meaning rather than appearance.
 *
 * Autocomplete is not a meaning. It is how the same value gets picked. An
 * `autocomplete_choice` type would copy `choice`'s storage, its constraints, its
 * operators and its display and differ in one method — and from the day it
 * existed, a customer who wanted to switch a field over would be doing a data
 * migration through the metadata editor (§5.4 refuses changes that strand data)
 * rather than ticking a box. The registry is a small closed set on purpose
 * (§5); a type per widget is how it stops being one.
 *
 * The test that keeps this honest, and it is worth restating wherever this
 * option is read: **turning it on changes nothing about what is stored, what
 * validates, how the field filters or how it exports.** What changes is which
 * candidates the widget can offer and how somebody reaches them.
 *
 * **Three states rather than a flag.** The engine knows how many candidates
 * there are, so the default is to decide: a plain select while the count is
 * small, a search box once it is not. A customer should not have to discover a
 * setting because their contact list grew past a number they never see. The
 * explicit override still earns its place, because the count is not the only
 * reason to want typing — and `never` is what a field with four options wants
 * forever, whatever happens to it later.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum Autocomplete: string
{
    /** Decided from the candidate count, and the default. */
    case Auto = 'auto';

    /** A search box however few candidates there are. */
    case Always = 'always';

    /** A plain select however many there are. */
    case Never = 'never';

    /** Where the answer lives in a field definition's options. */
    public const string OPTION = 'autocomplete';

    /**
     * The count above which `auto` stops offering a plain dropdown.
     *
     * A dropdown is usable while somebody can find the entry they want by
     * looking — which is about a screenful, and a browser draws roughly twenty
     * rows of one before it starts scrolling. Past that, scanning turns into
     * hunting, and hunting is what typing is for.
     *
     * One number for both kinds of field, deliberately. A `choice` with thirty
     * options and a `reference` with thirty records are the same problem for the
     * person looking at them, and two numbers would be two things to justify
     * separately for a difference nobody can see.
     */
    public const int AUTO_ABOVE = 20;

    /**
     * What a field asks for, defaulting to auto.
     *
     * Anything unrecognised is auto too — an option typed into a blueprint by
     * hand, or one left behind by a spelling that has since changed, should
     * leave the field working rather than raise on a page that is only trying to
     * draw an input.
     */
    public static function of(FieldDefinition $field): self
    {
        $set = $field->getOption(self::OPTION);

        return \is_string($set) ? (self::tryFrom($set) ?? self::Auto) : self::Auto;
    }

    /**
     * Whether this many candidates should be typed at rather than scrolled
     * through.
     *
     * The count is the *whole* count and not the page in hand, which matters:
     * a picker that showed the first two hundred of nine thousand is exactly the
     * case this exists for, and asking it about two hundred would answer about
     * the ceiling instead of about the data.
     */
    public function wants(int $candidates): bool
    {
        return match ($this) {
            self::Always => true,
            self::Never => false,
            self::Auto => $candidates > self::AUTO_ABOVE,
        };
    }

    /**
     * The choices the metadata editor offers, as stored value => label key in
     * the engine's own catalogue (XIV-8).
     *
     * **Auto is the empty value**, deliberately, because that is what an unset
     * option already means: absent and `auto` would otherwise be two spellings
     * of one answer, and two spellings are one more than anything reading these
     * definitions has to compare. It is the same shape the width control takes
     * (XIV-43), where blank means "however wide this kind of field usually is"
     * and is the right answer for almost every field.
     *
     * Keys in the `xivi` domain rather than the application's, like
     * {@see \Xivi\Core\Query\Operator::labelKey()}: these are words the engine
     * chose, and the engine should not need the application's catalogue to say
     * them.
     *
     * @return array<string, string>
     */
    public static function settable(): array
    {
        return [
            '' => 'autocomplete.auto',
            self::Always->value => 'autocomplete.always',
            self::Never->value => 'autocomplete.never',
        ];
    }
}

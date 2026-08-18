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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * An install the store will not perform (XIV-6).
 *
 * Every one of these is also checked on the screen before the button is drawn,
 * and that is not duplication for its own sake. The screen is a courtesy; this is
 * the check. A store page is a GET somebody can keep open while a colleague
 * installs the same module in the next tab, and the install itself is a POST that
 * can be retyped — so the state the page was drawn from is never the state the
 * install happens in.
 *
 * The refusals are worded as guidance rather than as errors because every one of
 * them has an obvious next step: install the requirement first, or nothing,
 * because you already have it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StoreInstallRefused extends \RuntimeException
{
    /**
     * What to show the person who caused it, in their language (XIV-8). The
     * exception's own message stays English for the log, whose reader is a
     * developer — the same two audiences GroupChangeRefused splits.
     */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters = []): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, 'messages');

        return $refusal;
    }

    /**
     * Already theirs.
     *
     * The store says so rather than erroring, and the installer would in any case
     * hand back what is there untouched — a preset only ever seeds a *new*
     * installation (§6.1). Saying "you have this" is the honest report of that;
     * a success flash would claim a preset had been applied when nothing was.
     */
    public static function alreadyInstalled(string $label): self
    {
        return self::of(
            sprintf('Module "%s" is already installed.', $label),
            'store.refusal.already_installed',
            ['%module%' => $label],
        );
    }

    /**
     * It costs money, so the store will not install it (XIV-102).
     *
     * **The one refusal here that is not guidance about a next step the customer
     * can take on their own**, which is why it names the one they can: ask, and
     * somebody gets in touch. That is the entire placeholder — there is no
     * payment gateway in this system, deliberately, and a refusal that hinted at
     * one would be worse than this sentence.
     *
     * Reachable in three ways, only one of which is a hand-typed request: a page
     * loaded before an operator set a price and submitted after, a form retyped
     * out of a browser's history, and somebody guessing the URL. The first is
     * ordinary and is why this is worded as information rather than as an
     * accusation.
     */
    public static function costsMoney(string $label): self
    {
        return self::of(
            sprintf('Module "%s" has a price, so it cannot be installed from the store.', $label),
            'store.refusal.costs_money',
            ['%module%' => $label],
        );
    }

    /** @param list<string> $missing labels, as the customer reads them */
    public static function requirementsMissing(string $label, array $missing): self
    {
        return self::of(
            sprintf('Module "%s" needs %s first.', $label, implode(', ', $missing)),
            'store.refusal.requirements_missing',
            ['%module%' => $label, '%missing%' => implode(', ', $missing)],
        );
    }

    /**
     * A preset the module does not offer.
     *
     * Only reachable by hand-editing the form, and refused rather than quietly
     * falling back to the default: silently installing a different shape from the
     * one somebody asked for is the worst possible outcome of a decision nothing
     * can undo.
     */
    public static function noSuchPreset(string $label): self
    {
        return self::of(
            sprintf('That is not one of the ways "%s" can be installed.', $label),
            'store.refusal.no_such_preset',
            ['%module%' => $label],
        );
    }
}

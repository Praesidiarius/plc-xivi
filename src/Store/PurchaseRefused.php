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
 * A purchase request the store will not write down (XIV-102).
 *
 * {@see StoreInstallRefused}'s sibling, and its docblock is this one's: the
 * screen is a courtesy and this is the check, because a store page is a GET
 * somebody can leave open while the world moves underneath it. Two of the three
 * refusals here are reachable without anybody typing a URL — a colleague
 * installing the module in the next tab, an operator making it free while
 * somebody is reading the price.
 *
 * A separate exception rather than more cases on `StoreInstallRefused`, and that
 * is the same argument the class it names makes about itself: an install and a
 * purchase request are different acts with different outcomes, and a controller
 * that caught one type for both would be one `catch` away from telling somebody
 * their module was installed when what happened is that a wish was recorded.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PurchaseRefused extends \RuntimeException
{
    /**
     * What to show the person who caused it, in their language (XIV-8), while
     * the exception's own message stays English for whoever reads the log.
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

    /** They have it. Asking to buy something you already have needs saying rather than storing. */
    public static function alreadyInstalled(string $label): self
    {
        return self::of(
            sprintf('Module "%s" is already installed.', $label),
            'store.refusal.already_installed',
            ['%module%' => $label],
        );
    }

    /**
     * It does not cost money — most interestingly because it stopped costing
     * money while somebody was reading the page.
     *
     * The wording sends them back to look rather than explaining the four pricing
     * states, because from where they are standing the useful fact is that the
     * page has changed and the thing they wanted may now simply be installable.
     */
    public static function notForSale(string $label): self
    {
        return self::of(
            sprintf('Module "%s" is not something this installation is selling.', $label),
            'store.refusal.not_purchasable',
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
}

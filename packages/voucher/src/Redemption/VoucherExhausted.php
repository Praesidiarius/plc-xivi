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

namespace Xivi\Voucher\Redemption;

/**
 * The refusal a voucher that has been used up comes back as (XIV-103).
 *
 * An exception rather than a `false`, because the caller is in the middle of a
 * transaction that is about to write a discounted order and there is no sensible
 * way to continue: a redemption that did not happen must not become an order
 * that says it did. A boolean invites a caller to forget the branch, and the
 * branch it invites them to forget is the one that gives money away.
 *
 * It carries the numbers rather than a sentence, because the sentence belongs to
 * whoever is drawing a page — the message a customer reads when a code is
 * refused at checkout ([XIV-104]) is not the same message an administrator reads
 * on the voucher's own record, and neither of them is this package's to write.
 *
 * The count in it is a **read taken after the refusal**, which is worth knowing
 * before quoting it as a fact: another redemption may have landed in between, so
 * the number can only be at or above the one that caused this. It is right for a
 * message and wrong for a decision, which is true of every count in this feature
 * that is not inside {@see VoucherRedemptions::redeem()}'s own statement.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherExhausted extends \RuntimeException
{
    private function __construct(
        public readonly int $voucherId,
        public readonly int $redeemed,
        public readonly ?int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function after(int $voucherId, int $redeemed, ?int $limit): self
    {
        return new self($voucherId, $redeemed, $limit, sprintf(
            'Voucher #%d allows %s and has been redeemed %d time(s).',
            $voucherId,
            $limit === VoucherRedemptions::UNLIMITED
                // Unreachable from `redeem()` — an unlimited voucher's guard
                // cannot fail — and spelled out anyway, because a limit of zero
                // reaches this constructor and a reader meeting "allows 0" would
                // otherwise have to work out which of the two absences it was.
                ? 'unlimited redemptions'
                : sprintf('%d redemption(s)', $limit),
            $redeemed,
        ));
    }
}

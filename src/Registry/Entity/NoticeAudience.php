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
 * Who inside a customer's installation a notice is for (XIV-120,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * **Per notice rather than per installation**, which is the decision worth
 * stating because the alternative is one line shorter. *"This installation will
 * be unavailable on Sunday"* is for everybody who might sit down to work on
 * Sunday; *"your trial ends in a week"* is for whoever pays the bill, and
 * putting it on the screen of a colleague who cannot act on it is either noise
 * or an awkward conversation somebody did not choose to start. A global rule
 * would have to pick one of those and be wrong about the other every time.
 *
 * **Two cases, and the second one is coarse on purpose.** A tenant's own
 * authority model is §8.4's grants — resolved per person, per module, per verb —
 * and none of it describes *"the person who pays"*, because nothing in the
 * product has ever needed to. `ROLE_ADMIN` is the nearest true thing an
 * installation knows: whoever set it up and manages its users. That is honest
 * for a trial ending and would be dishonest dressed up as anything finer, so
 * this enum says administrators and means it.
 *
 * **A grant was considered and refused.** A `@notices` permission area (§8.4.3)
 * would let a customer decide for themselves who reads announcements — and would
 * therefore let a customer switch them off, which is a channel the operator is
 * relied upon to have. The addressing belongs to the sender here, which is the
 * one place in this product where that is true, and it is true because the
 * sender is the party running the installation.
 *
 * **A third case is a column change and nothing else**, which is why this is a
 * short string rather than a boolean.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum NoticeAudience: string
{
    /** Every user of the installation. A maintenance window, a release note. */
    case Everyone = 'everyone';

    /**
     * Only users holding `ROLE_ADMIN`.
     *
     * Not "only the owner" and not "only the billing contact", neither of which
     * a tenant records — see the class docblock for why the coarseness is stated
     * rather than papered over.
     */
    case Administrators = 'administrators';

    /**
     * A key in the `messages` domain, so the operator's form and the customer's
     * card can name this without either of them holding an English string.
     */
    public function labelKey(): string
    {
        return 'notice.audience.' . $this->value;
    }

    /**
     * Which audiences a reader with (or without) `ROLE_ADMIN` may be shown.
     *
     * Expressed as the *set a query filters on* rather than as a per-notice
     * `mayBeSeenBy()`, deliberately: the reader's question is asked of a database
     * with a `WHERE`, and a predicate that could only be applied after loading
     * every notice in the installation would be a filter in the wrong place —
     * and the kind that quietly stops being applied at all when somebody adds a
     * second caller.
     *
     * @return list<self>
     */
    public static function visibleTo(bool $administrator): array
    {
        return $administrator ? [self::Everyone, self::Administrators] : [self::Everyone];
    }
}

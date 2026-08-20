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

namespace Xivi\ControlPlane\View;

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeReach;
use App\Registry\Entity\NoticeRecipient;

/**
 * One notice as the operator's screen draws it (XIV-120, §8.16).
 *
 * A view model for {@see PurchaseIntentListing}'s reason, which is the alarming
 * one: a {@see Notice} addressed to named customers holds {@see NoticeRecipient}
 * rows, each of which holds a `Tenant` — and a `Tenant` holds the customer's
 * **encrypted database credential**. §8.10's whole defence is that such a row
 * cannot reach an HTML page, and the way that is guaranteed is that the template
 * is never handed one. So the slug and the name cross and the entity does not.
 *
 * It also settles **what "live" means** in one place, in PHP, where the answer
 * can carry a comment. The screen's job is to let an operator see what is
 * currently being shown to customers, and that is a question about a moment: a
 * notice is live when it has been published and has not expired or been
 * withdrawn ({@see Notice::isLiveAt()}). Working it out in the template would put
 * a second copy of that predicate in Twig, one refactor away from disagreeing
 * with the one the customers' dashboards actually run.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NoticeListing
{
    /**
     * @param list<array{slug: string, name: string}> $recipients the customers
     *                                                            this notice
     *                                                            names, empty when
     *                                                            it is addressed to
     *                                                            everybody
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $authorLabel,
        /** A key in the `messages` domain — the audience is drawn in the operator's language, not stored in it. */
        public string $audienceKey,
        /** The same, for where it appears (XIV-166). */
        public string $reachKey,
        /**
         * And for how loud it is drawn there.
         *
         * A key rather than the enum, like the two above, and there is a second
         * reason here that is worth spelling out: what the operator's *table*
         * wants is the word, whereas what a customer's banner wants is the word
         * *and* a Bootstrap context. Handing this screen the context as well
         * would put `notice_tone()` on a page that draws no banner and invite a
         * coloured row, which is the operator's list quietly acquiring an
         * opinion about severity that §8.16 gives only to the loud channel.
         */
        public string $priorityKey,
        /** Whether it goes in front of people everywhere, which is what the screen leads the cell with. */
        public bool $everyPage,
        public bool $everyTenant,
        public array $recipients,
        public \DateTimeImmutable $publishedAt,
        public ?\DateTimeImmutable $expiresAt,
        /** Whether customers are being shown this right now — see the class docblock. */
        public bool $live,
    ) {
    }

    public static function of(Notice $notice, \DateTimeImmutable $now): self
    {
        $recipients = [];

        foreach ($notice->getRecipients() as $recipient) {
            $tenant = $recipient->getTenant();

            // The slug and the name, and nothing else about the customer. The
            // entity stops here; see the class docblock.
            $recipients[] = [
                'slug' => $tenant->getSlug(),
                'name' => $tenant->getName(),
            ];
        }

        // Ordered by slug so that two renders of an unchanged notice produce the
        // same list. A screen whose rows shuffle between reloads is one nobody
        // can compare against yesterday's.
        usort($recipients, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return new self(
            id: (int) $notice->getId(),
            title: $notice->getTitle(),
            body: $notice->getBody(),
            authorLabel: $notice->getAuthorLabel(),
            audienceKey: $notice->getAudience()->labelKey(),
            reachKey: $notice->getReach()->labelKey(),
            priorityKey: $notice->getPriority()->labelKey(),
            everyPage: $notice->getReach() === NoticeReach::EveryPage,
            everyTenant: $notice->isForEveryTenant(),
            recipients: $recipients,
            publishedAt: $notice->getPublishedAt(),
            expiresAt: $notice->getExpiresAt(),
            live: $notice->isLiveAt($now),
        );
    }

    /**
     * How many customers this was addressed to, or null when it went to every
     * one of them.
     *
     * Null rather than a count of all the tenants there are, which would be a
     * number that changes after the fact: a notice addressed to everybody in
     * March reached the customers of March, and printing today's total beside it
     * would be quietly inventing a figure nobody ever sent anything to. The
     * screen says "every customer" for this case instead.
     */
    public function addressedCount(): ?int
    {
        return $this->everyTenant ? null : \count($this->recipients);
    }
}

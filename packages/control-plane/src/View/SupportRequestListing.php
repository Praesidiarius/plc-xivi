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

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;

/**
 * One customer's question, as the operator's screen draws it (XIV-123, §8.17).
 *
 * **A readonly object of scalars, and the `Tenant` entity does not reach the
 * template.** §8.10's rule and its reason: a tenant row carries the customer's
 * encrypted database credential and their DSN, and the defence against either
 * arriving in somebody's HTML — through a `|json_encode`, a stray `dump()`, a
 * profiler panel on a page that gets pasted into a chat — is a type that does
 * not contain them rather than care in a template. {@see TenantSummary} exists
 * for exactly this and this class holds the two fields it needs of it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SupportRequestListing
{
    public function __construct(
        public int $id,
        public string $tenantSlug,
        public string $tenantName,
        public string $subject,
        public string $body,
        public \DateTimeImmutable $raisedAt,
        public \DateTimeImmutable $collectedAt,
        public SupportStatus $status,
        public ?string $reply,
        public ?\DateTimeImmutable $repliedAt,
        public ?string $replyAuthorLabel,
    ) {
    }

    public static function of(SupportRequest $request): self
    {
        return new self(
            (int) $request->getId(),
            $request->getTenant()->getSlug(),
            $request->getTenant()->getName(),
            $request->getSubject(),
            $request->getBody(),
            $request->getRaisedAt(),
            $request->getCollectedAt(),
            $request->getStatus(),
            $request->getReply(),
            $request->getRepliedAt(),
            $request->getReplyAuthorLabel(),
        );
    }

    /** Whether somebody still owes this customer an answer. */
    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }
}

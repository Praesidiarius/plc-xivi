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

namespace App\Registry\Support;

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the operator has said about this customer's tickets (XIV-123,
 * docs/architecture/identity-and-access.md §8.17).
 *
 * **The one query on a customer's request path that touches the control plane
 * for this feature**, and the reason the return leg of the feature is
 * immediate. The question travels outward through a collector, because §4.4
 * gives a customer's instance no way to write over here; the *answer* comes back
 * through this class, because reading the registry is exactly what that grant
 * already permits. An operator who replies at 14:03 has replied on the
 * customer's screen at 14:03, with no interval anywhere in it.
 *
 * ## Why this is a plain service and not a `ServiceEntityRepository`
 *
 * {@see \App\Registry\Notice\LiveNotices}'s reason, and it is the same claim
 * being made checkable. *"A customer's instance needs no privilege it has not
 * already got"* is worth what it can be tested with, and the honest test is to
 * run **this class** against **that role** — which a `ServiceEntityRepository`
 * makes impossible, because it resolves its own connection out of the
 * `ManagerRegistry` and would therefore only ever be exercised as whoever the
 * suite connects as. Taking the entity manager as a constructor argument is what
 * lets `tests/Functional/Deployment/SupportGrantsTest.php` hand it a connection
 * as the restricted role and let PostgreSQL answer.
 *
 * ## Scoped in the query, not in the template
 *
 * The tenant is a `WHERE` clause. A support ticket's body is a customer telling
 * whoever runs their installation what is wrong with their business software,
 * which is about as far from *"and it does not matter if the wrong company sees
 * it"* as this product gets — so the scope is applied where a mistake is a
 * missing row rather than where a mistake is a drawn one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectedTickets
{
    public function __construct(
        /**
         * The control plane's, which is the default manager here — a collected
         * ticket is a registry row (§3.1) and this is the only database it is in.
         */
        private EntityManagerInterface $control,
    ) {
    }

    /**
     * Everything collected from this customer, keyed by the reference their own
     * row carries.
     *
     * Keyed rather than a list because the only caller is joining it to the
     * customer's own tickets, one lookup per ticket — see
     * {@see \App\Tenant\Support\SupportTickets}, which is where the two databases
     * meet.
     *
     * @return array<string, SupportRequest>
     */
    public function forTenant(Tenant $tenant): array
    {
        $query = $this->control->createQuery(
            <<<'DQL'
                SELECT s FROM App\Registry\Entity\SupportRequest s
                WHERE s.tenant = :tenant
                DQL,
        );

        $query->setParameter('tenant', $tenant->getId());

        /** @var list<SupportRequest> $rows */
        $rows = $query->getResult();

        $byReference = [];

        foreach ($rows as $row) {
            $byReference[$row->getReference()] = $row;
        }

        return $byReference;
    }
}

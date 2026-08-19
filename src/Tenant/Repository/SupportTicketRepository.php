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

namespace App\Tenant\Repository;

use App\Tenant\Entity\SupportTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The questions this customer has asked whoever runs their installation
 * (XIV-123).
 *
 * Resolves through the `tenant` manager, like every repository in this
 * namespace, so a ticket is only ever read out of the database of the customer
 * being served (§8.1). That is load-bearing rather than tidy: the collector that
 * puts these in front of an operator
 * ({@see \Xivi\ControlPlane\Support\SupportTicketCollector}) calls
 * {@see self::newestFirst()} once per tenant *inside* a
 * `TenantSwitcher::runFor()`, so the same method answers about a different
 * customer each time round the loop and about none at all outside one.
 *
 * @extends ServiceEntityRepository<SupportTicket>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class SupportTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportTicket::class);
    }

    /**
     * Everything this customer has raised, newest first.
     *
     * The whole list rather than a page of it. A support queue for one company
     * is a list of tens over the life of an installation, and paging it would be
     * furniture on a page that is usually nearly empty — [XIV-58]'s judgement
     * about the tenant list, one screen over and with a smaller number.
     *
     * @return list<SupportTicket>
     */
    public function newestFirst(): array
    {
        return $this->findBy([], ['raisedAt' => 'DESC', 'id' => 'DESC']);
    }
}

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

use Doctrine\ORM\Mapping as ORM;

/**
 * One customer a notice was addressed to by name (XIV-120, §8.16).
 *
 * **An entity rather than a `ManyToMany`, for a reason that is not modelling
 * taste.** `App\Deployment\RegistryGrants` works out what the customer-facing
 * instance may `SELECT` by walking the `control` entity manager's mapping and
 * taking each `App\Registry\Entity\` class's table name. A many-to-many's join
 * table is not a class, has no metadata, and would therefore be absent from that
 * list — so the grant would be generated, run, and be wrong, and the way anybody
 * would find out is a customer's dashboard meeting SQLSTATE 42501 on the one
 * query this whole feature exists for. Worse, only for notices addressed to
 * *named* customers, since an announcement to everybody never touches this table.
 *
 * That is a boundary error a class avoids by existing.
 * `tests/Functional/Deployment/NoticeGrantsTest.php` asserts this table is on the
 * readable list and reads a notice through it as the restricted role, so
 * converting this into an association would go red rather than being discovered
 * in production.
 *
 * **It carries nothing but the pair.** No "addressed at" moment — the notice has
 * one and every recipient on it shares it — and no per-recipient state of any
 * kind: whether a customer has seen a notice is their database's business
 * (§8.16), and a column here would be a copy of it that only an operator's
 * screen could contradict.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'notice_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_notice_recipient', columns: ['notice_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_notice_recipient_tenant', columns: ['tenant_id'])]
class NoticeRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Notice::class, inversedBy: 'recipients')]
        #[ORM\JoinColumn(name: 'notice_id', nullable: false, onDelete: 'CASCADE')]
        private Notice $notice,
        /**
         * The customer.
         *
         * `ON DELETE CASCADE` for `purchase_intent`'s reason word for word: a
         * deprovisioned customer's addressing is meaningless, and a foreign key
         * left standing turns a clean removal into a constraint violation
         * somebody has to clear by hand.
         */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotice(): Notice
    {
        return $this->notice;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }
}

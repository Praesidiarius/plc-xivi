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

namespace Xivi\ControlPlane\Entity;

use Doctrine\ORM\Mapping as ORM;
use Xivi\ControlPlane\Repository\SignupRefusalRepository;
use Xivi\ControlPlane\Signup\DisposableEmailDomains;

/**
 * How often this installation has turned somebody away at the signup form, by
 * the provider they came from (XIV-125).
 *
 * **A refusal an operator cannot see is a decision nobody can review**, and that
 * is the whole reason this table exists rather than a log line. The list in
 * {@see DisposableEmailDomains} is a judgement about which providers are
 * throwaway, made by whoever wrote it, and the ticket's own rule is that
 * refusing a real business is the expensive mistake. The only way that mistake
 * is ever noticed is if the refusals are counted somewhere a person looks: a
 * domain an operator recognises, showing up here with three attempts against it,
 * is the signal that a line in that list is wrong. Without this row the same
 * three customers simply never arrive and nothing anywhere says so.
 *
 * ## Counts, not contents
 *
 * §8.11 settled this for tenant figures and the same line is drawn here, one
 * degree harder. **The address is not stored, only the domain**, and the domain
 * is not even the visitor's contribution: it is one of the entries this
 * installation ships, so a row here says "the list matched, this many times"
 * rather than anything at all about a person. {@see SignupRequest} refuses to
 * keep an IP or a user agent for somebody who is not yet a customer, and
 * somebody who was turned away is further from being one than that.
 *
 * It also makes the table **bounded by the list rather than by the traffic**. A
 * row can only ever exist for a domain in {@see DisposableEmailDomains::DOMAINS},
 * because a domain that is not on it is not refused, so the worst a script
 * pointed at the public endpoint can do here is increment a counter that already
 * existed. A table keyed on whatever a stranger typed would have been the
 * obvious shape and would have handed an anonymous endpoint an unbounded write.
 *
 * ## Never written through the ORM
 *
 * {@see SignupRefusalRepository::record()} performs one `INSERT … ON CONFLICT DO
 * UPDATE`, and the reasons are in that method. In short: two refusals in the
 * same instant must not race, and a failed flush on a public endpoint must not
 * be able to close the entity manager the signup itself is about to use. So this
 * class has getters and no mutators, and the counters are true because one SQL
 * statement makes them so.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: SignupRefusalRepository::class)]
#[ORM\Table(name: 'signup_refusal')]
// One row per domain, which is what makes the upsert an upsert. Without this
// index `ON CONFLICT (domain)` has nothing to conflict with and every refusal
// would be a new row.
#[ORM\UniqueConstraint(name: 'uniq_signup_refusal_domain', columns: ['domain'])]
class SignupRefusal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The provider, exactly as {@see DisposableEmailDomains::domainOf()} read it
     * off the address.
     *
     * The *matched* address rather than the listed entry, so a refusal of
     * `x.mailinator.com` is its own row: the subdomain rule is part of what a
     * reviewer of this list is checking, and folding every subdomain onto its
     * parent would hide the case where the widening is what did the refusing.
     *
     * 255 rather than 63: this is a whole domain name and not a label.
     */
    #[ORM\Column(length: 255)]
    private string $domain;

    /**
     * How many signups this domain has cost.
     *
     * The number is the entire point of the row. One refusal from a domain
     * nobody recognises is a stranger; forty is a script, and one from a name an
     * operator knows is a mistake in the list that has now happened once.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'first_seen_at')]
    private \DateTimeImmutable $firstSeenAt;

    /**
     * The most recent one, which is what says whether this is still happening.
     *
     * A domain that stopped being tried a year ago and one that is being tried
     * this morning are different situations and read identically without it.
     */
    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(string $domain)
    {
        $this->domain = $domain;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}

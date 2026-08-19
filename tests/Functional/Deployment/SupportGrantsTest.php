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

namespace App\Tests\Functional\Deployment;

use App\Deployment\RegistryGrants;
use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use App\Registry\Entity\Tenant;
use App\Registry\Support\CollectedTickets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **That a customer's instance can read an operator's answer with the privileges
 * it already has, and still cannot write anything** (XIV-123,
 * docs/architecture.md §8.17).
 *
 * ## The claim this class exists to make checkable
 *
 * §8.17's design rests on one sentence: the question goes outward through a
 * collector because §4.4 gives a customer's instance no write privilege in the
 * control-plane database, and **the answer comes back with no collector at all**
 * because `SELECT` on the registry tables is what that same grant has always
 * permitted. That is a claim about a database role, and the only honest way to
 * test it is to be that role.
 *
 * So this makes the real role with {@see RegistryGrants}'s real statements,
 * opens a connection **as that role**, builds an entity manager on it, and runs
 * {@see CollectedTickets} — the same class the customer's support page runs —
 * through it. A version of this test that used the suite's own connection would
 * pass as a database superuser and prove precisely nothing; that is why
 * {@see CollectedTickets} takes its entity manager as a constructor argument
 * rather than resolving one out of the registry. `NoticeGrantsTest` set this
 * shape for [XIV-120] and this is the same shape for the same reason.
 *
 * ## And the writes, refused for the right reason
 *
 * The three refusals below are the interesting half, and each one is written so
 * that **the privilege is the only possible cause of the failure**. That is a
 * scar from [XIV-120]: one of its refusal tests inserted a row that violated a
 * unique constraint, so it would have thrown for a superuser too and would have
 * passed with `INSERT` granted. So the insert here names a reference and a
 * tenant that nothing else has, the update targets a row that genuinely exists
 * and is genuinely selected as this role, and the delete's `WHERE` is proved to
 * match by the read tests above it.
 *
 * ## Why there are no tenant databases in it
 *
 * A `Tenant` is a *registry row*, and nothing here connects to a customer's
 * database — the copy, the status and the reply are all control-plane facts. The
 * customer's end of the feature, where a real tenant database holds the ticket,
 * is {@see \App\Tests\Functional\Tenant\SupportTicketTest}, and the collector
 * that joins the two is
 * {@see \App\Tests\Functional\ControlPlane\SupportRequestTest}.
 *
 * The control-plane database is not rolled back between tests (DAMA is off for
 * that connection, which is what lets a role be created at all), so everything
 * written here is removed by hand on the way in and on the way out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SupportGrantsTest extends KernelTestCase
{
    private const string PASSWORD = 'not-a-secret-this-role-lives-for-one-test';

    /**
     * Both customers are prefixed so the cleanup can find them and nothing else.
     *
     * The prefix is this class's own, and it is deliberately not a prefix of
     * anybody else's: `NoticeGrantsTest` carries the scar of a `LIKE` in a
     * cleanup that matched another class's tenant and deleted its registry row
     * while its database was still standing. `test_support_grants_` is not a
     * prefix of `test_support_` — which
     * {@see \App\Tests\Functional\Tenant\SupportTicketTest} does not use either,
     * for the same reason.
     */
    private const string SLUG_PREFIX = 'test_support_grants_';

    private const string ALPHA = self::SLUG_PREFIX . 'alpha';
    private const string BETA = self::SLUG_PREFIX . 'beta';

    /** Every body this class writes starts with it, so the cleanup can find its own rows. */
    private const string MARKER = 'SupportGrantsTest says: ';

    private Connection $administrator;
    private EntityManagerInterface $control;

    /** The role under test, namespaced per worker and per checkout — a role is a cluster-wide object. */
    private string $role;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $administrator = $container->get('doctrine.dbal.control_connection');
        \assert($administrator instanceof Connection);
        $this->administrator = $administrator;

        $control = $container->get('doctrine.orm.control_entity_manager');
        \assert($control instanceof EntityManagerInterface);
        $this->control = $control;

        $prefix = $container->getParameter('app.tenant_object_prefix');
        \assert(\is_string($prefix));
        $this->role = $prefix . 'support_reader';

        $this->forgetEverything();

        $this->administrator->executeStatement(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $this->quote($this->role),
            $this->administrator->quote(self::PASSWORD),
        ));

        $grants = $container->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        foreach ($grants->statements($this->role) as $statement) {
            $this->administrator->executeStatement($statement);
        }
    }

    protected function tearDown(): void
    {
        $this->forgetEverything();

        parent::tearDown();
    }

    /**
     * **The ticket's central claim.** The status an operator set and the reply
     * they wrote are readable by the role a customer-facing instance connects as,
     * using the grant it had before this feature existed.
     *
     * This is what makes the return leg immediate: if this failed, an answer
     * would have to be pushed back into every customer's database by a second
     * collector, and a customer would wait for a reply the same way they wait for
     * their question to arrive.
     */
    public function testTheRestrictedRoleCanReadAnOperatorsAnswer(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $request = $this->collectedTicket($alpha, 'the invoice module is odd');
        $request->moveTo(SupportStatus::InProgress);
        $request->replyWith('We are looking at it.', 'The Operator');
        $this->control->flush();

        $read = $this->readAs($this->restrictedManager())->forTenant($alpha);

        self::assertCount(1, $read);

        $seen = $read[$request->getReference()] ?? null;

        self::assertInstanceOf(SupportRequest::class, $seen);
        self::assertSame(SupportStatus::InProgress, $seen->getStatus());
        self::assertSame('We are looking at it.', $seen->getReply());
        self::assertSame('The Operator', $seen->getReplyAuthorLabel());
    }

    /**
     * **And nobody else's.** The quiet failure here is not a permission error, it
     * is one customer reading what another wrote — which on this table is a
     * company describing its own problems to the people who run its software.
     *
     * Two customers, and the assertion says both what is returned and what is
     * not, because "the template does not draw it" is not the same guarantee as
     * "the query did not return it".
     */
    public function testACustomerDoesNotSeeAnotherCustomersTicket(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);

        $mine = $this->collectedTicket($alpha, 'ours');
        $theirs = $this->collectedTicket($beta, 'theirs');

        $read = $this->readAs($this->restrictedManager())->forTenant($beta);

        self::assertArrayHasKey($theirs->getReference(), $read);
        self::assertArrayNotHasKey($mine->getReference(), $read);
        self::assertCount(1, $read);
    }

    /**
     * **The other half of the guarantee**, and the reason this feature widens
     * nothing: the role that can read every answer can write no ticket.
     *
     * The row this tries to insert **cannot violate any constraint**: the tenant
     * exists, the reference is one nothing else has, and the unique index is on
     * the pair. So a permission error is the only thing that can make this throw,
     * which is exactly the property [XIV-120]'s equivalent test lacked for an
     * afternoon.
     */
    public function testTheRestrictedRoleCannotInsertATicket(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $public = $this->connectAsTheRestrictedRole();

        $this->expectException(DbalException::class);

        $public->executeStatement(
            <<<'SQL'
                INSERT INTO support_request
                    (tenant_id, reference, subject, body, raised_at, collected_at, status)
                VALUES (?, ?, 'smuggled', 'smuggled', now(), now(), 'open')
                SQL,
            [$alpha->getId(), str_repeat('f', 32)],
        );
    }

    /**
     * And it cannot answer itself, which is the privilege that would matter most.
     *
     * A customer-facing instance able to `UPDATE` this table could write its own
     * reply onto its own ticket and close it — an installation in which the
     * software answers on the operator's behalf, in the operator's name, on the
     * one screen a customer has no reason to distrust.
     *
     * The row exists and the same role can select it (the first test proves that
     * against the same grant), so nothing but the privilege can make this throw.
     */
    public function testTheRestrictedRoleCannotWriteAReply(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $request = $this->collectedTicket($alpha, 'a real row');

        $public = $this->connectAsTheRestrictedRole();

        // Proves the WHERE matches before asserting that the UPDATE is refused,
        // so a refusal cannot be mistaken for a statement that touched no rows.
        self::assertSame(
            1,
            (int) $public->fetchOne('SELECT count(*) FROM support_request WHERE id = ?', [$request->getId()]),
        );

        $this->expectException(DbalException::class);

        $public->executeStatement(
            "UPDATE support_request SET reply = 'answered by nobody', status = 'closed' WHERE id = ?",
            [$request->getId()],
        );
    }

    /** And it cannot make a ticket disappear, which would be the way to hide one. */
    public function testTheRestrictedRoleCannotDeleteATicket(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $request = $this->collectedTicket($alpha, 'a real row');

        $public = $this->connectAsTheRestrictedRole();

        $this->expectException(DbalException::class);

        $public->executeStatement('DELETE FROM support_request WHERE id = ?', [$request->getId()]);
    }

    /**
     * The generated list names the table, which is the assertion that keeps the
     * mapping honest in the direction the queries above cannot see.
     *
     * A `SupportRequest` filed in `Xivi\ControlPlane\Entity` — where the purchase
     * intent lives, and therefore the obvious place for it — would be on the
     * *withheld* list, and every customer's support page would meet SQLSTATE
     * 42501. This is what says the namespace decision is still the one that was
     * made.
     */
    public function testTheSupportTableIsOnTheReadableList(): void
    {
        $grants = static::getContainer()->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        self::assertContains('support_request', $grants->readableTables());
        self::assertNotContains('support_request', $grants->withheldTables());

        // And the customer's own table is in neither list, because it is not in
        // this database at all — which is the whole reason a collector exists.
        self::assertNotContains('support_ticket', $grants->readableTables());
        self::assertNotContains('support_ticket', $grants->withheldTables());
    }

    /** A reader running against whatever entity manager it is handed. */
    private function readAs(EntityManagerInterface $manager): CollectedTickets
    {
        return new CollectedTickets($manager);
    }

    /**
     * An entity manager on a connection **as the restricted role**, with this
     * build's own mapping.
     *
     * The configuration is the real one, so the query is compiled from the same
     * metadata the application uses; only the credentials differ.
     */
    private function restrictedManager(): EntityManagerInterface
    {
        return new EntityManager($this->connectAsTheRestrictedRole(), $this->control->getConfiguration());
    }

    private function connectAsTheRestrictedRole(): Connection
    {
        $params = $this->administrator->getParams();

        $params['user'] = $this->role;
        $params['password'] = self::PASSWORD;

        // Whatever DAMA and the tenant middleware wrapped the real connection in,
        // this one is a plain connection to the same database.
        unset($params['wrapperClass']);

        return DriverManager::getConnection($params);
    }

    /** A registry row, with no database behind it: nothing here connects to a customer. */
    private function tenant(string $slug): Tenant
    {
        $existing = $this->control->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        $tenant = new Tenant($slug, ucfirst($slug), 'postgresql://nobody@nowhere/' . $slug);

        $this->control->persist($tenant);
        $this->control->flush();

        return $tenant;
    }

    /**
     * A collected ticket, written straight into the control plane.
     *
     * The collector is exercised end to end elsewhere; here the row is made by
     * hand so that this class stays about the *grant* and runs in a second.
     */
    private function collectedTicket(Tenant $tenant, string $subject): SupportRequest
    {
        $request = new SupportRequest($tenant, bin2hex(random_bytes(16)));
        $request->record($subject, self::MARKER . $subject, new \DateTimeImmutable());

        $this->control->persist($request);
        $this->control->flush();

        return $request;
    }

    /**
     * Everything this class wrote, and the role it made.
     *
     * Idempotent on the way in rather than trusting the way out, which is the
     * argument `SharesATenant` makes about tenant databases: a run that died
     * halfway leaves the objects standing and the next run has to cope.
     */
    private function forgetEverything(): void
    {
        $this->control->clear();

        // Named by the marker this class writes into every body, so nothing
        // belonging to another test or to a developer's own database is in range.
        $this->administrator->executeStatement(
            'DELETE FROM support_request WHERE body LIKE ?',
            [self::MARKER . '%'],
        );
        $this->administrator->executeStatement(
            'DELETE FROM tenant WHERE slug LIKE ?',
            [self::SLUG_PREFIX . '%'],
        );

        $this->dropRole();
    }

    /**
     * Removes the role, tolerating every state it might be in.
     *
     * `DROP OWNED BY` first, because PostgreSQL refuses to drop a role that is
     * still referenced by a privilege — and this one is granted several by
     * construction.
     */
    private function dropRole(): void
    {
        $exists = $this->administrator->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$this->role]);

        if ($exists === false) {
            return;
        }

        $quoted = $this->quote($this->role);

        $this->administrator->executeStatement(sprintf('DROP OWNED BY %s', $quoted));
        $this->administrator->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $quoted));
    }

    /** Identifier quoting, matching {@see RegistryGrants}'s own. */
    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

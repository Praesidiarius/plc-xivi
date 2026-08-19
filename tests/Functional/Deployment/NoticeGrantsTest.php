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
use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeAudience;
use App\Registry\Entity\Tenant;
use App\Registry\Notice\LiveNotices;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **That a customer's instance can read a notice with the privileges it already
 * has, and still cannot write anything** (XIV-120, docs/architecture/identity-and-access.md §8.16).
 *
 * ## The claim this class exists to make checkable
 *
 * §8.16's whole argument is that a notice can live in the control plane and
 * simply be read, because §4.4 already grants the customer-facing instance
 * `SELECT` on the registry tables — where [XIV-102] had to send its row the
 * other way, into the customer's own database, because a *write* was involved.
 * That is a claim about a database role, and the only honest way to test it is
 * to be that role.
 *
 * So this makes the real role with {@see RegistryGrants}'s real statements,
 * opens a connection **as that role**, builds an entity manager on it, and runs
 * {@see LiveNotices} — the same class the dashboard runs — through it. A version
 * of this test that used the suite's own connection would pass as a database
 * superuser and would prove precisely nothing; that is why {@see LiveNotices}
 * takes its entity manager as a constructor argument rather than resolving one
 * out of the registry.
 *
 * ## And the scoping, asserted from inside that role
 *
 * The other thing that can be wrong here is quieter than a permission error: a
 * query that returns somebody else's notices. Two customers exist in every test
 * below and each assertion says both what is returned and what is not, because
 * "the template does not draw it" is not the same guarantee as "the query did
 * not return it".
 *
 * ## Why there are no tenant databases in it
 *
 * A `Tenant` is a *registry row*, and nothing here connects to a customer's
 * database — the notices, the addressing and the audience filter are all
 * control-plane facts. So the rows are made directly, which keeps this class to
 * a second and lets it say what it is about. The dashboard end of the feature,
 * where a real customer's database holds the dismissals, is
 * {@see \App\Tests\Functional\Tenant\NoticeWidgetTest}.
 *
 * The control-plane database is not rolled back between tests (DAMA is off for
 * that connection, which is what lets a role be created at all), so everything
 * written here is removed by hand on the way in and on the way out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeGrantsTest extends KernelTestCase
{
    private const string PASSWORD = 'not-a-secret-this-role-lives-for-one-test';

    /**
     * Both customers are prefixed so the cleanup can find them and nothing else.
     *
     * The prefix is this class's own rather than the feature's, and that is a
     * scar: it was a prefix of {@see \App\Tests\Functional\Tenant\NoticeWidgetTest}'s
     * slug for an afternoon, so the cleanup here deleted that class's registry row
     * while its database was still standing — and the next run refused to
     * provision into a database it no longer had a row for. A LIKE in a cleanup is
     * a wildcard aimed at somebody else's fixtures unless it is namespaced to the
     * class that wrote them.
     */
    private const string SLUG_PREFIX = 'test_notice_grants_';

    private const string ALPHA = self::SLUG_PREFIX . 'alpha';
    private const string BETA = self::SLUG_PREFIX . 'beta';

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
        $this->role = $prefix . 'notice_reader';

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
     * **The ticket's central claim.** A notice addressed to every customer is
     * readable by the role a customer-facing instance connects as, using the
     * grant it already had before this feature existed.
     */
    public function testTheRestrictedRoleCanReadANoticeAddressedToEverybody(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $this->publish('Sunday maintenance', everyTenant: true);

        $notices = $this->readAs($this->restrictedManager())->forTenant($alpha, administrator: false);

        self::assertSame(['Sunday maintenance'], self::titlesOf($notices));
    }

    /**
     * And the addressed case, which is the one that goes through
     * `notice_recipient` — the table a `ManyToMany` would have hidden from the
     * grant generator entirely.
     *
     * This is the test that goes red if somebody "simplifies" the recipient
     * entity into an association: the join table would not be in
     * {@see RegistryGrants::readableTables()}, so it would not be granted, and
     * this line would meet SQLSTATE 42501.
     */
    public function testTheRestrictedRoleCanReadANoticeAddressedToOneCustomer(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $this->publish('Your trial', everyTenant: false, recipients: [$alpha]);

        $notices = $this->readAs($this->restrictedManager())->forTenant($alpha, administrator: false);

        self::assertSame(['Your trial'], self::titlesOf($notices));
    }

    /**
     * **And nobody else's.** The quiet failure this feature has is not a
     * permission error, it is one customer being shown what was written to
     * another — which on this screen would be an operator's private message to a
     * competitor.
     */
    public function testACustomerDoesNotSeeANoticeAddressedToAnother(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);

        $this->publish('For alpha only', everyTenant: false, recipients: [$alpha]);
        $this->publish('For everybody', everyTenant: true);

        $notices = $this->readAs($this->restrictedManager())->forTenant($beta, administrator: false);

        self::assertSame(['For everybody'], self::titlesOf($notices));
    }

    /**
     * Who inside a tenant sees it is decided per notice, and the filter is in the
     * query rather than in a template.
     *
     * Both directions, in one test, because they are the same clause: an
     * ordinary user is shown one of the two and an administrator both.
     */
    public function testAnAdministratorsOnlyNoticeReachesOnlyAdministrators(): void
    {
        $alpha = $this->tenant(self::ALPHA);

        $this->publish('Everybody', everyTenant: true);
        $this->publish('Only admins', everyTenant: true, audience: NoticeAudience::Administrators);

        $reader = $this->readAs($this->restrictedManager());

        self::assertSame(['Everybody'], self::titlesOf($reader->forTenant($alpha, administrator: false)));
        self::assertSame(
            ['Only admins', 'Everybody'],
            self::titlesOf($reader->forTenant($alpha, administrator: true)),
        );
    }

    /**
     * A withdrawn notice stops being shown, and the reader is what stops showing
     * it.
     *
     * Withdrawing is an expiry set to now ({@see Notice::withdraw()}), so this
     * also covers a notice whose end has simply passed — they are one mechanism
     * on purpose.
     */
    public function testAWithdrawnNoticeIsNotLive(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $notice = $this->publish('Briefly true', everyTenant: true);

        $notice->withdraw(new \DateTimeImmutable('-1 second'));
        $this->control->flush();

        self::assertSame(
            [],
            self::titlesOf($this->readAs($this->restrictedManager())->forTenant($alpha, administrator: true)),
        );
    }

    /**
     * **The other half of the guarantee**, and the reason this feature adds no
     * privilege: the role that can read every notice can write none of them.
     *
     * Asserted for `notice` and for `notice_recipient` separately, because they
     * are granted separately and an `INSERT` on the second would be enough to let
     * a customer-facing instance address somebody else's announcement to itself.
     */
    public function testTheRestrictedRoleCannotWriteANotice(): void
    {
        $public = $this->connectAsTheRestrictedRole();

        $this->expectException(DbalException::class);

        $public->executeStatement(<<<'SQL'
            INSERT INTO notice (title, body, audience, every_tenant, author_label, published_at, created_at)
            VALUES ('smuggled', 'smuggled', 'everyone', true, 'nobody', now(), now())
            SQL);
    }

    /** The addressing table, for the reason above. */
    public function testTheRestrictedRoleCannotAddressANotice(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);
        $notice = $this->publish('For alpha only', everyTenant: false, recipients: [$alpha]);

        $public = $this->connectAsTheRestrictedRole();

        $this->expectException(DbalException::class);

        // Deliberately a pair this notice does **not** already have. Addressing it
        // to `$alpha` again would violate `uniq_notice_recipient` and throw for a
        // privileged role too, so the test would pass with `INSERT` granted --
        // which is the one thing it exists to detect.
        $public->executeStatement(
            'INSERT INTO notice_recipient (notice_id, tenant_id) VALUES (?, ?)',
            [$notice->getId(), $beta->getId()],
        );
    }

    /**
     * And it cannot take one down either, which is the privilege that would
     * matter most: a bug in a customer-facing controller that could `DELETE FROM
     * notice` would let one customer silence an announcement to all of them.
     */
    public function testTheRestrictedRoleCannotDeleteANotice(): void
    {
        $public = $this->connectAsTheRestrictedRole();

        $this->expectException(DbalException::class);

        $public->executeStatement("DELETE FROM notice WHERE title = 'nothing matches this'");
    }

    /**
     * The generated list names both tables, which is the assertion that keeps the
     * mapping honest in the direction the queries above cannot see.
     *
     * A `ManyToMany` would still pass every functional test in this file *if* the
     * join table happened to be granted by something else; this is what says it
     * is granted because the generator knows about it.
     */
    public function testBothNoticeTablesAreOnTheReadableList(): void
    {
        $grants = static::getContainer()->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        $readable = $grants->readableTables();

        self::assertContains('notice', $readable);
        self::assertContains('notice_recipient', $readable);

        // And they are not on the withheld list, which is derived separately and
        // would disagree if the entity ever moved into the administration
        // surface's namespace — the mistake this whole class is about.
        self::assertNotContains('notice', $grants->withheldTables());
        self::assertNotContains('notice_recipient', $grants->withheldTables());
    }

    /** A reader running against whatever entity manager it is handed. */
    private function readAs(EntityManagerInterface $manager): LiveNotices
    {
        return new LiveNotices($manager);
    }

    /**
     * An entity manager on a connection **as the restricted role**, with this
     * build's own mapping.
     *
     * The configuration is the real one, so the query is compiled from the same
     * metadata the application uses; only the credentials differ. That is the
     * whole point — this is the application's own reader, running as the
     * customer-facing instance's database user.
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

    /** @param list<Tenant> $recipients */
    private function publish(
        string $title,
        bool $everyTenant,
        array $recipients = [],
        NoticeAudience $audience = NoticeAudience::Everyone,
    ): Notice {
        $notice = new Notice(
            $title,
            'The body of ' . $title,
            $audience,
            everyTenant: $everyTenant,
            authorLabel: 'The Operator',
        );

        foreach ($recipients as $recipient) {
            $notice->addRecipient($recipient);
        }

        $this->control->persist($notice);
        $this->control->flush();

        return $notice;
    }

    /**
     * @param list<Notice> $notices
     *
     * @return list<string>
     */
    private static function titlesOf(array $notices): array
    {
        return array_map(static fn (Notice $notice): string => $notice->getTitle(), $notices);
    }

    /**
     * Everything this class wrote, and the role it made.
     *
     * Idempotent on the way in rather than trusting the way out, which is the
     * argument `SharesATenant` makes about tenant databases: a run that died
     * halfway leaves the objects standing and the next run is the one that has to
     * cope.
     */
    private function forgetEverything(): void
    {
        $this->control->clear();

        // The recipients go with the notices by cascade, and the notices are
        // named by the bodies this class writes. Everything else in these tables
        // belongs to another test or to a developer's own database.
        $this->administrator->executeStatement(
            "DELETE FROM notice WHERE body LIKE 'The body of %'",
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

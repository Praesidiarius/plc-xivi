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

namespace App\Tests\Functional\ControlPlane;

use App\Registry\Entity\Tenant;
use App\Registry\Entity\TenantStatus;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Exception\NoTenantResolvedException;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Entity\SignupRefusal;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Repository\SignupRefusalRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * The tenant list, and the two boundaries it is built to keep (XIV-58).
 *
 * The page itself is a table, and a table is not usually worth a test class. What
 * is worth one is everything the table is *not* allowed to do, and there are two
 * of those:
 *
 *   1. **It opens no tenant connection.** One request reads one database and here
 *      that database is the control plane's. The moment this page connects to a
 *      customer's database for one convenient column, [XIV-59] — usage figures —
 *      stops being a design problem with several defensible answers and becomes a
 *      join somebody already wrote.
 *   2. **It leaks no credential.** The registry row this page renders also carries
 *      `database_dsn` and an encrypted `database_password`. Neither belongs in the
 *      HTML, and the way they arrive in HTML is never deliberate.
 *
 * **The fixtures have no databases, and that is the first half of the first
 * proof.** These three tenants are rows written straight into the registry —
 * nothing is provisioned, and the DSNs name a host that does not resolve. A page
 * that opened a tenant connection would therefore not merely be doing the wrong
 * thing, it would be failing, loudly, in every test in this class. Provisioning
 * three real customers would have been the more realistic fixture and a strictly
 * weaker instrument: the page would have had a working database to connect to,
 * and the connection nobody wants would have succeeded quietly.
 *
 * `TenantSummary` is the structural half of the second proof — the entity never
 * reaches the template, so there is no credential in scope to leak — and
 * `testNeitherTheDsnNorThePasswordReachesTheBrowser` is the half that keeps
 * noticing after somebody decides the entity would be more convenient. `XIV-49`
 * set that shape on `TenantLogoTest`, for a tenant settings row that holds an SMTP
 * password beside the one column that is public.
 *
 * **Who may reach the page is proven next door**, in {@see ControlPlaneSignInTest},
 * and deliberately not repeated here: `/control/` *is* this page, so that class's
 * assertions — a tenant hostname 404s, a signed-in tenant administrator holding
 * `ROLE_OPERATOR` in their own database still 404s, a tenant session on this host
 * is nobody's session — are already assertions about this controller. What is
 * added below is the one case that class has no reason to make: an operator who
 * has not signed in at all.
 *
 * The control plane is not rolled back between tests (see
 * `config/packages/test/dama_doctrine_test.yaml`, and the reason: a tenant
 * database is created by `CREATE DATABASE`, which no transaction can undo), so
 * the fixtures are removed by hand at both ends.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantListTest extends WebTestCase
{
    /**
     * A provider on the throwaway list, for the refusals section (XIV-125).
     *
     * A real entry rather than an invented domain, because the section is about
     * whether an operator can review the shipped list and an invented name would
     * be reviewing nothing.
     */
    private const string REFUSED_DOMAIN = 'mailinator.com';

    private const string EMAIL = 'tenant-list@example.test';
    private const string PASSWORD = 'operator-password-58';
    private const string OPERATOR_NAME = 'The Operator';

    /** Provisioned, serving, and the row that should sort *below* the unhappy ones. */
    private const string HEALTHY = 'test_xiv58_healthy';

    /**
     * The row this page is designed around: created, never provisioned, no
     * hostname routed to it — which is exactly what a provisioning run that died
     * halfway leaves behind (§4.1). Its name sorts last of the three on purpose,
     * so that "it is at the top" can only be the status ordering doing it.
     */
    private const string STUCK = 'test_xiv58_zzz_stuck';

    /** Not serving either, but on purpose, which is a different kind of not-serving. */
    private const string SUSPENDED = 'test_xiv58_suspended';

    /**
     * The two values that must appear nowhere in the response.
     *
     * Distinctive enough that a match cannot be a coincidence, and shaped like the
     * real things: a DSN with a role, a host, a port and a database in it, and a
     * ciphertext that is opaque by construction. Nothing decrypts the second one
     * here — the assertion is about the stored string reaching a browser, and a
     * ciphertext on a page is a leak whether or not the reader holds the key.
     */
    private const string DSN = 'postgresql://xiv58role:@xiv58-secret-host.invalid:5432/xiv58db?serverVersion=16';
    private const string CIPHERTEXT = 'XIV58CIPHERTEXTMUSTNEVERRENDER';

    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request for the connection assertions
        // below: they ask what state the tenant connection was left in, and a
        // rebooted kernel would hand back a fresh one that had never been used by
        // anybody.
        $this->client->disableReboot();

        $this->host = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->removeFixtures();
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    /**
     * Every column the ticket asked for, on the customer that has all of them.
     */
    public function testTheListShowsEveryTenantAndWhatIsKnownAboutIt(): void
    {
        $text = $this->listPageText();

        self::assertStringContainsString('Healthy Customer', $text, 'the name');
        self::assertStringContainsString(self::HEALTHY, $text, 'the slug');
        self::assertStringContainsString('Active', $text, 'the status');
        self::assertStringContainsString('enterprise', $text, 'the plan');
        self::assertStringContainsString('healthy.xiv58.test', $text, 'the primary domain');
        self::assertStringContainsString('contact', $text, 'an enabled module');
        self::assertStringContainsString('article', $text, 'the other enabled module');

        // The two dates, to the minute, as the rest of the application draws them.
        self::assertStringContainsString($this->tenant(self::HEALTHY)->getCreatedAt()->format('Y-m-d H:i'), $text);
        self::assertStringContainsString(
            $this->tenant(self::HEALTHY)->getProvisionedAt()?->format('Y-m-d H:i') ?? 'never provisioned',
            $text,
        );

        // And the other two rows are on the same page rather than filtered off it.
        self::assertStringContainsString(self::STUCK, $text);
        self::assertStringContainsString(self::SUSPENDED, $text);
    }

    /**
     * **A tenant that is not being served is visible without going looking for
     * it**, which is the acceptance criterion the ordering exists for.
     *
     * Two independent things carry it and both are asserted, because either alone
     * would be a weaker promise than the ticket asked for: the page opens with a
     * line naming the customers that are not being served, and the table puts them
     * first. `test_xiv58_zzz_stuck` is named to sort last alphabetically, so its
     * appearing above a customer called "Healthy Customer" cannot be an accident
     * of the name.
     */
    public function testATenantThatIsNotBeingServedIsVisibleFromTheDoorway(): void
    {
        $crawler = $this->openList();

        $alert = $crawler->filter('[role="alert"]')->text();

        // **Both of this class's own unhealthy tenants are named, and the count
        // is not asserted.** The registry is one table shared by every test
        // class in the suite, so any other class that leaves a tenant in a
        // non-serving status changes the total — `CrossModuleLinkTest`'s does,
        // and which worker runs it first decides whether it is there yet.
        // Asserting "2 customers" made this pass or fail on the parallel
        // schedule rather than on the behaviour, which is the one thing a test
        // must not do. What the acceptance criterion actually asks is that a
        // tenant nobody is serving is named without going looking for it, and
        // that is what these two lines say.
        self::assertStringContainsString(self::STUCK, $alert);
        self::assertStringContainsString(self::SUSPENDED, $alert);
        self::assertMatchesRegularExpression('/\d+ customers? (are|is) not being served/', $alert);

        $order = $this->slugOrder($crawler);

        self::assertLessThan(
            array_search(self::SUSPENDED, $order, true),
            array_search(self::STUCK, $order, true),
            'a provisioning tenant outranks a suspended one: nobody chooses to leave a tenant provisioning',
        );
        self::assertLessThan(
            array_search(self::HEALTHY, $order, true),
            array_search(self::SUSPENDED, $order, true),
            'and both outrank a customer that is being served',
        );
    }

    /**
     * The banner is drawn only when it has something to say.
     *
     * A box reading "0 customers are not being served" on all but three days of
     * the year is a box the reader's eye learns to skip, and skipping it is the
     * exact failure the banner exists to prevent. Asserted by putting the registry
     * in the state where it would be wrong — every fixture serving requests — and
     * checking that nothing is drawn.
     */
    public function testTheBannerIsAbsentWhenEveryCustomerIsBeingServed(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);

        foreach ([self::STUCK, self::SUSPENDED] as $slug) {
            $this->tenant($slug)->setStatus(TenantStatus::Active);
        }

        $entityManager->flush();

        // Other classes in this worker's control-plane database leave rows behind
        // (a tenant database cannot be dropped from inside a transaction, so
        // neither is its row), and one of those could be suspended. The assertion
        // is therefore about *this* test's fixtures rather than about the banner
        // being absent outright.
        $crawler = $this->openList();
        $alert = $crawler->filter('[role="alert"]');

        foreach ([self::STUCK, self::SUSPENDED] as $slug) {
            self::assertStringNotContainsString(
                $slug,
                $alert->count() === 0 ? '' : $alert->text(),
                $slug . ' is being served and should not be called out.',
            );
        }
    }

    /**
     * **A signup refused as a throwaway address is visible to an operator**
     * (XIV-125), which is the requirement that keeps the list reviewable.
     *
     * The list of throwaway providers is a judgement somebody made, and the
     * expensive way for it to be wrong is a real business being turned away and
     * never told why. That mistake is invisible unless the refusals are drawn
     * somewhere a person looks, so this asserts the provider, the count and the
     * heading, the count because "somebody was refused once" and "a script has
     * been refused forty times" are different findings drawn identically without
     * it.
     */
    public function testASignupRefusedAsAThrowawayAddressIsVisibleToAnOperator(): void
    {
        $refusals = self::service(SignupRefusalRepository::class);
        $refusals->record(self::REFUSED_DOMAIN);
        $refusals->record(self::REFUSED_DOMAIN);

        $text = $this->listPageText();

        self::assertStringContainsString('Turned away at the signup form', $text);
        self::assertStringContainsString(self::REFUSED_DOMAIN, $text, 'the provider that was refused');
        self::assertMatchesRegularExpression(
            '{' . preg_quote(self::REFUSED_DOMAIN, '}') . '\s+2\b}',
            $text,
            'both refusals counted onto one row, which is what the upsert is for',
        );
    }

    /**
     * And it is absent when nobody has been turned away, like the banner above.
     *
     * A section permanently reading "nobody has been refused" is furniture, and
     * furniture is what the eye learns to skip. It is §8.10's argument for the
     * not-being-served banner, applied to the other end of the same page.
     */
    public function testTheRefusalSectionIsAbsentWhenNobodyHasBeenTurnedAway(): void
    {
        self::assertStringNotContainsString('Turned away at the signup form', $this->listPageText());
    }

    /**
     * **The page opens no tenant connection**, which is the property [XIV-59]
     * depends on.
     *
     * Three assertions, and the third is what stops the second from being empty:
     *
     *   * no tenant is resolved on this host at all (§8.9), so there is nothing
     *     for a connection to be *to*;
     *   * the tenant connection was left unopened by a request that rendered every
     *     fixture successfully;
     *   * and touching it now throws, which proves the previous line means "nothing
     *     tried" rather than "something tried and DBAL was lazy about it".
     *
     * The fixtures compound it: none of these three customers has a database, so a
     * page that connected would have had nothing to connect to.
     */
    public function testTheListOpensNoTenantConnection(): void
    {
        $this->openList();

        self::assertNull(
            self::service(TenantContext::class)->tryGetTenant(),
            'a control-plane request resolves no tenant',
        );

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        self::assertFalse($connection->isConnected(), 'the tenant connection was never opened');

        $this->expectException(NoTenantResolvedException::class);
        $connection->executeQuery('SELECT 1');
    }

    /**
     * **Neither the DSN nor the encrypted password appears anywhere in the
     * response**, which is the hazard the ticket did not name.
     *
     * Searched over the headers as well as the body, because a leak does not have
     * to be visible to be a leak: a DSN in a `Link` header, in a redirect target or
     * in a cookie is in the browser's hands just the same. The DSN's parts are
     * checked separately from the whole, so that a template rendering a parsed
     * connection — the host on its own, say, as a "which server is this customer
     * on" column somebody thought was harmless — still fails this.
     *
     * The precedent is `TenantLogoTest`, XIV-49, on a tenant settings row that
     * holds an SMTP password beside the one column that is deliberately public.
     */
    public function testNeitherTheDsnNorThePasswordReachesTheBrowser(): void
    {
        $this->openList();

        $response = $this->client->getResponse();
        $whole = (string) $response . (string) $response->getContent();

        foreach ([
            self::DSN,
            self::CIPHERTEXT,
            'xiv58role',
            'xiv58-secret-host.invalid',
            'xiv58db',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $whole, $secret . ' must not reach the browser.');
        }

        // And the values really are on the rows being drawn, so the assertions
        // above are about a page that had every opportunity to leak them rather
        // than about a page with nothing to say.
        self::assertSame(self::DSN, $this->tenant(self::HEALTHY)->getDatabaseDsn());
        self::assertSame(self::CIPHERTEXT, $this->tenant(self::HEALTHY)->getEncryptedDatabasePassword());
    }

    /**
     * Nobody reaches it without signing in.
     *
     * The rest of "only an operator" lives in {@see ControlPlaneSignInTest}, which
     * makes its assertions against this same path: a customer's hostname does not
     * have it, and a tenant administrator who has written `ROLE_OPERATOR` into
     * their own database still does not have it.
     */
    public function testAnAnonymousVisitorIsSentToSignIn(): void
    {
        $this->client->request('GET', sprintf('https://%s/control/', $this->host));

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));
    }

    /**
     * `tenant:list` still works, and this page did not replace it.
     *
     * A headless deployment has no browser, and the command is how a tenant is
     * found from an SSH session at three in the morning. Asserted here rather than
     * left to the command's own coverage because the risk this guards against is
     * this branch's: a repository method rewritten for the page, with the command
     * quietly following it.
     */
    public function testTheConsoleCommandStillListsTheSameTenants(): void
    {
        $tenants = self::service(TenantRepository::class)->findAllOrdered();
        $slugs = array_map(static fn (Tenant $tenant): string => $tenant->getSlug(), $tenants);

        foreach ([self::HEALTHY, self::STUCK, self::SUSPENDED] as $slug) {
            self::assertContains($slug, $slugs);
        }

        // Including the one with no hostname, which an inner join would have
        // dropped — the reason the page has a repository method of its own.
        $withDomains = self::service(TenantRepository::class)->findAllWithDomains();
        self::assertContains(
            self::STUCK,
            array_map(static fn (Tenant $tenant): string => $tenant->getSlug(), $withDomains),
        );
    }

    private function openList(): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/control/login', $this->host));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));

        $crawler = $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function listPageText(): string
    {
        return $this->openList()->filter('body')->text();
    }

    /**
     * The slugs in the order the table draws them.
     *
     * Read out of the second line of the first cell, which is where the template
     * puts the slug under the name — a structural read rather than a search
     * through the page text, so that a slug appearing in the banner above cannot
     * be mistaken for a row.
     *
     * @return list<string>
     */
    private function slugOrder(Crawler $crawler): array
    {
        return $crawler
            ->filter('tbody tr td:first-child .font-monospace')
            ->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    private function createFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);

        $healthy = new Tenant(self::HEALTHY, 'Healthy Customer', self::DSN, 'enterprise');
        $healthy->addDomain('healthy.xiv58.test', true);
        $healthy->addDomain('old-name.xiv58.test');
        $healthy->setEnabledModules(['contact', 'article']);
        $healthy->setEncryptedDatabasePassword(self::CIPHERTEXT);
        $healthy->markProvisioned();

        // No hostname and no `markProvisioned()`: the row a run that died between
        // creating the tenant and routing a domain to it leaves behind. It keeps
        // the status the constructor gives it, which is `Provisioning`.
        $stuck = new Tenant(self::STUCK, 'Zzz Stuck Customer', self::DSN, 'standard');
        $stuck->setEncryptedDatabasePassword(self::CIPHERTEXT);

        $suspended = new Tenant(self::SUSPENDED, 'Suspended Customer', self::DSN, 'standard');
        $suspended->addDomain('suspended.xiv58.test', true);
        $suspended->markProvisioned();
        $suspended->setStatus(TenantStatus::Suspended);
        $suspended->setEncryptedDatabasePassword(self::CIPHERTEXT);

        foreach ([$healthy, $stuck, $suspended] as $tenant) {
            $entityManager->persist($tenant);
        }

        $entityManager->flush();

        self::service(OperatorCreator::class)->create(self::EMAIL, self::OPERATOR_NAME, self::PASSWORD);
    }

    private function removeFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $tenants = self::service(TenantRepository::class);

        foreach ([self::HEALTHY, self::STUCK, self::SUSPENDED] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                // Straight removal rather than `TenantProvisioner::deprovision()`,
                // because these customers never had a database to drop — that is
                // the whole point of the fixture. Cascading `remove` on the
                // association takes the hostnames with the row.
                $entityManager->remove($tenant);
            }
        }

        $operator = self::service(OperatorRepository::class)->findOneByEmail(self::EMAIL);

        if ($operator instanceof Operator) {
            $entityManager->remove($operator);
        }

        // Every refusal, not only this class's (XIV-125). The table is a tally
        // rather than a record of anything anybody did, this is the worker's own
        // control-plane database, and one of the tests above asserts that the
        // section is *absent*, which nothing can be sure of while a row another
        // class left behind may still be there. Test classes do not interleave
        // inside a worker, so this cannot empty a table another one is mid-way
        // through asserting on.
        $entityManager->createQuery('DELETE FROM ' . SignupRefusal::class . ' r')->execute();

        $entityManager->flush();
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug($slug);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

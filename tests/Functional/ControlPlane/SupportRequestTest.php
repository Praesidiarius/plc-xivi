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

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\SupportTicket;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;
use Xivi\ControlPlane\Support\SupportTicketCollector;

/**
 * An operator sees what every customer has asked, and can answer (XIV-123,
 * docs/architecture.md §8.17).
 *
 * The operator's half of the ticket.
 * {@see \App\Tests\Functional\Tenant\SupportTicketTest} proves that a customer
 * can raise one and that the write lands in their own database; this proves that
 * it is not therefore shouted into a void, and that the answer gets back.
 *
 * ## The thing being tested is a boundary, not a screen
 *
 * A ticket is written into the **customer's own database**, because §4.4 grants
 * the customer-facing instance's role `SELECT` on the registry tables and no
 * write privilege anywhere in the control plane. So the question and the
 * operator are in two different databases by construction, and
 * `tenant:support:collect` is the only thing that joins them. The *answer*
 * travels the other way with no collector at all, which is what
 * {@see \App\Tests\Functional\Deployment\SupportGrantsTest} proves against a
 * real role.
 *
 * Six things are proved, and the second is the one that would be easiest to get
 * silently wrong:
 *
 *   1. **A ticket written by a customer turns up on the operator's screen**,
 *      after a collection and not before — because "it appears eventually" and
 *      "it appears" are different claims.
 *   2. **Every customer's tickets, not one customer's.** Asserted with tickets
 *      from *two different* companies on one page, which is the only shape in
 *      which a reader that quietly scoped itself to something would be caught.
 *   3. **A status an operator moves, through a real request**, and the customer's
 *      copy of that fact is the same row rather than a second one.
 *   4. **A reply is written here and is immediately readable**, with no
 *      collection in between.
 *   5. **A collection does not undo an answer.** The job runs every few minutes
 *      and would otherwise race somebody typing, with the one visible symptom
 *      being a customer shown their own question back.
 *   6. **The screen opens no tenant connection** ([XIV-58]'s boundary) and
 *      **nobody's name crosses** ([XIV-102]'s line).
 *
 * **Two tenants, deliberately.** One would have proved every assertion here
 * except the second, and the second is the ticket's own acceptance criterion.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SupportRequestTest extends WebTestCase
{
    use SharesATenant;

    /**
     * Two customers with real databases.
     *
     * Neither slug is a prefix of the other, nor of any other class's — the scar
     * `NoticeGrantsTest` carries about a cleanup's `LIKE`. This class's cleanup
     * names both exactly rather than matching a pattern, which makes the question
     * moot as well as answered.
     */
    private const string ALPHA = 'test_supdesk_one';
    private const string BETA = 'test_supdesk_two';

    private const string ALPHA_HOST = 'supdesk-one.localhost';
    private const string BETA_HOST = 'supdesk-two.localhost';

    private const string OPERATOR = 'support@example.test';
    private const string OPERATOR_NAME = 'The Answerer';
    private const string PASSWORD = 'operator-password-123';

    private KernelBrowser $client;
    private string $controlPlaneHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request, because one test asks what
        // state the tenant connection was left in afterwards — a rebooted kernel
        // would hand back a fresh one nobody had touched.
        $this->client->disableReboot();

        $this->controlPlaneHost = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->forgetEverything();
    }

    protected function tearDown(): void
    {
        $this->forgetEverything();

        parent::tearDown();
    }

    /**
     * **The whole path, end to end**: a row in a customer's database, a
     * collection, and a card on the operator's screen.
     *
     * Checked before the collection as well as after, because the interval is the
     * honest cost of §4.4's boundary and a test that only looked afterwards would
     * pass for an implementation that had quietly widened the grant instead.
     */
    public function testATicketReachesTheOperatorAfterACollection(): void
    {
        $this->raise(self::ALPHA, 'The invoice module is odd');

        self::assertStringNotContainsString('The invoice module is odd', $this->openSupport()->filter('main')->text());

        $this->collect(self::ALPHA);

        $text = $this->openSupport()->filter('main')->text();

        self::assertStringContainsString('The invoice module is odd', $text, 'the question');
        self::assertStringContainsString(self::ALPHA, $text, 'and who asked it');
    }

    /**
     * **Every customer, on one page.** The acceptance criterion this ticket
     * exists for, and the one a reader scoped to the wrong thing would fail
     * without anything else going red.
     *
     * Two companies, two tickets, one screen. A page that had somehow acquired a
     * tenant — through a context, a default, a "current" anything — would draw one
     * of these two and look entirely correct while doing it.
     */
    public function testTheOperatorSeesTicketsFromEveryCustomer(): void
    {
        $this->raise(self::ALPHA, 'Question from the first company');
        $this->raise(self::BETA, 'Question from the second company');

        $this->collect(self::ALPHA);
        $this->collect(self::BETA);

        $text = $this->openSupport()->filter('main')->text();

        self::assertStringContainsString('Question from the first company', $text);
        self::assertStringContainsString('Question from the second company', $text);
        self::assertStringContainsString(self::ALPHA, $text);
        self::assertStringContainsString(self::BETA, $text);
    }

    /**
     * **A status an operator can move**, through a real request rather than by
     * calling the writer.
     *
     * The state is stored once, in the control plane, and is the same row the
     * customer reads — so there is nothing here that can disagree with what they
     * see. That is the reason this feature has no second collector pointing back
     * into the customer's database.
     */
    public function testAnOperatorMovesTheStatus(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        self::assertSame(SupportStatus::Open, $this->collected(self::ALPHA)?->getStatus());

        $this->openSupport();
        $this->post('status', ['status' => SupportStatus::InProgress->value]);

        self::assertSame(SupportStatus::InProgress, $this->collected(self::ALPHA)?->getStatus());
        self::assertStringContainsString('Being looked at', $this->openSupport()->filter('main')->text());
    }

    /**
     * **An operator answers, and the answer is written where the customer can
     * read it** — with no collection in between and nothing to wait for.
     *
     * The reply carries the operator's name as a *copy*, because §4.4 gives a
     * customer-facing instance no access to the `operator` table at all; a
     * foreign key would be unreadable by the only party the value is for.
     */
    public function testAnOperatorReplies(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        $this->openSupport();
        $this->post('reply', ['reply' => 'We have fixed it, sorry about that.']);

        $answered = $this->collected(self::ALPHA);
        self::assertInstanceOf(SupportRequest::class, $answered);

        self::assertSame('We have fixed it, sorry about that.', $answered->getReply());
        self::assertSame(self::OPERATOR_NAME, $answered->getReplyAuthorLabel());
        self::assertNotNull($answered->getRepliedAt());

        // And replying does **not** move the status: being answered and being
        // finished are different things, and a hidden state change on a screen
        // with a visible state control is how the two stop agreeing.
        self::assertSame(SupportStatus::Open, $answered->getStatus());
    }

    /**
     * **An empty reply is refused**, and the refusal is in the writer rather than
     * on the form.
     *
     * A form is one caller. The customer's screen draws a reply block the moment
     * there is one, so a blank reply would put a card on somebody's page saying
     * they had been answered with nothing in it — which is worse than not having
     * been answered, because it is the page they came back to look at.
     *
     * Through a real request, and the row is checked afterwards: a refusal that
     * only removed a button would leave a retyped POST able to do it anyway.
     */
    public function testAnEmptyReplyIsRefused(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        $this->openSupport();
        $this->post('reply', ['reply' => '   ']);

        self::assertNull($this->collected(self::ALPHA)?->getReply(), 'nothing was written');
        self::assertStringContainsString(
            'An empty reply would put an answer',
            $this->openSupport()->filter('main')->text(),
            'and the operator is told why, in a sentence rather than a key',
        );
    }

    /**
     * **A collection does not undo an answer**, which is the race this design
     * would otherwise lose every few minutes.
     *
     * `SupportRequest::record()` writes the customer's three columns and nothing
     * else. A collector that rewrote the row — the obvious implementation, and the
     * one an `upsert` would produce — would blank the reply and the status
     * whenever a run overlapped with somebody typing, and the visible symptom
     * would be a customer being shown their own question back with the answer
     * gone.
     */
    public function testACollectionDoesNotUndoAnOperatorsAnswer(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        $this->openSupport();
        $this->post('reply', ['reply' => 'Answered before the next run.']);
        $this->post('status', ['status' => SupportStatus::Closed->value]);

        $this->collect(self::ALPHA);

        $after = $this->collected(self::ALPHA);
        self::assertInstanceOf(SupportRequest::class, $after);

        self::assertSame('Answered before the next run.', $after->getReply(), 'the reply survived a collection');
        self::assertSame(SupportStatus::Closed, $after->getStatus(), 'and so did the status');
    }

    /**
     * **The screen opens no tenant connection**, which is [XIV-58]'s boundary and
     * is asserted the same way `TenantListTest` and `PurchaseIntentTest` assert
     * it.
     *
     * It is not merely unused: a control-plane request resolves no tenant at all
     * (§8.9), so anything reaching for that connection here would have thrown
     * rather than quietly served the previous customer's database. Asserting it
     * was never opened is what proves the fan-out happens in the collector rather
     * than on a page that lists every company's tickets.
     */
    public function testTheScreenOpensNoTenantConnection(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        // Closed first, because the collection legitimately opened it — that is
        // its whole job, and the reason the page does not have to.
        $connection->close();

        $this->openSupport();

        self::assertFalse($connection->isConnected(), 'the customer databases were left alone');
    }

    /**
     * **And nobody's name crosses.** §8.11 drew the line at *how much* rather than
     * *what*, [XIV-102] held it for purchase requests, and it is held here where
     * crossing it would be most tempting: an operator would obviously like to
     * know whom to write back to.
     *
     * They do not need to, because the answer is delivered in the product — which
     * is the property that makes this line free rather than merely principled.
     */
    public function testWhoAskedDoesNotReachTheControlPlane(): void
    {
        $this->raise(self::ALPHA, 'Please look at this');
        $this->collect(self::ALPHA);

        self::assertStringNotContainsString(
            'The Asker',
            $this->openSupport()->filter('main')->text(),
            'who typed the question is the customer\'s own data',
        );
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Writes a ticket straight into one customer's database.
     *
     * Directly rather than through the customer's own screen, on purpose: what
     * this class is about is the journey from that row to the operator, and going
     * through the page would drag a signed-in tenant user into a test that needs
     * none. {@see \App\Tests\Functional\Tenant\SupportTicketTest} is where the
     * customer's half is proved through the front door.
     */
    private function raise(string $slug, string $subject): void
    {
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant($slug),
            function () use ($subject): void {
                $manager = self::getContainer()->get('doctrine')->getManager('tenant');
                \assert($manager instanceof EntityManagerInterface);

                $manager->persist(new SupportTicket($subject, 'The body of: ' . $subject, 1, 'The Asker'));
                $manager->flush();
            },
        );
    }

    private function collect(string $slug): void
    {
        self::service(SupportTicketCollector::class)->collect($this->tenant($slug));
        $this->controlManager()->clear();
    }

    /** The one collected row for a customer, read back out of the database rather than the identity map. */
    private function collected(string $slug): ?SupportRequest
    {
        $this->controlManager()->clear();

        $rows = $this->controlManager()
            ->getRepository(SupportRequest::class)
            ->findBy(['tenant' => $this->tenant($slug)]);

        return $rows[0] ?? null;
    }

    /**
     * Posts to one of the two controls on the operator's screen, for the first
     * ticket on it.
     *
     * The token is read out of the rendered page rather than generated, so the
     * post goes through the same CSRF check a browser would meet.
     *
     * @param array<string, string> $fields
     */
    private function post(string $action, array $fields): void
    {
        $request = $this->collected(self::ALPHA);
        \assert($request instanceof SupportRequest);

        $this->client->request(
            'POST',
            sprintf('https://%s/control/support/%d/%s', $this->controlPlaneHost, (int) $request->getId(), $action),
            $fields + ['_token' => $this->token()],
        );

        self::assertResponseRedirects();

        $this->controlManager()->clear();
    }

    /** The page's CSRF token, taken from the page itself. */
    private function token(): string
    {
        $page = $this->openSupport();

        $token = $page->filter('input[name="_token"]')->first()->attr('value');
        \assert(\is_string($token));

        return $token;
    }

    /**
     * A tenant, **freshly out of the control-plane manager** every time.
     *
     * `sharedTenant()` hands back the object provisioning made, and this class
     * clears the control manager repeatedly — after every collection, so that an
     * assertion is about the database rather than about the identity map. A
     * cleared manager leaves that object detached, and persisting a
     * `SupportRequest` pointing at a detached `Tenant` is Doctrine's "a new entity
     * was found through the relationship" rather than the row anybody wanted. So
     * the slug is what this class holds on to, and the entity is looked up again
     * each time. `PurchaseIntentTest` learned this the same way.
     */
    private function tenant(string $slug): Tenant
    {
        $this->sharedTenant($slug, [$slug === self::ALPHA ? self::ALPHA_HOST : self::BETA_HOST]);

        $tenant = self::service(TenantRepository::class)->findOneBySlug($slug);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /** Signs an operator in and opens the support screen. */
    private function openSupport(): Crawler
    {
        // Created once and left behind for the rest of the process, like the
        // shared tenants: the control plane is not rolled back, so making one per
        // test would be making one per test for ever.
        if (self::service(OperatorRepository::class)->findOneByEmail(self::OPERATOR) === null) {
            self::service(OperatorCreator::class)->create(self::OPERATOR, self::OPERATOR_NAME, self::PASSWORD);
        }

        $login = $this->client->request('GET', sprintf('https://%s/control/login', $this->controlPlaneHost));

        // The sign-in page redirects an operator who is already signed in, so
        // submitting unconditionally would fail on a crawler with no form in it.
        if ($login->filter('form')->count() > 0) {
            $this->client->submit($login->selectButton('Sign in')->form([
                'email' => self::OPERATOR,
                'password' => self::PASSWORD,
            ]));
        }

        $page = $this->client->request('GET', sprintf('https://%s/control/support', $this->controlPlaneHost));
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * The one thing this class writes outside the rollback.
     *
     * Collected rows are in the control plane, which DAMA deliberately does not
     * roll back. The tenant-side tickets *are* rolled back with each test, so
     * they are not here. Both customers are named exactly rather than matched by
     * a pattern — a `LIKE` in a cleanup is a wildcard aimed at somebody else's
     * fixtures.
     */
    private function forgetEverything(): void
    {
        $manager = $this->controlManager();

        $manager->createQuery(
            'DELETE FROM ' . SupportRequest::class . ' s WHERE s.tenant IN ('
            . 'SELECT t.id FROM ' . Tenant::class . ' t WHERE t.slug IN (:slugs))',
        )
            ->setParameter('slugs', [self::ALPHA, self::BETA])
            ->execute();

        $manager->clear();
    }

    private function controlManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
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

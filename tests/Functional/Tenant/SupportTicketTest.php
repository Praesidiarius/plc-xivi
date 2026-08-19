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

namespace App\Tests\Functional\Tenant;

use App\Registry\Entity\SupportRequest;
use App\Registry\Entity\SupportStatus;
use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Registry\Support\SupportDesk;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\SupportTicket;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Support\SupportTicketCollector;

/**
 * A customer can reach whoever runs their installation (XIV-123,
 * docs/architecture.md §8.17).
 *
 * The customer's half of the ticket;
 * {@see \App\Tests\Functional\ControlPlane\SupportRequestTest} is the operator's.
 * Five things are proved here and the first two are the ones the acceptance
 * criteria name:
 *
 *   1. **Every signed-in user may raise one, and nobody else may.** Both halves
 *      through the front door: somebody holding no grant of any kind can ask,
 *      and a browser with no session gets the login page rather than the form.
 *      That is the decision §8.17 makes about who may raise one, asserted as a
 *      request instead of as a call to a guard.
 *   2. **The write lands in the customer's own database and nowhere else.**
 *      Proved by looking in both: the ticket is in the tenant's `support_ticket`
 *      and the control plane has nothing for this customer until a collection
 *      has run. §4.4's grant is what makes the second half necessary rather than
 *      merely tidy, and it is the assertion that goes red if somebody
 *      "simplifies" this by writing straight into the registry.
 *   3. **The delay is visible rather than hidden.** A ticket nobody has
 *      collected says so on the customer's own screen, which is the honest
 *      rendering of the interval §4.4 forces on this direction.
 *   4. **The answer arrives with no collection at all.** An operator writes a
 *      status and a reply into the control plane and the customer's very next
 *      page load has them — the asymmetry that makes (3) acceptable.
 *   5. **The company's tickets, not the reader's.** A colleague finds the answer
 *      rather than asking the same question again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SupportTicketTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_support_page';
    private const string HOST = 'support-page.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    /**
     * Somebody with no permission of any kind.
     *
     * Created with an empty role list and never granted anything, which is the
     * point: §8.17 decides that raising a ticket needs no authority at all, and
     * a fixture that quietly held `ROLE_ADMIN` would prove the opposite while
     * passing.
     */
    private const string ANYBODY = 'anybody@support.test';

    /** A second person in the same company, for the last assertion. */
    private const string COLLEAGUE = 'colleague@support.test';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The page resolves the reader and the tenant per request, and a
        // rebooting kernel would throw away the tenant the sign-in landed on
        // between one request and the next.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ANYBODY, 'Sam Nobody', self::PASSWORD, []);
        $users->create($this->tenant, self::COLLEAGUE, 'Kim Colleague', self::PASSWORD, []);

        $this->forgetCollected();
    }

    protected function tearDown(): void
    {
        $this->forgetCollected();

        parent::tearDown();
    }

    /**
     * **The acceptance criterion in one method.** Somebody with no grants raises a
     * ticket from inside their own installation, and it is there afterwards.
     */
    public function testAnyUserCanRaiseATicket(): void
    {
        $this->signIn(self::ANYBODY);

        $this->raise('The invoice module is odd', 'It numbered two invoices the same.');

        $tickets = $this->ticketsInTheirDatabase();

        self::assertCount(1, $tickets);
        self::assertSame('The invoice module is odd', $tickets[0]->getSubject());
        self::assertSame('It numbered two invoices the same.', $tickets[0]->getBody());
        self::assertSame('Sam Nobody', $tickets[0]->getRaisedByLabel(), 'who asked, copied at the time');
    }

    /**
     * **And a browser with no session cannot**, which is what "who may raise one"
     * means when the answer is *everybody signed in*.
     *
     * Asserted as a real request to the real route rather than as a call to a
     * guard: a page that is only protected by not being linked to is not
     * protected, and the POST is checked as well as the GET because a form that
     * is not drawn is not a check either.
     */
    public function testSomebodyWhoIsNotSignedInCannotReachIt(): void
    {
        $this->client->request('GET', $this->url('/support'));
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', $this->url('/support'), [
            'subject' => 'smuggled',
            'body' => 'smuggled',
            '_token' => 'whatever',
        ]);
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());

        self::assertSame([], $this->ticketsInTheirDatabase(), 'and nothing was written');
    }

    /**
     * **The write goes where a customer's writes belong, and §4.4's grant is not
     * widened.**.
     *
     * The row is in the customer's own database and the control plane has nothing
     * for them until `tenant:support:collect` has run. This is the assertion that
     * fails the moment somebody makes the page "immediate" by writing into the
     * registry — which a customer-facing instance's role could not do anyway
     * ({@see \App\Tests\Functional\Deployment\SupportGrantsTest} proves that
     * against the real role), so the failure would be in production and only
     * there.
     */
    public function testTheWriteDoesNotReachTheControlPlane(): void
    {
        $this->signIn(self::ANYBODY);
        $this->raise('Nothing crosses yet', 'Not until a collection has run.');

        self::assertCount(1, $this->ticketsInTheirDatabase());
        self::assertSame([], $this->collectedForThisCustomer(), 'the control plane has nothing');
    }

    /**
     * **The delay, on the screen of the person it happens to.**.
     *
     * A ticket nobody has collected reads *not received yet* rather than
     * borrowing a status it has not got — §8.11's "absence says it exactly",
     * pointed at the customer. After a collection the same ticket has a real
     * state, and the page says which.
     */
    public function testATicketNobodyHasCollectedSaysSo(): void
    {
        $this->signIn(self::ANYBODY);
        $this->raise('Waiting to be collected', 'Body.');

        self::assertStringContainsString('Not received yet', $this->open()->filter('main')->text());

        $this->collect();

        $text = $this->open()->filter('main')->text();
        self::assertStringNotContainsString('Not received yet', $text);
        self::assertStringContainsString('Waiting for an answer', $text);
    }

    /**
     * **And the answer comes back with no collection in it at all.**.
     *
     * The operator writes into the control plane; the customer's instance reads
     * the registry, which is what §4.4's grant has always permitted. So the very
     * next page load has the reply — no second collector, no interval, nothing to
     * wait for. That asymmetry is the reason the interval on the way *in* is
     * acceptable, and it is stated on both screens.
     */
    public function testTheOperatorsAnswerAppearsImmediately(): void
    {
        $this->signIn(self::ANYBODY);
        $this->raise('Please look at this', 'Body.');
        $this->collect();

        $collected = $this->collectedForThisCustomer()[0] ?? null;
        \assert($collected instanceof SupportRequest);

        $desk = self::service(SupportDesk::class);
        $desk->moveTo($collected, SupportStatus::InProgress);
        $desk->reply($collected, 'We are looking at it now.', 'The Answerer');

        $text = $this->open()->filter('main')->text();

        self::assertStringContainsString('Being looked at', $text);
        self::assertStringContainsString('We are looking at it now.', $text);
        self::assertStringContainsString('The Answerer', $text);
    }

    /**
     * **The company's tickets, not the reader's own.**.
     *
     * A colleague who asked the same question on Tuesday should find the answer
     * rather than ask it again, which is most of what a screen buys over an
     * email. The name of whoever raised it is on the row, so nothing here is
     * anonymous — it is simply not private between colleagues, and §8.17 says so
     * where somebody deciding what to type can read it.
     */
    public function testAColleagueSeesWhatTheCompanyHasAsked(): void
    {
        $this->signIn(self::ANYBODY);
        $this->raise('Asked by one person', 'Body.');

        $this->signIn(self::COLLEAGUE);

        $text = $this->open()->filter('main')->text();

        self::assertStringContainsString('Asked by one person', $text);
        self::assertStringContainsString('Sam Nobody', $text, 'and who asked it');
    }

    // -- helpers -------------------------------------------------------------

    /** Fills in the form on the page and submits it, which is what a person does. */
    private function raise(string $subject, string $body): void
    {
        $page = $this->open();

        $this->client->submit($page->selectButton('Send')->form([
            'subject' => $subject,
            'body' => $body,
        ]));

        self::assertResponseRedirects();
        $this->client->followRedirect();
    }

    private function open(): Crawler
    {
        $page = $this->client->request('GET', $this->url('/support'));
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function collect(): void
    {
        self::service(SupportTicketCollector::class)->collect($this->registryRow());
        $this->controlManager()->clear();
    }

    /**
     * This class's tenant, **freshly out of the control-plane manager**.
     *
     * The object `sharedTenant()` handed back is detached the moment this class
     * clears that manager — which it does on the way into every test — and
     * persisting a `SupportRequest` pointing at a detached `Tenant` is Doctrine's
     * *"a new entity was found through the relationship"* rather than the row
     * anybody wanted. `PurchaseIntentTest` carries the same helper and the same
     * scar.
     */
    private function registryRow(): Tenant
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::SLUG);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * What is actually in the customer's own database.
     *
     * Read through the switcher rather than through whatever manager the last
     * request left behind, so this is a statement about a database rather than
     * about an identity map.
     *
     * @return list<SupportTicket>
     */
    private function ticketsInTheirDatabase(): array
    {
        /** @var list<SupportTicket> $tickets */
        $tickets = self::service(TenantSwitcher::class)->runFor($this->registryRow(), function (): array {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $manager->clear();

            return $manager->getRepository(SupportTicket::class)->findBy([], ['id' => 'ASC']);
        });

        return $tickets;
    }

    /** @return list<SupportRequest> */
    private function collectedForThisCustomer(): array
    {
        $this->controlManager()->clear();

        /** @var list<SupportRequest> $rows */
        $rows = $this->controlManager()
            ->getRepository(SupportRequest::class)
            ->findBy(['tenant' => $this->registryRow()]);

        return $rows;
    }

    /**
     * Signs somebody in, having signed out whoever was there.
     *
     * `/login` redirects an already-authenticated browser to the dashboard, so
     * without the sign-out the second call would find no form and the test would
     * fail somewhere unrelated — `NoticeWidgetTest`'s scar.
     */
    private function signIn(string $email): void
    {
        $this->client->request('GET', $this->url('/logout'));

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * The collected copies, which are in the control plane and are therefore not
     * rolled back with the test.
     *
     * Scoped to this class's own tenant by identity rather than by a pattern — a
     * `LIKE` in a cleanup is a wildcard aimed at somebody else's fixtures
     * (`NoticeGrantsTest` has the scar).
     */
    private function forgetCollected(): void
    {
        $manager = $this->controlManager();

        $manager->createQuery(
            'DELETE FROM ' . SupportRequest::class . ' s WHERE s.tenant IN ('
            . 'SELECT t.id FROM ' . Tenant::class . ' t WHERE t.slug = :slug)',
        )
            ->setParameter('slug', self::SLUG)
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

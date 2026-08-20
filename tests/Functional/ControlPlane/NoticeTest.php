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

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticePriority;
use App\Registry\Entity\NoticeReach;
use App\Registry\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * An operator can say something, and can see what they have said (XIV-120,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * The operator's half of the ticket.
 * {@see \App\Tests\Functional\Tenant\NoticeWidgetTest} proves a notice arrives on
 * the dashboard of the people it was addressed to; this proves the writing of one
 * and — the part the ticket is sharpest about — that the screen tells the truth
 * about **what is live** and **who it went to**.
 *
 * ## Why that second thing gets three tests rather than one
 *
 * Because the failure it guards against is silent. *"A notice nobody sees is
 * worse than none, because the operator believes they have told somebody"* — and
 * an operator's belief here rests on two numbers on one page. A count that
 * included withdrawn notices, or an addressee list that showed another notice's
 * customers, would both leave that page looking exactly as it does when it is
 * right.
 *
 * So: the count is asserted against a mixture of live and ended notices rather
 * than against a page with one thing on it, and the addressing is asserted with
 * **two** notices addressed to **different** customers, which is the only shape
 * in which a query that ignores its scope can be caught.
 *
 * ## Registry rows, and no customer databases
 *
 * Addressing a notice needs customers to address it to, and a customer is a
 * registry row. Nothing on this screen opens a tenant connection — that is
 * [XIV-58]'s boundary, asserted below — so the rows are made directly and no
 * database is provisioned. The control plane is not rolled back between tests, so
 * everything written here is removed by hand on the way in and on the way out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeTest extends WebTestCase
{
    private const string OPERATOR = 'notices@example.test';
    private const string OPERATOR_NAME = 'The Announcer';
    private const string PASSWORD = 'operator-password-120';

    /** Three customers, so that "addressed to two of them" is a statement about a scope. */
    private const string SLUG_PREFIX = 'test_notice_cp_';

    private const string ALPHA = self::SLUG_PREFIX . 'alpha';
    private const string BETA = self::SLUG_PREFIX . 'beta';
    private const string GAMMA = self::SLUG_PREFIX . 'gamma';

    /** Every notice this class writes says this, so the cleanup can find them and nothing else. */
    private const string MARK = '[notice-cp-test]';

    private KernelBrowser $client;
    private string $controlPlaneHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // One test asks what state the tenant connection was left in after a
        // request; a rebooted kernel would hand back a fresh one nobody had
        // touched.
        $this->client->disableReboot();

        $this->controlPlaneHost = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->forgetEverything();

        foreach ([self::ALPHA, self::BETA, self::GAMMA] as $slug) {
            $this->tenant($slug);
        }
    }

    protected function tearDown(): void
    {
        $this->forgetEverything();

        parent::tearDown();
    }

    /**
     * The whole point of the ticket, in one request: an operator writes something
     * and it is addressed to every customer.
     */
    public function testAnOperatorWritesANoticeForEverybody(): void
    {
        $this->publish(['title' => 'Sunday maintenance']);

        $page = $this->openNotices()->filter('main')->text();

        self::assertStringContainsString('Sunday maintenance', $page);
        self::assertStringContainsString('Every customer', $page);
        self::assertStringContainsString('Live', $page);
        // The author is on the page beside the date, which is what makes it an
        // announcement rather than an anonymous banner.
        self::assertStringContainsString(self::OPERATOR_NAME, $page);
    }

    /**
     * **Addressed to named customers, and the screen says which ones.**.
     *
     * Two notices with different addressees, because a row that printed *every*
     * recipient in the database — or the other notice's — would pass a test with
     * one notice on the page. The assertion is per row, not per page.
     */
    public function testTheScreenSaysWhoEachNoticeWentTo(): void
    {
        $this->publish([
            'title' => 'For alpha and beta',
            'every_tenant' => '0',
            'tenants' => [self::ALPHA, self::BETA],
        ]);
        $this->publish([
            'title' => 'For gamma',
            'every_tenant' => '0',
            'tenants' => [self::GAMMA],
        ]);

        $rows = $this->rows();

        self::assertSame(
            [self::ALPHA, self::BETA],
            self::addresseesOf($rows, 'For alpha and beta'),
            'a notice lists the customers it was addressed to',
        );
        self::assertSame(
            [self::GAMMA],
            self::addresseesOf($rows, 'For gamma'),
            'and not another notice\'s',
        );
    }

    /**
     * **What is live**, which is the other number an operator's belief rests on.
     *
     * Asserted against a page holding one live notice and one ended one, so that
     * a banner counting rows rather than live rows fails here. The withdrawn one
     * is still on the page — *"what did we tell them in March"* is a question
     * somebody asks — and is marked as ended rather than removed.
     */
    public function testTheBannerCountsWhatIsLiveAndNotWhatIsOnThePage(): void
    {
        $this->publish(['title' => 'Still true']);
        $this->publish(['title' => 'No longer true']);

        $this->withdraw('No longer true');

        $page = $this->openNotices()->filter('main')->text();

        self::assertStringContainsString('1 notice(s) customers are seeing now', $page);
        self::assertStringContainsString('No longer true', $page, 'a withdrawn notice stays on the operator\'s page');
        self::assertStringContainsString('Ended', $page);
    }

    /**
     * Withdrawing is the operator's undo, and it takes the notice off every
     * customer's dashboard by expiring it rather than by deleting anything.
     */
    public function testWithdrawingEndsANoticeWithoutRemovingIt(): void
    {
        $this->publish(['title' => 'Briefly true']);
        $this->withdraw('Briefly true');

        $notice = $this->noticeTitled('Briefly true');

        self::assertInstanceOf(Notice::class, $notice, 'withdrawing is not deleting');
        self::assertFalse($notice->isLiveAt(new \DateTimeImmutable()));
    }

    /**
     * A notice addressed to named customers and naming none is refused.
     *
     * The failure it prevents is the characteristic one: an operator believing
     * they have told somebody. Nothing is published, and the message says so.
     */
    public function testANoticeAddressedToNobodyIsRefused(): void
    {
        $landed = $this->publish([
            'title' => 'To nobody at all',
            'every_tenant' => '0',
            'tenants' => [],
        ]);

        self::assertNull($this->noticeTitled('To nobody at all'));
        self::assertStringContainsString('has to name at least one', $landed->filter('main')->text());
    }

    /**
     * And one naming a customer who is not there is refused **entirely**, rather
     * than published to the ones that resolved.
     *
     * A customer deprovisioned while the page was open produces exactly this, and
     * reaching three of the four companies while reporting success is the same
     * silent failure wearing a different hat.
     */
    public function testANoticeNamingAnUnknownCustomerIsRefusedEntirely(): void
    {
        $landed = $this->publish([
            'title' => 'To one who left',
            'every_tenant' => '0',
            'tenants' => [self::ALPHA, 'test_notice_cp_departed'],
        ]);

        self::assertNull($this->noticeTitled('To one who left'), 'all or nothing');
        self::assertStringContainsString(
            'test_notice_cp_departed',
            $landed->filter('main')->text(),
            'and the operator is told which name did not resolve',
        );
    }

    /**
     * An every-page notice with no end is refused (XIV-166).
     *
     * **The refusal that pays for the decision next door**, and it is worth
     * saying why it is a refusal rather than a warning. An every-page notice is
     * deliberately not dismissible, because a band a reader can switch off once
     * is the dashboard channel with extra steps
     * ({@see NoticeReach::isDismissible()}). Somebody has to be able to make it
     * stop, and if it is not the reader then it has to be the operator, and the
     * moment to make an operator decide when is before the band goes up rather
     * than after a customer complains.
     *
     * The dashboard half of the same submission is the control: a notice with no
     * end is perfectly ordinary there, because a release note is still true next
     * month.
     */
    public function testAnEveryPageNoticeWithNoEndIsRefused(): void
    {
        $landed = $this->publish([
            'title' => 'A band with no end',
            'reach' => 'every_page',
            'expires_at' => '',
        ]);

        self::assertNull($this->noticeTitled('A band with no end'));
        self::assertStringContainsString('has to say when it stops', $landed->filter('main')->text());

        $this->publish(['title' => 'A card with no end']);

        self::assertInstanceOf(
            Notice::class,
            $this->noticeTitled('A card with no end'),
            'the dashboard channel is unaffected: a release note is still true next month',
        );
    }

    /**
     * The reach and the priority reach the row, and the screen says so.
     *
     * Both halves in one test because they are one submission and one cell. The
     * screen's part matters more than it looks: an operator publishing to every
     * page of every customer's installation should be able to see afterwards
     * that that is what they did, and the list is the only place that can tell
     * them.
     */
    public function testTheReachAndThePriorityAreStoredAndShown(): void
    {
        $this->publish([
            'title' => 'A loud one',
            'reach' => 'every_page',
            'priority' => 'danger',
            'expires_at' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        ]);

        $notice = $this->noticeTitled('A loud one');
        \assert($notice instanceof Notice);

        self::assertSame(NoticeReach::EveryPage, $notice->getReach());
        self::assertSame(NoticePriority::Danger, $notice->getPriority());
        self::assertFalse($notice->isDismissible(), 'and the loud channel cannot be put away');

        $row = $this->openNotices()->filter('tbody tr')->first()->text();

        self::assertStringContainsString('On every page', $row);
        self::assertStringContainsString('Action needed', $row);
    }

    /**
     * A notice published with no opinion about either is the quiet channel at
     * one weight, which is what every notice written before XIV-166 was.
     *
     * The form preselects both, so this is not testing the form: it is testing
     * that the two defaults an operator is offered are the two the *row* would
     * have taken anyway, which is what makes the migration's back-fill honest
     * rather than a guess.
     */
    public function testAPlainNoticeIsADashboardNoticeForInformation(): void
    {
        $this->publish(['title' => 'An ordinary one']);

        $notice = $this->noticeTitled('An ordinary one');
        \assert($notice instanceof Notice);

        self::assertSame(NoticeReach::Dashboard, $notice->getReach());
        self::assertSame(NoticePriority::Info, $notice->getPriority());
        self::assertTrue($notice->isDismissible());
    }

    /**
     * **The screen opens no tenant connection**, which is [XIV-58]'s boundary and
     * is asserted the same way the tenant list and the purchase screen assert it.
     *
     * It matters more here than it looks: this page draws a checkbox per customer,
     * which is exactly the shape that tempts somebody to ask each of them
     * something.
     */
    public function testTheScreenOpensNoTenantConnection(): void
    {
        $this->publish(['title' => 'Sunday maintenance']);

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);
        $connection->close();

        $this->openNotices();

        self::assertFalse($connection->isConnected(), 'the customer databases were left alone');
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Posts the publish form.
     *
     * The token is read off the page the operator is looking at rather than
     * minted, so this goes through the same CSRF check a browser does; the fields
     * are posted directly because `tenants[]` is a list of checkboxes and driving
     * those through the crawler would be a test about DomCrawler's array-field
     * handling rather than about notices.
     *
     * Returns the page the operator lands on, because the flash saying what
     * happened is on it and is gone by the next request — which is the whole
     * point of a flash and is why a refusal has to be read here rather than on a
     * later visit.
     *
     * @param array{title?: string, body?: string, audience?: string, every_tenant?: string, tenants?: list<string>, expires_at?: string, reach?: string, priority?: string} $values
     */
    private function publish(array $values): Crawler
    {
        $page = $this->openNotices();

        $token = $page->filter('form[action="/control/notices"] input[name="_token"]')->attr('value');
        \assert(\is_string($token));

        $this->client->request('POST', $this->controlUrl('/control/notices'), [
            '_token' => $token,
            'title' => $values['title'] ?? 'A notice',
            'body' => ($values['body'] ?? 'The body of ' . ($values['title'] ?? 'A notice')) . ' ' . self::MARK,
            'audience' => $values['audience'] ?? 'everyone',
            // The two XIV-166 added, defaulted here to what the form's own
            // controls are preselected to: the quiet channel at the one weight
            // everything used to be drawn in. A test about addressing or about
            // withdrawal should not have to have an opinion about reach.
            'reach' => $values['reach'] ?? 'dashboard',
            'priority' => $values['priority'] ?? 'info',
            'every_tenant' => $values['every_tenant'] ?? '1',
            'tenants' => $values['tenants'] ?? [],
            'expires_at' => $values['expires_at'] ?? '',
        ]);

        return $this->client->followRedirect();
    }

    /** Presses the withdraw button the screen draws for that notice. */
    private function withdraw(string $title): void
    {
        $notice = $this->noticeTitled($title);
        \assert($notice instanceof Notice);

        $page = $this->openNotices();
        $action = sprintf('/control/notices/%d/withdraw', $notice->getId());

        $token = $page->filter(sprintf('form[action="%s"] input[name="_token"]', $action))->attr('value');
        \assert(\is_string($token));

        $this->client->request('POST', $this->controlUrl($action), ['_token' => $token]);
        $this->client->followRedirect();

        $this->controlManager()->clear();
    }

    /**
     * The rows of the notice table, as title => the text of the cell naming who
     * it went to.
     *
     * Per row rather than per page, which is the whole point — see
     * {@see testTheScreenSaysWhoEachNoticeWentTo()}.
     *
     * @return array<string, string>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->openNotices()->filter('table tbody tr') as $node) {
            $row = new Crawler($node);
            $cells = $row->filter('td');

            $rows[trim($cells->eq(0)->filter('div')->first()->text())] = $cells->eq(1)->text();
        }

        return $rows;
    }

    /**
     * Which of this class's customers a row names.
     *
     * Derived from the row's own text rather than from the database, because what
     * is being tested is what the operator can see.
     *
     * @param array<string, string> $rows
     *
     * @return list<string>
     */
    private static function addresseesOf(array $rows, string $title): array
    {
        $cell = $rows[$title] ?? '';

        return array_values(array_filter(
            [self::ALPHA, self::BETA, self::GAMMA],
            static fn (string $slug): bool => str_contains($cell, $slug),
        ));
    }

    private function noticeTitled(string $title): ?Notice
    {
        $manager = $this->controlManager();
        $manager->clear();

        return $manager->getRepository(Notice::class)->findOneBy(['title' => $title]);
    }

    /** A customer, as a registry row: nothing here connects to one. */
    private function tenant(string $slug): Tenant
    {
        $manager = $this->controlManager();
        $existing = $manager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        $tenant = new Tenant($slug, ucfirst($slug), 'postgresql://nobody@nowhere/' . $slug);

        $manager->persist($tenant);
        $manager->flush();

        return $tenant;
    }

    /** Signs an operator in and opens the notices screen. */
    private function openNotices(): Crawler
    {
        // Created once and left behind for the rest of the process, like the
        // shared tenant: the control plane is not rolled back, so making one per
        // test would be making one per test for ever.
        if (self::service(OperatorRepository::class)->findOneByEmail(self::OPERATOR) === null) {
            self::service(OperatorCreator::class)->create(self::OPERATOR, self::OPERATOR_NAME, self::PASSWORD);
        }

        $login = $this->client->request('GET', $this->controlUrl('/control/login'));

        // The sign-in page redirects an operator who is already signed in, so
        // submitting unconditionally would fail on a crawler with no form in it.
        if ($login->filter('form')->count() > 0) {
            $this->client->submit($login->selectButton('Sign in')->form([
                'email' => self::OPERATOR,
                'password' => self::PASSWORD,
            ]));
        }

        $page = $this->client->request('GET', $this->controlUrl('/control/notices'));
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function controlUrl(string $path): string
    {
        return sprintf('https://%s%s', $this->controlPlaneHost, $path);
    }

    /**
     * Everything this class wrote, which DAMA deliberately does not roll back.
     *
     * Idempotent on the way in as well as the way out: a run that died halfway
     * leaves the rows standing and the next run is the one that has to cope.
     */
    private function forgetEverything(): void
    {
        $manager = $this->controlManager();

        $manager->createQuery('DELETE FROM App\Registry\Entity\NoticeRecipient r WHERE r.notice IN (
            SELECT n.id FROM App\Registry\Entity\Notice n WHERE n.body LIKE :mark
        )')->setParameter('mark', '%' . self::MARK . '%')->execute();

        $manager->createQuery('DELETE FROM App\Registry\Entity\Notice n WHERE n.body LIKE :mark')
            ->setParameter('mark', '%' . self::MARK . '%')
            ->execute();

        $manager->createQuery('DELETE FROM App\Registry\Entity\Tenant t WHERE t.slug LIKE :slug')
            ->setParameter('slug', self::SLUG_PREFIX . '%')
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

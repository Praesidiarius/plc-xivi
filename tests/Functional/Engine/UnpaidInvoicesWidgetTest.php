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

namespace App\Tests\Functional\Engine;

use App\Dashboard\Widget\ModuleTilesWidget;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\Dbal\MeasuresQueries;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Invoice\Dashboard\UnpaidInvoicesWidget;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * A widget shipped by a module package, end to end (XIV-66).
 *
 * **This class is the acceptance criterion.** The rest of the ticket could be
 * built entirely inside `src/` and look finished: the seam would sit in
 * `packages/core`, the layout would save, the panels would defer, and nothing
 * would prove that a module can actually declare a widget — which is the whole
 * reason the seam moved. So this drives the invoice module's own card through the
 * application: its class is in `packages/invoice`, its template is in
 * `packages/invoice/templates`, its words are in `packages/invoice/translations`,
 * and none of the three is reachable from the other direction.
 *
 * Three claims are worth a test each and are the three this makes:
 *
 * - **It draws what it says it draws** — sent invoices, most overdue first, as
 *   links rather than as a number.
 * - **It answers under the reader's own permissions**, both ways: no grant means
 *   no card at all, and a reader scoped to their own records sees their own with
 *   a count that agrees with what is under it.
 * - **It does not block the page.** The heading is on the first response and the
 *   contents are not, which is the difference between a card that costs its own
 *   tile and one that costs the landing page.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UnpaidInvoicesWidgetTest extends WebTestCase
{
    use MeasuresQueries;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_unpaid_widget';
    private const string HOST = 'unpaid-widget.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    /** Creates the data, so everything below is owned by this account. */
    private const string EMAIL = 'admin@unpaid.test';

    /** May see every invoice: the ordinary reader this widget is for. */
    private const string READER = 'reader@unpaid.test';

    /** May see only their own, and owns none of them. */
    private const string SCOPED = 'scoped@unpaid.test';

    /** Has no grant on invoices at all. */
    private const string OUTSIDER = 'outsider@unpaid.test';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The widget resolves the reader and their grants per request, and a
        // rebooting kernel would throw away the tenant the sign-in landed on.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }

            // Thirty days, so that sending an invoice materialises a due date at
            // all (§5.16). Without terms anywhere nothing is ever overdue, and
            // the overdue half of this widget would be untestable rather than
            // merely untested.
            self::service(TenantProfileManager::class)->apply('Unpaid AG', 'CHF', 'CH', 30);
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, []);
        $users->create($this->tenant, self::SCOPED, 'Sam Scoped', self::PASSWORD, []);
        $users->create($this->tenant, self::OUTSIDER, 'Olly Outsider', self::PASSWORD, []);

        // List as well, so the module tiles beside this card are drawn for these
        // readers too — a dashboard with one widget on it would prove less about
        // the page than one with two.
        $this->grant(self::READER, [ModuleAction::View, ModuleAction::List]);
        $this->grant(self::SCOPED, [ModuleAction::View, ModuleAction::List], PermissionScope::Own);

        // Signed in as the administrator, because building an invoice means
        // driving the record form and the send transition through the
        // application — and both are behind the firewall. Every test signs in
        // again as whoever it is actually about.
        $this->signIn();
    }

    // -- what the card shows ------------------------------------------------

    /**
     * Sent and not settled, and nothing else.
     *
     * A draft was never asked for and a cancelled one never will be, so "unpaid"
     * is exactly one lifecycle state and needs no negation — which is why the
     * widget's filter is a single equality rather than a list of exclusions.
     */
    public function testItNamesTheInvoicesThatAreOutOfTheDoorAndUnsettled(): void
    {
        $sent = $this->sentInvoice('2026-08-01');
        $draft = $this->draftInvoice('2026-08-02');
        $paid = $this->paidInvoice('2026-08-03');

        $this->signIn(self::READER);
        $card = $this->card();

        self::assertStringContainsString($this->numberOf($sent), $card);
        self::assertStringNotContainsString($this->numberOf($draft), $card, 'a draft was never asked for');
        self::assertStringNotContainsString($this->numberOf($paid), $card, 'and a paid one has been settled');
    }

    /**
     * A number is worse than a list you can act on, so every line is a way to
     * get to the record.
     *
     * The address is the thing this package could not have built for itself: a
     * route name belongs to the application, so the widget asks `RecordPageUrl`
     * for one and nothing in `packages/invoice` knows that `module_show` exists.
     * The assertion is on the path that seam produces.
     */
    public function testEveryLineIsAWayToGetToTheInvoice(): void
    {
        $invoice = $this->sentInvoice('2026-08-01');

        $this->signIn(self::READER);

        self::assertStringContainsString('/m/invoice/' . $invoice, $this->card());
    }

    /**
     * Late is a read rather than a stored flag (§5.16), and the badge says so.
     *
     * One invoice issued long enough ago that thirty days have passed and one
     * issued today, so the assertion is about the calendar rather than about
     * which day the suite happens to run on.
     */
    public function testAnInvoicePastItsDateIsBadgedAndOneStillInTermsIsNot(): void
    {
        $late = $this->sentInvoice((new \DateTimeImmutable('-90 days'))->format('Y-m-d'));
        $current = $this->sentInvoice((new \DateTimeImmutable('today'))->format('Y-m-d'));

        $this->signIn(self::READER);
        $card = $this->card();

        self::assertStringContainsString('Overdue', $card);

        // Most overdue first: the order the widget sorts in is the order somebody
        // would want to work through, and it comes from the due date alone.
        self::assertLessThan(
            strpos($card, $this->numberOf($current)),
            strpos($card, $this->numberOf($late)),
            'the one that is late is at the top',
        );
    }

    // -- permissions --------------------------------------------------------

    /**
     * Somebody with no grant on invoices is not shown the card at all.
     *
     * Null rather than an empty card, and the distinction is XIV-81's: a widget's
     * null means "this does not apply to you", and there is no reading of this one
     * that could ever say anything to a person who may not see an invoice. That is
     * the opposite call from the follow-up widget's, which stays on the page for a
     * reader with no grants because outstanding *work* does not disappear when a
     * View grant does — see the widget's own docblock, where the two are argued
     * apart.
     */
    public function testSomebodyWithNoGrantOnInvoicesIsNotOfferedTheCard(): void
    {
        $this->sentInvoice('2026-08-01');

        $this->signIn(self::OUTSIDER);
        $page = $this->dashboardPage();

        self::assertStringNotContainsString('Awaiting payment', $page, 'not even the heading');
    }

    /**
     * And a reader scoped to their own records is shown their own, which here is
     * none of them.
     *
     * Everything in this class is owned by the account that created it, so a
     * scoped reader's answer is the empty one — and the card says "nothing is
     * outstanding", which is true *for them*. The failure this rules out is the
     * one XIV-52 is about: a list restricted by scope with a total that was not,
     * printing the number of records somebody may not see directly underneath the
     * ones they may.
     */
    public function testAReaderScopedToTheirOwnRecordsSeesTheirOwn(): void
    {
        $invoice = $this->sentInvoice('2026-08-01');
        // Read while the administrator is still signed in: this reader may not
        // open that invoice at all, so asking for its page as them answers 404 —
        // which is the correct behaviour and would make a useless failure here.
        $number = $this->numberOf($invoice);

        $this->signIn(self::SCOPED);
        $card = $this->card();

        self::assertStringNotContainsString($number, $card);
        self::assertStringContainsString('Nothing is outstanding', $card);
        self::assertStringNotContainsString('And 1 more', $card, 'the count is scoped with the list');
    }

    // -- loading ------------------------------------------------------------

    /**
     * The landing page arrives without the card's contents, and gets them one
     * request later.
     *
     * This is what `loading="defer"` buys and the only way to see it from
     * outside: the heading is on the first response, because whether a card
     * exists is settled before anything renders, and the rows are not, because
     * counting them is what the second request is for. A change that quietly went
     * back to rendering inline would still draw the right page and would fail
     * here.
     */
    public function testTheDashboardDoesNotWaitForTheCard(): void
    {
        $invoice = $this->sentInvoice('2026-08-01');

        $this->signIn(self::READER);
        $page = $this->dashboardPage();

        self::assertStringContainsString('Awaiting payment', $page, 'the card exists');
        self::assertStringNotContainsString($this->numberOf($invoice), $page, 'and its rows are still to come');
        self::assertStringContainsString(
            'data-live-url-value="/_components/DashboardPanel"',
            $page,
            'mounted, and fetched separately',
        );

        self::assertStringContainsString($this->numberOf($invoice), $this->card(), 'and they arrive');
    }

    /**
     * And the saving is a number rather than a feeling: the page issues no query
     * on this widget's behalf.
     *
     * This is the assertion that makes `defer` mean anything. Deferring the
     * *rendering* while `panel()` still counted rows would move the work to a
     * different line of the same request and change nothing at all — which is
     * why the panel's data is a promise rather than an array, and why this
     * counts statements instead of reading markup.
     *
     * The bound is the whole page rather than this widget's share of it, because
     * a widget's share is not separable from outside; what is separable is the
     * *difference*, so the same page is measured with the card and without it and
     * the two are asserted to cost the same. Two invoices exist while it runs, so
     * a widget that had read them would show up.
     */
    public function testTheLandingPageCostsNoInvoiceQueryUntilTheCardIsFetched(): void
    {
        $this->sentInvoice('2026-08-01');
        $this->sentInvoice('2026-08-02');

        $this->signIn(self::READER);
        // Warmed first: the first page after a sign-in pays for the session's
        // user, the profile and the definitions, and none of that is what is
        // being measured.
        $this->dashboardPage();

        [, $withTheCard] = self::countingQueries(fn (): string => $this->dashboardPage());

        $this->inTenant(fn () => self::service(UserManager::class)->setDashboardLayout(
            $this->user(self::READER),
            [ModuleTilesWidget::KEY],
        ));

        [, $withoutIt] = self::countingQueries(fn (): string => $this->dashboardPage());

        self::assertSame(
            $withoutIt,
            $withTheCard,
            'the landing page costs the same whether or not this card is on it',
        );
    }

    // -- helpers ------------------------------------------------------------

    /** The card as this reader sees it, once the browser has been back for it. */
    private function card(): string
    {
        return $this->inTenant(fn (): string => $this->panel()->render()->crawler()->html());
    }

    private function panel(): TestLiveComponent
    {
        return $this->createLiveComponent(
            'DashboardPanel',
            ['widget' => UnpaidInvoicesWidget::KEY],
            $this->client,
        );
    }

    private function dashboardPage(): string
    {
        return $this->client->request('GET', $this->url('/'))->filter('main')->html();
    }

    /** What the invoice is called, which is its document number (§5.10). */
    private function numberOf(int $invoice): string
    {
        return $this->inTenant(function () use ($invoice): string {
            $number = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))
                ->filter('main h1')
                ->text();

            return trim($number);
        });
    }

    private function draftInvoice(string $issuedOn): int
    {
        $contact = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));

        $order = $this->savedId($this->saveRecord(
            OrderModule::KEY,
            ['contact' => (string) $contact, 'ordered_on' => $issuedOn, 'status' => OrderModule::DRAFT],
            [OrderModule::LINES => [self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
            ])]],
        ));

        return $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            [
                InvoiceModule::ORDER => (string) $order,
                InvoiceModule::CONTACT => (string) $contact,
                InvoiceModule::ISSUED_ON => $issuedOn,
                InvoiceModule::STATUS => InvoiceModule::DRAFT,
            ],
            [InvoiceModule::LINES => [self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
            ])]],
        ));
    }

    /**
     * An invoice that has been sent, through the transition rather than by
     * writing the state.
     *
     * The due date is derived on the way into `sent` (§5.16) — writing the column
     * by hand would suppress the engine and produce a record that looks plausible
     * and has no deadline on it, which is exactly the record this widget cannot
     * be tested against.
     */
    private function sentInvoice(string $issuedOn): int
    {
        $invoice = $this->draftInvoice($issuedOn);
        $this->transition($invoice, 'send');

        return $invoice;
    }

    private function paidInvoice(string $issuedOn): int
    {
        $invoice = $this->sentInvoice($issuedOn);
        $this->transition($invoice, 'pay');

        return $invoice;
    }

    private function transition(int $invoice, string $name): void
    {
        $tokens = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))
            ->filter('input[name="_token"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        $this->client->request(
            'POST',
            $this->url(sprintf('/m/invoice/%d/transition/%s', $invoice, $name)),
            ['_token' => $tokens[0] ?? 'no-token'],
        );
    }

    /** @param list<ModuleAction> $actions */
    private function grant(string $email, array $actions, PermissionScope $scope = PermissionScope::All): void
    {
        $this->inTenant(function () use ($email, $actions, $scope): void {
            $user = $this->user($email);

            foreach ($actions as $action) {
                $this->entityManager()->persist(
                    PermissionGrant::forUser($user, InvoiceModule::KEY, $action, $scope),
                );
            }

            $this->entityManager()->flush();
        });
    }

    /**
     * The *tenant's* manager, which autowiring cannot pick for a test.
     *
     * `EntityManagerInterface` is the control plane's here, and grants live in
     * the customer's database — the same split every service in
     * config/services.yaml is handed explicitly.
     */
    private function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine')->getManager('tenant');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function user(string $email): User
    {
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Sign in, from wherever this client happens to be.
     *
     * The sign-out first is not ceremony: every test here builds its invoices as
     * the administrator and then reads the card as somebody else, and `/login`
     * asked for by an authenticated browser redirects to the dashboard — so
     * without this the second sign-in looks for a button on a page that has
     * none.
     */
    private function signIn(string $email = self::EMAIL): void
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

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
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

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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Type\DateFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Payment\Overdue;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * When an invoice falls due, and what makes it late (XIV-67).
 *
 * The ticket is two arguments and this class is the proof of both.
 *
 * **The due date is stored rather than computed**, so that editing a customer's
 * payment terms cannot silently restate the deadline on every invoice they have
 * already been sent — {@see self::testChangingAContactsTermsLeavesSentInvoicesAlone()}
 * is the regression the whole design exists to prevent, and without it the design
 * would be unproven.
 *
 * **Overdue is a read rather than a fifth lifecycle state**, so no job has to
 * move anything and no stored flag can disagree with the calendar.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceDueDateTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_due_dates';
    private const string HOST = 'due-dates.localhost';
    private const string EMAIL = 'due-dates@example.test';
    private const string PASSWORD = 'due-dates-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Due dates', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the three layers ---------------------------------------------------

    /** With nothing anywhere, an invoice goes out with no deadline on it at all. */
    public function testWithNoTermsAnywhereAnInvoiceHasNoDueDate(): void
    {
        $invoice = $this->sentInvoiceFor($this->aCompany(), issuedOn: '2026-08-15');

        self::assertNull($this->invoiceRecord($invoice)->get(InvoiceModule::DUE_DATE));
    }

    /** The installation's own answer, which applies to everybody nobody has said otherwise about. */
    public function testTheTenantDefaultDecidesWhenAnInvoiceFallsDue(): void
    {
        $this->tenantTerms(30);

        $invoice = $this->sentInvoiceFor($this->aCompany(), issuedOn: '2026-08-15');

        self::assertSame('2026-09-14', $this->dueDateOf($invoice));
    }

    /** And a customer with their own terms overrides it, which is the middle layer's whole job. */
    public function testAContactOverridesTheTenantDefault(): void
    {
        $this->tenantTerms(30);

        $invoice = $this->sentInvoiceFor($this->aCompany(terms: 14), issuedOn: '2026-08-15');

        self::assertSame('2026-08-29', $this->dueDateOf($invoice));
    }

    /**
     * Zero is a term and not an absence: payable on receipt is a real agreement,
     * and reading it as "nobody said" would quietly give that customer the
     * installation's month.
     */
    public function testAContactOnZeroDaysIsDueTheDayItIsIssued(): void
    {
        $this->tenantTerms(30);

        $invoice = $this->sentInvoiceFor($this->aCompany(terms: 0), issuedOn: '2026-08-15');

        self::assertSame('2026-08-15', $this->dueDateOf($invoice));
    }

    // -- stored, not computed -----------------------------------------------

    /**
     * The regression this whole design exists to prevent (XIV-67).
     *
     * An implementation that worked the due date out on read would fail every
     * assertion below: the customer's terms are cut from ninety days to one
     * *after* the invoice went out, which would move a deadline nobody
     * renegotiated back into the past and report an invoice as overdue for a date
     * that was never agreed. What was agreed is a fact about that document, so
     * nothing about it moves.
     *
     * The second half matters as much as the first. A test that only asserted
     * "nothing changed" would also pass against an implementation where the
     * contact's terms are never read at all, so the *next* invoice is checked
     * too: the edit does take effect, and it takes effect on documents that had
     * not been sent yet.
     */
    public function testChangingAContactsTermsLeavesSentInvoicesAlone(): void
    {
        $this->tenantTerms(30);

        $contact = $this->aCompany(terms: 90);
        $issued = new \DateTimeImmutable('today');
        $sent = $this->sentInvoiceFor($contact, issuedOn: $issued->format(DateFieldType::FORMAT));

        $due = $issued->modify('+90 days')->format(DateFieldType::FORMAT);

        self::assertSame($due, $this->dueDateOf($sent));
        self::assertFalse($this->isOverdue($sent), 'ninety days from today has not passed');

        // The relationship is renegotiated, hard: this customer now pays the day
        // after the bill arrives.
        $this->setTermsOn($contact, 1);

        self::assertSame($due, $this->dueDateOf($sent), 'a deadline already agreed does not move');
        self::assertFalse(
            $this->isOverdue($sent),
            'and the invoice does not become retroactively late for a date nobody agreed to',
        );

        // But the new terms are real, and the proof is the next document: an
        // invoice issued today under one-day terms is due tomorrow.
        $next = $this->sentInvoiceFor($contact, issuedOn: $issued->format(DateFieldType::FORMAT));

        self::assertSame($issued->modify('+1 day')->format(DateFieldType::FORMAT), $this->dueDateOf($next));
    }

    /** A draft owes nobody anything, so it carries no deadline. */
    public function testADraftHasNoDueDate(): void
    {
        $this->tenantTerms(30);

        $draft = $this->draftInvoiceFor($this->aCompany(), issuedOn: '2026-08-15');

        self::assertNull($this->invoiceRecord($draft)->get(InvoiceModule::DUE_DATE));
        self::assertFalse($this->isOverdue($draft));
    }

    /**
     * Materialised on the way into `sent` and never again: settling the invoice
     * writes the record a second time, and the date it was due stays what it was
     * on the day it went out rather than being restated from today's terms.
     */
    public function testPayingAnInvoiceDoesNotRestateItsDueDate(): void
    {
        $this->tenantTerms(30);

        $contact = $this->aCompany(terms: 10);
        $invoice = $this->sentInvoiceFor($contact, issuedOn: '2026-08-15');

        self::assertSame('2026-08-25', $this->dueDateOf($invoice));

        $this->setTermsOn($contact, 60);
        $this->transition($invoice, 'pay');

        self::assertSame(InvoiceModule::PAID, $this->invoiceRecord($invoice)->get(InvoiceModule::STATUS));
        self::assertSame('2026-08-25', $this->dueDateOf($invoice), 'unchanged by a second save');
    }

    // -- overdue is a read --------------------------------------------------

    /** Sent, and the date has gone by. */
    public function testASentInvoicePastItsDateIsOverdue(): void
    {
        $this->tenantTerms(0);

        $invoice = $this->sentInvoiceFor(
            $this->aCompany(),
            issuedOn: (new \DateTimeImmutable('today'))->modify('-3 days')->format(DateFieldType::FORMAT),
        );

        self::assertTrue($this->isOverdue($invoice));
        self::assertStringContainsString('Overdue', $this->pageOf($invoice), 'and it says so where somebody reads it');
    }

    /**
     * Due today is due today. Telling somebody their customer is late on the
     * morning the bill falls due is how a dunning list loses its credibility.
     */
    public function testAnInvoiceDueTodayIsNotOverdue(): void
    {
        $this->tenantTerms(0);

        $invoice = $this->sentInvoiceFor(
            $this->aCompany(),
            issuedOn: (new \DateTimeImmutable('today'))->format(DateFieldType::FORMAT),
        );

        self::assertFalse($this->isOverdue($invoice));
        self::assertStringNotContainsString('Overdue', $this->pageOf($invoice));
    }

    /**
     * The rule for every invoice that predates this feature, and the one it must
     * not get wrong: an empty column is **not overdue**, never overdue. Nothing
     * is backfilled, because guessing which terms were in force months ago and
     * guessing wrong in the direction that says a paid invoice was late is the
     * bad failure.
     */
    public function testAnInvoiceWithNoDueDateIsNotOverdue(): void
    {
        // No terms anywhere, so nothing materialises — the state every invoice
        // written before this ticket is in.
        $invoice = $this->sentInvoiceFor(
            $this->aCompany(),
            issuedOn: (new \DateTimeImmutable('today'))->modify('-5 years')->format(DateFieldType::FORMAT),
        );

        self::assertNull($this->invoiceRecord($invoice)->get(InvoiceModule::DUE_DATE));
        self::assertFalse($this->isOverdue($invoice));
        self::assertStringNotContainsString('Overdue', $this->pageOf($invoice));
    }

    /**
     * Overdue is not a fifth state, and this is what that means concretely: the
     * lifecycle still knows four, a late invoice is still `sent`, and the only
     * moves offered on it are the two a person performs.
     */
    public function testOverdueIsNotALifecycleState(): void
    {
        $this->tenantTerms(0);

        $invoice = $this->sentInvoiceFor(
            $this->aCompany(),
            issuedOn: (new \DateTimeImmutable('today'))->modify('-3 days')->format(DateFieldType::FORMAT),
        );

        $lifecycle = self::service(ModuleRegistry::class)->get(InvoiceModule::KEY)->lifecycle;
        self::assertNotNull($lifecycle);

        self::assertSame(
            [InvoiceModule::DRAFT, InvoiceModule::SENT, InvoiceModule::PAID, InvoiceModule::CANCELLED],
            $lifecycle->states(),
        );

        self::assertTrue($this->isOverdue($invoice));
        self::assertSame(InvoiceModule::SENT, $this->invoiceRecord($invoice)->get(InvoiceModule::STATUS));
        self::assertSame(['Mark paid', 'Cancel'], $this->transitionsOn($invoice), 'nothing performs overdue');
    }

    /**
     * A cancelled invoice is owed by nobody, however long ago its date went by.
     * The state carries the whole of "is this money outstanding", which is why
     * the declaration names one state rather than two.
     */
    public function testACancelledInvoiceIsNotOverdue(): void
    {
        $this->tenantTerms(0);

        $invoice = $this->sentInvoiceFor(
            $this->aCompany(),
            issuedOn: (new \DateTimeImmutable('today'))->modify('-3 days')->format(DateFieldType::FORMAT),
        );

        $this->transition($invoice, 'cancel');

        self::assertFalse($this->isOverdue($invoice));
    }

    /**
     * The same rule as query conditions, for the list XIV-66 wants: sent, and
     * strictly before today. Null for a module with no notion of being owed,
     * rather than an empty list — which as a query would match everything.
     */
    public function testOverdueIsAlsoAQuery(): void
    {
        $filters = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): array {
            $metadata = self::service(MetadataRepository::class);
            $overdue = self::service(Overdue::class);

            return [
                $overdue->filters($metadata->get(InvoiceModule::KEY)),
                $overdue->filters($metadata->get(ContactModule::KEY)),
            ];
        });

        self::assertNotNull($filters[0]);
        self::assertSame(
            [InvoiceModule::STATUS, InvoiceModule::DUE_DATE],
            array_map(static fn (object $filter): string => $filter->field, $filters[0]),
        );
        self::assertNull($filters[1], 'a contact is not owed by a date');
    }

    // -- shown and filterable -----------------------------------------------

    /** Filterable, because "which of these is late" is the question it exists to answer. */
    public function testTheDueDateIsShownAndFilterable(): void
    {
        $this->tenantTerms(30);

        $invoice = $this->sentInvoiceFor($this->aCompany(), issuedOn: '2026-08-15');
        $page = $this->pageOf($invoice);

        // In the reader's own format (XIV-50) — this installation writes by Swiss
        // convention, so day first and dotted — and never the ISO string it is
        // stored as, which is a storage detail and not a date anybody writes.
        self::assertStringContainsString('14.09.2026', $page);
        self::assertStringNotContainsString('2026-09-14', $page);

        $filterable = $this->client
            ->request('GET', $this->url('/m/invoice'))
            ->filter('select[name="filter[0][path]"] option')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        self::assertContains(InvoiceModule::DUE_DATE, $filterable);
    }

    // -- helpers ------------------------------------------------------------

    /** What this installation gives everybody nobody has said otherwise about. */
    private function tenantTerms(?int $days): void
    {
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(TenantProfileManager::class)->apply('Due Dates AG', 'CHF', 'CH', $days),
        );
    }

    /** A customer, with their own terms or on whatever the installation gives. */
    private function aCompany(?int $terms = null): int
    {
        $fields = ['kind' => 'company', 'company_name' => 'Acme AG'];

        if ($terms !== null) {
            $fields['payment_terms'] = (string) $terms;
        }

        return $this->savedId($this->saveRecord(ContactModule::KEY, $fields, variant: 'company'));
    }

    /** Renegotiating, after the fact — the move the stored date has to survive. */
    private function setTermsOn(int $contact, int $terms): void
    {
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG', 'payment_terms' => (string) $terms],
            recordId: $contact,
            variant: 'company',
        );

        self::assertSame(
            $terms,
            $this->recordIn(ContactModule::KEY, $contact)->get('payment_terms'),
            'the contact really was changed',
        );
    }

    private function draftInvoiceFor(int $contact, string $issuedOn): int
    {
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
                'order' => (string) $order,
                'contact' => (string) $contact,
                'issued_on' => $issuedOn,
                'status' => InvoiceModule::DRAFT,
            ],
            [InvoiceModule::LINES => [self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
            ])]],
        ));
    }

    /** A draft, then the one transition that materialises anything. */
    private function sentInvoiceFor(int $contact, string $issuedOn): int
    {
        $invoice = $this->draftInvoiceFor($contact, $issuedOn);
        $this->transition($invoice, 'send');

        self::assertSame(
            InvoiceModule::SENT,
            $this->invoiceRecord($invoice)->get(InvoiceModule::STATUS),
            'the send really happened',
        );

        return $invoice;
    }

    /** The stored date as it is stored, so an assertion reads as a date and not as an object. */
    private function dueDateOf(int $invoice): ?string
    {
        $value = $this->invoiceRecord($invoice)->get(InvoiceModule::DUE_DATE);

        return $value instanceof \DateTimeInterface ? $value->format(DateFieldType::FORMAT) : null;
    }

    private function isOverdue(int $invoice): bool
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($invoice): bool {
            $module = self::service(MetadataRepository::class)->get(InvoiceModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $invoice);
            self::assertInstanceOf(Record::class, $record);

            return self::service(Overdue::class)->isOverdue($module, $record);
        });
    }

    private function invoiceRecord(int $id): Record
    {
        return $this->recordIn(InvoiceModule::KEY, $id);
    }

    private function recordIn(string $module, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): Record {
            $definition = self::service(MetadataRepository::class)->get($module);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
    }

    private function pageOf(int $invoice): string
    {
        return $this->client->request('GET', $this->url('/m/invoice/' . $invoice))->filter('main')->text();
    }

    /** @return list<string> */
    private function transitionsOn(int $invoice): array
    {
        return $this->client->request('GET', $this->url('/m/invoice/' . $invoice))
            ->filter('form[action*="/transition/"] button')
            ->each(static fn (Crawler $node): string => trim($node->text()));
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

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

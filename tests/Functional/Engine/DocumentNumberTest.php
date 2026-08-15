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
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Order\OrderModule;

/**
 * Real numbers on real documents (XIV-15).
 *
 * An invoice without a number is not an invoice, and the two things that can go
 * wrong with one are both here: a number that changes after somebody has quoted
 * it down the phone, and two documents carrying the same one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DocumentNumberTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_numbers';
    private const string HOST = 'numbers.localhost';
    private const string EMAIL = 'numbers@example.test';
    private const string PASSWORD = 'numbers-password';
    private const string FORM = 'module_record';

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

            foreach ([ContactModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Numbers', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** The first order of the year is 0001, and the next one is 0002. */
    public function testOrdersAreNumberedInSequence(): void
    {
        $year = date('Y');

        self::assertSame(sprintf('ORD-%s-0001', $year), $this->numberOf($this->anOrder()));
        self::assertSame(sprintf('ORD-%s-0002', $year), $this->numberOf($this->anOrder()));
        self::assertSame(sprintf('ORD-%s-0003', $year), $this->numberOf($this->anOrder()));
    }

    /** Nobody types one: the field is on the form to be read. */
    public function testTheNumberCannotBeTypedIn(): void
    {
        $this->client->request('GET', $this->url('/m/order/new'));

        $field = $this->client->getCrawler()->filter('[name="' . self::field('number') . '"]');

        self::assertCount(1, $field, 'it is shown');
        self::assertNotNull($field->attr('disabled'), 'and it is not an input');
    }

    /**
     * The sharp edge: once assigned it never moves, whatever else is edited and
     * however the record is pushed through its lifecycle.
     */
    public function testANumberNeverChanges(): void
    {
        $order = $this->anOrder();
        $number = $this->numberOf($order);

        $crawler = $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        $form[self::field('note')] = 'Changed my mind about the delivery date';
        $this->client->submit($form);

        self::assertSame($number, $this->numberOf($order), 'after an edit');

        $this->transition($order, 'confirm');

        self::assertSame($number, $this->numberOf($order), 'and after a transition');
    }

    /** And a second order still gets the next number, not the one just used. */
    public function testEditingDoesNotConsumeANumber(): void
    {
        $first = $this->anOrder();

        $crawler = $this->client->request('GET', $this->url('/m/order/' . $first . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        $form[self::field('note')] = 'Edited twice';
        $this->client->submit($form);
        $this->client->submit($form);

        self::assertSame(
            sprintf('ORD-%s-0002', date('Y')),
            $this->numberOf($this->anOrder()),
            'three saves, one number',
        );
    }

    /** It is what the order is called — on its own page and in a link to it. */
    public function testTheNumberIsWhatTheRecordIsCalled(): void
    {
        $customer = $this->aCompany();
        $order = $this->anOrder($customer);
        $number = $this->numberOf($order);

        $heading = $this->client->request('GET', $this->url('/m/order/' . $order))->filter('h1')->text();
        self::assertStringContainsString($number, $heading);

        $contact = $this->client->request('GET', $this->url('/m/contact/' . $customer))->filter('main')->text();
        self::assertStringContainsString($number, $contact, 'and in the reverse list on the contact');
    }

    /** Filterable and sortable like any other field, which the padding is for. */
    public function testTheNumberIsFilterableAndSortable(): void
    {
        $this->anOrder();
        $second = $this->anOrder();

        $listed = $this->rowsOf('/m/order?filter[0][path]=number&filter[0][op]=eq&filter[0][value]='
            . $this->numberOf($second));

        self::assertCount(1, $listed);
        self::assertStringContainsString($this->numberOf($second), $listed[0]);

        $descending = $this->rowsOf('/m/order?sort=number&dir=desc');
        self::assertStringContainsString($this->numberOf($second), $descending[0], 'the newest first');
    }

    /**
     * A deleted record keeps its number and the sequence moves on. Records are
     * soft-deleted, so what looks like a gap in the list is a document that is
     * still there to be looked at — a hole in a list rather than in the books.
     */
    public function testADeletedRecordKeepsItsNumberAndTheSequenceMovesOn(): void
    {
        $order = $this->anOrder();
        $number = $this->numberOf($order);

        $crawler = $this->client->request('GET', $this->url('/m/order/' . $order));
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertSame(
            sprintf('ORD-%s-0002', date('Y')),
            $this->numberOf($this->anOrder()),
            'the number is not handed out again',
        );
        self::assertSame($number, $this->numberOf($order, includeDeleted: true), 'and the deleted one still has it');
    }

    /**
     * Every number comes from one atomic statement, so a hundred of them are a
     * hundred different numbers. The race this rules out is read-then-increment
     * in PHP, where two requests read 41 and two invoices go out as 42.
     */
    public function testACounterNeverGivesTheSameNumberTwice(): void
    {
        $allocated = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): array {
            $allocator = self::service(NumberAllocator::class);
            $numbers = [];

            for ($i = 0; $i < 100; ++$i) {
                $numbers[] = $allocator->next('probe', 'number', '2026');
            }

            return $numbers;
        });

        self::assertSame(range(1, 100), $allocated);
    }

    /** Counters are per field and per period, so nothing shares a sequence. */
    public function testEachFieldAndPeriodCountsSeparately(): void
    {
        [$first, $second, $nextYear, $otherField] = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function (): array {
                $allocator = self::service(NumberAllocator::class);

                return [
                    $allocator->next('invoice', 'number', '2026'),
                    $allocator->next('invoice', 'number', '2026'),
                    $allocator->next('invoice', 'number', '2027'),
                    $allocator->next('invoice', 'reference', '2026'),
                ];
            },
        );

        self::assertSame([1, 2], [$first, $second]);
        self::assertSame(1, $nextYear, 'January starts again');
        self::assertSame(1, $otherField, 'and a second numbered field is its own sequence');
    }

    // -- helpers ------------------------------------------------------------

    private function anOrder(?int $customer = null): int
    {
        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) ($customer ?? $this->aCompany()),
            'ordered_on' => '2026-08-15',
            'status' => OrderModule::DRAFT,
        ]));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));
    }

    private function numberOf(int $order, bool $includeDeleted = false): string
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($order, $includeDeleted): string {
                $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
                $record = self::service(RecordRepository::class)->find($module, $order, $includeDeleted);
                self::assertInstanceOf(Record::class, $record);

                return (string) $record->get(OrderModule::NUMBER);
            },
        );
    }

    /** @return list<string> */
    private function rowsOf(string $path): array
    {
        return $this->client->request('GET', $this->url($path))
            ->filter('main table tbody tr')
            ->each(static fn (Crawler $row): string => $row->text());
    }

    private function transition(int $order, string $name): void
    {
        $tokens = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('input[name="_token"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        $this->client->request(
            'POST',
            $this->url(sprintf('/m/order/%d/transition/%s', $order, $name)),
            ['_token' => $tokens[0] ?? 'no-token'],
        );
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
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

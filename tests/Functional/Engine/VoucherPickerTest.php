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

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * Each voucher picker offers the vouchers that may actually go where it is
 * (XIV-172).
 *
 * An order carries two of them: one on the document and one on every line. A
 * voucher's kind says which of the two it belongs to, {@see
 * VoucherModule::ORDER_KINDS} against {@see VoucherModule::LINE_KINDS}, and
 * both pickers used to list all four kinds, so the first thing that ever told
 * anybody a voucher was in the wrong place was the save refusing it.
 *
 * **The refusal at the write is not what is under test here, and it has not
 * moved.** `OrderLineVoucherRedemptionTest` owns it and still does, asking the
 * writer directly, because that is the route it answers on now: an import, a
 * copy, anything that reaches the engine without drawing a picker first. What
 * changed with this ticket is only who speaks first on the one path that *has* a
 * picker. The two tests below about a crafted id are that half, and the rule
 * underneath them is asserted over there.
 *
 * **Both widget shapes, because they are different code** (XIV-167 was a defect
 * that existed on one of them and not the other). Below
 * {@see Autocomplete::AUTO_ABOVE} the picker is a plain select filled from
 * `CandidateLists`; above it there are no options in the page at all and the
 * widget types at the search endpoint, resolving whatever comes back through
 * `RecordChoiceLoader`. A narrowing applied to one of those and not the other is
 * a picker that behaves differently on a big catalogue than on a small one,
 * which is the hardest kind of report to believe.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherPickerTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_voucher_picker';
    private const string HOST = 'voucherpicker.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'voucher-picker-password';
    private const string FORM = 'module_record';

    /** The Stimulus controller ux-autocomplete puts on a field it has taken over. */
    private const string CONTROLLER = 'symfony--ux-autocomplete--autocomplete';

    /** One of the country's rates; nothing here turns on which. */
    private const string STANDARD = '8.10';

    /** Enough of one family to push that picker over the threshold on its own. */
    private const int MANY = Autocomplete::AUTO_ABOVE + 1;

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

            // Through the install order rather than the order they are written
            // here (XIV-72, XIV-104): an order installed before vouchers is an
            // order with no voucher field on its header and none on its lines.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the select, which is what a shop with a handful of vouchers sees -----

    /** The document's picker lists the two kinds that apply to a document. */
    public function testTheDocumentSelectOffersOnlyVouchersForTheDocument(): void
    {
        $this->oneOfEachKind();

        $options = self::optionsOf($this->newOrderForm(), sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertContains('ORDER-AMOUNT', $options);
        self::assertContains('ORDER-PERCENTAGE', $options);
        self::assertNotContains('LINE-AMOUNT', $options, 'a line voucher cannot be applied to the document');
        self::assertNotContains('LINE-PERCENTAGE', $options);
    }

    /** And a line's picker lists the two that apply to a line. */
    public function testTheLineSelectOffersOnlyVouchersForALine(): void
    {
        $this->oneOfEachKind();

        $options = self::optionsOf(
            $this->editFormOf($this->anOrderWithOneLine()),
            sprintf('select[name="%s"]', self::lineField(OrderModule::LINE_VOUCHER)),
        );

        self::assertContains('LINE-AMOUNT', $options);
        self::assertContains('LINE-PERCENTAGE', $options);
        self::assertNotContains('ORDER-AMOUNT', $options, 'an order voucher cannot be applied to one line');
        self::assertNotContains('ORDER-PERCENTAGE', $options);
    }

    /**
     * An id from the other family gets no purchase, even typed straight into the
     * request.
     *
     * **This is the half a filtered list is worthless without.** The options in
     * the page are a suggestion; what makes the narrowing a rule is that the same
     * narrowing decides whether a submitted id is a *choice* at all, through
     * `RecordCandidates::byId()`, so a value nobody clicked meets it too.
     *
     * **What happens to it then is the record form's own long-standing
     * behaviour, not this ticket's**: the form does not validate (`RecordType`
     * turns Symfony's validation off, because a record is checked against its
     * customer's definitions instead), so a value the choice list does not know
     * fails to transform and arrives as nothing. That is what every unofferable
     * reference id has always done here, another customer's contact or a
     * deleted record, and a voucher of the wrong kind is now one of them. The order
     * saves, carrying no voucher, which is the outcome to assert: what must not
     * happen is the wrong one being stored.
     *
     * Before this ticket the same submission was a perfectly good choice, went
     * through, and was caught four layers down by the write refusing it, which
     * is why this test fails without the narrowing rather than merely passing
     * differently.
     */
    public function testAnIdFromTheOtherFamilyNeverReachesTheRecordOnTheSelect(): void
    {
        $vouchers = $this->oneOfEachKind();

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $vouchers[VoucherModule::LINE_AMOUNT],
        ], [OrderModule::LINES => [self::row($this->aCustomLine())]]));

        self::assertNull(
            $this->recordOf($order)->get(OrderModule::VOUCHER),
            'the crafted id was not a choice, so nothing was linked',
        );
    }

    // -- the search box, which is the other code path entirely ---------------

    /**
     * Past the threshold the document picker types at the endpoint, and the
     * endpoint answers with its family only.
     *
     * Asserted through the URL the widget is actually handed rather than by
     * building one: what is under test is whether the narrowing survives the trip
     * from the field definition into the query string, and a URL assembled by the
     * test would assert nothing about that.
     */
    public function testTheDocumentSearchBoxFindsOnlyVouchersForTheDocument(): void
    {
        $this->manyOfEachKind();

        $field = $this->newOrderForm()->filter(sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertTrue(self::isAutocompleting($field), 'past the threshold it is a search box');

        $labels = $this->labelsFrom($this->endpointOf($field));

        self::assertNotSame([], $labels, 'it finds its own family');
        self::assertSame([], self::linesAmong($labels), 'and nothing that belongs on a line');
    }

    /** And the line's picker searches the other family, the same way. */
    public function testTheLineSearchBoxFindsOnlyVouchersForALine(): void
    {
        $this->manyOfEachKind();

        $field = $this->editFormOf($this->anOrderWithOneLine())
            ->filter(sprintf('select[name="%s"]', self::lineField(OrderModule::LINE_VOUCHER)));

        self::assertTrue(self::isAutocompleting($field));

        $labels = $this->labelsFrom($this->endpointOf($field));

        self::assertNotSame([], $labels);
        self::assertSame([], self::ordersAmong($labels), 'and nothing that belongs on the document');
    }

    /**
     * And the loader behind the search box gives the other family no purchase
     * either.
     *
     * The same assertion as the select's, on the path where the choices are not
     * in the page at all: `RecordChoiceLoader` resolves every submitted id
     * through `RecordCandidates::byId()`, so if that narrowed nothing the
     * crafted value would be accepted by the widget's own list and go to the
     * write. Both paths, or the guarantee is a property of how many vouchers a
     * shop happens to keep.
     */
    public function testAnIdFromTheOtherFamilyNeverReachesTheRowOnTheSearchBox(): void
    {
        $vouchers = $this->manyOfEachKind();

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => '',
        ], [OrderModule::LINES => [self::row([
            ...$this->aCustomLine(),
            OrderModule::LINE_VOUCHER => (string) $vouchers[VoucherModule::ORDER_AMOUNT],
        ])]]));

        $rows = $this->rowsOf($order);

        self::assertCount(1, $rows, 'the line was saved');
        self::assertNull($rows[0]->get(OrderModule::LINE_VOUCHER), 'and it names no voucher');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * One voucher of every kind, named after the kind so a list of labels reads
     * as an answer.
     *
     * @return array<string, int>
     */
    private function oneOfEachKind(): array
    {
        $made = [];

        foreach (self::kinds() as $kind) {
            $made[$kind] = $this->aVoucher(strtoupper(str_replace('_', '-', $kind)), $kind);
        }

        return $made;
    }

    /**
     * Enough of each kind that each picker is past the threshold **on its own
     * family**, which is the number that decides the widget once the narrowing
     * is in place.
     *
     * @return array<string, int>
     */
    private function manyOfEachKind(): array
    {
        $made = [];

        foreach (self::kinds() as $kind) {
            $prefix = strtoupper(str_replace('_', '-', $kind));

            // Numbered *first*, so the four families interleave when the
            // endpoint sorts by name. Named the other way round, one page of
            // twenty-five held nothing but the family that sorts earliest and
            // the assertion below passed against the unnarrowed endpoint: a
            // green test asserting the ordering rather than the narrowing.
            foreach (range(1, self::MANY) as $n) {
                $made[$kind] = $this->aVoucher(sprintf('%03d-%s', $n, $prefix), $kind);
            }
        }

        return $made;
    }

    /** @return list<string> */
    private static function kinds(): array
    {
        return [...VoucherModule::ORDER_KINDS, ...VoucherModule::LINE_KINDS];
    }

    private function aVoucher(string $code, string $kind): int
    {
        $worth = \in_array($kind, [VoucherModule::ORDER_AMOUNT, VoucherModule::LINE_AMOUNT], true)
            ? [VoucherModule::AMOUNT => '5.00']
            : [VoucherModule::PERCENTAGE => '10'];

        return $this->savedId($this->saveRecord(
            VoucherModule::KEY,
            [VoucherModule::CODE => $code, VoucherModule::KIND => $kind, ...$worth],
            variant: $kind,
        ));
    }

    private function anOrderWithOneLine(): int
    {
        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => '',
        ], [OrderModule::LINES => [self::row($this->aCustomLine())]]));
    }

    /** @return array<string, string> */
    private function aCustomLine(): array
    {
        return [
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ];
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => ContactModule::COMPANY, 'company_name' => 'Regal AG'],
            variant: ContactModule::COMPANY,
        ));
    }

    private function recordOf(int $order): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): Record {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);

            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
    }

    /** @return list<Record> */
    private function rowsOf(int $order): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): array {
            $lines = self::service(MetadataRepository::class)->get(OrderModule::KEY)->getCollection(OrderModule::LINES);

            self::assertNotNull($lines);

            return self::service(RecordRepository::class)->findChildren($lines, $order);
        });
    }

    private function newOrderForm(): Crawler
    {
        return $this->client->request('GET', $this->url('/m/order/new'));
    }

    private function editFormOf(int $order): Crawler
    {
        return $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
    }

    /**
     * What the endpoint behind a search box answers, asked at the address the
     * widget was given.
     *
     * @return array{results: list<array{value: int, text: string}>, next_page: ?string}
     */
    private function endpointOf(Crawler $field): array
    {
        $url = (string) $field->attr('data-' . self::CONTROLLER . '-url-value');

        self::assertNotSame('', $url, 'the widget was told where to look');

        $this->client->request('GET', $this->url($url));

        self::assertResponseIsSuccessful();

        /** @var array{results: list<array{value: int, text: string}>, next_page: ?string} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * @param array{results: list<array{value: int, text: string}>, next_page: ?string} $answer
     *
     * @return list<string>
     */
    private function labelsFrom(array $answer): array
    {
        return array_map(static fn (array $result): string => $result['text'], $answer['results']);
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private static function linesAmong(array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => str_contains($label, 'LINE-')));
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private static function ordersAmong(array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => str_contains($label, 'ORDER-')));
    }

    private static function isAutocompleting(Crawler $field): bool
    {
        return str_contains((string) $field->attr('data-controller'), self::CONTROLLER);
    }

    /** @return list<string> */
    private static function optionsOf(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector . ' option')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private static function lineField(string $key, int $index = 0): string
    {
        return sprintf('%s[collections][%s][%d][fields][%s]', self::FORM, OrderModule::LINES, $index, $key);
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
        $this->client->followRedirect();
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
        /** @var T $service */
        $service = self::getContainer()->get($id);

        return $service;
    }
}

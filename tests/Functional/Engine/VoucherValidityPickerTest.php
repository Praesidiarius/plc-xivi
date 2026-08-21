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
use Xivi\Core\Record\RecordWriter;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * A voucher picker offers what can be used today, and keeps what a document
 * already holds (XIV-175).
 *
 * The second half of {@see VoucherPickerTest}'s complaint, and the same one:
 * **a list should only offer what can actually be chosen.** XIV-172 narrowed
 * both of an order's pickers to their own family and left the calendar alone,
 * so a voucher that expired last month was still in the list and still refused
 * by the save, which is the first thing that ever said so.
 *
 * **Its own class rather than eight more tests over there**, for the reason
 * `OrderLineVoucherRedemptionTest` gives about the class it was split from: a
 * test here is a full save cycle, the fixture is deliberately large enough to
 * push a picker past {@see Autocomplete::AUTO_ABOVE}, and one class holding
 * both halves runs out of memory in the middle of the second.
 *
 * ### What is asserted, and why each of the three parts is needed
 *
 * - **Nothing outside its dates is offered**, on both widgets. Five states
 *   rather than two: a voucher with no dates at all is the ordinary one and the
 *   most common way a shop creates one, and a rule reading an empty column as a
 *   passed date would empty the picker rather than narrow it.
 * - **An id typed into the request is not a choice either.**
 *   `RecordCandidates::byId()` narrows on the rule the list narrowed on, so a
 *   value nobody clicked meets it too. Without that the filtered list would be
 *   a suggestion rather than a rule.
 * - **What a document already carries is kept.** A voucher on an order that
 *   expires the following week is a state somebody reaches by doing nothing,
 *   and the engine's answer is written down in §5.9 and in `RedeemsVouchers`
 *   (XIV-110): a use is taken when the document first names the voucher and is
 *   never re-checked. A picker that stopped offering the stored value would
 *   undo that from the outside, releasing the use and taking the discount off a
 *   document the shop had already agreed to, with nobody told. So what the
 *   calendar narrows is what may be **newly chosen**.
 *
 * **The save-time refusals are untouched by all of it.**
 * `OrderVoucherRedemptionTest` owns them and still does, asking the writer
 * directly, which is the route an import, a copy and anything else without a
 * picker in front of it takes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherValidityPickerTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_voucher_validity';
    private const string HOST = 'vouchervalidity.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'voucher-validity-password';
    private const string FORM = 'module_record';

    /** The Stimulus controller ux-autocomplete puts on a field it has taken over. */
    private const string CONTROLLER = 'symfony--ux-autocomplete--autocomplete';

    /** One of the country's rates; nothing here turns on which. */
    private const string STANDARD = '8.10';

    /**
     * The five states the calendar can put a voucher in.
     *
     * Spelled into the codes, so a page of labels says which state each of them
     * is in and a failure reads as a sentence rather than as a list of ids.
     */
    private const string OPEN = 'OPEN';
    private const string WINDOW = 'WINDOW';
    private const string LAST_DAY = 'LAST-DAY';
    private const string EXPIRED = 'EXPIRED';
    private const string EARLY = 'EARLY';

    /**
     * Usable vouchers per kind, so a family holds twice this many.
     *
     * The number that decides the widget is the **narrowed** count, so this is
     * what has to clear {@see Autocomplete::AUTO_ABOVE} once the fix is in: two
     * kinds make a family, and twelve of each puts twenty-four in front of a
     * threshold of twenty.
     */
    private const int USABLE_EACH = 12;

    /**
     * And unusable ones, per kind and per unusable state.
     *
     * What makes the search-box tests able to fail. Numbered first so the states
     * interleave when the endpoint sorts by name, four of each fills most of a
     * page of twenty-five with vouchers an unnarrowed endpoint answers with.
     * XIV-172 found the alternative the hard way: named the other way round, one
     * page held nothing but the state that sorts earliest and the assertion
     * passed against an endpoint that narrowed nothing.
     */
    private const int UNUSABLE_EACH = 4;

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

    /**
     * The document's picker leaves out whatever today is outside the dates of.
     *
     * The three that stay carry as much of the rule as the two that go. The one
     * whose last day is today is the boundary `VoucherValidity` draws with a
     * strict comparison, good until today being good today, and the query has to
     * draw it in the same place or the list and the save disagree for
     * twenty-four hours.
     */
    public function testTheDocumentSelectOffersOnlyVouchersThatAreUsableToday(): void
    {
        $this->oneOfEachKindAndState();

        $options = self::optionsOf($this->newOrderForm(), sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertContains('ORDER-AMOUNT-OPEN', $options, 'no dates at all means no boundary in either direction');
        self::assertContains('ORDER-AMOUNT-WINDOW', $options, 'inside both of its dates');
        self::assertContains('ORDER-AMOUNT-LAST-DAY', $options, 'good until today is good today');
        self::assertNotContains('ORDER-AMOUNT-EXPIRED', $options, 'its last day is behind us');
        self::assertNotContains('ORDER-AMOUNT-EARLY', $options, 'its first day is still ahead of us');
    }

    /** And a line's picker narrows the same way, over the other family. */
    public function testTheLineSelectOffersOnlyVouchersThatAreUsableToday(): void
    {
        $this->oneOfEachKindAndState();

        $options = self::optionsOf(
            $this->editFormOf($this->anOrderWithOneLine()),
            sprintf('select[name="%s"]', self::lineField(OrderModule::LINE_VOUCHER)),
        );

        self::assertContains('LINE-AMOUNT-OPEN', $options);
        self::assertContains('LINE-AMOUNT-WINDOW', $options);
        self::assertContains('LINE-AMOUNT-LAST-DAY', $options);
        self::assertNotContains('LINE-AMOUNT-EXPIRED', $options);
        self::assertNotContains('LINE-AMOUNT-EARLY', $options);
        // And still nothing but its own family, which is what composing with
        // XIV-172 means: one list, narrowed twice, the two ANDed.
        self::assertSame([], self::ordersAmong($options), 'an order voucher still cannot be applied to one line');
    }

    /**
     * An expired id gets no purchase on the select either, typed straight into
     * the request.
     *
     * **What happens to it is the record form's long-standing behaviour rather
     * than this ticket's** (XIV-172 says the same about the other family): the
     * form does not validate, because a record is checked against its customer's
     * definitions instead, so a value the choice list does not know fails to
     * transform and arrives as nothing. The order saves carrying no voucher, and
     * what must not happen is the expired one being stored.
     *
     * **Before this ticket the same submission was a perfectly good choice**,
     * went through, and was refused four layers down by `RedeemsVouchers` with
     * its `expired` sentence, which took the whole save with it. So this does
     * not merely pass differently without the narrowing: the order is not saved
     * at all.
     */
    public function testAnExpiredIdNeverReachesTheRecordOnTheSelect(): void
    {
        $vouchers = $this->oneOfEachKindAndState();

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $vouchers[VoucherModule::ORDER_AMOUNT][self::EXPIRED],
        ], [OrderModule::LINES => [self::row($this->aCustomLine())]]));

        self::assertNull(
            $this->recordOf($order)->get(OrderModule::VOUCHER),
            'the expired id was not a choice, so nothing was linked',
        );
    }

    // -- the search box, which is the other code path entirely ---------------

    /**
     * Past the threshold the document's picker types at the endpoint, and the
     * endpoint answers with its own family, in date.
     *
     * Asked at the URL the widget is actually handed rather than at one the test
     * assembles: what is under test is whether the narrowing survives the trip
     * out of the field definition, and a URL built here would assert nothing
     * about that. The validity half never appears in it at all, which is a
     * property worth naming: it is decided where the query is built, so there is
     * no parameter for anybody to leave out.
     */
    public function testTheDocumentSearchBoxFindsOnlyVouchersThatAreUsableToday(): void
    {
        $this->manyOfEachKindAndState();

        $field = $this->newOrderForm()->filter(sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertTrue(self::isAutocompleting($field), 'past the threshold it is a search box');

        $labels = $this->labelsFrom($this->endpointOf($field));

        self::assertNotSame([], $labels, 'it finds the vouchers that can be used');
        self::assertSame([], self::unusableAmong($labels), 'and none that today is outside the dates of');
        self::assertSame([], self::linesAmong($labels), 'and nothing that belongs on a line');
    }

    /** And the line's search box, which is the same code over the other half. */
    public function testTheLineSearchBoxFindsOnlyVouchersThatAreUsableToday(): void
    {
        $this->manyOfEachKindAndState();

        $field = $this->editFormOf($this->anOrderWithOneLine())
            ->filter(sprintf('select[name="%s"]', self::lineField(OrderModule::LINE_VOUCHER)));

        self::assertTrue(self::isAutocompleting($field));

        $labels = $this->labelsFrom($this->endpointOf($field));

        self::assertNotSame([], $labels);
        self::assertSame([], self::unusableAmong($labels));
        self::assertSame([], self::ordersAmong($labels), 'and nothing that belongs on the document');
    }

    /**
     * And the loader behind the search box gives an expired id no purchase
     * either.
     *
     * Both paths, or the guarantee is a property of how many vouchers a shop
     * happens to keep. `RecordChoiceLoader` resolves every submitted id through
     * `RecordCandidates::byId()`, so a narrowing that reached only the endpoint
     * would leave the crafted value acceptable to the widget's own list.
     */
    public function testAnExpiredIdNeverReachesTheRowOnTheSearchBox(): void
    {
        $vouchers = $this->manyOfEachKindAndState();

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => '',
        ], [OrderModule::LINES => [self::row([
            ...$this->aCustomLine(),
            OrderModule::LINE_VOUCHER => (string) $vouchers[VoucherModule::LINE_AMOUNT][self::EXPIRED],
        ])]]));

        $rows = $this->rowsOf($order);

        self::assertCount(1, $rows, 'the line was saved');
        self::assertNull($rows[0]->get(OrderModule::LINE_VOUCHER), 'and it names no voucher');
    }

    // -- what a document already carries, which the narrowing may not take ----

    /**
     * An order goes on showing, and keeping, the voucher it was agreed with
     * after that voucher expires.
     *
     * **The case the narrowing must not swallow**, and it is reached by doing
     * nothing: a promotion ends, and the order that used it is opened again the
     * following week. Re-saving takes no use, gives none back and refuses
     * nothing, because a use is taken once (XIV-110).
     *
     * **What is asserted first is what the customer sees**, and that is the half
     * with teeth. A picker that stopped offering the stored voucher does not
     * necessarily lose it: the value fails to transform and Symfony leaves the
     * field's model data as it was, so the record quietly keeps a voucher the
     * page has stopped showing. That is worse than either honest outcome. The
     * page would say the order has no voucher, the order would have one, the
     * discount line would still be on it, and clearing the field would do
     * nothing, because the field already looks clear. So the option has to be
     * *there*, selected, and the second assertion is the one about the value
     * surviving a save.
     *
     * And it is offered on that order's form only: a new order is not offered
     * an expired voucher, which is the whole of the narrowing.
     */
    public function testAnOrderKeepsAVoucherThatExpiredAfterItWasChosenOnTheSelect(): void
    {
        $vouchers = $this->oneOfEachKindAndState();
        $voucher = $vouchers[VoucherModule::ORDER_AMOUNT][self::OPEN];
        $code = self::prefixOf(VoucherModule::ORDER_AMOUNT) . '-' . self::OPEN;

        $order = $this->anOrderNaming($voucher);

        $this->expire($voucher);

        self::assertContains($code, $this->documentPickerOn($this->editFormOf($order)), 'the order shows what it carries');
        self::assertNotContains($code, $this->documentPickerOn($this->newOrderForm()), 'and a new order is not offered it');

        $this->resave($order, $voucher);

        self::assertSame(
            $voucher,
            $this->recordOf($order)->get(OrderModule::VOUCHER),
            'the voucher the order was agreed with is still on it',
        );
    }

    /**
     * And the same on the widget that has no options in the page at all.
     *
     * Where a search box renders anything, it is exactly this: what the record
     * is already pointing at. So the assertion reads the same and means
     * something slightly different, which is why it is worth making twice.
     */
    public function testAnOrderKeepsAVoucherThatExpiredAfterItWasChosenOnTheSearchBox(): void
    {
        $vouchers = $this->manyOfEachKindAndState();
        $voucher = $vouchers[VoucherModule::ORDER_AMOUNT][self::OPEN];
        $code = self::numbered(1, VoucherModule::ORDER_AMOUNT, self::OPEN);

        $order = $this->anOrderNaming($voucher);

        $this->expire($voucher);

        self::assertContains($code, $this->documentPickerOn($this->editFormOf($order)));
        self::assertNotContains($code, $this->documentPickerOn($this->newOrderForm()));

        $this->resave($order, $voucher);

        self::assertSame($voucher, $this->recordOf($order)->get(OrderModule::VOUCHER));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * One voucher of every kind in every state, in one write.
     *
     * @return array<string, array<string, int>> kind, then state, then its id
     */
    private function oneOfEachKindAndState(): array
    {
        return $this->vouchers(static function (\Closure $add): array {
            $made = [];

            foreach (self::kinds() as $kind) {
                foreach (self::states() as $state) {
                    $made[$kind][$state] = $add(self::prefixOf($kind) . '-' . $state, $kind, $state);
                }
            }

            return $made;
        });
    }

    /**
     * Enough of each kind that each picker is past the threshold on the
     * vouchers it can actually offer, with unusable ones between them.
     *
     * @return array<string, array<string, int>> kind, then state, then the id of
     *                                           the first of them
     */
    private function manyOfEachKindAndState(): array
    {
        return $this->vouchers(static function (\Closure $add): array {
            $made = [];

            foreach (self::kinds() as $kind) {
                foreach (range(1, self::USABLE_EACH) as $n) {
                    $id = $add(self::numbered($n, $kind, self::OPEN), $kind, self::OPEN);
                    $made[$kind][self::OPEN] ??= $id;
                }

                foreach ([self::EXPIRED, self::EARLY] as $state) {
                    foreach (range(1, self::UNUSABLE_EACH) as $n) {
                        $id = $add(self::numbered($n, $kind, $state), $kind, $state);
                        $made[$kind][$state] ??= $id;
                    }
                }
            }

            return $made;
        });
    }

    /**
     * A fixture of vouchers, written through the engine rather than through the
     * form.
     *
     * **Through {@see RecordWriter} on purpose**, and it is a decision about
     * memory rather than about coverage. The picker is what these tests are
     * about, and a picker is exercised by the two or three form requests each
     * test makes; the eighty vouchers behind it are scenery. Built through the
     * record form instead, one live-component round trip each, this class
     * exhausted its memory limit part way through, which is the same wall
     * `OrderLineVoucherRedemptionTest` describes. The writer is still the engine
     * (§5.2): derivers run, the code is assigned, nothing is written by hand
     * that the engine owns.
     *
     * @param \Closure(\Closure(string, string, string): int): array<string, array<string, int>> $build
     *
     * @return array<string, array<string, int>>
     */
    private function vouchers(\Closure $build): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($build): array {
            $module = self::service(MetadataRepository::class)->get(VoucherModule::KEY);
            $writer = self::service(RecordWriter::class);

            return $build(static function (string $code, string $kind, string $state) use ($module, $writer): int {
                [$from, $until] = self::datesFor($state);

                return (int) $writer->save($module, new Record(data: [
                    VoucherModule::CODE => $code,
                    VoucherModule::KIND => $kind,
                    ...self::worthOf($kind),
                    VoucherModule::VALID_FROM => $from,
                    VoucherModule::VALID_UNTIL => $until,
                ]))->id;
            });
        });
    }

    /**
     * The same voucher, with its last valid day moved into the past.
     *
     * How a voucher goes out of date without anybody touching the order that
     * names it: the shop shortens the promotion, or the calendar catches up with
     * what the voucher already said. Either way what changes is the voucher.
     */
    private function expire(int $id): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): void {
            $module = self::service(MetadataRepository::class)->get(VoucherModule::KEY);
            $voucher = self::service(RecordRepository::class)->find($module, $id);

            self::assertInstanceOf(Record::class, $voucher);

            $voucher->data[VoucherModule::VALID_UNTIL] = self::day(-1);

            self::service(RecordWriter::class)->save($module, $voucher);
        });
    }

    /** @return list<string> */
    private static function kinds(): array
    {
        return [...VoucherModule::ORDER_KINDS, ...VoucherModule::LINE_KINDS];
    }

    /** @return list<string> */
    private static function states(): array
    {
        return [self::OPEN, self::WINDOW, self::LAST_DAY, self::EXPIRED, self::EARLY];
    }

    /**
     * The two dates that put a voucher in one of the five states.
     *
     * Relative to the day this runs rather than written out, because a fixture
     * with a fixed date in it is a test that expires. Which is the subject
     * itself: `2026-08-19` is usable while somebody writes the test and past by
     * the time anybody reads it.
     *
     * @return array{0: ?string, 1: ?string} valid from, valid until
     */
    private static function datesFor(string $state): array
    {
        return match ($state) {
            self::WINDOW => [self::day(-1), self::day(1)],
            self::LAST_DAY => [null, self::day(0)],
            self::EXPIRED => [null, self::day(-1)],
            self::EARLY => [self::day(1), null],
            // OPEN: no boundary in either direction, which is the ordinary
            // voucher and the only kind most shops ever create.
            default => [null, null],
        };
    }

    /** A day either side of today, in the format a date field stores. */
    private static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify(sprintf('%+d days', $offset))
            ->format('Y-m-d');
    }

    private static function numbered(int $n, string $kind, string $state): string
    {
        return sprintf('%03d-%s-%s', $n, self::prefixOf($kind), $state);
    }

    private static function prefixOf(string $kind): string
    {
        return strtoupper(str_replace('_', '-', $kind));
    }

    /** @return array<string, string> */
    private static function worthOf(string $kind): array
    {
        return \in_array($kind, [VoucherModule::ORDER_AMOUNT, VoucherModule::LINE_AMOUNT], true)
            ? [VoucherModule::AMOUNT => '5.00']
            : [VoucherModule::PERCENTAGE => '10'];
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

    /** An order carrying one voucher on its document, and a line to discount. */
    private function anOrderNaming(int $voucher): int
    {
        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [self::row($this->aCustomLine())]]));
    }

    /**
     * The same order saved again, naming the voucher it already names.
     *
     * **No rows submitted**, which is a save that touched only the header and
     * leaves the lines carrying what they carried, the shape
     * {@see \Xivi\Voucher\Redemption\RedeemsVouchers} describes at length. It is
     * also the shortest way to ask the one question this is about: the form is
     * built from the stored record, so the picker meets the voucher the order
     * holds exactly as it would for somebody who opened the page.
     */
    private function resave(int $order, int $voucher): void
    {
        $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->recordOf($order)->get('contact'),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], recordId: $order));
    }

    /**
     * The options the document's voucher picker draws on a given form.
     *
     * On a select those are the page it renders; on a search box they are
     * whatever the widget has been told the record already points at, which is
     * the only thing in the page before somebody types. The same question either
     * way: what does this form show as chosen or choosable?
     *
     * @return list<string>
     */
    private function documentPickerOn(Crawler $form): array
    {
        return self::optionsOf($form, sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));
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
     * The ones today is outside the dates of, which are what must not be there.
     *
     * Both directions in one helper, because a picker that dropped the expired
     * and kept the ones starting next month would be half a fix, and it is the
     * half nobody notices until December.
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private static function unusableAmong(array $labels): array
    {
        return array_values(array_filter(
            $labels,
            static fn (string $label): bool => str_contains($label, '-' . self::EXPIRED)
                || str_contains($label, '-' . self::EARLY),
        ));
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

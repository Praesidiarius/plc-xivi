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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleAddition;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Module\ModuleUpgrade;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRefused;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * A module's own field options reach a tenant that installed the module before
 * they existed ([XIV-176]).
 *
 * XIV-172 narrowed the order's two voucher pickers by adding `variant` to the
 * blueprint. §6.1 does not retro-fit a blueprint and §7.2.1 never offers a key
 * the shape already has, both correctly, so on every tenant that already had the
 * order module the stored definitions kept saying
 * `{"module":"voucher","samples":[null]}` and the narrowing could not arrive at
 * all. It is read live from the blueprint now, and nothing is written.
 *
 * **The gate stands in for a tenant provisioned before that commit.** The real
 * reproduction is a two-commit dance (check out `2d55a96`, provision, come
 * back, migrate) which cannot live in CI, so this installs through the ordinary
 * installer and then rewrites the two definitions' options to the exact JSON
 * that installer used to write. That is the same row, arrived at from the other
 * direction, and it is the row the whole ticket is about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleOwnedOptionsTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_module_owned_options';
    private const string HOST = 'moduleownedoptions.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'module-owned-options-password';
    private const string FORM = 'module_record';

    /** One of the country's rates; nothing here turns on which. */
    private const string STANDARD = '8.10';

    /**
     * Exactly what `ModuleInstaller` wrote for both voucher fields before
     * XIV-172, character for character.
     */
    private const array BEFORE_XIV_172 = ['module' => 'voucher', 'samples' => [null]];

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
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the gate ------------------------------------------------------------

    /** The document's picker narrows, on a definition that says nothing about kinds. */
    public function testTheDocumentPickerNarrowsOnADefinitionInstalledBeforeTheNarrowing(): void
    {
        $this->asATenantFromBeforeXiv172();
        $this->oneOfEachKind();

        $options = self::optionsOf($this->newOrderForm(), sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertContains('ORDER-AMOUNT', $options);
        self::assertContains('ORDER-PERCENTAGE', $options);
        self::assertNotContains('LINE-AMOUNT', $options, 'the narrowing arrived without the row changing');
        self::assertNotContains('LINE-PERCENTAGE', $options);
    }

    /** And so does the one on a line, which is a collection's field rather than a module's. */
    public function testTheLinePickerNarrowsOnADefinitionInstalledBeforeTheNarrowing(): void
    {
        $this->asATenantFromBeforeXiv172();
        $this->oneOfEachKind();

        $options = self::optionsOf(
            $this->editFormOf($this->anOrderWithOneLine()),
            sprintf('select[name="%s"]', self::lineField(OrderModule::LINE_VOUCHER)),
        );

        self::assertContains('LINE-AMOUNT', $options);
        self::assertContains('LINE-PERCENTAGE', $options);
        self::assertNotContains('ORDER-AMOUNT', $options, 'a collection field is resolved in its collection');
        self::assertNotContains('ORDER-PERCENTAGE', $options);
    }

    /**
     * And the stored row is untouched, which is the whole point rather than a
     * side note.
     *
     * Nothing here writes: no screen, no command, no migration. If this goes red
     * the mechanism has become the retro-fit §6.1 refuses, whatever the two
     * assertions above say.
     */
    public function testNothingIsWrittenBack(): void
    {
        $this->asATenantFromBeforeXiv172();
        $this->oneOfEachKind();

        $this->newOrderForm();
        $this->editFormOf($this->anOrderWithOneLine());

        self::assertSame(self::BEFORE_XIV_172, $this->storedOptionsOfTheDocumentVoucher());
    }

    /**
     * §7.2.1's boundary is untouched: the upgrade still offers nothing for a key
     * the shape already has.
     *
     * The two mechanisms answer different questions and must not start
     * overlapping. An upgrade that began offering the voucher field back would
     * mean this ticket had turned a live read into an addition, which is the
     * thing §7.2.2 exists to say it is not.
     */
    public function testTheUpgradeStillOffersNothingForThoseFields(): void
    {
        $this->asATenantFromBeforeXiv172();

        $offered = self::service(TenantSwitcher::class)->runFor($this->tenant, static function (): array {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            return array_map(
                static fn (ModuleAddition $addition): string => $addition->key,
                self::service(ModuleUpgrade::class)->available($module),
            );
        });

        self::assertNotContains(OrderModule::VOUCHER, $offered, 'a key the shape has is never offered');
        self::assertNotContains(OrderModule::LINES, $offered, 'and neither is the collection holding the other one');
    }

    /**
     * The customer's own decisions survive, which is §7.2.1's promise and this
     * ticket's fourth acceptance criterion.
     *
     * Label, width and `required` are three things a customer sets in the editor
     * and a module never touches again. A mechanism that resolved options by
     * copying the blueprint over the row would take all three away, and the
     * picker assertions above would still be green.
     */
    public function testTheCustomersOwnLabelWidthAndRequiredAllSurvive(): void
    {
        $this->asATenantFromBeforeXiv172();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $field = $this->documentVoucherField();
            $field->setLabel('Gutschein');
            $field->setWidth(4);
            $field->setRequired(true);

            $this->flushDefinitions();
        });

        $this->oneOfEachKind();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $field = $this->documentVoucherField();

            self::assertSame('Gutschein', $field->getLabel(), 'the relabelling survives');
            self::assertSame(4, $field->getWidth(), 'and the width');
            self::assertTrue($field->isRequired(), 'and the requirement');
        });

        $options = self::optionsOf($this->newOrderForm(), sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertNotContains('LINE-AMOUNT', $options, 'and the narrowing still arrives');
    }

    // -- the guard -----------------------------------------------------------

    /**
     * A narrowing the tenant's own shapes cannot express is dropped, and the
     * picker is left exactly as it is today.
     *
     * **Without the guard this picker lists nothing at all.**
     * `QueryCompiler::variantGroup()` compiles to the SQL string `FALSE` when the
     * target shape has no variant field, and `RecordCandidates::isOneOf()` says
     * the same thing about a record in hand, so live-reading a narrowing into a
     * tenant whose voucher module has no `kind` would turn a working picker into
     * an empty one across the whole instance. That is the failure, not a
     * hypothetical: XIV-133's article narrowing is inert for exactly this reason,
     * and the pre-XIV-122 voucher shape named three kinds none of which the
     * blueprint mentions.
     *
     * The variant field is taken off the shape rather than the kinds being
     * renamed, because it is the shorter way to the same state and the one
     * `variantGroup()` names.
     */
    public function testANarrowingTheTenantCannotExpressLeavesThePickerAlone(): void
    {
        $this->oneOfEachKind();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(MetadataRepository::class)->get(VoucherModule::KEY)->setVariantField(null);

            $this->flushDefinitions();
        });

        $options = self::optionsOf($this->newOrderForm(), sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)));

        self::assertContains('ORDER-AMOUNT', $options, 'the picker still lists its records');
        self::assertContains('LINE-AMOUNT', $options, 'all of them, unnarrowed, exactly as before the narrowing existed');
        self::assertContains('LINE-PERCENTAGE', $options);
    }

    // -- what a record already holds -----------------------------------------

    /**
     * A voucher the document already names is still shown, even though the
     * narrowing that arrived afterwards would not offer it.
     *
     * The value is written straight into the payload because the shape that used
     * to produce such rows is gone: before XIV-122 the voucher module's kinds
     * were `absolute`, `relative` and `free_article`, and there is no way left to
     * save one of those onto an order through the ordinary path. What the test is
     * about is the reading, and what is stored is what a tenant of that vintage
     * has.
     */
    public function testAValueTheRecordHoldsSurvivesANarrowingThatArrivedLater(): void
    {
        $this->asATenantFromBeforeXiv172();
        $vouchers = $this->oneOfEachKind();
        $order = $this->anOrderWithOneLine();

        $this->putIntoStorage($order, OrderModule::VOUCHER, $vouchers[VoucherModule::LINE_AMOUNT]);

        $options = self::optionsOf(
            $this->editFormOf($order),
            sprintf('select[name="%s"]', self::field(OrderModule::VOUCHER)),
        );

        self::assertContains('LINE-AMOUNT', $options, 'the document keeps the voucher it was agreed with');
        self::assertNotContains(
            'LINE-PERCENTAGE',
            $options,
            'and the narrowing still holds for everything nobody has already chosen',
        );
    }

    /**
     * And a save that changes nothing keeps it, rather than refusing.
     *
     * **This is where the decision's stated cost turned out not to arise, and
     * the reason is the rule `held()` already cites.** The decision expected the
     * write to refuse an agreed document holding a voucher of the wrong family,
     * so that it could not be saved until somebody cleared the field.
     * `RedeemsVouchers` does not work that way: it acts on the *difference*
     * between what a document carried before and what it carries now, because a
     * use is taken once and re-saving re-checks nothing (§5.9, XIV-110). A
     * document that already names the voucher is naming it before and after, so
     * there is nothing to take and nothing to refuse.
     *
     * So the outcome is better than the one that was accepted: the picker shows
     * the value, the save goes through, and the voucher stays on the document
     * somebody agreed to. What refuses is the document *taking* the voucher
     * afresh, which is the assertion below.
     */
    public function testAndASaveThatChangesNothingKeepsIt(): void
    {
        $this->asATenantFromBeforeXiv172();
        $vouchers = $this->oneOfEachKind();
        $order = $this->anOrderWithOneLine();

        $this->putIntoStorage($order, OrderModule::VOUCHER, $vouchers[VoucherModule::LINE_AMOUNT]);

        $this->savedId($this->resaveThroughTheForm($order));

        self::assertSame(
            $vouchers[VoucherModule::LINE_AMOUNT],
            $this->storedVoucherOn($order),
            'the document keeps the discount it was agreed with',
        );
    }

    /**
     * What does refuse is a document taking that voucher afresh, and it names
     * the field.
     *
     * XIV-122's rule, unchanged by any of this and deliberately not softened: a
     * picker is a convenience in front of a guarantee, and an import, a copy or
     * anything else that never drew one meets the same sentence. The narrowing
     * decides who is *offered* the voucher; this decides whether a document may
     * take it.
     */
    public function testAndTheWriteStillRefusesADocumentTakingItAfresh(): void
    {
        $this->asATenantFromBeforeXiv172();
        $vouchers = $this->oneOfEachKind();
        $order = $this->anOrderWithOneLine();

        $refusal = $this->refusalOfTaking($order, $vouchers[VoucherModule::LINE_AMOUNT]);

        self::assertStringContainsString('applies to a single line and was put on the document', $refusal->getMessage());
        self::assertSame(OrderModule::VOUCHER, $refusal->fieldKey, 'and it says which field to clear');
    }

    // -- the count -----------------------------------------------------------

    /**
     * And the number is takeable: how many records hold a link the picker no
     * longer offers.
     *
     * Counted and named, never acted on, which is the discipline the two counts
     * beside it already have. `tenant:inspect` is what prints it.
     */
    public function testTheRecordsOutsideTheNarrowingAreCounted(): void
    {
        $this->asATenantFromBeforeXiv172();
        $vouchers = $this->oneOfEachKind();
        $order = $this->anOrderWithOneLine();

        self::assertSame(0, $this->countOutside(), 'nothing points outside it yet');

        $this->putIntoStorage($order, OrderModule::VOUCHER, $vouchers[VoucherModule::LINE_AMOUNT]);

        self::assertSame(1, $this->countOutside(), 'one document holds a voucher of the other family');

        $this->putIntoStorage($order, OrderModule::VOUCHER, $vouchers[VoucherModule::ORDER_AMOUNT]);

        self::assertSame(0, $this->countOutside(), 'and a link inside the narrowing is not one of them');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Both voucher definitions put back to the exact options the installer wrote
     * before XIV-172.
     */
    private function asATenantFromBeforeXiv172(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $this->documentVoucherField()->setOptions(self::BEFORE_XIV_172);
            $this->lineVoucherField()->setOptions(self::BEFORE_XIV_172);

            $this->flushDefinitions();
        });
    }

    /**
     * A value written past every picker, the way a tenant of an older vintage
     * holds one.
     */
    private function putIntoStorage(int $order, string $key, int $value): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($order, $key, $value): void {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $field = $module->getField($key);

            self::assertNotNull($field);

            self::service(RecordRepository::class)->writeStoredValue($module, $field, $order, $value);
        });
    }

    private function countOutside(): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): int => self::service(MetadataEditor::class)
            ->recordsPointingOutside($this->documentVoucherField()));
    }

    private function storedVoucherOn(int $order): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($order): mixed {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);

            self::assertInstanceOf(Record::class, $record);

            return $record->get(OrderModule::VOUCHER);
        });
    }

    private function resaveThroughTheForm(int $order): \Symfony\Component\HttpFoundation\Response
    {
        return $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->contactOn($order),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $this->storedVoucherOn($order),
        ], [OrderModule::LINES => array_map(
            static fn (Record $row): array => self::row([
                OrderModule::KIND => (string) $row->get(OrderModule::KIND),
                'description' => (string) $row->get('description'),
                OrderModule::QUANTITY => (string) $row->get(OrderModule::QUANTITY),
                OrderModule::UNIT_PRICE => (string) $row->get(OrderModule::UNIT_PRICE),
                OrderModule::TAX_RATE => (string) $row->get(OrderModule::TAX_RATE),
            ], (int) $row->id),
            $this->rowsOf($order),
        )], $order);
    }

    private function contactOn(int $order): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($order): int {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);

            self::assertInstanceOf(Record::class, $record);

            return (int) $record->get('contact');
        });
    }

    /** @return list<Record> */
    private function rowsOf(int $order): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($order): array {
            $lines = self::service(MetadataRepository::class)->get(OrderModule::KEY)->getCollection(OrderModule::LINES);

            self::assertNotNull($lines);

            return self::service(RecordRepository::class)->findChildren($lines, $order);
        });
    }

    /**
     * What the engine says when this document is made to take that voucher.
     *
     * Written through {@see RecordWriter} rather than through the form, because
     * the form is exactly what will not offer it: the point of the refusal is
     * that it holds on the route with no picker in front of it.
     */
    private function refusalOfTaking(int $order, int $voucher): RecordRefused
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($order, $voucher): RecordRefused {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);

            self::assertInstanceOf(Record::class, $record);

            try {
                self::service(RecordWriter::class)->save(
                    $module,
                    new Record(id: $record->id, data: [...$record->data, OrderModule::VOUCHER => $voucher]),
                );
            } catch (RecordRefused $refused) {
                return $refused;
            }

            self::fail('the write was expected to be refused');
        });
    }

    private function documentVoucherField(): FieldDefinition
    {
        return self::fieldOf(self::service(MetadataRepository::class)->get(OrderModule::KEY), OrderModule::VOUCHER);
    }

    private function lineVoucherField(): FieldDefinition
    {
        $lines = self::service(MetadataRepository::class)->get(OrderModule::KEY)->getCollection(OrderModule::LINES);

        self::assertNotNull($lines);

        return self::fieldOf($lines, OrderModule::LINE_VOUCHER);
    }

    private static function fieldOf(ShapeDefinition $shape, string $key): FieldDefinition
    {
        $field = $shape->getField($key);

        self::assertNotNull($field, sprintf('%s has a field %s', $shape->getKey(), $key));

        return $field;
    }

    /** @return array<string, mixed> */
    private function storedOptionsOfTheDocumentVoucher(): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): array {
            // Off the database rather than off the definition the request left in
            // memory, since "nothing was written" is a claim about the row.
            self::service(MetadataRepository::class);

            /** @var array<string, mixed> $options */
            $options = json_decode((string) self::manager()->getConnection()->fetchOne(
                'SELECT f.options FROM field_definition f
                 JOIN shape_definition s ON s.id = f.shape_id
                 WHERE s.shape_key = :shape AND f.field_key = :field',
                ['shape' => OrderModule::KEY, 'field' => OrderModule::VOUCHER],
            ), true, 512, \JSON_THROW_ON_ERROR);

            return $options;
        });
    }

    private function flushDefinitions(): void
    {
        $manager = self::manager();
        $manager->flush();
    }

    private static function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine.orm.tenant_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /**
     * One voucher of every kind, named after the kind so a list of labels reads
     * as an answer.
     *
     * @return array<string, int>
     */
    private function oneOfEachKind(): array
    {
        $made = [];

        foreach ([...VoucherModule::ORDER_KINDS, ...VoucherModule::LINE_KINDS] as $kind) {
            $made[$kind] = $this->aVoucher(strtoupper(str_replace('_', '-', $kind)), $kind);
        }

        return $made;
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
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ])]]));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => ContactModule::COMPANY, 'company_name' => 'Regal AG'],
            variant: ContactModule::COMPANY,
        ));
    }

    private function newOrderForm(): Crawler
    {
        return $this->client->request('GET', $this->url('/m/order/new'));
    }

    private function editFormOf(int $order): Crawler
    {
        return $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
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

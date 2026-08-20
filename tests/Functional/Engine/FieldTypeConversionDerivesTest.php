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
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Metadata\FieldTypeConversion;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\DerivedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Order\OrderModule;

/**
 * What a conversion does to the values that follow from the one it changed
 * ([XIV-146], §5.9).
 *
 * §7.2 gives the rule as an "or": a field something derives from re-derives, or
 * the change is refused while the derivation exists. This is the first half
 * being taken, and it needs a module that actually derives, which is why this
 * class installs three of them rather than living beside the other conversion
 * tests on a tenant that only has contacts.
 *
 * **A deriver never says which fields it read** (§5.9 hands it the whole record
 * and asks for nothing back), so the engine cannot narrow this to the fields a
 * total is made of and does not try: every record a conversion touched, on a
 * module that derives anything at all, goes back through an ordinary save. What
 * that is worth is only visible if a derived value is *wrong* when the
 * conversion starts, which is what this test arranges by hand.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldTypeConversionDerivesTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_convert_derive';

    /** A customer's own field on the order, whose text really moves when it is read as a number. */
    private const string CODE = 'code';

    /** With the leading zero a person types and a number cannot keep. */
    private const string TYPED = '007';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['convert-derive.localhost']);

        $this->switcher->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }

            self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(OrderModule::KEY),
                key: self::CODE,
                label: 'Code',
                type: 'text',
            );
        });
    }

    /** The question the conversion asks before it decides whether to do any of this. */
    public function testAModuleWithATotalDerivesAndAModuleWithoutOneDoesNot(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            $modules = self::service(MetadataRepository::class);
            $derived = self::service(DerivedValues::class);

            self::assertTrue($derived->derivesOn($modules->get(OrderModule::KEY)));
            self::assertFalse($derived->derivesOn($modules->get(ContactModule::KEY)));
        });
    }

    /**
     * Converting one field on an order restates everything that follows from
     * the record, because nothing says what follows from what.
     *
     * The gross total is put wrong first, straight into the column, the way
     * nothing in the application would: `RecordWriter` derives on every save, so
     * a total cannot be wrong through any ordinary door and the only way to
     * observe the re-derivation is to break one on purpose. Converting an
     * unrelated field then puts it back, which is the whole claim.
     */
    public function testConvertingAFieldReDerivesTheRecordsItTouched(): void
    {
        $order = $this->orderWithOneLine();

        $before = $this->grossTotal($order);
        self::assertNotSame('0.00', $before, 'the fixture has to have a total worth restating');

        $this->switcher->runFor($this->tenant, function () use ($order): void {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $field = $module->getField(OrderModule::GROSS_TOTAL);
            \assert($field !== null);

            self::service(RecordRepository::class)->writeStoredValue($module, $field, $order, '999.00');
        });

        self::assertSame('999.00', $this->grossTotal($order));

        $this->switcher->runFor($this->tenant, function (): void {
            self::service(FieldTypeConversion::class)->convert($this->field(self::CODE), 'integer');
        });

        // The conversion touched this record, so the module worked its own
        // values out again and the wrong total is gone.
        self::assertSame($before, $this->grossTotal($order));
    }

    /** An order with something on it, so that there is a total to be wrong about. */
    private function orderWithOneLine(): int
    {
        return $this->switcher->runFor($this->tenant, function (): int {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            return (int) self::service(RecordWriter::class)->save(
                $module,
                new Record([
                    'status' => OrderModule::DRAFT,
                    self::CODE => self::TYPED,
                ]),
                [OrderModule::LINES => [[
                    'id' => null,
                    'data' => [
                        OrderModule::KIND => OrderModule::CUSTOM_LINE,
                        'description' => 'One thing',
                        OrderModule::QUANTITY => '2',
                        OrderModule::UNIT_PRICE => '19.90',
                        OrderModule::TAX_RATE => '8.1',
                    ],
                ]]],
            )->id;
        });
    }

    private function grossTotal(int $order): string
    {
        return $this->switcher->runFor($this->tenant, function () use ($order): string {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);
            \assert($record !== null);

            return (string) $record->get(OrderModule::GROSS_TOTAL);
        });
    }

    /** The customer's own field on the order, read inside the tenant. */
    private function field(string $key): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get(OrderModule::KEY)->getField($key);
        \assert($field !== null);

        return $field;
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

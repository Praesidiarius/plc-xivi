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
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Module\ModuleUpgrade;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * An order book with no promotions in it (XIV-104).
 *
 * §6.1 says a customer's own module list is the truth, and this ticket's negative
 * acceptance criterion follows from it: **a tenant with orders and no vouchers
 * must see no trace of any of this.** Not a control with an empty picker behind
 * it, not a kind of line nobody can explain, not a column on a list.
 *
 * The mechanism is {@see \Xivi\Core\Module\AvailableFields} and it is blunt on
 * purpose: the field is not installed, so there is nothing anywhere to hide. That
 * is worth a test class of its own rather than an assertion inside
 * `OrderVoucherTest`, because the fact under test is the *absence* of a module
 * and that tenant has one — the same reason `VoucherWithoutArticlesTest` exists.
 *
 * **Every absence here is checked against a presence.** A test that passes
 * because nothing rendered at all proves nothing, so each assertion below either
 * names a control the page definitely does have, or is the mirror of an assertion
 * `OrderVoucherTest` makes on the same page in a tenant that has both modules.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderWithoutVouchersTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_order_alone';
    private const string HOST = 'order-alone.localhost';
    private const string EMAIL = 'alone@example.test';
    private const string PASSWORD = 'no-vouchers-password';

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

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Alone', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** Orders install with no voucher module anywhere, which is what `uses` means. */
    public function testOrdersInstallWithNoVoucherModuleAnywhere(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $metadata = self::service(MetadataRepository::class);

            self::assertNotNull($metadata->find(OrderModule::KEY), 'orders are installed');
            self::assertNull($metadata->find(VoucherModule::KEY), 'and vouchers are not');
        });
    }

    /** The field is not in this customer's definitions, so there is nothing to draw. */
    public function testTheOrderHasNoVoucherField(): void
    {
        $order = $this->orderModule();

        self::assertNull($order->getField(OrderModule::VOUCHER), 'no voucher field');
        self::assertNotNull(
            $order->getField(OrderModule::VAT_MODE),
            'and the field beside it is there, so this is not a module that failed to install',
        );
    }

    /**
     * **And neither has a line** (XIV-122), which is the same rule one shape
     * further down and needed the rule to learn about collections.
     *
     * Worth a method rather than a line inside the one above, because it is the
     * half that would have leaked silently: an order form with no rows on it yet
     * draws no line controls at all, so the page assertion below passes whether or
     * not the field was installed. The definitions are where the answer is.
     *
     * **The discount column beside it is asserted *present*, and that is the
     * honest half.** It names no module, so `AvailableFields` has no opinion about
     * it and this customer gets a *Discount* column that nothing can ever fill in.
     * It is a cosmetic cost and it is accepted rather than fixed: hiding it would
     * need a rule saying "this field is only writable while that other field
     * exists", which is one module's internals living in the engine, and §5.4
     * already gives the customer the better answer — the field editor removes a
     * field they do not want. Asserted so that the cost is a decision somebody
     * made rather than something the next reader discovers.
     */
    public function testTheOrderLineHasNoVoucherFieldEither(): void
    {
        $lines = $this->orderModule()->getCollection(OrderModule::LINES);

        self::assertNotNull($lines, 'the collection installed');
        self::assertNotNull($lines->getField(OrderModule::UNIT_PRICE), 'and it is a real order line');
        self::assertNull($lines->getField(OrderModule::LINE_VOUCHER), 'with no voucher picker on it');
        self::assertNotNull(
            $lines->getField(OrderModule::LINE_DISCOUNT),
            'and a discount column that nothing here can fill in — see the docblock',
        );
    }

    /**
     * And the page somebody types an order into has no control for it.
     *
     * The positive half is asserted in the same breath: the customer control *is*
     * on this form, and it is drawn by the same loop over the same definitions —
     * so an assertion that only looked for the voucher would pass just as happily
     * against a page that rendered nothing at all.
     */
    public function testTheRecordFormDrawsNoVoucherControl(): void
    {
        $form = (string) $this->client->request('GET', $this->url('/m/order/new'))
            ->filter('form[name="module_record"]')
            ->html();

        self::assertStringContainsString('module_record[fields][contact]', $form, 'the form is a real order form');
        self::assertStringNotContainsString('module_record[fields][voucher]', $form);
        self::assertStringNotContainsString('Voucher', $form);
    }

    /**
     * Nor is there a kind of line called Discount to add, which is the other
     * thing a customer could have met.
     *
     * The kind is in the module's blueprint whether or not vouchers are installed
     * — rows of it have to render on a document that has one — so this is
     * {@see \Xivi\Core\Metadata\AvailableVariants} keeping it out of the buttons
     * rather than the blueprint being different here.
     */
    public function testNoDiscountLineCanBeAdded(): void
    {
        $form = (string) $this->client->request('GET', $this->url('/m/order/new'))
            ->filter('form[name="module_record"]')
            ->html();

        self::assertStringContainsString('data-live-kind-param="subtotal"', $form, 'the other kinds are offered');
        self::assertStringNotContainsString('data-live-kind-param="discount"', $form);
    }

    /**
     * And the upgrade screen does not offer the field either (§7.2.1).
     *
     * This is the half that would have leaked: the offer is computed from the
     * blueprint, which declares the field, so without the same rule the installer
     * uses this customer would be invited to take a link into a module they have
     * not got — an invitation nothing would refuse and that would leave them with
     * exactly the empty picker the install skipped.
     */
    public function testTheUpgradeScreenDoesNotOfferTheVoucherField(): void
    {
        $offered = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): array {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            return array_map(
                static fn (\Xivi\Core\Module\ModuleAddition $addition): string => $addition->key,
                self::service(ModuleUpgrade::class)->available($module),
            );
        });

        self::assertNotContains(OrderModule::VOUCHER, $offered);
    }

    /**
     * An order still saves, and its totals are what they always were.
     *
     * The point of the whole design: none of this feature runs here, and the
     * arithmetic in `DerivesTotals` has one more branch in it that this customer
     * never enters.
     */
    public function testAnOrderStillSavesAndAddsUp(): void
    {
        $contact = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Regal AG'],
            variant: 'company',
        ));

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $contact,
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => '8.10',
        ])]]));

        $record = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): array {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $found = self::service(\Xivi\Core\Record\RecordRepository::class)->find($module, $order);
            self::assertNotNull($found);

            return [$found->get(OrderModule::NET_TOTAL), $found->get(OrderModule::GROSS_TOTAL)];
        });

        self::assertSame(['100.00', '108.10'], $record);
    }

    private function orderModule(): ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ModuleDefinition => self::service(MetadataRepository::class)->get(OrderModule::KEY),
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

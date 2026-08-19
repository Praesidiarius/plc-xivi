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
use Symfony\Component\HttpFoundation\Response;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\Redemption\VoucherRedemptions;
use Xivi\Voucher\VoucherModule;

/**
 * A voucher on an order (XIV-104).
 *
 * The other half of [XIV-103], which made a voucher exist and be redeemable.
 * What is under test here is what happens to the money and to the counter when
 * an order names one.
 *
 * **The figures are chosen to be awkward on purpose**, the same way
 * `VatIncludedPricesTest` chose 19.95. Ten francs over three equal VAT rates is
 * 3.33 three times, which is 9.99 — so every test here about a mixed-rate order
 * is a test that the missing rappen lands where this ticket said it would rather
 * than wherever the last division happened to leave it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderVoucherTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_order_voucher';
    private const string HOST = 'ordervoucher.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'order-voucher-password';

    /** The three rates this country has, which is what makes a split uneven. */
    private const string STANDARD = '8.10';
    private const string REDUCED = '2.60';
    private const string LODGING = '3.80';

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

            // **Through the install order rather than in the order they are
            // written here** (XIV-72, XIV-104). An order installed before
            // vouchers is an order with no voucher field on it, because a field
            // pointing at a module the customer has not got is not installed —
            // so the whole of this test class depends on the sort, and asking
            // for it here is what a `tenant:reset` or a store purchase does too.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the field ----------------------------------------------------------

    /** An order can carry a voucher, and the field is on the form somebody fills in. */
    public function testAnOrderCanNameAVoucher(): void
    {
        self::assertNotNull(
            $this->orderModule()->getField(OrderModule::VOUCHER),
            'the order module has the field where both modules are installed',
        );

        self::assertStringContainsString(
            'module_record[fields][voucher]',
            $this->recordFormOn('/m/order/new'),
            'and the control is drawn on the page somebody types an order into',
        );
    }

    // -- the discount is a line ---------------------------------------------

    /**
     * **An absolute voucher is a `-10.00` line and nothing else.**.
     *
     * The lines it discounts are untouched — asserted here rather than implied,
     * because the alternative design this ticket rejected was to reach into them.
     */
    public function testAnAbsoluteVoucherIsALineOfItsOwn(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        self::assertSame(
            [
                ['custom', 'Desk lamp', '1.00', '100.00', self::STANDARD, '', '100.00'],
                ['discount', 'GIVE-10', '1.00', '-10.00', self::STANDARD, '', '-10.00'],
            ],
            $this->linesOf($order),
            'the line somebody typed, and a line the engine wrote under it',
        );

        $record = $this->orderRecord($order);

        self::assertSame('90.00', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('7.29', $record->get(OrderModule::TAX_TOTAL), '8.1% of 90, not of 100');
        self::assertSame('97.29', $record->get(OrderModule::GROSS_TOTAL));
    }

    /**
     * **The voucher applies before VAT**, which is the decision this ticket was
     * given and the one figure that proves it.
     *
     * 8.1% of the discounted 90.00 is 7.29; of the undiscounted 100.00 it would
     * be 8.10. The VAT table is asserted as well as the total, because a discount
     * that reached the total without reaching the table is exactly the shape of
     * the bug being designed out — the two would disagree and only the table is
     * what a tax inspector reads.
     */
    public function testTheDiscountIsInsideTheVatTable(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        self::assertSame([[self::STANDARD, '90.00', '7.29']], $this->vatTableOf($order));
    }

    /** A percentage is the same line with the amount worked out first. */
    public function testARelativeVoucherIsTheSameLineWithTheAmountComputed(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'TENTH', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::ORDER_PERCENTAGE,
        );

        $order = $this->anOrder($voucher, [
            ['description' => 'Consulting', 'quantity' => '3', 'unit_price' => '66.65', 'tax_rate' => self::STANDARD],
        ]);

        // 3 × 66.65 is 199.95, a tenth of which is 19.995 — which rounds to 20.00
        // rather than being left as a third decimal nobody can pay.
        self::assertSame(
            [['custom', 'Consulting', '3.00', '66.65', self::STANDARD, '', '199.95'],
                ['discount', 'TENTH', '1.00', '-20.00', self::STANDARD, '', '-20.00']],
            $this->linesOf($order),
        );

        self::assertSame('179.95', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
    }

    /**
     * **A free article is now a line voucher at a hundred percent**, and this is
     * the assertion that says the third kind was dissolved rather than dropped
     * (XIV-122).
     *
     * The customer puts the mug on the order the way every other article goes on
     * it, and the voucher — restricted to that article — takes its whole price off
     * that line. What is gone is the row appearing underneath at a quantity the
     * voucher decided; what is kept is the customer receiving a mug and paying
     * nothing for it, which is the half anybody cared about.
     */
    public function testAFreeArticleIsALineVoucherAtAHundredPercent(): void
    {
        $article = $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => 'Travel mug',
            'price' => '24.00',
            'tax_rate' => self::STANDARD,
        ]));

        $voucher = $this->aVoucher([
            'code' => 'FREE-MUG',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '100',
            'article' => (string) $article,
        ], VoucherModule::LINE_PERCENTAGE);

        $order = $this->anOrder(null, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
            [
                'kind' => OrderModule::ARTICLE_LINE,
                'article' => (string) $article,
                'description' => 'Travel mug',
                'quantity' => '2',
                'unit_price' => '24.00',
                'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher,
            ],
        ]);

        self::assertSame(
            [['custom', 'Desk lamp', '1.00', '100.00', self::STANDARD, '', '100.00'],
                ['article', 'Travel mug', '2.00', '24.00', self::STANDARD, '48.00', '0.00']],
            $this->linesOf($order),
            'the mug is charged for and then given away in the column beside it',
        );

        self::assertSame('100.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
        self::assertSame([[self::STANDARD, '100.00', '8.10']], $this->vatTableOf($order));
    }

    // -- the rate a discount line carries -----------------------------------

    /**
     * **The case that does not divide evenly**, which is the one this ticket is
     * really about.
     *
     * Three rates selling exactly 100.00 each and a ten-franc voucher: each share
     * is 10 × 100 ÷ 300 = 3.3333…, which rounds to 3.33, and three of those come
     * to 9.99. The rule written down is that the shares are computed in ascending
     * rate order and **the last line takes what is left** — so the highest rate
     * on the document, 8.1%, carries 3.34 and the voucher is worth exactly the ten
     * francs it says it is.
     *
     * Both halves are asserted separately below: that the discount comes to
     * 10.00, and that the odd rappen is on the rate the rule names rather than on
     * whichever rate the division happened to reach last.
     */
    public function testAMixedRateDiscountIsSplitProRataAndTheLastLineTakesTheRemainder(): void
    {
        $order = $this->aThreeRateOrder('10.00');

        self::assertSame(
            [
                [self::REDUCED, '-3.33'],
                [self::LODGING, '-3.33'],
                [self::STANDARD, '-3.34'],
            ],
            $this->discountLinesOf($order),
            'ascending by rate, and the last one absorbs the rappen',
        );
    }

    /**
     * And the whole thing still reconciles: the VAT table adds up to the totals
     * beside it on a document whose discount did not divide.
     *
     * Asserted as arithmetic over the stored rows rather than as more literals —
     * the test above already says what the numbers are, and what this says is that
     * they agree, which is the property a rounding change would break silently.
     */
    public function testTheVatTableSumsToTheTotalOnAnUnevenSplit(): void
    {
        $order = $this->aThreeRateOrder('10.00');
        $record = $this->orderRecord($order);
        $table = $this->vatTableOf($order);

        $net = array_sum(array_map(static fn (array $row): float => (float) $row[1], $table));
        $tax = array_sum(array_map(static fn (array $row): float => (float) $row[2], $table));

        self::assertEqualsWithDelta(290.0, $net, 0.0001, '300 sold, 10 off, to the rappen');
        self::assertEqualsWithDelta((float) $record->get(OrderModule::NET_TOTAL), $net, 0.0001);
        self::assertEqualsWithDelta((float) $record->get(OrderModule::TAX_TOTAL), $tax, 0.0001);
        self::assertEqualsWithDelta((float) $record->get(OrderModule::GROSS_TOTAL), $net + $tax, 0.0001);
    }

    /**
     * A voucher worth more than the order it is used on comes off entirely and
     * stops there.
     *
     * The alternative is a negative total, which is money owed back to a customer
     * and a thing nothing downstream is built to hand over (§5.19 stops the
     * percentage at 100 for the same reason).
     */
    public function testAVoucherWorthMoreThanTheOrderIsCappedByIt(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-50', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '50.00']);

        $order = $this->anOrder($voucher, [
            ['description' => 'Notebook', 'quantity' => '1', 'unit_price' => '20.00', 'tax_rate' => self::STANDARD],
        ]);

        $record = $this->orderRecord($order);

        self::assertSame('0.00', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('0.00', $record->get(OrderModule::GROSS_TOTAL));
    }

    // -- the engine owns the line -------------------------------------------

    /**
     * **A generated line cannot be edited by hand**, tested the way somebody
     * would try it: a request that submits a different price for it.
     *
     * Through the same component the record form is (XIV-33), with the row's own
     * id, so this is the save a browser with a modified page would perform rather
     * than a guard method called directly.
     */
    public function testAGeneratedLineCannotBeEditedByHand(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, $voucher, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
            self::row([
                OrderModule::KIND => OrderModule::DISCOUNT_LINE,
                'description' => 'A better deal',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '-90.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[1]->id),
        ]);

        self::assertSame(
            [['custom', 'Desk lamp', '1.00', '100.00', self::STANDARD, '', '100.00'],
                ['discount', 'GIVE-10', '1.00', '-10.00', self::STANDARD, '', '-10.00']],
            $this->linesOf($order),
            'what was typed over it is gone; the voucher still decides',
        );

        self::assertSame('97.29', $this->orderRecord($order)->get(OrderModule::GROSS_TOTAL));
    }

    /** And it cannot be deleted by hand either: a save that leaves it out gets it back. */
    public function testAGeneratedLineCannotBeDeletedByHand(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, $voucher, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
        ]);

        self::assertSame(
            [['custom', 'Desk lamp', '1.00', '100.00', self::STANDARD, '', '100.00'],
                ['discount', 'GIVE-10', '1.00', '-10.00', self::STANDARD, '', '-10.00']],
            $this->linesOf($order),
        );
    }

    /**
     * **And nobody is invited to add one**, which is the other half of the same
     * decision and the one that costs nothing to get right.
     *
     * A subtotal row is the precedent for a line whose *figure* is the engine's,
     * and the editor lets somebody add, move and delete one — so the protection a
     * subtotal has is not the protection a discount needs, and this is where the
     * difference shows: `subtotal` is offered as a button and `discount` is not.
     */
    public function testTheDiscountKindIsNotOfferedAsSomethingToAdd(): void
    {
        $page = $this->recordFormOn('/m/order/new');

        self::assertStringContainsString('data-live-kind-param="subtotal"', $page, 'the precedent is offered');
        self::assertStringNotContainsString('data-live-kind-param="discount"', $page, 'and this one is not');
    }

    /** Asking for one anyway, by name, changes nothing. */
    public function testAskingForADiscountRowByNameAddsNothing(): void
    {
        $form = $this->recordForm(OrderModule::KEY)
            ->call('addRow', ['collection' => OrderModule::LINES, 'kind' => OrderModule::DISCOUNT_LINE]);

        self::assertStringNotContainsString(
            'value="discount"',
            $form->render()->toString(),
            'a hand-edited action is not a button anybody was offered',
        );
    }

    /**
     * The controls for a generated row are drawn disabled, which is the second
     * refusal and an independent one.
     *
     * The deriver is what makes an edit *have no effect*; this is what makes the
     * form not offer it in the first place. Symfony ignores what is submitted for
     * a disabled field, so the two do not overlap: one refuses a hand-edited
     * request, the other refuses the page.
     *
     * The typed line beside it is asserted enabled in the same breath, because a
     * check that only looked for `disabled` would pass just as happily against a
     * form that had disabled everything.
     */
    public function testTheControlsForAGeneratedRowAreDisabled(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'))
            ->filter('form[name="module_record"] .row-of-collection');

        self::assertCount(2, $rows, 'the line and the discount under it');

        $typed = (string) $rows->eq(0)->html();
        $generated = (string) $rows->eq(1)->html();

        self::assertStringNotContainsString('name="module_record[collections][lines][0][fields][unit_price]" disabled', $typed);
        self::assertStringContainsString('name="module_record[collections][lines][1][fields][unit_price]" disabled', $generated);
        self::assertStringContainsString('name="module_record[collections][lines][1][fields][description]" disabled', $generated);
        self::assertStringNotContainsString('removeRow', $generated, 'and there is no button offering to delete it');
        self::assertStringContainsString('removeRow', $typed, 'while the line somebody typed still has one');
    }

    /**
     * **Editing a discounted order does not discount it again.**.
     *
     * The bug this is written against is one line of arithmetic: a relative
     * voucher is a tenth of what the lines came to, so if last save's discount
     * line is counted in that sum, every save takes a tenth off a total that
     * already had a tenth off it. Three saves and the customer has 27% off. The
     * generated rows are therefore taken out of the sum before the voucher is
     * asked what it is worth.
     */
    public function testEditingADiscountedOrderDoesNotCompoundTheDiscount(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'TENTH', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::ORDER_PERCENTAGE,
        );

        $order = $this->anOrder($voucher, [
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        for ($save = 0; $save < 3; ++$save) {
            $rows = $this->rowsOf($order, OrderModule::LINES);

            $this->saveOrder($order, $voucher, [
                self::row([
                    OrderModule::KIND => OrderModule::CUSTOM_LINE,
                    'description' => 'Consulting',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => '100.00',
                    OrderModule::TAX_RATE => self::STANDARD,
                ], (int) $rows[0]->id),
                self::row([
                    OrderModule::KIND => OrderModule::DISCOUNT_LINE,
                    'description' => 'TENTH',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => '-10.00',
                    OrderModule::TAX_RATE => self::STANDARD,
                ], (int) $rows[1]->id),
            ]);

            self::assertSame('90.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL), 'after save ' . $save);
        }

        self::assertSame(
            [['custom', 'Consulting', '1.00', '100.00', self::STANDARD, '', '100.00'],
                ['discount', 'TENTH', '1.00', '-10.00', self::STANDARD, '', '-10.00']],
            $this->linesOf($order),
            'still two lines, and the same two',
        );
    }

    /**
     * **The same row, not a new one every time.**.
     *
     * A generated row that were deleted and re-inserted on every save would churn
     * ids, pile up soft-deleted tombstones behind an order nobody changed, and
     * write "line removed / line added" into the timeline of every edit — the
     * argument {@see \Xivi\Core\Form\CollectionRowType} makes about a row's id,
     * which is why the deriver hands the replaced rows' ids back with the new
     * ones.
     */
    public function testAGeneratedRowKeepsItsIdAcrossSaves(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);
        $was = (int) $rows[1]->id;

        $this->saveOrder($order, $voucher, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '2',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
            self::row([
                OrderModule::KIND => OrderModule::DISCOUNT_LINE,
                'description' => 'GIVE-10',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '-10.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], $was),
        ]);

        self::assertSame($was, (int) $this->rowsOf($order, OrderModule::LINES)[1]->id);
    }

    // -- what is stored, and what is not re-read ----------------------------

    /**
     * **Deleting the voucher afterwards changes nothing on the order.**.
     *
     * The discount is stored like every other line total (§5.9), and the order's
     * reference merely says which voucher it was. This is the assertion that the
     * figure is not re-read from the voucher when somebody looks at the order —
     * because by then there is no voucher to re-read.
     */
    public function testDeletingTheVoucherLeavesTheOrderAsItWas(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $before = $this->linesOf($order);

        $this->deleteRecord(VoucherModule::KEY, $voucher);

        self::assertSame($before, $this->linesOf($order), 'the same lines, to the rappen');
        self::assertSame('97.29', $this->orderRecord($order)->get(OrderModule::GROSS_TOTAL));
    }

    /**
     * And a draft edited after the voucher is gone keeps the discount it was
     * given rather than losing it.
     *
     * A voucher that cannot be read is not a voucher worth nothing — the deriver
     * leaves the lines it finds alone, which is the safe direction and the one
     * §5.9 argues for at length.
     */
    public function testADraftEditedAfterTheVoucherIsGoneKeepsItsDiscount(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->deleteRecord(VoucherModule::KEY, $voucher);

        $this->saveOrder($order, $voucher, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '2',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
            self::row([
                OrderModule::KIND => OrderModule::DISCOUNT_LINE,
                'description' => 'GIVE-10',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '-10.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[1]->id),
        ]);

        self::assertSame('190.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL), '200 sold, 10 still off');
    }

    /**
     * An invoice made from a discounted order is billed for the discounted
     * amount (§5.12).
     *
     * Through the button and the form the customer uses, so the seeding itself
     * stays under test — and the discount needs nothing of its own to get there,
     * which is the point: it is a line, and a line is what §5.12 already copies.
     */
    public function testAnInvoiceSeededFromADiscountedOrderCarriesTheDiscount(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $invoice = $this->invoiceOf($order);

        self::assertSame(
            [['custom', 'Desk lamp', '100.00'], ['discount', 'GIVE-10', '-10.00']],
            array_map(
                static fn (Record $row): array => [
                    (string) $row->get(InvoiceModule::KIND),
                    (string) $row->get(InvoiceModule::DESCRIPTION),
                    (string) $row->get(InvoiceModule::LINE_TOTAL),
                ],
                $this->rowsOf($invoice, InvoiceModule::LINES, InvoiceModule::KEY),
            ),
            'both lines came across, the discount among them',
        );

        self::assertSame('90.00', $this->invoiceRecord($invoice)->get(InvoiceModule::NET_TOTAL));
        self::assertSame('97.29', $this->invoiceRecord($invoice)->get(InvoiceModule::GROSS_TOTAL));
        self::assertSame(
            1,
            $this->redeemed($voucher),
            'and billing it takes no second use — an invoice names no voucher',
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * An order naming a voucher, all lines custom so nothing depends on a
     * catalogue.
     *
     * @param list<array<string, string>> $lines
     */
    private function anOrder(?int $voucher, array $lines): int
    {
        $rows = [];

        foreach ($lines as $values) {
            // Custom unless the row says otherwise, so the ordinary case stays one
            // line and a line naming an article — or a voucher — can still be
            // written without a second helper.
            $rows[] = self::row([OrderModule::KIND => OrderModule::CUSTOM_LINE, ...$values]);
        }

        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => $voucher === null ? '' : (string) $voucher,
        ], [OrderModule::LINES => $rows]));
    }

    /**
     * The awkward one: three rates, a hundred francs each, and a voucher that
     * does not divide by three.
     */
    private function aThreeRateOrder(string $amount): int
    {
        $voucher = $this->aVoucher(['code' => 'SPLIT-' . str_replace('.', '', $amount), 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => $amount]);

        return $this->anOrder($voucher, [
            ['description' => 'Dinner', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
            ['description' => 'Guidebook', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::REDUCED],
            ['description' => 'Room', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::LODGING],
        ]);
    }

    /**
     * Save an existing order with the given lines, as the record form does.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function saveOrder(int $order, ?int $voucher, array $rows): Response
    {
        return $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->orderRecord($order)->get('contact'),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => $voucher === null ? '' : (string) $voucher,
        ], [OrderModule::LINES => $rows], $order);
    }

    /**
     * Bill an order, through the button on its page and the form that comes back
     * filled in.
     *
     * Lifted from `InvoiceModuleTest`, including the two subtleties it found: the
     * component has to be mounted with the seeding it was drawn from (XIV-19),
     * and an empty control is a value nobody set rather than an empty string,
     * which a decimal field refuses.
     */
    private function invoiceOf(int $order): int
    {
        $page = $this->client->request('GET', $this->url('/m/order/' . $order));
        $seeded = $this->client->click($page->selectLink('Invoice what is left')->link());

        $values = self::formValuesOn($seeded);

        /** @var array<string, string> $fields */
        $fields = $values['fields'];
        $seedFields = array_filter($fields, static fn (mixed $value): bool => $value !== '');

        $fields['issued_on'] = '2026-08-19';
        $fields['status'] = InvoiceModule::DRAFT;

        /** @var array<string, list<array<string, mixed>>> $rows */
        $rows = $values['collections'] ?? [];

        return $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            $fields,
            $rows,
            seeded: array_map(
                static fn (array $lines): array => array_values(array_map(
                    static fn (array $row): array => array_filter(
                        (array) $row['fields'],
                        static fn (mixed $value): bool => $value !== '',
                    ),
                    $lines,
                )),
                $rows,
            ),
            seededFields: $seedFields,
        ));
    }

    /** @param array<string, string> $fields */
    private function aVoucher(array $fields, string $variant = VoucherModule::ORDER_AMOUNT): int
    {
        return $this->savedId($this->saveRecord(VoucherModule::KEY, $fields, variant: $variant));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Regal AG'],
            variant: 'company',
        ));
    }

    /** How many times the counter says this voucher has been used. */
    private function redeemed(int $voucher): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => self::service(VoucherRedemptions::class)->countFor($voucher),
        );
    }

    /**
     * The lines as stored: kind, description, quantity, price, rate, discount,
     * total.
     *
     * The discount column joined the tuple with XIV-122 rather than being asserted
     * separately, because what it is worth checking against is the line total
     * beside it — a reduction and a total that disagree is the one failure this
     * feature can have that still looks plausible.
     *
     * @return list<array{string, string, string, string, string, string, string}>
     */
    private function linesOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get(OrderModule::KIND),
                (string) $row->get('description'),
                (string) $row->get(OrderModule::QUANTITY),
                (string) $row->get(OrderModule::UNIT_PRICE),
                (string) $row->get(OrderModule::TAX_RATE),
                (string) $row->get(OrderModule::LINE_DISCOUNT),
                (string) $row->get(OrderModule::LINE_TOTAL),
            ],
            $this->rowsOf($order, OrderModule::LINES),
        );
    }

    /**
     * Only the generated ones, as rate and amount.
     *
     * @return list<array{string, string}>
     */
    private function discountLinesOf(int $order): array
    {
        return array_values(array_map(
            static fn (array $line): array => [$line[4], $line[6]],
            array_filter($this->linesOf($order), static fn (array $line): bool => $line[0] === OrderModule::DISCOUNT_LINE),
        ));
    }

    /** @return list<array{string, string, string}> rate, net, VAT — one per row */
    private function vatTableOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get(OrderModule::RATE),
                (string) $row->get(OrderModule::TAXABLE_NET),
                (string) $row->get(OrderModule::TAX_AMOUNT),
            ],
            $this->rowsOf($order, OrderModule::TAXES),
        );
    }

    /** @return list<Record> */
    private function rowsOf(int $record, string $collection, string $module = OrderModule::KEY): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($record, $collection, $module): array {
                $rows = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
                self::assertNotNull($rows);

                return self::service(RecordRepository::class)->findChildren($rows, $record);
            },
        );
    }

    private function orderModule(): \Xivi\Core\Entity\ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): \Xivi\Core\Entity\ModuleDefinition => self::service(MetadataRepository::class)->get(OrderModule::KEY),
        );
    }

    private function orderRecord(int $order): Record
    {
        return $this->recordOf(OrderModule::KEY, $order);
    }

    private function invoiceRecord(int $invoice): Record
    {
        return $this->recordOf(InvoiceModule::KEY, $invoice);
    }

    private function recordOrNull(string $module, int $id): ?Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): ?Record {
            $definition = self::service(MetadataRepository::class)->get($module);

            return self::service(RecordRepository::class)->find($definition, $id);
        });
    }

    private function recordOf(string $module, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): Record {
            $definition = self::service(MetadataRepository::class)->get($module);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
    }

    /**
     * Delete a record the way its own page does, token and all.
     *
     * Through the button rather than around it: the controller checks a token
     * scoped to the record, so a request that skipped the page would be redirected
     * having quietly done nothing — which is exactly the shape of test that passes
     * for the wrong reason.
     */
    private function deleteRecord(string $module, int $id): void
    {
        $path = sprintf('/m/%s/%d', $module, $id);
        $page = $this->client->request('GET', $this->url($path));
        $token = $page->filter('form[action$="/delete"] input[name="_token"]')->first()->attr('value');

        self::assertNotNull($token, 'the record page offers a delete button');

        $this->client->request('POST', $this->url($path . '/delete'), ['_token' => $token]);

        self::assertNull(
            $this->recordOrNull($module, $id),
            'the record is gone, rather than the request having been quietly refused',
        );
    }

    /** The record form on a page, without the chrome around it. */
    private function recordFormOn(string $path): string
    {
        return (string) $this->client->request('GET', $this->url($path))
            ->filter('form[name="module_record"]')
            ->html();
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

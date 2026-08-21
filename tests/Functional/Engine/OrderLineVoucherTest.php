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
 * A voucher applied to one line rather than to the order (XIV-122).
 *
 * [XIV-104] built the other mode — a voucher on the document, adding a line of
 * its own — and `OrderVoucherTest` is where that stays under test. What is under
 * test here is the second mode and the three things it can quietly get wrong:
 * **whether it reduces only the line it was applied to**, **whether the reduction
 * survives somebody typing over it**, and **what it costs the counter**.
 *
 * **The figures are chosen to be awkward on purpose**, the same way
 * `VatIncludedPricesTest` chose 19.95 and `OrderVoucherTest` chose ten francs over
 * three rates. Fifteen percent of 199.95 is 29.9925 — a figure with a third
 * decimal nobody can pay — and the discounted line shares its VAT rate with an
 * undiscounted one, so a reduction that leaked into the wrong line or out of the
 * VAT grouping would move a figure this class asserts rather than one it happens
 * not to look at.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderLineVoucherTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_line_voucher';
    private const string HOST = 'linevoucher.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'line-voucher-password';

    /** Two of the three rates this country has, so one grouping holds two lines. */
    private const string STANDARD = '8.10';
    private const string REDUCED = '2.60';

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
            // order with no voucher field on its header *and none on its lines*,
            // which since XIV-122 is the same rule asked one shape further down.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }
    // -- the field ----------------------------------------------------------

    /** A line can carry a voucher, and the control is on the form somebody types into. */
    public function testALineCanNameAVoucher(): void
    {
        $lines = $this->orderModule()->getCollection(OrderModule::LINES);

        self::assertNotNull($lines);
        self::assertNotNull($lines->getField(OrderModule::LINE_VOUCHER), 'the line has the field');
        self::assertNotNull($lines->getField(OrderModule::LINE_DISCOUNT), 'and the column that says what came off');
    }

    /**
     * **And the busiest order line still fits on one row** (XIV-43, XIV-122).
     *
     * `OrderTotalsTest` has asserted this since [XIV-43] and can no longer assert
     * it exactly: its tenant has no vouchers, so it draws the row one twelfth
     * short. This tenant has both modules, which makes it the installation where
     * the busiest line actually exists — an article line with a voucher on it and
     * a column saying what that voucher took off. Two fields were added and two
     * were narrowed to pay for them, and this is the arithmetic that says the
     * paying was done.
     */
    public function testTheBusiestOrderLineStillFitsOnOneRow(): void
    {
        $lines = $this->orderModule()->getCollection(OrderModule::LINES);

        self::assertNotNull($lines);

        $widths = [];

        foreach ($lines->getFieldsFor(OrderModule::ARTICLE_LINE) as $field) {
            // The kind travels hidden in a row (XIV-20), so it takes no room.
            if ($field->getKey() === $lines->getVariantField()) {
                continue;
            }

            $widths[$field->getKey()] = $field->getWidth();
        }

        self::assertArrayHasKey(OrderModule::LINE_VOUCHER, $widths, 'the voucher column is on this line');
        self::assertArrayHasKey(OrderModule::LINE_DISCOUNT, $widths, 'and so is what it took off');
        self::assertSame(12, array_sum($widths), sprintf(
            'an article line with a voucher on it is exactly one row: %s',
            json_encode($widths, \JSON_THROW_ON_ERROR),
        ));
    }

    // -- it reduces its own line and nobody else's --------------------------

    /**
     * **The claim this ticket is really about**, on a mixed-rate order where the
     * discounted line is not the only one at its rate.
     *
     * Fifteen percent of 199.95 is 29.9925, which rounds to 29.99 — so the line
     * comes to 169.96 rather than to a figure ending in a third decimal. The line
     * beside it shares the 8.1% rate and is untouched, and the 2.6% line is
     * untouched as well: a reduction that reached the rate rather than the line
     * would move both of the first two, and a reduction applied to the document
     * would move all three.
     */
    public function testALineVoucherReducesItsOwnLineAndLeavesTheOthersAlone(): void
    {
        $order = $this->aMixedRateOrder();

        self::assertSame(
            [
                ['Consulting', '3.00', '66.65', '29.99', '169.96'],
                ['Support', '1.00', '55.55', '', '55.55'],
                ['Manual', '1.00', '33.33', '', '33.33'],
            ],
            $this->linesOf($order),
            'one line reduced, in a column of its own, and the other two exactly as typed',
        );
    }

    /**
     * And the VAT table still sums, which is the half a reduction can break
     * silently.
     *
     * The 8.1% group is the reduced 169.96 plus the untouched 55.55 — 225.51,
     * whose tax is 18.266310 and therefore 18.27. The 2.6% group is 33.33 alone,
     * whose tax is 0.866580 and therefore 0.87. **The tax is computed on what was
     * charged rather than on what was quoted**, which is XIV-104's "before VAT"
     * decision arriving in the other mode: 8.1% of the undiscounted 255.50 would
     * have been 20.70, and that figure appears nowhere.
     *
     * XIV-116 settled that VAT groups per rate before rounding and that no
     * remainder crosses a rate boundary. Nothing here needs a rule about that and
     * the reason is worth saying: a line discount **stays on its own line**, so it
     * joins exactly one rate by being part of it and there is no share to
     * distribute. That is the whole difference from XIV-104's order voucher, which
     * needed a line per rate precisely because it belonged to none of them.
     */
    public function testTheVatTableIsComputedOnTheReducedLine(): void
    {
        $order = $this->aMixedRateOrder();

        self::assertSame(
            [[self::REDUCED, '33.33', '0.87'], [self::STANDARD, '225.51', '18.27']],
            $this->vatTableOf($order),
        );

        $record = $this->orderRecord($order);

        self::assertSame('258.84', $record->get(OrderModule::NET_TOTAL), '199.95 − 29.99, plus 55.55, plus 33.33');
        self::assertSame('19.14', $record->get(OrderModule::TAX_TOTAL), '18.27 + 0.87');
        self::assertSame('277.98', $record->get(OrderModule::GROSS_TOTAL));

        // Asserted as arithmetic over the stored rows as well as as literals: the
        // figures above say what the numbers are, and this says that they agree,
        // which is the property a rounding change would break without moving any
        // single one of them enough to notice.
        $table = $this->vatTableOf($order);
        $net = array_sum(array_map(static fn (array $row): float => (float) $row[1], $table));
        $tax = array_sum(array_map(static fn (array $row): float => (float) $row[2], $table));

        self::assertEqualsWithDelta((float) $record->get(OrderModule::NET_TOTAL), $net, 0.0001);
        self::assertEqualsWithDelta((float) $record->get(OrderModule::TAX_TOTAL), $tax, 0.0001);
        self::assertEqualsWithDelta((float) $record->get(OrderModule::GROSS_TOTAL), $net + $tax, 0.0001);
    }

    /**
     * **A custom line, which is the case the earlier design could not reach.**.
     *
     * A custom line has no article, so a voucher that found its line by naming an
     * article would have missed it — and a negotiated discount is exactly what
     * lands on a line somebody typed by hand. The voucher here is unrestricted,
     * which is what makes it able to go anywhere.
     */
    public function testALineVoucherReachesACustomLine(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'OFF-15', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '15'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            [
                'description' => 'Bespoke joinery',
                'quantity' => '1',
                'unit_price' => '1234.55',
                'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher,
            ],
        ]);

        // 15% of 1234.55 is 185.1825, which is 185.18 to the rappen.
        self::assertSame(
            [['Bespoke joinery', '1.00', '1234.55', '185.18', '1049.37']],
            $this->linesOf($order),
        );
    }

    /** A fixed amount off one line is the other kind, and needs nothing of its own. */
    public function testAFixedAmountComesOffTheLineItIsPutOn(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'TWENTY-OFF', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '20.00'],
            VoucherModule::LINE_AMOUNT,
        );

        $order = $this->anOrder([
            ['description' => 'Chair', 'quantity' => '2', 'unit_price' => '199.95', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Cushion', 'quantity' => '1', 'unit_price' => '49.95', 'tax_rate' => self::STANDARD],
        ]);

        self::assertSame(
            [['Chair', '2.00', '199.95', '20.00', '379.90'], ['Cushion', '1.00', '49.95', '', '49.95']],
            $this->linesOf($order),
        );
    }

    // -- the bound, decided rather than emergent ----------------------------

    /**
     * **A fixed amount larger than the line is floored at the line, not refused.**.
     *
     * Decided in [XIV-122] and argued in `DerivesTotals::offOneLine()`: a negative
     * line is money owed back and nothing downstream hands any over, and a refusal
     * would be the engine declining an arithmetic it can perform — the shop said
     * "fifty off this", the line was worth fifteen, and fifteen off is plainly
     * what was meant. §5.19's ceiling of 100 on a percentage is the same rule
     * reached from the other side, and this is the assertion that says the two
     * agree.
     */
    public function testAnAmountLargerThanTheLineIsFlooredAtIt(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'FIFTY-OFF', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '50.00'],
            VoucherModule::LINE_AMOUNT,
        );

        $order = $this->anOrder([
            ['description' => 'Notebook', 'quantity' => '1', 'unit_price' => '15.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        self::assertSame([['Notebook', '1.00', '15.00', '15.00', '0.00']], $this->linesOf($order));

        $record = $this->orderRecord($order);

        self::assertSame('0.00', $record->get(OrderModule::NET_TOTAL), 'nothing, rather than money owed back');
        self::assertSame('0.00', $record->get(OrderModule::GROSS_TOTAL));
    }

    /** And the percentage is bounded by the field itself, at a hundred. */
    public function testThePercentageIsBoundedAtAHundred(): void
    {
        $field = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?\Xivi\Core\Entity\FieldDefinition => self::service(MetadataRepository::class)
                ->get(VoucherModule::KEY)
                ->getField(VoucherModule::PERCENTAGE),
        );

        self::assertNotNull($field);
        self::assertSame(100, $field->getOptions()['max'] ?? null, 'a 120% voucher is an order that owes money back');
        self::assertSame(0, $field->getOptions()['min'] ?? null, 'and a negative one is a surcharge');
    }

    // -- it cannot be un-reduced by hand ------------------------------------

    /**
     * **The reduction survives somebody typing over it**, tested the way somebody
     * would try it: a save that submits the row's own id with the discount forged
     * back to nothing and the line total forged back to what it was before.
     *
     * Through the record form's own save action, so this is the request a browser
     * with a modified page would perform rather than a guard method called
     * directly. §5.24 found that the subtotal precedent protects *a column and not
     * a row* and needed three mechanisms because the engine owned the whole row;
     * here a column is exactly what needs protecting, because the row is the
     * customer's article line and they edit the rest of it freely. So the derived
     * flag is the right precedent and this is the assertion that it holds.
     */
    public function testAReducedLineCannotBeUnReducedByHand(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'OFF-15', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '15'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'Consulting', 'quantity' => '3', 'unit_price' => '66.65', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                OrderModule::QUANTITY => '3',
                OrderModule::UNIT_PRICE => '66.65',
                OrderModule::TAX_RATE => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher,
                // The forgery, both halves of it: no discount, and a line total
                // that agrees with there being none.
                OrderModule::LINE_DISCOUNT => '0.00',
                OrderModule::LINE_TOTAL => '199.95',
            ], (int) $rows[0]->id),
        ]);

        self::assertSame(
            [['Consulting', '3.00', '66.65', '29.99', '169.96']],
            $this->linesOf($order),
            'what was typed over it is gone; the voucher still decides',
        );

        self::assertSame('169.96', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
    }

    /**
     * **And taking the voucher off the line gives the money back**, which is the
     * same mechanism read in the other direction and the one that says the column
     * is restated rather than merely defended.
     *
     * A save that keeps the row and empties its voucher has to clear the discount
     * too. If the deriver only ever *wrote* reductions, this order would keep a
     * 29.99 discount attached to no voucher at all — and nobody in the building
     * could explain it, because there would be nothing left on the record to
     * explain it with.
     */
    public function testTakingTheVoucherOffTheLineClearsTheReduction(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'OFF-15', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '15'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'Consulting', 'quantity' => '3', 'unit_price' => '66.65', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                OrderModule::QUANTITY => '3',
                OrderModule::UNIT_PRICE => '66.65',
                OrderModule::TAX_RATE => self::STANDARD,
                OrderModule::LINE_VOUCHER => '',
            ], (int) $rows[0]->id),
        ]);

        self::assertSame([['Consulting', '3.00', '66.65', '', '199.95']], $this->linesOf($order));
        self::assertSame(0, $this->redeemed($voucher), 'and the use goes back with it');
    }

    /** Editing a discounted order does not discount it twice. */
    public function testEditingAReducedLineDoesNotCompoundTheReduction(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'TENTH-LINE', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        for ($save = 0; $save < 3; ++$save) {
            $rows = $this->rowsOf($order, OrderModule::LINES);

            $this->saveOrder($order, [
                self::row([
                    OrderModule::KIND => OrderModule::CUSTOM_LINE,
                    'description' => 'Consulting',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => '100.00',
                    OrderModule::TAX_RATE => self::STANDARD,
                    OrderModule::LINE_VOUCHER => (string) $voucher,
                ], (int) $rows[0]->id),
            ]);

            self::assertSame(
                [['Consulting', '1.00', '100.00', '10.00', '90.00']],
                $this->linesOf($order),
                'after save ' . $save,
            );
        }

        self::assertSame(1, $this->redeemed($voucher), 'and three saves are still one document');
    }
    // -- the restriction ------------------------------------------------------

    /** A restricted voucher works on a line carrying the article it names. */
    public function testARestrictedVoucherWorksOnALineForThatArticle(): void
    {
        $article = $this->anArticle('Travel mug', '24.00');

        $voucher = $this->aVoucher([
            'code' => 'MUGS-HALF',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '50',
            'article' => (string) $article,
        ], VoucherModule::LINE_PERCENTAGE);

        $order = $this->anOrder([[
            OrderModule::KIND => OrderModule::ARTICLE_LINE,
            'article' => (string) $article,
            'description' => 'Travel mug',
            'quantity' => '3',
            'unit_price' => '24.00',
            'tax_rate' => self::STANDARD,
            OrderModule::LINE_VOUCHER => (string) $voucher,
        ]]);

        self::assertSame([['Travel mug', '3.00', '24.00', '36.00', '36.00']], $this->linesOf($order));
    }

    /**
     * And it is refused on a line for anything else, by name.
     *
     * "This voucher cannot be used here" would leave whoever is holding it with
     * nowhere to go; naming the article says what to do next.
     */
    public function testARestrictedVoucherIsRefusedOnALineForSomethingElse(): void
    {
        $mug = $this->anArticle('Travel mug', '24.00');
        $lamp = $this->anArticle('Desk lamp', '80.00');

        $voucher = $this->aVoucher([
            'code' => 'MUGS-ONLY',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '50',
            'article' => (string) $mug,
        ], VoucherModule::LINE_PERCENTAGE);

        $response = $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => '',
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::ARTICLE_LINE,
            'article' => (string) $lamp,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '80.00',
            OrderModule::TAX_RATE => self::STANDARD,
            OrderModule::LINE_VOUCHER => (string) $voucher,
        ])]]);

        self::assertStringContainsString('Travel mug', (string) $response->getContent());
        self::assertSame(0, $this->redeemed($voucher));
    }

    /** An unrestricted one goes anywhere, which is what makes a custom line reachable. */
    public function testAnUnrestrictedVoucherGoesOnALineForAnyArticle(): void
    {
        $lamp = $this->anArticle('Desk lamp', '80.00');

        $voucher = $this->aVoucher(
            ['code' => 'ANY-LINE', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '25'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([[
            OrderModule::KIND => OrderModule::ARTICLE_LINE,
            'article' => (string) $lamp,
            'description' => 'Desk lamp',
            'quantity' => '1',
            'unit_price' => '80.00',
            'tax_rate' => self::STANDARD,
            OrderModule::LINE_VOUCHER => (string) $voucher,
        ]]);

        self::assertSame([['Desk lamp', '1.00', '80.00', '20.00', '60.00']], $this->linesOf($order));
    }
    // -- both modes on one document -----------------------------------------

    /**
     * **Both kinds of discount on one order**, and the order voucher is worth a
     * share of what is left after the line one.
     *
     * The line is reduced by a tenth — 20.00 off 200.00 — and the `GIVE-10` off the
     * document then comes off 180.00 + 100.00 = 280.00 rather than off 300.00. It
     * is an absolute voucher here, so the ordering does not move its figure, and
     * what it does move is the **cap**: the two together can never come to more
     * than the document charges, which is the property that makes the ordering
     * worth deciding at all.
     */
    public function testAnOrderVoucherAndALineVoucherOnTheSameDocument(): void
    {
        $line = $this->aVoucher(
            ['code' => 'TENTH-LINE-2', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );
        $whole = $this->aVoucher(
            ['code' => 'GIVE-10-2', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'],
            VoucherModule::ORDER_AMOUNT,
        );

        $order = $this->anOrder([
            ['description' => 'Chair', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $line],
            ['description' => 'Desk', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ], $whole);

        self::assertSame(
            [['Chair', '1.00', '200.00', '20.00', '180.00'],
                ['Desk', '1.00', '100.00', '', '100.00'],
                ['GIVE-10-2', '1.00', '-10.00', '', '-10.00']],
            $this->linesOf($order),
            'the reduced line, the untouched one, and the order voucher as a line of its own',
        );

        self::assertSame('270.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
        self::assertSame(1, $this->redeemed($line));
        self::assertSame(1, $this->redeemed($whole));
    }

    /**
     * And a percentage off the whole order is a percentage of what is left, which
     * is the half the ordering actually decides.
     *
     * A tenth off the line takes 20.00 from 200.00; a tenth off the order is then
     * a tenth of 280.00 — 28.00 — rather than a tenth of the 300.00 nobody is
     * being charged.
     */
    public function testAnOrderPercentageComesOffWhatTheLineDiscountLeft(): void
    {
        $line = $this->aVoucher(
            ['code' => 'TENTH-LINE-3', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );
        $whole = $this->aVoucher(
            ['code' => 'TENTH-ORDER', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::ORDER_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'Chair', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $line],
            ['description' => 'Desk', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ], $whole);

        self::assertSame(
            ['TENTH-ORDER', '-28.00'],
            [$this->linesOf($order)[2][0], $this->linesOf($order)[2][2]],
            'a tenth of 280, not a tenth of 300',
        );

        self::assertSame('252.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
    }

    // -- and it survives being billed ---------------------------------------

    /**
     * **An invoice seeded from a discounted order carries both kinds of
     * discount** (§5.12).
     *
     * The order voucher's line is copied as a row, which is [XIV-104]'s answer and
     * needed nothing new. The line voucher's reduction is copied as a **column**,
     * which did: without it the bill would charge the undiscounted line, and it
     * would do so with figures that added up perfectly.
     */
    public function testAnInvoiceSeededFromAReducedOrderCarriesBothDiscounts(): void
    {
        $line = $this->aVoucher(
            ['code' => 'TENTH-LINE-4', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );
        $whole = $this->aVoucher(
            ['code' => 'GIVE-10-3', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'],
            VoucherModule::ORDER_AMOUNT,
        );

        $order = $this->anOrder([
            ['description' => 'Chair', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $line],
            ['description' => 'Desk', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ], $whole);

        $invoice = $this->invoiceOf($order);

        self::assertSame(
            [['custom', 'Chair', '20.00', '180.00'],
                ['custom', 'Desk', '', '100.00'],
                ['discount', 'GIVE-10-3', '', '-10.00']],
            array_map(
                static fn (Record $row): array => [
                    (string) $row->get(InvoiceModule::KIND),
                    (string) $row->get(InvoiceModule::DESCRIPTION),
                    (string) $row->get(InvoiceModule::LINE_DISCOUNT),
                    (string) $row->get(InvoiceModule::LINE_TOTAL),
                ],
                $this->rowsOf($invoice, InvoiceModule::LINES, InvoiceModule::KEY),
            ),
            'the reduction as a column and the order voucher as a row',
        );

        self::assertSame('270.00', $this->invoiceRecord($invoice)->get(InvoiceModule::NET_TOTAL));
        self::assertSame(
            1,
            $this->redeemed($line),
            'and billing it takes no second use — an invoice names no voucher anywhere',
        );
    }
    // -- helpers ------------------------------------------------------------

    // -- helpers ------------------------------------------------------------

    /**
     * The awkward one: two rates, three lines, and only one of the two lines at
     * 8.1% discounted.
     */
    private function aMixedRateOrder(): int
    {
        $voucher = $this->aVoucher(
            ['code' => 'FIFTEEN', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '15'],
            VoucherModule::LINE_PERCENTAGE,
        );

        return $this->anOrder([
            ['description' => 'Consulting', 'quantity' => '3', 'unit_price' => '66.65', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Support', 'quantity' => '1', 'unit_price' => '55.55', 'tax_rate' => self::STANDARD],
            ['description' => 'Manual', 'quantity' => '1', 'unit_price' => '33.33', 'tax_rate' => self::REDUCED],
        ]);
    }

    /**
     * An order, whose lines are custom unless a row says otherwise.
     *
     * @param list<array<string, string>> $lines
     */
    private function anOrder(array $lines, ?int $voucher = null): int
    {
        $rows = [];

        foreach ($lines as $values) {
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
     * Save an existing order with the given lines, as the record form does.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function saveOrder(int $order, array $rows): Response
    {
        $record = $this->orderRecord($order);

        return $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $record->get('contact'),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) ($record->get(OrderModule::VOUCHER) ?? ''),
        ], [OrderModule::LINES => $rows], $order);
    }

    /**
     * Bill an order, through the button on its page and the form that comes back
     * filled in. Lifted from `OrderVoucherTest`, whose comment explains the two
     * subtleties.
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
    private function aVoucher(array $fields, string $variant): int
    {
        return $this->savedId($this->saveRecord(VoucherModule::KEY, $fields, variant: $variant));
    }

    private function anArticle(string $title, string $price): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            ArticleModule::KIND => ArticleModule::PLAIN,
            'title' => $title,
            'price' => $price,
            'tax_rate' => self::STANDARD,
        ], variant: ArticleModule::PLAIN));
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
     * The lines as stored, in the columns this ticket is about: what it says, how
     * many at what each, what came off it, and what is left.
     *
     * The discount and the total are read together on purpose — a reduction and a
     * line total that disagree is the one failure this feature can have that still
     * looks plausible on the page.
     *
     * @return list<array{string, string, string, string, string}>
     */
    private function linesOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get('description'),
                (string) $row->get(OrderModule::QUANTITY),
                (string) $row->get(OrderModule::UNIT_PRICE),
                (string) $row->get(OrderModule::LINE_DISCOUNT),
                (string) $row->get(OrderModule::LINE_TOTAL),
            ],
            $this->rowsOf($order, OrderModule::LINES),
        );
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

    private function recordOf(string $module, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): Record {
            $definition = self::service(MetadataRepository::class)->get($module);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
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

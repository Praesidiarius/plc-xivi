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

namespace App\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * The order module's voucher pickers name the voucher module's own kinds
 * (XIV-172).
 *
 * **The one thing a string key cannot say for itself.** §3 forbids a module from
 * importing another module, so `OrderModule` names the voucher module by the key
 * `'voucher'` and its kinds by the keys `'order_amount'`, `'line_amount'` and
 * the rest. That is the same arrangement, and the same exposure, as every
 * cross-module reference in this codebase. What is new is that these keys now *decide
 * something*: they narrow two pickers, so a kind renamed in `VoucherModule` and
 * not here would not be a broken import, it would be a picker that silently
 * offers nothing at all, on a form that otherwise looks perfectly well.
 *
 * `tests/` is the layer allowed to see both modules, since deptrac's rule is
 * about `packages/` and the application above them is what wires them together,
 * so the check lives here rather than in either module. It is a unit test because
 * it is a question about two class constants and needs no database to answer.
 *
 * The blueprints are read rather than the constants alone, because the constants
 * being right and the fields not using them would be the same defect wearing a
 * green test.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderNamesTheVoucherKindsTest extends TestCase
{
    /** The picker on the document offers exactly the kinds a document takes. */
    public function testTheDocumentPickerNamesTheOrderKinds(): void
    {
        self::assertSame(
            VoucherModule::ORDER_KINDS,
            self::variantsOf($this->documentVoucherField()),
            'the header voucher field narrows to VoucherModule::ORDER_KINDS',
        );
    }

    /** And the one on a line offers exactly the kinds a line takes. */
    public function testTheLinePickerNamesTheLineKinds(): void
    {
        self::assertSame(
            VoucherModule::LINE_KINDS,
            self::variantsOf($this->lineVoucherField()),
            'the line voucher field narrows to VoucherModule::LINE_KINDS',
        );
    }

    /**
     * And between them they name every kind there is, once.
     *
     * A fifth kind added to the voucher module would belong to one of the two
     * places a voucher can go, and nothing else in the codebase would ask which.
     * This is where somebody finds out.
     */
    public function testEveryVoucherKindBelongsToExactlyOneOfThem(): void
    {
        $named = [...self::variantsOf($this->documentVoucherField()), ...self::variantsOf($this->lineVoucherField())];

        self::assertSame($named, array_unique($named), 'no kind is offered in both places');
        self::assertEqualsCanonicalizing(
            [...VoucherModule::ORDER_KINDS, ...VoucherModule::LINE_KINDS],
            $named,
            'and none of them is offered nowhere',
        );
    }

    /** @return list<string> */
    private static function variantsOf(FieldBlueprint $field): array
    {
        $variants = $field->options[ReferenceFieldType::VARIANT] ?? null;

        self::assertIsArray($variants, 'the narrowing is a list of kinds');

        return array_values($variants);
    }

    private function documentVoucherField(): FieldBlueprint
    {
        foreach ((new OrderModule())->blueprint()->fields as $field) {
            if ($field->key === OrderModule::VOUCHER) {
                return $field;
            }
        }

        self::fail('the order has a voucher field on the document');
    }

    private function lineVoucherField(): FieldBlueprint
    {
        foreach ((new OrderModule())->blueprint()->collections as $collection) {
            \assert($collection instanceof CollectionBlueprint);

            if ($collection->key !== OrderModule::LINES) {
                continue;
            }

            foreach ($collection->fields as $field) {
                if ($field->key === OrderModule::LINE_VOUCHER) {
                    return $field;
                }
            }
        }

        self::fail('an order line has a voucher field');
    }
}

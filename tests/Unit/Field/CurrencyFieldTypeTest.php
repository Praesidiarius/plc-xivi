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

namespace App\Tests\Unit\Field;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\CurrencyFieldType;
use Xivi\Core\Money\InstanceCurrency;

/**
 * What a price is, once it is stored (XIV-11).
 *
 * A unit test because the interesting part is arithmetic rather than plumbing:
 * money that goes through a float loses a hundredth of a cent somewhere, and the
 * place that shows up is an invoice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CurrencyFieldTypeTest extends TestCase
{
    public function testAnAmountIsStoredAsADecimalStringAtTwoPlaces(): void
    {
        $type = $this->type();
        $field = $this->field();

        self::assertSame('19.90', $type->toStorage('19.9', $field));
        self::assertSame('19.90', $type->toStorage(19.9, $field));
        self::assertSame('0.00', $type->toStorage('0', $field));
        self::assertSame('1234.50', $type->toStorage('1234.5', $field));
    }

    /** "19.9" and "19.90" are one price, and one stored value. */
    public function testTwoWaysOfWritingThePriceStoreTheSame(): void
    {
        $type = $this->type();
        $field = $this->field();

        self::assertSame($type->toStorage('19.9', $field), $type->toStorage('19.90', $field));
    }

    public function testEmptyIsNoPriceRatherThanZero(): void
    {
        $type = $this->type();

        self::assertNull($type->toStorage('', $this->field()));
        self::assertNull($type->toStorage(null, $this->field()));
    }

    /**
     * Refusing it is the validator's job. Casting "12abc" here would store a
     * price nobody typed and call the record valid.
     */
    public function testSomethingThatIsNotANumberIsLeftAloneForTheValidator(): void
    {
        self::assertSame('12abc', $this->type()->toStorage('12abc', $this->field()));
    }

    /** Read back as the string it was stored as, never as a float. */
    public function testItComesBackAsAString(): void
    {
        self::assertSame('19.90', $this->type()->fromStorage('19.90', $this->field()));
        self::assertNull($this->type()->fromStorage(null, $this->field()));
    }

    /** With no currency chosen it is a number, which is all it can honestly be. */
    public function testWithNoCurrencyItReadsAsAPlainAmount(): void
    {
        self::assertSame('19.90', $this->type(null)->display('19.90', $this->field()));
    }

    public function testWithACurrencyItIsNamedInIt(): void
    {
        $shown = $this->type('CHF')->display('19.90', $this->field());

        self::assertStringContainsString('19', $shown);
        self::assertStringContainsString('CHF', $shown);
    }

    /** 100 sorts after 9, which a text comparison would get backwards. */
    public function testItComparesAsANumber(): void
    {
        self::assertSame("(data->>'price')::numeric", $this->type()->comparableSql("data->>'price'"));
    }

    private function type(?string $currency = null): CurrencyFieldType
    {
        return new CurrencyFieldType(new class($currency) implements InstanceCurrency {
            public function __construct(private readonly ?string $code)
            {
            }

            public function code(): ?string
            {
                return $this->code;
            }
        });
    }

    /** A real definition rather than a mock: the type reads its options. */
    private function field(): FieldDefinition
    {
        return new FieldDefinition(
            new ModuleDefinition('article', 'Articles', 'article'),
            'price',
            'Price',
            'currency',
        );
    }
}

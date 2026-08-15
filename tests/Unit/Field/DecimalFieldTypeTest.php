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
use Xivi\Core\Field\Type\DecimalFieldType;

/**
 * A number with a fraction, and what happens to it in storage (XIV-22).
 *
 * A unit test because the interesting part is arithmetic: a quantity is usually
 * one side of a multiplication, so an error here does not stay a quantity error.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DecimalFieldTypeTest extends TestCase
{
    public function testItIsStoredAsAStringAtTheFieldsOwnScale(): void
    {
        $type = new DecimalFieldType();

        self::assertSame('2.50', $type->toStorage('2.5', $this->field()));
        self::assertSame('2.50', $type->toStorage(2.5, $this->field()));
        self::assertSame('0.00', $type->toStorage('0', $this->field()));
    }

    public function testTheScaleIsTheFieldsToChoose(): void
    {
        $type = new DecimalFieldType();

        self::assertSame('2.500', $type->toStorage('2.5', $this->field(['scale' => 3])));
        self::assertSame('3', $type->toStorage('2.5', $this->field(['scale' => 0])), 'and rounds to it');
    }

    /**
     * A scale outside what storage promises is brought inside it rather than
     * refused: a definition asking for forty places wants "lots", and one asking
     * for minus three wants none.
     */
    public function testAnAbsurdScaleIsClampedRatherThanObeyed(): void
    {
        $type = new DecimalFieldType();

        self::assertSame('3', $type->toStorage('2.5', $this->field(['scale' => -3])));
        self::assertSame('2.500000', $type->toStorage('2.5', $this->field(['scale' => 40])));
    }

    public function testEmptyIsNoNumberRatherThanZero(): void
    {
        $type = new DecimalFieldType();

        self::assertNull($type->toStorage('', $this->field()));
        self::assertNull($type->toStorage(null, $this->field()));
    }

    /** Refusing it is the validator's job; casting would store what nobody typed. */
    public function testSomethingThatIsNotANumberIsLeftAlone(): void
    {
        self::assertSame('3 boxes', (new DecimalFieldType())->toStorage('3 boxes', $this->field()));
    }

    public function testItComesBackAsAString(): void
    {
        self::assertSame('2.50', (new DecimalFieldType())->fromStorage('2.50', $this->field()));
    }

    /** 10 sorts after 9, which a text comparison would get backwards. */
    public function testItComparesAsANumber(): void
    {
        self::assertSame(
            "(data->>'quantity')::numeric",
            (new DecimalFieldType())->comparableSql("data->>'quantity'"),
        );
    }

    /** @param array<string, mixed> $options */
    private function field(array $options = []): FieldDefinition
    {
        $field = new FieldDefinition(
            new ModuleDefinition('order', 'Orders', 'sales_order'),
            'quantity',
            'Quantity',
            'decimal',
        );
        $field->setOptions($options);

        return $field;
    }
}

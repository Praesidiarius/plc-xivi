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
use Xivi\Article\ArticleModule;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * Every field that points at an article offers the kinds an article can be sold
 * as, and never the base ([XIV-133]).
 *
 * **The same exposure {@see OrderNamesTheVoucherKindsTest} exists for, one
 * catalogue further along.** §3 forbids a module importing a module, so the
 * order, the invoice and the voucher each write `'plain'` and `'sku'` out as
 * strings rather than reading `ArticleModule::SELLABLE`. Those strings now
 * *decide* something, they narrow three pickers, so a kind renamed in the
 * article module and not in the other three would not fail to compile, it would
 * be a picker that silently offers nothing at all on a form that otherwise looks
 * perfectly well. `tests/` is the layer allowed to see all four packages, so this
 * is where somebody finds out.
 *
 * The blueprints are read rather than the constants alone: a constant that is
 * right beside a field that does not use it is the same defect wearing a green
 * test.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SellersNameTheArticleKindsTest extends TestCase
{
    /**
     * A base is not on the list, which is the decision this whole test protects.
     *
     * An article sold in three sizes is not itself a sellable thing, so a line
     * naming it would be an order nobody can fulfil ([XIV-133]).
     */
    public function testABaseIsNotSellable(): void
    {
        self::assertNotContains(ArticleModule::BASE, ArticleModule::SELLABLE);
        self::assertSame([ArticleModule::PLAIN, ArticleModule::SKU], ArticleModule::SELLABLE);
    }

    /** And the kinds it names are kinds the article module actually has. */
    public function testTheSellableKindsAreRealKinds(): void
    {
        self::assertSame(
            [ArticleModule::PLAIN, ArticleModule::BASE, ArticleModule::SKU],
            array_keys(self::kindChoices()),
            'the three kinds, in the order the form offers them',
        );
    }

    /** An order line sells one of those and nothing else. */
    public function testTheOrderLineNarrowsToTheSellableKinds(): void
    {
        self::assertSame(
            ArticleModule::SELLABLE,
            self::variantsOf(self::rowField((new OrderModule())->blueprint(), OrderModule::LINES, 'article')),
        );
    }

    /** So does an invoice line written from nothing. */
    public function testTheInvoiceLineNarrowsToTheSellableKinds(): void
    {
        self::assertSame(
            ArticleModule::SELLABLE,
            self::variantsOf(self::rowField((new InvoiceModule())->blueprint(), InvoiceModule::LINES, InvoiceModule::ARTICLE)),
        );
    }

    /**
     * And so does a voucher's restriction, for a reason of its own: a voucher
     * restricted to a base could never match a line, since no line may name one.
     */
    public function testTheVoucherRestrictionNarrowsToTheSellableKinds(): void
    {
        self::assertSame(
            ArticleModule::SELLABLE,
            self::variantsOf(self::moduleField((new VoucherModule())->blueprint(), VoucherModule::ARTICLE)),
        );
    }

    /**
     * The link an SKU holds points the other way, at bases only.
     *
     * Which stops two things at once: an SKU of a plain article, leaving the
     * plain one sellable beside its own variants, and an SKU of an SKU, so
     * chains are impossible rather than merely discouraged.
     */
    public function testAnSkuMayOnlyBeAVariantOfABase(): void
    {
        $field = self::moduleField((new ArticleModule())->blueprint(), ArticleModule::SKU_OF);

        self::assertSame([ArticleModule::BASE], self::variantsOf($field));
        self::assertSame(ArticleModule::KEY, $field->options[ReferenceFieldType::MODULE] ?? null);
        self::assertSame([ArticleModule::SKU], $field->variants, 'and only an SKU carries it');
    }

    /** @return array<string, string> */
    private static function kindChoices(): array
    {
        $choices = self::moduleField((new ArticleModule())->blueprint(), ArticleModule::KIND)->options['choices'] ?? null;

        self::assertIsArray($choices);

        /* @var array<string, string> $choices */
        return $choices;
    }

    /** @return list<string> */
    private static function variantsOf(FieldBlueprint $field): array
    {
        $variants = $field->options[ReferenceFieldType::VARIANT] ?? null;

        self::assertIsArray($variants, 'the narrowing is a list of kinds');

        return array_values($variants);
    }

    private static function moduleField(ModuleBlueprint $blueprint, string $key): FieldBlueprint
    {
        foreach ($blueprint->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        self::fail(sprintf('%s has a field "%s"', $blueprint->key, $key));
    }

    private static function rowField(ModuleBlueprint $blueprint, string $collection, string $key): FieldBlueprint
    {
        foreach ($blueprint->collections as $one) {
            \assert($one instanceof CollectionBlueprint);

            if ($one->key !== $collection) {
                continue;
            }

            foreach ($one->fields as $field) {
                if ($field->key === $key) {
                    return $field;
                }
            }
        }

        self::fail(sprintf('%s.%s has a field "%s"', $blueprint->key, $collection, $key));
    }
}

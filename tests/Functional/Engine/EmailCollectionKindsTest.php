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
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Mail\EmailRenderer;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Mail\RenderedEmail;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordRepository;
use Xivi\Order\OrderModule;

/**
 * What a rendered collection does with rows that are not all the same thing
 * (XIV-62).
 *
 * The counterpart to {@see RepeatingBlockTest}'s kind cases, and the point of
 * having both is that the two answers are deliberately different. In Word the
 * template lays out a row per kind and the engine picks between them, because
 * there the layout is the deliverable and choosing it for somebody is the one
 * thing a template exists to stop. In an email the shape ships in code, so there
 * is nothing to lay out and the engine has to answer for itself.
 *
 * It answers: **one table, every row, in the collection's own order**. Order
 * lines are the module that makes the question real — an order line is an
 * article, a custom line, a comment or a subtotal, and only some of those carry
 * money — so the interesting cases all live here rather than on Contact, whose
 * addresses are all the same thing.
 *
 * The contact test file has the safety and formatting half of this ticket. This
 * one has the decision.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EmailCollectionKindsTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_email_kinds';
    private const string HOST = 'email-kinds.localhost';
    private const string EMAIL = 'kinds@example.test';
    private const string PASSWORD = 'kinds-password';

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

            // Article too, because an order line of the article kind cannot be
            // added without it (XIV-23) and the mixed order below needs all four
            // kinds to be offerable.
            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Kinds', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * Every kind of line lands in the same table, in the order the order holds
     * them.
     *
     * The alternative — a table per kind — was rejected on §5.11's own argument:
     * a comment sits *between* two priced lines and means nothing anywhere else,
     * so grouping by kind sorts the invoice by kind and changes what it says.
     */
    public function testEveryKindOfRowLandsInOneTableInTheirOwnOrder(): void
    {
        $text = $this->render("What you ordered:\n\n[lines]", [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Everything below is optional']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
        ])->text;

        self::assertStringContainsString('Consulting', $text);
        self::assertStringContainsString('Everything below is optional', $text, 'a comment line is a line');
        self::assertLessThan(strpos($text, 'Travel') ?: 0, strpos($text, 'Everything below') ?: 0);
        self::assertLessThan(strpos($text, 'Everything below') ?: 0, strpos($text, 'Consulting') ?: 0);
    }

    /**
     * The columns are the union, and a row whose kind carries none of a column
     * leaves it empty.
     *
     * Which is what a printed invoice looks like. The intersection would have
     * been the safe-sounding choice and is a one-column table on this module —
     * `description` is the only field every kind of line has — so an order
     * confirmation would have listed what was bought and none of the money.
     */
    public function testARowThatHasNothingForAColumnLeavesItEmpty(): void
    {
        $text = $this->render('[lines.description,quantity,line_total]', [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Please pay within 30 days']],
        ])->text;

        self::assertMatchesRegularExpression('/\| Consulting \| 2[.,]00 \| .*300[.,]00 \|/', $text);
        self::assertStringContainsString('| Please pay within 30 days |  |  |', $text, 'empty cells, not a missing row');
    }

    /**
     * A line the table would have listed is never silently dropped.
     *
     * The third candidate for the kinds question was "the default kind, and name
     * the rest explicitly", and it is the only one of the three that can be
     * *wrong* rather than merely plain: an order confirmation listing four of six
     * lines is a document that says something untrue about the order.
     */
    public function testNoLineIsLeftOutOfTheDefaultTable(): void
    {
        $text = $this->render('[lines]', [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'A note']],
            [OrderModule::SUBTOTAL_LINE, ['description' => 'Subtotal']],
        ])->text;

        foreach (['Consulting', 'A note', 'Subtotal'] as $line) {
            self::assertStringContainsString($line, $text);
        }
    }

    /** And naming a kind is the escape hatch, with the colon meaning what it means in Word. */
    public function testNamingAKindDrawsOnlyThatKind(): void
    {
        $text = $this->render('[lines:comment]', [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Delivery is next week']],
        ])->text;

        self::assertStringContainsString('Delivery is next week', $text);
        self::assertStringNotContainsString('Consulting', $text);
    }

    /**
     * The field that says which kind a row is does not become a column.
     *
     * It is the discriminator rather than something the customer wrote, and a
     * column reading "Custom, Comment, Custom" beside rows that already look
     * different is noise.
     */
    public function testTheKindFieldIsNotAColumn(): void
    {
        $rendered = $this->render('[lines]', [
            [OrderModule::COMMENT_LINE, ['description' => 'A note']],
        ]);

        self::assertStringNotContainsString('<th>Kind</th>', $rendered->html);
    }

    /**
     * Nor does a field another field is copied out of (XIV-18).
     *
     * An order line's description is inherited from the article it names, so a
     * table with both prints the same words twice under two headings. This is
     * the one place the default set is narrowed on purpose, and it is read off
     * the metadata rather than guessed at.
     */
    public function testAFieldAnotherIsCopiedFromIsNotAColumn(): void
    {
        $rendered = $this->render('[lines]', [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '10.00']],
        ]);

        self::assertStringNotContainsString('<th>Article</th>', $rendered->html);
        self::assertStringContainsString('<th>Description</th>', $rendered->html);
    }

    /**
     * Money reads as money, because a cell goes through the field type (§5.7).
     *
     * Thousands separated is what makes this a real check rather than a look at
     * the stored string: `display()` groups (XIV-47) and the column is derived,
     * so a table printing `1500.00` would be one reading the database instead of
     * asking the field what it is worth.
     */
    public function testValuesAreRenderedThroughTheirFieldType(): void
    {
        $text = $this->render('[lines.description,line_total]', [
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1500.00']],
        ])->text;

        self::assertStringNotContainsString('1500.00', $text, 'not the stored string');
        self::assertMatchesRegularExpression('/1[\'’,. ]500[.,]00/u', $text);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * One order, one template, rendered.
     *
     * @param list<array{0: string, 1: array<string, string>}> $lines
     */
    private function render(string $body, array $lines): RenderedEmail
    {
        $order = $this->anOrder($lines);

        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($body, $order): RenderedEmail {
                $templates = self::service(EmailTemplateRepository::class);
                $templates->save(new \Xivi\Core\Entity\EmailTemplate(
                    OrderModule::KEY,
                    'Confirmation',
                    'Your order',
                    $body,
                ));

                $written = $templates->forModule(OrderModule::KEY);
                $template = end($written);
                self::assertNotFalse($template);

                $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
                $record = self::service(RecordRepository::class)->find($module, $order);
                self::assertNotNull($record);

                return self::service(EmailRenderer::class)->render($template, $module, $record);
            },
        );
    }

    /** @param list<array{0: string, 1: array<string, string>}> $lines */
    private function anOrder(array $lines): int
    {
        $rows = [];

        foreach ($lines as [$kind, $values]) {
            $rows[] = self::row([OrderModule::KIND => $kind, ...$values]);
        }

        return $this->savedId($this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $this->aCompany(),
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
            ],
            [OrderModule::LINES => $rows],
        ));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));
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

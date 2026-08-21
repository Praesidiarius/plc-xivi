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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Article\ArticleModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Voucher\Code\VoucherCode;
use Xivi\Voucher\VoucherModule;

/**
 * A voucher exists, is called something, and is worth one of four things
 * (XIV-103).
 *
 * The module half of the ticket. What is not here is the counter, which has a
 * class of its own because a race cannot be tested inside DAMA's transaction —
 * see {@see VoucherRedemptionRaceTest}.
 *
 * Like every module test before it, the point is partly what is *absent*: no
 * controller, no form type and no template were written for vouchers, so every
 * page below is the same generic pair that serves contacts and articles, reading
 * a different set of definitions. The one thing this module added to the engine
 * is a field type, and it is a field type by the same rule every other one is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherModuleTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_voucher';
    private const string HOST = 'voucher.localhost';
    private const string EMAIL = 'voucher@example.test';
    private const string PASSWORD = 'voucher-password';
    private const string FORM = 'module_record';

    /**
     * The four the module ships, in the order the blueprint lists them
     * (XIV-122).
     *
     * @var list<string>
     */
    private const array KINDS = [
        VoucherModule::ORDER_AMOUNT,
        VoucherModule::ORDER_PERCENTAGE,
        VoucherModule::LINE_AMOUNT,
        VoucherModule::LINE_PERCENTAGE,
    ];

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

            // Articles first, because the free-article kind is only offered to a
            // customer who has them — which is itself one of the things under
            // test, from the other side, in the class below.
            $installer->install($registry->get(ArticleModule::KEY));
            $installer->install($registry->get(VoucherModule::KEY));
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Voucher', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the kind is a variant ----------------------------------------------

    /**
     * Adding a voucher asks which kind first, because the fields depend on the
     * answer (§5.5).
     */
    public function testAddingAVoucherAsksWhichKindFirst(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/voucher/new'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What kind');
        self::assertCount(4, $crawler->filter('a[href*="variant="]'), 'one link per kind');

        foreach (self::KINDS as $kind) {
            self::assertStringContainsString('variant=' . $kind, $crawler->html());
        }
    }

    /**
     * And the fields offered are the kind's own. This is the assertion that says
     * "variant" rather than "one shape with four nullable columns".
     */
    public function testEachKindIsAskedForItsOwnFieldsAndNobodyElses(): void
    {
        $order = $this->client->request('GET', $this->url('/m/voucher/new?variant=' . VoucherModule::ORDER_AMOUNT));

        self::assertSelectorExists(self::selector(VoucherModule::AMOUNT));
        self::assertCount(0, $order->filter(self::selector(VoucherModule::PERCENTAGE)));
        // **The combination that does not exist** (XIV-122): an order voucher
        // restricted to an article. It is refused by the article field being
        // declared on the two line variants and no others, which is §5.5 doing
        // the work a validation rule would otherwise have to.
        self::assertCount(0, $order->filter(self::selector(VoucherModule::ARTICLE)));

        $relative = $this->client->request(
            'GET',
            $this->url('/m/voucher/new?variant=' . VoucherModule::ORDER_PERCENTAGE),
        );

        self::assertSelectorExists(self::selector(VoucherModule::PERCENTAGE));
        self::assertCount(0, $relative->filter(self::selector(VoucherModule::AMOUNT)));
        self::assertCount(0, $relative->filter(self::selector(VoucherModule::ARTICLE)));

        $line = $this->client->request('GET', $this->url('/m/voucher/new?variant=' . VoucherModule::LINE_PERCENTAGE));

        self::assertSelectorExists(self::selector(VoucherModule::PERCENTAGE));
        self::assertSelectorExists(self::selector(VoucherModule::ARTICLE));
        self::assertCount(0, $line->filter(self::selector(VoucherModule::AMOUNT)));
    }

    /** What every kind shares: its code, its dates and its limit. */
    public function testTheFieldsEveryKindSharesAreOnAllOfThem(): void
    {
        foreach (self::KINDS as $kind) {
            $this->client->request('GET', $this->url('/m/voucher/new?variant=' . $kind));

            foreach ([VoucherModule::CODE, VoucherModule::VALID_FROM, VoucherModule::VALID_UNTIL, VoucherModule::MAX_REDEMPTIONS] as $field) {
                self::assertSelectorExists(self::selector($field), sprintf('%s is on the %s form', $field, $kind));
            }
        }
    }

    /**
     * The four kinds save, each with the field only it has — and the fourth is
     * what a free article became (XIV-122).
     *
     * `FREE-CUP` is the interesting row: [XIV-103] shipped it as a kind of its
     * own, and it is now a line voucher restricted to that article at a hundred
     * percent, said entirely in the vocabulary the other three already used.
     */
    public function testAllFourKindsSave(): void
    {
        $article = $this->savedId($this->saveRecord(
            ArticleModule::KEY,
            [ArticleModule::KIND => ArticleModule::PLAIN, 'title' => 'Coffee', 'price' => '4.50'],
            variant: ArticleModule::PLAIN,
        ));

        $this->submit(
            ['code' => 'TEN-OFF', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'],
            VoucherModule::ORDER_AMOUNT,
        );
        $this->submit(
            ['code' => 'HALF', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '50'],
            VoucherModule::ORDER_PERCENTAGE,
        );
        $this->submit(
            ['code' => 'FIVE-OFF-ONE', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '5.00'],
            VoucherModule::LINE_AMOUNT,
        );
        $this->submit(
            [
                'code' => 'FREE-CUP',
                'kind' => VoucherModule::LINE_PERCENTAGE,
                'percentage' => '100',
                'article' => (string) $article,
            ],
            VoucherModule::LINE_PERCENTAGE,
        );

        self::assertSame(4, $this->countVouchers());
        self::assertSame('10.00', $this->stored('TEN-OFF')[VoucherModule::AMOUNT] ?? null);
        self::assertSame('50.00', $this->stored('HALF')[VoucherModule::PERCENTAGE] ?? null);
        self::assertSame('5.00', $this->stored('FIVE-OFF-ONE')[VoucherModule::AMOUNT] ?? null);
        self::assertSame('100.00', $this->stored('FREE-CUP')[VoucherModule::PERCENTAGE] ?? null);
        self::assertSame($article, $this->stored('FREE-CUP')[VoucherModule::ARTICLE] ?? null);
    }

    // -- the code -----------------------------------------------------------

    /** A code the customer typed is the code they get, in capitals. */
    public function testATypedCodeIsKeptAndFolded(): void
    {
        $this->submit(['code' => 'give-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'], VoucherModule::ORDER_AMOUNT);

        self::assertSame('GIVE-10', $this->stored('GIVE-10')[VoucherModule::CODE] ?? null);
    }

    /**
     * Leaving the box empty is how somebody asks for a generated code, and the
     * one that comes back is in the generator's alphabet.
     */
    public function testAnEmptyCodeBoxProducesAGeneratedCode(): void
    {
        $this->submit(['code' => '', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'], VoucherModule::ORDER_PERCENTAGE);

        $codes = $this->codes();

        self::assertCount(1, $codes);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codes[0]);
        self::assertTrue(VoucherCode::isWellFormed($codes[0]));

        foreach (['0', '1', 'I', 'L', 'O', 'U'] as $confusable) {
            self::assertStringNotContainsString($confusable, $codes[0]);
        }
    }

    /**
     * And a code that exists is not touched by a later save.
     *
     * The rule §5.10 states about a document number, applied to a value somebody
     * may also have typed: assigned once, never restated. Without it every edit
     * of a voucher would reprint the flyer.
     */
    public function testEditingAVoucherDoesNotChangeItsCode(): void
    {
        $id = $this->savedId($this->submit(['code' => '', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'], VoucherModule::ORDER_PERCENTAGE));
        $first = $this->codes()[0];

        $this->saveRecord(VoucherModule::KEY, ['code' => $first, 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '20'], recordId: $id);

        self::assertSame([$first], $this->codes(), 'the same code after an edit');
    }

    /**
     * **The duplicate, refused with something to act on.**.
     *
     * And refused across case, which is the assertion the whole fold exists for:
     * `give-10` is not a second voucher next to `GIVE-10`, it is the same name
     * typed lazily.
     */
    public function testADuplicateCodeIsRefusedWhateverCaseItIsTypedIn(): void
    {
        $this->submit(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'], VoucherModule::ORDER_AMOUNT);
        $refused = $this->submit(['code' => 'give-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '5.00'], VoucherModule::ORDER_AMOUNT);

        self::assertNull($refused->headers->get('Location'), 'nothing was saved');
        self::assertStringContainsString('Another record already uses this value', (string) $refused->getContent());
        self::assertSame(1, $this->countVouchers());
    }

    /** A code that cannot be dictated is refused, and the message says what one looks like. */
    public function testAMalformedCodeIsRefusedWithAnExample(): void
    {
        $refused = $this->submit(['code' => 'GIVE 10!', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'], VoucherModule::ORDER_AMOUNT);
        $body = (string) $refused->getContent();

        self::assertNull($refused->headers->get('Location'), 'nothing was saved');
        self::assertStringContainsString('invalid-feedback', $body);
        self::assertStringContainsString('GIVE-10', $body, 'the refusal shows what a code looks like');
    }

    /** The code is what a voucher is called, so it is the heading and the link. */
    public function testTheCodeIsWhatAVoucherIsCalled(): void
    {
        $id = $this->savedId($this->submit(['code' => 'give-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'], VoucherModule::ORDER_AMOUNT));

        $this->client->request('GET', $this->url('/m/voucher/' . $id));

        self::assertSelectorTextContains('h1', 'GIVE-10');
        self::assertStringContainsString('GIVE-10', $this->client->request('GET', $this->url('/m/voucher'))->filter('table')->text());
    }

    // -- limits -------------------------------------------------------------

    /**
     * **Unlimited is not a large number: it is nothing at all.**.
     *
     * Asserted on the stored payload rather than on a page, because the claim is
     * about what is in the database. `RecordRepository` drops nulls out of the
     * document, so an unlimited voucher does not carry the key — there is no
     * sentinel for a future reader to mistake for a limit, and no number for an
     * arithmetic comparison to accidentally satisfy.
     */
    public function testUnlimitedIsAnAbsentValueAndNotABigOne(): void
    {
        $this->submit(['code' => 'FOREVER', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10'], VoucherModule::ORDER_PERCENTAGE);

        // The raw column rather than a loaded record: a `Record` read back is
        // hydrated with every field this shape defines, so an unlimited voucher
        // carries the key holding null once it is in memory. What the claim is
        // about is the document in the database, where the key is not there at
        // all — nothing for a future reader to mistake for a limit, and nothing
        // for `count < :limit` to compare against by accident.
        self::assertArrayNotHasKey(VoucherModule::MAX_REDEMPTIONS, $this->payloadOf('FOREVER'));
        self::assertNull(
            $this->stored('FOREVER')[VoucherModule::MAX_REDEMPTIONS],
            'and it reads back as nothing rather than as a number',
        );
    }

    /** Once is one, and a fixed number is that number. Nothing else is needed to tell them apart. */
    public function testOnceAndNTimesAreOrdinaryNumbers(): void
    {
        $this->submit(['code' => 'ONCE', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10', 'max_redemptions' => '1'], VoucherModule::ORDER_PERCENTAGE);
        $this->submit(['code' => 'FIVE', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10', 'max_redemptions' => '5'], VoucherModule::ORDER_PERCENTAGE);

        self::assertSame(1, $this->stored('ONCE')[VoucherModule::MAX_REDEMPTIONS] ?? null);
        self::assertSame(5, $this->stored('FIVE')[VoucherModule::MAX_REDEMPTIONS] ?? null);
    }

    /**
     * Zero redemptions is not a voucher, it is a voucher somebody switched off —
     * and this module says that with dates rather than with a number.
     */
    public function testAVoucherGoodForNoRedemptionsIsRefused(): void
    {
        $refused = $this->submit(
            ['code' => 'NEVER', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '10', 'max_redemptions' => '0'],
            VoucherModule::ORDER_PERCENTAGE,
        );

        self::assertNull($refused->headers->get('Location'), 'nothing was saved');
        self::assertSame(0, $this->countVouchers());
    }

    // -- helpers ------------------------------------------------------------

    /** @param array<string, string> $values */
    private function submit(array $values, string $variant): Response
    {
        return $this->saveRecord(VoucherModule::KEY, $values, variant: $variant);
    }

    /**
     * The JSONB document exactly as Postgres holds it.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(string $code): array
    {
        $json = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($code): string {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);

            return (string) $connection->fetchOne(
                "SELECT data FROM voucher WHERE data->>'code' = :code AND deleted_at IS NULL",
                ['code' => $code],
            );
        });

        $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($payload));

        return $payload;
    }

    /** @return array<string, mixed> */
    private function stored(string $code): array
    {
        foreach ($this->vouchers() as $voucher) {
            if ($voucher->get(VoucherModule::CODE) === $code) {
                return $voucher->data;
            }
        }

        self::fail(sprintf('No voucher is called %s.', $code));
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_values(array_map(
            static fn (Record $voucher): string => (string) $voucher->get(VoucherModule::CODE),
            $this->vouchers(),
        ));
    }

    private function countVouchers(): int
    {
        return \count($this->vouchers());
    }

    /** @return list<Record> */
    private function vouchers(): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function (): array {
            $module = self::service(MetadataRepository::class)->get(VoucherModule::KEY);

            return array_values(self::service(RecordRepository::class)->findAll($module));
        });
    }

    private static function selector(string $field): string
    {
        return sprintf('[name="%s[fields][%s]"]', self::FORM, $field);
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
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

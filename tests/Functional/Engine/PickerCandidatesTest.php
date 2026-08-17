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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Dbal\MeasuresQueries;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Form\CandidateLists;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Order\OrderModule;

/**
 * A reference picker's candidate list is read once per request (XIV-87).
 *
 * **The claim is about a number, so it is asserted as one.** Every other property
 * of the picker — that it is scoped, that it says when it truncates, that it
 * narrows to a variant — has a test in {@see CrossModuleLinkTest} already, and
 * none of them would notice the list being built five hundred times instead of
 * once. That is what makes this its own file: the bug XIV-68 measured was
 * invisible to a suite that was otherwise thorough about this control.
 *
 * **Two sizes, asserted equal rather than pinned.** A magic number would break
 * every time a query is added somewhere unrelated and would say nothing about the
 * property being defended, which is that the cost does not follow the row count.
 * `assertSame` between a short order and a long one can only pass one way.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PickerCandidatesTest extends WebTestCase
{
    use MeasuresQueries;
    use SharesATenant;

    private const string SLUG = 'test_picker_candidates';
    private const string HOST = 'picker-candidates.localhost';
    private const string EMAIL = 'picker@example.test';
    private const string PASSWORD = 'picker-password';

    /** Enough that a per-row read is unmistakable, few enough that the fixture is quick. */
    private const int FEW = 2;
    private const int MANY = 25;

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<int> */
    private array $articles = [];

    private int $customer = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The counter and the memo are both per request, and a rebooting kernel
        // would throw away the tenant the sign-in landed on between one request
        // and the next.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Pat Picker', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
        $this->buildCatalogue();
    }

    /**
     * The edit form of a long order costs no more picker reads than a short one.
     *
     * The order module is the right subject because its `lines` collection is the
     * only place in the product where one picker is drawn many times on one page:
     * every article line carries a reference to an article, and before this each
     * of those resolved the whole catalogue for itself.
     */
    public function testTheCandidateListIsReadOnceHoweverManyRowsUseIt(): void
    {
        $short = $this->anOrderOf(self::FEW);
        $long = $this->anOrderOf(self::MANY);

        // The short one first and discarded: the first request of a test warms
        // metadata caches that would otherwise be counted as this page's cost.
        $this->queriesEditing($short);

        $few = $this->queriesEditing($short);
        $many = $this->queriesEditing($long);

        self::assertGreaterThan(0, $few, 'a count of zero would prove nothing');
        self::assertSame(
            $few,
            $many,
            sprintf(
                'editing %d lines cost %d queries and %d lines cost %d — the picker is being rebuilt per row',
                self::FEW,
                $few,
                self::MANY,
                $many,
            ),
        );
    }

    /**
     * The memo does not outlive the request that filled it.
     *
     * This is the half the class it was extracted from was worried about, and the
     * reason that comment refused a memo at all: a list kept between requests
     * hands an older answer to a newer form, and — worse, since the list is scoped
     * — could hand one reader the names another reader may see. Asserted by adding
     * an article between two requests and requiring the second to know about it.
     */
    public function testTheListIsNotCarriedIntoTheNextRequest(): void
    {
        $order = $this->anOrderOf(self::FEW);

        $before = $this->optionsEditing($order);

        $this->write(ArticleModule::KEY, [
            'title' => 'Article added between two requests',
            'price' => '12.00',
            'tax_rate' => '8.1',
        ]);

        self::assertSame(
            $before + 1,
            $this->optionsEditing($order),
            'a record created after one page was drawn has to appear on the next',
        );
    }

    /** And the service says so itself, so the reset is not only observable through a page. */
    public function testTheMemoEmptiesOnReset(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $lists = self::service(CandidateLists::class);

            $first = $lists->for(ArticleModule::KEY, null);
            self::assertNotSame([], $first['choices']);

            [, $cached] = self::countingQueries(static fn (): array => $lists->for(ArticleModule::KEY, null));
            self::assertSame(0, $cached, 'a second ask inside one request reads nothing');

            $lists->reset();

            [, $afterReset] = self::countingQueries(static fn (): array => $lists->for(ArticleModule::KEY, null));
            self::assertGreaterThan(0, $afterReset, 'and after a reset it reads again');
        });
    }

    // -- helpers ------------------------------------------------------------

    private function queriesEditing(int $order): int
    {
        [, $count] = self::countingQueries(function () use ($order): void {
            $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
            self::assertResponseIsSuccessful();
        });

        return $count;
    }

    /** How many article options the first line's picker offers. */
    private function optionsEditing(int $order): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
        self::assertResponseIsSuccessful();

        // The *first* row's picker, not every row's. Counting them all was the
        // first version of this and it silently multiplied by the row count,
        // which made the assertion arithmetic rather than the claim.
        return $crawler->filter('select[id*="article"]')->first()->filter('option')->count();
    }

    private function buildCatalogue(): void
    {
        $this->customer = $this->write(ContactModule::KEY, ['kind' => 'company', 'company_name' => 'Picker AG']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->articles[] = $this->write(ArticleModule::KEY, [
                'title' => sprintf('Article %04d', $i),
                'price' => number_format(5 + $i * 1.35, 2, '.', ''),
                'tax_rate' => '8.1',
            ]);
        }
    }

    private function anOrderOf(int $rows): int
    {
        $lines = [];

        for ($i = 0; $i < $rows; ++$i) {
            $article = $this->articles[$i % \count($this->articles)];

            $lines[] = ['id' => null, 'data' => [
                OrderModule::KIND => OrderModule::ARTICLE_LINE,
                'article' => $article,
                'description' => sprintf('Article %04d', ($i % \count($this->articles)) + 1),
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '10.00',
                OrderModule::TAX_RATE => '8.1',
            ]];
        }

        return $this->write(
            OrderModule::KEY,
            ['contact' => $this->customer, 'ordered_on' => '2026-08-17', 'status' => OrderModule::DRAFT],
            [OrderModule::LINES => $lines],
        );
    }

    /**
     * @param array<string, mixed>                                                 $fields
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows
     */
    private function write(string $moduleKey, array $fields, array $rows = []): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($moduleKey, $fields, $rows): int {
            $module = self::service(MetadataRepository::class)->get($moduleKey);
            $record = new Record(data: $fields);

            self::service(RecordWriter::class)->save($module, $record, $rows);

            return (int) $record->id;
        });
    }

    private function signIn(): void
    {
        $this->client->request('GET', $this->url('/login'));
        $this->client->submitForm('Sign in', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
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

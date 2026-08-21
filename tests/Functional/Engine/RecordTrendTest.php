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
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;
use Xivi\Order\OrderModule;

/**
 * An article's price over time, read out of the history that was already there
 * (XIV-121).
 *
 * ## The first assertion is the ticket's own question
 *
 * The ticket asked whether record history already answers "what was this
 * article's price in March" before anything was designed, because the answer
 * decides whether the work is a query or a storage design.
 * {@see self::testHistoryAlreadyHoldsEveryValueAPriceHasHad()} is that question
 * asked of the running engine rather than of the source, and it is deliberately
 * first in the file: everything below it is only worth having because it passes.
 *
 * ## Why so much of this is about the degenerate cases
 *
 * A chart is easy to get right on the record somebody demonstrates it with — six
 * changes, a nice shape — and wrong on the other several thousand. A catalogue is
 * mostly articles nobody has ever edited, so "one change, or none" is not an edge
 * case here, it is the common one, and an empty box on it would be the whole
 * feature's reputation.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordTrendTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_trend';
    public const string HOST = 'trend.localhost';
    private const string ADMIN = 'trend@example.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    public const string EMAIL = self::ADMIN;
    private const string MEMBER = 'member@trend.test';
    private const string PASSWORD = 'trend-password';

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

            // Contact and article for their own sakes; order because it is the
            // module in this repository with a `reference` field on the record
            // itself, and a reference holding a record id is the one number that
            // must never turn up on an axis.
            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $module) {
                $installer->install($registry->get($module));
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /**
     * **The question the ticket opens with, asked of the engine.**.
     *
     * §5.2 says the diff is structured and carries the values, and this is what
     * proves the sentence means what it says: three saves, and the timeline holds
     * every price the article has ever had — the one it was created with, as a
     * change from nothing, and each one it was moved to since. Nothing prunes the
     * table, so the chain is unbroken from the record's birth.
     *
     * If this ever fails, the feature below it is not fixable by fixing the
     * chart: it would mean history had become a record of *that* something
     * changed, and a price series would have to be stored.
     */
    public function testHistoryAlreadyHoldsEveryValueAPriceHasHad(): void
    {
        $id = $this->anArticle('100.00');
        $this->priceBecomes($id, '120.00');
        $this->priceBecomes($id, '95.50');

        $prices = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $module = self::service(MetadataRepository::class)->find(ArticleModule::KEY);
            self::assertNotNull($module);

            $seen = [];

            foreach (self::service(HistoryRepository::class)->fieldChangesFor($module, $id, 50) as $entry) {
                $change = $entry['fields']['price'] ?? null;
                self::assertIsArray($change);
                $seen[] = [$change['from'], $change['to']];
            }

            return $seen;
        });

        self::assertSame([
            // The record's birth: a change from nothing, which is what makes the
            // first price recoverable at all.
            [null, '100.00'],
            ['100.00', '120.00'],
            ['120.00', '95.50'],
        ], $prices);
    }

    /**
     * The feature, on the record it belongs to.
     *
     * The card is on the article's own page rather than on the dashboard, and
     * what it draws is every price the article has held, in order, ending at the
     * one it holds now.
     */
    public function testAnArticlesPriceOverTimeIsOnTheArticleItself(): void
    {
        $id = $this->anArticle('100.00');
        $this->priceBecomes($id, '120.00');
        $this->priceBecomes($id, '95.50');

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $id));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.trend-chart canvas'));
        self::assertSame(
            // The three recorded prices, and the current one carried forward to
            // now so that the line does not appear to stop at the last edit.
            [100.0, 120.0, 95.5, 95.5],
            $this->plotted($crawler),
        );
        self::assertStringContainsString('2 changes', $this->card($crawler)->text());
        self::assertStringContainsString('95.50', $this->card($crawler)->text());
    }

    /**
     * The step is where the change was, not spread across the gap before it.
     *
     * A price holds until somebody changes it, so the dataset says `stepped`, and
     * `after` rather than the `true` that means `before`. Asserted because it is
     * the difference between a chart that reports what happened and one that
     * draws a price drifting smoothly between two edits it never had a value
     * between.
     */
    public function testThePriceIsDrawnAsSomethingThatHoldsUntilItIsChanged(): void
    {
        $id = $this->anArticle('100.00');
        $this->priceBecomes($id, '120.00');

        $dataset = $this->dataset($this->client->request('GET', $this->url('/m/article/' . $id)));

        self::assertSame('after', $dataset['stepped']);
    }

    /** One change is three points and a sentence, not a broken chart. */
    public function testARecordWithOneChangeDrawsALine(): void
    {
        $id = $this->anArticle('100.00');
        $this->priceBecomes($id, '120.00');

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $id));

        self::assertSame([100.0, 120.0, 120.0], $this->plotted($crawler));
        self::assertStringContainsString('1 change', $this->card($crawler)->text());
    }

    /**
     * And a record with no change at all draws a flat line and says why it is
     * flat.
     *
     * This is most of a catalogue, so it is the case that decides whether the
     * feature looks finished. A horizontal line and a chart that failed to load
     * are the same picture; the sentence is what tells them apart, and it carries
     * the answer — the value, and since when — for somebody who never looks at
     * the chart at all.
     */
    public function testARecordWithNoChangesDrawsAFlatLineAndSaysSo(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article/' . $this->anArticle('100.00')));

        self::assertCount(1, $crawler->filter('.trend-chart canvas'));
        self::assertSame([100.0, 100.0], $this->plotted($crawler), 'from when it was made until now');
        self::assertStringContainsString('unchanged', $this->card($crawler)->text());
    }

    /**
     * A module with nothing numeric on it gets no card, not an empty one.
     *
     * A contact is not a record with a missing chart; it is a record a chart has
     * no opinion about. The component is still mounted — the page does not know
     * which modules have numbers on them — so what is asserted is that it draws
     * nothing at all.
     */
    public function testAModuleWithNothingNumericOnItDrawsNoCard(): void
    {
        $id = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            variant: 'person',
        ));

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.trend-chart'));
        self::assertStringNotContainsString('Trend', $crawler->filter('main')->text());
    }

    /**
     * It generalises: every numeric field is on offer, and the picker names them.
     *
     * An article has two numbers — a price and a VAT rate — and nothing in the
     * engine knows that one of them is called `price`. What is *not* offered is
     * the title, the description and the unit, which are not numbers, and that is
     * the other half of the claim.
     */
    public function testEveryNumericFieldIsOnOfferAndNothingElseIs(): void
    {
        $id = $this->anArticle('100.00', taxRate: '8.1');

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $id));

        self::assertSame(
            ['price', 'tax_rate'],
            $this->card($crawler)->filter('select option')->each(
                static fn (Crawler $option): string => (string) $option->attr('value'),
            ),
        );
    }

    /**
     * A reference is a number and is deliberately not one of them.
     *
     * An order's `contact` field stores the id of a contact. Plotting it would
     * draw a line through somebody's primary keys, which is meaningless and also
     * says more about the database than a page should. The engine asks the field
     * type rather than knowing the word `reference`: a type that names another
     * record says so by implementing `LinksToRecord` (XIV-42).
     */
    public function testAReferenceIsNotPlottedEvenThoughItsValueIsANumber(): void
    {
        $contact = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            variant: 'person',
        ));

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => $contact,
            'ordered_on' => '2026-03-01',
            'status' => OrderModule::DRAFT,
        ]));

        $offered = $this->card($this->client->request('GET', $this->url('/m/order/' . $order)))
            ->filter('select option')
            ->each(static fn (Crawler $option): string => (string) $option->attr('value'));

        self::assertNotContains('contact', $offered);
        self::assertNotSame([], $offered, 'the order does have numbers on it, so this is not passing by accident');
    }

    /**
     * Picking another field redraws the card and nothing else.
     *
     * The state is the component's, not the URL's (§8.3.1): narrowing what a card
     * shows is not navigation, and nobody sends a colleague a link to which of
     * two numbers they were looking at.
     */
    public function testPickingAnotherFieldRedrawsTheCard(): void
    {
        $id = $this->anArticle('100.00', taxRate: '8.1');

        $rendered = $this->trendComponent($id)->set('field', 'tax_rate')->render();

        self::assertStringContainsString('VAT rate', $rendered->toString());
        self::assertStringContainsString('8.10', $rendered->toString());
    }

    /**
     * A field key nothing answers to draws the default rather than an error.
     *
     * A writable prop is a value the client sets, so it is a value the client can
     * make up. The same treatment a stale widget key gets on the dashboard (§8.3.1)
     * and a stale reference gets in a record (§7.6): the missing thing is dropped,
     * because it is a runtime fact rather than a broken installation.
     */
    public function testAFieldKeyThatIsNotOnOfferFallsBackToTheDefault(): void
    {
        $id = $this->anArticle('100.00');

        $rendered = $this->trendComponent($id)->set('field', 'nonsense')->render();

        self::assertStringContainsString('Price', $rendered->toString());
    }

    /**
     * Somebody who may not open the record learns nothing about it from the card.
     *
     * The record page has voted already, but a live component answers at an
     * endpoint of its own and its props are signed rather than secret — so the
     * check is made in the component too, against *this record* rather than the
     * module, and it refuses the way the record page refuses: 404, so that a
     * record somebody may not see is indistinguishable from one that is not
     * there.
     */
    public function testSomebodyWhoMayNotViewTheRecordGetsNothingFromTheCard(): void
    {
        $id = $this->anArticle('100.00');

        $mine = $this->trendComponent($id)->render()->toString();
        self::assertStringContainsString('100.00', $mine, 'the same card, for somebody who may open it');

        $theirs = $this->trendComponent($id, as: self::MEMBER)->render()->toString();

        self::assertStringNotContainsString('100.00', $theirs, 'not the price');
        self::assertStringNotContainsString('canvas', $theirs, 'not a chart of it either');
        // The component's own name is in the props either way, so what is
        // asserted is that no card was drawn around it — no heading, no body.
        self::assertStringNotContainsString('<h2', $theirs, 'and not a card saying there is one');
    }

    /**
     * A timeline longer than the cap draws its recent end and says it did.
     *
     * A line beginning at whatever the oldest entry it happened to read said,
     * presented without a word, would be claiming the record was born at that
     * price.
     */
    public function testATimelineBeyondTheCapSaysThatItWasCutOff(): void
    {
        $id = $this->anArticle('100.00');
        $this->appendPriceChanges($id, 520);

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $id));

        self::assertStringContainsString('Older changes', $this->card($crawler)->text());
        self::assertLessThanOrEqual(501, \count($this->plotted($crawler)));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * One article, saved the way the application saves one.
     *
     * A plain one ([XIV-133], §5.32): an article comes in three kinds now, and
     * a price series is a question about something that has a price. What a
     * *variant's* price history looks like needs no test of its own for the same
     * reason nothing else here changed: a variant is an article record, so it is
     * this record with a different value in one field.
     */
    private function anArticle(string $price, ?string $taxRate = null): int
    {
        $fields = [ArticleModule::KIND => ArticleModule::PLAIN, 'title' => 'Bürostuhl Ergo', 'price' => $price];

        if ($taxRate !== null) {
            $fields['tax_rate'] = $taxRate;
        }

        return $this->savedId($this->saveRecord(ArticleModule::KEY, $fields, variant: ArticleModule::PLAIN));
    }

    private function priceBecomes(int $id, string $price): void
    {
        $this->saveRecord(
            ArticleModule::KEY,
            [ArticleModule::KIND => ArticleModule::PLAIN, 'title' => 'Bürostuhl Ergo', 'price' => $price],
            recordId: $id,
            variant: ArticleModule::PLAIN,
        );
    }

    /**
     * Synthetic changes, written where the writer would write them.
     *
     * Through the repository rather than through the form, for the reason
     * `RecordHistoryTest` gives about the same trick: five hundred round trips
     * through a component would be testing the component five hundred times to
     * find out what a long timeline looks like.
     */
    private function appendPriceChanges(int $recordId, int $count): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($recordId, $count): void {
            $module = self::service(MetadataRepository::class)->find(ArticleModule::KEY);
            self::assertNotNull($module);

            $history = self::service(HistoryRepository::class);
            $when = new \DateTimeImmutable();

            for ($i = 0; $i < $count; ++$i) {
                $history->append(
                    $module,
                    $recordId,
                    RecordAction::Updated,
                    $when->modify(sprintf('-%d minutes', $count - $i)),
                    null,
                    'Robot',
                    new RecordChanges(['price' => [
                        'label' => 'Price',
                        'from' => sprintf('%d.00', 100 + $i),
                        'to' => sprintf('%d.00', 101 + $i),
                    ]]),
                );
            }
        });
    }

    /** The trend card on a rendered record page. */
    private function card(Crawler $crawler): Crawler
    {
        return $crawler->filter('.card:has(.trend-chart)');
    }

    /**
     * What Chart.js is handed, decoded.
     *
     * The whole configuration travels to the browser in a Stimulus value on the
     * canvas, which is what makes it assertable without a browser at all — and
     * the values in it are the point of the feature. Whether any of it is ever
     * *painted* is the browser test's question; this is only the payload.
     *
     * @return array<array-key, mixed>
     */
    private function chart(Crawler $crawler): array
    {
        $view = $crawler->filter('.trend-chart canvas')->attr('data-symfony--ux-chartjs--chart-view-value');
        self::assertIsString($view);

        $decoded = json_decode($view, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The one dataset drawn on the card.
     *
     * @return array<array-key, mixed>
     */
    private function dataset(Crawler $crawler): array
    {
        $data = $this->chart($crawler)['data'] ?? null;
        self::assertIsArray($data);

        $dataset = $data['datasets'][0] ?? null;
        self::assertIsArray($dataset);

        return $dataset;
    }

    /** @return list<float> the y values of the one line on the card, in order */
    private function plotted(Crawler $crawler): array
    {
        $points = $this->dataset($crawler)['data'] ?? null;
        self::assertIsArray($points);

        $values = [];

        foreach ($points as $point) {
            self::assertIsArray($point);
            $values[] = (float) $point['y'];
        }

        return $values;
    }

    /** The card as its own component, for the tests that are about its control. */
    private function trendComponent(int $recordId, string $as = self::ADMIN): TestLiveComponent
    {
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $user = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?User => self::service(UserRepository::class)->findOneByEmail($as),
        );
        self::assertInstanceOf(User::class, $user);

        return $this
            ->createLiveComponent('RecordTrend', ['module' => ArticleModule::KEY, 'recordId' => $recordId], $this->client)
            ->actingAs($user);
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
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

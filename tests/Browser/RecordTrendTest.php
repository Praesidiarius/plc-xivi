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

namespace App\Tests\Browser;

use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tests\Support\ReleasesTheBrowser;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * The trend chart actually draws, and what is written along its axis (XIV-121,
 * XIV-31, [XIV-174]).
 *
 * **The one thing no other test here can see.** Every other test of this feature
 * asserts what the *server* put in the page: the right values, in the right
 * order, in a Stimulus value on a canvas. None of them can tell whether anything
 * ever paints, and the ways this feature breaks silently are all on the far side
 * of that line — Chart.js is loaded **lazily** (`assets/controllers.json`), so a
 * page that never triggers the dynamic import shows a blank box; a stepped line
 * with a configuration Chart.js rejects throws in the console and leaves the same
 * blank box; and the small controller beside it edits the options in an event
 * that has to fire before the chart is constructed. Every one of those is a green
 * suite and an empty card.
 *
 * **So this asserts pixels.** It reads the canvas back and counts how many of
 * them are not transparent — which is true only if the dynamic import resolved,
 * the controller connected, the configuration was accepted and a line was
 * painted. There is nothing cheaper that means all four.
 *
 * ## And pixels were not enough ([XIV-174])
 *
 * A chart drew, in full colour, with `1'787'200'000'000` under it where a date
 * belonged, and every check in this repository was green: the server's payload
 * was correct by construction, so the functional tests could not see it, and a
 * canvas full of ink is a canvas full of ink whatever it says. The three tests
 * below close that, and they read the labels through **Chart.js's own API**
 * rather than off the bitmap. `scale.ticks` after an update is the text the
 * library is about to draw, which is as close to the reader's eye as anything
 * short of optical recognition gets.
 *
 * Two conditions in the setup are load-bearing, and both of them are what the
 * old test lacked rather than decoration:
 *
 *  * **This class's reader has a region.** The account below is Swiss, so
 *    `FormattingLocale` joins `en` and `CH` into `en_CH` and the page is served
 *    with `lang="en_CH"`, which was the whole bug: that is an ICU locale, HTML
 *    wants the BCP 47 `en-CH`, and `Intl.DateTimeFormat` throws on the
 *    underscore rather than shrugging at it. A reader with no region gets a bare
 *    `en`, which is a valid tag, and the buggy build passes every assertion
 *    below. **A test of this that signs in as `e2e@` proves nothing.** It is
 *    this class's own account for the same reason
 *    `FiguresInEveryLanguageTest` has one: the region is a column on the user
 *    and four other browser classes share `e2e@`.
 *  * **Its article's history is spread over days.** The engine stamps everything
 *    it writes with the moment it writes it, so three saves in a test are three
 *    events in the same second and every tick on that axis is the same day
 *    whatever the code does. Spread over a fortnight, "each tick is a different
 *    day" becomes a claim that can fail.
 *
 * Nothing here writes out a date. What a date looks like in `en_CH` is CLDR's
 * business and ICU's, and the expectation is taken from `IntlDateFormatter` at
 * the medium style, which is `dateStyle: 'medium'` on the other side of the
 * wire. That is `SwissFiguresTest`'s rule: a test that quotes `13 Aug 2026` is a
 * second copy of the world's date data and goes wrong the first time the first
 * copy moves.
 *
 * The rule the rest of this directory works to holds here: no sleep, one explicit
 * wait on a condition. It shares the end-to-end tenant because there is exactly
 * one hostname both the browser's container and the application's can resolve,
 * and it adds an article of its own rather than touching anything another class
 * left — nothing here may be rolled back, since the browser is another process
 * making real requests.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordTrendTest extends PantherTestCase
{
    use ReleasesTheBrowser;
    use SharesATenant;

    private const string HOST = 'xivi-e2e';
    private const string SLUG = 'e2e';

    /**
     * A colleague of this file's own, and the reason is in the class docblock:
     * they work in a country, which is what makes the page's `lang` the two-part
     * thing [XIV-174] was about.
     */
    private const string EMAIL = 'e2e-trend@example.test';

    private const string PASSWORD = 'e2e-password';

    /** Where this reader is, which is what turns `en` into `en_CH`. */
    private const string REGION = 'CH';

    /** What the articles are called, and therefore how this class finds its own. */
    private const string TITLE = 'Trend-Chart-Testartikel';
    private const string SHORT_TITLE = 'Trend-Chart-Testartikel (gestern)';

    /**
     * The other numeric field on an article, and what the picker switches to.
     *
     * Written out rather than taken from `ArticleModule`, because what this
     * class needs is *a second candidate* and the module is entitled to change
     * which fields it ships. If this key ever stops existing the test says so at
     * the picker rather than somewhere further in.
     */
    private const string OTHER_FIELD = 'tax_rate';

    /** How far back the article's timeline is stretched, and in what steps. */
    private const int SPAN_IN_DAYS = 8;
    private const int STEP_IN_DAYS = 4;

    /**
     * And how short the other one's is.
     *
     * Thirty-six hours is the reporter's own screenshot: a record made yesterday
     * and read today, which is what every record looks like for its first two
     * days. Four ticks across it at even millisecond spacing is one every
     * fourteen hours, and two of those fall on the same date, which is the
     * defect `testEveryTickOnTheAxisIsADifferentDay` exists for.
     */
    private const int SHORT_SPAN_IN_HOURS = 36;

    private static ?int $article = null;
    private static ?int $shortLivedArticle = null;

    private Client $browser;

    protected function setUp(): void
    {
        self::$article ??= $this->anArticleWithAPriceHistory();
        self::$shortLivedArticle ??= $this->anArticleMadeYesterday();

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->signIn();
    }

    public function testThePriceChartIsDrawnOnTheRecordPage(): void
    {
        $this->openTheRecord();

        self::assertGreaterThan(
            0,
            (int) $this->browser->executeScript(
                'const c = document.querySelector(".trend-chart canvas");'
                . ' const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data;'
                // Every fourth byte is alpha. Anything non-zero is ink.
                . ' let painted = 0;'
                . ' for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) { painted++; } }'
                . ' return painted;',
            ),
            'the canvas has something painted on it',
        );
    }

    /**
     * Every label under the axis is the date of the moment it sits at, and so is
     * the tooltip's heading.
     *
     * **The tooltip is in here rather than in a test of its own because it is
     * the same failure.** Both are functions Chart.js calls, both are installed
     * by the same four lines of `trend_chart_controller.js`, and in [XIV-174]
     * both were missing for one reason: the method that installs them threw on
     * its third line and never reached either. Splitting them would be two tests
     * that can only ever fail together.
     *
     * The comparison is against what the *browser* was given to draw: the tick
     * is a millisecond value and its label has to be that instant's date, so
     * this passes only if the values and the words agree. A build that formatted
     * something else entirely, or that printed the epoch count, fails with both
     * strings in the message.
     */
    public function testTheAxisAndTheTooltipReadAsDates(): void
    {
        $this->openTheRecord();

        $chart = $this->whatTheChartSays();
        $format = $this->dateFormatterFor($chart['lang'], $chart['zone']);

        self::assertNotSame([], $chart['ticks'], 'the axis has labels at all');

        foreach ($chart['ticks'] as $tick) {
            self::assertSame(
                $this->formatted($format, $tick['value']),
                $tick['label'],
                sprintf(
                    'the tick at %d is labelled with its own date. A number here is [XIV-174]: the server '
                    . 'sends epoch milliseconds on a linear scale on purpose, and the browser puts the '
                    . 'dates back. A page whose lang is an ICU locale rather than a language tag '
                    . 'makes Intl throw and leaves the raw count on screen.',
                    $tick['value'],
                ),
            );
        }

        self::assertSame(
            [$this->formatted($format, $chart['firstPoint'])],
            $chart['tooltip'],
            'the tooltip is headed with the date of the point it is about',
        );
    }

    /**
     * No two labels say the same day.
     *
     * **The second half of [XIV-174], and it survives the first half being
     * fixed.** A linear scale spends `maxTicksLimit` on round numbers, and a
     * round number of milliseconds is not a day: four of them across the day and
     * a half the reporter's contact had spanned works out at one every fourteen
     * hours, so two of them formatted to the same date. An axis reading
     * `21.08.2026`, `21.08.2026` is not wrong so much as pointless, and it is
     * exactly what fixing the formatter alone would have shipped.
     *
     * So the controller places the ticks itself, on midnight, and this is the
     * assertion that says so. It is deliberately about the *labels* rather than
     * about the values: ticks a whole number of days apart is the mechanism, and
     * distinct dates is the thing a reader gets.
     *
     * **It runs against a record of its own, and the span is the point.** The
     * article the other tests use covers a fortnight, on which round millisecond
     * ticks land on different days by luck and this would pass on a build that
     * places no ticks at all. This one covers a day and a half, which is the
     * reporter's own contact and the shape a record made yesterday has: the
     * ordinary case rather than a corner.
     */
    public function testEveryTickOnTheAxisIsADifferentDay(): void
    {
        $this->openTheRecord(self::$shortLivedArticle);

        $chart = $this->whatTheChartSays();
        $labels = array_column($chart['ticks'], 'label');

        self::assertSame(
            array_values(array_unique($labels)),
            $labels,
            sprintf(
                'the axis reads [%s], and a day printed twice is a tick placed on a round millisecond '
                . 'count rather than on a date',
                implode(', ', $labels),
            ),
        );

        // **The mechanism, and the half of this that fails deterministically.**
        // Whether four evenly spaced millisecond values happen to repeat a date
        // depends on the hour the suite runs at, so distinctness alone is a
        // coin toss against a build that formats the labels and leaves the
        // placement to the linear scale. Sitting on midnight is not a coin
        // toss: a round number of milliseconds since 1970 is midnight roughly
        // never. This record spans a day and a half, so there is exactly one
        // day boundary inside it and every tick has to be on one.
        foreach ($chart['ticks'] as $tick) {
            self::assertSame(
                '00:00:00',
                $this->timeOfDay($tick['value'], $chart['zone']),
                sprintf(
                    'the tick labelled "%s" sits on the start of that day. A tick anywhere else is one '
                    . 'the linear scale placed on a round number, which is what put two identical dates '
                    . 'under a thirty-six-hour axis.',
                    $tick['label'],
                ),
            );
        }
    }

    /**
     * Picking another field leaves the axis reading as dates.
     *
     * **This is a second defect with a second cause, and it is not covered by
     * the tests above.** `RecordTrend` is a live component, so the picker
     * re-renders it and the canvas is handed a fresh `view` value. The chart
     * controller answers that by assigning `chart.options` from the server's
     * payload, which has none of the browser's edits in it, so a card that was
     * right on arrival goes back to epoch milliseconds the
     * moment somebody uses the control it came with. Removing the
     * `chartjs:view-value-change` listener from the controller makes this test,
     * and only this test, fail.
     *
     * It waits on the dataset's own label changing, which is the server having
     * answered and Chart.js having been given the new series. No sleep.
     */
    public function testTheAxisStillReadsAsDatesAfterThePickerRedrawsTheCard(): void
    {
        $this->openTheRecord();

        $this->pick(self::OTHER_FIELD);

        $chart = $this->whatTheChartSays();
        $format = $this->dateFormatterFor($chart['lang'], $chart['zone']);

        self::assertNotSame([], $chart['ticks'], 'the redrawn axis has labels at all');

        foreach ($chart['ticks'] as $tick) {
            self::assertSame(
                $this->formatted($format, $tick['value']),
                $tick['label'],
                'the tick keeps its date across a re-render of the component',
            );
        }
    }

    // -- reading the chart --------------------------------------------------

    /** The record page, waited on until Chart.js has actually laid a chart out. */
    private function openTheRecord(?int $record = null): void
    {
        $this->browser->request('GET', '/m/article/' . ($record ?? self::$article));
        $this->browser->waitForVisibility('.trend-chart canvas');

        // Chart.js writes an explicit pixel size onto the canvas when it lays
        // itself out, so this waits for the library to have arrived and run
        // rather than for a fixed number of milliseconds. The import is lazy, so
        // "has arrived" is a network round trip and not a given.
        $this->browser->waitFor('.trend-chart canvas[style*="px"]');
    }

    /**
     * What the chart is about to draw, asked of Chart.js rather than of the
     * bitmap.
     *
     * The instance is reached through the bundle's own Stimulus controller,
     * which is the only thing holding a reference to it. `window.Stimulus` is
     * put there by `assets/stimulus_bootstrap.js` for exactly this kind of
     * question (XIV-31).
     *
     * `scale.ticks` carries both halves of the claim: `value` is the millisecond
     * position and `label` is the text, so a caller can check that the words
     * belong to the moment rather than merely that they are words. The tooltip
     * is asked for the same way a mouse would ask, by activating the first point
     * and updating, because the title is built during the update and reading the
     * callback back out of the options would only prove that a function is
     * installed, which was true throughout [XIV-174].
     *
     * @return array{lang: string, zone: string, firstPoint: int, tooltip: list<string>, ticks: list<array{value: int, label: string}>}
     */
    private function whatTheChartSays(): array
    {
        $script = <<<'JS'
            const canvas = document.querySelector('.trend-chart canvas');
            const controller = window.Stimulus.getControllerForElementAndIdentifier(
                canvas,
                'symfony--ux-chartjs--chart',
            );

            if (!controller || !controller.chart) {
                return null;
            }

            const chart = controller.chart;
            const first = chart.data.datasets[0].data[0].x;

            chart.tooltip.setActiveElements([{datasetIndex: 0, index: 0}], {x: 0, y: 0});
            chart.update();

            return JSON.stringify({
                lang: document.documentElement.lang,
                zone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                firstPoint: first,
                tooltip: chart.tooltip.title,
                ticks: chart.scales.x.ticks.map((tick) => ({value: tick.value, label: tick.label})),
            });
            JS;

        $state = $this->browser->executeScript($script);
        self::assertIsString($state, 'the canvas is carrying a Chart.js instance');

        /** @var array{lang: string, zone: string, firstPoint: int, tooltip: list<string>, ticks: list<array{value: int, label: string}>} $decoded */
        $decoded = json_decode($state, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Choose another field in the card's own picker, and wait for the card to
     * come back.
     *
     * Typed the way the live component expects to be told: a real `input` and
     * `change` on the bound `<select>`, not a property assignment, which changes
     * the DOM and tells nothing. Then the wait is on the dataset's label, which
     * is the *field's* label and therefore only arrives once the server has
     * rendered the other trend.
     */
    private function pick(string $field): void
    {
        $before = $this->datasetLabel();

        $this->browser->executeScript(sprintf(
            'const s = document.querySelector(%s);'
            . ' s.value = %s;'
            . ' s.dispatchEvent(new Event("input", {bubbles: true}));'
            . ' s.dispatchEvent(new Event("change", {bubbles: true}));',
            json_encode('select[data-model="field"]', \JSON_THROW_ON_ERROR),
            json_encode($field, \JSON_THROW_ON_ERROR),
        ));

        for ($attempt = 0; $attempt < 120; ++$attempt) {
            $now = $this->datasetLabel();

            if ($now !== null && $now !== $before) {
                return;
            }

            usleep(250_000);
        }

        self::fail(sprintf(
            'the trend card never redrew for field "%s"; it is still showing "%s"',
            $field,
            (string) $before,
        ));
    }

    /** What the drawn series calls itself, which is the chosen field's label. */
    private function datasetLabel(): ?string
    {
        $label = $this->browser->executeScript(
            'const canvas = document.querySelector(".trend-chart canvas");'
            . ' const c = canvas && window.Stimulus.getControllerForElementAndIdentifier('
            . '   canvas, "symfony--ux-chartjs--chart");'
            . ' return c && c.chart ? c.chart.data.datasets[0].label : null;',
        );

        return \is_string($label) ? $label : null;
    }

    /**
     * The date this instant falls on, spelled the way the page spells dates.
     *
     * ICU at the medium date style and no time at all, which is what
     * `Intl.DateTimeFormat(locale, {dateStyle: 'medium'})` resolves to on the
     * browser's side: the same CLDR pattern, asked for through the other
     * binding. The zone is the *browser's*, because the axis is drawn by the
     * browser: a tick at midnight UTC is a different date in Auckland, and the
     * label the reader sees is theirs rather than the server's (§8.4.4 settles
     * the same question for everything Twig renders).
     */
    private function dateFormatterFor(string $lang, string $zone): \IntlDateFormatter
    {
        // ICU takes either spelling; the browser will only take the hyphen, and
        // that difference is [XIV-174] in one line.
        $formatter = \IntlDateFormatter::create(
            str_replace('-', '_', $lang),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::NONE,
            new \DateTimeZone($zone),
        );

        self::assertInstanceOf(
            \IntlDateFormatter::class,
            $formatter,
            sprintf('ICU can write dates in "%s"', $lang),
        );

        return $formatter;
    }

    /**
     * The wall-clock time that instant falls at, where the browser is.
     *
     * The zone is the browser's for the same reason the dates are formatted in
     * it: the tick is drawn by the browser, and midnight is a local fact.
     */
    private function timeOfDay(int|float $milliseconds, string $zone): string
    {
        return (new \DateTimeImmutable('@' . (int) round($milliseconds / 1000)))
            ->setTimezone(new \DateTimeZone($zone))
            ->format('H:i:s');
    }

    /** One epoch-millisecond value, as a date. */
    private function formatted(\IntlDateFormatter $formatter, int|float $milliseconds): string
    {
        $formatted = $formatter->format((int) round($milliseconds / 1000));
        self::assertIsString($formatted, 'ICU can write that instant');

        return $formatted;
    }

    // -- what the tenant holds ----------------------------------------------

    /**
     * An article whose price has moved, created through the engine and then aged.
     *
     * Through `RecordWriter` rather than through the record form, because what
     * this class is about is the drawing rather than the saving, and because the
     * writer is the only thing that writes history (§5.2) — which is where the
     * chart comes from. Once per process, and the tenant is thrown away between
     * runs, so nothing here accumulates.
     *
     * **The one thing done behind the engine's back is the ageing**, and it is
     * done because there is no other way. Every entry is stamped with the moment
     * it was written, so three saves in a test method are three events inside one
     * second, and a chart of one second is a chart on which no tick placement can
     * be wrong. The single statement below spreads this record's timeline over a
     * fortnight in even steps, which is what an article's price history looks
     * like and what makes "each label is a different day" a question at all.
     */
    private function anArticleWithAPriceHistory(): int
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        return self::withoutRollback(function () use ($tenant): int {
            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                self::service(ModuleInstaller::class)->install(
                    self::service(ModuleRegistry::class)->get(ArticleModule::KEY),
                );
            });

            if (self::service(TenantSwitcher::class)->runFor(
                $tenant,
                fn (): bool => self::service(UserRepository::class)->findOneByEmail(self::EMAIL) === null,
            )) {
                self::service(UserCreator::class)->create($tenant, self::EMAIL, 'E2E', self::PASSWORD, ['ROLE_ADMIN']);
            }

            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
                self::assertInstanceOf(User::class, $user);

                self::service(UserManager::class)->setRegion($user, self::REGION);
            });

            return self::service(TenantSwitcher::class)->runFor($tenant, function (): int {
                $module = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
                $writer = self::service(RecordWriter::class);

                // Both numeric fields carry a value, so the card has two
                // trends and therefore a picker, which is what the re-render
                // test needs something to click.
                $record = new Record([
                    // Plain ([XIV-133]): an article has kinds now, and only the
                    // two that are sold carry a price at all, so a record with
                    // no kind is a record whose price is not on its page.
                    ArticleModule::KIND => ArticleModule::PLAIN,
                    'title' => self::TITLE,
                    'price' => '100.00',
                    self::OTHER_FIELD => '7.70',
                ]);
                $writer->save($module, $record);

                foreach (['120.00', '95.50'] as $price) {
                    $record->set('price', $price);
                    $writer->save($module, $record);
                }

                $this->age(
                    $module->getHistoryTableName(),
                    (int) $record->id,
                    new \DateTimeImmutable(sprintf('-%d days', self::SPAN_IN_DAYS)),
                    self::STEP_IN_DAYS,
                );

                return (int) $record->id;
            });
        });
    }

    /**
     * An article somebody made yesterday and has not touched since.
     *
     * The flat case, and the commonest one there is: a record with a number on
     * it, one entry in its history, and a line that has held its value since it
     * was created. `FieldTrends` draws it from that creation to now, so the axis
     * spans a day and a half, which is the reporter's screenshot and the span on
     * which day-repeating ticks show up.
     *
     * Only `price` is filled, so this record has one trend and no picker. That
     * is deliberate: this is not the test that switches fields, and a picker
     * here would be one more thing to keep working for no assertion.
     */
    private function anArticleMadeYesterday(): int
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        return self::withoutRollback(fn (): int => self::service(TenantSwitcher::class)->runFor(
            $tenant,
            function (): int {
                $module = self::service(MetadataRepository::class)->get(ArticleModule::KEY);

                $record = new Record([
                    ArticleModule::KIND => ArticleModule::PLAIN,
                    'title' => self::SHORT_TITLE,
                    'price' => '30.00',
                ]);
                self::service(RecordWriter::class)->save($module, $record);

                $this->age(
                    $module->getHistoryTableName(),
                    (int) $record->id,
                    new \DateTimeImmutable(sprintf('-%d hours', self::SHORT_SPAN_IN_HOURS)),
                    0,
                );

                return (int) $record->id;
            },
        ));
    }

    /**
     * Move this record's timeline into the past, in even steps.
     *
     * Ordered by `id` rather than by `occurred_at`, because the timestamps this
     * is about to overwrite are all the same second and would not order anything.
     * The id is the order the engine appended them in, which is the order they
     * happened in.
     */
    private function age(string $history, int $record, \DateTimeImmutable $born, int $stepInDays): void
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);

        $connection = $registry->getConnection('tenant');
        \assert($connection instanceof Connection);

        $connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE %s AS h
                    SET occurred_at = :born::timestamptz + ((entry.n - 1) * make_interval(days => :step::int))
                    FROM (
                        SELECT id, row_number() OVER (ORDER BY id) AS n
                        FROM %s
                        WHERE record_id = :record
                    ) AS entry
                    WHERE h.id = entry.id
                    SQL,
                $history,
                $history,
            ),
            [
                'born' => $born->format(\DateTimeInterface::ATOM),
                'step' => $stepInDays,
                'record' => $record,
            ],
        );
    }

    private function signIn(): void
    {
        $this->browser->request('GET', '/login');
        $this->browser->waitForVisibility('form');

        $form = $this->browser->getCrawler()->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->browser->submit($form);

        $this->browser->waitForVisibility('main');
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

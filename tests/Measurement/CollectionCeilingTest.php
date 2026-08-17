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

namespace App\Tests\Measurement;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Dbal\CountsQueries;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Order\OrderModule;

/**
 * How long a collection can get before its record page stops being usable
 * (XIV-68).
 *
 * **This is a measuring instrument, not a test.** It asserts almost nothing and
 * proves nothing; it draws an order with ten lines and then with ten thousand,
 * asks the application for the two pages that draw them, and writes down what
 * that cost. XIV-68 names three possible fixes and says nobody should pick one
 * until somebody has a number, and this is where the number comes from. It lives
 * in `tests/Measurement` rather than `tests/Functional` for exactly that reason,
 * and is in no `<testsuite>` in phpunit.dist.xml — `bin/ci` must not spend four
 * minutes building ten thousand order lines on every commit.
 *
 * ```
 * bin/compose exec -e APP_DEBUG=0 php vendor/bin/phpunit \
 *     tests/Measurement/CollectionCeilingTest.php
 * bin/compose exec -e APP_DEBUG=0 -e XIV68_ROWS=10,100,1000 -e XIV68_ARTICLES=25 php \
 *     vendor/bin/phpunit tests/Measurement/CollectionCeilingTest.php
 * ```
 *
 * **`APP_DEBUG=0` is part of the command, not a flourish.** The test environment
 * boots debug by default, and debug is not a constant overhead here: Twig's
 * profiler keeps a `Profile` node per template render, so a page that renders ten
 * templates per row keeps ten thousand of them for a thousand-line order. Left
 * on, it doubles the read view's wall clock and multiplies its memory by four and
 * a half — measured at five hundred rows, not assumed — and memory is the axis
 * this whole exercise turns out to be about. The numbers in §5.1 of the brief
 * were taken with it off.
 *
 * **The two views are measured separately because they are different problems.**
 * `/m/order/{id}` reads: it draws every row once and asks, per row, whether the
 * values copied from an article have since drifted from it (§5.1). `/m/order/
 * {id}/edit` writes: every row is a form with a kind, a position, a picker and
 * five controls. **The article count is a parameter because the picker is not
 * one thing.** `RecordReferenceType` resolves its candidates per form instance,
 * so a page of N article lines is N selects each holding up to `MAX_CHOICES`
 * options — which makes part of the form's weight a property of the customer's
 * *catalogue* rather than of the document. Running the same length against 25
 * articles and against 250 separates the two, and the answer turned out to be
 * that the picker is most of the bytes and a quarter of the memory. The default
 * is above the cap because a customer with fewer than two hundred articles is
 * the unusual one.
 *
 * **Read straight off the kernel, not through the browser client.** A
 * `KernelBrowser::request()` parses the response into a `Crawler` before it
 * returns, and parsing thirty megabytes of HTML with DOM costs more than the
 * render being measured — the number would then be mostly DomCrawler. So the
 * session cookie is copied out of the client's jar and the request is handed to
 * the kernel directly, which is as close to what FrankenPHP does as this process
 * can get.
 *
 * **What the numbers are not.** Even with debug off this is a test kernel on a
 * test database in the same container, so wall clock is an upper bound rather
 * than a promise; the shape of the curve is what should be read. Bytes are
 * exact.
 *
 * One distortion on the query column is worth naming rather than engineering
 * around, because engineering around it costs more than it is worth:
 * `ReferenceFieldType` holds the records it has named in a per-request array and
 * is not among the services the resetter empties, so a run measuring several
 * sizes has a warmer memo at the fourth than at the first. It is bounded by the
 * size of the article catalogue — a couple of hundred statements against tens of
 * thousands — and it moves the count *down*, so it cannot manufacture the
 * problem being looked for. **Run one size per process for a cold count**, which
 * is another reason the sizes are a parameter.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionCeilingTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'measure_xiv68';
    private const string HOST = 'measure-xiv68.localhost';
    private const string EMAIL = 'measure@example.test';
    private const string PASSWORD = 'measure-password';

    /** The document lengths measured when nothing says otherwise. */
    private const string DEFAULT_ROWS = '10,100,500,1000,5000,10000';

    /**
     * How big the article catalogue is.
     *
     * Above `RecordReferenceType::MAX_CHOICES` on purpose. A customer with two
     * hundred and fifty articles is a small customer, and every picker on the
     * edit form renders the first two hundred of them; a catalogue of twenty
     * would measure a shop that does not exist and would hide the multiplier
     * that turns out to matter most.
     *
     * **It also decides which control the form draws now** (XIV-36). A catalogue
     * past `Autocomplete::AUTO_ABOVE` makes each row's picker a search box, which
     * emits no options at all — so this number no longer separates "big
     * catalogue" from "small catalogue" alone, it separates two widgets. That is
     * the finding rather than a distortion: below the threshold the form draws
     * the same selects it always did, and the numbers in §5.1 name which side of
     * it they were taken on.
     */
    private const string DEFAULT_ARTICLES = '250';

    /** One subtotal line every so many article lines, as a real document has. */
    private const int SUBTOTAL_EVERY = 25;

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<int> */
    private array $articles = [];

    private int $customer = 0;

    protected function setUp(): void
    {
        // Measuring peak memory means being allowed to reach it. The suite's
        // 512M is a sensible ceiling for tests and a wrong one here: a run that
        // dies of exhaustion at eight thousand rows reports nothing at all,
        // where a run that records 700M reports the finding.
        //
        // `XIV68_MEMORY_LIMIT` puts a ceiling back, and what it is good for is
        // watching the failure rather than reading about it: pinned at 512M the
        // edit form of a five-hundred-line order dies with "Allowed memory size
        // exhausted" inside Twig, which is the shape of the 500 a customer would
        // get. It cannot be pinned at the 128M the product actually allows,
        // because PHPUnit, the kernel and the fixtures together stand in more
        // than that before a page is rendered at all — the product's ceiling is
        // reached by holding the measured per-request figure against 128M, not by
        // reproducing it here. The table is written to standard error a line at a
        // time precisely so that the row before a fatal survives it.
        ini_set('memory_limit', (string) ($_SERVER['XIV68_MEMORY_LIMIT'] ?? '-1'));

        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Measure', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    public function testHowLongADocumentCanGet(): void
    {
        $this->say(sprintf(
            "\nXIV-68 — collection ceiling, measured on %s\n%s\n",
            date('Y-m-d H:i:s'),
            str_repeat('=', 96),
        ));

        $this->buildCatalogue();
        $this->warmUp();

        $this->say(sprintf(
            "| %5s | %-9s | %9s | %11s | %8s | %9s | %6s |\n",
            'rows',
            'view',
            'time (ms)',
            'bytes',
            'queries',
            'peak (MB)',
            'status',
        ));
        $this->say('|' . str_repeat('-', 7) . '|' . str_repeat('-', 11) . '|' . str_repeat('-', 11)
            . '|' . str_repeat('-', 13) . '|' . str_repeat('-', 10) . '|' . str_repeat('-', 11)
            . '|' . str_repeat('-', 8) . "|\n");

        $measured = 0;

        foreach (self::rowCounts() as $rows) {
            $built = hrtime(true);
            $order = $this->anOrderOf($rows);
            $saving = (hrtime(true) - $built) / 1e6;

            $this->report($rows, 'read', $this->measure('/m/order/' . $order));
            $this->report($rows, 'edit', $this->measure('/m/order/' . $order . '/edit'));
            $this->say(sprintf("| %5d | %-9s | %9.0f | %11s | %8s | %9s | %6s |\n", $rows, '(saving)', $saving, '', '', '', ''));

            ++$measured;
        }

        self::assertGreaterThan(0, $measured, 'something was measured');
    }

    /**
     * A short order down both routes, thrown away.
     *
     * Twig compiles a template the first time it is rendered and caches the
     * result on disk; the form theme, the record page and the field templates
     * are a few dozen of them. Left in, that one-off cost lands entirely on the
     * first row of the table and reads as "ten lines take four hundred
     * milliseconds", which is a fact about a cold cache rather than about the
     * document.
     */
    private function warmUp(): void
    {
        $order = $this->anOrderOf(3);

        $this->measure('/m/order/' . $order);
        $this->measure('/m/order/' . $order . '/edit');
    }

    /**
     * One request, and what it cost.
     *
     * @return array{ms: float, bytes: int, queries: int, peak: int, status: int}
     */
    private function measure(string $path): array
    {
        $counter = self::service(CountsQueries::class);

        $request = Request::create(sprintf('https://%s%s', self::HOST, $path));

        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $request->cookies->set($cookie->getName(), $cookie->getValue());
        }

        // **The boundary a real request has, put back by hand.** Going straight
        // to the kernel skips what `KernelBrowser` does between requests: it
        // calls the container's `services_resetter`, which is how Symfony
        // empties the per-request state of services that hold any — the form
        // component's choice-list cache above all, which otherwise carries a
        // page of two hundred options per row into the next measurement.
        //
        // Without it the memory column is not merely noisy but wrong in a
        // specific direction: the previous request's memory is still standing
        // when the peak is reset and is released *during* the next one, which
        // hides the next one's own allocations underneath it.
        $container = static::getContainer();

        if ($container->has('services_resetter')) {
            $container->get('services_resetter')->reset();
        }

        gc_collect_cycles();

        // **The request's own footprint, not the process's.** A long-lived test
        // process never gives memory back, so `memory_get_peak_usage()` on its
        // own reports the high-water mark of everything measured so far and
        // would say a ten-row page costs seventy megabytes. Resetting the peak
        // to the current usage and subtracting that usage afterwards leaves what
        // *this* request needed on top of whatever was already standing, which
        // is the figure to hold against the 128M the product runs with.
        //
        // **Counted at the allocator, not at the chunks**, which is the `false`
        // and is not a detail. After a request that reached three gigabytes PHP
        // holds on to the chunks it took and the next request allocates inside
        // them, so the *real* figure does not move at all. The emalloc figure is
        // a few per cent under what `memory_limit` counts and behaves the same
        // way at every size, which is what a table of sizes needs.
        $standing = memory_get_usage(false);
        memory_reset_peak_usage();
        $counter->reset();

        $kernel = self::$kernel;
        \assert($kernel instanceof HttpKernelInterface);

        $started = hrtime(true);
        $response = $kernel->handle($request);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $content = (string) $response->getContent();

        $measured = [
            'ms' => $elapsed,
            'bytes' => \strlen($content),
            'queries' => $counter->count(),
            'peak' => max(0, memory_get_peak_usage(false) - $standing),
            'status' => $response->getStatusCode(),
        ];

        // Held only to be measured, and big enough that holding it would show up
        // in the next measurement's peak.
        unset($content, $response);

        return $measured;
    }

    /** @param array{ms: float, bytes: int, queries: int, peak: int, status: int} $result */
    private function report(int $rows, string $view, array $result): void
    {
        $this->say(sprintf(
            "| %5d | %-9s | %9.0f | %11s | %8s | %9.1f | %6d |\n",
            $rows,
            $view,
            $result['ms'],
            number_format($result['bytes']),
            number_format($result['queries']),
            $result['peak'] / 1024 / 1024,
            $result['status'],
        ));
    }

    /**
     * The catalogue and the customer every measured order is made of.
     *
     * Built once: what is being measured is the length of one document, so the
     * things it points at have to be the same at every length or the query
     * counts would be comparing two tenants.
     */
    private function buildCatalogue(): void
    {
        $count = (int) ($_SERVER['XIV68_ARTICLES'] ?? self::DEFAULT_ARTICLES);
        $rates = [8.1, 8.1, 8.1, 2.6, 3.8];

        $this->customer = $this->write(ContactModule::KEY, ['kind' => 'company', 'company_name' => 'Measured AG']);

        for ($i = 1; $i <= $count; ++$i) {
            $this->articles[] = $this->write(ArticleModule::KEY, [
                'title' => sprintf('Article %04d', $i),
                'price' => number_format(5 + ($i % 400) * 1.35, 2, '.', ''),
                'tax_rate' => (string) $rates[$i % \count($rates)],
            ]);
        }

        $this->say(sprintf("catalogue: %d articles, one customer\n\n", \count($this->articles)));
    }

    /**
     * An order of exactly this many lines.
     *
     * Article lines with a subtotal every {@see self::SUBTOTAL_EVERY}, which is
     * what a long quotation looks like and is also the arrangement that costs
     * the most: an article line is the kind that carries a reference, an
     * inherited description, an inherited price and an inherited rate, so it is
     * the kind both pages do the most work for. The inherited values are written
     * as the article's own, so nothing has drifted — the drift check runs either
     * way, and a page full of warnings would be measuring a different page.
     */
    private function anOrderOf(int $rows): int
    {
        $lines = [];

        for ($i = 0; $i < $rows; ++$i) {
            if ($i > 0 && $i % self::SUBTOTAL_EVERY === 0) {
                $lines[] = ['id' => null, 'data' => [
                    OrderModule::KIND => OrderModule::SUBTOTAL_LINE,
                    'description' => sprintf('Subtotal after %d', $i),
                ]];

                continue;
            }

            $article = $this->articles[$i % \count($this->articles)];

            $lines[] = ['id' => null, 'data' => [
                OrderModule::KIND => OrderModule::ARTICLE_LINE,
                'article' => $article,
                'description' => sprintf('Article %04d', ($i % \count($this->articles)) + 1),
                OrderModule::QUANTITY => (string) (1 + $i % 7),
                OrderModule::UNIT_PRICE => number_format(5 + (($i % \count($this->articles)) % 400) * 1.35, 2, '.', ''),
                OrderModule::TAX_RATE => '8.1',
            ]];
        }

        return $this->write(
            OrderModule::KEY,
            [
                'contact' => $this->customer,
                'ordered_on' => '2026-08-16',
                'status' => OrderModule::DRAFT,
            ],
            [OrderModule::LINES => $lines],
        );
    }

    /**
     * A record, written the way the application writes one.
     *
     * Through the writer rather than the repository, so the derivers run: the
     * totals, the VAT table and the document number are what a real long
     * document carries, and a fixture that skipped them would be measuring a
     * page that does not exist (§5.9, XIV-73).
     *
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

    /** @return list<int> */
    private static function rowCounts(): array
    {
        $given = (string) ($_SERVER['XIV68_ROWS'] ?? self::DEFAULT_ROWS);

        return array_values(array_filter(
            array_map(static fn (string $part): int => (int) trim($part), explode(',', $given)),
            static fn (int $rows): bool => $rows > 0,
        ));
    }

    /**
     * Straight to standard error, and a line at a time.
     *
     * PHPUnit buffers a test's standard output and prints it when the test ends,
     * which is the one thing this must not do: the run that matters most is the
     * one that dies of memory exhaustion at ten thousand rows, and a buffer is
     * lost when the process is. Standard error is not captured, so everything
     * measured before the crash is already on the terminal.
     */
    private function say(string $line): void
    {
        fwrite(\STDERR, $line);
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
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
        $service = static::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

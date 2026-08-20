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
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\ReleasesTheBrowser;
use App\Tests\Support\SharesATenant;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * The trend chart actually draws, in a browser (XIV-121, XIV-31).
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
    private const string EMAIL = 'e2e@example.test';
    private const string PASSWORD = 'e2e-password';

    /** What the article is called, and therefore how this class finds its own. */
    private const string TITLE = 'Trend-Chart-Testartikel';

    private static ?int $article = null;

    private Client $browser;

    protected function setUp(): void
    {
        self::$article ??= $this->anArticleWithAPriceHistory();

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->signIn();
    }

    public function testThePriceChartIsDrawnOnTheRecordPage(): void
    {
        $this->browser->request('GET', '/m/article/' . self::$article);
        $this->browser->waitForVisibility('.trend-chart canvas');

        // Chart.js writes an explicit pixel size onto the canvas when it lays
        // itself out, so this waits for the library to have arrived and run
        // rather than for a fixed number of milliseconds. The import is lazy, so
        // "has arrived" is a network round trip and not a given.
        $this->browser->waitFor('.trend-chart canvas[style*="px"]');

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

    // -- helpers ------------------------------------------------------------

    /**
     * An article whose price has moved, created through the engine.
     *
     * Through `RecordWriter` rather than through the record form, because what
     * this class is about is the drawing rather than the saving, and because the
     * writer is the only thing that writes history (§5.2) — which is where the
     * chart comes from. Found by title first, so a rerun against a tenant this
     * class has already touched adds nothing and changes no chart.
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

            return self::service(TenantSwitcher::class)->runFor($tenant, function (): int {
                $module = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
                $writer = self::service(RecordWriter::class);

                $record = new Record(['title' => self::TITLE, 'price' => '100.00']);
                $writer->save($module, $record);

                foreach (['120.00', '95.50'] as $price) {
                    $record->set('price', $price);
                    $writer->save($module, $record);
                }

                return (int) $record->id;
            });
        });
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

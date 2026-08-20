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
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Order\OrderModule;

/**
 * A figure typed and read back, in every language this installation offers
 * (XIV-45).
 *
 * **This is the test XIV-44 would have failed.** That defect derived the live
 * totals from the form's *view* values, and a view value is written in the
 * reader's language: `Amount::of('19,90')` is null, so every total on a German
 * page blanked the moment a re-render fed a formatted number back in, and it
 * never recovered, because each render re-read the formatting the last one
 * produced. Four hundred and eighty tests missed it, the browser layer included,
 * for one reason: they all read English, and in English `19.90` on the screen
 * and `19.90` in the database are the same string.
 *
 * **Why the browser, when §9.2's round trips already cover every field type in
 * thirty formatting locales.** Those run without a page: they build the form,
 * take what a browser would post and post it. This layer is the one that
 * exercises the keystroke, the debounce, the re-render and the server's
 * arithmetic at once, and it is the layer the defect actually walked through. A
 * value that survives the round trip and still blanks on the page is exactly the
 * bug that shipped.
 *
 * **Why its own class, and not one more test in CollectionRowsTest**, which is
 * where the interaction below comes from. That file is about the three things
 * only a browser can see, and none of them is about language; a fifth test
 * under that heading would be a file about two subjects. The practical half is
 * recorded in `App\Tests\Support\ReleasesTheBrowser`: writing this test is
 * what found that Panther never closes a Selenium session, so every browser
 * test held a slot on a four-slot grid until the run ended, and a seventeenth
 * test simply could not start.
 *
 * **What this costs, and what a fifth language would cost.** One browser
 * session, one sign-in, and one page load per enabled language. Nothing here
 * multiplies: adding a language adds a page load and no edit, because the
 * languages come from `enabled_locales` and both figures are spelled by ICU
 * rather than written out. §8.3's "deliberately few" is kept by this being one
 * test rather than the browser suite run four times.
 *
 * Every wait is an explicit condition; there is no sleep here and there should
 * never be one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FiguresInEveryLanguageTest extends PantherTestCase
{
    use ReleasesTheBrowser;
    use SharesATenant;

    /**
     * The hostname the browser asks for, which is the application's own compose
     * service: the tenant is provisioned under it, so the Host header the
     * browser sends is one this application recognises.
     */
    private const string HOST = 'xivi-e2e';

    private const string SLUG = 'e2e';

    /**
     * A colleague of this file's own, whose only job is to keep changing which
     * language they work in.
     *
     * Not the `e2e@` account every other browser class signs in as. The language
     * is a column on the user, so rewriting it there would leave whichever class
     * ran next reading its page in Italian, and the shared tenant is shared
     * precisely because there is one hostname both containers resolve.
     */
    private const string EMAIL = 'e2e-languages@example.test';

    private const string PASSWORD = 'e2e-password';

    /** What is typed, and what three of them have to come to. */
    private const float PRICE = 19.9;
    private const float TOTAL = 59.7;

    private static bool $ready = false;

    private Client $browser;

    protected function setUp(): void
    {
        // Before the browser, not after: provisioning runs every tenant
        // migration, and a session opened first would sit idle through all of it
        // until the grid reaped it.
        if (!self::$ready) {
            $this->provision();
            self::$ready = true;
        }

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->signIn();
    }

    /**
     * Three at nineteen ninety is fifty-nine seventy, in whichever language
     * somebody works in.
     *
     * The interaction is `CollectionRowsTest::testTheCaretSurvivesTheTotalsUpdating()`'s,
     * which is the one XIV-44 broke: add a line, type a quantity, type a price,
     * and wait for the line total the server sends back. What is new is that it
     * happens once per enabled language, with both figures **spelled by ICU
     * rather than written out here**: a test quoting `19,90` would be a second
     * copy of CLDR's data, wrong the first time the first copy moved, which is
     * the rule `SwissFiguresTest` sets.
     *
     * A total that never arrives is the German symptom of XIV-44 exactly; a
     * total that arrives spelled the wrong way is the other half of the same
     * mistake, and both fail here with the expected spelling in the message.
     */
    public function testAPriceTypedInEachLanguageComesBackAsATotalInThatLanguage(): void
    {
        foreach ($this->enabledLanguages() as $language) {
            $this->workIn($language);

            $this->browser->request('GET', '/m/order/new');
            // `main form`, not `form`: the first form on every signed-in page is
            // the sign-out form inside the top bar's menu, which is invisible
            // until somebody opens the menu and is meant to be.
            $this->browser->waitForVisibility('main form');

            $this->addLine();
            $this->browser->waitFor('[name$="[fields][unit_price]"]');

            $price = $this->browser->getCrawler()->filter('[name$="[fields][unit_price]"]')->attr('name');
            self::assertIsString($price);

            // A quantity first, so the line has something to multiply by.
            $this->typeInto(str_replace('[unit_price]', '[quantity]', $price), '3');
            $this->typeInto($price, $this->figure(self::PRICE, $language));

            $this->waitForValue(
                str_replace('[unit_price]', '[line_total]', $price),
                $this->figure(self::TOTAL, $language),
                $language,
            );
        }
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Put the signed-in colleague into that language, for the next request on.
     *
     * **Outside the rollback**, like everything else this file writes: the
     * browser is another process and cannot see this test's transaction, so a
     * language change left inside it would be a change the page never reads. The
     * language is resolved per request from the user and never parked in the
     * session (§8.4.2), which is why nothing has to sign in again between
     * languages.
     */
    private function workIn(string $language): void
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::withoutRollback(function () use ($tenant, $language): void {
            self::service(TenantSwitcher::class)->runFor($tenant, function () use ($language): void {
                $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
                self::assertInstanceOf(User::class, $user);

                self::service(UserManager::class)->setLocale($user, $language);
            });
        });
    }

    /**
     * A figure as this language writes it.
     *
     * The formatting locale is composed the way the application composes it
     * (§8.4.2): this tenant has chosen no region, so a language stands for
     * itself here and `FormattingLocale` would hand ICU the same string. Two
     * fraction digits, which is what a money widget of ours is configured with
     * (§5.9), and grouping is irrelevant at these sizes.
     */
    private function figure(float $value, string $language): string
    {
        $formatter = new \NumberFormatter($language, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

        $formatted = $formatter->format($value);
        self::assertNotFalse($formatted, sprintf('ICU can write a figure in %s', $language));

        return $formatted;
    }

    /**
     * Which languages this installation promises to serve.
     *
     * Read from `enabled_locales` rather than listed, which is the promise a
     * language in the picker is served whole. `TranslationCatalogueTest` holds
     * the words to it; this holds the figures.
     *
     * @return list<string>
     */
    private function enabledLanguages(): array
    {
        /** @var list<string> $languages */
        $languages = self::getContainer()->getParameter('kernel.enabled_locales');

        return $languages;
    }

    /**
     * Wait until a field holds a value.
     *
     * Not `waitForElementToContain`, which reads an element's *text*: a total
     * lives in an input's value and an input has no text, so that wait would sit
     * out its thirty seconds while the right number was on the screen the whole
     * time. The same reasoning, and the same code, as CollectionRowsTest.
     */
    private function waitForValue(string $name, string $expected, string $language): void
    {
        $script = sprintf(
            'const el = document.getElementsByName(%s)[0]; return el ? el.value : null;',
            json_encode($name, \JSON_THROW_ON_ERROR),
        );

        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if ($this->browser->executeScript($script) === $expected) {
                return;
            }

            usleep(250_000);
        }

        self::fail(sprintf(
            'Reading in %s, "%s" never reached "%s" and holds "%s". An empty total is XIV-44 exactly: '
            . 'something read the form\'s displayed values where it wanted the stored ones, and this '
            . 'language is one that tells the two apart (docs/architecture/decisions.md §9.2).',
            $language,
            $name,
            $expected,
            (string) $this->browser->executeScript($script),
        ));
    }

    /**
     * Type the way a person does: a real `input` event, so the component
     * notices. Setting `.value` alone changes the DOM and tells nothing.
     */
    private function typeInto(string $name, string $value): void
    {
        $this->browser->executeScript(sprintf(
            'const el = document.getElementsByName(%s)[0];'
            . ' el.value = %s;'
            . ' el.dispatchEvent(new Event("input", {bubbles: true}));'
            . ' el.dispatchEvent(new Event("change", {bubbles: true}));',
            json_encode($name, \JSON_THROW_ON_ERROR),
            json_encode($value, \JSON_THROW_ON_ERROR),
        ));
    }

    /** Press the button that adds a line somebody types into. */
    private function addLine(): void
    {
        $this->browser->executeScript(sprintf(
            'document.querySelector(%s).click();',
            json_encode(
                sprintf('[data-live-action-param="addRow"][data-live-kind-param="%s"]', OrderModule::CUSTOM_LINE),
                \JSON_THROW_ON_ERROR,
            ),
        ));
    }

    /**
     * The end-to-end tenant, which this class adds one user to.
     *
     * Written defensively because the browser classes share the tenant and any
     * of them may run first: the modules install idempotently and the user is
     * created only if nobody has yet. Nothing here may be rolled back, since the
     * browser is another process making real requests and cannot see this test's
     * transaction.
     */
    private function provision(): void
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::withoutRollback(function () use ($tenant): void {
            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                $installer = self::service(ModuleInstaller::class);
                $registry = self::service(ModuleRegistry::class);

                foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                    $installer->install($registry->get($key));
                }
            });

            if (self::service(TenantSwitcher::class)->runFor(
                $tenant,
                fn (): bool => self::service(UserRepository::class)->findOneByEmail(self::EMAIL) === null,
            )) {
                self::service(UserCreator::class)->create($tenant, self::EMAIL, 'Sprachen', self::PASSWORD, ['ROLE_ADMIN']);
            }
        });
    }

    private function signIn(): void
    {
        $this->browser->request('GET', '/login');
        $this->browser->waitForVisibility('form');

        $this->browser->submit($this->browser->getCrawler()->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));

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

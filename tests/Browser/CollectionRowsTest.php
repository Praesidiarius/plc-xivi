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
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Order\OrderModule;

/**
 * The three things only a browser can see (XIV-31).
 *
 * Every other test in this suite calls the component: it invokes `addRow` and
 * `save` directly, which is what makes it honest about what the server does and
 * blind to whether the page does anything. The Stimulus attributes that turn
 * those buttons into a request are, without this file, asserted by nobody.
 *
 * That is not a hypothetical gap. Building the Live Components spikes I wrote
 * `data-live-action` where Stimulus wants `data-action` — buttons that would
 * never have fired — and a full green suite said nothing at all.
 *
 * **Deliberately three tests.** An end-to-end layer is where flakiness lives,
 * flaky tests get skipped, and a skipped safety net is worse than none because
 * everybody believes it is there. Every wait below is an explicit `waitFor*`;
 * there is no sleep anywhere, and there should never be one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionRowsTest extends PantherTestCase
{
    use SharesATenant;

    /**
     * The hostname the browser asks for: the application's own compose service.
     *
     * The tenant is provisioned under it, so the Host header the browser sends
     * is one this application recognises — which rules out the service name
     * `php`, since that is a system host and is served without a tenant on
     * purpose. See compose.override.yaml, where the name is both a hostname and
     * a network alias for reasons that are not interchangeable.
     */
    private const string HOST = 'xivi-e2e';

    private const string SLUG = 'e2e';
    private const string EMAIL = 'e2e@example.test';
    private const string PASSWORD = 'e2e-password';

    private static bool $ready = false;

    private Client $browser;

    protected function setUp(): void
    {
        // Three arguments, and the third is the one that matters: the second is
        // for the kernel, so a Selenium host passed there is silently ignored and
        // the driver is asked for at no address at all.
        // **Before the browser, not after.** Provisioning creates a database and
        // runs every tenant migration into it, which takes long enough that a
        // session opened first would sit idle through all of it — and an idle
        // session is one the grid reaps, after which the test fails with a
        // timeout that says nothing about what it was waiting for.
        if (!self::$ready) {
            $this->provision();
            self::$ready = true;
        }

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            // No `/wd/hub`: that path is the Selenium 3 convention, and on 4 a
            // request to it hangs rather than 404s, which reads like a browser
            // that will not start. The session endpoint is at the root.
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->signIn();
    }

    /**
     * The assertion everything else here depends on.
     *
     * If Stimulus never starts — a bad importmap, an asset that was never
     * installed, a CSP — every other test in this file would be asserting
     * against a page that simply does nothing, and a safety net that cannot fail
     * is not one. A live component announces itself by getting its controller
     * connected, which is what this looks for.
     */
    public function testTheComponentIsConnectedInTheBrowser(): void
    {
        $this->browser->request('GET', '/m/order/new');
        $this->browser->waitForVisibility('form');

        self::assertTrue(
            $this->browser->executeScript('return typeof window.Stimulus === "object";'),
            'Stimulus started, so the component can be live at all',
        );
        self::assertTrue(
            $this->browser->executeScript(
                'return document.querySelector("[data-controller~=\'live\']") !== null;',
            ),
            'and the record form is one',
        );
    }

    /** Pressing a button adds a row of that kind, in place. */
    public function testAddingALineDoesNotReloadThePage(): void
    {
        $this->browser->request('GET', '/m/order/new');
        $this->browser->waitForVisibility('form');

        // A mark on the page itself. If the swap turns into a navigation this is
        // gone, and the assertion below says so — which is the difference
        // between "a row appeared" and "a row appeared without a page load".
        $this->browser->executeScript('window.__stillHere = true;');

        $this->addLine(OrderModule::CUSTOM_LINE);

        $this->browser->waitFor('[name$="[fields][unit_price]"]');

        self::assertTrue(
            $this->browser->executeScript('return window.__stillHere === true;'),
            'the page was swapped into, not reloaded',
        );
    }

    /** And what is already typed survives it, which is the whole point of a swap. */
    public function testWhatIsAlreadyTypedSurvivesAddingALine(): void
    {
        $this->browser->request('GET', '/m/order/new');
        $this->browser->waitForVisibility('form');

        $this->addLine(OrderModule::CUSTOM_LINE);
        $this->browser->waitFor('[name$="[fields][description]"]');

        $description = $this->browser->getCrawler()->filter('[name$="[fields][description]"]')->attr('name');
        self::assertIsString($description);

        $this->browser->executeScript(sprintf(
            'document.getElementsByName(%s)[0].value = "Consulting";',
            json_encode($description, \JSON_THROW_ON_ERROR),
        ));

        $this->addLine(OrderModule::COMMENT_LINE);
        $this->browser->waitForElementToContain('body', 'Comment');

        self::assertSame(
            'Consulting',
            $this->browser->executeScript(sprintf(
                'return document.getElementsByName(%s)[0].value;',
                json_encode($description, \JSON_THROW_ON_ERROR),
            )),
            'the row that was already there kept what was typed into it',
        );
    }

    /**
     * The caret stays where it was while the totals update around it (XIV-32).
     *
     * **This is the assertion the framework was chosen for.** A form that
     * redraws while somebody is typing has to update the changed nodes rather
     * than replace the region — replace it, and the input goes with it, taking
     * the cursor mid-number. Nothing on the server can see that happen: to the
     * server both mechanisms produce the same HTML, and the difference is
     * entirely in what the browser does with it.
     *
     * So: type a price, put the caret in the middle of it, wait for the figure
     * to arrive, and check the caret has not moved.
     */
    public function testTheCaretSurvivesTheTotalsUpdating(): void
    {
        $this->browser->request('GET', '/m/order/new');
        $this->browser->waitForVisibility('form');

        $this->addLine(OrderModule::CUSTOM_LINE);
        $this->browser->waitFor('[name$="[fields][unit_price]"]');

        $price = $this->browser->getCrawler()->filter('[name$="[fields][unit_price]"]')->attr('name');
        self::assertIsString($price);
        $quantity = str_replace('[unit_price]', '[quantity]', $price);

        // A quantity first, so the line has something to multiply by.
        $this->typeInto($quantity, '3');

        // Then the price, with the caret parked three characters in — between
        // the 9 and the . of "19.90", which is where somebody correcting a
        // number actually stands.
        $this->typeInto($price, '19.90');
        $this->browser->executeScript(sprintf(
            'const el = document.getElementsByName(%s)[0]; el.focus(); el.setSelectionRange(3, 3);',
            json_encode($price, \JSON_THROW_ON_ERROR),
        ));

        // The debounce is 400ms; the figure arrives after it.
        $total = str_replace('[unit_price]', '[line_total]', $price);
        $this->waitForValue($total, '59.70');

        self::assertSame(
            3,
            $this->browser->executeScript(sprintf(
                'return document.getElementsByName(%s)[0].selectionStart;',
                json_encode($price, \JSON_THROW_ON_ERROR),
            )),
            'the caret is still between the 9 and the point',
        );

        self::assertSame(
            $price,
            $this->browser->executeScript('return document.activeElement.getAttribute("name");'),
            'and the field somebody was typing in still has the focus',
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Wait until a field holds a value.
     *
     * Not `waitForElementToContain`, which reads an element's *text*: a total
     * lives in an input's value and an input has no text, so that wait would sit
     * there for thirty seconds while the right number was on the screen the
     * whole time.
     */
    private function waitForValue(string $name, string $expected): void
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
            '"%s" never reached "%s" — it holds "%s".',
            $name,
            $expected,
            (string) $this->browser->executeScript($script),
        ));
    }

    /**
     * Type into a field the way a person does — a real `input` event, so the
     * component notices. Setting `.value` alone changes the DOM and tells
     * nothing, which is fine where a test only wants a value parked somewhere
     * and useless where it wants the form to react.
     */
    private function typeInto(string $name, string $value): void
    {
        $this->browser->executeScript(sprintf(
            'const el = document.getElementsByName(%s)[0];'
            .' el.value = %s;'
            .' el.dispatchEvent(new Event("input", {bubbles: true}));'
            .' el.dispatchEvent(new Event("change", {bubbles: true}));',
            json_encode($name, \JSON_THROW_ON_ERROR),
            json_encode($value, \JSON_THROW_ON_ERROR),
        ));
    }

    /** Press the button that adds a line of that kind. */
    private function addLine(string $kind): void
    {
        $this->browser->executeScript(sprintf(
            'document.querySelector(%s).click();',
            json_encode(
                sprintf('[data-live-action-param="addRow"][data-live-kind-param="%s"]', $kind),
                \JSON_THROW_ON_ERROR,
            ),
        ));
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
     * One tenant for the file, made once and left alone.
     *
     * **Nothing here may be rolled back**, and that is the whole difference from
     * every other test in this suite. The browser is another process making real
     * requests, so it cannot see this test's transaction — which is the basis of
     * the suite's speed (XIV-9, XIV-10) and simply does not apply here. Left to
     * DAMA, the tenant's migrations run inside a transaction that is thrown away
     * and the browser is handed a database with no tables in it, which is a
     * confusing way to be told about a login failure.
     *
     * `sharedTenant()` already provisions outside the rollback and reclaims what
     * the last run left behind; the modules and the user need the same treatment
     * for the same reason.
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

            self::service(UserCreator::class)->create($tenant, self::EMAIL, 'E2E', self::PASSWORD, ['ROLE_ADMIN']);
        });
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

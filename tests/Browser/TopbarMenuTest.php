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

use App\Tenant\Security\UserCreator;
use App\Tests\Support\ReleasesTheBrowser;
use App\Tests\Support\SharesATenant;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * The top bar's menu actually opens (XIV-77).
 *
 * Everything else about that menu — which items it holds, who is allowed to see
 * each of them, which one is marked as the page you are on — is asserted without
 * a browser in {@see \App\Tests\Functional\Tenant\TopbarMenuTest}, because it is
 * all in the HTML. **Whether the thing opens at all is not in the HTML.** It is
 * `data-bs-toggle="dropdown"` meeting a Bootstrap listener that has to have been
 * registered, and a functional test looking at the markup would pass on a page
 * whose entire right-hand navigation is unreachable.
 *
 * That is not a hypothetical. `assets/app.js` imports one named export —
 * `import { Tooltip } from 'bootstrap'` — and the dropdowns work because that
 * pulls in Bootstrap's whole ES module, data API and all, as a side effect.
 * Narrowing it to `bootstrap/js/dist/tooltip` would be an obvious tidy-up, would
 * shrink the bundle, and would leave every menu in the application dead with a
 * green suite. This file is what fails then.
 *
 * Two tests, both about the same event and one of them about the width where it
 * behaves differently. Kept as short as that on purpose: an end-to-end layer is
 * where flakiness lives, and see the note at the top of
 * {@see CollectionRowsTest} for the rest of that argument.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TopbarMenuTest extends PantherTestCase
{
    use ReleasesTheBrowser;
    use SharesATenant;

    /** The hostname the browser asks for — see {@see CollectionRowsTest::HOST}. */
    private const string HOST = 'xivi-e2e';

    /**
     * The same tenant the other browser test uses, deliberately.
     *
     * One hostname routes to one tenant, and `xivi-e2e` is the only name the
     * browser container can resolve back to this application, so a second slug
     * here would be a second tenant nothing could reach. `sharedTenant()` hands
     * back the existing one when another class has already made it.
     */
    private const string SLUG = 'e2e';

    private const string EMAIL = 'topbar@example.test';
    private const string PASSWORD = 'topbar-password';

    /** Wide enough for the whole bar on one row. */
    private const array DESKTOP = [1440, 900];

    /** A phone, and the width the bar has to survive. */
    private const array PHONE = [390, 844];

    private static bool $ready = false;

    private Client $browser;

    protected function setUp(): void
    {
        // Before the browser, for the reason CollectionRowsTest sets out at
        // length: provisioning takes long enough that a session opened first
        // would be reaped for idling.
        if (!self::$ready) {
            $this->provision();
            self::$ready = true;
        }

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );
    }

    /**
     * It opens, it holds the items, and signing out of it still signs you out.
     *
     * The sign-out half is here rather than in the functional test for the same
     * reason as the rest: it is a submit button inside a dropdown, and "the form
     * posts when the button in the menu is clicked" is a sentence about a
     * browser. A menu that swallowed the click, or an item rendered outside its
     * form, would look perfectly correct in the markup.
     */
    public function testTheMenuOpensAndSignsYouOut(): void
    {
        $this->signIn(self::DESKTOP);

        // Shut to begin with: the items are in the document from the start, so
        // "the menu works" has to be asked of what is *visible*, not of what is
        // present.
        self::assertFalse($this->menuIsOpen(), 'the menu starts closed');

        $this->browser->findElement(WebDriverBy::cssSelector('.navbar .dropdown-toggle'))->click();
        $this->browser->waitForVisibility('.navbar .dropdown-menu.show');

        self::assertTrue($this->menuIsOpen(), 'Bootstrap opened it');

        $items = $this->browser->getCrawler()->filter('.navbar .dropdown-menu .dropdown-item')->each(
            static fn ($node): string => trim($node->text()),
        );

        self::assertContains('Account', $items);
        self::assertContains('Sign out', $items);

        // And the way out still works from inside the menu.
        $this->browser->findElement(WebDriverBy::cssSelector('.navbar .dropdown-menu form button'))->click();

        $this->browser->waitForVisibility('form[action*="login"], .signin-shell');

        self::assertStringContainsString('/login', (string) $this->browser->getCurrentURL());
    }

    /**
     * The same menu on a phone-sized screen.
     *
     * This navbar is `navbar-expand`, so it never collapses into a toggler —
     * there is no hamburger for a dropdown to be trapped inside, which is the
     * failure mode the ticket asked about, and it cannot happen here. What can
     * happen is the other one: a menu that opens off the right-hand edge of the
     * screen, where half of it cannot be read and none of it can be scrolled to.
     * `dropdown-menu-end` plus Bootstrap's own repositioning is what prevents
     * that, and this is the assertion that says so.
     */
    public function testTheMenuStaysOnTheScreenAtPhoneWidth(): void
    {
        $this->signIn(self::PHONE);

        $this->browser->findElement(WebDriverBy::cssSelector('.navbar .dropdown-toggle'))->click();
        $this->browser->waitForVisibility('.navbar .dropdown-menu.show');

        /** @var array{left: float, right: float, width: float} $box */
        $box = $this->browser->executeScript(
            'const r = document.querySelector(".navbar .dropdown-menu.show").getBoundingClientRect();'
            . ' return {left: r.left, right: r.right, width: document.documentElement.clientWidth};',
        );

        // Measured against the width the page actually got rather than the one
        // that was asked for — see signIn(), which cannot always get 390.
        self::assertLessThan(576, $box['width'], 'narrow enough to be the case this test is about');
        self::assertGreaterThanOrEqual(0, $box['left'], 'the menu does not start off the left edge');
        self::assertLessThanOrEqual(
            $box['width'] + 1,
            $box['right'],
            'nor run off the right one — a menu half of which is off-screen is a menu with items nobody can press',
        );
    }

    // -- helpers ------------------------------------------------------------

    private function menuIsOpen(): bool
    {
        return true === $this->browser->executeScript(
            'return document.querySelector(".navbar .dropdown-menu.show") !== null;',
        );
    }

    /**
     * @param array{int, int} $size
     */
    private function signIn(array $size): void
    {
        $this->browser->manage()->window()->setSize(new WebDriverDimension($size[0], $size[1]));

        // **A desktop Chromium will not make its window narrower than about 500
        // pixels**, whatever it is asked for, so the line above alone tests a
        // width no phone has. The viewport is overridden through the dev tools
        // protocol instead, which does reach 390 — and if this grid ever stops
        // answering that command the size above still applies and the assertion
        // it feeds measures whatever was really rendered, rather than a number
        // this file assumed.
        $driver = $this->browser->getWebDriver();

        if ($driver instanceof RemoteWebDriver) {
            try {
                $driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
                    'cmd' => 'Emulation.setDeviceMetricsOverride',
                    'params' => [
                        'width' => $size[0],
                        'height' => $size[1],
                        'deviceScaleFactor' => 1,
                        'mobile' => $size[0] < 576,
                    ],
                ]);
            } catch (\Throwable) {
            }
        }

        $this->browser->request('GET', '/login');
        $this->browser->waitForVisibility('form');

        $form = $this->browser->getCrawler()->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->browser->submit($form);

        $this->browser->waitForVisibility('.navbar .dropdown-toggle');
    }

    /**
     * A user of this class's own, in the shared tenant, committed rather than
     * rolled back — the browser is another process and cannot see this test's
     * transaction. The same reasoning as {@see CollectionRowsTest::provision()},
     * and the reason the email is not that class's.
     */
    private function provision(): void
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::withoutRollback(function () use ($tenant): void {
            $users = self::getContainer()->get(UserCreator::class);
            \assert($users instanceof UserCreator);

            $users->create($tenant, self::EMAIL, 'Nina Baumgartner', self::PASSWORD, ['ROLE_ADMIN']);
        });
    }
}

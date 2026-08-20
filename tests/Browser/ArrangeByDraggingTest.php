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
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Moving a field by dragging it, and by pressing a button (XIV-165, XIV-31).
 *
 * **This is the only layer that can see any of it.** What the arrange page sends
 * has not changed, one POST with `position[id]` per field in tens, so every
 * functional test of that form passes whether the rows can be dragged or not,
 * and would go on passing if `assets/controllers/arrange_fields_controller.js`
 * were deleted, if its import of SortableJS failed to resolve, or if the handle
 * lost the class the library is told to look for. The page would render
 * perfectly and simply not move under the cursor, under a green suite. That is
 * the same failure {@see CollectionRowsTest} was written after, one page along.
 *
 * The rule from that file holds here, and this class is short for its sake: an
 * end-to-end layer is where flakiness lives, a flaky test gets skipped, and a
 * skipped safety net is worse than none (§8.3). So there are two claims below
 * and nothing else, and neither is checked against a number this file knows.
 *
 * ### Why neither assertion can pass by accident
 *
 * Both are read **after a save and a reload**, off a page the server built from
 * the definitions it has just written. A drag that moved the rows on screen and
 * never reached the hidden inputs fails; so does one that reached them and was
 * refused on the way in. Nothing here asserts that a DOM node moved, which is
 * the assertion that would pass while the feature did nothing.
 *
 * And the expected order is **computed from the order the page opened in**,
 * never written down. The two fields it swaps are whichever two the Contact
 * module happens to put first, so this cannot drift into agreeing with a
 * hard-coded list that stopped being true; if the swap does not happen, the
 * assertion compares a list against itself reordered and fails.
 *
 * ### It puts the module back
 *
 * The browser classes share one tenant because there is exactly one hostname
 * both containers can resolve, so a class that leaves the Contact form in a
 * different order is a class that changes what the others open. The restoring
 * move is the keyboard one, which makes the tidying up the second half of the
 * feature rather than a chore: dragging is an addition and never the only way,
 * and the button that proves it is the button that puts things back.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArrangeByDraggingTest extends PantherTestCase
{
    use ReleasesTheBrowser;
    use SharesATenant;

    /** The one hostname the browser's container resolves back here; see {@see CollectionRowsTest::HOST}. */
    private const string HOST = 'xivi-e2e';
    private const string SLUG = 'e2e';
    private const string EMAIL = 'arrange@example.test';
    private const string PASSWORD = 'arrange-password';

    /**
     * A window of a size this file decided, rather than whichever one the grid
     * hands out.
     *
     * A drag is arithmetic on pixels: the pointer goes down on a handle and
     * moves by offsets, and where it ends up depends on how tall a table row is
     * and on whether the list scrolled underneath it while it was moving. None
     * of that is worth leaving to a default that a Selenium image is free to
     * change between versions. Wide enough that no column wraps and tall enough
     * that the first rows of the table are on screen when the page opens, which
     * is all this gesture needs.
     */
    private const array WINDOW = [1280, 720];

    private static bool $ready = false;

    private Client $browser;

    protected function setUp(): void
    {
        // Before the browser is opened, because provisioning takes long enough
        // that a session opened first would be reaped for idling.
        if (!self::$ready) {
            $this->provision();
            self::$ready = true;
        }

        $this->browser = self::createPantherClient(
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->browser->manage()->window()->setSize(new WebDriverDimension(self::WINDOW[0], self::WINDOW[1]));

        $this->signIn();
    }

    /**
     * Drag the second field above the first, save, and it stayed there.
     *
     * Then press the arrow that undoes it, save, and it went back. Two
     * assertions and two round trips, because the two gestures are one feature:
     * §5.1's decision is that arranging is spatial *and* that the spatial way is
     * never the only way, and a test covering only the drag would leave the half
     * that somebody without a mouse depends on unwatched.
     */
    public function testAFieldMovesByDraggingAndComesBackByKeyboard(): void
    {
        $this->openTheArrangePage();

        $before = $this->fieldsInOrder();
        self::assertGreaterThan(1, \count($before), 'the Contact module has fields to rearrange');

        $swapped = $before;
        [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];

        $this->dragTheSecondRowAboveTheFirst();
        // A wait rather than an assertion: if the gesture did not take, the
        // failure should name the drag rather than the save that followed it.
        $this->browser->wait(5)->until(fn (): bool => $this->fieldsInOrder() === $swapped);

        $this->save();

        self::assertSame($swapped, $this->fieldsInOrder(), 'the drag is what the form saved');

        $this->pressTheDownArrowOnTheFirstRow();
        $this->browser->wait(5)->until(fn (): bool => $this->fieldsInOrder() === $before);

        $this->save();

        self::assertSame($before, $this->fieldsInOrder(), 'and a keyboard moves the same field back');
    }

    // -- the gestures -------------------------------------------------------

    /**
     * A real drag, driven as a pointer rather than as a `dragstart` nobody sent.
     *
     * The controller configures SortableJS with `forceFallback`, which puts it on
     * plain pointer events; this is the other end of that decision, and the
     * reason it was not left on the browser's own drag-and-drop API, which
     * WebDriver cannot produce at all.
     *
     * Several small moves rather than one jump, because that is what a drag is
     * made of: the library starts the drag on the first move after the button
     * goes down and works out where the row belongs on every one after it. A
     * single hop to the destination would be one event and could land the row
     * anywhere. The last step stops in the *upper* half of the row being passed,
     * which is what "above this one" means to a sortable list.
     */
    private function dragTheSecondRowAboveTheFirst(): void
    {
        $rows = $this->browser->findElements(WebDriverBy::cssSelector('main tbody tr[data-field-label]'));
        $driver = $this->browser->getWebDriver();
        \assert($driver instanceof RemoteWebDriver);

        $driver->action()
            ->clickAndHold($rows[1]->findElement(WebDriverBy::cssSelector('.arrange-handle')))
            ->moveByOffset(0, -8)
            ->moveByOffset(0, -12)
            ->moveToElement($rows[0])
            ->moveByOffset(0, -8)
            ->release()
            ->perform();
    }

    /**
     * And the same move made with the keyboard, on the button beside the handle.
     *
     * Focused with a script and then pressed with a real key, which is the
     * combination that asks the right question. `.focus()` proves nothing about
     * reachability on its own, since a `<button>` is focusable by construction,
     * but the key is not a click: it goes to whatever the document has focused, so
     * what this exercises is a button that a keyboard both reaches and can
     * operate. A `click()` here would have been the mouse again, wearing the
     * other test's clothes.
     */
    private function pressTheDownArrowOnTheFirstRow(): void
    {
        $this->browser->executeScript(
            'document.querySelector("main tbody tr[data-field-label]").querySelectorAll("button")[1].focus();',
        );

        $this->browser->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The fields of the Contact form, in the order the table has them.
     *
     * Off `data-field-label`, which the row carries for the live region's sake,
     * rather than off the link text: one string per row, already the customer's
     * own word, and nothing to trim.
     *
     * @return list<string>
     */
    private function fieldsInOrder(): array
    {
        /** @var list<string> $labels */
        $labels = $this->browser->executeScript(
            'return Array.from(document.querySelectorAll("main tbody tr[data-field-label]"))'
            . '.map((row) => row.dataset.fieldLabel);',
        );

        return $labels;
    }

    /**
     * Press Save and wait for the page the server sends back.
     *
     * The wait is for the flash, not for the table: the table is on the page
     * being left as well, so waiting for it would be satisfied before anything
     * had been sent and would read the old order back.
     *
     * **Getting the button somewhere clickable took three lines and each is
     * load-bearing**, which is worth writing down because every one of them
     * looks like superstition and none of them is.
     *
     * `scroll-behavior: smooth` is the first, and it is Bootstrap's, set on the
     * root element for everybody. A scroll is therefore an animation, so an
     * element scrolled to has not arrived by the time the next command runs, and
     * WebDriver clicks where the button *was*. That is the whole of the mystery:
     * a click that missed by a few hundred pixels and reported an interception
     * somewhere else entirely. `behavior: "instant"` on the call would be enough
     * for our own scroll and is not enough for the one WebDriver does before a
     * click, so the property is turned off on the document instead.
     *
     * The padding is the second. Save is the last control on this page, and the
     * furthest a page can scroll leaves its last element flush against the
     * bottom of the window at any window size, which is where a development
     * instance puts the Symfony debug toolbar. Half a screen of space underneath
     * gives the button somewhere to be scrolled *to*.
     *
     * The scroll itself is the third, and with the other two it does what it
     * says: the button ends up in the middle of the window, where a click hits
     * it. Nothing about the application is changed, and nothing is waited for.
     */
    private function save(): void
    {
        $this->browser->executeScript(
            'document.documentElement.style.scrollBehavior = "auto";'
            . ' document.body.style.paddingBottom = "50vh";',
        );

        $button = $this->browser->findElement(WebDriverBy::cssSelector('main button[type="submit"]'));
        $this->browser->executeScript('arguments[0].scrollIntoView({block: "center", behavior: "instant"});', [$button]);
        $button->click();

        $this->browser->waitForVisibility('main .alert');
    }

    /**
     * The arrange page, reached the way somebody reaches it.
     *
     * Through the editor's own doors rather than by a URL this test knows, so
     * the way in is under test too ([XIV-163] made it three pages and this is
     * the third of them).
     */
    private function openTheArrangePage(): void
    {
        $this->browser->request('GET', '/m/contact/fields');
        // `main a`, not `form`: the first form on every signed-in page is the
        // sign-out form inside the top bar's menu, which is invisible until
        // somebody opens the menu and is meant to be.
        $this->browser->waitForVisibility('main a');

        $href = (string) $this->browser->executeScript(
            'return document.querySelector(\'main a[href$="/arrange"]\').getAttribute("href");',
        );

        $this->browser->request('GET', $href);
        $this->browser->waitForVisibility('main tbody tr[data-field-label] .arrange-handle');
    }

    /**
     * The end-to-end tenant, which this class needs nothing added to.
     *
     * Written defensively because the browser classes share the tenant and any
     * of them may run first: the module installs idempotently and the user is
     * created only if nobody has yet. Nothing here may be rolled back: the
     * browser is another process making real requests and cannot see this test's
     * transaction.
     */
    private function provision(): void
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::withoutRollback(function () use ($tenant): void {
            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                self::service(ModuleInstaller::class)->install(
                    self::service(ModuleRegistry::class)->get(ContactModule::KEY),
                );
            });

            if (self::service(TenantSwitcher::class)->runFor(
                $tenant,
                fn (): bool => self::service(UserRepository::class)->findOneByEmail(self::EMAIL) === null,
            )) {
                self::service(UserCreator::class)->create($tenant, self::EMAIL, 'Arrange', self::PASSWORD, ['ROLE_ADMIN']);
            }
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

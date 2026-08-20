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

use App\Tests\Support\ReleasesTheBrowser;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Signup\SelfServiceSlug;
use Xivi\ControlPlane\Signup\SignupHost;

/**
 * The address appearing as somebody types their company name (XIV-105).
 *
 * ### Why this needed a browser, when almost nothing else about signup does
 *
 * {@see \App\Tests\Functional\ControlPlane\SignupPageTest} drives every route
 * this page owns with a real client: the form renders, `POST /signup/name`
 * answers with a name and a verdict, `POST /signup` records a request and sends a
 * mail, and a refusal comes back as words a visitor can act on. All of it is
 * true, and all of it would go on being true with the page's script deleted.
 *
 * What joins the two is sixty lines of Stimulus, and [XIV-84] is the reason that
 * is not left unproven. The dashboard's lens buttons were moved onto a live
 * action, the server-side tests called that action directly, and what shipped was
 * `data-action="live#action|prevent"` — Stimulus reads `|` as a separator between
 * *actions*, so it looked for a method named `prevent`, found none, and threw at
 * connect time. Every button on the page was inert and the suite was green,
 * because no test had ever pressed one. **This page has the same shape**: three
 * `data-signup-name-target` attributes, two `data-action` descriptors and one
 * value name, none of which any server-side assertion can see, in front of the
 * one page in this repository that strangers reach.
 *
 * ### What is deliberately *not* being tested here, and it is most of it
 *
 * The derivation is the server's. §8.13 and {@see SelfServiceSlug::derive()} give
 * the argument at length — a transliteration rule copied into the browser
 * disagrees with the server's on the first umlaut somebody types, which is
 * [XIV-100] one layer further out — so the script contains no rule at all. The
 * risky half is therefore already covered by fast, in-process tests, and what is
 * left uncovered is *wiring*: whether the boxes are connected to the controller,
 * whether the controller reaches the route, and whether what comes back is put
 * where a visitor will see it.
 *
 * That is why there are two tests here rather than a suite. The discipline
 * {@see CollectionRowsTest} sets out applies unchanged: an end-to-end layer is
 * where flakiness lives, flaky tests get skipped, and a skipped safety net is
 * worse than none. Every wait below is an explicit `waitFor*` and there is no
 * sleep anywhere.
 *
 * ### The two tests are chosen so that neither can pass by accident
 *
 * **The available one asserts the name a naive script could not have produced.**
 * `Müller Söhne AG` becomes `mueller-soehne-ag` because
 * {@see SelfServiceSlug::TRANSLITERATION_LOCALE} is `de`; every browser-side
 * slugifier anybody would reach for gives `muller-sohne-ag` or worse. So the
 * expectation is computed by calling the *server's* own deriver from this
 * process, and the assertion is that what appeared in the box is what the server
 * would create — not merely that something appeared.
 *
 * **The unavailable one needs no fixture at all**, which is what makes it cheap
 * and deterministic: `admin` is in {@see SelfServiceSlug} 's reserved list, so the
 * verdict is a property of the code rather than of a row somebody committed. It
 * exercises the other side of `report()` — the red class, and the absence of the
 * green one — which is the half a test that only ever sees a free name would
 * leave inert.
 *
 * ### How the browser reaches an `https`-only, host-bound page
 *
 * It should not be able to, and the fact that it can is a property of the test
 * harness rather than of this application. Panther serves the app with `php -S`,
 * which speaks plain HTTP on an ephemeral port; the signup routes carry the
 * signup host and `https`, stamped on by {@see \Xivi\ControlPlane\Routing\SignupRouteLoader}
 * because the surface behind them mints mailbox-proving links and carries a shared
 * secret in a header. Two things bridge that and neither is in the application:
 *
 *   * `compose.override.yaml` gives the application container a second network
 *     alias, so `SIGNUP_HOST` — `signup.e2e` in `.env.test` — is a name the
 *     browser container's resolver answers with the address the web server is
 *     already listening on. The `Host` header is then simply what the browser
 *     asked for.
 *   * `tests/panther-router.php` stands in for the TLS terminator production has,
 *     and tells the front controller that a request to *that hostname and no
 *     other* arrived securely.
 *
 * {@see testTheRouteTheBrowserReachedIsStillTlsOnlyAndHostBound()} is the
 * compensating assertion, and it is here rather than in a unit test on purpose:
 * the one place a reader will wonder whether a guarantee was traded for a test is
 * the file that appears to have traded it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupNameTest extends PantherTestCase
{
    use ReleasesTheBrowser;

    /** The hostname the web server binds — see {@see CollectionRowsTest::HOST}. */
    private const string HOST = 'xivi-e2e';

    /**
     * How long a wait is given before it is called a failure, and why it is not
     * Panther's thirty seconds.
     *
     * Both waits below are satisfied in well under a second when the page works:
     * a 400ms debounce and one round trip to a server in the same container. What
     * the number really sets is **the cost of a red run** — a broken controller
     * satisfies neither wait ever, so thirty seconds each is a minute of a
     * developer's time spent learning nothing that the first three seconds had not
     * already said. The same argument the browser grid's session timeout is tuned
     * on (`compose.override.yaml`), one layer up.
     *
     * Ten is roughly ten times the slowest honest case, which leaves room for a
     * cold container warming its cache on the first request of a run and none for
     * a wait that is quietly flaky.
     */
    private const int WAIT_SECONDS = 10;

    /**
     * A company name whose derived address only the server's rule produces.
     *
     * See the class docblock: under `de` the umlauts expand, and that expansion is
     * exactly what a script that had quietly grown its own copy of the rule would
     * get wrong.
     */
    private const string COMPANY = 'Müller Söhne AG';

    /**
     * And one whose address this installation keeps for itself.
     *
     * A reserved name rather than a taken one, so that the verdict comes from
     * {@see SelfServiceSlug} rather than from a tenant a previous test happened to
     * leave behind. The browser classes share a committed tenant (see
     * {@see CollectionRowsTest::provision()}) and depending on it here would make
     * this test's answer depend on which class ran first.
     */
    private const string RESERVED_COMPANY = 'Admin';

    private ?Client $browser = null;

    /**
     * Typing a company name fills in the address, and the address is the
     * server's.
     *
     * The wait is the assertion that the wiring works at all: the sentence being
     * waited for is `landing.name.free` with the derived name interpolated into
     * it, so it cannot appear unless the `input` action fired, the controller
     * connected, the request reached an `https`-bound route and the answer was
     * written into the target. Everything after the wait is about *what* was
     * written.
     */
    public function testTypingACompanyNameFillsInTheAddressTheServerWouldCreate(): void
    {
        $browser = $this->openTheLandingPage();

        $expected = self::service(SelfServiceSlug::class)->derive(self::COMPANY);

        // Asserted before typing, so that "the message changed" is a claim about
        // this test rather than about the page having been rendered with the
        // answer already in it.
        self::assertStringNotContainsString(
            $expected,
            (string) $browser->executeScript('return document.getElementById("address-help").textContent;'),
            'the page cannot already be showing an answer nobody has asked for',
        );

        $this->type('company', self::COMPANY);

        $browser->waitForElementToContain(
            '#address-help',
            self::service(TranslatorInterface::class)
                ->trans('landing.name.free', ['%slug%' => $expected], 'landing'),
            self::WAIT_SECONDS,
        );

        // `value` off the DOM property rather than the attribute: the attribute
        // still holds what the server rendered, which here is the empty string,
        // and asserting on it would pass whatever the script did.
        self::assertSame(
            $expected,
            $browser->executeScript('return document.getElementById("slug").value;'),
            'the box holds the name the server would create, transliterated by the server\'s rule',
        );

        self::assertSame(
            ['text-success' => true, 'text-danger' => false],
            $this->verdictClasses(),
            'a free name is drawn as free',
        );

        // A Stimulus wiring error is reported in the console and nowhere else — a
        // page whose controls are inert still renders perfectly (XIV-84).
        self::assertSame([], $this->severeConsoleEntries(), 'the browser console is clean');
    }

    /**
     * And a name this installation keeps for itself is said to be taken.
     *
     * The other half of `report()`, and the reason it is worth its own session:
     * the two class toggles are independent, so a controller that only ever set
     * the green one would pass the test above and mislead every visitor who typed
     * a name that has gone.
     */
    public function testANameThisInstallationKeepsIsReportedAsTaken(): void
    {
        $browser = $this->openTheLandingPage();

        $slug = self::service(SelfServiceSlug::class)->derive(self::RESERVED_COMPANY);

        self::assertTrue(
            self::service(SelfServiceSlug::class)->isReserved($slug),
            'this test is only meaningful while ' . $slug . ' is a name the deployment holds',
        );

        $this->type('company', self::RESERVED_COMPANY);

        $browser->waitForElementToContain(
            '#address-help',
            self::service(TranslatorInterface::class)->trans('landing.error.slug_taken', [], 'landing'),
            self::WAIT_SECONDS,
        );

        self::assertSame(
            ['text-success' => false, 'text-danger' => true],
            $this->verdictClasses(),
            'a name that has gone is drawn as gone',
        );

        self::assertSame([], $this->severeConsoleEntries(), 'the browser console is clean');
    }

    /**
     * Nothing in the application was relaxed to let the two tests above run.
     *
     * The browser reached this page over plain HTTP, which the routing table
     * forbids, and the honest question a reader will have is whether the
     * guarantee [XIV-65] fixed a live defect to establish was quietly given back
     * to make a test possible. It was not: what changed is a router script in
     * `tests/` that stands in for the TLS terminator production has, and a compose
     * alias that gives the container a second name. The routes are exactly what
     * they were.
     *
     * Asserted against the compiled router in *this* process — the same instrument
     * and the same claim as
     * {@see \App\Tests\Functional\ControlPlane\SignupPageTest::testEverySignupRouteInTheRouterCameFromTheLoader()},
     * restated here because this is the file where somebody will doubt it. It
     * opens no browser, so it costs a few milliseconds rather than a session.
     */
    public function testTheRouteTheBrowserReachedIsStillTlsOnlyAndHostBound(): void
    {
        $host = self::service(SignupHost::class)->normalisedHost();
        $routes = self::service(RouterInterface::class)->getRouteCollection();

        foreach (['signup_page', 'signup_page_name', 'signup_page_submit'] as $name) {
            $route = $routes->get($name);

            self::assertNotNull($route, $name . ' is not in the routing table');
            self::assertSame(['https'], $route->getSchemes(), $name . ' is no longer confined to TLS');
            self::assertSame($host, $route->getHost(), $name . ' is no longer bound to the signup host');
        }
    }

    // -- helpers ------------------------------------------------------------

    /**
     * A browser on the landing page, waited until the form is there.
     *
     * The client is built here rather than in `setUp()` so that the assertion
     * about the routing table above pays for no Selenium session — with
     * `PantherTestCase::SELENIUM` every call is a new session, since Panther only
     * reuses a client it started itself.
     */
    private function openTheLandingPage(): Client
    {
        $this->browser = self::createPantherClient(
            // The same hostname and grid as every other browser class, and it has
            // to be: the web server is started once for the whole suite by
            // Panther's `ServerExtension`, so whichever class runs first decides
            // what it binds. This class does not ask for anything different — it
            // asks for the *same socket* under the other name it answers to.
            ['hostname' => self::HOST, 'browser' => self::SELENIUM],
            [],
            ['host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://browser:4444'],
        );

        $this->browser->request('GET', $this->landingPageUrl());
        $this->browser->waitForVisibility('form[data-controller="signup-name"]', self::WAIT_SECONDS);

        return $this->browser;
    }

    /**
     * The landing page's address, assembled from what the suite is configured
     * with rather than written out.
     *
     * The hostname is the one the routing table is bound to, read from the same
     * service the loader reads; the port is whichever one Panther's web server
     * took, read off the base URI it built for the other classes. Hard-coding
     * either would be a third place they are written down, and the third place is
     * the one that goes stale.
     */
    private function landingPageUrl(): string
    {
        $port = parse_url((string) self::$baseUri, \PHP_URL_PORT);

        return sprintf(
            'http://%s:%d/',
            self::service(SignupHost::class)->normalisedHost(),
            \is_int($port) ? $port : 9080,
        );
    }

    /**
     * Type into one of the form's boxes, one key at a time.
     *
     * `sendKeys` rather than setting `value` from a script, and that is the whole
     * point of doing this in a browser: assigning to `value` fires no `input`
     * event, so a page whose `data-action` was misspelled would pass a test
     * written that way exactly as XIV-84's dashboard passed its own.
     */
    private function type(string $id, string $text): void
    {
        \assert($this->browser instanceof Client);

        $this->browser->getWebDriver()->findElement(WebDriverBy::id($id))->sendKeys($text);
    }

    /**
     * Which of the two verdict classes the message is wearing.
     *
     * Both, always, rather than asserting the one that should be present: the
     * script toggles them independently and a bug that adds the right one without
     * removing the wrong one paints a taken name green and red at once.
     *
     * @return array{text-success: bool, text-danger: bool}
     */
    private function verdictClasses(): array
    {
        \assert($this->browser instanceof Client);

        $classes = (string) $this->browser->executeScript(
            'return document.getElementById("address-help").className;',
        );

        $worn = preg_split('/\s+/', trim($classes)) ?: [];

        return [
            'text-success' => \in_array('text-success', $worn, true),
            'text-danger' => \in_array('text-danger', $worn, true),
        ];
    }

    /**
     * Everything the browser thought was serious.
     *
     * A Stimulus controller that fails to connect — an unknown target, an action
     * descriptor it cannot parse — throws, and the throw is reported here and
     * nowhere else. The page still renders, which is precisely why this is asked
     * for rather than inferred.
     *
     * @return list<array<string, mixed>>
     */
    private function severeConsoleEntries(): array
    {
        \assert($this->browser instanceof Client);

        return array_values(array_filter(
            $this->browser->getWebDriver()->manage()->getLog('browser'),
            static fn (array $entry): bool => ($entry['level'] ?? '') === 'SEVERE',
        ));
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

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
use App\Tests\Support\SharesATenant;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Order\OrderModule;

/**
 * The numbering preview, in a browser (XIV-27, XIV-31).
 *
 * **One test, and it is the whole feature.** XIV-27's argument for building a
 * live control rather than a text box is that `ORD-{year}-{number:4}` is a small
 * language whose failure modes are quiet, and that watching the number appear is
 * what turns it into something somebody can learn. Every other test of this
 * feature calls the component directly: they prove that the *server* renders the
 * right preview for a given pattern, and are blind to whether a keystroke ever
 * reaches it. If the `data-model` attribute were misspelled, the page would show
 * a preview frozen at whatever was stored, silently, and a full green suite
 * would say nothing at all — which is exactly the failure CollectionRowsTest was
 * written after.
 *
 * The rule from that file holds here: an end-to-end layer is where flakiness
 * lives, flaky tests get skipped, and a skipped safety net is worse than none.
 * There is no sleep below; the one wait is an explicit condition.
 *
 * It shares the end-to-end tenant with the other browser tests because it has
 * to — there is exactly one hostname both the browser's container and the
 * application's can resolve — and it deliberately **saves nothing**: it types,
 * reads the preview and leaves, so the shared tenant's order module is still
 * numbered the way the other classes found it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NumberingPreviewTest extends PantherTestCase
{
    use SharesATenant;

    private const string HOST = 'xivi-e2e';
    private const string SLUG = 'e2e';
    private const string EMAIL = 'e2e@example.test';
    private const string PASSWORD = 'e2e-password';

    /** Typed into the pattern, and the number it has to produce. */
    private const string TYPED = 'RG-{number:6}';
    private const string PRODUCES = 'RG-000001';

    private static bool $ready = false;

    private Client $browser;

    protected function setUp(): void
    {
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
     * Typing a pattern changes the number under it, and the counter it names.
     *
     * Two assertions on one render, because they are two halves of one claim:
     * dropping `{year}` changes both what the number looks like and *which
     * counter it comes out of*, and the second is the one nobody would guess.
     * The page has to say it before anything is saved, which is what makes this
     * a live control rather than a validation message.
     */
    public function testTypingAPatternChangesTheNumberUnderIt(): void
    {
        $this->openTheNumberingPage();

        self::assertStringContainsString(
            'ORD-' . date('Y') . '-0001',
            (string) $this->browser->executeScript(
                'return document.querySelector("[data-numbering-preview]").textContent;',
            ),
            'it opens on the pattern in use',
        );

        // With a real `input` event, because that is the path a keystroke takes.
        // Setting the value alone changes the DOM and tells the component
        // nothing — which is precisely the class of bug this file exists for.
        $this->browser->executeScript(sprintf(
            'const el = document.getElementById("numbering-pattern");'
            . ' el.value = %s;'
            . ' el.dispatchEvent(new Event("input", {bubbles: true}));',
            json_encode(self::TYPED, \JSON_THROW_ON_ERROR),
        ));

        // The re-render is a request, so this is a wait about the network rather
        // than about a paint.
        $this->browser->waitForElementToContain('[data-numbering-preview]', self::PRODUCES);

        self::assertStringContainsString(
            'never starts again',
            (string) $this->browser->executeScript('return document.querySelector("main").textContent;'),
            'and the page has moved to naming the counter that does not reset',
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The numbering page, reached the way somebody reaches it.
     *
     * Through the link on the field editor rather than by a URL this test knows,
     * so the link being drawn on the right field is under test too — it is the
     * only way into this feature.
     */
    private function openTheNumberingPage(): void
    {
        $this->browser->request('GET', '/m/order/fields');
        // `main table`, not `form`: the first form on every signed-in page is the
        // sign-out form inside the top bar's menu, which is invisible until
        // somebody opens the menu and is meant to be.
        $this->browser->waitForVisibility('main table');

        $link = (string) $this->browser->executeScript(
            'return document.querySelector(\'main a[href$="/numbering"]\').getAttribute("href");',
        );

        $this->browser->request('GET', $link);
        $this->browser->waitForVisibility('#numbering-pattern');
    }

    /**
     * The end-to-end tenant, which this class needs nothing added to.
     *
     * Written defensively because the browser classes share the tenant and any
     * of them may run first: the modules install idempotently and the user is
     * created only if nobody has yet. Nothing here may be rolled back — the
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

                foreach ([ContactModule::KEY, OrderModule::KEY] as $key) {
                    $installer->install($registry->get($key));
                }
            });

            if (self::service(TenantSwitcher::class)->runFor(
                $tenant,
                fn (): bool => self::service(UserRepository::class)->findOneByEmail(self::EMAIL) === null,
            )) {
                self::service(UserCreator::class)->create($tenant, self::EMAIL, 'E2E', self::PASSWORD, ['ROLE_ADMIN']);
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

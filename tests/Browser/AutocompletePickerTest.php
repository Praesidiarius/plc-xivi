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
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Order\OrderModule;

/**
 * Typing into a picker and picking what comes back (XIV-36).
 *
 * **Nothing on the server can see this happen**, which is the whole reason the
 * browser layer exists (XIV-31). Every other test of this ticket asserts that
 * the right attributes are on the right `<select>` and that the endpoint answers
 * correctly — and all of them would still pass against a page where the widget
 * never started, the fetch went nowhere, or clicking a result set no value.
 * Those are three separate failures with no server-side symptom at all.
 *
 * **Two tests, and no more.** The rule from CollectionRowsTest applies here for
 * the same reason: an end-to-end layer is where flakiness lives, flaky tests get
 * skipped, and a skipped safety net is worse than none because everybody
 * believes it is there. Every wait below is an explicit condition; there is no
 * sleep.
 *
 * It shares the end-to-end tenant with CollectionRowsTest, because it has to:
 * this application resolves the customer from the Host header and there is
 * exactly one hostname that both the browser's container and the application's
 * own can resolve — see compose.override.yaml, where `hostname` and the network
 * alias are both `xivi-e2e` for reasons that are not interchangeable.
 * `SharesATenant` provisions a slug once per process, so whichever of the two
 * classes runs first pays for it and the other finds it standing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AutocompletePickerTest extends PantherTestCase
{
    use SharesATenant;

    private const string HOST = 'xivi-e2e';
    private const string SLUG = 'e2e';
    private const string EMAIL = 'e2e@example.test';
    private const string PASSWORD = 'e2e-password';

    /** The order's link to a contact, which this file makes into a search box. */
    private const string PICKER = 'module_record_fields_contact';

    /** What is typed, and the company it should find. */
    private const string COMPANY = 'Zeppelin Werke AG';
    private const string TYPED = 'Zepp';

    private static bool $ready = false;

    /** The id the picked option must put into the select, learned while provisioning. */
    private static int $company = 0;

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
     * The widget starts, and starts on the right control.
     *
     * The assertion the second test depends on: Tom Select replaces the select
     * with its own markup and records itself on the element, so if the
     * controller never connected — a bad importmap, an asset that was never
     * installed, a CSP — this is where it says so rather than the next test
     * timing out on a dropdown that was never going to appear.
     */
    public function testThePickerBecomesASearchBoxInTheBrowser(): void
    {
        $this->openTheOrderForm();

        self::assertTrue(
            $this->browser->executeScript(sprintf(
                'return document.getElementById(%s).tomselect !== undefined;',
                json_encode(self::PICKER, \JSON_THROW_ON_ERROR),
            )),
            'the reference picker is a search box, not a dropdown',
        );
    }

    /**
     * Typing narrows it, and picking a result fills the field in.
     *
     * The end of the whole chain in one assertion: the keystroke reaches the
     * widget, the widget calls the endpoint, the endpoint answers with a record
     * this reader may see, and clicking it puts that record's *id* into the
     * `<select>` the form will submit. Everything between is somebody else's
     * test; that it joins up is only visible here.
     *
     * The company typed for exists in no other test's data and is deliberately
     * spelled so that four characters cannot match anything the demo modules
     * ship.
     */
    public function testTypingFindsARecordAndPickingItFillsTheField(): void
    {
        $this->openTheOrderForm();

        // Through the control's own input, with a real `input` event, because
        // that is the path a keystroke takes. Setting the value alone changes
        // the DOM and tells the widget nothing.
        $this->browser->executeScript(sprintf(
            'const ts = document.getElementById(%s).tomselect;'
            .' ts.focus();'
            .' ts.control_input.value = %s;'
            .' ts.control_input.dispatchEvent(new Event("input", {bubbles: true}));',
            json_encode(self::PICKER, \JSON_THROW_ON_ERROR),
            json_encode(self::TYPED, \JSON_THROW_ON_ERROR),
        ));

        // The result comes back from the endpoint over the network, so this is
        // the one wait in the file that is about a request rather than a render.
        $this->browser->waitFor('.ts-dropdown [data-selectable]');

        self::assertStringContainsString(
            self::COMPANY,
            (string) $this->browser->executeScript(
                'return document.querySelector(".ts-dropdown").textContent;',
            ),
            'the endpoint answered with the record that was typed for',
        );

        $this->browser->executeScript('document.querySelector(".ts-dropdown [data-selectable]").click();');

        $this->waitForValue(self::PICKER, (string) self::$company);
    }

    // -- helpers ------------------------------------------------------------

    private function openTheOrderForm(): void
    {
        $this->browser->request('GET', '/m/order/new');
        // `main form`, not `form`: the first form on every signed-in page is the
        // sign-out form inside the top bar's menu, which is invisible until
        // somebody opens it and is meant to be.
        $this->browser->waitForVisibility('main form');
        $this->browser->waitFor('#' . self::PICKER);
    }

    /** Wait until the underlying select holds a value. */
    private function waitForValue(string $id, string $expected): void
    {
        $script = sprintf(
            'const el = document.getElementById(%s); return el ? el.value : null;',
            json_encode($id, \JSON_THROW_ON_ERROR),
        );

        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $held = (string) $this->browser->executeScript($script);

            if ($held === $expected) {
                self::assertSame($expected, $held, 'the picked record is what the form will submit');

                return;
            }

            usleep(250_000);
        }

        self::fail(sprintf(
            'the picker never took the record: "%s" holds "%s" rather than "%s"',
            $id,
            (string) $this->browser->executeScript($script),
            $expected,
        ));
    }

    /**
     * The end-to-end tenant, plus what this file needs on top of it.
     *
     * **Nothing here may be rolled back**, the same as CollectionRowsTest and
     * for the same reason: the browser is another process making real requests
     * and cannot see this test's transaction.
     *
     * Everything is written defensively, because this class and CollectionRowsTest
     * share the tenant and either may run first — the modules install
     * idempotently, and the user is created only if nobody has yet.
     *
     * **The picker is told `always`** rather than being given twenty-one
     * companies to trip `auto`. Both would work; this one states what the test
     * is about instead of leaving it to a threshold, and it leaves the shared
     * tenant with three contacts rather than a couple of dozen belonging to
     * nothing.
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

                $orders = self::service(MetadataRepository::class)->get(OrderModule::KEY);
                $field = $orders->getField('contact');
                self::assertNotNull($field);

                self::service(MetadataEditor::class)->updateField(
                    field: $field,
                    label: $field->getLabel(),
                    required: $field->isRequired(),
                    unique: $field->isUnique(),
                    filterable: $field->isFilterable(),
                    listed: $field->isListed(),
                    title: $field->isTitle(),
                    position: $field->getPosition(),
                    options: [Autocomplete::OPTION => Autocomplete::Always->value],
                    width: $field->getWidth(),
                );

                $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
                $writer = self::service(RecordWriter::class);

                foreach ([self::COMPANY, 'Aalen Handels GmbH', 'Basel Logistik AG'] as $name) {
                    $saved = $writer->save($contacts, new Record(data: [
                        'kind' => ContactModule::COMPANY,
                        'company_name' => $name,
                    ]));

                    if ($name === self::COMPANY) {
                        self::$company = (int) $saved->id;
                    }
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

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
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpManager;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * The two things about the follow-up panel only a browser can see (XIV-82).
 *
 * Everything else about this feature is asserted server-side, where it belongs:
 * {@see \App\Tests\Functional\Tenant\FollowUpPanelTest} drives the routes with a
 * real client and the component through the library's own harness, which is
 * honest about what the server does and completely blind to whether pressing
 * anything on the page reaches it. The archive counter and the add button are
 * `data-action="live#action"` and nothing but — get that attribute wrong, as
 * XIV-31 records happening during the Live Components spikes, and a full green
 * suite says nothing at all.
 *
 * **Two tests, for the reason {@see CollectionRowsTest} sets out at length.** An
 * end-to-end layer is where flakiness lives, flaky tests get skipped, and a
 * skipped safety net is worse than none. Every wait below is an explicit
 * `waitFor*`; there is no sleep anywhere.
 *
 * The claims are chosen to be the two the design rests on. The archive has to
 * reveal content **that was not in the document**, because that is the whole
 * argument for a live action rather than the `<details>` the linked-record cards
 * use — a `<details>` would still have sent forty settled follow-ups to a page
 * about a record. And the create form has to open *and then post to a route*,
 * because this component deliberately does not own its own writes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpsTest extends PantherTestCase
{
    use SharesATenant;

    /** The hostname the browser asks for — see {@see CollectionRowsTest::HOST}. */
    private const string HOST = 'xivi-e2e';

    /**
     * The same tenant the other browser tests use, and for the same reason: one
     * hostname routes to one tenant, and this is the only name the browser
     * container can resolve back to the application.
     */
    private const string SLUG = 'e2e';

    private const string EMAIL = 'follow-ups@example.test';
    private const string PASSWORD = 'follow-ups-password';

    /** What the settled follow-up says, and must not say until it is asked for. */
    private const string ARCHIVED = 'Settled last week and filed away';

    private static bool $ready = false;

    /** The contact everything here hangs on, made once for the class. */
    private static int $recordId = 0;

    private Client $browser;

    protected function setUp(): void
    {
        // Before the browser, for the reason CollectionRowsTest sets out:
        // provisioning takes long enough that a session opened first would sit
        // idle through it and be reaped by the grid.
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
     * The archive brings back markup the page never had.
     *
     * Asserted in that order — absent, then pressed, then present — because
     * "present after pressing" alone would pass just as well for a `<details>`
     * that had been holding the content all along, which is the design this one
     * deliberately is not.
     */
    public function testTheArchiveRevealsWhatWasNeverSentWithThePage(): void
    {
        $this->browser->request('GET', $this->recordPath());
        $this->browser->waitForVisibility('#follow-ups');

        self::assertStringNotContainsString(
            self::ARCHIVED,
            (string) $this->browser->executeScript('return document.body.innerHTML;'),
            'a settled follow-up costs the page nothing until somebody asks for it',
        );

        // A mark on the page, so that "it appeared" and "it appeared without a
        // page load" are two different assertions rather than one hopeful one.
        $this->browser->executeScript('window.__stillHere = true;');

        $this->press('revealArchive');
        $this->browser->waitForElementToContain('#follow-ups', self::ARCHIVED);

        self::assertTrue(
            $this->browser->executeScript('return window.__stillHere === true;'),
            'the panel was swapped into, not navigated away from',
        );
    }

    /**
     * Opening one: a live action reveals the form, and the form posts to a route.
     *
     * The half that matters here is the join between the two. The component owns
     * the disclosure and owns none of the writing, so a page where the button
     * works and the form posts nowhere — or posts to the live endpoint, which is
     * where a relative action would land — would be a feature that looks
     * complete and stores nothing.
     */
    public function testTheAddFormOpensAndWhatItPostsLandsOnTheRecord(): void
    {
        $this->browser->request('GET', $this->recordPath());
        $this->browser->waitForVisibility('#follow-ups');

        $this->press('startAdding');
        $this->browser->waitForVisibility('#follow-up-note');

        $this->browser->executeScript(
            'document.getElementById("follow-up-priority").value = "important";'
            . ' document.getElementById("follow-up-note").value = "Ring the workshop about the spare part";',
        );

        // Pressed rather than submitted through the crawler, which refuses to
        // submit anything it was handed that is not the button — and pressing is
        // the more honest half anyway: a `<button type="submit">` inside a live
        // component is exactly the thing that could have been swallowed.
        $this->browser->executeScript(
            'document.getElementById("follow-up-note").closest("form")'
            . '.querySelector("button[type=submit]").click();',
        );

        // Back on the record page, with the new one at the top of it — and the
        // priority it was given drawn as Bootstrap's `danger` rather than as a
        // context Bootstrap has never had.
        $this->browser->waitForElementToContain('#follow-ups', 'Ring the workshop about the spare part');

        self::assertNotNull(
            $this->browser->executeScript(
                'return document.querySelector("#follow-ups .border-danger");',
            ),
            'important is drawn as danger',
        );
    }

    // -- helpers ------------------------------------------------------------

    /** Press one of the panel's live buttons by the action it names. */
    private function press(string $action): void
    {
        $this->browser->executeScript(sprintf(
            'document.querySelector(%s).click();',
            json_encode(
                sprintf('#follow-ups [data-live-action-param="%s"]', $action),
                \JSON_THROW_ON_ERROR,
            ),
        ));
    }

    private function recordPath(): string
    {
        return sprintf('/m/%s/%d', ContactModule::KEY, self::$recordId);
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
     * A user, a contact and one settled follow-up, committed rather than rolled
     * back.
     *
     * The browser is another process making real requests and cannot see this
     * test's transaction — the reasoning {@see CollectionRowsTest::provision()}
     * gives at length, and the reason every write here is inside
     * `withoutRollback()`.
     *
     * The module is installed only if this tenant has not got it: the browser
     * classes share one hostname and therefore one tenant, so whichever of them
     * runs first has already done it and installing twice is not a no-op.
     */
    private function provision(): void
    {
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::withoutRollback(function () use ($tenant): void {
            self::service(UserCreator::class)->create(
                $tenant,
                self::EMAIL,
                'Fran Follows-Up',
                self::PASSWORD,
                // An administrator, so that what is on the screen is decided by
                // this ticket rather than by a grant matrix this test would then
                // also be about. The grants themselves are covered server-side.
                ['ROLE_ADMIN'],
            );

            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                $metadata = self::service(MetadataRepository::class);

                if ($metadata->find(ContactModule::KEY) === null) {
                    self::service(ModuleInstaller::class)->install(
                        self::service(ModuleRegistry::class)->get(ContactModule::KEY),
                    );
                }

                $record = new Record();
                $record->set('kind', 'person');
                $record->set('first_name', 'Ada');
                $record->set('last_name', 'Lovelace');

                self::$recordId = (int) self::service(RecordWriter::class)
                    ->save($metadata->get(ContactModule::KEY), $record)->id;

                $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
                \assert($user instanceof User);

                $manager = self::service(FollowUpManager::class);

                // One open, so the panel has something to draw without being
                // asked, and one settled, which is what the archive counts.
                $manager->create(
                    actor: $user,
                    moduleKey: ContactModule::KEY,
                    recordId: self::$recordId,
                    priority: FollowUpPriority::Warning,
                    dueAt: new \DateTimeImmutable('+2 days'),
                    note: 'Still to do: confirm the delivery window',
                );

                $manager->markDone($user, $manager->create(
                    actor: $user,
                    moduleKey: ContactModule::KEY,
                    recordId: self::$recordId,
                    priority: FollowUpPriority::Info,
                    dueAt: new \DateTimeImmutable('-7 days'),
                    note: self::ARCHIVED,
                ));
            });
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

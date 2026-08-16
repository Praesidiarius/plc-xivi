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

namespace App\Tests\Functional\Tenant;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\FollowUpNote;
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpManager;
use App\Tenant\FollowUp\ModuleFollowUps;
use App\Tenant\Repository\FollowUpRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * The follow-up panel on a record's page (XIV-82).
 *
 * XIV-80 tested the rules at the write path, on purpose and without a screen
 * anywhere near them. This file is the other half and is deliberately *not* a
 * second copy of those assertions: nothing here re-checks that the manager
 * refuses a note that is not yours, because it does and one test of that is
 * enough. What is tested here is everything that only exists because there is a
 * page — which control is drawn, which is not, what is in the HTML and what has
 * deliberately been kept out of it.
 *
 * **Three layers meet on this page and each is exercised where it lives.** The
 * routes are driven with a real client, because a `#[IsGranted]` that is never
 * requested is not enforcement. The component's disclosure is driven through the
 * library's own harness, because a form that only exists after a live action
 * cannot be crawled off a first render. And the archive's *reveal* — the
 * Stimulus attribute that turns the counter into a request — is the browser
 * suite's, for the reason XIV-31 gives: a full green suite once said nothing at
 * all about a button that could never have fired.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpPanelTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use SharesATenant;

    private const string SLUG = 'test_follow_up_panel';
    private const string HOST = 'follow-up-panel.localhost';
    private const string MODULE = ContactModule::KEY;
    private const string PASSWORD = 'a-long-enough-password';

    /** Holds View and both follow-up verbs: everything the panel can offer. */
    private const string KEEPER = 'keeper@panel.test';

    /** Holds View and `follow_up_create`, so their notes are somebody else's to look at. */
    private const string COLLEAGUE = 'colleague@panel.test';

    /** Holds View and nothing else — reads the panel, is offered no control at all. */
    private const string READER = 'reader@panel.test';

    /** Holds nothing, so the picker must not offer them. */
    private const string OUTSIDER = 'outsider@panel.test';

    private KernelBrowser $client;
    private Tenant $tenant;
    private int $recordId;

    /** Who the browser is currently signed in as, so it is not done twice. */
    private ?string $signedIn = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The tenant is resolved from the host, and the component harness posts
        // to one global route, so both have to be told which customer this is.
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            // Two modules, because one of the claims is about a module that does
            // *not* take follow-ups and the honest way to test that is a second
            // module with the switch off rather than the same one switched back
            // and forth.
            foreach ([self::MODULE, ArticleModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::KEEPER, 'Kim Keeper', self::PASSWORD, []);
        $users->create($this->tenant, self::COLLEAGUE, 'Chris Colleague', self::PASSWORD, []);
        $users->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, []);
        $users->create($this->tenant, self::OUTSIDER, 'Ollie Outsider', self::PASSWORD, []);

        $this->inTenant(function (): void {
            $this->grant(self::KEEPER, [
                // List as well as View, because one of the claims below is about
                // what the list does *not* show, and a 403 would prove it by
                // accident.
                ModuleAction::List,
                ModuleAction::View,
                ModuleAction::FollowUpCreate,
                ModuleAction::FollowUpComplete,
            ]);
            $this->grant(self::COLLEAGUE, [ModuleAction::View, ModuleAction::FollowUpCreate]);
            $this->grant(self::READER, [ModuleAction::View]);

            $this->recordId = $this->contact();
        });
    }

    /**
     * The panel is above the record's own fields, which is the whole of "cannot
     * be missed".
     *
     * Asserted as a position in the document rather than as "the markup exists
     * somewhere", because a follow-up panel under a contact's address is one
     * nobody scrolls to — and the two orderings produce exactly the same set of
     * elements, so nothing else would tell them apart.
     */
    public function testTheOpenFollowUpsSitAboveTheRecordsOwnFields(): void
    {
        $this->open(FollowUpPriority::Important, 'Chase the second invoice', self::READER);

        $html = $this->showAs(self::KEEPER);

        self::assertStringContainsString('id="follow-ups"', $html);
        self::assertStringContainsString('Chase the second invoice', $html);
        // The assignee's name, captured on the row at write time (§5.18).
        self::assertStringContainsString('Robin Reader', $html);

        // Against the record's own field list rather than against its name: the
        // name is also in the <title>, which comes before everything and would
        // have made this assertion pass whatever the body did.
        self::assertLessThan(
            (int) strpos($html, '<dl class="row'),
            (int) strpos($html, 'id="follow-ups"'),
            'the panel comes before the record it is about',
        );
    }

    /**
     * Priority is a coloured left border, and `important` is `danger`.
     *
     * The one mapping in this feature that is not an identity, which is why it
     * has a test of its own: `info` and `warning` would come out right from a
     * template that simply printed the stored word, and `important` would come
     * out as no colour at all — silently, on the priority that must not go quiet.
     */
    public function testPriorityIsALeftBorderAndImportantIsDrawnAsDanger(): void
    {
        $this->open(FollowUpPriority::Important, 'The loud one');
        $this->open(FollowUpPriority::Info, 'The quiet one');
        $this->open(FollowUpPriority::Warning, 'The middle one');

        $html = $this->showAs(self::KEEPER);

        self::assertStringContainsString('border-start border-4 border-danger', $html);
        self::assertStringContainsString('border-start border-4 border-info', $html);
        self::assertStringContainsString('border-start border-4 border-warning', $html);
        self::assertStringNotContainsString('border-important', $html, 'Bootstrap has no such context');
    }

    /**
     * A follow-up says when it wants doing, and on the reader's clock.
     *
     * The zone half matters more than it looks: `due_at` is `timestamptz` and a
     * deadline read an hour out is still a plausible-looking deadline (XIV-83).
     * The assertion is against the stored instant converted to the zone the page
     * was rendered for, never against a string typed twice.
     */
    public function testTheDueMomentIsShownOnTheReadersClock(): void
    {
        $due = new \DateTimeImmutable('2026-09-04 15:30:00', new \DateTimeZone('UTC'));
        $this->open(FollowUpPriority::Warning, 'Ring them back', dueAt: $due);

        // Set and flushed in one block: two would load the user through two
        // managers and flush a copy the second one has never seen.
        $this->inTenant(function (): void {
            $this->userNow(self::KEEPER)->setTimezone('Asia/Tokyo');
            $this->entityManager()->flush();
        });

        $html = $this->showAs(self::KEEPER);

        self::assertStringContainsString(
            $due->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'),
            $html,
        );
        self::assertStringNotContainsString($due->format('Y-m-d H:i'), $html, 'and not on UTC');
    }

    /**
     * The archive is a counter, and what it counts is not in the page until
     * somebody asks.
     *
     * This is the claim the whole disclosure exists for. A `<details>` would
     * have hidden the done follow-ups just as well and would still have sent
     * forty cards and forty note threads to a browser that is showing a record —
     * so the assertion is about the bytes rather than about what is visible.
     */
    public function testTheArchiveIsACounterAndItsContentsAreNotSentUntilAskedFor(): void
    {
        $this->markDone($this->open(FollowUpPriority::Info, 'Sent them the catalogue'));

        $html = $this->showAs(self::KEEPER);

        self::assertStringNotContainsString('Sent them the catalogue', $html);
        self::assertStringContainsString('1 done', $html);

        // And it is there the moment it is asked for.
        self::assertStringContainsString(
            'Sent them the catalogue',
            $this->panelAs(self::KEEPER)->call('revealArchive')->render()->toString(),
        );
    }

    /**
     * Opening one from the record page, through the form the page actually draws.
     *
     * The create form only exists after a live action — its assignee picker
     * costs a permission resolution per person, and building it on every record
     * page to fill a form nobody opened is what the disclosure avoids — so the
     * component is asked to open it, and what comes back is posted to the route
     * exactly as a browser would post it, token and all.
     */
    public function testAFollowUpCanBeOpenedFromTheRecordPage(): void
    {
        // On the Tokyo clock, so that the moment stored proves the conversion
        // rather than agreeing with the server by coincidence.
        $this->inTenant(function (): void {
            $this->userNow(self::KEEPER)->setTimezone('Asia/Tokyo');
            $this->entityManager()->flush();
        });

        $form = $this->addForm();

        $this->submit($form, [
            'due_at' => '2026-10-01T09:00',
            'priority' => FollowUpPriority::Important->value,
            'assignee' => (string) $this->inTenant(fn (): ?int => $this->userNow(self::COLLEAGUE)->getId()),
            'note' => 'They asked to be called after the audit',
        ], as: self::KEEPER);

        $opened = $this->followUps();

        self::assertCount(1, $opened);
        self::assertSame(FollowUpPriority::Important, $opened[0]->getPriority());
        self::assertSame('Chris Colleague', $opened[0]->getAssigneeLabel());
        self::assertSame(['They asked to be called after the audit'], $this->noteBodies($opened[0]));

        // `datetime-local` sends a wall clock with no zone on it, so nine in the
        // morning in Tokyo is midnight in UTC — and the seconds are zero rather
        // than whatever the server's clock happened to read while parsing.
        self::assertSame(
            '2026-10-01 00:00:00',
            $opened[0]->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    /**
     * The picker offers the people who could open this record, and nobody else.
     *
     * The rule is XIV-80's and is enforced on the write path; what is tested
     * here is that the screen never gets that far. Outsider holds no grant at
     * all, so a picker containing their name would be a control whose only
     * possible outcome is a refusal.
     */
    public function testTheAssigneePickerOffersOnlyPeopleWhoMayViewTheRecord(): void
    {
        $options = $this->addForm()->filter('select[name="assignee"] option')->each(
            static fn (Crawler $option): string => $option->text(),
        );

        self::assertContains('Chris Colleague', $options);
        self::assertContains('Robin Reader', $options, 'view alone is enough to be given one');
        self::assertNotContains('Ollie Outsider', $options);
    }

    /**
     * Notes read forwards, with who said it and when.
     *
     * Oldest first is the one place in this application where newest-first would
     * be wrong: a follow-up is the question and its notes are what happened
     * about it, so the thread is read from the beginning (§5.18).
     */
    public function testNotesReadAsAThreadWithTheirAuthorAndTime(): void
    {
        $followUp = $this->open(FollowUpPriority::Warning, 'First: they rang about the delivery');
        $this->addNoteAs(self::COLLEAGUE, $followUp, 'Second: promised a date by Friday');

        $html = $this->showAs(self::KEEPER);

        self::assertStringContainsString('Kim Keeper', $html);
        self::assertStringContainsString('Chris Colleague', $html);
        self::assertLessThan(
            (int) strpos($html, 'Second: promised a date by Friday'),
            (int) strpos($html, 'First: they rang about the delivery'),
            'a thread reads forwards',
        );
    }

    /**
     * Edit and delete are offered on your own notes and on nobody else's.
     *
     * Asserted as the presence of the *form*, not of a word: this is the one
     * rule in the application with no administrator override, and the panel's
     * job is to decline to draw a control that the manager would refuse.
     */
    public function testOnlyTheAuthorIsOfferedEditAndDelete(): void
    {
        $followUp = $this->open(FollowUpPriority::Info, 'Mine');
        $mine = $this->noteIds($followUp)[0];
        $theirs = $this->addNoteAs(self::COLLEAGUE, $followUp, 'Theirs');

        $page = $this->crawlShowAs(self::KEEPER);

        self::assertCount(
            1,
            $page->filter(sprintf('form[action$="/notes/%d/delete"]', $mine)),
            'the author is offered a delete',
        );

        self::assertCount(
            0,
            $page->filter(sprintf('form[action$="/notes/%d/delete"]', $theirs)),
            'and nobody else is, administrators included',
        );
    }

    /**
     * A note can be written from the page, through the box the page draws.
     *
     * Unlike the create form this one is on the first render — a thread with no
     * way to add to it is a thread nobody would use — so the token comes off the
     * page the way every other form test here reads one.
     */
    public function testANoteCanBeAddedFromThePage(): void
    {
        $followUp = $this->open(FollowUpPriority::Info, 'Opened');
        $form = $this->crawlShowAs(self::KEEPER)
            ->filter(sprintf('form[action$="/follow-ups/%d/notes"]', $followUp));

        $this->submit($form, ['note' => 'Left a message'], as: self::KEEPER);

        self::assertSame(
            ['Opened', 'Left a message'],
            $this->noteBodies($this->followUps()[0]),
        );
    }

    /**
     * Done from the page, and back out of the archive.
     *
     * One test rather than two because they are one edit pointing two ways
     * (§5.18) and the interesting part is the round trip: a follow-up that goes
     * into the archive and cannot come out again is work quietly lost.
     */
    public function testAFollowUpIsMarkedDoneFromThePageAndReopenedFromTheArchive(): void
    {
        $id = $this->open(FollowUpPriority::Warning, 'Round trip');

        $this->submit(
            $this->crawlShowAs(self::KEEPER)->filter(sprintf('form[action$="/follow-ups/%d/done"]', $id)),
            [],
            as: self::KEEPER,
        );

        self::assertTrue($this->followUps()[0]->isDone());

        // The reopen button lives in the archive, which is markup that has to be
        // asked for — so the form is read out of the revealed panel rather than
        // out of the page.
        $revealed = new Crawler(
            $this->panelAs(self::KEEPER)->call('revealArchive')->render()->toString(),
        );

        $this->submit(
            $revealed->filter(sprintf('form[action$="/follow-ups/%d/reopen"]', $id)),
            [],
            as: self::KEEPER,
        );

        self::assertFalse($this->followUps()[0]->isDone());
    }

    /**
     * Somebody with only `view` reads everything and is offered nothing.
     *
     * The panel is not a permission — reading a follow-up says nothing the record
     * does not already say to whoever may open it (§5.18) — so the text is there
     * and every control is gone.
     */
    public function testEveryActionIsHiddenWhenTheGrantIsMissing(): void
    {
        $this->open(FollowUpPriority::Info, 'Visible to everybody who may read this record');

        $page = $this->crawlShowAs(self::READER);
        $html = $page->html();

        self::assertStringContainsString('Visible to everybody who may read this record', $html);
        self::assertCount(0, $page->filter('form[action*="/follow-ups/"]'), 'no done, no reopen, no note box');
        self::assertStringNotContainsString('data-live-action-param="startAdding"', $html);
    }

    /**
     * A module with follow-ups switched off renders nothing at all.
     *
     * Not an empty state and not a counter reading zero. The switch is reversible
     * by design (§5.18), and a customer who turned the feature off is entitled to
     * a page with no trace of it — a box saying "no follow-ups" is the feature
     * refusing to leave.
     */
    public function testNothingRendersForAModuleWithFollowUpsSwitchedOff(): void
    {
        $this->open(FollowUpPriority::Important, 'Written while the feature was on');

        $this->inTenant(function (): void {
            $module = self::service(MetadataRepository::class)->get(self::MODULE);
            self::service(ModuleFollowUps::class)->set($module, false);
        });

        $html = $this->showAs(self::KEEPER);

        self::assertStringNotContainsString('id="follow-ups"', $html);
        self::assertStringNotContainsString('Written while the feature was on', $html);
        self::assertStringContainsString('Lovelace', $html, 'the record itself is untouched');
    }

    /**
     * And they are not on the list.
     *
     * A page of twenty-five records asking each one what is outstanding on it is
     * the N+1 §5.16 warned about, and a list is for scanning records rather than
     * for reading the work outstanding on them.
     */
    public function testFollowUpsAreNotOnTheRecordList(): void
    {
        $this->open(FollowUpPriority::Important, 'Only ever on the record page');

        $this->signIn(self::KEEPER);
        $html = (string) $this->client->request('GET', $this->url('/m/' . self::MODULE))->html();

        self::assertStringNotContainsString('Only ever on the record page', $html);
        self::assertStringNotContainsString('id="follow-ups"', $html);
    }

    /**
     * A follow-up belonging to another record cannot be reached through this one.
     *
     * The one rule the controller owns rather than the manager: `#[IsGranted]`
     * votes on the module in the path and the manager resolves the module off
     * the follow-up row, so without this check the two would be talking about
     * different records and both be satisfied. A 404 rather than a 403, so that
     * a wrong id and somebody else's id are indistinguishable (§8.4).
     */
    public function testAFollowUpOnAnotherRecordIsNotReachableThroughThisOne(): void
    {
        $followUp = $this->open(FollowUpPriority::Info, 'On the first contact');
        $other = $this->inTenant(fn (): int => $this->contact());

        $form = $this->crawlShowAs(self::KEEPER)
            ->filter(sprintf('form[action$="/follow-ups/%d/done"]', $followUp));

        // The real token, so what is being tested is the path and not the check
        // in front of it.
        $this->client->request('POST', $this->url(sprintf(
            '/m/%s/%d/follow-ups/%d/done',
            self::MODULE,
            $other,
            $followUp,
        )), ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->followUps()[0]->isDone());
    }

    // -- helpers ------------------------------------------------------------

    /** The panel's create form, opened the way the button opens it. */
    private function addForm(): Crawler
    {
        $panel = new Crawler(
            $this->panelAs(self::KEEPER)->call('startAdding')->render()->toString(),
            $this->url('/'),
        );

        // The one form whose action is the panel's own path with nothing after
        // it — the note boxes and the done buttons all sit below it.
        return $panel->filter('form[action$="/follow-ups"]');
    }

    /**
     * The component itself, mounted with the props the record page mounts it
     * with.
     *
     * Signed in through the form rather than through the harness's `actingAs()`,
     * for the same reason {@see signIn()} gives: the token either of them writes
     * has to be refreshed against a provider that reads the *tenant* database,
     * and the tenant is only chosen once a request carrying this host has been
     * made.
     */
    private function panelAs(string $email): TestLiveComponent
    {
        $this->signIn($email);

        return $this->inTenant(fn (): TestLiveComponent => $this->createLiveComponent(
            'FollowUps',
            ['module' => self::MODULE, 'recordId' => $this->recordId],
            $this->client,
        ));
    }

    /**
     * Posts one of the panel's own forms, carrying its token.
     *
     * Every write here is a plain POST with a CSRF token, exactly as the record
     * page's transitions and its delete already are — so the tests submit real
     * forms rather than building requests, and a form that stopped being drawn
     * would fail here rather than pass quietly.
     *
     * @param array<string, string> $values
     */
    private function submit(Crawler $form, array $values, string $as): void
    {
        self::assertGreaterThan(0, $form->count(), 'the form this test submits is on the page');

        $this->signIn($as);

        // Absolute, deliberately. BrowserKit resolves a relative target against
        // whatever it asked for last, and when the form came out of the
        // component harness that was the live-component endpoint — where this
        // POST is intercepted and refused as malformed JSON, which is a
        // spectacularly unhelpful way to be told the URL was wrong.
        $this->client->request('POST', $this->url((string) $form->attr('action')), [
            ...$values,
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'a write comes back to the record page',
        );
    }

    private function showAs(string $email): string
    {
        return $this->crawlShowAs($email)->html();
    }

    private function crawlShowAs(string $email): Crawler
    {
        $this->signIn($email);

        return $this->client->request('GET', $this->url(sprintf('/m/%s/%d', self::MODULE, $this->recordId)));
    }

    /**
     * Signs somebody in, through the form, once per person.
     *
     * `KernelBrowser::loginUser()` would be shorter and does not work here: it
     * writes a token into a session the firewall then has to refresh the user
     * from, and the provider that would do the refreshing reads the *tenant*
     * database, which is only chosen once a request with this host has been made.
     * The result is a silent redirect to the sign-in page, which reads exactly
     * like a permissions bug and is not one.
     *
     * Remembered, because several of these tests post twice as the same person
     * and signing in again between the two would be two requests spent proving
     * something LoginTest already covers.
     */
    private function signIn(string $email): void
    {
        if ($this->signedIn === $email) {
            return;
        }

        $page = $this->client->request('GET', $this->url('/login'));

        $this->client->submit($page->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));

        $this->signedIn = $email;
    }

    /**
     * One follow-up on the shared record, with its first note — as an id.
     *
     * **An id rather than the entity, and that is not fussiness.** Every one of
     * these helpers runs inside its own `TenantSwitcher::runFor()`, and the
     * manager it hands back to is a different one each time, so an entity
     * carried out of one block and used in the next is detached — which surfaces
     * three calls later as "a new entity was found through this relationship",
     * about a row that has been in the database the whole time. An integer
     * cannot go stale.
     */
    private function open(
        FollowUpPriority $priority,
        string $note,
        ?string $assignee = null,
        ?\DateTimeImmutable $dueAt = null,
    ): int {
        return $this->inTenant(fn (): int => (int) self::service(FollowUpManager::class)->create(
            actor: $this->userNow(self::KEEPER),
            moduleKey: self::MODULE,
            recordId: $this->recordId,
            priority: $priority,
            dueAt: $dueAt ?? new \DateTimeImmutable('+3 days'),
            assignee: $assignee === null ? null : $this->userNow($assignee),
            note: $note,
        )->getId());
    }

    /** Somebody else writing on a thread, so that a note has an author who is not the reader. */
    private function addNoteAs(string $email, int $followUpId, string $body): int
    {
        return $this->inTenant(fn (): int => (int) self::service(FollowUpManager::class)->addNote(
            $this->userNow($email),
            $this->followUp($followUpId),
            $body,
        )->getId());
    }

    /** Settling one without going through the page, for the tests that start from a settled one. */
    private function markDone(int $followUpId): void
    {
        $this->inTenant(fn () => self::service(FollowUpManager::class)
            ->markDone($this->userNow(self::KEEPER), $this->followUp($followUpId)));
    }

    /**
     * The ids of a follow-up's notes, oldest first.
     *
     * @return list<int>
     */
    private function noteIds(int $followUpId): array
    {
        return $this->inTenant(fn (): array => array_values(array_map(
            static fn (FollowUpNote $note): int => (int) $note->getId(),
            $this->followUp($followUpId)->getNotes()->toArray(),
        )));
    }

    /**
     * A follow-up's notes as text, oldest first.
     *
     * @return list<string>
     */
    private function noteBodies(FollowUp $followUp): array
    {
        return array_values(array_map(
            static fn (FollowUpNote $note): string => $note->getBody(),
            $followUp->getNotes()->toArray(),
        ));
    }

    /** One follow-up, loaded in whichever tenant block is currently open. */
    private function followUp(int $id): FollowUp
    {
        $followUp = self::service(FollowUpRepository::class)->find($id);
        self::assertInstanceOf(FollowUp::class, $followUp);

        return $followUp;
    }

    /**
     * What is stored right now, read fresh.
     *
     * The identity map is cleared first: a request the client has just made wrote
     * through the same connection, and an entity this process loaded before it
     * would otherwise still be the version from before the write.
     *
     * @return list<FollowUp>
     */
    private function followUps(): array
    {
        return $this->inTenant(function (): array {
            $this->entityManager()->clear();

            return self::service(FollowUpRepository::class)->forRecord(self::MODULE, $this->recordId);
        });
    }

    /** @param list<ModuleAction> $actions */
    private function grant(string $email, array $actions): void
    {
        foreach ($actions as $action) {
            $this->entityManager()->persist(PermissionGrant::forUser(
                $this->userNow($email),
                self::MODULE,
                $action,
            ));
        }

        $this->entityManager()->flush();
    }

    private function contact(): int
    {
        $record = new Record();
        $record->set('kind', 'person');
        $record->set('first_name', 'Ada');
        $record->set('last_name', 'Lovelace');

        return (int) self::service(RecordWriter::class)->save($this->module(), $record)->id;
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(self::MODULE);
    }

    /**
     * Somebody, looked up inside whichever tenant block is already open.
     *
     * The `Now` is a warning rather than decoration: {@see TenantSwitcher} is not
     * re-entrant in the way a wrapper here would need it to be — a `runFor`
     * inside a `runFor` hands back an entity the *outer* manager has never seen,
     * and the first thing that tries to persist a relation to it is told it has
     * found a new entity, about a row that has been in the database all along.
     * So this never opens one of its own, and every caller is somewhere that has.
     */
    private function userNow(string $email): User
    {
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine')->getManager('tenant');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
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

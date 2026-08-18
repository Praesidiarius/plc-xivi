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

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\FollowUp;
use App\Tenant\Entity\FollowUpPriority;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpLens;
use App\Tenant\FollowUp\FollowUpManager;
use App\Tenant\FollowUp\ModuleFollowUps;
use App\Tenant\FollowUp\MyFollowUps;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Dbal\CountsQueries;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * The dashboard's follow-up widget (XIV-81).
 *
 * The lens arithmetic itself is a unit test — {@see \App\Tests\Unit\FollowUp\FollowUpLensTest}
 * — because calendar boundaries need no database. What needs one is everything
 * around them: that the predicate reaches the right rows, that a record the
 * reader may no longer open is named to nobody, that a deleted record takes its
 * follow-ups off the page, and that resolving a page of follow-ups back to their
 * records costs a fixed number of queries rather than one per row.
 *
 * **The query count is asserted rather than eyeballed**, which is the assertion
 * this class exists for above the others. "This is not an N+1" is a claim about a
 * number, and the way it regresses is somebody replacing a batched read with a
 * perfectly readable loop — which passes every other test here. See
 * {@see CountsQueries} for how the number is obtained.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpWidgetTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use SharesATenant;

    private const string SLUG = 'test_follow_up_widget';
    private const string HOST = 'follow-up-widget.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    /** Holds View and every follow-up verb on both modules; opens the follow-ups. */
    private const string KEEPER = 'keeper@widget.test';

    /** Whose dashboard is under test. Holds View on both modules to begin with. */
    private const string READER = 'reader@widget.test';

    private KernelBrowser $client;
    private Tenant $tenant;
    private int $contact;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The widget resolves the reader, the zone and the grants per request,
        // and a rebooting kernel would throw away the tenant the sign-in landed
        // on between one request and the next.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            // Two modules, because the whole batching argument is about the cost
            // being per module rather than per follow-up, and one module cannot
            // tell those apart.
            $installer->install($registry->get(ContactModule::KEY));
            $installer->install($registry->get(ArticleModule::KEY));
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::KEEPER, 'Kim Keeper', self::PASSWORD, []);
        $users->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, []);

        $keeperMay = [ModuleAction::View, ModuleAction::FollowUpCreate, ModuleAction::FollowUpComplete];

        $this->grant(self::KEEPER, ContactModule::KEY, $keeperMay);
        $this->grant(self::KEEPER, ArticleModule::KEY, $keeperMay);
        // List as well as View, because the module tiles are the *other* widget
        // on this page and they are filtered by the permission the tile links to.
        // A reader with no List grant would prove nothing about the page having
        // two widgets on it.
        $this->grant(self::READER, ContactModule::KEY, [ModuleAction::View, ModuleAction::List]);
        $this->grant(self::READER, ArticleModule::KEY, [ModuleAction::View, ModuleAction::List]);

        $this->contact = $this->contact('Ada', 'Lovelace');
    }

    // -- what the widget shows ----------------------------------------------

    /**
     * The ordinary case: my work, named, and a way to get to the record.
     *
     * Read off the component rather than off the page since XIV-66, because the
     * card is fetched in a request of its own now — see
     * {@see self::testTheDashboardDoesNotWaitForTheFollowUpCard()} below, which is
     * the assertion that the page really does arrive without it. What the reader
     * ends up looking at is this, one round trip later.
     */
    public function testTheWidgetNamesAndLinksTheRecordAFollowUpIsAbout(): void
    {
        $this->open($this->contact, '-2 days', 'Call them back about the second invoice.');

        $this->signIn(self::READER);
        $markup = $this->dashboard(FollowUpLens::Week);

        self::assertStringContainsString('Call them back about the second invoice.', $markup);
        self::assertStringContainsString('Ada Lovelace', $markup);
        self::assertStringContainsString('/m/contact/' . $this->contact, $markup);
    }

    /**
     * The landing page arrives without waiting for this card (XIV-66).
     *
     * The point of `loading="defer"` is that the slowest widget costs its own
     * tile rather than the whole dashboard, and the only way to assert that from
     * outside is what the first response does *not* contain: the follow-up itself
     * is absent from the page and present one request later, which is the
     * difference between a card that blocks and a card that does not.
     *
     * The module tiles are asserted in the same breath and for the same reason
     * turned round: they are navigation, they draw inline, and a change that
     * deferred everything uniformly would be caught here rather than by somebody
     * noticing the menu flicker.
     */
    public function testTheDashboardDoesNotWaitForTheFollowUpCard(): void
    {
        $this->open($this->contact, '-2 days', 'Call them back about the second invoice.');

        $this->signIn(self::READER);
        $page = $this->client->request('GET', $this->url('/'))->filter('main')->html();

        self::assertStringNotContainsString(
            'Call them back about the second invoice.',
            $page,
            'the follow-up card is fetched separately, so its contents are not in the first response',
        );
        // The mount rather than the contents: a `data-live-url-value` on the page
        // with nothing under it is exactly what a deferred component looks like
        // before the browser has been back for it. Asserted on the URL attribute
        // rather than on the `data-action`, which carries a `->` the crawler
        // re-serialises as an entity — a brittle thing to write a test around.
        self::assertStringContainsString(
            'data-live-url-value="/_components/DueFollowUps"',
            $page,
            'the card is mounted and its contents are still to come',
        );
        self::assertStringContainsString('/m/contact', $page, 'and the module tiles still draw inline');
    }

    /**
     * The lenses are ceilings, and they nest: something overdue is under all
     * three, and something a year out is under only the widest.
     *
     * Thirty days ago and four hundred days ahead rather than anything nearer,
     * so that the assertion is about the bound and not about which day of the
     * week the suite happens to be running on.
     */
    public function testTheLensesNarrowFromTheFarEndOnly(): void
    {
        $this->open($this->contact, '-30 days', 'Missed this one.');
        $this->open($this->contact, '+400 days', 'The annual review.');

        $this->signIn(self::READER);

        foreach ([FollowUpLens::Today, FollowUpLens::Week] as $lens) {
            $page = $this->dashboard($lens);

            self::assertStringContainsString(
                'Missed this one.',
                $page,
                $lens->value . ' has no lower bound, so overdue work stays in it',
            );
            self::assertStringNotContainsString('The annual review.', $page, $lens->value . ' stops at its ceiling');
        }

        $everything = $this->dashboard(FollowUpLens::All);

        self::assertStringContainsString('Missed this one.', $everything);
        self::assertStringContainsString('The annual review.', $everything);
    }

    /**
     * The lens moves on the component, and the URL is not part of it (XIV-84).
     *
     * The behaviour that replaced `?follow_ups=today`, and the assertion is
     * deliberately about the round trip rather than about the markup: acting on
     * the live component has to *change what it renders*, because a control that
     * updates a prop and draws the same list is exactly what a page reload used
     * to hide.
     */
    public function testTheLensNarrowsOnTheComponentWithoutTheUrl(): void
    {
        $this->open($this->contact, '+400 days', 'The annual review.');

        $this->signIn(self::READER);

        $widget = $this->widget();

        self::assertStringNotContainsString(
            'The annual review.',
            $this->markup($widget),
            'the default lens has a ceiling this is well past',
        );

        $widget->call('show', ['lens' => FollowUpLens::All->value]);

        self::assertStringContainsString('The annual review.', $this->markup($widget));
    }

    /**
     * A lens nobody recognises selects the default rather than being kept.
     *
     * Props are signed, so this is not about tampering — it is that
     * {@see FollowUpLens::fromInput()} is already where "no answer" and "an
     * answer nobody recognises" both become the default, and storing the argument
     * raw would draw an empty list with no button highlighted.
     */
    public function testAnUnrecognisedLensFallsBackRatherThanSticking(): void
    {
        $this->open($this->contact, '+1 hour', 'Due very shortly.');

        $this->signIn(self::READER);

        $widget = $this->widget();
        $widget->call('show', ['lens' => 'last-tuesday']);

        self::assertStringContainsString('Due very shortly.', $this->markup($widget));
    }

    /**
     * Priority reads as a bar down the leading edge, in the same colours the
     * record page uses (XIV-84).
     *
     * The tone is asserted through the custom property rather than a class,
     * because that is where the colour actually travels — Bootstrap has no
     * per-side border colour utility, which is the whole reason
     * `.follow-up-priority` exists.
     */
    public function testPriorityIsDrawnAsALeftBarInTheSharedTone(): void
    {
        $this->open($this->contact, '+1 hour', 'This one matters.', priority: FollowUpPriority::Important);

        $this->signIn(self::READER);
        $markup = $this->dashboard(FollowUpLens::Week);

        self::assertStringContainsString('follow-up-priority', $markup);
        self::assertStringContainsString('--follow-up-tone: var(--bs-danger)', $markup);
        self::assertStringNotContainsString(
            '--bs-important',
            $markup,
            'important is not a Bootstrap context and must go through follow_up_tone()',
        );
    }

    /**
     * A follow-up whose record the reader may no longer open keeps its own text
     * and loses everything about the record.
     *
     * XIV-80 refuses the *assignment*, and deliberately does not undo one when
     * the grant is taken away afterwards (§5.18) — a screen about people must not
     * silently unassign somebody's outstanding work. This is where that residue
     * lands, and the rule is the reader keeps the work and does not learn what it
     * is attached to.
     */
    public function testAFollowUpWhoseRecordTheReaderMayNotSeeLosesItsRecordAndNotItself(): void
    {
        $this->open($this->contact, '-1 day', 'Something about somebody.');
        $this->revokeContactGrantsFrom(self::READER);

        $this->signIn(self::READER);
        $page = $this->dashboard(FollowUpLens::All);

        self::assertStringContainsString('Something about somebody.', $page, 'the work is still theirs');
        self::assertStringNotContainsString('Ada Lovelace', $page, 'the record is not');
        self::assertStringNotContainsString('/m/contact/' . $this->contact, $page, 'and there is nowhere to click');
    }

    /**
     * A grant scoped to "own records" is the same answer arrived at from the
     * other side: the follow-up is on the list, and the record it names is not
     * this reader's to be told about.
     */
    public function testAGrantScopedToOwnRecordsDoesNotNameSomebodyElsesRecord(): void
    {
        $this->open($this->contact, '-1 day', 'On a record that is not mine.');

        $this->revokeContactGrantsFrom(self::READER);
        $this->grant(self::READER, ContactModule::KEY, [ModuleAction::View], PermissionScope::Own);

        $this->signIn(self::READER);
        $page = $this->dashboard(FollowUpLens::All);

        self::assertStringContainsString('On a record that is not mine.', $page);
        self::assertStringNotContainsString('Ada Lovelace', $page);
    }

    /**
     * A soft-deleted record takes its follow-ups off the page entirely — not
     * shown without a link, gone.
     *
     * Nothing cascades, because `record_id` carries no foreign key and cannot
     * (§5.18), so this is the reading side's job and there is nothing in Postgres
     * to notice if it stops being done.
     */
    public function testFollowUpsOnADeletedRecordAreExcludedRatherThanAnonymised(): void
    {
        $this->open($this->contact, '-1 day', 'About a customer who is about to be removed.');

        $this->inTenant(function (): void {
            $module = $this->module(ContactModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $this->contact);
            self::assertInstanceOf(Record::class, $record);
            self::service(RecordWriter::class)->delete($module, $record);
        });

        $this->signIn(self::READER);
        $page = $this->dashboard(FollowUpLens::All);

        self::assertStringNotContainsString('About a customer who is about to be removed.', $page);
        self::assertStringContainsString('Nothing on your list', $page);
    }

    /** Somebody else's list is not mine, however much of it there is. */
    public function testOnlyTheSignedInPersonsFollowUpsAreShown(): void
    {
        $this->openFor(self::KEEPER, $this->contact, '-1 day', 'The keeper\'s own job.');
        $this->open($this->contact, '-1 day', 'The reader\'s job.');

        $this->signIn(self::READER);
        $page = $this->dashboard(FollowUpLens::All);

        self::assertStringContainsString('The reader\'s job.', $page);
        self::assertStringNotContainsString('The keeper\'s own job.', $page);
    }

    /** And neither is one that has been dealt with. */
    public function testSomethingAlreadyDoneIsOffTheList(): void
    {
        // Opened and closed inside one switch into the tenant, because a
        // FollowUp handed back out of one is not the entity the *next* one's
        // manager holds — and a flush on a detached object does nothing at all.
        $this->inTenant(function (): void {
            $manager = self::service(FollowUpManager::class);
            $keeper = $this->user(self::KEEPER);

            $manager->markDone($keeper, $manager->create(
                actor: $keeper,
                moduleKey: ContactModule::KEY,
                recordId: $this->contact,
                priority: FollowUpPriority::Warning,
                dueAt: new \DateTimeImmutable('-1 day'),
                assignee: $this->user(self::READER),
                note: 'Finished business.',
            ));
        });

        $this->signIn(self::READER);

        self::assertStringNotContainsString('Finished business.', $this->dashboard(FollowUpLens::All));
    }

    // -- the widget concept --------------------------------------------------

    /**
     * The dashboard is its widgets: the module tiles it always had, and the
     * follow-ups above them.
     *
     * The tiles being a widget too is the assertion worth having — if follow-ups
     * had been wired into the page beside a hard-coded grid, this would pass
     * while the concept was one interface with one implementation.
     */
    public function testTheDashboardDrawsBothWidgets(): void
    {
        $this->signIn(self::READER);
        $page = $this->client->request('GET', $this->url('/'))->filter('main')->html();

        self::assertStringContainsString('Your follow-ups', $page);
        self::assertStringContainsString('Contacts', $page, 'the module tiles are still there');
        self::assertStringContainsString('/m/contact', $page);
    }

    /**
     * A customer whose modules do not take follow-ups gets no widget at all, and
     * still gets the rest of the page.
     */
    public function testTheWidgetStaysOffAPageItHasNothingToSayOn(): void
    {
        $this->inTenant(function (): void {
            $followUps = self::service(ModuleFollowUps::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY] as $moduleKey) {
                $followUps->set($this->module($moduleKey), false);
            }
        });

        $this->signIn(self::READER);
        $page = $this->client->request('GET', $this->url('/'))->filter('main')->html();

        self::assertStringNotContainsString('Your follow-ups', $page);
        self::assertStringContainsString('Contacts', $page);
    }

    // -- the number this ticket is about -------------------------------------

    /**
     * Resolving follow-ups back to their records costs the same whether there are
     * four of them or forty.
     *
     * **Asserted as "the count does not move", not as a magic number.** What is
     * being defended is that the work is grouped by module and read in batches;
     * pinning the exact number would break every time a query is added somewhere
     * unrelated and would say nothing about the property that matters. Growing
     * the list tenfold and watching the count stay put is the assertion that can
     * only pass one way.
     */
    public function testResolvingRecordsIsBatchedPerModuleRatherThanPerFollowUp(): void
    {
        $article = $this->article('Widget');

        $this->openMany(4, $this->contact, ContactModule::KEY);
        $this->openMany(4, $article, ArticleModule::KEY);

        $small = $this->queriesToRead();

        $this->openMany(36, $this->contact, ContactModule::KEY);
        $this->openMany(36, $article, ArticleModule::KEY);

        $large = $this->queriesToRead();

        self::assertSame(
            $small,
            $large,
            sprintf('eight follow-ups cost %d queries and eighty cost %d — that is an N+1', $small, $large),
        );

        // **Both ends, and the lower one is not padding.** An equality between
        // two numbers that are both zero passes beautifully and proves nothing,
        // which is exactly what would happen if the counting middleware stopped
        // being wired up or DBAL renamed the message it logs. So the count has
        // to be a real number as well as a stable one.
        //
        // Five is what it is: one read of `follow_up` with its notes joined, one
        // liveness check per module, and one record read per module. The bounds
        // are loose either side of that, because pinning five exactly would break
        // on any unrelated query and would say nothing about the property being
        // defended.
        self::assertGreaterThan(2, $large, 'a count of nearly nothing means nothing is being counted');
        self::assertLessThan(10, $large, 'two modules should cost a handful of statements, not dozens');
    }

    /** How many statements one full read of the widget's list runs. */
    private function queriesToRead(): int
    {
        return $this->inTenant(function (): int {
            $counter = self::service(CountsQueries::class);
            $reader = $this->user(self::READER);

            // Warm whatever is cached for the request — the metadata definitions,
            // the reader's grants — so the measurement is about resolving records
            // rather than about being first.
            self::service(MyFollowUps::class)->due($reader, FollowUpLens::All, new \DateTimeZone('UTC'), 'de_CH');

            $counter->reset();
            $found = self::service(MyFollowUps::class)->due($reader, FollowUpLens::All, new \DateTimeZone('UTC'), 'de_CH');

            self::assertNotEmpty($found, 'a query count over an empty list would prove nothing');

            return $counter->count();
        });
    }

    // -- helpers -------------------------------------------------------------

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
     * The widget as this reader sees it through one lens.
     *
     * Through the component rather than through a URL since XIV-84: the lens is
     * component state now, so `?follow_ups=today` no longer exists and driving
     * this from the address bar would be testing something the application does
     * not do. The reader has to be signed in first for the same reason
     * {@see signIn()} gives — the token has to be refreshed against a provider
     * that reads the *tenant* database.
     */
    private function dashboard(FollowUpLens $lens): string
    {
        return $this->markup($this->widget($lens));
    }

    /**
     * One rendering of the widget, as prose an assertion can be written against.
     *
     * Through the crawler rather than straight off `toString()`, and that is not
     * incidental: Twig escapes an apostrophe to `&#039;`, while a parse and
     * re-serialise turns it back into the character somebody typed. The old
     * helper filtered a real page and therefore got the second, so going to the
     * raw string here would have quietly rewritten every assertion in this class
     * into entity soup — and only the ones about text containing a quote would
     * have failed, which is the worst way to find out.
     */
    private function markup(TestLiveComponent $widget): string
    {
        return $widget->render()->crawler()->html();
    }

    /** The live component itself, so a test can act on it and not only read it. */
    private function widget(?FollowUpLens $lens = null): TestLiveComponent
    {
        return $this->inTenant(fn (): TestLiveComponent => $this->createLiveComponent(
            'DueFollowUps',
            $lens === null ? [] : ['selected' => $lens->value],
            $this->client,
        ));
    }

    /** One follow-up on the reader's list. */
    private function open(
        int $recordId,
        string $due,
        string $note,
        string $moduleKey = ContactModule::KEY,
        FollowUpPriority $priority = FollowUpPriority::Warning,
    ): FollowUp {
        return $this->openFor(self::READER, $recordId, $due, $note, $moduleKey, $priority);
    }

    private function openFor(
        string $assignee,
        int $recordId,
        string $due,
        string $note,
        string $moduleKey = ContactModule::KEY,
        FollowUpPriority $priority = FollowUpPriority::Warning,
    ): FollowUp {
        return $this->inTenant(fn (): FollowUp => self::service(FollowUpManager::class)->create(
            actor: $this->user(self::KEEPER),
            moduleKey: $moduleKey,
            recordId: $recordId,
            priority: $priority,
            dueAt: new \DateTimeImmutable($due),
            assignee: $this->user($assignee),
            note: $note,
        ));
    }

    /** Several at once, for the measurement — all on one record, which is enough. */
    private function openMany(int $count, int $recordId, string $moduleKey): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->open($recordId, sprintf('-%d days', $i + 1), sprintf('Job %d.', $i), $moduleKey);
        }
    }

    /**
     * @param list<ModuleAction> $actions
     */
    private function grant(
        string $email,
        string $moduleKey,
        array $actions,
        PermissionScope $scope = PermissionScope::All,
    ): void {
        $this->inTenant(function () use ($email, $moduleKey, $actions, $scope): void {
            $user = $this->user($email);

            foreach ($actions as $action) {
                $this->entityManager()->persist(PermissionGrant::forUser($user, $moduleKey, $action, $scope));
            }

            $this->entityManager()->flush();
        });
    }

    /** Takes every contact grant off somebody, which is what revocation looks like. */
    private function revokeContactGrantsFrom(string $email): void
    {
        $this->inTenant(function () use ($email): void {
            foreach ($this->user($email)->getPermissionGrants() as $grant) {
                if ($grant->getModuleKey() === ContactModule::KEY) {
                    $this->entityManager()->remove($grant);
                }
            }

            $this->entityManager()->flush();
        });
    }

    private function contact(string $first, string $last): int
    {
        return $this->inTenant(function () use ($first, $last): int {
            $record = new Record();
            $record->set('kind', 'person');
            $record->set('first_name', $first);
            $record->set('last_name', $last);
            // Owned by the keeper, so that "scoped to own records" is a real
            // restriction for the reader rather than a no-op.
            $record->ownerId = $this->user(self::KEEPER)->getId();

            return (int) self::service(RecordWriter::class)
                ->save($this->module(ContactModule::KEY), $record)->id;
        });
    }

    private function article(string $title): int
    {
        return $this->inTenant(function () use ($title): int {
            $record = new Record();
            $record->set('title', $title);

            return (int) self::service(RecordWriter::class)
                ->save($this->module(ArticleModule::KEY), $record)->id;
        });
    }

    private function module(string $moduleKey): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get($moduleKey);
    }

    private function user(string $email): User
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

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

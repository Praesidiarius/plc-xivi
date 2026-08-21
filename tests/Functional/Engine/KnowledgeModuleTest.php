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

namespace App\Tests\Functional\Engine;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\RecordRepository;
use Xivi\Knowledge\KnowledgeModule;

/**
 * A knowledge base that is a declaration and nothing else (XIV-132).
 *
 * **What this file is really asserting is an absence.** No controller, no form
 * type, no template, no service, no migration and no field type was written for
 * this module, so every page below is the same generic pair that serves contacts
 * and invoices, reading a different set of definitions — and every property the
 * ticket asked for is a property the engine already had. If any of these ever
 * needs a class of its own to keep passing, the claim §1 makes has stopped being
 * true and this is where it should show.
 *
 * So the tests are grouped by the claim each one is defending rather than by the
 * screen it visits: the shape, the formatting, the search and its ceiling, whose
 * work it is, who may write, and the two boundaries — staleness being visible,
 * and nothing here reaching a customer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class KnowledgeModuleTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_knowledge';
    private const string HOST = 'knowledge.localhost';

    /** An administrator, who bypasses the permission axis (§8.4). */
    private const string EMAIL = 'writer@knowledge.test';

    /** An ordinary member with no grants at all, for the permission tests. */
    private const string READER = 'reader@knowledge.test';

    private const string PASSWORD = 'a-long-enough-password';

    /**
     * A body with all three of the constructs §5.21 says a `textarea` could not
     * carry: a heading, an emphasised run inside a paragraph, and a list. The
     * word `Meier` is in the prose and in no title, which is what makes the
     * search tests about the *body* rather than about the name.
     */
    private const string BODY = <<<'MARKDOWN'
        ## Wenn Meier nicht liefern kann

        Zuerst **Keller AG** anfragen, danach Brunner.

        - Keller AG: liefert in zwei Tagen
        - Brunner: teurer, liefert am selben Tag
        MARKDOWN;

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        // **Installed on its own, into a tenant with nothing else in it.** That
        // is an assertion disguised as a fixture: this module declares no
        // `requires` and no `uses`, so `install()` succeeding here is the proof
        // that somebody who signed up an hour ago can write down what they know
        // without first installing a catalogue or an address book (§5.22).
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(KnowledgeModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Writer', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::READER, 'Reader', self::PASSWORD, []);

        $this->signIn(self::EMAIL);
    }

    // -- the shape ----------------------------------------------------------

    /**
     * Three fields, and the third one is the reason this module waited for
     * [XIV-131]: a procedure written into a plain `textarea` is a wall of text.
     */
    public function testAnEntryIsATitleATopicAndAFormattedBody(): void
    {
        $definition = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            static fn () => self::service(MetadataRepository::class)->get(KnowledgeModule::KEY),
        );

        $types = [];

        foreach ($definition->getFields() as $field) {
            $types[$field->getKey()] = $field->getType();
        }

        self::assertSame([
            KnowledgeModule::TITLE => 'text',
            KnowledgeModule::TOPIC => 'choice',
            KnowledgeModule::BODY => 'markdown',
        ], $types);

        // And no collections: an entry has no rows hanging off it, which is what
        // keeps this the smallest module in the build.
        self::assertSame([], $definition->getCollections()->toArray());
    }

    /**
     * **The fields that are not there**, which is half of what this ticket
     * decided.
     *
     * No author, no written-on, no last-changed and no review date. Every one of
     * those is a field somebody would have to remember to fill in, disagreeing
     * with the truth the moment they forgot — and every one is already answered
     * by §5.2's history and by the system columns the installer puts on every
     * module's table.
     */
    public function testNothingRecordsAuthorshipOrDatesBecauseHistoryAlreadyDoes(): void
    {
        $definition = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            static fn () => self::service(MetadataRepository::class)->get(KnowledgeModule::KEY),
        );

        foreach ($definition->getFields() as $field) {
            self::assertNotContains($field->getKey(), [
                'author', 'written_by', 'written_on', 'last_reviewed', 'review_date', 'updated_by',
            ], sprintf('%s duplicates something §5.2 records', $field->getKey()));
        }
    }

    /** An entry saves, and the topic is stored as its key rather than as a label. */
    public function testAnEntrySaves(): void
    {
        $id = $this->savedId($this->write('Welcher Lieferant, wenn Meier ausfällt', KnowledgeModule::SUPPLIER));

        $entry = $this->entry($id);

        self::assertSame('Welcher Lieferant, wenn Meier ausfällt', $entry[KnowledgeModule::TITLE] ?? null);
        self::assertSame(KnowledgeModule::SUPPLIER, $entry[KnowledgeModule::TOPIC] ?? null);
        self::assertStringContainsString('Keller AG', (string) ($entry[KnowledgeModule::BODY] ?? ''));
    }

    /**
     * The topic is optional, deliberately: somebody writing down what they know
     * at half past five should not be stopped by a dropdown they have no opinion
     * about.
     */
    public function testAnEntryWithNoTopicIsStillAnEntry(): void
    {
        $saved = $this->write('Mahnlauf, Schritt für Schritt', topic: null);

        self::assertNotNull($saved->headers->get('Location'), 'it saved');
    }

    // -- the formatting -----------------------------------------------------

    /**
     * **The body arrives on the page as formatting**, which is the whole reason
     * the field type exists.
     *
     * Asserted on the markup rather than on the words: a page that had rendered
     * the source into a `<pre>` would contain every word below and would be
     * exactly the wall of text this module was waiting for [XIV-131] to avoid.
     */
    public function testTheBodyIsDrawnAsMarkupOnTheRecordPage(): void
    {
        $id = $this->savedId($this->write('Wenn Meier nicht liefern kann', KnowledgeModule::SUPPLIER));

        $crawler = $this->client->request('GET', $this->url('/m/knowledge/' . $id));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('main ul li')->count(), 'the list is a list');
        self::assertGreaterThan(0, $crawler->filter('main strong')->count(), 'the emphasis is emphasis');
        self::assertStringNotContainsString('**Keller AG**', $crawler->filter('main')->text(), 'and not the source');
    }

    /**
     * And the body is not on the index at all, because the index exists to
     * *find* an entry rather than to read one (§5.21).
     *
     * It was a column that was not drawn until XIV-168 and it is a card that is
     * not drawn now, which is the same claim about a page that changed shape
     * underneath it. The topic is still on the index and is now the thing the
     * page is arranged by, so the positive half of this reads off the card
     * heading where it used to read off a column header.
     */
    public function testTheBodyIsNotOnTheIndex(): void
    {
        $this->write('Wenn Meier nicht liefern kann', KnowledgeModule::SUPPLIER);

        $page = $this->client->request('GET', $this->url('/m/knowledge'))->filter('main');

        self::assertContains(
            'Supplier',
            $page->filter('.card-header > span:first-child')->each(static fn ($name): string => trim($name->text())),
            'the topic is what the page is arranged by',
        );
        self::assertStringNotContainsString('Keller AG', $page->text(), 'and the body is nowhere on it');
    }

    // -- the search, and its ceiling ----------------------------------------

    /**
     * **Search finds an entry by words in its body**, and nothing was built for
     * it: `filterable: true` on the body is the whole feature (§5.3).
     */
    public function testAWordInTheBodyFindsTheEntry(): void
    {
        $this->write('Lieferantenwechsel', KnowledgeModule::SUPPLIER);
        $this->write('Ferien im Juli', KnowledgeModule::OTHER, body: 'Nichts von Bedeutung.');

        self::assertSame(['Lieferantenwechsel'], $this->titlesMatching('Keller'));
    }

    /**
     * **The ceiling, asserted rather than merely written down.**.
     *
     * `Operator::Contains` is `ILIKE %word%`. Case does not matter, which is the
     * half people notice — and the half they do not is that there is no
     * stemming: the plural does not find the singular, because "Lieferanten" is
     * not a substring of "Lieferant". Full text with stemming and ranking is a
     * separate ticket (§5.22); this test exists so that the day somebody builds
     * it there is a red line pointing at what changed.
     */
    public function testTheSearchIsSubstringMatchingAndNotFullText(): void
    {
        $this->write('Lieferantenwechsel', KnowledgeModule::SUPPLIER, body: 'Zuerst den Lieferant anfragen.');

        self::assertSame(['Lieferantenwechsel'], $this->titlesMatching('LIEFERANT'), 'case does not matter');
        self::assertSame([], $this->titlesMatching('Lieferanten anfragen'), 'and stemming does not happen');
    }

    // -- whose work it is ---------------------------------------------------

    /**
     * **Who wrote it, and when it last changed, using §5.2 rather than a field.**.
     *
     * The owner and the two timestamps are on the record's own card, and the
     * timeline underneath names the person who made it — none of which this
     * module asked for, and all of which arrived with the installer.
     */
    public function testWhoWroteItAndWhenAreOnThePageWithoutAFieldSayingSo(): void
    {
        $id = $this->savedId($this->write('Reklamation: was wir zuerst prüfen', KnowledgeModule::PROCESS));

        $page = $this->client->request('GET', $this->url('/m/knowledge/' . $id))->filter('main')->text();

        self::assertStringContainsString('Writer', $page, 'who');
        self::assertStringContainsString('Created', $page);
        self::assertStringContainsString('Changed', $page);
        self::assertStringContainsString(date('Y-m-d'), $page);
    }

    /**
     * **And the age is on the index too**, which is where it has to be.
     *
     * A stale entry that looks current is this module's failure mode, and by the
     * time somebody has opened the page they have already decided this is the
     * answer they came for. It arrived as a "Changed" column beside the owner
     * column (XIV-132), both of them system columns rather than fields anybody
     * declared.
     *
     * **XIV-168 took the columns away and kept the date**, deliberately: the
     * index is cards now, and a card of bare titles would have dropped the one
     * thing that column was for. So the day each entry last changed sits at the
     * end of its line on the card. This test moved with it rather than being
     * deleted, because what it defends is the property and not the column.
     */
    public function testTheAgeOfAnEntryIsVisibleOnTheIndex(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);

        $entry = $this->client->request('GET', $this->url('/m/knowledge'))->filter('main .list-group-item');

        self::assertCount(1, $entry);
        self::assertSame(date('Y-m-d'), trim($entry->filter('time')->first()->text()));
    }

    // -- who may write ------------------------------------------------------

    /**
     * **Reading and writing are separable, and neither is granted by default.**.
     *
     * This is §8.4 unchanged and is the reason no permission concept was added:
     * `view` and `list` are one grant, `add` and `edit` are others, and a person
     * holding the first two is a reader. That is also the recommended *default*
     * for this module, argued in §5.22 — knowledge people will act on is worth
     * granting write on deliberately — and it needed nothing built, because the
     * platform default is already deny.
     */
    public function testAReaderCanReadAndCannotWrite(): void
    {
        $id = $this->savedId($this->write('Anzahlung ab welchem Betrag', KnowledgeModule::POLICY));

        $this->grant([
            [ModuleAction::List, PermissionScope::All],
            [ModuleAction::View, PermissionScope::All],
        ]);
        $this->signIn(self::READER);

        $this->client->request('GET', $this->url('/m/knowledge'));
        self::assertResponseIsSuccessful('a reader can list');

        $this->client->request('GET', $this->url('/m/knowledge/' . $id));
        self::assertResponseIsSuccessful('and can read');

        $this->client->request('GET', $this->url('/m/knowledge/new'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and cannot write');

        $this->client->request('GET', $this->url('/m/knowledge/' . $id . '/edit'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'nor change one');
    }

    /** And a writer is the same person with one more grant. Nothing else moved. */
    public function testGrantingAddIsWhatMakesSomebodyAWriter(): void
    {
        $this->grant([
            [ModuleAction::List, PermissionScope::All],
            [ModuleAction::View, PermissionScope::All],
            [ModuleAction::Add, PermissionScope::All],
        ]);
        $this->signIn(self::READER);

        $this->client->request('GET', $this->url('/m/knowledge/new'));

        self::assertResponseIsSuccessful();
    }

    // -- the boundary -------------------------------------------------------

    /**
     * **Nothing here is customer-facing**, and the declaration is what keeps it
     * that way rather than anybody's care.
     *
     * The module declares no `mailRecipient` and names no contact, so §5.14's
     * "send this record" path has nothing to resolve an address through and the
     * button is not on the page. An entry cannot be mailed to somebody by
     * accident because there is nowhere for the address to come from.
     */
    public function testAnEntryCannotBeSentToAnybody(): void
    {
        self::assertNull(
            self::service(ModuleRegistry::class)->get(KnowledgeModule::KEY)->mailRecipient,
            'no recipient is declared, so none can be resolved',
        );

        $id = $this->savedId($this->write('Übergabe an die Buchhaltung', KnowledgeModule::PROCESS));

        $page = $this->client->request('GET', $this->url('/m/knowledge/' . $id));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('a[href*="/email"]'), 'nothing offers to send it');
    }

    // -- helpers ------------------------------------------------------------

    private function write(string $title, ?string $topic, string $body = self::BODY): Response
    {
        $fields = [KnowledgeModule::TITLE => $title, KnowledgeModule::BODY => $body];

        if ($topic !== null) {
            $fields[KnowledgeModule::TOPIC] = $topic;
        }

        return $this->saveRecord(KnowledgeModule::KEY, $fields);
    }

    /**
     * The titles of the entries a `contains` filter on the body returns, read
     * off the index exactly as somebody typing into the filter bar would see
     * them.
     *
     * Off the cards since XIV-168, and across all of them: a filter reshapes the
     * cards rather than sitting above them, so what a search finds may be spread
     * over several topics and reading only the first card would be reading only
     * part of the answer.
     *
     * @return list<string>
     */
    private function titlesMatching(string $term): array
    {
        $crawler = $this->client->request('GET', $this->url(sprintf(
            '/m/knowledge?filter[0][path]=%s&filter[0][op]=contains&filter[0][value]=%s',
            KnowledgeModule::BODY,
            rawurlencode($term),
        )));

        self::assertResponseIsSuccessful();

        return $crawler->filter('main .list-group-item a')
            ->each(static fn ($link): string => trim($link->text()));
    }

    /** @return array<string, mixed> */
    private function entry(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($id): array {
            $module = self::service(MetadataRepository::class)->get(KnowledgeModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $id);

            self::assertNotNull($record);

            return $record->data;
        });
    }

    /** @param list<array{ModuleAction, PermissionScope}> $grants */
    private function grant(array $grants): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($grants): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $group = new PermissionGroup('readers', 'Readers');
            $manager->persist($group);

            foreach ($grants as [$action, $scope]) {
                $manager->persist(PermissionGrant::forGroup($group, KnowledgeModule::KEY, $action, $scope));
            }

            $user = self::service(UserRepository::class)->findOneByEmail(self::READER);
            \assert($user instanceof User);
            $user->addPermissionGroup($group);

            $manager->flush();
        });
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * Sign somebody in, dropping whoever was signed in before.
     *
     * The permission tests start from the writer's session — `setUp()` signs one
     * in, because everything before them is about what a page shows rather than
     * about who may see it — and `/login` redirects an already-authenticated
     * visitor away rather than drawing the form. Clearing the jar is signing out
     * without depending on where the logout route sends you.
     */
    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
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

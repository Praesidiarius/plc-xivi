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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;

/**
 * A timeline somebody can still read at five hundred entries (XIV-3).
 *
 * The two halves of the ticket are one design: the record page shows a fixed
 * handful whatever the volume, and the rest is a page that pages. What makes
 * both readable is that an entry is one line — the changes are there, behind a
 * disclosure, rather than printed under every row.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordHistoryTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_history';
    private const string HOST = 'history.localhost';
    private const string ADMIN = 'history@example.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string MEMBER = 'member@history.test';
    private const string PASSWORD = 'history-password';

    /** Mirrors ModuleController's own constants; the numbers are the design. */
    private const int ON_RECORD = 5;
    private const int PER_PAGE = 25;

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /**
     * The card is the same height on a record edited once and one edited every
     * day for a year, and it says how much it is not showing.
     */
    public function testTheRecordPageShowsTheLatestFewAndSaysHowManyThereAre(): void
    {
        $id = $this->aContact();
        $this->appendEntries($id, 40);

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id));
        $card = $crawler->filter('.card:contains("History")');

        self::assertCount(self::ON_RECORD, $card->filter('.history-entry'));
        self::assertStringContainsString('All 41 entries', $card->text());
    }

    /** Nothing to link to when the card is already showing all of it. */
    public function testAShortTimelineHasNoLinkToMore(): void
    {
        $id = $this->aContact();

        $card = $this->client->request('GET', $this->url('/m/contact/' . $id))
            ->filter('.card:contains("History")');

        self::assertCount(1, $card->filter('.history-entry'));
        self::assertCount(0, $card->filter(sprintf('a[href*="/m/contact/%d/history"]', $id)));
    }

    /**
     * One line per entry, with what changed behind it.
     *
     * The old layout printed every change under every row, which is what made
     * fifty entries a wall — so this asserts the shape, not only the content.
     */
    public function testAnEntryIsOneLineWithItsChangesBehindADisclosure(): void
    {
        $id = $this->aContact();
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Augusta', 'last_name' => 'Lovelace'],
            recordId: $id,
        );

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'));
        $updated = $crawler->filter('details.history-entry')->first();

        self::assertCount(1, $updated->filter('summary'));
        self::assertStringContainsString('1 change', $updated->filter('summary')->text());
        // Still there to be opened, and still the same sentence as before.
        self::assertStringContainsString('First name: Ada → Augusta', $updated->text());
    }

    public function testTheFullTimelineIsPaged(): void
    {
        $id = $this->aContact();
        $this->appendEntries($id, 59);

        $first = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'));

        self::assertResponseIsSuccessful();
        self::assertCount(self::PER_PAGE, $first->filter('.history-entry'));
        // 60 entries at 25 a page.
        self::assertCount(3, $first->filter('.pagination .page-item'));

        $second = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history?page=2'));

        self::assertCount(self::PER_PAGE, $second->filter('.history-entry'));
        self::assertNotSame($this->times($first), $this->times($second), 'page two is a different page');

        $last = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history?page=3'));

        self::assertCount(10, $last->filter('.history-entry'));
    }

    /** The page number is in the URL, so it can be anything. */
    public function testAPageNumberBeyondTheEndLandsOnTheLastPage(): void
    {
        $id = $this->aContact();
        $this->appendEntries($id, 59);

        $beyond = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history?page=900'));

        self::assertResponseIsSuccessful();
        self::assertCount(10, $beyond->filter('.history-entry'), 'the last page, not an empty one');
        self::assertSame(
            $this->times($this->client->request('GET', $this->url('/m/contact/' . $id . '/history?page=3'))),
            $this->times($beyond),
        );
    }

    /**
     * The optional half of the ticket: old entries fold away.
     *
     * Today's are open because they are what somebody came for; a year of edits
     * from before they started is context, and context costs a click.
     */
    public function testOlderEntriesAreFoldedAndRecentOnesAreNot(): void
    {
        $id = $this->aContact();
        $this->appendEntries($id, 3, new \DateTimeImmutable('-2 years'));

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'));
        $sections = $crawler->filter('details.history-section');

        // Today (the record being created) and Earlier (the three backdated).
        self::assertCount(2, $sections);
        self::assertNotNull($sections->eq(0)->attr('open'), 'the newest section is open');
        self::assertNull($sections->eq(1)->attr('open'), 'the old one is folded');
        self::assertStringContainsString('Today', $sections->eq(0)->filter('summary')->text());
        self::assertStringContainsString('Earlier', $sections->eq(1)->filter('summary')->text());
    }

    /**
     * A page deep enough to hold nothing but old entries is not a screen of shut
     * boxes: the first section on a page always opens, whatever its age.
     */
    public function testTheFirstSectionOfAPageIsOpenEvenWhenItIsOld(): void
    {
        $id = $this->aContact();
        $this->appendEntries($id, 40, new \DateTimeImmutable('-2 years'));

        $second = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history?page=2'));
        $sections = $second->filter('details.history-section');

        self::assertCount(1, $sections);
        self::assertStringContainsString('Earlier', $sections->first()->filter('summary')->text());
        self::assertNotNull($sections->first()->attr('open'));
    }

    /** Reading a record's history is reading the record (§8.4). */
    public function testSomebodyWhoMayNotViewTheRecordMayNotReadItsHistory(): void
    {
        $id = $this->aContact();

        $this->client->getCookieJar()->clear();
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // -- helpers ------------------------------------------------------------

    /** One contact, through the UI, so its history starts the way a record's does. */
    private function aContact(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            variant: 'person',
        ));
    }

    /**
     * Synthetic entries, written where the writer would write them.
     *
     * Through the repository rather than through the form: this is testing what
     * a long timeline looks like, and sixty round trips through the browser
     * would be testing the form sixty times to find out.
     */
    private function appendEntries(int $recordId, int $count, ?\DateTimeImmutable $when = null): void
    {
        $when ??= new \DateTimeImmutable();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($recordId, $count, $when): void {
            $module = self::service(MetadataRepository::class)->find(ContactModule::KEY);
            self::assertNotNull($module);

            $history = self::service(HistoryRepository::class);

            for ($i = 0; $i < $count; ++$i) {
                $history->append(
                    $module,
                    $recordId,
                    RecordAction::Updated,
                    $when->modify(sprintf('-%d minutes', $i)),
                    null,
                    'Robot',
                    new RecordChanges(['phone' => ['label' => 'Phone', 'from' => (string) $i, 'to' => (string) ($i + 1)]]),
                );
            }
        });
    }

    /** @return list<string> the times shown on a page, in order */
    private function times(Crawler $crawler): array
    {
        return $crawler->filter('.history-entry time')->each(
            static fn (Crawler $node): string => (string) $node->attr('datetime'),
        );
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

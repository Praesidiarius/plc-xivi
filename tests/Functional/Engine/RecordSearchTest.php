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
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordCandidates;
use Xivi\Core\Record\RecordWriter;

/**
 * The endpoint an autocompleting picker types at (XIV-36).
 *
 * **The sharp part of the ticket, and the reason it has a file of its own.** A
 * picker leaks the names it renders, once, on a page somebody was allowed to
 * open. A search box is a different thing: it lets whoever holds it enumerate a
 * module a letter at a time, which is strictly more than the unrestricted picker
 * XIV-13 closed. So the interesting assertions here are not that searching works
 * — they are the two that say what it refuses.
 *
 * Both seams are tested, because neither implies the other (§7.5): the route's
 * `#[IsGranted]` refuses somebody with no grant on the module at all, and the
 * `RecordAccess` predicate narrows what comes back for somebody whose grant is
 * scoped to their own records. A test of only the first would pass against an
 * endpoint that handed a scoped reader the whole module.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordSearchTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_record_search';
    private const string HOST = 'recordsearch.localhost';
    private const string ADMIN = 'admin@recordsearch.test';
    private const string SCOUT = 'scout@recordsearch.test';
    private const string STRANGER = 'stranger@recordsearch.test';
    private const string PASSWORD = 'recordsearch-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)
            ->install(self::service(ModuleRegistry::class)->get(ContactModule::KEY)));

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::SCOUT, 'Scout', self::PASSWORD, []);
        $users->create($this->tenant, self::STRANGER, 'Stranger', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    // -- what it refuses ----------------------------------------------------

    /**
     * **The assertion this endpoint exists to survive.**.
     *
     * Somebody scoped to their own records may not open a colleague's — the
     * record page answers 404 for them, because a record you may not view is one
     * this application says does not exist rather than one it refuses (§8.4). A
     * search box that returned its name would undo that in the most direct way
     * possible: the name is the thing being protected, and typing letters is a
     * cheaper way to get it than guessing ids.
     */
    public function testAScopedReaderCannotFindARecordTheyMayNotOpen(): void
    {
        $mine = $this->contact('Ada', 'Lovelace', $this->idOf(self::SCOUT));
        $theirs = $this->contact('Grace', 'Hopper', $this->idOf(self::ADMIN));

        $this->scoutMaySeeTheirOwn();
        $this->signIn(self::SCOUT);

        self::assertSame(['Ada Lovelace'], $this->labelsFrom($this->search('Lovelace')), 'their own, by name');
        self::assertSame([], $this->labelsFrom($this->search('Hopper')), 'and nothing of anybody else’s');
        self::assertSame([$mine], $this->valuesFrom($this->search('')), 'nor on the unfiltered first page');

        // And the reason that matters, stated rather than assumed: the record
        // they cannot find is a record they cannot open either. If this ever
        // started answering 200, the search would be leaking something the rest
        // of the application was still hiding.
        $this->client->request('GET', $this->url('/m/contact/' . $theirs));
        self::assertResponseStatusCodeSame(404);
    }

    /** No grant at all, and the route refuses before anything is read. */
    public function testSomebodyWithNoGrantOnTheModuleIsRefused(): void
    {
        $this->contact('Ada', 'Lovelace', $this->idOf(self::ADMIN));

        $this->signIn(self::STRANGER);
        $this->client->request('GET', $this->url('/m/contact/search?query=Lovelace'));

        self::assertResponseStatusCodeSame(403);
    }

    /** A module this customer does not have is a 404, not an empty result. */
    public function testAModuleThatIsNotInstalledIsNotFound(): void
    {
        $this->client->request('GET', $this->url('/m/invoice/search?query=anything'));

        self::assertResponseStatusCodeSame(404);
    }

    // -- what it answers ----------------------------------------------------

    /**
     * Found by any of the fields the name is built from.
     *
     * The reason {@see \Xivi\Core\Query\Search} exists: a contact is a first
     * name and a last name, and a search that could only look in one of them
     * would find Ada by "Ada" and not by "Lovelace", which nobody would describe
     * as working.
     */
    public function testItFindsARecordByEitherHalfOfItsName(): void
    {
        $this->contact('Ada', 'Lovelace', null);
        $this->contact('Grace', 'Hopper', null);

        self::assertSame(['Ada Lovelace'], $this->labelsFrom($this->search('Lovelace')));
        self::assertSame(['Ada Lovelace'], $this->labelsFrom($this->search('ada')), 'and case does not matter');
        self::assertSame([], $this->labelsFrom($this->search('Turing')));
    }

    /**
     * An empty query is the first page rather than a search for nothing — and
     * it arrives in the order the select would have put it in.
     *
     * Ordered by the shape's first title field, which for a contact is the
     * company name, so this asks about companies: what somebody sees while
     * typing has to be the list they were scrolling a moment ago, and the only
     * way to guarantee that is for both to go through the same reading.
     */
    public function testAnEmptyQueryIsTheOrdinaryFirstPage(): void
    {
        $this->company('Zeta AG', null);
        $this->company('Acme AG', null);

        self::assertSame(
            ['Acme AG', 'Zeta AG'],
            $this->labelsFrom($this->search('', ContactModule::COMPANY)),
        );
    }

    /** The variant narrows it, exactly as it narrows the select (§5.5). */
    public function testTheVariantNarrowsWhatComesBack(): void
    {
        $this->contact('Ada', 'Lovelace', null);
        $this->company('Acme AG', null);

        self::assertSame(['Acme AG'], $this->labelsFrom($this->search('', ContactModule::COMPANY)));
        self::assertSame(
            ['Ada Lovelace'],
            $this->labelsFrom($this->search('', ContactModule::PERSON)),
            'and the other way round',
        );
    }

    /**
     * More matches than a page holds, and the way to the rest.
     *
     * `next_page` rather than a total: the widget scrolls into it and says "no
     * more results" when there is not one, so nobody has to count the matches on
     * every keystroke to tell somebody where the list ends.
     */
    public function testItHandsBackTheWayToTheNextPage(): void
    {
        foreach (range(1, RecordCandidates::PER_PAGE + 3) as $n) {
            $this->contact('Ada', sprintf('Lovelace %03d', $n), null);
        }

        $first = $this->search('Lovelace');

        self::assertCount(RecordCandidates::PER_PAGE, $first['results']);
        self::assertIsString($first['next_page'], 'there is more');

        $this->client->request('GET', 'https://' . self::HOST . $first['next_page']);
        /** @var array{results: list<array{value: int, text: string}>, next_page: ?string} $second */
        $second = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $second['results'], 'and the rest of it');
        self::assertNull($second['next_page'], 'which is the end');
        self::assertSame(
            [],
            array_intersect($this->valuesFrom($first), $this->valuesFrom($second)),
            'no record is on both pages',
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return array{results: list<array{value: int, text: string}>, next_page: ?string}
     */
    private function search(string $query, ?string $variant = null): array
    {
        $this->client->request('GET', $this->url('/m/contact/search?' . http_build_query(array_filter([
            'query' => $query,
            'variant' => $variant,
        ]))));

        self::assertResponseIsSuccessful();

        /** @var array{results: list<array{value: int, text: string}>, next_page: ?string} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * @param array{results: list<array{value: int, text: string}>, next_page: ?string} $answer
     *
     * @return list<string>
     */
    private function labelsFrom(array $answer): array
    {
        return array_map(static fn (array $result): string => $result['text'], $answer['results']);
    }

    /**
     * @param array{results: list<array{value: int, text: string}>, next_page: ?string} $answer
     *
     * @return list<int>
     */
    private function valuesFrom(array $answer): array
    {
        return array_map(static fn (array $result): int => $result['value'], $answer['results']);
    }

    private function contact(string $first, string $last, ?int $ownerId): int
    {
        return $this->save(['kind' => ContactModule::PERSON, 'first_name' => $first, 'last_name' => $last], $ownerId);
    }

    private function company(string $name, ?int $ownerId): int
    {
        return $this->save(['kind' => ContactModule::COMPANY, 'company_name' => $name], $ownerId);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, ?int $ownerId): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($data, $ownerId): int {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = new Record(data: $data);
            $record->ownerId = $ownerId;

            return (int) self::service(RecordWriter::class)->save($contacts, $record)->id;
        });
    }

    /** `view`, but only their own — the grant the whole file turns on. */
    private function scoutMaySeeTheirOwn(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail(self::SCOUT);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser(
                $user,
                ContactModule::KEY,
                ModuleAction::View,
                PermissionScope::Own,
            ));
            $manager->flush();
        });
    }

    private function idOf(string $email): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email): int {
            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            return (int) $user->getId();
        });
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

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

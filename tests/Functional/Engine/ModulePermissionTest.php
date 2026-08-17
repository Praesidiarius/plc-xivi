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
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * Permissions as the application actually enforces them (§7.5).
 *
 * The two seams have to agree. A voter decides about one record and a WHERE
 * clause decides about a page of them, and if they ever disagree the disagreement
 * is the vulnerability: a record kept out of somebody's list that they can still
 * open by typing its id is not protected, it is merely inconvenient to find.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePermissionTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_module_perms';
    private const string HOST = 'perms.localhost';
    private const string MEMBER = 'member@perms.test';
    private const string COLLEAGUE = 'colleague@perms.test';
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    /** The member's own record, and one belonging to somebody else. */
    private int $mine;
    private int $theirs;

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
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
        $users->create($this->tenant, self::COLLEAGUE, 'Colleague', self::PASSWORD, []);

        $this->mine = $this->contact('Ada', 'Lovelace', self::MEMBER);
        $this->theirs = $this->contact('Alan', 'Turing', self::COLLEAGUE);
    }

    // -- nothing, by default ------------------------------------------------

    /**
     * The default this whole design turns on. Before permissions existed anybody
     * who could sign in could do anything, and an upgrade that silently kept
     * that would be the migration this deliberately did not write.
     */
    public function testAUserWithNoGrantsIsRefusedEverywhere(): void
    {
        $this->signIn(self::MEMBER);

        foreach (['/m/contact', '/m/contact/new', '/m/contact/export', '/m/contact/import'] as $path) {
            $this->client->request('GET', $this->url($path));

            self::assertResponseStatusCodeSame(
                Response::HTTP_FORBIDDEN,
                sprintf('%s should be refused with no grants', $path),
            );
        }
    }

    /** Not even the record they own — owning something is not a permission. */
    public function testOwningARecordIsNotAPermissionToSeeIt(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/' . $this->mine));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // -- listing ------------------------------------------------------------

    public function testListingWithAllScopeShowsEverybodysRecords(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::All]]);
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->text();

        self::assertStringContainsString('Lovelace', $text);
        self::assertStringContainsString('Turing', $text);
    }

    public function testListingWithOwnScopeShowsOnlyTheirOwn(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::Own]]);
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->text();

        self::assertStringContainsString('Lovelace', $text);
        self::assertStringNotContainsString('Turing', $text);
    }

    /**
     * The count is a second query, so a restriction that reached the page and
     * not the total would print the number of records somebody is not allowed
     * to see, directly underneath the ones they are.
     */
    public function testTheTotalOnTheListAgreesWithTheRestriction(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::Own]]);
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->text();

        // The exact sentence the template prints, singular and all. Asserting on
        // a bare "1" would pass against almost any page and prove nothing.
        self::assertStringContainsString('1 record.', $text);
        self::assertStringNotContainsString('2 records', $text);
    }

    /** The same total, unrestricted, so the assertion above is known to move. */
    public function testTheTotalCountsEverythingWhenTheScopeIsAll(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::All]]);
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->text();

        self::assertStringContainsString('2 records', $text);
    }

    // -- the two seams agreeing ---------------------------------------------

    /**
     * The heart of it. A record kept out of the list must also be unreachable by
     * typing its id, or the list was decoration.
     *
     * 404 rather than 403, so that guessing ids cannot be used to find out which
     * ones exist.
     */
    public function testARecordExcludedFromTheListCannotBeOpenedDirectly(): void
    {
        $this->grant([
            [ModuleAction::List, PermissionScope::Own],
            [ModuleAction::View, PermissionScope::Own],
        ]);
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/' . $this->mine));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url('/m/contact/' . $this->theirs));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Once they can see it, the refusal stops pretending. "You may look but not
     * change this" is a real answer; a 404 here would send somebody hunting for
     * a record that is on the screen in front of them.
     */
    public function testARecordTheyMayViewButNotEditIsForbiddenRatherThanMissing(): void
    {
        $this->grant([
            [ModuleAction::View, PermissionScope::All],
            [ModuleAction::Edit, PermissionScope::Own],
        ]);
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/edit', $this->mine)));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/edit', $this->theirs)));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** Deleting is its own grant: editing your own does not imply removing it. */
    public function testDeletingNeedsItsOwnGrant(): void
    {
        $this->grant([
            [ModuleAction::View, PermissionScope::All],
            [ModuleAction::Edit, PermissionScope::All],
        ]);
        $this->signIn(self::MEMBER);

        $this->client->request('POST', $this->url(sprintf('/m/contact/%d/delete', $this->mine)));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // -- import, off ROLE_ADMIN ---------------------------------------------

    /**
     * §5.6 left importing admin-only and said §7.5 would answer it. This is the
     * answer: an ordinary user with the grant may import, and an administrator
     * is no longer the only one who can.
     */
    public function testImportingIsAGrantRatherThanBeingAnAdministrator(): void
    {
        $this->grant([[ModuleAction::Import, PermissionScope::All]]);
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/import'));

        self::assertResponseIsSuccessful();
    }

    // -- what the person is told the application consists of ----------------

    /**
     * A module you cannot list is not a module you have.
     *
     * Navigation that advertises doors and then refuses them is worse than
     * navigation that is honest about the building — and it is the first thing
     * anybody notices about a permission system.
     */
    public function testAModuleYouCannotListIsNotInTheNavigation(): void
    {
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/'))->filter('body')->text();

        self::assertStringNotContainsString('Contacts', $text);
        self::assertStringContainsString('do not have access to any modules', $text);
    }

    public function testAModuleYouCanListIsInTheNavigation(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::All]]);
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/'))->filter('body')->text();

        self::assertStringContainsString('Contacts', $text);
        self::assertStringNotContainsString('do not have access to any modules', $text);
    }

    /**
     * The empty dashboard has to tell two states apart: nothing is installed,
     * which an administrator can fix with a command, and nothing is yours, which
     * they cannot. Telling somebody with no permissions to run a console command
     * against their employer's database is the wrong sentence in every respect.
     */
    public function testTheEmptyDashboardDoesNotTellOrdinaryUsersToRunConsoleCommands(): void
    {
        $this->signIn(self::MEMBER);

        $text = $this->client->request('GET', $this->url('/'))->filter('body')->text();

        self::assertStringNotContainsString('tenant:module:install', $text);
    }

    /** Buttons follow the same rule: no point offering what will be refused. */
    public function testTheListOnlyOffersButtonsForWhatIsGranted(): void
    {
        $this->grant([[ModuleAction::List, PermissionScope::All]]);
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->html();

        self::assertStringNotContainsString('module/contact/import', $page);
        self::assertStringNotContainsString('/new', $page);
        self::assertStringNotContainsString('/export', $page);
    }

    public function testTheListOffersAddOnceItIsGranted(): void
    {
        $this->grant([
            [ModuleAction::List, PermissionScope::All],
            [ModuleAction::Add, PermissionScope::All],
        ]);
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->html();

        self::assertStringContainsString('/new', $page);
    }

    /**
     * With a scope of "own" the answer differs from one row to the next, so the
     * buttons are asked about the record rather than about the module.
     */
    public function testRowButtonsAreDecidedPerRecord(): void
    {
        $this->grant([
            [ModuleAction::List, PermissionScope::All],
            [ModuleAction::Edit, PermissionScope::Own],
        ]);
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/m/contact'))->filter('main')->html();

        self::assertStringContainsString(sprintf('/m/contact/%d/edit', $this->mine), $page);
        self::assertStringNotContainsString(sprintf('/m/contact/%d/edit', $this->theirs), $page);
    }

    // -- administrators -----------------------------------------------------

    /** The bypass, which is what keeps an installation recoverable (§8.4.1). */
    public function testAnAdministratorNeedsNoGrants(): void
    {
        self::service(UserCreator::class)->create(
            $this->tenant,
            'boss@perms.test',
            'Boss',
            self::PASSWORD,
            ['ROLE_ADMIN'],
        );

        $this->signIn('boss@perms.test');

        $this->client->request('GET', $this->url('/m/contact'));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url('/m/contact/' . $this->theirs));
        self::assertResponseIsSuccessful();
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Grants the member a set of permissions on contact, through a group.
     *
     * @param list<array{0: ModuleAction, 1: PermissionScope}> $grants
     */
    private function grant(array $grants): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($grants): void {
            $manager = $this->entityManager();
            $group = new PermissionGroup('test', 'Test');
            $manager->persist($group);

            foreach ($grants as [$action, $scope]) {
                $manager->persist(PermissionGrant::forGroup($group, ContactModule::KEY, $action, $scope));
            }

            $user = self::service(UserRepository::class)->findOneByEmail(self::MEMBER);
            \assert($user instanceof User);
            $user->addPermissionGroup($group);

            $manager->flush();
        });
    }

    private function contact(string $first, string $last, string $ownerEmail): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($first, $last, $ownerEmail): int {
            $owner = self::service(UserRepository::class)->findOneByEmail($ownerEmail);
            \assert($owner instanceof User);

            $record = new Record();
            $record->set('first_name', $first);
            $record->set('last_name', $last);
            $record->set('kind', 'person');
            $record->ownerId = $owner->getId();

            return (int) self::service(RecordWriter::class)->save($this->module(), $record)->id;
        });
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
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

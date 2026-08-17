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
use App\Tenant\FollowUp\FollowUpManager;
use App\Tenant\FollowUp\FollowUpRefused;
use App\Tenant\FollowUp\ModuleFollowUps;
use App\Tenant\Repository\FollowUpRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Xivi\Article\ArticleModule;
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
 * Follow-ups: the storage, the permissions, and the per-module opt-in (XIV-80).
 *
 * There is no user interface in this ticket, so everything here goes at the
 * write path directly — which is the point rather than a limitation. The claim
 * being tested is that the rules hold without a form having been involved, since
 * an import, a console command and a future API all reach
 * {@see FollowUpManager} without passing a route or a voter.
 *
 * The two rules with no equivalent anywhere else in the application get the most
 * attention: a note belongs to whoever wrote it and to nobody else *including an
 * administrator*, and a follow-up can only be assigned to somebody who could open
 * the record it sits on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_follow_ups';
    private const string HOST = 'follow-ups.localhost';
    private const string MODULE = ContactModule::KEY;
    private const string PASSWORD = 'a-long-enough-password';

    /** Holds every follow-up verb, and View, on contact. */
    private const string KEEPER = 'keeper@follow-ups.test';

    /** Holds View and nothing else — a legitimate assignee who cannot act. */
    private const string COLLEAGUE = 'colleague@follow-ups.test';

    /** Holds nothing at all. */
    private const string OUTSIDER = 'outsider@follow-ups.test';

    /** ROLE_ADMIN, to prove the note rule has no override. */
    private const string ADMIN = 'admin@follow-ups.test';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(self::MODULE),
        ));

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::KEEPER, 'Kim Keeper', self::PASSWORD, []);
        $users->create($this->tenant, self::COLLEAGUE, 'Chris Colleague', self::PASSWORD, []);
        $users->create($this->tenant, self::OUTSIDER, 'Ollie Outsider', self::PASSWORD, []);
        $users->create($this->tenant, self::ADMIN, 'Ada Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->inTenant(function (): void {
            $this->grant(self::KEEPER, [
                ModuleAction::View,
                ModuleAction::FollowUpCreate,
                ModuleAction::FollowUpComplete,
            ]);
            $this->grant(self::COLLEAGUE, [ModuleAction::View]);
        });
    }

    public function testAFollowUpRemembersWhoMadeItAndWhoItIsFor(): void
    {
        $this->inTenant(function (): void {
            $followUp = $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Important,
                dueAt: new \DateTimeImmutable('2026-09-01 09:00:00'),
                assignee: $this->user(self::COLLEAGUE),
                note: 'They asked about the second invoice.',
            );

            self::assertSame(self::MODULE, $followUp->getModule());
            self::assertSame(FollowUpPriority::Important, $followUp->getPriority());
            self::assertSame('Kim Keeper', $followUp->getCreatedByLabel());
            // The label is captured rather than joined, so a rename later cannot
            // rewrite what this said at the time (§5.2's argument, reused).
            self::assertSame('Chris Colleague', $followUp->getAssigneeLabel());
            self::assertFalse($followUp->isDone());
            self::assertCount(1, $followUp->getNotes());
        });
    }

    /** Default deny, the same as everything else granted per module (§8.4). */
    public function testCreatingWithoutTheGrantIsRefused(): void
    {
        $this->inTenant(function (): void {
            $this->expectRefusal(fn () => $this->manager()->create(
                actor: $this->user(self::OUTSIDER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
            ));
        });
    }

    /**
     * A grant scoped to "own records" reaches the records that person owns, and
     * stops there — the same answer the list's WHERE clause gives, which is why
     * both go through RecordAccess rather than comparing ids twice.
     */
    public function testAGrantScopedToOwnRecordsDoesNotReachSomebodyElses(): void
    {
        $this->inTenant(function (): void {
            $colleague = $this->user(self::COLLEAGUE);
            $this->grant(self::COLLEAGUE, [ModuleAction::FollowUpCreate], PermissionScope::Own);

            $mine = $this->contact($colleague->getId());
            $theirs = $this->contact($this->user(self::KEEPER)->getId());

            $followUp = $this->manager()->create(
                actor: $colleague,
                moduleKey: self::MODULE,
                recordId: $mine,
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
            );

            self::assertSame($mine, $followUp->getRecordId());

            $this->expectRefusal(fn () => $this->manager()->create(
                actor: $colleague,
                moduleKey: self::MODULE,
                recordId: $theirs,
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
            ));
        });
    }

    /**
     * The parent's timestamp is about the thread, not about its own fields.
     *
     * Asserted against the follow-up's *own* `updated_at` moving while nothing on
     * that row changed, which is the only way this can be wrong: a `PreUpdate`
     * callback fires on a changed row, and a note is a different row.
     */
    public function testEditingANoteBumpsTheFollowUp(): void
    {
        $this->inTenant(function (): void {
            $manager = $this->manager();
            $keeper = $this->user(self::KEEPER);

            $followUp = $this->openOne();
            $note = $manager->addNote($keeper, $followUp, 'Called, no answer.');

            // Pushed into the past so the assertion is about the bump rather
            // than about how fast the test runs.
            $before = new \DateTimeImmutable('-1 hour');
            $this->rewind($followUp, $before);

            $manager->editNote($keeper, $note, 'Called, left a message.');

            self::assertGreaterThan($before, $followUp->getUpdatedAt());
            self::assertTrue($note->isEdited());
        });
    }

    /**
     * A note is a sentence somebody said, and there is no configuration of the
     * permission system that makes it somebody else's to rewrite — administrator
     * included, which is the assertion this test exists for.
     */
    public function testANoteMayOnlyBeChangedByItsAuthor(): void
    {
        $this->inTenant(function (): void {
            $manager = $this->manager();
            $followUp = $this->openOne();
            $note = $manager->addNote($this->user(self::KEEPER), $followUp, 'Mine.');

            $this->expectRefusal(fn () => $manager->editNote($this->user(self::ADMIN), $note, 'Not yours.'));
            $this->expectRefusal(fn () => $manager->deleteNote($this->user(self::ADMIN), $note));

            self::assertSame('Mine.', $note->getBody());

            $manager->deleteNote($this->user(self::KEEPER), $note);

            self::assertCount(0, $followUp->getNotes());
        });
    }

    /** Done is a timestamp pointing two ways, so both directions are one grant. */
    public function testMarkingDoneAndReopeningAreTheSamePermission(): void
    {
        $this->inTenant(function (): void {
            $manager = $this->manager();
            $followUp = $this->openOne();

            $manager->markDone($this->user(self::KEEPER), $followUp);
            self::assertTrue($followUp->isDone());

            $manager->reopen($this->user(self::KEEPER), $followUp);
            self::assertFalse($followUp->isDone());
        });
    }

    /** And it is a different grant from creating one. */
    public function testCompletingIsNotIncludedInCreating(): void
    {
        $this->inTenant(function (): void {
            $this->grant(self::COLLEAGUE, [ModuleAction::FollowUpCreate]);
            $followUp = $this->openOne();

            $this->expectRefusal(fn () => $this->manager()->markDone($this->user(self::COLLEAGUE), $followUp));
        });
    }

    /**
     * The rule the coordinator added after the ticket: a task must not land on a
     * list whose owner cannot open the record it is about.
     */
    public function testAFollowUpCannotBeAssignedToSomebodyWhoMayNotSeeTheRecord(): void
    {
        $this->inTenant(function (): void {
            $this->expectRefusal(fn () => $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
                assignee: $this->user(self::OUTSIDER),
            ));
        });
    }

    /**
     * And the other half: revoking the grant afterwards leaves the assignment
     * standing.
     *
     * Deliberate, not an oversight — a screen about people must not silently
     * unassign somebody's outstanding work. XIV-81's widget handles the residue
     * by listing such a follow-up without a link to its record.
     */
    public function testRevokingTheGrantLeavesAnExistingAssignmentStanding(): void
    {
        $this->inTenant(function (): void {
            $followUp = $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
                assignee: $this->user(self::COLLEAGUE),
            );

            $colleague = $this->user(self::COLLEAGUE);

            foreach ($colleague->getPermissionGrants() as $grant) {
                $this->entityManager()->remove($grant);
            }

            $this->entityManager()->flush();

            self::assertSame($colleague->getId(), $followUp->getAssigneeId());
            self::assertSame('Chris Colleague', $followUp->getAssigneeLabel());
        });
    }

    /**
     * There is no foreign key on `assignee_id`, so nothing in the database does
     * this. The listener is the whole of the mechanism.
     */
    public function testDeletingAUserLeavesTheFollowUpAndClearsTheAssignment(): void
    {
        $this->inTenant(function (): void {
            $followUp = $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Warning,
                dueAt: new \DateTimeImmutable('+1 day'),
                assignee: $this->user(self::COLLEAGUE),
            );

            $id = $followUp->getId();

            $this->entityManager()->remove($this->user(self::COLLEAGUE));
            $this->entityManager()->flush();
            // The clearing is a bulk update, which does not reach the entity
            // already in the identity map — reading it back is what the next
            // request would do.
            $this->entityManager()->clear();

            $reloaded = $this->entityManager()->find(FollowUp::class, $id);

            self::assertInstanceOf(FollowUp::class, $reloaded, 'the follow-up outlives its assignee');
            self::assertNull($reloaded->getAssigneeId());
            self::assertSame('Chris Colleague', $reloaded->getAssigneeLabel(), 'the name is the last clue there is');
        });
    }

    /**
     * Records are soft-deleted, so nothing hides these rows for us and no cascade
     * would fire even if there were a foreign key to fire it.
     */
    public function testAFollowUpOnASoftDeletedRecordDisappearsFromEveryRead(): void
    {
        $this->inTenant(function (): void {
            $recordId = $this->contact();

            $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $recordId,
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
                assignee: $this->user(self::COLLEAGUE),
            );

            $followUps = self::service(FollowUpRepository::class);
            $assignee = (int) $this->user(self::COLLEAGUE)->getId();

            self::assertCount(1, $followUps->forRecord(self::MODULE, $recordId));
            self::assertCount(1, $followUps->openFor($assignee));

            $module = $this->module();
            $record = self::service(\Xivi\Core\Record\RecordRepository::class)->find($module, $recordId);
            self::assertInstanceOf(Record::class, $record);
            self::service(RecordWriter::class)->delete($module, $record);

            self::assertSame([], $followUps->forRecord(self::MODULE, $recordId));
            self::assertSame([], $followUps->openFor($assignee), 'and it is off the widget too');
        });
    }

    /** The note's foreign key is real, and it cascades. */
    public function testRemovingAFollowUpTakesItsNotesWithIt(): void
    {
        $this->inTenant(function (): void {
            $followUp = $this->openOne();
            $this->manager()->addNote($this->user(self::KEEPER), $followUp, 'Something.');

            $this->entityManager()->remove($followUp);
            $this->entityManager()->flush();

            self::assertSame(
                0,
                (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM follow_up_note'),
            );
        });
    }

    /**
     * The opt-in, and the property that makes it different from a preset (§6.1):
     * it can be turned round for as long as the installation lives, because there
     * is no schema behind it.
     */
    public function testFollowUpsCanBeSwitchedOffAndBackOnAfterInstall(): void
    {
        $this->inTenant(function (): void {
            $followUps = self::service(ModuleFollowUps::class);

            self::assertTrue($followUps->enabledFor(self::MODULE), 'on by default');

            $followUps->set($this->module(), false);

            $this->expectRefusal(fn () => $this->manager()->create(
                actor: $this->user(self::KEEPER),
                moduleKey: self::MODULE,
                recordId: $this->contact(),
                priority: FollowUpPriority::Info,
                dueAt: new \DateTimeImmutable('+1 day'),
            ));

            $followUps->set($this->module(), true);

            // Back to writable, which is the half a one-way switch would fail.
            self::assertNotNull($this->openOne()->getId());
        });
    }

    /**
     * The console can turn them off at install time, for a headless deployment.
     *
     * The store's half of the same question is in {@see ModuleStoreTest}, where
     * the wizard it is a checkbox on already lives.
     */
    public function testTheInstallCommandCanTurnFollowUpsOff(): void
    {
        $tester = new CommandTester(
            (new Application(self::$kernel ?? self::bootKernel()))->find('tenant:module:install'),
        );

        self::assertSame(0, $tester->execute([
            'tenant' => self::SLUG,
            'module' => ArticleModule::KEY,
            '--no-follow-ups' => true,
        ]));

        $this->inTenant(function (): void {
            self::assertFalse(self::service(ModuleFollowUps::class)->enabledFor(ArticleModule::KEY));
        });
    }

    // -- helpers ----------------------------------------------------------

    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    private function inTenant(callable $work): mixed
    {
        return $this->switcher->runFor($this->tenant, $work);
    }

    /** One follow-up somebody may act on, for the tests that are about what happens next. */
    private function openOne(): FollowUp
    {
        return $this->manager()->create(
            actor: $this->user(self::KEEPER),
            moduleKey: self::MODULE,
            recordId: $this->contact(),
            priority: FollowUpPriority::Info,
            dueAt: new \DateTimeImmutable('+1 day'),
        );
    }

    /** @param callable():mixed $work */
    private function expectRefusal(callable $work): void
    {
        try {
            $work();
        } catch (FollowUpRefused) {
            return;
        }

        self::fail('the write path should have refused this');
    }

    /** @param list<ModuleAction> $actions */
    private function grant(string $email, array $actions, PermissionScope $scope = PermissionScope::All): void
    {
        $user = $this->user($email);

        foreach ($actions as $action) {
            $this->entityManager()->persist(PermissionGrant::forUser($user, self::MODULE, $action, $scope));
        }

        $this->entityManager()->flush();
    }

    private function contact(?int $ownerId = null): int
    {
        $record = new Record();
        $record->set('kind', 'person');
        $record->set('first_name', 'Ada');
        $record->set('last_name', 'Lovelace');
        $record->ownerId = $ownerId;

        return (int) self::service(RecordWriter::class)->save($this->module(), $record)->id;
    }

    /**
     * Moves a follow-up's timestamps into the past, so that "it was bumped" is an
     * assertion about the code rather than about the clock's resolution.
     */
    private function rewind(FollowUp $followUp, \DateTimeImmutable $to): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE follow_up SET updated_at = :at WHERE id = :id',
            ['at' => $to->format('Y-m-d H:i:s'), 'id' => $followUp->getId()],
        );

        $this->entityManager()->refresh($followUp);
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(self::MODULE);
    }

    private function manager(): FollowUpManager
    {
        return self::service(FollowUpManager::class);
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

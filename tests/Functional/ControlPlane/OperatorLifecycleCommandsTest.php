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

namespace App\Tests\Functional\ControlPlane;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * The half of an operator's life that XIV-57 did not build (XIV-92).
 *
 * `control:operator:create` made one and nothing removed one, disabled one,
 * changed a password or even said who existed — so every one of those was a
 * statement typed into `psql`, against the identity with the most reach in the
 * installation, usually in a hurry. §4.1 makes that argument about tenants and
 * §8.9 now makes it about operators; this is what holds the four commands to it.
 *
 * **What is asserted here is the console.** Whether a revoked operator can still
 * reach anything is a question about the firewall and about a live session, and
 * it is answered where it can be answered honestly — in
 * {@see ControlPlaneRevocationTest}, through a browser.
 *
 * Writes to the control plane, which DAMA deliberately does not roll back
 * (config/packages/test/dama_doctrine_test.yaml), so every row this makes is
 * removed by hand. Its own addresses, so this class cannot collide with
 * {@see CreateOperatorCommandTest} or {@see ControlPlaneSignInTest} when
 * paratest runs them together.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OperatorLifecycleCommandsTest extends KernelTestCase
{
    private const string FIRST = 'lifecycle-first@example.test';
    private const string SECOND = 'lifecycle-second@example.test';
    private const string PASSWORD = 'a-perfectly-long-password';
    private const string NEW_PASSWORD = 'an-entirely-different-password';

    protected function setUp(): void
    {
        self::bootKernel();

        $this->removeOperators();
    }

    protected function tearDown(): void
    {
        $this->removeOperators();

        parent::tearDown();
    }

    public function testAnOperatorIsRevokedWithoutADatabaseClient(): void
    {
        $this->createBoth();

        $tester = $this->console('control:operator:revoke', ['email' => self::SECOND]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        // The row survives and is merely inactive, which is the decision §8.9
        // argues for rather than an implementation detail. `operator()` asserts
        // the row is still findable; a test that only checked the person was
        // locked out would pass against a `DELETE` too.
        self::assertFalse($this->operator(self::SECOND)->isActive());
    }

    /** Reversible is a claim §8.9 makes; this is the route that makes it one. */
    public function testARevokedOperatorIsRestored(): void
    {
        $this->createBoth();
        $this->console('control:operator:revoke', ['email' => self::SECOND]);

        $tester = $this->console('control:operator:restore', ['email' => self::SECOND]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->operator(self::SECOND)->isActive());
    }

    public function testRevokingAnOperatorWhoIsAlreadyRevokedSaysSo(): void
    {
        $this->createBoth();
        $this->console('control:operator:revoke', ['email' => self::SECOND]);

        $tester = $this->console('control:operator:revoke', ['email' => self::SECOND]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already been revoked', $tester->getDisplay());
    }

    public function testAnUnknownAddressIsASentenceRatherThanASilentSuccess(): void
    {
        $this->create(self::FIRST);

        $commands = [
            'control:operator:revoke' => [],
            'control:operator:restore' => [],
            // Given a password it will not need, deliberately: the address is
            // resolved before anything is asked for or hashed, so a typo in it
            // must not cost two hidden prompts first.
            'control:operator:password' => ['--password' => self::NEW_PASSWORD],
        ];

        foreach ($commands as $command => $options) {
            $tester = $this->console($command, ['email' => 'nobody@example.test'] + $options);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $command);
            self::assertStringContainsString('No operator has the address', $tester->getDisplay(), $command);
        }
    }

    /**
     * The rotation route that did not exist at all before this ticket — not from
     * the console and not from the web, because the control plane has no
     * `/account` (§8.9).
     */
    public function testAnOperatorPasswordIsChanged(): void
    {
        $this->create(self::FIRST);

        $tester = $this->console('control:operator:password', [
            'email' => self::FIRST,
            '--password' => self::NEW_PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $operator = $this->operator(self::FIRST);
        $hasher = self::service(UserPasswordHasherInterface::class);

        self::assertTrue($hasher->isPasswordValid($operator, self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($operator, self::PASSWORD));
    }

    public function testAPasswordUnderTheFloorIsRefusedByTheChangeToo(): void
    {
        $this->create(self::FIRST);

        $tester = $this->console('control:operator:password', ['email' => self::FIRST, '--password' => 'short']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString((string) Operator::MINIMUM_PASSWORD_LENGTH, $tester->getDisplay());
        self::assertTrue(self::service(UserPasswordHasherInterface::class)->isPasswordValid($this->operator(self::FIRST), self::PASSWORD));
    }

    /**
     * Nothing is generated here either, and the refusal is the same one
     * `control:operator:create` makes — they share
     * {@see \Xivi\ControlPlane\Command\AsksForAnOperatorPassword} precisely so
     * that the decision cannot drift between them.
     */
    public function testAnUnattendedPasswordChangeWithNoPasswordIsRefused(): void
    {
        $this->create(self::FIRST);

        $tester = $this->console('control:operator:password', ['email' => self::FIRST], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nothing is generated here', $tester->getDisplay());
    }

    /**
     * Rotating a revoked account's password works and does not reinstate it.
     *
     * The alternative — refusing — would mean the only way to rotate a leaked
     * credential is to restore access first, which is to say to re-admit, however
     * briefly, the person whose credential leaked.
     */
    public function testARevokedOperatorsPasswordCanBeRotatedWithoutRestoringThem(): void
    {
        $this->createBoth();
        $this->console('control:operator:revoke', ['email' => self::SECOND]);

        $tester = $this->console('control:operator:password', [
            'email' => self::SECOND,
            '--password' => self::NEW_PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('still revoked', $tester->getDisplay());
        self::assertFalse($this->operator(self::SECOND)->isActive());
    }

    /**
     * "Who can sign in to this installation" — answerable without a query, which
     * is the whole of why the command exists.
     */
    public function testTheListNamesEverybodyAndMarksTheRevoked(): void
    {
        $this->createBoth();
        $this->console('control:operator:revoke', ['email' => self::SECOND]);

        $display = $this->console('control:operator:list')->getDisplay();

        self::assertStringContainsString(self::FIRST, $display);
        self::assertStringContainsString(self::SECOND, $display);
        self::assertStringContainsString('revoked', $display);

        // A revoked account is *listed*, not hidden. Hiding it would make
        // "revoked yesterday" and "never existed" the same answer, and telling
        // those apart is what somebody investigating a leaked credential is here
        // for.
        //
        // The summary line is matched as a shape rather than as two numbers.
        // The control-plane database is not rolled back between tests and is
        // shared by every paratest worker, so the operators of *other* classes
        // are in this table while this one runs — which is why the
        // last-operator refusal, whose whole subject is that count, is proved in
        // {@see \App\Tests\Unit\ControlPlane\OperatorManagerTest} against a
        // repository nothing else can write to.
        self::assertMatchesRegularExpression('/\d+ of \d+ can sign in/', $display);
    }

    /**
     * Creating over an existing address stays an error rather than becoming a
     * password change, and creating over a *revoked* one gets its own sentence.
     *
     * The convenient reading is that `create` should just set a new password.
     * It is refused because a typo'd address is then indistinguishable from a
     * rotation — one of them mints a second identity with the reach of the
     * first — and because it would undo a revocation with a command that never
     * mentions revocation.
     */
    public function testCreatingOverAnExistingAddressIsRefusedAndSaysWhichCommandToUse(): void
    {
        $this->createBoth();

        $tester = $this->console('control:operator:create', ['email' => self::FIRST, '--password' => self::NEW_PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('control:operator:password', $tester->getDisplay());
        self::assertTrue(self::service(UserPasswordHasherInterface::class)->isPasswordValid($this->operator(self::FIRST), self::PASSWORD));

        $this->console('control:operator:revoke', ['email' => self::SECOND]);

        $tester = $this->console('control:operator:create', ['email' => self::SECOND, '--password' => self::NEW_PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('control:operator:restore', $tester->getDisplay());
        self::assertFalse($this->operator(self::SECOND)->isActive());
    }

    /** A brand new operator can sign in; nothing has to switch the flag on. */
    public function testANewOperatorIsActive(): void
    {
        $this->create(self::FIRST);

        self::assertTrue($this->operator(self::FIRST)->isActive());
    }

    private function createBoth(): void
    {
        $this->create(self::FIRST);
        $this->create(self::SECOND);
    }

    private function create(string $email): void
    {
        self::service(OperatorCreator::class)->create($email, $email, self::PASSWORD);
    }

    /** @param array<string, string> $input */
    private function console(string $command, array $input = [], bool $interactive = true): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel !== null);

        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    private function operator(string $email): Operator
    {
        // The commands wrote through the same entity manager this reads from, so
        // a row changed in an earlier step is still in the identity map with its
        // old values unless the map is dropped between the write and the read.
        self::service(EntityManagerInterface::class)->clear();

        $operator = self::service(OperatorRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(Operator::class, $operator);

        return $operator;
    }

    private function removeOperators(): void
    {
        $operators = self::service(OperatorRepository::class);
        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->clear();

        foreach ([self::FIRST, self::SECOND] as $email) {
            $operator = $operators->findOneByEmail($email);

            if ($operator instanceof Operator) {
                $entityManager->remove($operator);
            }
        }

        $entityManager->flush();
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

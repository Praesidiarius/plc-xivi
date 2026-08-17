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

/**
 * The only way an operator comes into existence (XIV-57).
 *
 * There is no sign-up and there is no screen, which is not a gap to be filled
 * later: a page able to mint an identity that can see every customer is a page
 * somebody can find, and the first operator has to exist before there is anybody
 * signed in to guard it. So the console is the whole surface, and this is what
 * checks that it is the whole surface — that the command really writes an
 * account somebody can use, and that it refuses the two ways of ending up with a
 * credential nobody owns.
 *
 * Writes to the control plane, which DAMA deliberately does not roll back
 * (config/packages/test/dama_doctrine_test.yaml), so the row is cleaned up by
 * hand. Its own address, so this class and {@see ControlPlaneSignInTest} cannot
 * collide when paratest runs them together.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CreateOperatorCommandTest extends KernelTestCase
{
    private const string EMAIL = 'command@example.test';
    private const string PASSWORD = 'a-perfectly-long-password';

    protected function setUp(): void
    {
        self::bootKernel();

        $this->removeOperator();
    }

    protected function tearDown(): void
    {
        $this->removeOperator();

        parent::tearDown();
    }

    public function testItCreatesAnOperatorWhoseHashedPasswordIsTheOneThatWasTyped(): void
    {
        $tester = $this->execute(['email' => self::EMAIL, '--password' => self::PASSWORD, '--name' => 'A Person']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $operator = $this->operator();
        self::assertInstanceOf(Operator::class, $operator);
        self::assertSame('A Person', $operator->getName());
        self::assertSame([Operator::ROLE], $operator->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        self::assertTrue($hasher->isPasswordValid($operator, self::PASSWORD));
    }

    /**
     * The address, when nothing else is given.
     *
     * The same fallback `tenant:user:create` makes, and for the same reason: a
     * display name is a courtesy and an account with an empty one looks broken on
     * every page it appears on.
     */
    public function testTheNameDefaultsToTheAddress(): void
    {
        $this->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);

        self::assertSame(self::EMAIL, $this->operator()?->getName());
    }

    /**
     * Nothing is generated, and an unattended run says so rather than
     * inventing one.
     *
     * This is the departure from §8.5 that the command's docblock argues for. A
     * tenant admin's generated password is safe because `must_change_password`
     * holds the account until the owner replaces it; the control plane has no
     * account page to hold anybody on, so a password printed here would be a
     * credential two people know and nothing ever makes them rotate.
     */
    public function testAnUnattendedRunWithNoPasswordIsRefused(): void
    {
        $tester = $this->execute(['email' => self::EMAIL], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull($this->operator());
        self::assertStringContainsString('nothing is generated here', $tester->getDisplay());
    }

    public function testAPasswordUnderTheFloorIsRefused(): void
    {
        $tester = $this->execute(['email' => self::EMAIL, '--password' => 'short']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull($this->operator());
        self::assertStringContainsString((string) Operator::MINIMUM_PASSWORD_LENGTH, $tester->getDisplay());
    }

    /** The unique index holds the rule; the command is what explains it. */
    public function testASecondOperatorAtTheSameAddressIsRefused(): void
    {
        $this->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);
        $tester = $this->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    /**
     * Where to go and use it — read off the same parameter the firewall matches
     * on, so the command cannot send somebody to an address the application does
     * not answer.
     */
    public function testItSaysWhereToSignIn(): void
    {
        $tester = $this->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);

        $host = self::getContainer()->getParameter('app.control_plane_host');
        \assert(\is_string($host));

        self::assertStringContainsString($host, $tester->getDisplay());
        self::assertStringContainsString('/control/login', $tester->getDisplay());
    }

    /** @param array<string, string> $input */
    private function execute(array $input, bool $interactive = true): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel !== null);

        $tester = new CommandTester((new Application($kernel))->find('control:operator:create'));
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    private function operator(): ?Operator
    {
        $operators = self::getContainer()->get(OperatorRepository::class);
        \assert($operators instanceof OperatorRepository);

        // The command wrote through a different unit of work than the one this
        // repository reads from within the same kernel; without clearing, an
        // identity map that was empty when the row was made stays empty.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();

        return $operators->findOneByEmail(self::EMAIL);
    }

    private function removeOperator(): void
    {
        $operator = $this->operator();

        if ($operator instanceof Operator) {
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            \assert($entityManager instanceof EntityManagerInterface);
            $entityManager->remove($operator);
            $entityManager->flush();
        }
    }
}

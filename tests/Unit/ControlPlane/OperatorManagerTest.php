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

namespace App\Tests\Unit\ControlPlane;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\OperatorChangeRefused;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * The refusal that keeps an installation from being locked out of its own
 * control plane (XIV-92, §8.9).
 *
 * **A unit test rather than a command test, and the reason is the table.** The
 * guard's whole subject is *how many operators can currently sign in*, and the
 * control-plane database is not rolled back between tests
 * (config/packages/test/dama_doctrine_test.yaml) and is shared by every paratest
 * worker — so the operator rows belonging to `ControlPlaneSignInTest` and
 * `CreateOperatorCommandTest` are inside that count while any functional test of
 * it runs. A test of a count, run against a table other tests are writing to, is
 * one that passes for reasons it did not intend and fails on a Tuesday.
 *
 * So the repository is a fake standing in front of a list of `Operator` objects
 * this class owns, and `countActive()` really counts them. That is what makes
 * the interesting case sayable at all: **two rows present, one of them already
 * revoked**, which is the arrangement produced by revoking two people one after
 * the other and the one a guard written as "refuse when only one operator
 * exists" gets wrong.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OperatorManagerTest extends TestCase
{
    public function testTheLastOperatorWhoCanSignInCannotBeRevoked(): void
    {
        $only = new Operator('only@example.test', 'The Only One');

        $this->expectException(OperatorChangeRefused::class);
        $this->expectExceptionMessageMatches('/only operator who can still sign in/');

        $this->managerFor($only)->revoke($only);
    }

    /**
     * **The assertion this class exists for**: two accounts, revoked one after
     * the other, and the second call is refused.
     *
     * A guard that counted *rows* rather than active ones passes both calls —
     * there are two rows throughout — and leaves an installation with two
     * operator accounts and nobody who can sign in, on a control plane with no
     * sign-up, no invitation and no password reset to get back in through. The
     * order is the trap: the first revocation is entirely legitimate and it is
     * the second that has to be caught.
     */
    public function testTheGuardIsNotDefeatedByRevokingTwoAccountsInTurn(): void
    {
        $first = new Operator('first@example.test', 'First');
        $second = new Operator('second@example.test', 'Second');
        $manager = $this->managerFor($first, $second);

        $manager->revoke($first);
        self::assertFalse($first->isActive());

        $this->expectException(OperatorChangeRefused::class);
        $this->expectExceptionMessageMatches('/only operator who can still sign in/');

        $manager->revoke($second);
    }

    /** And having restored the first, revoking the second becomes legitimate again. */
    public function testRevokingIsAllowedOnceSomebodyElseCanSignIn(): void
    {
        $first = new Operator('first@example.test', 'First');
        $second = new Operator('second@example.test', 'Second');
        $manager = $this->managerFor($first, $second);

        $manager->revoke($first);
        $manager->restore($first);
        $manager->revoke($second);

        self::assertTrue($first->isActive());
        self::assertFalse($second->isActive());
    }

    public function testAnAlreadyRevokedOperatorIsNotRevokedTwice(): void
    {
        $gone = new Operator('gone@example.test', 'Already Gone');
        $gone->setActive(false);
        $here = new Operator('here@example.test', 'Still Here');

        $this->expectException(OperatorChangeRefused::class);
        $this->expectExceptionMessageMatches('/already been revoked/');

        $this->managerFor($gone, $here)->revoke($gone);
    }

    public function testRestoringSomebodyWhoWasNeverRevokedIsRefused(): void
    {
        $here = new Operator('here@example.test', 'Never Left');

        $this->expectException(OperatorChangeRefused::class);
        $this->expectExceptionMessageMatches('/not revoked/');

        $this->managerFor($here)->restore($here);
    }

    /**
     * Restoring never consults the count, because giving access back cannot take
     * it away from anybody — including when the row being restored is the only
     * one there is.
     */
    public function testRestoringIsNotGuarded(): void
    {
        $back = new Operator('back@example.test', 'Returning');
        $back->setActive(false);

        $this->managerFor($back)->restore($back);

        self::assertTrue($back->isActive());
    }

    public function testAnUnknownAddressIsARefusalRatherThanNull(): void
    {
        $known = new Operator('known@example.test', 'Known');

        $this->expectException(OperatorChangeRefused::class);
        $this->expectExceptionMessageMatches('/No operator has the address/');

        $this->managerFor($known)->byEmail('unknown@example.test');
    }

    /**
     * The floor is enforced by the manager, not only by the command, so the
     * screen that eventually calls this cannot forget it.
     */
    public function testAPasswordUnderTheFloorIsRefusedBeforeAnythingIsWritten(): void
    {
        $operator = new Operator('short@example.test', 'Short Password');
        $operator->setPassword('the-old-hash');

        $this->expectException(OperatorChangeRefused::class);

        try {
            $this->managerFor($operator)->changePassword(
                $operator,
                str_repeat('a', Operator::MINIMUM_PASSWORD_LENGTH - 1),
            );
        } finally {
            self::assertSame('the-old-hash', $operator->getPassword());
        }
    }

    /** A revoked account's password is rotated without its access coming back. */
    public function testRotatingARevokedOperatorsPasswordDoesNotReinstateThem(): void
    {
        $revoked = new Operator('revoked@example.test', 'Revoked');
        $revoked->setActive(false);

        $this->managerFor($revoked)->changePassword($revoked, str_repeat('a', Operator::MINIMUM_PASSWORD_LENGTH));

        self::assertSame('a-new-hash', $revoked->getPassword());
        self::assertFalse($revoked->isActive());
    }

    /**
     * A manager over exactly these operators, counted live.
     *
     * A stub rather than a mock, and PHPUnit is right to insist: nothing here
     * cares *that* a method was called, only what it answers. `OperatorRepository`
     * extends Doctrine's `ServiceEntityRepository`, whose constructor wants a
     * `ManagerRegistry` — the double does not call it, which is what makes this
     * cheap enough to be a unit test at all.
     *
     * The callbacks read `$operators` on every call rather than a number
     * captured once, so a revocation made earlier in a test is visible to the
     * guard later in the same test. Without that the case above could not be
     * written, because its whole subject is the second call seeing what the
     * first one did.
     */
    private function managerFor(Operator ...$operators): OperatorManager
    {
        $repository = $this->createStub(OperatorRepository::class);

        $repository->method('countActive')->willReturnCallback(
            static fn (): int => \count(array_filter($operators, static fn (Operator $o): bool => $o->isActive())),
        );

        $repository->method('findOneByEmail')->willReturnCallback(
            static function (string $email) use ($operators): ?Operator {
                foreach ($operators as $operator) {
                    if ($operator->getEmail() === mb_strtolower(trim($email))) {
                        return $operator;
                    }
                }

                return null;
            },
        );

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('a-new-hash');

        return new OperatorManager($repository, $this->createStub(EntityManagerInterface::class), $hasher);
    }
}

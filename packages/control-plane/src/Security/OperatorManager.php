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

namespace Xivi\ControlPlane\Security;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Repository\OperatorRepository;

/**
 * Everything that happens to an operator after they exist (XIV-92).
 *
 * {@see OperatorCreator} is the other half and stays separate for the reason
 * `App\Tenant\Security\UserCreator` and `UserManager` are separate: creating is
 * what provisioning and a first-run console command do, when there is nobody
 * around yet to be refused anything, while everything here is a change made to
 * an account that already exists and every one of its refusals is about not
 * ending up locked out of an installation with no support desk behind it.
 *
 * **Why this class exists at all, rather than three commands writing to the
 * entity.** The last-active-operator refusal is the reason. A guard that lives
 * in a command is a guard the next command does not have, and the next command
 * here is a screen — [XIV-58]'s tenant list is already the page an operator
 * lands on, and an operator page is a small step from it. Putting the rule
 * between the caller and `Operator::setActive()` means it is the *only* way the
 * flag is written, so a caller that has not thought about lock-out cannot be
 * the one that causes it.
 *
 * **No entity manager is named**, like `OperatorCreator` and unlike anything
 * touching a customer's database: the control plane is the default manager, so
 * autowiring is already right (§8.9).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OperatorManager
{
    public function __construct(
        private OperatorRepository $operators,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Everybody who can sign in to this installation, revoked ones included.
     *
     * @return list<Operator>
     */
    public function all(): array
    {
        return $this->operators->findAllOrdered();
    }

    /**
     * The row an address names, or a refusal that says so in a sentence.
     *
     * Every command below starts here, which is why the lookup is on the manager
     * rather than repeated three times: "no operator has that address" is the
     * mistake somebody makes at three in the morning with a typo in a hostname,
     * and it must not read like a stack trace.
     *
     * @throws OperatorChangeRefused
     */
    public function byEmail(string $email): Operator
    {
        return $this->operators->findOneByEmail($email)
            ?? throw OperatorChangeRefused::unknownOperator(mb_strtolower(trim($email)));
    }

    /**
     * Withdraw somebody's access, keeping the row (XIV-92, §8.9).
     *
     * **The refusal counts *active* operators, not rows**, and that is the whole
     * subtlety of the guard. "Refuse when this is the only operator" would be
     * defeated by revoking two accounts in the wrong order: with two rows
     * present, revoking the first passes the count, and revoking the second
     * passes it too — leaving an installation with two operator rows and nobody
     * who can sign in. Counting what is still active makes the second call the
     * one that is refused, which is the call somebody would actually be making.
     *
     * There is no `--force` past it, deliberately. The legitimate shape of
     * "remove the last operator" is *create the successor, then revoke*, and the
     * decommissioning case loses nothing by leaving a row behind in a database
     * that is about to be dropped. An escape hatch here would exist only to be
     * typed by the person the guard is for.
     *
     * Already-revoked is refused rather than treated as a no-op. Both are
     * defensible and the difference matters at the console: somebody revoking an
     * address in a hurry wants to know whether *this* command withdrew the
     * access or whether it was already gone, because the second answer means
     * somebody else has been here.
     *
     * @throws OperatorChangeRefused
     */
    public function revoke(Operator $operator): void
    {
        if (!$operator->isActive()) {
            throw OperatorChangeRefused::alreadyRevoked($operator->getEmail());
        }

        if ($this->operators->countActive() <= 1) {
            throw OperatorChangeRefused::lastOperator($operator->getEmail());
        }

        $operator->setActive(false);
        $this->entityManager->flush();
    }

    /**
     * The other half of a reversible decision.
     *
     * A separate command rather than `revoke --undo`, and a separate method
     * rather than `setActive(bool)`, because *reversible* is a claim §8.9 makes
     * about choosing deactivation over deletion — and a claim with no route is
     * the kind of gap §4.1 is about. A flag on a verb that means the opposite
     * reads as a contradiction at the one moment somebody is reading `--help`
     * carefully.
     *
     * No guard: restoring access cannot lock anybody out.
     *
     * @throws OperatorChangeRefused
     */
    public function restore(Operator $operator): void
    {
        if ($operator->isActive()) {
            throw OperatorChangeRefused::notRevoked($operator->getEmail());
        }

        $operator->setActive(true);
        $this->entityManager->flush();
    }

    /**
     * A new password, replacing whatever was there (XIV-92).
     *
     * **The current one is not asked for, and that is a real difference from
     * `UserManager::changeOwnPassword()`.** That method demands it because an
     * unattended browser session must not be enough to take an account over.
     * There is no session here at all: this runs at a console, on the machine the
     * installation runs on, where whoever is typing could edit the row directly —
     * so a proof of identity would be theatre, and worse, it would make the
     * command useless for the case it mostly exists for, which is a credential
     * that has leaked and whose owner is not at the keyboard.
     *
     * **A revoked operator's password can be changed**, and it is not an
     * oversight. Rotating a leaked credential and withdrawing an account are
     * two independent things somebody may want in either order, and refusing
     * this would mean the only way to rotate is to restore access first — which
     * is to say, to briefly re-admit the person whose credential leaked. The
     * command says out loud that the account is still revoked, so nobody
     * mistakes the rotation for a reinstatement.
     *
     * The length floor is checked here rather than only in the command, because
     * the command is not going to be the only caller for ever and a floor that
     * lives in the user interface is one a screen will forget.
     *
     * @throws OperatorChangeRefused
     */
    public function changePassword(Operator $operator, #[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < Operator::MINIMUM_PASSWORD_LENGTH) {
            throw OperatorChangeRefused::passwordTooShort(Operator::MINIMUM_PASSWORD_LENGTH);
        }

        $operator->setPassword($this->passwordHasher->hashPassword($operator, $password));
        $this->entityManager->flush();
    }
}

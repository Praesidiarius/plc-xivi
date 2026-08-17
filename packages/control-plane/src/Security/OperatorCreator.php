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
 * Makes an operator, and is the only thing that can (XIV-57).
 *
 * **There is no sign-up, and there is no screen.** `control:operator:create` is
 * the sole caller outside the test suite, which is not a limitation to be lifted
 * later so much as the property that makes the control plane's front door
 * defensible: a page that can mint an identity able to see every customer is a
 * page somebody can find, and the first operator has to exist before anybody can
 * sign in to guard it anyway. §8.5's provisioning path is the precedent —
 * `tenant:provision --admin-email=…` is how the first tenant user comes into
 * being for the same reason, because there is nobody signed in yet to invite
 * them.
 *
 * **No entity manager is named explicitly**, unlike everything that writes to a
 * customer's database. The control plane is the *default* manager (see
 * `config/packages/doctrine.yaml`), so autowiring is already correct here, and
 * that asymmetry with `UserManager` — which has to ask for
 * `doctrine.orm.tenant_entity_manager` by name — is the shape of the whole
 * ticket in one constructor.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OperatorCreator
{
    public function __construct(
        private OperatorRepository $operators,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @param string $password chosen by whoever runs the command, never generated
     *                         here — see `CreateOperatorCommand` for why this one
     *                         credential is not printed the way a tenant admin's is
     *
     * @throws OperatorAlreadyExists
     * @throws \InvalidArgumentException on an empty address or a password under the floor
     */
    public function create(string $email, string $name, #[\SensitiveParameter] string $password): Operator
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new \InvalidArgumentException('An operator needs an email address: it is the login.');
        }

        if (mb_strlen($password) < Operator::MINIMUM_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'An operator password must be at least %d characters.',
                Operator::MINIMUM_PASSWORD_LENGTH,
            ));
        }

        // Checked here as well as by the unique index, because the index reports
        // a driver exception and the person typing the command wants a sentence.
        // The index is what actually holds the rule; this is what explains it.
        if ($this->operators->findOneByEmail($email) !== null) {
            throw OperatorAlreadyExists::withEmail($email);
        }

        $operator = new Operator($email, $name === '' ? $email : $name);
        $operator->setPassword($this->passwordHasher->hashPassword($operator, $password));

        $this->entityManager->persist($operator);
        $this->entityManager->flush();

        return $operator;
    }
}

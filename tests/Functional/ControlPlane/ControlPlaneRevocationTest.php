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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * What revoking an operator actually does to somebody who is signed in
 * (XIV-92).
 *
 * **This is the half of the ticket that could not be answered by reasoning.**
 * "Symfony refreshes the user from the provider on every request" is true and
 * suggests that a withdrawn account falls out at the next click, and that is not
 * what happens: `ContextListener::refreshUser()` compares the stored user with
 * the reloaded one on identifier, password and roles, and `active` is none of
 * the three. The user checker wired onto the firewall does not help either,
 * because a checker is consulted when somebody signs in and never when a session
 * is restored.
 *
 * So `testARevokedOperatorsLiveSessionEnds()` was written before
 * `RevokedOperatorListener` existed and watched to fail — with
 * `ActiveOperatorChecker` already in place and the sign-in refusal already
 * passing. That is the establishing, and it is why the two classes both exist:
 * neither covers the other's case.
 *
 * The third assertion here is the one nobody has to build anything for. A
 * password *is* one of the three things `ContextListener` compares, so
 * `control:operator:password` signs every live session out on its own — worth a
 * test precisely because it is behaviour inherited rather than written, and
 * inherited behaviour is what a framework upgrade takes away quietly.
 *
 * No tenant is provisioned, unlike {@see ControlPlaneSignInTest}: nothing here
 * is about a customer, and a control-plane request resolves no tenant anyway.
 * The operator rows are removed by hand because DAMA deliberately does not roll
 * the control plane back, and **two of them are created** so that the
 * last-active-operator refusal is never the thing being tripped over — that
 * guard is proved in {@see \App\Tests\Unit\ControlPlane\OperatorManagerTest},
 * where the count can be stated rather than shared with every other test class.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneRevocationTest extends WebTestCase
{
    private const string EMAIL = 'revocation@example.test';
    private const string SPARE = 'revocation-spare@example.test';
    private const string PASSWORD = 'a-perfectly-long-password';
    private const string NEW_PASSWORD = 'an-entirely-different-password';

    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to outlive a request here: this class revokes
        // somebody *between* two requests and the second request has to see it.
        $this->client->disableReboot();

        $this->removeOperators();

        $this->host = self::service(ControlPlaneHost::class)->normalisedHost();

        self::service(OperatorCreator::class)->create(self::EMAIL, 'The Operator', self::PASSWORD);
        self::service(OperatorCreator::class)->create(self::SPARE, 'The Spare', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->removeOperators();

        parent::tearDown();
    }

    /**
     * The sign-in refusal, which is `ActiveOperatorChecker`'s half.
     *
     * Without it `Operator::active` would be a column that is written, listed
     * and never enforced — worse than not having it, because somebody would
     * revoke an account and believe it.
     */
    public function testARevokedOperatorCannotSignIn(): void
    {
        $this->revoke();

        $this->signIn(self::PASSWORD);

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));

        // And the redirect is not the assertion. What matters is that the
        // session behind it is nobody's, so the control plane itself still turns
        // them away rather than a form having merely re-rendered.
        $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));
    }

    /**
     * The page says the account was revoked rather than that the password was
     * wrong.
     *
     * `ActiveOperatorChecker` throws `CustomUserMessageAccountStatusException`
     * for the same reason the tenant checker does: somebody whose access has
     * been withdrawn needs to know to ask a colleague, and a generic *invalid
     * credentials* sends them round a password-reset loop the control plane does
     * not have.
     */
    public function testTheSignInPageSaysTheAccountWasRevoked(): void
    {
        $this->revoke();
        $this->signIn(self::PASSWORD);

        $crawler = $this->client->request('GET', sprintf('https://%s/control/login', $this->host));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('revoked', $crawler->filter('body')->text());
    }

    /**
     * **The assertion this class was built to make.** Signed in first, revoked
     * second, and the very next request is over.
     *
     * This failed before `RevokedOperatorListener` existed, with the checker
     * already refusing sign-ins — which is exactly the gap the listener fills
     * and the reason it is not redundant with the checker.
     */
    public function testARevokedOperatorsLiveSessionEnds(): void
    {
        $this->signIn(self::PASSWORD);
        $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        $this->revoke();

        $this->client->request('GET', sprintf('https://%s/control/', $this->host));

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));

        // The token is gone rather than merely being refused by a rule, which is
        // the difference between a session that is over and one that would come
        // back the moment somebody adds a page with looser access control.
        self::assertFalse($this->client->getRequest()->getSession()->has('_security_control_plane'));
    }

    /** And they are told why, rather than being left to read it as a timeout. */
    public function testARevokedOperatorIsToldWhyTheyWereSignedOut(): void
    {
        $this->signIn(self::PASSWORD);
        $this->client->request('GET', sprintf('https://%s/control/', $this->host));

        $this->revoke();

        $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('revoked', $crawler->filter('body')->text());
    }

    /**
     * Restoring lets them back in, which is what makes the choice of a flag over
     * a `DELETE` mean something (§8.9).
     */
    public function testARestoredOperatorSignsInAgain(): void
    {
        $this->revoke();
        $this->restore();

        $this->signIn(self::PASSWORD);

        self::assertResponseRedirects(sprintf('https://%s/control/', $this->host));
    }

    /**
     * Changing a password ends every session that account had, with no listener
     * of ours involved.
     *
     * Inherited from `ContextListener`, which compares the stored password hash
     * against the reloaded one — and tested for exactly that reason. Somebody
     * rotating a leaked credential is asking this question, and behaviour nobody
     * wrote is behaviour that can be taken away by a framework release without
     * anything in this repository changing.
     */
    public function testChangingAPasswordSignsThatOperatorOut(): void
    {
        $this->signIn(self::PASSWORD);
        $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        $manager = self::service(OperatorManager::class);
        $manager->changePassword($manager->byEmail(self::EMAIL), self::NEW_PASSWORD);

        $this->client->request('GET', sprintf('https://%s/control/', $this->host));

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));
    }

    /** And the new one works, so the rotation is a rotation rather than a lock-out. */
    public function testTheNewPasswordIsTheOneThatSignsIn(): void
    {
        $manager = self::service(OperatorManager::class);
        $manager->changePassword($manager->byEmail(self::EMAIL), self::NEW_PASSWORD);

        $this->signIn(self::PASSWORD);
        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->host));

        $this->signIn(self::NEW_PASSWORD);
        self::assertResponseRedirects(sprintf('https://%s/control/', $this->host));
    }

    private function revoke(): void
    {
        $manager = self::service(OperatorManager::class);
        $manager->revoke($manager->byEmail(self::EMAIL));
    }

    private function restore(): void
    {
        $manager = self::service(OperatorManager::class);
        $manager->restore($manager->byEmail(self::EMAIL));
    }

    private function signIn(#[\SensitiveParameter] string $password): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/control/login', $this->host));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => $password,
        ]));
    }

    private function removeOperators(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->clear();

        $operators = self::service(OperatorRepository::class);

        foreach ([self::EMAIL, self::SPARE] as $email) {
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

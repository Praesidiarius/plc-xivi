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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Response;

/**
 * Managing the people who can sign in to one customer's installation (§8).
 *
 * The half that matters here is not the forms — it is that every refusal really
 * refuses. An installation whose last administrator can deactivate themselves is
 * one nobody can get back into.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UserManagementTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_users';
    private const string HOST = 'users.localhost';
    private const string ADMIN = 'admin@users.test';
    private const string SECOND_ADMIN = 'second@users.test';
    private const string MEMBER = 'member@users.test';
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
    }

    public function testAnOrdinaryUserCannotReachTheUserList(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/users'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testTheListShowsEverybodyAndWhatTheyAre(): void
    {
        $this->signIn(self::ADMIN);

        $text = $this->client->request('GET', $this->url('/users'))->filter('main')->text();

        self::assertStringContainsString(self::ADMIN, $text);
        self::assertStringContainsString(self::MEMBER, $text);
        self::assertStringContainsString('Administrator', $text);
        self::assertStringContainsString('Member', $text);
    }

    /**
     * The point of the whole screen: a colleague can be added without a console
     * command against the customer's database.
     */
    public function testAnAdminCanAddAUserAndIsShownTheirPasswordOnce(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/users/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => 'new@users.test',
            'name' => 'Newcomer',
        ]));

        $text = $this->client->followRedirect()->filter('main')->text();
        self::assertStringContainsString('cannot be shown again', $text);

        // Shown once means once: the next page does not carry it.
        $again = $this->client->request('GET', $this->url('/users'))->filter('main')->text();
        self::assertStringNotContainsString('cannot be shown again', $again);

        // And it is a password that works, not a decoration.
        $password = $this->passwordFrom($text);
        $this->client->request('POST', $this->url('/logout'));
        $this->signIn('new@users.test', $password);

        // Signed in, but held at the password page until they pick their own.
        $this->client->request('GET', $this->url('/'));
        self::assertResponseRedirects();
        self::assertStringContainsString('/account', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * A generated password is a way in, not a credential: the administrator read
     * it off a screen and passed it on somehow, so at least two people know it
     * until its owner replaces it (§8.5).
     */
    public function testANewUserIsHeldAtThePasswordPageUntilTheyChooseTheirOwn(): void
    {
        $password = $this->addUser('held@users.test');

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn('held@users.test', $password);

        // Every page, not just the one they asked for.
        foreach (['/', '/users'] as $path) {
            $this->client->request('GET', $this->url($path));
            self::assertResponseRedirects(message: sprintf('%s should have been held', $path));
        }

        $crawler = $this->client->request('GET', $this->url('/account'));
        self::assertStringContainsString('before you carry on', $crawler->filter('main')->text());

        $this->client->submit($crawler->selectButton('Change password')->form([
            'current_password' => $password,
            'new_password' => 'one-of-my-very-own',
            'repeat_password' => 'one-of-my-very-own',
        ]));

        // Let straight through to where they were going, not left on the page
        // that was holding them.
        self::assertResponseRedirects();
        $this->client->followRedirect();

        $this->client->request('GET', $this->url('/'));
        self::assertResponseIsSuccessful();
    }

    /** Somebody who cannot change their password right now must still be able to leave. */
    public function testSomebodyHeldCanStillSignOut(): void
    {
        $password = $this->addUser('leaving@users.test');

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn('leaving@users.test', $password);

        $this->client->request('POST', $this->url('/logout'));
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('login', (string) $this->client->getRequest()->getUri());
    }

    /** A password somebody else generated for you is the same situation again. */
    public function testAResetPasswordAlsoHoldsTheAccount(): void
    {
        $this->signIn(self::ADMIN);

        $member = $this->find(self::MEMBER);
        $this->client->request('POST', $this->url('/users/' . $member->getId() . '/password'), [
            '_token' => $this->token('manage-users'),
        ]);
        $password = $this->passwordFrom($this->client->followRedirect()->filter('main')->text());

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn(self::MEMBER, $password);

        $this->client->request('GET', $this->url('/'));
        self::assertResponseRedirects();
    }

    /**
     * Provisioning and the console hand in a password somebody chose. Demanding
     * they change it immediately would be telling them their own decision was
     * wrong — and it is how every other test in this class signs in.
     */
    public function testAPasswordChosenByWhoeverCreatedTheAccountIsNotHeld(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/'));

        self::assertResponseIsSuccessful();
    }

    public function testAnEmailAlreadyInUseHereIsRefused(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/users/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => self::MEMBER,
            'name' => 'Impostor',
        ]));

        self::assertStringContainsString('already signs in', $this->client->getCrawler()->filter('main')->text());
    }

    public function testAnAdminCanMakeSomebodyElseAnAdmin(): void
    {
        $this->signIn(self::ADMIN);

        $this->editMember(['admin' => '1']);

        self::assertTrue($this->isAdmin(self::MEMBER));
    }

    /**
     * Deactivating has to actually lock somebody out. `User::active` existed long
     * before anything read it, so this is the test that says it means something.
     */
    public function testADeactivatedUserCannotSignIn(): void
    {
        $this->signIn(self::ADMIN);
        $this->deactivate(self::MEMBER);

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/'));
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Withdrawing access should not wait for a session to expire on its own.
     *
     * Deactivated through the manager rather than through the admin screens,
     * because one test cannot hold two browsers — and the point being made is
     * about the session that already exists, not about who ended it.
     */
    public function testDeactivatingSomebodyAlreadySignedInTakesEffectAtOnce(): void
    {
        $this->signIn(self::MEMBER);
        $this->client->request('GET', $this->url('/'));
        self::assertResponseIsSuccessful();

        // Loaded and changed inside one tenant scope. Splitting them leaves the
        // entity detached when the flush happens, and nothing reaches the
        // database — which is a mistake worth only making once.
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $member = self::service(UserRepository::class)->findOneByEmail(self::MEMBER);
            \assert($member instanceof User);

            self::service(UserManager::class)->setActive($member, false, null);
        });

        $this->client->request('GET', $this->url('/'));

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'a deactivated user keeps browsing until their session happens to expire',
        );
    }

    public function testTheLastAdminCannotDeactivateThemselves(): void
    {
        $this->signIn(self::ADMIN);
        $this->deactivate(self::ADMIN);

        self::assertStringContainsString('your own account', $this->client->getCrawler()->filter('main')->text());
        self::assertTrue($this->isActive(self::ADMIN));
    }

    public function testAnAdminCannotTakeAdministratorFromThemselves(): void
    {
        $this->signIn(self::ADMIN);

        $user = $this->find(self::ADMIN);
        $crawler = $this->client->request('GET', $this->url('/users/' . $user->getId()));
        $form = $crawler->selectButton('Save')->form();
        $admin = $form['admin'];
        \assert($admin instanceof ChoiceFormField);
        $admin->untick();
        $this->client->submit($form);

        self::assertStringContainsString('take administrator away from yourself', $this->client->getCrawler()->filter('main')->text());
        self::assertTrue($this->isAdmin(self::ADMIN));
    }

    /**
     * The other way to end up with nobody in charge: a second admin removing the
     * one who is left, having just deactivated themselves out of the count.
     */
    public function testTheOnlyRemainingAdminCannotBeDemotedBySomebodyElse(): void
    {
        self::service(UserCreator::class)
            ->create($this->tenant, self::SECOND_ADMIN, 'Second', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn(self::SECOND_ADMIN);

        // The first admin is deactivated, so the signed-in one is now the only
        // active administrator — and deactivating them would leave none.
        $this->deactivate(self::ADMIN);

        $second = $this->find(self::SECOND_ADMIN);
        $this->client->request('POST', $this->url('/users/' . $second->getId() . '/active'), [
            '_token' => $this->token('manage-users'),
            'active' => '0',
        ]);

        self::assertTrue($this->isActive(self::SECOND_ADMIN));
    }

    public function testAResetPasswordIsShownAndWorks(): void
    {
        $this->signIn(self::ADMIN);

        $member = $this->find(self::MEMBER);
        $this->client->request('POST', $this->url('/users/' . $member->getId() . '/password'), [
            '_token' => $this->token('manage-users'),
        ]);

        $text = $this->client->followRedirect()->filter('main')->text();
        $password = $this->passwordFrom($text);

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn(self::MEMBER, $password);

        // Held rather than turned away: the new password worked, which is what
        // this is about. The hold itself has its own test.
        $this->client->request('GET', $this->url('/account'));
        self::assertResponseIsSuccessful();
    }

    /** @return string the generated password the administrator was shown */
    private function addUser(string $email): string
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/users/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => $email,
            'name' => 'Newcomer',
        ]));

        return $this->passwordFrom($this->client->followRedirect()->filter('main')->text());
    }

    public function testSomebodyCanChangeTheirOwnPassword(): void
    {
        $this->signIn(self::MEMBER);

        $crawler = $this->client->request('GET', $this->url('/account'));
        $this->client->submit($crawler->selectButton('Change password')->form([
            'current_password' => self::PASSWORD,
            'new_password' => 'a-brand-new-password',
            'repeat_password' => 'a-brand-new-password',
        ]));

        self::assertStringContainsString('has been changed', $this->client->getCrawler()->filter('main')->text());

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn(self::MEMBER, 'a-brand-new-password');
        $this->client->request('GET', $this->url('/'));

        self::assertResponseIsSuccessful();
    }

    /** An unattended session should not be enough to take an account over. */
    public function testChangingAPasswordNeedsTheCurrentOne(): void
    {
        $this->signIn(self::MEMBER);

        $crawler = $this->client->request('GET', $this->url('/account'));
        $this->client->submit($crawler->selectButton('Change password')->form([
            'current_password' => 'not-the-right-one',
            'new_password' => 'a-brand-new-password',
            'repeat_password' => 'a-brand-new-password',
        ]));

        self::assertStringContainsString('not your current password', $this->client->getCrawler()->filter('main')->text());

        $this->client->request('POST', $this->url('/logout'));
        $this->signIn(self::MEMBER, 'a-brand-new-password');
        $this->client->request('GET', $this->url('/'));

        self::assertResponseRedirects();
    }

    /** @param array<string, string> $overrides */
    private function editMember(array $overrides): void
    {
        $member = $this->find(self::MEMBER);
        $crawler = $this->client->request('GET', $this->url('/users/' . $member->getId()));

        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => self::MEMBER,
            'name' => 'Member',
            ...$overrides,
        ]));
    }

    private function deactivate(string $email): void
    {
        $user = $this->find($email);

        $this->client->request('POST', $this->url('/users/' . $user->getId() . '/active'), [
            '_token' => $this->token('manage-users'),
            'active' => '0',
        ]);

        $this->client->followRedirect();
    }

    private function token(string $id): string
    {
        // Rendered by the page rather than generated here, so the test posts the
        // token a person's browser would have.
        $crawler = $this->client->request('GET', $this->url('/users'));

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function passwordFrom(string $text): string
    {
        self::assertSame(1, preg_match('/(?:is|:) ([A-Za-z0-9_-]{16}) —/u', $text, $matches), $text);

        return $matches[1];
    }

    private function find(string $email): User
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): User => self::service(UserRepository::class)->findOneByEmail($email)
                ?? self::fail(sprintf('No user %s', $email)),
        );
    }

    private function isAdmin(string $email): bool
    {
        return \in_array('ROLE_ADMIN', $this->find($email)->getRoles(), true);
    }

    private function isActive(string $email): bool
    {
        return $this->find($email)->isActive();
    }

    private function signIn(string $email, string $password = self::PASSWORD): void
    {
        $this->signInWith($this->client, $email, $password);
    }

    private function signInWith(KernelBrowser $client, string $email, string $password = self::PASSWORD): void
    {
        $crawler = $client->request('GET', sprintf('https://%s/login', self::HOST));
        $client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => $password,
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

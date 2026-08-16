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
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Inviting a colleague by email instead of reading them a password (XIV-1).
 *
 * **The happy path is the least interesting thing here.** An invitation link is a
 * credential that arrives by mail and lets somebody in without a password, so
 * what this class is mostly about is the four ways it has to stop being one: it
 * expires, it is spent on use, a second invitation retires the first, and a
 * deactivated account's link is refused at the door. Each of those is a property
 * that fails *open* and silently when it breaks — the link keeps working, which
 * looks exactly like it working correctly.
 *
 * **Nothing here puts mail on the wire and nothing reads the catcher.** XIV-37's
 * `NonProductionMailGuard` refuses to build a transport that could deliver
 * outside production, including the dev catcher, and §9.2 refused the catcher for
 * the suite separately — eight paratest workers against one inbox is a shared
 * mutable thing. So the message is read out of Symfony's own message logger,
 * which collects in this process before any transport, exactly as
 * `OutgoingMailTest` and `SendEmailTest` do. The link the tests then click is the
 * one that was really in the mail.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UserInvitationTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use SharesATenant;

    private const string SLUG = 'test_invitations';
    private const string HOST = 'invites.localhost';
    private const string ADMIN = 'admin@invites.test';
    private const string MEMBER = 'member@invites.test';
    private const string PASSWORD = 'a-long-enough-password';
    private const string CHOSEN = 'one-i-picked-myself';

    /** Symfony's own words for a link that does not verify, in the `security` domain. */
    private const string STALE_LINK = 'Invalid or expired login link.';

    /** ActiveUserChecker's, which is a different refusal with a different remedy. */
    private const string DEACTIVATED = 'This account has been deactivated.';

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

    // -- what the ticket asked for -----------------------------------------

    /**
     * The choice itself: an administrator picks the invitation instead of the
     * generated password, and a mail goes out.
     */
    public function testAnAdminCanInviteSomebodyInsteadOfGeneratingAPassword(): void
    {
        $this->invite('invited@invites.test');

        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailHeaderSame($message, 'To', 'Newcomer <invited@invites.test>');
        self::assertEmailTextBodyContains($message, 'https://' . self::HOST . '/invitation?');

        // Through TenantMailer with no exception carved for it (§8.7, §8.8), so a
        // tenant that has configured nothing sends this from the instance under
        // their own name — which is also what makes it work for XIV-64's first
        // user, before anybody has configured anything at all.
        self::assertEmailAddressContains($message, 'From', 'no-reply@' . self::HOST);
        self::assertEmailHeaderSame(
            $message,
            'Subject',
            sprintf('You have been invited to %s', $this->tenant->getName()),
        );

        // The two things the message has to say beyond the link itself.
        self::assertEmailTextBodyContains($message, 'after 24 hours');
        self::assertEmailTextBodyContains($message, 'only once');

        // Both parts, because a message with only an HTML body is one a
        // text-only client shows as nothing — and this one exists to carry a URL.
        self::assertEmailHtmlBodyContains($message, '/invitation?');

        $text = $this->client->followRedirect()->filter('main')->text();
        self::assertStringContainsString('invited@invites.test', $text);
    }

    /**
     * **No password is generated**, which is half of the acceptance criteria and
     * the half that is invisible if it regresses.
     *
     * An unused generated password is a credential sitting on the account that
     * nobody will ever rotate, because nobody knows it is there.
     */
    public function testInvitingGeneratesNoPasswordAtAll(): void
    {
        $this->invite('nopassword@invites.test');

        self::assertFalse($this->find('nopassword@invites.test')->hasPassword());

        // And the screen does not print one either: the flash that shows a
        // generated password is the one thing this path must not produce.
        $text = $this->client->followRedirect()->filter('main')->text();
        self::assertStringNotContainsString('cannot be shown again', $text);
    }

    /** The other half of the choice still works exactly as it did (§8.5). */
    public function testTheGeneratedPasswordPathIsStillOffered(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/users/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => 'classic@invites.test',
            'name' => 'Classic',
            'method' => 'password',
        ]));

        self::assertEmailCount(0, message: 'the password path must not send mail');
        self::assertStringContainsString(
            'cannot be shown again',
            $this->client->followRedirect()->filter('main')->text(),
        );
        self::assertTrue($this->find('classic@invites.test')->hasPassword());
    }

    /** The whole point: the link gets them in, and lands them on the password page. */
    public function testTheLinkSignsThemInAndHoldsThemAtThePasswordPage(): void
    {
        $link = $this->invite('arriving@invites.test');

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('/account', (string) $this->client->getRequest()->getUri());
        self::assertStringContainsString('Choose a password', $crawler->filter('main')->text());

        // They may set one without being asked for a current password, because
        // there is none — the link was the proof (XIV-1).
        $this->client->submit($crawler->selectButton('Set password')->form([
            'new_password' => self::CHOSEN,
            'repeat_password' => self::CHOSEN,
        ]));

        $this->client->request('GET', $this->url('/'));
        self::assertResponseIsSuccessful();

        // And it is a real password afterwards, which is what makes the next
        // sign-in possible at all.
        $this->client->request('POST', $this->url('/logout'));
        $this->signIn('arriving@invites.test', self::CHOSEN);
        $this->client->request('GET', $this->url('/'));
        self::assertResponseIsSuccessful();
    }

    // -- the properties that must hold --------------------------------------

    /** Twenty-four hours, read off the mechanism rather than off the config file. */
    public function testTheLinkIsGoodForTwentyFourHours(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url('/users'));

        $expires = $this->loginLinks()
            ->createLoginLink($this->find(self::MEMBER), $this->client->getRequest())
            ->getExpiresAt();

        // A minute of slack for the wall clock between the two calls; the point
        // is a day rather than the ten minutes Symfony defaults to.
        self::assertEqualsWithDelta(86400, $expires->getTimestamp() - time(), 60);
    }

    /**
     * An expired link is refused, and it is the *expiry* refusing it.
     *
     * Minted through the real handler with a negative lifetime, so the signature
     * is genuine and everything except the clock is in order. Editing the
     * `expires` parameter of a valid link would prove nothing: the HMAC covers
     * it, so such a link would be rejected as forged even if expiry were never
     * checked at all.
     */
    public function testAnExpiredLinkIsRefused(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url('/users'));

        // The browser's own request is handed in so the handler generates the URL
        // against this tenant's hostname. Without it the router falls back to
        // whatever context it was left in, and the link points at a host that
        // resolves to no tenant — which fails for a reason that has nothing to do
        // with what is being tested.
        $stale = $this->loginLinks()
            ->createLoginLink($this->find(self::MEMBER), $this->client->getRequest(), -10)
            ->getUrl();

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $stale);

        // Symfony's sentence names the cause; ours says what to do about it,
        // which is the whole difference between this and a blank 403 shown to
        // somebody who has no account here to sign in to.
        self::assertStringContainsString(
            'send you a new invitation',
            $this->assertTurnedAwayWithAnExplanation(self::STALE_LINK),
        );
    }

    /** Single use: accepting it is what invalidates it. */
    public function testALinkCannotBeUsedTwice(): void
    {
        $link = $this->invite('once@invites.test');

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);
        self::assertResponseRedirects();

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);

        $this->assertTurnedAwayWithAnExplanation(self::STALE_LINK);
    }

    /**
     * A second invitation retires the first, and restarts the clock.
     *
     * The decision, written down: there is never more than one live invitation
     * per person. Letting both run would mean "I sent them a new one" was not a
     * way to fix an invitation that leaked.
     */
    public function testASecondInvitationInvalidatesTheFirst(): void
    {
        $first = $this->invite('twice@invites.test');

        $user = $this->find('twice@invites.test');
        $this->client->request('POST', $this->url('/users/' . $user->getId() . '/invite'), [
            '_token' => $this->token(),
        ]);

        $second = self::linkInTheLastMessage();
        self::assertNotSame($first, $second, 'the second invitation reissued the same link');

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $first);
        $this->assertTurnedAwayWithAnExplanation(self::STALE_LINK);

        // And the replacement is the one that works.
        $this->client->request('GET', $second);
        self::assertResponseRedirects();
    }

    /**
     * Withdrawing access has to withdraw the invitation with it (§8.5).
     *
     * `ActiveUserChecker` is what refuses this, and it is consulted by the login
     * link authenticator exactly as it is by the sign-in form — which is most of
     * the argument for using the firewall's own mechanism rather than a
     * controller that would have had to remember to ask.
     */
    public function testADeactivatedUsersLinkDoesNotWork(): void
    {
        $link = $this->invite('withdrawn@invites.test');

        $this->setActive($this->find('withdrawn@invites.test'), false);

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);

        // Told which of the two it was, deliberately: "deactivated" sends
        // somebody to ask a colleague, where "expired" would send them to ask for
        // a new link that would be refused in exactly the same way.
        $this->assertTurnedAwayWithAnExplanation(self::DEACTIVATED);
    }

    /**
     * A refusal does not spend the invitation.
     *
     * The seed is rotated in a listener on `LoginSuccessEvent`, which is after the
     * user checker — so a deactivated person's click never consumes their link.
     * Reactivating them inside the 24 hours makes the invitation they were already
     * sent work, rather than having silently burnt it on a refusal they never saw.
     */
    public function testALinkRefusedForDeactivationSurvivesReactivation(): void
    {
        $link = $this->invite('paused@invites.test');
        $user = $this->find('paused@invites.test');

        $this->setActive($user, false);
        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);
        $this->assertTurnedAwayWithAnExplanation(self::DEACTIVATED);

        $this->signIn(self::ADMIN);
        $this->setActive($user, true);

        $this->client->request('POST', $this->url('/logout'));
        $this->client->request('GET', $link);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('/account', (string) $this->client->getRequest()->getUri());
    }

    /**
     * Nothing replayable is written down.
     *
     * There is no invitation table and no stored token, hashed or otherwise: what
     * is in the row is one input to an HMAC keyed with `kernel.secret`, so a copy
     * of the tenant database is not enough to mint a link. This asserts the
     * observable half of that — the thing in the mail is nowhere in the row.
     */
    public function testWhatIsStoredIsNotWhatWasSent(): void
    {
        $link = $this->invite('stored@invites.test');

        $user = $this->find('stored@invites.test');
        $seed = $user->getInvitationSeed();

        self::assertNotNull($seed);
        self::assertStringNotContainsString($seed, $link, 'the stored value is in the link');
        self::assertSame('', $user->getPassword(), 'an invited account holds no password hash either');
    }

    /** Inviting is behind the same authority that creates a user (§8.4). */
    public function testAnOrdinaryUserCannotInviteAnybody(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('POST', $this->url('/users/' . $this->find(self::MEMBER)->getId() . '/invite'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertEmailCount(0);
    }

    // -- what invitations are not for ---------------------------------------

    /**
     * An invitation is not a way past a password somebody chose.
     *
     * It signs its holder in without one, so offering it for an established
     * account would make "invite" a quieter version of "reset password" that the
     * account owner never sees happen.
     */
    public function testSomebodyWithAPasswordCannotBeInvited(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('POST', $this->url('/users/' . $this->find(self::MEMBER)->getId() . '/invite'), [
            '_token' => $this->token(),
        ]);

        self::assertEmailCount(0);
        self::assertStringContainsString(
            'already has a password',
            $this->client->followRedirect()->filter('main')->text(),
        );
    }

    /** Nor a promise the sign-in page then breaks. */
    public function testADeactivatedUserCannotBeSentAnInvitationAtAll(): void
    {
        $this->invite('frozen@invites.test');
        $user = $this->find('frozen@invites.test');

        $this->setActive($user, false);

        $this->client->request('POST', $this->url('/users/' . $user->getId() . '/invite'), [
            '_token' => $this->token(),
        ]);

        self::assertEmailCount(0, message: 'an invitation was sent to somebody who cannot sign in');
        self::assertStringContainsString(
            'deactivated',
            $this->client->followRedirect()->filter('main')->text(),
        );
    }

    /**
     * The initial-password path is not a way around the current-password
     * question either.
     *
     * The controller picks between the two from the account's own state, and the
     * manager checks the same fact again — this is the second check, asked
     * directly, because it is the one a future caller will run into.
     */
    public function testTheInitialPasswordPathRefusesAnAccountThatHasOne(): void
    {
        $this->expectExceptionMessage('already has a password');

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $member = self::service(UserRepository::class)->findOneByEmail(self::MEMBER);
            \assert($member instanceof User);

            self::service(UserManager::class)->setInitialPassword($member, 'does-not-matter');
        });
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Invites somebody through the screen, and returns the link that was mailed.
     *
     * Leaves the browser on the redirect response rather than following it, so a
     * caller can read the flash message if it wants to.
     */
    private function invite(string $email): string
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/users/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'email' => $email,
            'name' => 'Newcomer',
            'method' => 'invite',
        ]));

        return self::linkInTheLastMessage();
    }

    /**
     * The invitation URL out of the message that was just collected.
     *
     * Read from the *text* part rather than the HTML one, because that part is a
     * bare URL on a line of its own — extracting it out of an anchor would be
     * asserting on markup rather than on the link.
     */
    private static function linkInTheLastMessage(): string
    {
        $message = self::getMailerMessage();

        self::assertInstanceOf(Email::class, $message, 'no invitation was sent');
        self::assertSame(1, preg_match('#https://\S+/invitation\?\S+#', (string) $message->getTextBody(), $matches));

        return rtrim($matches[0], '.');
    }

    /**
     * A refused link says what happened and offers a way forward, rather than
     * answering a blank 403 to somebody who has no account here yet.
     *
     * @param string $because the sentence naming the cause. It is a parameter
     *                        because the causes really do differ: a stale link is
     *                        Symfony's own "invalid or expired" plus our line
     *                        about asking for a new one, while a deactivated
     *                        account is refused by ActiveUserChecker and told so
     *                        — where suggesting a fresh invitation would be
     *                        sending them back to somebody who cannot help yet
     *
     * @return string what the sign-in page said, so a caller can check the rest
     *                of it — this navigates on afterwards, so reading the crawler
     *                back out is reading the wrong page
     */
    private function assertTurnedAwayWithAnExplanation(string $because): string
    {
        self::assertResponseRedirects();
        $text = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('login', (string) $this->client->getRequest()->getUri());
        self::assertStringContainsString($because, $text);

        // And no session came of it.
        $this->client->request('GET', $this->url('/'));
        self::assertResponseRedirects();

        return $text;
    }

    /**
     * The `main` firewall's handler by name, for the same reason
     * `UserInvitations` injects it that way rather than autowiring the interface:
     * the autowired alias is firewall-aware and works the firewall out from the
     * current request, which a test standing between two browser calls has not
     * got.
     */
    private function loginLinks(): LoginLinkHandlerInterface
    {
        $handler = self::getContainer()->get('security.authenticator.login_link_handler.main');
        \assert($handler instanceof LoginLinkHandlerInterface);

        return $handler;
    }

    /** Through the screen, so the CSRF token and the refusals are the real ones. */
    private function setActive(User $user, bool $active): void
    {
        $this->client->request('POST', $this->url('/users/' . $user->getId() . '/active'), [
            '_token' => $this->token(),
            'active' => $active ? '1' : '0',
        ]);

        $this->client->followRedirect();
    }

    /** Rendered by the page, so the test posts the token a browser would have. */
    private function token(): string
    {
        $crawler = $this->client->request('GET', $this->url('/users'));

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function find(string $email): User
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): User => self::service(UserRepository::class)->findOneByEmail($email)
                ?? self::fail(sprintf('No user %s', $email)),
        );
    }

    private function signIn(string $email, string $password = self::PASSWORD): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
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

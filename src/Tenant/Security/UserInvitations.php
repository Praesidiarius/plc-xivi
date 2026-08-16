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

namespace App\Tenant\Security;

use App\Tenant\Entity\User;
use App\Tenant\Mail\MailSendFailed;
use App\Tenant\Mail\TenantMailer;
use App\Tenant\Settings\InstanceName;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Inviting somebody by email instead of reading them a password (XIV-1).
 *
 * ### The link is Symfony's, not ours
 *
 * `security-http` already ships the thing this ticket describes: a signed URL
 * carrying a user identifier and an expiry, verified by HMAC over `kernel.secret`
 * and a chosen set of the user's own properties, which authenticates whoever
 * presents it. Writing an `invitation` table with a hashed token, an expiry
 * column and a controller that compares digests would have been re-implementing
 * `SignatureHasher` — including the parts that are easy to get subtly wrong, like
 * comparing in constant time and checking the expiry before touching the
 * database. It is also strictly worse in one respect: a token table *stores*
 * something replayable, and a signature stores nothing at all.
 *
 * What is left over, after taking the framework's version, is small and it is
 * the honest departure to declare (§ "Symfony way first"):
 *
 * - **An invitee has no password**, so a login link is not sufficient by itself.
 *   It gets them in; `User::$mustChangePassword` and `MustChangePasswordListener`
 *   then hold them at `/account` until they have chosen one. Both of those
 *   already existed for generated passwords (§8.5) and neither needed changing —
 *   the invite composes out of parts that were already here, which is most of the
 *   argument for this shape.
 * - **A stateless link cannot be revoked**, and an invitation has to be revoked
 *   twice: when it is used, and when a second one supersedes it. That is what
 *   `User::$invitationSeed` is for. It is a signature property, so rotating it
 *   invalidates every link already sent, and rotating it is one `UPDATE`. The
 *   framework's own answer to single-use is `max_uses` with a *cache pool*, which
 *   was considered and rejected: a cache is evictable, and an eviction would
 *   quietly restore a consumed invitation. A security property that un-enforces
 *   itself under memory pressure is not one.
 *
 * ### The message is a system message, and lives in code
 *
 * Not an XIV-38 email template. Those are per-module, customer-facing and
 * tenant-editable, and every one of those three is wrong here: this message
 * belongs to no module, its recipient is a colleague rather than a customer, and
 * a tenant who edited the link out of it would lock somebody out of an account
 * they cannot otherwise reach. It also has to work for a tenant that has
 * installed nothing and written nothing — which is precisely XIV-64's first user,
 * where this stops being a nicety.
 *
 * So the words are in the ordinary message catalogue and the frame is
 * `@XiviCore/email/base.html.twig`, the same skeleton every other email from this
 * application is drawn in (§5.13). It is rendered in the *sender's* language: the
 * invitee has no locale yet — they have no account they have ever opened — and
 * the administrator typing their address is the person who knows which language
 * to write to them in.
 *
 * ### Who it is from is not decided here
 *
 * It goes through `TenantMailer` like everything else, which means a customer
 * with their own SMTP server sends it from their own address and a customer
 * without one sends it through this instance (§8.7, and §8.8 for why an invite
 * does not get an exception to that rule).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class UserInvitations
{
    /** The frame every email this application sends is drawn in (§5.13). */
    private const string FRAME = '@XiviCore/email/base.html.twig';

    public function __construct(
        private UserManager $users,
        /*
         * The `main` firewall's handler by name, rather than the autowired
         * `LoginLinkHandlerInterface`.
         *
         * That alias is `FirewallAwareLoginLinkHandler`, which works out which
         * firewall to use *from the current request* and throws outright when
         * there is not one — "there is no active Request and so, the firewall
         * cannot be determined". Every invitation sent from a screen has a
         * request, so the alias would work today and stop working the first time
         * something sends one from a console command. XIV-64 is exactly that:
         * self-service signup provisions a tenant on a cron and has to invite its
         * first user with nobody's browser involved.
         *
         * Naming the firewall is not a loss of generality either — there is one,
         * and if it is ever renamed this fails at compile time with the service id
         * in the message rather than at runtime with a puzzle. Same reasoning
         * TenantMailer applies to `mailer.transport_factory`.
         */
        #[Autowire(service: 'security.authenticator.login_link_handler.main')]
        private LoginLinkHandlerInterface $loginLinks,
        private TenantMailer $mailer,
        private InstanceName $instanceName,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Sends one, and kills whatever was sent before it.
     *
     * The order matters and is not incidental. The seed is rotated *and flushed*
     * first, then the link is built from what is now in the database: build the
     * link first and a failure to store the seed would leave a live link signed
     * against a value the row does not hold, which is a link that either never
     * works or — worse — one whose predecessor still does.
     *
     * So the answer to "what happens when an administrator invites somebody
     * twice" is: the first link stops working the instant the second is created,
     * and the 24 hours start again. There is never more than one live invitation
     * per person. The alternative — letting both run — means a link an
     * administrator believed they had replaced is still in a mailbox somewhere,
     * and "I sent a new one" would not be a way to fix a leaked invitation.
     *
     * @return \DateTimeImmutable when the link stops working, so the caller can say
     *
     * @throws UserChangeRefused when this person should not be sent one at all
     * @throws MailSendFailed    when it could not be handed to a mail server; the
     *                           account and its pending invitation both survive,
     *                           so sending it again is the fix
     */
    public function send(User $user): \DateTimeImmutable
    {
        if ($user->hasPassword()) {
            throw UserChangeRefused::alreadyHasPassword($user->getEmail());
        }

        if (!$user->isActive()) {
            throw UserChangeRefused::inactiveInvitee($user->getEmail());
        }

        $this->users->rotateInvitationSeed($user);

        $link = $this->loginLinks->createLoginLink($user);
        $instance = $this->instanceName->current();
        $hours = self::hoursUntil($link->getExpiresAt());

        $context = [
            'name' => $user->getName(),
            'instance' => $instance,
            'url' => $link->getUrl(),
            'hours' => $hours,
        ];

        $subject = $this->translator->trans('invitation.subject', ['%instance%' => $instance]);

        $this->mailer->send(
            new Email()
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject($subject)
                // Both parts, for the reason RenderedEmail gives: a message with
                // only an HTML body is one a text-only client shows as nothing,
                // and the one thing this mail has to deliver is a URL.
                ->text($this->twig->render('mail/invitation.txt.twig', $context))
                ->html($this->twig->render(self::FRAME, [
                    'subject' => $subject,
                    'content' => $this->twig->render('mail/invitation.html.twig', $context),
                    // The footer names the company sending this, exactly as it
                    // does for a template-driven email (§5.13).
                    'general' => ['tenant.name' => $instance],
                ])),
        );

        return $link->getExpiresAt();
    }

    /**
     * Whether an invitation is what this person is waiting for.
     *
     * Derived rather than stored, and from the credential itself: somebody with
     * no password has never signed in on one and has nothing else to sign in
     * with. A `pending` column beside it would be a second version of the same
     * fact, free to disagree with the first.
     */
    public static function isPending(User $user): bool
    {
        return !$user->hasPassword();
    }

    /**
     * How long is left, in hours.
     *
     * Rounded, because both sentences it goes into say "24 hours" rather than a
     * timestamp — a moment printed in a mail is a moment in some timezone, and
     * neither the recipient's nor the reader's is known here. Public because the
     * screen that reports a sent invitation says the same number the mail does,
     * and two ways of computing it are one way for them to disagree.
     */
    public static function hoursUntil(\DateTimeImmutable $moment): int
    {
        return max(1, (int) round(($moment->getTimestamp() - time()) / 3600));
    }
}

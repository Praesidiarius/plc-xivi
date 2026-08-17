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

namespace Xivi\ControlPlane\Signup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Xivi\ControlPlane\Entity\SignupRequest;

/**
 * The one mail this feature sends, and the only one in the system that comes
 * from the instance rather than from a customer (XIV-64).
 *
 * ### Why `TenantMailer` is not used, and could not be
 *
 * §8.7 put every outbound message through `App\Tenant\Mail\TenantMailer`, and
 * §8.8 refused to carve an exception for the invitation with a good argument:
 * one place decides who a message is from, and a second rule is a second thing
 * to disagree with the first.
 *
 * This is not an exception to that rule; it is a message the rule cannot be
 * applied to. `TenantMailer` asks the current tenant's profile whether they have
 * their own SMTP server, and falls back to `no-reply@` at *their* primary domain
 * when `MAILER_SENDER` is empty. **A signup has no tenant.** There is no profile
 * to ask and no SMTP credential to find — the entire point of the feature is that
 * the customer does not exist yet. So this goes out
 * through the instance's own transport, under the instance's own address, and
 * §8.7's decision procedure is untouched because it was never applicable.
 *
 * That also makes this the *only* correct answer rather than a preference: a
 * confirmation is a message from the platform to somebody who is not yet
 * anybody's customer, about an arrangement with the platform.
 *
 * ### An empty `MAILER_SENDER` falls back the way §8.7 does, one noun along
 *
 * That section allows the variable to be empty and sends from `no-reply@` at the
 * *tenant's own primary domain* instead — which is honest rather than a guess,
 * because that hostname **is** this installation as far as that customer is
 * concerned. The identical argument applies here with the tenant replaced by the
 * signup host: `SIGNUP_HOST` is the name this prospective customer's site just
 * posted to and the name the confirmation link points at, so it is this
 * installation as far as they are concerned.
 *
 * The alternative — requiring `MAILER_SENDER` whenever signup is switched on —
 * was written first and then withdrawn. It made a deployment step out of
 * something that has a truthful default, and it would have made "switch signup
 * on" quietly change the `From` of every *tenant's* mail as well, since the two
 * would then be the same variable set for one of them.
 *
 * ### The link is built from configuration, not from the request
 *
 * The confirmation URL is `https://` plus `app.signup_host` plus the generated
 * path, and the host is deliberately *not* taken from the router's request
 * context. The context's host comes from the `Host` header of the request being
 * served, which on this endpoint arrives from a caller rather than from a
 * browser — and a URL that goes in a mail must not be something an attacker can
 * influence by sending a header. §8.8 flagged the same hazard from the other
 * end, where a link generated off a cron has no hostname at all.
 *
 * The scheme is fixed at `https` for the same reason and one more: the link is
 * how somebody proves control of a mailbox, so it is not something to serve over
 * a plaintext connection because a proxy forgot a header.
 *
 * ### Language
 *
 * Rendered in the language the *visitor* was reading the form in, which the
 * calling site forwards as `locale`. There is nowhere else to get it: this
 * person has no account on this installation, so there is no stored preference,
 * and the `Accept-Language` of a server-to-server POST belongs to the calling
 * server. `LocaleSwitcher` rather than passing a locale through every `trans()`
 * call, so the Twig templates need no special handling and cannot forget.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupMailer
{
    /** The frame every email this application sends is drawn in (§5.13). */
    private const string FRAME = '@XiviCore/email/base.html.twig';

    /** The local part of the address invented when nothing has been configured, as §8.7 invents one. */
    private const string DEFAULT_LOCAL_PART = 'no-reply';

    public function __construct(
        /**
         * The instance's own transport — what `MAILER_DSN` resolved to.
         *
         * `NonProductionMailGuard` still stands in front of it, so dev and test
         * cannot put this on the wire any more than they can a tenant's mail.
         */
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LocaleSwitcher $locales,
        private UrlGeneratorInterface $urls,
        /**
         * Where signup is served.
         *
         * The object rather than `%app.signup_host%` directly, so that the host
         * in a mailed link is **normalised through the same function the route
         * carries** ({@see SignupHost::normalisedHost()}, and through it
         * `TenantResolver::normalize()`). A deployment that wrote
         * `Signup.Example.COM` would otherwise get routes on `signup.example.com`
         * and links pointing at `Signup.Example.COM` — which most of the time is
         * the same place and is exactly the sort of "most of the time" that turns
         * into a confirmation link nobody can follow.
         */
        private SignupHost $host,
        /** @see senderAddress() for what an empty one falls back to, and why that is not a guess */
        #[Autowire('%env(MAILER_SENDER)%')]
        private string $senderAddress = '',
    ) {
    }

    /**
     * @throws SignupMailFailed
     */
    public function sendConfirmation(SignupRequest $signup, ConfirmationToken $token): void
    {
        $from = $this->senderAddress();
        $url = $this->confirmationUrl($token);

        $this->locales->runWithLocale($signup->getLocale(), function () use ($signup, $url, $from): void {
            $subject = $this->translator->trans('signup.mail.subject');
            $context = [
                'company' => $signup->getCompanyName(),
                'slug' => $signup->getSlug(),
                'url' => $url,
                'hours' => self::hoursUntil($signup->getConfirmationExpiresAt()),
            ];

            try {
                $this->mailer->send(
                    new Email()
                        ->from(new Address($from))
                        ->to(new Address($signup->getEmail()))
                        ->subject($subject)
                        // Both parts, for the reason the invitation gives: a
                        // message with only an HTML body is one a text-only
                        // client shows as nothing, and the one thing this mail
                        // has to deliver is a URL.
                        ->text($this->twig->render('@XiviControlPlane/mail/signup_confirmation.txt.twig', $context))
                        ->html($this->twig->render(self::FRAME, [
                            'subject' => $subject,
                            'content' => $this->twig->render(
                                '@XiviControlPlane/mail/signup_confirmation.html.twig',
                                $context,
                            ),
                            // The footer names who is writing. Not a customer's
                            // company here — there is not one yet — so it is the
                            // hostname this signup was made against, which is
                            // the only thing about this installation the reader
                            // has met.
                            'general' => ['tenant.name' => $this->host->normalisedHost()],
                        ])),
                );
            } catch (\Throwable $failure) {
                throw SignupMailFailed::because($failure);
            }
        });
    }

    /**
     * What a confirmation comes from.
     *
     * `MAILER_SENDER` where a deployment has set one, and `no-reply@` at the
     * signup host otherwise — see the class docblock for why that fallback is
     * the truth rather than a guess. The host cannot be empty here: an empty one
     * means no signup route was ever registered, so nothing can have reached
     * this method.
     *
     * @throws SignupMailFailed when there is no host to fall back to either
     */
    public function senderAddress(): string
    {
        if ($this->senderAddress !== '') {
            return $this->senderAddress;
        }

        $host = $this->host->normalisedHost();

        if ($host === '') {
            throw SignupMailFailed::noSenderAddress();
        }

        return self::DEFAULT_LOCAL_PART . '@' . $host;
    }

    /** Absolute, and absolute against configuration rather than against a header. */
    public function confirmationUrl(ConfirmationToken $token): string
    {
        return sprintf(
            'https://%s%s',
            $this->host->normalisedHost(),
            $this->urls->generate('signup_confirm', ['token' => $token->plaintext]),
        );
    }

    /**
     * How long is left, in hours.
     *
     * Rounded, and printed as a number of hours rather than as a moment, for the
     * reason `UserInvitations::hoursUntil()` gives: a timestamp in a mail is a
     * timestamp in some timezone, and neither the recipient's nor the sender's is
     * known here. It is not shared with that method because sharing it would
     * mean the control plane depending on a tenant security service to format a
     * number.
     */
    private static function hoursUntil(\DateTimeImmutable $moment): int
    {
        return max(1, (int) round(($moment->getTimestamp() - time()) / 3600));
    }
}

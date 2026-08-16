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

namespace App\Tenant\Mail;

use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\TenantProfile;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Settings\InstanceName;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The one way a message leaves this application on a customer's behalf (XIV-37).
 *
 * Two questions, and they are the same question asked twice — *whose* mail is
 * this, and *whose server* does it leave through — so they are answered here
 * together rather than by a caller who would eventually answer one and forget
 * the other.
 *
 * **The sender identity follows the transport, and that is the deliverability
 * trade in one sentence.** A customer who has given us their own SMTP server has
 * a server entitled to claim their address: SPF, DKIM and the reputation that
 * comes with them are theirs, which is the correct place for all three, so the
 * `From` is their address and there is nothing to explain. A customer who has
 * not is sending through this instance, whose domain is emphatically *not*
 * allowed to claim theirs — a `From` of `billing@their-company.example` sent from
 * our IP is the definition of the mail SPF exists to fail. So the `From` is this
 * instance's address carrying *their name*, and their address becomes the
 * `Reply-To`, so an answer still reaches them. Honest, works on day one, and
 * upgrades to genuinely-from-them the moment they fill in a server. §8.7 is the
 * long version.
 *
 * **The `From` is not the caller's to choose.** Whatever a caller set is
 * overwritten here. XIV-39 sends invoices on a customer's behalf and must not be
 * able to send one on somebody else's, and a rule enforced at the only place
 * that can send is a rule that cannot be forgotten at a call site.
 *
 * **Synchronous, deliberately.** Messenger with an async transport wants a
 * consumer process, and this runtime is FrankenPHP in classic mode with no
 * worker (§9.2) precisely so that nothing runs between requests. A queue with
 * nothing draining it is worse than a slow request: the mail simply never goes.
 * So a slow SMTP server is a slow request, and that is the accepted cost until
 * there is a reason to run a process — one that is about more than mail.
 *
 * **Nothing here is caught and dropped.** Every failure becomes MailSendFailed
 * and is thrown on; see that class for why an email is different from a document
 * in that respect.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantMailer
{
    /** The local part of the address invented when nothing else has been configured. */
    private const string DEFAULT_LOCAL_PART = 'no-reply';

    /** The port that has meant implicit TLS for twenty years; everything else does STARTTLS. */
    private const int IMPLICIT_TLS_PORT = 465;

    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
        private InstanceName $instanceName,
        private TenantSecretCipher $cipher,
        /*
         * The same object the framework builds `mailer.transports` with, so a
         * per-tenant DSN meets exactly the factories — NonProductionMailGuard
         * included — that `MAILER_DSN` meets. Injecting it by service id because
         * `Transport` is a final class the container registers under a name of
         * its own rather than an aliased interface.
         */
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transportFactory,
        /** What `MAILER_DSN` resolved to: this instance's own way out, and the fallback. */
        private TransportInterface $instanceTransport,
        /*
         * The address this instance sends as when a customer has configured
         * none. Empty is allowed and falls back to the tenant's own primary
         * domain, which *is* this instance's domain for that customer — so a
         * deployment that never sets this still sends from an address that
         * belongs to it, rather than refusing on day one.
         */
        #[Autowire('%env(MAILER_SENDER)%')]
        private string $instanceSenderAddress = '',
    ) {
    }

    /**
     * Who this tenant's mail will appear to come from.
     *
     * Public because a preview that does not say so is not a preview of the
     * message being sent (XIV-39).
     *
     * @throws MailSendFailed when no address can be worked out at all
     */
    public function senderIdentity(): SenderIdentity
    {
        $profile = $this->profiles->current();
        $name = $this->instanceName->current();
        $configured = $profile->getMailSenderAddress();

        if ($profile->hasOwnMailTransport() && $configured !== '') {
            // Their server, their address, their reputation. Nothing to add.
            return new SenderIdentity($this->address($configured, $name), null, true);
        }

        $from = $this->address($this->instanceSenderAddress(), $name);

        return new SenderIdentity(
            $from,
            // Only when it is somewhere else: a Reply-To repeating the From is
            // noise in every mail client that shows it.
            $configured !== '' && $configured !== $from->getAddress()
                ? $this->address($configured, $name)
                : null,
            false,
        );
    }

    /**
     * Sends now, and says so if it did not.
     *
     * @throws MailSendFailed
     */
    public function send(Email $email): void
    {
        $profile = $this->profiles->current();
        $identity = $this->senderIdentity();

        $email->from($identity->from);

        if ($identity->replyTo !== null) {
            $email->replyTo($identity->replyTo);
        }

        try {
            // The transport is built per send rather than kept. Under classic
            // PHP (§9.2) the container is thrown away with the request anyway,
            // so a cache would live exactly as long as the one send it served —
            // and building it late is what keeps a settings change effective on
            // the very next message rather than the next request.
            $this->transportFor($profile)->send($email);
        } catch (\Throwable $failure) {
            throw MailSendFailed::because($failure);
        }
    }

    /** The customer's own server where they have named one, this instance's otherwise. */
    private function transportFor(TenantProfile $profile): TransportInterface
    {
        if (!$profile->hasOwnMailTransport()) {
            return $this->instanceTransport;
        }

        return $this->transportFactory->fromString($this->smtpDsn($profile));
    }

    /**
     * The customer's SMTP settings as a DSN.
     *
     * A DSN rather than an EsmtpTransport built by hand, and that is the load-
     * bearing choice rather than a convenience: it is what puts a tenant's
     * credentials through the same tagged factories `MAILER_DSN` goes through,
     * so NonProductionMailGuard sees this too. Construct the transport directly
     * and dev and test could mail a real customer through a fixture, which is
     * the guarantee XIV-37 exists to make.
     */
    private function smtpDsn(TenantProfile $profile): string
    {
        $credentials = '';

        if (($user = $profile->getMailSmtpUser()) !== '') {
            $credentials = rawurlencode($user);
            $ciphertext = $profile->getEncryptedMailSmtpPassword();

            if ($ciphertext !== null && $ciphertext !== '') {
                $credentials .= ':' . rawurlencode($this->cipher->decrypt($ciphertext));
            }

            $credentials .= '@';
        }

        $port = $profile->getMailSmtpPort();

        return sprintf(
            '%s://%s%s%s',
            $port === self::IMPLICIT_TLS_PORT ? 'smtps' : 'smtp',
            $credentials,
            $profile->getMailSmtpHost(),
            $port === null ? '' : ':' . $port,
        );
    }

    /**
     * What this instance sends as: its configured address, or one at the domain
     * this customer reaches it by.
     *
     * The fallback is not a guess. A tenant's primary domain is the hostname
     * this installation serves them on (§4), so it is literally this instance's
     * domain for them — which is exactly what the honest version of "sent from
     * our infrastructure" should say.
     */
    private function instanceSenderAddress(): string
    {
        if ($this->instanceSenderAddress !== '') {
            return $this->instanceSenderAddress;
        }

        $tenant = $this->context->getTenant();
        $domain = $tenant->getPrimaryDomain();

        if ($domain === null) {
            throw MailSendFailed::noSenderAddress($tenant->getSlug());
        }

        return self::DEFAULT_LOCAL_PART . '@' . $domain->getHostname();
    }

    /**
     * @throws MailSendFailed when what was stored is not an address at all
     */
    private function address(string $address, string $name): Address
    {
        try {
            return new Address($address, $name);
        } catch (\InvalidArgumentException $malformed) {
            throw MailSendFailed::because($malformed);
        }
    }
}

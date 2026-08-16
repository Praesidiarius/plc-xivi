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

namespace App\Tenant\Entity;

use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What this customer says about themselves: their company name, and the currency
 * their instance works in (XIV-12).
 *
 * In the customer's own database, next to their users and their definitions
 * (§8.1). It is their data, edited by them, and the control plane's `tenant.name`
 * is a different fact — the operator's label in the registry, which they cannot
 * see and would not want to be renaming.
 *
 * **One row, enforced by the primary key.** The id is a constant rather than a
 * sequence, so a second profile is a duplicate key rather than a thing to notice
 * later. Settings tables that allow two rows eventually have two.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: TenantProfileRepository::class)]
#[ORM\Table(name: 'tenant_profile')]
#[ORM\HasLifecycleCallbacks]
class TenantProfile
{
    /** @see the class docblock — the row is a singleton by primary key. */
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    /**
     * Empty until somebody fills it in, rather than null.
     *
     * "Not set" is one state here, not two, and every reader of this asks the
     * same question — is there something to show — which `!== ''` answers without
     * a null check first.
     */
    #[ORM\Column(name: 'company_name', length: 255, options: ['default' => ''])]
    private string $companyName = '';

    /**
     * ISO 4217, e.g. `CHF`. Null means nobody has chosen.
     *
     * Null rather than a default, because a currency guessed for a customer is
     * wrong quietly: it would come out on the first priced thing they ever
     * printed. What is stored is the code, never a symbol or a formatted string —
     * symfony/intl turns it into either, in whatever language is being read.
     */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    /**
     * Which country's conventions this installation writes in (XIV-50).
     *
     * An ISO 3166-1 alpha-2 code, and **not the same question as the language**.
     * Swiss German and German German are one catalogue and two ways of writing a
     * number: `1’234’500.00` against `1.234.500,00`, differing in the decimal
     * separator as well as the grouping one.
     *
     * Null means the application's own default. A company's people are mostly in
     * one country, so this is the sensible place for the answer, and a person who
     * is somewhere else says so on their account.
     */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $region = null;

    /**
     * The address this customer's mail claims to come from (XIV-37).
     *
     * Empty until somebody fills it in, like the company name above and for the
     * same reason. What it is *used* for depends on whether the SMTP fields below
     * are set, and that is the whole deliverability trade — see TenantMailer and
     * §8.7. Briefly: with their own SMTP it is the `From`, because their server is
     * entitled to claim it; without one it is the `Reply-To`, because this
     * instance is not.
     */
    #[ORM\Column(name: 'mail_sender_address', length: 255, options: ['default' => ''])]
    private string $mailSenderAddress = '';

    /**
     * The customer's own SMTP server, empty when they have not named one.
     *
     * Empty is the ordinary state and not a broken one: mail then leaves through
     * the instance's own transport, which works on day one and is honest about
     * whose domain it came from.
     */
    #[ORM\Column(name: 'mail_smtp_host', length: 255, options: ['default' => ''])]
    private string $mailSmtpHost = '';

    /**
     * Null means the scheme's default, which is 25 for SMTP and 465 for SMTPS.
     *
     * **465 is also what selects implicit TLS**, rather than a checkbox of its
     * own: it is the port that has meant exactly that everywhere for twenty
     * years, and every other port negotiates STARTTLS, which Symfony's transport
     * does by itself when the server offers it. One fact instead of two that can
     * disagree.
     */
    #[ORM\Column(name: 'mail_smtp_port', nullable: true)]
    private ?int $mailSmtpPort = null;

    #[ORM\Column(name: 'mail_smtp_user', length: 255, options: ['default' => ''])]
    private string $mailSmtpUser = '';

    /**
     * The SMTP password, encrypted with TenantSecretCipher — never the plaintext.
     *
     * The same mechanism the control plane stores tenant *database* passwords
     * with, deliberately rather than a second one: the stored value names the key
     * it was written with, so several keys are valid at once and
     * `tenant:rotate-secrets` is a resumable job rather than an all-or-nothing
     * rewrite. TenantSecretRotator walks this column in every tenant's database
     * for exactly that reason.
     *
     * Null means no password, which is a real answer — a relay on a private
     * network may want none.
     */
    #[ORM\Column(name: 'mail_smtp_password', type: 'text', nullable: true)]
    private ?string $encryptedMailSmtpPassword = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): void
    {
        $this->companyName = mb_substr(trim($companyName), 0, 255);
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    /** @param string|null $region an ISO 3166-1 alpha-2 code, or null to follow the default */
    public function setRegion(?string $region): void
    {
        $region = strtoupper(trim((string) $region));

        $this->region = $region === '' ? null : $region;
    }

    /** @param string|null $currency an ISO 4217 code; the caller checks it is one */
    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getMailSenderAddress(): string
    {
        return $this->mailSenderAddress;
    }

    public function setMailSenderAddress(string $address): void
    {
        $this->mailSenderAddress = mb_substr(trim($address), 0, 255);
    }

    public function getMailSmtpHost(): string
    {
        return $this->mailSmtpHost;
    }

    public function setMailSmtpHost(string $host): void
    {
        $this->mailSmtpHost = mb_substr(trim($host), 0, 255);
    }

    public function getMailSmtpPort(): ?int
    {
        return $this->mailSmtpPort;
    }

    public function setMailSmtpPort(?int $port): void
    {
        // Outside the range a TCP port can take is not a port, and storing it
        // would produce a DSN nothing can parse later. Null is the honest answer
        // to nonsense here, because null already means "the scheme's default".
        $this->mailSmtpPort = $port !== null && $port >= 1 && $port <= 65535 ? $port : null;
    }

    public function getMailSmtpUser(): string
    {
        return $this->mailSmtpUser;
    }

    public function setMailSmtpUser(string $user): void
    {
        $this->mailSmtpUser = mb_substr(trim($user), 0, 255);
    }

    public function getEncryptedMailSmtpPassword(): ?string
    {
        return $this->encryptedMailSmtpPassword;
    }

    /** @param string|null $ciphertext already encrypted; plaintext passwords never reach this entity */
    public function setEncryptedMailSmtpPassword(?string $ciphertext): void
    {
        $this->encryptedMailSmtpPassword = $ciphertext;
    }

    /** Whether this customer's mail leaves through their own provider (§8.7). */
    public function hasOwnMailTransport(): bool
    {
        return $this->mailSmtpHost !== '';
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

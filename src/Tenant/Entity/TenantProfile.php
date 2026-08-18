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
     * Which zone this installation reads moments in (XIV-83), as an IANA
     * identifier.
     *
     * **Null is the answer most customers will keep**, and the reason is the
     * region above: where a country has exactly one zone the answer is already
     * on file, and asking for it a second time would be asking somebody to
     * repeat themselves. Switzerland is `Europe/Zurich`; there is nothing to
     * choose. This column exists for the countries where that is not true —
     * Spain, the United States, anywhere with an archipelago — and for the
     * company whose people sit somewhere other than where it is registered.
     *
     * Derivation is deliberately *not* "the first zone the country lists".
     * Spain's list begins `Africa/Ceuta` and America's begins `America/Adak`, so
     * taking the head would quietly file a Madrid office in North Africa and a
     * New York one in the Aleutians — wrong in a way nothing on screen reveals,
     * because a timestamp in the wrong zone still looks like a timestamp. Where
     * the country is ambiguous this stays null and the fallback is UTC, which is
     * at least visibly not local. See `DisplayTimezone`, which is where the rule
     * lives.
     *
     * A person may still override it on their own account — the same
     * relationship the region and the language above have with `User`.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    /**
     * The dashboard anybody who has never chosen one gets (XIV-66).
     *
     * The middle link of the chain the region and the zone above already walk,
     * with the same promise attached: this is what somebody's page looks like
     * until they say otherwise, and a person who leaves their own column empty
     * keeps following this if it changes. What it is emphatically *not* is a
     * restriction — a widget left out of here is still on offer in every
     * person's picker, because deciding what a colleague may look at is a
     * permission question and §8.4 already answers it, per module, in a place
     * where the answer applies to the records as well as to the tile.
     *
     * Null means the code's own order: every widget that applies to the reader,
     * arranged by the priority its tag declares. That is what every installation
     * had before this column existed, which is the property the bottom of one of
     * these chains always has (§8.4.2).
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'dashboard_layout', type: 'json', nullable: true)]
    private ?array $dashboardLayout = null;

    /**
     * How long this installation gives a customer to pay, in whole days
     * (XIV-67).
     *
     * The bottom of three layers: a contact may override it, and an invoice
     * materialises the resulting date once, as it is sent. What is stored here is
     * a *term* and never a date — the date is a fact about one document and lives
     * on that document, which is the whole argument of the ticket.
     *
     * **Null rather than thirty**, for exactly the reason the currency above is
     * null rather than francs: a term guessed for a customer is wrong quietly, and
     * it would surface as a deadline printed on a bill that nobody here ever
     * agreed to give. An installation that has never answered this question puts
     * no due date on anything, and a document with no due date is not overdue —
     * which is the safe direction to be wrong in. Zero is a different answer and a
     * real one: payable on receipt.
     */
    #[ORM\Column(name: 'payment_terms_days', nullable: true)]
    private ?int $paymentTermsDays = null;

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

    /**
     * The customer's own mark, as bytes (XIV-49).
     *
     * **In this database, in a bytea column, exactly as a document template is**
     * (§5.7). The argument transfers without a change: there is one of these, it
     * is small, and it is unmistakably one customer's — so the isolation §4
     * already provides is free here, where a shared volume would mean a path to
     * get wrong and a backup story to invent. It is as deliberately *not* the
     * general file-storage design as the templates were; attachments are many,
     * large and long-lived, and will want a different answer.
     *
     * A resource on the way back out of Doctrine, which is why nothing but
     * {@see self::getLogo()} touches this property — the same handling
     * DocumentTemplate::getContent() does, and for the same reason.
     *
     * **This row is read on nearly every page** (InstanceName, and therefore the
     * bar at the top of everything), so the bytes are read with it whether or not
     * anybody is going to draw them. That is the one real cost of keeping them
     * here rather than in a table of their own, and it is what the ceiling in
     * {@see \App\Tenant\Settings\LogoFormat} is sized against: half a megabyte of
     * extra row on a local connection is a millisecond, and the overwhelming
     * majority of installations store null and pay nothing at all. If a second
     * blob ever lands on this row, that arithmetic stops holding and the bytes
     * should move to a table of their own — the fingerprint below is already the
     * only thing the hot path actually wants.
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $logo = null;

    /**
     * What the bytes above actually are, decided by decoding them rather than by
     * believing the upload (XIV-49).
     *
     * Stored rather than sniffed on the way out: the serving route has to name a
     * type in a header, and re-deciding it on every request would be asking the
     * same question of the same bytes forever. `image/png` or `image/jpeg`, and
     * nothing else is accepted — see LogoFormat for why SVG is not on that list.
     */
    #[ORM\Column(name: 'logo_content_type', length: 64, nullable: true)]
    private ?string $logoContentType = null;

    /**
     * A hash of the bytes, and the whole of the cache story (XIV-49).
     *
     * The mark is on every page including the sign-in one, so it wants to be
     * cached hard; a cache that outlives a replacement means a customer uploads a
     * new logo, sees the old one, and reasonably concludes the upload failed.
     * Both are had at once by putting this in the URL: a different logo is a
     * different address, so the old address is never asked for again and the
     * bytes behind it may be declared immutable.
     *
     * Stored rather than computed on read, for the reason the property above is
     * stored: every page render needs this to build the URL, and hashing half a
     * megabyte on each of them to arrive at a value that cannot have changed is
     * work with no question behind it.
     */
    #[ORM\Column(name: 'logo_fingerprint', length: 64, nullable: true)]
    private ?string $logoFingerprint = null;

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

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * @param string|null $timezone an IANA identifier, or null to let the region
     *                              decide — see the property. Not upper-cased on
     *                              the way in, unlike the country code above:
     *                              `Europe/Zurich` is the identifier and its case
     *                              is part of it. The caller checks it names a
     *                              zone that exists.
     */
    public function setTimezone(?string $timezone): void
    {
        $timezone = trim((string) $timezone);

        $this->timezone = $timezone === '' ? null : $timezone;
    }

    /**
     * The layout everybody who has not chosen one is shown (XIV-66).
     *
     * @return list<string>|null
     */
    public function getDashboardLayout(): ?array
    {
        return $this->dashboardLayout;
    }

    /**
     * @param list<string>|null $layout widget keys in the order they should be
     *                                  drawn, or null for the order the code
     *                                  declares. An empty list is a real answer
     *                                  and is kept — an installation may decide
     *                                  its landing page says nothing, and would
     *                                  find that hard to express if this
     *                                  helpfully turned it back into null
     */
    public function setDashboardLayout(?array $layout): void
    {
        $this->dashboardLayout = $layout === null ? null : array_values(array_unique($layout));
    }

    /** @param string|null $currency an ISO 4217 code; the caller checks it is one */
    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getPaymentTermsDays(): ?int
    {
        return $this->paymentTermsDays;
    }

    /**
     * @param int|null $days whole days from the issue date, or null for "nobody
     *                       has said" — see the property
     */
    public function setPaymentTermsDays(?int $days): void
    {
        // A negative term is a document due before it was issued, which is a
        // typo rather than an agreement. Null is the honest answer to nonsense
        // here, because null already means "nobody has said" and the layers
        // below it read that correctly. The upper bound is generous on purpose:
        // a year is longer than any term anybody negotiates and short enough
        // that a stray keypress does not become a deadline in 2098.
        $this->paymentTermsDays = $days !== null && $days >= 0 && $days <= 365 ? $days : null;
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

    /**
     * Whether this installation has a mark of its own (XIV-49).
     *
     * Asked of the fingerprint rather than of the bytes, because that is the
     * question every caller in the hot path is really asking — is there a URL to
     * draw — and reading it costs nothing even where the bytes are a stream that
     * would have to be consumed to answer.
     */
    public function hasLogo(): bool
    {
        return $this->logoFingerprint !== null;
    }

    /**
     * The mark, as a string, or null when there is none.
     *
     * Doctrine's `blob` gives back a stream on a freshly loaded entity and the
     * original string on one that has just been persisted, so both are handled
     * here rather than at the call sites — the same two cases
     * DocumentTemplate::getContent() has to reconcile.
     */
    public function getLogo(): ?string
    {
        if (\is_resource($this->logo)) {
            rewind($this->logo);

            return (string) stream_get_contents($this->logo);
        }

        return $this->logo === null ? null : (string) $this->logo;
    }

    public function getLogoContentType(): ?string
    {
        return $this->logoContentType;
    }

    public function getLogoFingerprint(): ?string
    {
        return $this->logoFingerprint;
    }

    /**
     * Replaces the mark.
     *
     * **The fingerprint is derived here and never passed in**, so the address the
     * bytes are served under cannot drift away from the bytes. A caller that
     * could supply its own would eventually supply a stale one, and the failure
     * would be a customer looking at their previous logo with no way to tell why.
     *
     * SHA-256 rather than something shorter: this is an identifier a browser is
     * asked to treat as immutable, and a collision would mean one customer's
     * cached mark standing in for the next one they upload.
     *
     * @param string $contentType a LogoFormat value; the caller decides it by
     *                            decoding the bytes, never by reading the upload
     */
    public function setLogo(string $bytes, string $contentType): void
    {
        $this->logo = $bytes;
        $this->logoContentType = $contentType;
        $this->logoFingerprint = hash('sha256', $bytes);
    }

    /**
     * Removes it, and removes all three facts about it together.
     *
     * Leaving the type or the fingerprint behind would be a row claiming to have
     * a logo that no longer exists, which the serving route would answer with an
     * empty image rather than with the honest 404.
     */
    public function clearLogo(): void
    {
        $this->logo = null;
        $this->logoContentType = null;
        $this->logoFingerprint = null;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

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

namespace App\Tenant\Settings;

use App\Tenancy\Security\TenantSecretCipher;
use App\Tenant\Entity\TenantProfile;
use App\Tenant\Mail\MailSettingsRefused;
use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Timezones;
use Symfony\Component\Mime\Address;

/**
 * The write side of the tenant profile (XIV-12).
 *
 * Kept out of the controller for the same reason UserManager is: what a change is
 * allowed to do belongs next to the change rather than next to the HTTP.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantProfileManager
{
    public function __construct(
        private TenantProfileRepository $profiles,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        /** Encrypts the SMTP password on its way in; see applyMail(). */
        private TenantSecretCipher $cipher,
    ) {
    }

    public function current(): TenantProfile
    {
        return $this->profiles->current();
    }

    /**
     * Every currency there is, named in the language being read.
     *
     * From symfony/intl rather than a list kept here, which would be a copy of
     * ISO 4217 going quietly out of date. Sorted by name, because somebody is
     * looking for "Swiss franc" and not for "CHF".
     *
     * @return array<string, string> code => what to call it
     */
    public function currencyChoices(string $locale): array
    {
        return Currencies::getNames($locale);
    }

    /**
     * The countries, named in the reader's own language.
     *
     * From symfony/intl rather than a list kept here, for the same reason the
     * currencies are: a list of countries maintained by hand is a list that is
     * wrong.
     *
     * @return array<string, string> code => what to call it
     */
    public function regionChoices(string $locale): array
    {
        return Countries::getNames($locale);
    }

    /**
     * Applies what the form said.
     *
     * An unknown currency code leaves the stored one alone rather than throwing.
     * The select is built from symfony/intl's own list, so anything else came
     * from a hand-edited request, and the honest answer to that is to change
     * nothing — the same call PermissionManager makes about an unknown module key.
     * An empty code is different and does mean something: nobody has chosen.
     */
    public function apply(
        string $companyName,
        string $currency,
        ?string $region = null,
        ?int $paymentTermsDays = null,
        ?string $timezone = null,
    ): TenantProfile {
        $profile = $this->profiles->current();
        $profile->setCompanyName($companyName);

        if ($currency === '') {
            $profile->setCurrency(null);
        } elseif (Currencies::exists($currency)) {
            $profile->setCurrency($currency);
        }

        // Which country's conventions this installation writes in (XIV-50).
        // Empty means "follow the application's", which is a real answer and not
        // a missing one; an unknown code came from a hand-edited request and
        // changes nothing, the same call the currency above makes.
        if ($region === '' || $region === null) {
            $profile->setRegion(null);
        } elseif (Countries::exists(strtoupper($region))) {
            $profile->setRegion(strtoupper($region));
        }

        // Which zone this installation reads moments in (XIV-83). Empty is the
        // ordinary answer rather than a missing one: it means "let the region
        // say", and for a country with exactly one zone the region says it
        // perfectly well — a Swiss customer never has to touch this. Anything
        // symfony/intl does not know as a zone came from a hand-edited request
        // and changes nothing, the same call the currency and the region above
        // both make.
        if ($timezone === '' || $timezone === null) {
            $profile->setTimezone(null);
        } elseif (Timezones::exists($timezone)) {
            $profile->setTimezone($timezone);
        }

        // How long a customer gets to pay, when nobody has said otherwise on the
        // contact itself (XIV-67). Null clears it, which is not the same as zero:
        // zero is "on receipt" and null is "this installation does not put due
        // dates on anything", and both are answers somebody may mean.
        $profile->setPaymentTermsDays($paymentTermsDays);

        // Persisted every time rather than only when new: the entity is already
        // managed on the normal path, and persist() on a managed entity is a
        // no-op — which is cheaper than asking, and correct on the path where the
        // row was missing.
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * Who this customer's mail comes from, and which server it leaves through
     * (XIV-37, §8.7).
     *
     * **A method of its own rather than four more arguments on apply().** The two
     * halves of this page answer to different rules: what the company is called
     * is a preference where nonsense is worth ignoring, and mail settings are a
     * credential where nonsense is worth refusing — an address that is not one,
     * or a server with no address to send as, would both be discovered later as
     * a bounced invoice.
     *
     * **The password is write-only from here.** Null means "leave what is
     * stored", which is what an unchanged password field submits: rendering a
     * stored secret back into a form so the browser can send it again is a
     * decision this project does not make anywhere else either. Clearing the
     * server clears it, which is the one way to get rid of it.
     *
     * @param string|null $smtpPassword null to keep the stored one, '' to clear it
     *
     * @throws MailSettingsRefused
     */
    public function applyMail(
        string $senderAddress,
        string $smtpHost,
        ?int $smtpPort,
        string $smtpUser,
        #[\SensitiveParameter] ?string $smtpPassword,
    ): TenantProfile {
        $senderAddress = trim($senderAddress);
        $smtpHost = trim($smtpHost);

        if ($senderAddress !== '' && !$this->isAnAddress($senderAddress)) {
            throw MailSettingsRefused::notAnAddress($senderAddress);
        }

        if ($smtpHost !== '' && $senderAddress === '') {
            throw MailSettingsRefused::serverWithoutAnAddress();
        }

        $profile = $this->profiles->current();
        $profile->setMailSenderAddress($senderAddress);
        $profile->setMailSmtpHost($smtpHost);

        if ($smtpHost === '') {
            // No server means no credential to keep. Leaving one behind would be
            // a secret nobody can see, nothing can use, and rotation still has
            // to carry — so removing the server removes it.
            $profile->setMailSmtpPort(null);
            $profile->setMailSmtpUser('');
            $profile->setEncryptedMailSmtpPassword(null);
        } else {
            $profile->setMailSmtpPort($smtpPort);
            $profile->setMailSmtpUser($smtpUser);

            if ($smtpPassword !== null) {
                $profile->setEncryptedMailSmtpPassword(
                    $smtpPassword === '' ? null : $this->cipher->encrypt($smtpPassword),
                );
            }
        }

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * The customer's own mark, uploaded or taken away (XIV-49).
     *
     * **A method of its own rather than another argument on apply(), for the
     * reason applyMail() is one**: this half of the page answers to a different
     * rule. A company name nobody typed is an empty string and a currency nobody
     * chose is null — absence is an answer there. Here absence is the *ordinary*
     * submission: the file input is empty on every load, because a browser cannot
     * be handed the stored file back, so "no file" means "leave it alone" on
     * every save anybody makes for other reasons. Removing therefore has to be a
     * separate act rather than a blank field, which is exactly the shape the SMTP
     * password already has.
     *
     * **Refused rather than fixed up.** Nothing here re-encodes, downscales or
     * strips metadata: a logo that comes back out of this application is byte for
     * byte the one that went in. That is a deliberate choice against the obvious
     * alternative of running everything through GD to normalise it — re-encoding
     * a mark is how a crisp wordmark acquires JPEG ringing, and a customer whose
     * logo came back looking worse has no way of knowing we did it. The cost is
     * that the accepted list has to be genuinely safe to serve untouched, which
     * is the argument LogoFormat makes.
     *
     * **Nothing is refused in here, and that is the point of the argument type.**
     * A LogoUpload only exists because something already proved it was an
     * acceptable image, and the controller constructs it before it calls anything
     * that writes — which is what keeps the page's promise that a refused
     * submission saved nothing, now that two halves of it can refuse. See
     * LogoUpload for the whole of that reasoning.
     *
     * @param LogoUpload|null $logo   a proved upload, or null to leave the stored
     *                                mark alone
     * @param bool            $remove take the stored mark away
     */
    public function applyLogo(?LogoUpload $logo, bool $remove = false): TenantProfile
    {
        $profile = $this->profiles->current();

        // Removal first, and it wins over an upload in the same request. The two
        // cannot both be meant — the checkbox is beside the file input — and
        // deciding for the destructive one means a mis-click on "remove" is
        // undone by uploading again, where the other order would silently keep a
        // file somebody had just asked to be rid of.
        if ($remove) {
            $profile->clearLogo();
        } elseif ($logo !== null) {
            $profile->setLogo($logo->bytes, $logo->format->value);
        } else {
            // Nothing to do, and deliberately no flush: the ordinary save touches
            // this method on every submission and a write per page load would put
            // an `updated_at` bump behind a form that changed nothing about the
            // logo.
            return $profile;
        }

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * symfony/mime's own rules rather than a regular expression of ours: it is
     * the component that will have to build the header out of this, so it is the
     * component whose opinion decides whether it can.
     */
    private function isAnAddress(string $address): bool
    {
        try {
            new Address($address);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}

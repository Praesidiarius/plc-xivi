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
use App\Tenant\Payment\PaymentSettings;
use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Timezones;
use Symfony\Component\Mime\Address;
use Xivi\Core\Money\VatMode;

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
     * The two ways a price can be quoted, as value => translation key
     * (XIV-116).
     *
     * Built from the enum rather than written out on the page, so a third mode
     * could never leave the form offering two — the same rule `LogoFormat` sets
     * for the accepted upload types. **Keys rather than sentences**, because this
     * is a settings page and the sentence has to be in the reader's own language;
     * the template translates them.
     *
     * Static because it asks nothing of a tenant: which modes exist is a fact
     * about the code, unlike the currency and country lists above, which need a
     * locale to be named in.
     *
     * @return array<string, string>
     */
    public static function vatModeChoices(): array
    {
        $choices = [];

        foreach (VatMode::cases() as $mode) {
            $choices[$mode->value] = 'profile.vat_mode_' . $mode->value;
        }

        return $choices;
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
        ?string $vatMode = null,
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

        // Whether this customer's prices already have the VAT in them (XIV-116).
        // Empty clears it, and clearing is not the same as choosing "excluded":
        // an unanswered question writes nothing onto the next document, where an
        // explicit answer writes it down — which is what lets a document say, to
        // whoever receives it, how to read its price column. Anything that is not
        // one of the two modes came from a hand-edited request and changes
        // nothing, the same call the currency, the region and the zone above all
        // make.
        //
        // Nothing already stored moves either way. This is the default a *new*
        // record starts with; every order and invoice that exists carries its own
        // copy and is derived from that (§5.9).
        if ($vatMode === '' || $vatMode === null) {
            $profile->setVatMode(null);
        } elseif (VatMode::tryFrom($vatMode) !== null) {
            $profile->setVatMode($vatMode);
        }

        // Persisted every time rather than only when new: the entity is already
        // managed on the normal path, and persist() on a managed entity is a
        // no-op — which is cheaper than asking, and correct on the path where the
        // row was missing.
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * The account a QR-bill pays into, its reference type, and the company's
     * structured address (XIV-152).
     *
     * **A method of its own for the reason applyMail() is one, resolved the way
     * the logo resolved it**: these settings can be nonsense worth refusing,
     * an IBAN that is not one or a QRR type on an ordinary account, and the
     * page's promise is that a refused submission saved nothing. So nothing in
     * here refuses: a PaymentSettings only exists because its factory already
     * proved the submission, the controller constructs it before the first
     * flush of the request, and by the time this runs there is nothing left to
     * object to. See PaymentSettings for what is proved and what deliberately
     * is not.
     */
    public function applyPayment(PaymentSettings $settings): TenantProfile
    {
        $profile = $this->profiles->current();

        $profile->setPaymentIban($settings->iban);
        $profile->setPaymentReferenceType($settings->referenceType->value);
        $profile->setAddress($settings->street, $settings->buildingNumber, $settings->postalCode, $settings->city);

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * The dashboard anybody who has never chosen one is shown (XIV-66).
     *
     * **A method of its own for the reason `applyMail()` below is one**, and a
     * sharper version of it: this shares not one field with the settings form, so
     * folding it into `apply()` would mean the picker posting a company name, a
     * currency and a country it does not carry — which is to say blanking them.
     * The controller branches before it reaches the main handler for the same
     * reason.
     *
     * **Nothing is validated here, and that is the difference from every other
     * setting on this page.** A currency has to be a currency and a zone has to
     * be a zone, because storing something else means a formatter failing later.
     * A layout is a list of widget keys, and a key naming a widget that does not
     * exist is *already* a state this has to survive — an uninstalled module, a
     * renamed widget, a deleted class — so `Dashboard` drops what it cannot
     * resolve and a check here would guard against nothing that is not guarded
     * anyway. The controller still narrows the submission to the keys the picker
     * drew, which is the cheap half of the same idea.
     *
     * @param list<string>|null $layout widget keys in the order they should be
     *                                  drawn, or null for the order the code
     *                                  declares
     */
    public function applyDashboardLayout(?array $layout): TenantProfile
    {
        $profile = $this->profiles->current();
        $profile->setDashboardLayout($layout);

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

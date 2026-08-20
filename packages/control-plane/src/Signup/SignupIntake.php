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

use App\Registry\Repository\TenantRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Entity\SignupStatus;
use Xivi\ControlPlane\Provisioning\ProvisioningSlug;
use Xivi\ControlPlane\Provisioning\SelfServiceTenantHostname;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Repository\SignupRefusalRepository;
use Xivi\ControlPlane\Repository\SignupRequestRepository;

/**
 * Recording a self-service signup, and confirming the address it was made from
 * (XIV-64).
 *
 * ### What this class does not have
 *
 * **No {@see TenantProvisioner}, and no way to reach one.** That is the ticket's
 * central constraint rather than an accident of scope, so it is stated here where
 * somebody adding a constructor argument will read it. The provisioner connects
 * with `TENANT_ADMIN_DSN`, whose own docblock calls it *"credentials allowed to
 * CREATE DATABASE and CREATE ROLE; provisioning only"*. This class is reached by
 * an anonymous request from the open internet. Wiring the two together would put
 * the most privileged operation in the system one HTTP request away from the
 * least trusted caller there is — not because of a bug, but by design, with the
 * bug then being any flaw at all in the authentication, the parsing or the slug
 * rules in front of it.
 *
 * So this writes one row and sends one mail. Turning a confirmed row into a
 * customer is [XIV-98], and it runs somewhere an operator can see it.
 *
 * **The honest limit** (docs/architecture/identity-and-access.md §8.12, and worth repeating rather
 * than being read as a stronger claim than it is): what has been delivered here
 * is a **code** boundary — a separate service, its own table, no provisioner in
 * scope, nothing in this file that could create a database. It is *not* yet a
 * privilege boundary. There is one instance and one set of environment
 * variables, so `TENANT_ADMIN_DSN` is present in the process that serves this
 * request whether or not anything here reads it. Making the process that answers
 * the public endpoint one that does not hold that credential at all is [XIV-96].
 *
 * ### The two rules that make the endpoint cost something to abuse
 *
 * 1. **A name is held only by a confirmed address.** Submitting reserves
 *    nothing, so a script that posts ten thousand company names has produced ten
 *    thousand rows and blocked nobody. Holding a name costs a mailbox that can
 *    receive and a link that gets clicked, per name.
 * 2. **A confirmed address holds one unprovisioned signup at a time.** Otherwise
 *    the cost above is paid once and then reused: one working mailbox, as many
 *    names as you like.
 *
 * Volume is the rate limiter's problem rather than this class's, and it is
 * applied in the controller — see `Xivi\ControlPlane\Controller\SignupApiController`. The two are
 * different concerns and deliberately not merged: this one is about what is
 * *true* of a signup, and that one is about how often somebody may ask.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupIntake
{
    /**
     * How long somebody has to answer their confirmation mail.
     *
     * The same twenty-four hours §8.8 gives an invitation, and the argument
     * transfers with one change of sign. There it was recorded as *short* —
     * somebody who reads their mail on Monday cannot be told to have read it on
     * Sunday — and the mitigation was that an administrator can send another.
     * Here the person can reissue it themselves by submitting the form again,
     * which is the same mitigation with nobody to ask, so the shorter window
     * costs less. What it buys is that an unanswered signup stops occupying its
     * address within a day.
     */
    public const string CONFIRMATION_WINDOW = 'PT24H';

    /** `signup_request.company_name`'s width, checked here rather than by the driver. */
    private const int MAX_COMPANY_LENGTH = 255;

    /** `signup_request.email`'s width, and the same reasoning. */
    private const int MAX_EMAIL_LENGTH = 180;

    /**
     * @param list<string> $plans the plans this installation sells, most common first
     */
    public function __construct(
        private EntityManagerInterface $controlPlane,
        private SignupRequestRepository $signups,
        private TenantRepository $tenants,
        private SelfServiceSlug $slugs,
        /**
         * Where a name would be served, so that the intake can refuse a
         * hostname somebody else already owns (XIV-98).
         *
         * **Not a provisioner, and this is the constructor argument the class
         * docblock above is warning about.** It reads one configured hostname
         * and does string arithmetic on it; it holds no credential, opens no
         * connection and cannot create anything. `SignupEndpointTest` walks this
         * graph and asserts that {@see TenantProvisioner} and
         * `TENANT_ADMIN_DSN` are absent from it, which is the check that keeps
         * that sentence true rather than merely intended.
         */
        private SelfServiceTenantHostname $hostnames,
        private SignupMailer $mailer,
        private ValidatorInterface $validator,
        /**
         * Which providers are throwaway (XIV-125).
         *
         * A constant list and a string comparison, holding no credential and
         * opening no connection, so it changes nothing the class docblock above
         * claims about this constructor graph. See the class itself for why the
         * list is short, hand-kept and deliberately not a public one.
         */
        private DisposableEmailDomains $disposableDomains,
        /**
         * Where a refusal is counted so that somebody sees it (XIV-125).
         *
         * A repository over one control-plane table, reached only to increment
         * a tally by domain. It is here rather than in the controller because
         * the refusal is decided here, and a refusal recorded in one place and
         * decided in another is a pair that drifts.
         */
        private SignupRefusalRepository $refusals,
        /**
         * **So that a confirmation nobody received leaves a trace** (XIV-61).
         *
         * The send is wrapped twice before it reaches a person: the real cause
         * becomes {@see SignupMailFailed}, that becomes {@see SignupRefused},
         * and the page says the confirmation could not be sent just now and to
         * try again. Nothing along that path wrote the cause down, so an
         * operator looking into "signup is broken" had a row in the intake, a
         * sentence in a browser, and nothing else. That was found by hitting it
         * on a real deployment, where the answer turned out to be one line of
         * configuration and took a redeploy to see at all.
         */
        private LoggerInterface $logger,
        #[Autowire('%app.signup_plans%')]
        private array $plans = ['standard'],
        /**
         * The languages this build actually has (XIV-8's closed set).
         *
         * A caller's `locale` is checked against it rather than believed, and
         * for two reasons rather than one. The polite reason is that a build
         * with no French in it cannot write a French confirmation, so falling
         * back to the default is the honest answer. The other is that the value
         * arrives from outside and is used to switch the translator's locale and
         * is stored in a 16-character column — three different things that a
         * caller must not be able to hand an arbitrary string to.
         *
         * @var list<string>
         */
        #[Autowire('%kernel.enabled_locales%')]
        private array $locales = [],
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale = 'en',
    ) {
    }

    /**
     * Writes down that somebody wants an installation, and mails them to ask
     * whether they really do.
     *
     * ### What a second submission from the same address does
     *
     * It depends on whether the first one was confirmed, and the two answers are
     * different on purpose.
     *
     * **Unconfirmed: the row is reused and the previous link dies.** This is the
     * ordinary case — the mail went to spam, or they typed the company name
     * wrong and started again — and treating it as a conflict would mean the
     * only way out of a confirmation that never arrived is to own a second email
     * address. So the row is rewritten in place with the new answers, a new token
     * is minted, and the twenty-four hours start again. The old link stops
     * working the instant the new one exists, which is §8.8's rule for
     * invitations arriving at the same conclusion from the same argument: "I sent
     * another one" has to be a way to fix a link that leaked.
     *
     * **Confirmed: refused.** At that point the address is holding a name, and
     * the second submission is asking for a second installation. That is a real
     * request and it is not this endpoint's to grant silently — one confirmed
     * address, one unprovisioned signup, which is what stops a single working
     * mailbox from collecting names.
     *
     * ### Order of operations
     *
     * The row is written and flushed *before* the mail goes out, and that is not
     * negotiable in the other direction: a mail sent first would carry a link to
     * a row that does not exist yet, and a failure between the two would leave a
     * confirmation URL in somebody's inbox that answers "unknown" forever. The
     * cost of this order is the case where the write succeeds and the send fails
     * — which leaves a pending row holding nothing, occupying only its own
     * address, replaceable by the very next submission from it. That is the
     * cheaper failure by a long way, and the caller is told
     * {@see SignupError::MailFailed} rather than being congratulated.
     *
     * @throws SignupRefused with the word the caller is answered with
     */
    public function record(SignupSubmission $submission): SignupRequest
    {
        $email = $this->validEmail($submission->email);

        // **Before the name is looked at and before anything at all is written**
        // (XIV-125). The order is the requirement rather than an optimisation:
        // an accepted signup here is a database and a role once [XIV-98] gets to
        // it, so the one place a throwaway address can be refused cheaply is
        // ahead of every other step. Nothing is persisted, nothing is mailed,
        // and no name is held, which is also what makes this safe to do without
        // a transaction.
        //
        // It sits *beside* the rate limiter rather than instead of it. That one
        // bounds how fast signups arrive and is entirely silent about who is
        // making them; this one is the opposite and neither substitutes for the
        // other. See `SignupRateLimits`.
        $this->refuseDisposableAddress($email);

        $company = $submission->companyName;

        if ($company === '') {
            throw SignupRefused::invalidBody('"company" is required');
        }

        // The column is 255 and the caller is anonymous. Checked here so that a
        // long string is a documented refusal rather than a driver exception
        // turning into a 500 — the endpoint's answers are a contract, and "the
        // server broke" is not one of them.
        if (mb_strlen($company) > self::MAX_COMPANY_LENGTH) {
            throw SignupRefused::invalidBody(sprintf(
                '"company" is longer than %d characters',
                self::MAX_COMPANY_LENGTH,
            ));
        }

        $locale = $this->validLocale($submission->locale);
        $plan = $this->validPlan($submission->plan);

        // The locale is *not* passed here, and XIV-100 is why: it chooses the
        // language of the confirmation mail and nothing else. See
        // {@see SelfServiceSlug::derive()} for the argument — the short version is
        // that a hostname is permanent and must not depend on which language
        // somebody read the form in, and that the preview and the submission are
        // two requests which can carry different values of an optional field.
        $slug = $this->validSlug($submission->slug, $company);

        $existing = $this->signups->findOneByEmail($email);

        if ($existing !== null && $existing->getStatus() === SignupStatus::Confirmed) {
            throw SignupRefused::addressAlreadyRegistered($email);
        }

        $this->assertSlugIsFree($slug);

        $token = ConfirmationToken::generate();
        $expiresAt = new \DateTimeImmutable()->add(new \DateInterval(self::CONFIRMATION_WINDOW));

        if ($existing !== null) {
            $existing->reissue($company, $slug, $plan, $locale, $token->hash(), $expiresAt);
            $signup = $existing;
        } else {
            $signup = new SignupRequest($email, $company, $slug, $plan, $locale, $token->hash(), $expiresAt);
            $this->controlPlane->persist($signup);
        }

        try {
            $this->controlPlane->flush();
        } catch (UniqueConstraintViolationException $violation) {
            // Two submissions for one address arriving together. The checks above
            // read a moment ago and the index is what is actually true, so the
            // loser is told what it would have been told a microsecond earlier.
            throw SignupRefused::lostTheRace(SignupError::AddressAlreadyRegistered, $violation);
        }

        try {
            $this->mailer->sendConfirmation($signup, $token);
        } catch (\Throwable $failure) {
            // The slug and not the address: §8.11 keeps a log to counts and
            // identifiers rather than to who somebody is, and the row is what an
            // operator needs to find this again anyway.
            $this->logger->error('A signup confirmation could not be sent.', [
                'exception' => $failure,
                'slug' => $signup->getSlug(),
            ]);

            throw SignupRefused::mailFailed($failure);
        }

        return $signup;
    }

    /**
     * Somebody followed the link in their mail.
     *
     * **Idempotent by construction**, because the ordinary case is that this runs
     * more than once: people click twice, mail is forwarded, and corporate link
     * scanners fetch every URL in a message before its recipient has opened it.
     * A single-use token would turn all three into "your link is not valid", and
     * the third would do it before the human had any chance at all. So the token
     * survives its first use, and it is the row's *state* that makes the second
     * call a no-op — see {@see SignupRequest::confirm()}.
     *
     * What actually stops a replay from being worth anything is that there is
     * nothing to replay: confirming twice does not confirm twice, does not
     * re-reserve, does not send a mail, and does not extend anything. The token
     * still expires, and a second submission from the same address still
     * invalidates it by overwriting the digest it is checked against.
     *
     * The order of the checks is deliberate. **Confirmed is tested before
     * expired**, so somebody who confirmed on Monday and reopens the mail on
     * Friday is told they are confirmed rather than told they are too late —
     * which would be both wrong and alarming.
     */
    public function confirm(#[\SensitiveParameter] string $token): Confirmation
    {
        $signup = $this->signups->findOneByConfirmationTokenHash(ConfirmationToken::hashOf($token));

        if ($signup === null) {
            return Confirmation::unknown();
        }

        if ($signup->getStatus() === SignupStatus::Confirmed) {
            return Confirmation::of(ConfirmationOutcome::AlreadyConfirmed, $signup);
        }

        if ($signup->confirmationHasExpired()) {
            return Confirmation::of(ConfirmationOutcome::Expired, $signup);
        }

        // Nothing was held while this was pending, so the name may have gone in
        // the meantime. Checked here as well as by the unique index, because the
        // check produces the page and the index produces a stack trace.
        if (!$this->slugIsFree($signup->getSlug())) {
            return Confirmation::of(ConfirmationOutcome::SlugTaken, $signup);
        }

        $signup->confirm();

        try {
            $this->controlPlane->flush();
        } catch (UniqueConstraintViolationException) {
            // Two confirmations of one name, within the same instant. The index
            // is the arbiter; the loser is told the name is gone, which is true.
            $this->controlPlane->clear();

            return Confirmation::of(ConfirmationOutcome::SlugTaken, $signup);
        }

        return Confirmation::of(ConfirmationOutcome::Confirmed, $signup);
    }

    /**
     * Whether a name is free, and what it would be called.
     *
     * Reads and writes nothing, which is what makes it safe to expose as a
     * separate call at all — and is also exactly why it is the surface worth
     * being careful about: see {@see SignupError} for why one refusal word covers
     * three different reasons, and docs/architecture/identity-and-access.md §8.12 for the residual
     * enumeration risk it does not remove.
     *
     * **It answers about the name {@see record()} would create**, which is the
     * half of XIV-100 that was not merely cosmetic: an `available: true` computed
     * for a name the submission was never going to produce is not a wrong label
     * on the right answer, it is an answer about a different question. The two go
     * through one derivation that takes nothing from the request but the company
     * name, so there is no longer an argument the two calls can disagree on.
     */
    public function availability(string $slug, string $companyName): SlugAvailability
    {
        try {
            $slug = $this->validSlug($slug, $companyName);
        } catch (SignupRefused $refused) {
            return SlugAvailability::refused($slug, $refused->error);
        }

        return $this->slugIsFree($slug)
            ? SlugAvailability::free($slug)
            : SlugAvailability::refused($slug, SignupError::SlugTaken);
    }

    /** @return list<string> */
    public function plans(): array
    {
        return $this->plans;
    }

    /**
     * @throws SignupRefused
     */
    private function validEmail(string $email): string
    {
        // HTML5 mode: the same grammar a browser's `type="email"` enforces, which
        // is the grammar the form on the other side of this API will have
        // enforced already. Strict RFC mode would refuse addresses browsers
        // accept, and this endpoint is not the place to be more correct than the
        // form that feeds it.
        if ($email === '' || mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw SignupRefused::invalidEmail($email);
        }

        if (\count($this->validator->validate($email, new Email(mode: Email::VALIDATION_MODE_HTML5))) > 0) {
            throw SignupRefused::invalidEmail($email);
        }

        return $email;
    }

    /**
     * Turns away an address whose provider hands out mailboxes nobody keeps
     * (XIV-125).
     *
     * **The refusal is counted before it is thrown**, and that ordering is the
     * ticket's requirement that a refusal not be silent. The tally is by domain
     * and by nothing else, no address and no client, for §8.11's reason one
     * degree harder: this person is not a customer and was never going to be
     * one, so what is kept is that the list matched and how often. The operator
     * screen draws it, and that is how a wrong entry in the list is ever
     * noticed at all.
     *
     * **The word the caller hears is `invalid_email`**, which §8.12's
     * `slug_taken` decided the shape of: whatever the endpoint distinguishes, a
     * caller can enumerate. A code of its own would tell a script exactly which
     * providers are listed, which is a map of which providers still work, and it
     * would tell it in the machine-readable field a script branches on. So the
     * two situations share one word, exactly as three situations share
     * `slug_taken`, and the useful action is identical in both: use a different
     * address. The detailed sentence naming the domain goes to whoever reads a
     * stack trace, like {@see SignupRefused::unauthorized()}'s three.
     *
     * @throws SignupRefused
     */
    private function refuseDisposableAddress(string $email): void
    {
        if (!$this->disposableDomains->covers($email)) {
            return;
        }

        $domain = DisposableEmailDomains::domainOf($email);

        $this->refusals->record($domain);

        throw SignupRefused::disposableAddress($domain);
    }

    /**
     * The language to answer in: what the caller asked for when this build has
     * it, and the installation's own default otherwise.
     *
     * A fallback rather than a refusal, which is the same choice §8.8's
     * translation catalogue makes one level down — an unknown language is a
     * caller being more specific than this build can be, not a caller making a
     * mistake. See the constructor for why it is not simply believed.
     */
    private function validLocale(string $locale): string
    {
        return \in_array($locale, $this->locales, true) ? $locale : $this->defaultLocale;
    }

    /**
     * @throws SignupRefused
     */
    private function validPlan(string $plan): string
    {
        if ($plan === '') {
            // The first configured plan is the default. A deployment that sells
            // one thing therefore needs no `plan` in any request, and one that
            // sells three has said which is ordinary by putting it first.
            return $this->plans[0] ?? 'standard';
        }

        if (!\in_array($plan, $this->plans, true)) {
            throw SignupRefused::unknownPlan($plan, $this->plans);
        }

        return $plan;
    }

    /**
     * @throws SignupRefused
     */
    private function validSlug(string $slug, string $companyName): string
    {
        if ($slug === '') {
            $slug = $this->slugs->derive($companyName);

            if ($slug === '') {
                throw SignupRefused::undeducibleSlug($companyName);
            }
        } elseif (!$this->slugs->isValid($slug)) {
            throw SignupRefused::invalidSlug($slug);
        }

        // **Both paths, and that is the point** (XIV-98). A hostname label and a
        // PostgreSQL identifier disagree about single characters, leading digits
        // and length as well as about separators, so a name can satisfy every
        // rule this endpoint had and still be one [XIV-98] can never provision.
        // Refusing it here is the difference between a customer choosing another
        // name in the second it takes to read the message and a customer being
        // told the name is theirs, confirming an address, and then waiting for
        // an installation that a cron run refuses to build for ever.
        //
        // `derive()` already cuts to a length that translates, so the derived
        // path reaches this only for a name whose first character is a digit or
        // whose whole transliteration is one character — see
        // {@see SelfServiceSlug::MAX_DERIVED_LENGTH} for why shortening is done
        // for a suggestion and refusing is done for a request.
        if (!$this->slugs->isProvisionable($slug)) {
            throw SignupRefused::unprovisionableSlug($slug);
        }

        return $slug;
    }

    /**
     * @throws SignupRefused
     */
    private function assertSlugIsFree(string $slug): void
    {
        if ($this->slugs->isReserved($slug)) {
            throw SignupRefused::slugIsReserved($slug);
        }

        if ($this->tenants->findOneBySlug($slug) !== null) {
            throw SignupRefused::slugBelongsToATenant($slug);
        }

        // **The same question again about the name this becomes** (XIV-98), and
        // it is not a duplicate of the line above. `tenant.slug` holds
        // *provisioning* slugs — underscores legal, hyphens not — and a
        // self-service slug is the mirror image, so the two sets are disjoint
        // for every name containing a separator at all. An operator's `acme_bau`
        // is therefore invisible to `findOneBySlug('acme-bau')`, and without
        // this line the endpoint would promise `acme-bau` to somebody whose
        // tenant could never be created. §8.12 flagged exactly this and handed
        // it here; {@see ProvisioningSlug} carries the proof that the
        // translation cannot make two *signups* collide, which is why there is
        // no third query against this table.
        $tenantSlug = ProvisioningSlug::forSignupSlug($slug);

        if ($tenantSlug !== null && $this->tenants->findOneBySlug($tenantSlug) !== null) {
            throw SignupRefused::translatedSlugBelongsToATenant($slug, $tenantSlug);
        }

        // And the hostname, which is the same trap one noun along. `provision()`
        // takes hostnames as an explicit parameter and derives none from a slug
        // (§8.12), so an operator is free to have routed `acme.xivi.app` at a
        // tenant called something else entirely — and [XIV-98] would then be
        // refused a hostname *after* somebody had confirmed. Asked here, where
        // the answer is still cheap.
        $hostname = $this->hostnames->forSignupSlug($slug);

        if ($hostname !== '' && $this->tenants->hostnameIsTaken($hostname)) {
            throw SignupRefused::hostnameBelongsToATenant($slug, $hostname);
        }

        if ($this->signups->slugIsReserved($slug)) {
            throw SignupRefused::slugIsHeldByAnotherSignup($slug);
        }
    }

    private function slugIsFree(string $slug): bool
    {
        try {
            $this->assertSlugIsFree($slug);
        } catch (SignupRefused) {
            return false;
        }

        return true;
    }
}

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

namespace Xivi\ControlPlane\Provisioning;

use App\Registry\Entity\Tenant;
use App\Registry\Entity\TenantStatus;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserChangeRefused;
use App\Tenant\Security\UserInvitations;
use App\Tenant\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Xivi\ControlPlane\Entity\SignupRequest;

/**
 * Turning one confirmed signup into a customer (XIV-98).
 *
 * [XIV-64] records a signup and deliberately provisions nothing: its endpoint is
 * anonymous and reachable from the open internet, and `TenantProvisioner`
 * connects with the credential its own docblock calls *"allowed to CREATE
 * DATABASE and CREATE ROLE; provisioning only"*. This is the other half — the
 * non-public process that acts on what that endpoint wrote down, and the class
 * that legitimately holds the privilege the intake refuses to be within reach
 * of. It is reached from one console command and from nothing else. When
 * [XIV-96] splits the deployment in two, this belongs in the internal image.
 *
 * ## The four steps, and the fact that any of them can be the last one
 *
 *   1. **The tenant** — registry row, Postgres role, database, schema. All of it
 *      inside `TenantProvisioner::provision()`, which persists its registry row
 *      *first* and by design (§4.1).
 *   2. **The first user** — an administrator with no password at all.
 *   3. **The invitation** — [XIV-1]'s signed login link, mailed to the address
 *      that confirmed.
 *   4. **The signup row goes.** §8.12 promised that: the intake table holds
 *      *live* signups only, which is why `SignupStatus` has two cases and not
 *      three, and why nothing here writes `provisioned` anywhere.
 *
 * Nothing about that is transactional and nothing could make it so — it spans
 * the control-plane database, the Postgres cluster, a customer's own database
 * and somebody else's mail server. So the question is not how to avoid stopping
 * half way; it is what a run that stopped half way leaves behind, and what the
 * next run does with it.
 *
 * ## Which steps are idempotent, established rather than assumed
 *
 * This was read out of the code rather than hoped for, and the answer is
 * uncomfortable enough to be worth writing out in full.
 *
 * **`provision()` is not re-runnable, at either end.** Called a second time for
 * a slug that already has a registry row it throws `slugTaken` before it does
 * anything at all, and even with that row removed by hand it would throw
 * `databaseExists` from `createRoleAndDatabase()`; PostgreSQL has no
 * `CREATE ROLE IF NOT EXISTS`, so the role would raise `42710` on its own. The
 * generated role password is fresh on every call and stored encrypted on the
 * row, so a hypothetical resume would also have to `ALTER ROLE … PASSWORD` to
 * make the stored DSN true again. There is exactly **one** step inside it that
 * is safely repeatable: the migration, because Doctrine records executed
 * versions in the tenant's own `doctrine_migration_versions` and steps over
 * them.
 *
 * **So a half-made tenant is cleaned up rather than finished**, and the cleanup
 * is `deprovision()` — which [XIV-94] made re-runnable in exactly the way this
 * needs: both drops are `IF EXISTS`, sessions are terminated before the drop,
 * and **the registry row is removed last**, so a cleanup that itself fails
 * leaves a row pointing at whatever survived rather than an orphan nothing knows
 * about. Running this command again then repeats the same cleanup over what has
 * already gone and finishes it.
 *
 * Nothing is lost by destroying rather than resuming, and that is an argument
 * rather than a shrug. A tenant still in {@see TenantStatus::Provisioning} has
 * never served a request — `TenantStatus::servesRequests()` says so, and
 * `TenantRequestListener` enforces it — and its first user is created below,
 * after `provision()` has returned. So there is no session, no record and
 * nobody holding a credential. It is an empty database with a company's name on
 * it, and the only thing thrown away is the seconds it took to make.
 *
 * **Steps 2, 3 and 4 are idempotent as they stand**, which is why the tenant is
 * *not* torn down once it is serving:
 *
 *   * Creating the first user is guarded by a lookup on the address, and
 *     `UserManager::add()` refuses a duplicate anyway, so a second run finds the
 *     account rather than making a second one.
 *   * Sending an invitation twice is [XIV-1]'s own documented behaviour rather
 *     than something tolerated here: the seed is rotated, the previous link
 *     dies, and there is never more than one live invitation per person. A run
 *     that failed *after* handing the message to a mail server is the one case
 *     where a second mail really is sent; it costs the recipient one duplicate
 *     and nothing else, and the older link is dead before the newer one arrives.
 *   * Removing the signup row is a `DELETE` of a row that has already gone.
 *
 * ## Telling our own wreckage from somebody else's customer
 *
 * The resume path above is the dangerous one, because "a tenant with this slug
 * exists" is not the same sentence as "a previous run of mine made it". An
 * operator's own `acme_ag`, provisioned by hand a year ago, matches on the slug
 * — and walking into it to create an administrator and mail a stranger a link
 * into somebody else's installation is the worst thing this file could do.
 *
 * **So identity is the hostname, not the slug.** A tenant this feature made is
 * routed at {@see SelfServiceTenantHostname::forSignupSlug()}'s answer and holds
 * it as its primary domain, written in the same flush as the registry row — so
 * even the earliest wreckage carries it. A tenant that does not hold that
 * hostname is somebody else's, whatever it is called, and this refuses to touch
 * it in either direction: it is neither resumed nor torn down, the signup fails
 * at {@see SignupProvisioningStage::Preflight}, and a person is left to decide
 * which of the two names has to move. That refusal repeats every run, for ever,
 * which is the correct amount of pressure for something only a person can
 * settle.
 *
 * ## The invitation, and the two things a cron has that a browser does not
 *
 * §8.8 predicted both of them and left them here.
 *
 * **There is no request, so there is no hostname to be absolute against.**
 * `DEFAULT_URI` is `http://localhost` in this repository, and a login link
 * generated against it is a link to nowhere. So the router's context is pointed
 * at the tenant's own hostname for the duration of the send, over `https`,
 * exactly as {@see \Xivi\ControlPlane\Signup\SignupMailer} builds a confirmation
 * link from configuration rather than from a header. It is put back in a
 * `finally`, because this runs in a loop over many customers in one process and
 * a leaked context would sign the next person's link for the previous person's
 * domain — a link that *works*, and admits somebody to the wrong installation.
 *
 * **There is no request, so there is no locale either.** An invitation is
 * ordinarily rendered in the language of whoever pressed the button; nobody
 * pressed anything here. The best answer available is the language the visitor
 * was reading the signup form in, which the row has been carrying since
 * [XIV-64] recorded it for the confirmation mail.
 *
 * ## What is deliberately not done
 *
 * **No modules are installed.** `tenant.enabled_modules` is left as provisioning
 * leaves it, because which modules a plan includes is a question about billing
 * and nothing in this system answers it yet — §6.2's catalogue says what exists,
 * not what was sold. An installation with no modules is an empty one rather than
 * a broken one, and the first administrator can install from the store (§6.3).
 *
 * **No password is generated anywhere.** `tenant:provision --admin-email` prints
 * one because an operator is watching a terminal; nobody is watching this, and
 * §8.5's own note said the printing goes away once there is a mailer. A
 * generated password nobody ever reads is a live credential sitting on the
 * account for as long as the account exists.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupProvisioner
{
    /** What the first administrator is granted; §8.4 has the rest of the model. */
    private const array FIRST_USER_ROLES = ['ROLE_ADMIN'];

    /**
     * The scheme a tenant is reached over, and it is not negotiable.
     *
     * The same fixing {@see \Xivi\ControlPlane\Signup\SignupMailer} applies to a
     * confirmation link, for a stronger reason: this URL signs somebody into an
     * installation without asking them for anything. `DEFAULT_URI`'s scheme is
     * whatever a deployment wrote for the benefit of unrelated console output,
     * and a login link is not something to put on a plaintext connection because
     * a variable was left at its development value.
     */
    private const string SCHEME = 'https';

    public function __construct(
        private EntityManagerInterface $controlPlane,
        private TenantRepository $tenants,
        private TenantProvisioner $provisioner,
        private SelfServiceTenantHostname $hostnames,
        private TenantSwitcher $switcher,
        private UserManager $users,
        private UserRepository $userRepository,
        private UserInvitations $invitations,
        private RouterInterface $router,
        private LocaleSwitcher $locales,
    ) {
    }

    /**
     * Makes a customer out of one confirmed signup, or says where it stopped.
     *
     * **Never throws for a failure of this signup**, which is the contract the
     * command depends on: a run over a queue must not lose the rest of the queue
     * to the first person whose mail server is refusing connections. What it can
     * still let out is a genuinely unexpected error — a bug, an exhausted memory
     * limit — because swallowing those would cost the stack trace `-v` exists to
     * show, and §4.1 made the same distinction for `tenant:reset` for the same
     * reason.
     */
    public function provision(SignupRequest $signup): SignupProvisioningOutcome
    {
        // The *reserved* name rather than the requested one. The two are equal on
        // a confirmed row — `confirm()` copies one into the other and nothing
        // else ever writes the second — and reading the one the unique index
        // protects is the one that cannot be wrong about what this address
        // actually holds.
        $signupSlug = $signup->getReservedSlug() ?? $signup->getSlug();

        $tenantSlug = ProvisioningSlug::forSignupSlug($signupSlug);

        if ($tenantSlug === null) {
            // Unreachable through the intake, which refuses an unmappable name at
            // the door — see ProvisioningSlug for the three ways the two patterns
            // disagree about more than separators. Checked anyway, because
            // "unreachable" is a claim about today's callers and this costs a
            // regular expression.
            return $this->fail($signup, SignupProvisioningStage::Preflight, sprintf(
                'The name "%s" has no legal provisioning slug, so it cannot become a database name.',
                $signupSlug,
            ));
        }

        $hostname = $this->hostnames->forSignupSlug($signupSlug);

        if ($hostname === '') {
            // SIGNUP_HOST is empty, so signup is off — and yet a confirmed row is
            // sitting here, which means it was switched off after somebody had
            // already been promised an address. There is no domain to serve them
            // on, and inventing one would be inventing the promise as well.
            return $this->fail($signup, SignupProvisioningStage::Preflight, sprintf(
                'Signup is switched off (SIGNUP_HOST is empty), so there is no domain to serve "%s" on.',
                $signupSlug,
            ), $tenantSlug);
        }

        $tenant = $this->tenants->findOneBySlug($tenantSlug);
        $resumed = $tenant !== null;

        if ($tenant !== null && !$this->isOurs($tenant, $hostname)) {
            return $this->fail($signup, SignupProvisioningStage::Preflight, sprintf(
                'Tenant "%s" already exists and is not routed at %s, so it is somebody else\'s. '
                . 'One of the two names has to move before this signup can proceed.',
                $tenantSlug,
                $hostname,
            ), $tenantSlug, $hostname);
        }

        if ($tenant !== null && $tenant->getStatus() === TenantStatus::Provisioning) {
            // Wreckage from a run that died. The class docblock has both halves
            // of the argument: why this is cleared rather than continued, and why
            // nothing of a customer's can be inside it.
            try {
                $this->provisioner->deprovision($tenant);
            } catch (TenantRemovalFailed $failure) {
                return $this->fail(
                    $signup,
                    SignupProvisioningStage::Cleanup,
                    $failure->getMessage(),
                    $tenantSlug,
                    $hostname,
                );
            }

            $tenant = null;
            $resumed = false;
        }

        if ($tenant === null) {
            $owner = $this->tenants->findOneByHostname($hostname);

            if ($owner !== null) {
                // `provision()` would refuse this as well, and would refuse it
                // *before* persisting anything — so that failure would leave no
                // registry row and nothing on [XIV-58]'s page to look at. Caught
                // here so the report can name whose hostname it is rather than
                // reading the string back.
                return $this->fail($signup, SignupProvisioningStage::Preflight, sprintf(
                    'The hostname %s is already routed at tenant "%s".',
                    $hostname,
                    $owner->getSlug(),
                ), $tenantSlug, $hostname);
            }

            try {
                $tenant = $this->provisioner->provision(
                    slug: $tenantSlug,
                    // What they call themselves, which is also what §8.6's
                    // InstanceName falls back to until they have set one of their
                    // own — so the invitation below already carries the company's
                    // name on the day the tenant is made.
                    name: $signup->getCompanyName(),
                    hostnames: [$hostname],
                    plan: $signup->getPlan(),
                );
            } catch (ProvisioningFailed $failure) {
                return $this->fail(
                    $signup,
                    SignupProvisioningStage::Tenant,
                    $failure->getMessage(),
                    $tenantSlug,
                    $hostname,
                );
            }
        }

        $stopped = $this->admitFirstUser($tenant, $signup, $hostname);

        if ($stopped !== null) {
            [$stage, $reason] = $stopped;

            return $this->fail($signup, $stage, $reason, $tenantSlug, $hostname);
        }

        // Last, and only once everything else has worked. §8.12 chose deletion
        // over a `provisioned` status so that `tenant.slug` stays the one answer
        // to "does this customer exist"; the price of that choice is exactly this
        // ordering constraint, because the row is also the only thing that
        // remembers to try again.
        $this->controlPlane->remove($signup);
        $this->controlPlane->flush();

        return SignupProvisioningOutcome::provisioned($signup->getEmail(), $signupSlug, $tenantSlug, $hostname, $resumed);
    }

    /**
     * Whether this tenant is one this feature made for this name.
     *
     * The primary domain rather than the slug, and the class docblock carries the
     * argument. A tenant with no domains at all answers false, which is the
     * conservative direction: `provision()` writes the domain in the same flush
     * as the row, so a tenant without one was not made here.
     */
    private function isOurs(Tenant $tenant, string $hostname): bool
    {
        return $tenant->getPrimaryDomain()?->getHostname() === $hostname;
    }

    /**
     * Creates the administrator and mails them their way in, or says where it
     * stopped.
     *
     * ### Both halves inside one switch, which is not tidiness
     *
     * `TenantSwitcher::runFor()` resets the tenant entity manager on the way in
     * and on the way out, so a `User` carried out of one call is a detached
     * object by the time a second call could use it — and `UserInvitations`
     * rotates the invitation seed and flushes, which on a detached entity writes
     * nothing while appearing to work. One switch, both steps, and the failure
     * stage travels back as a value rather than as an exception so that the
     * caller can go on to the next customer.
     *
     * ### The account has no password, which is [XIV-1]'s requirement
     *
     * And the load-bearing half of it: a generated password created for somebody
     * who is about to choose their own is a credential sitting on the account
     * that nobody will ever rotate, because nobody knows it is there.
     * `createWithoutPassword()` leaves the hash empty — a state nothing can
     * authenticate against from either direction — and sets the hold that keeps
     * them at `/account` until they have chosen one.
     *
     * The address is the one that confirmed. The display name is that address
     * too: a signup knows a *company* name and an email and does not know what
     * the person is called, so putting the company's name on a person would be a
     * guess that then reads as a fact on every page they appear on.
     * `tenant:provision --admin-email` defaults the same way for the same reason,
     * and the owner can change it on their own profile in a moment.
     *
     * The locale is the one the visitor read the form in. It is the only thing
     * this feature knows about their language, it is better than the
     * installation's default, and unlike the display name it is a preference
     * rather than a claim about somebody — being wrong about it costs one
     * dropdown.
     *
     * @return array{SignupProvisioningStage, string}|null null when it worked
     */
    private function admitFirstUser(Tenant $tenant, SignupRequest $signup, string $hostname): ?array
    {
        $context = $this->router->getContext();
        $host = $context->getHost();
        $scheme = $context->getScheme();

        $context->setHost($hostname);
        $context->setScheme(self::SCHEME);

        // **The port is left exactly as it was**, and that is a decision worth
        // one sentence. The host is the part of this URL that only the tenant
        // can supply — nothing else in the process knows it — while the port is
        // a property of the *installation*, which is precisely what `DEFAULT_URI`
        // is the configured statement of. Forcing 443 here would produce a link
        // that cannot be followed on any deployment served on another port,
        // including this repository's own development stack, and it would do so
        // silently.

        try {
            return $this->switcher->runFor($tenant, function () use ($signup): ?array {
                try {
                    $user = $this->userRepository->findOneByEmail($signup->getEmail());

                    if ($user === null) {
                        $user = $this->users->createWithoutPassword(
                            $signup->getEmail(),
                            $signup->getEmail(),
                            self::FIRST_USER_ROLES,
                        );
                        $this->users->setLocale($user, $signup->getLocale());
                    }
                } catch (UserChangeRefused $refused) {
                    return [SignupProvisioningStage::FirstUser, $refused->getMessage()];
                }

                // Somebody who already has a password has accepted an invitation
                // and chosen one, so a previous run reached the mail and failed
                // only to delete the signup row. `UserInvitations` would refuse a
                // second link outright (§8.8: an invitation is not offered for an
                // account that already has a password); stopping here means that
                // refusal never has to be caught and reasoned about.
                if ($user->hasPassword()) {
                    return null;
                }

                try {
                    $this->locales->runWithLocale(
                        $signup->getLocale(),
                        fn () => $this->invitations->send($user),
                    );
                } catch (\Throwable $failure) {
                    return [SignupProvisioningStage::Invitation, $failure->getMessage()];
                }

                return null;
            });
        } finally {
            $context->setHost($host);
            $context->setScheme($scheme);
        }
    }

    /**
     * Writes the failure onto the signup and hands the words back.
     *
     * The row survives, still confirmed, still holding its name — see
     * {@see SignupRequest::recordProvisioningFailure()} for why there is no
     * `failed` status to move it into. Flushed straight away rather than at the
     * end of the run, because the run's next act is to create somebody else's
     * database and it may not reach the end.
     */
    private function fail(
        SignupRequest $signup,
        SignupProvisioningStage $stage,
        string $reason,
        ?string $tenantSlug = null,
        ?string $hostname = null,
    ): SignupProvisioningOutcome {
        $signup->recordProvisioningFailure($stage);
        $this->controlPlane->flush();

        return SignupProvisioningOutcome::failed(
            $signup->getEmail(),
            $signup->getReservedSlug() ?? $signup->getSlug(),
            $stage,
            $reason,
            $signup->getProvisioningAttempts(),
            $tenantSlug,
            $hostname,
        );
    }
}

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

namespace App\Tests\Functional\ControlPlane;

use App\Registry\Entity\Tenant;
use App\Registry\Entity\TenantStatus;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserInvitations;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mime\Email;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Entity\SignupStatus;
use Xivi\ControlPlane\Provisioning\ProvisioningSlug;
use Xivi\ControlPlane\Provisioning\SelfServiceTenantHostname;
use Xivi\ControlPlane\Provisioning\SignupProvisioningStage;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\Signup\SignupError;
use Xivi\ControlPlane\Signup\SignupIntake;
use Xivi\ControlPlane\Signup\SignupRefused;
use Xivi\ControlPlane\Signup\SignupSubmission;

/**
 * `signup:provision` against real databases (XIV-98).
 *
 * [XIV-64]'s own tests end where a row is written and a link is clicked; this
 * class begins there. Every claim in the ticket is about what happens to a
 * *cluster* — a role, a database, a schema, an account in it — so there is no
 * way to write it without creating them, in the same shape
 * {@see TenantUsageCollectionTest} uses and for the same reason: `CREATE
 * DATABASE` is not something a transaction can undo, so this carries
 * `#[SkipDatabaseRollback]` and clears up after itself at both ends.
 *
 * ## What is actually being asserted
 *
 * **That a partial failure is recoverable by running the command again.**
 * `provision()` is not re-runnable — it refuses a slug the registry already
 * holds, and would refuse the database and the role after that — so a retry has
 * to clear the wreckage rather than continue it, and
 * {@see testAHalfProvisionedTenantIsClearedAndRebuiltByTheNextRun} builds the
 * exact state a run that died leaves behind and then asks the command to finish
 * the job. A registry row in `provisioning` with no database behind it is what
 * §4.1 says that looks like, and it is what the fixture writes.
 *
 * **That one failure does not cost the others.** The fixture is a doomed signup
 * *beside* a good one, provisioned in one run, and the assertion is that the
 * good customer exists afterwards and the run still exited non-zero.
 *
 * **That the collision §8.12 handed this ticket is prevented at the intake**,
 * rather than discovered here. That one is asserted against `SignupIntake`
 * directly rather than through the HTTP endpoint, because the claim is about
 * what the intake refuses and not about how a controller renders it — and the
 * endpoint's own tests already cover the rendering.
 *
 * **That nobody's password is generated.** The one credential this feature
 * creates is a signed link, so the assertion is on the *absence* of a hash and
 * on the presence of a URL under the tenant's own hostname.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class SignupProvisioningTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    /**
     * Self-service slugs — hyphens legal, underscores not — which is the half of
     * the point.
     *
     * Short on purpose: each becomes `%app.tenant_object_prefix%` plus a
     * translated slug, and that string is a PostgreSQL database name with sixty
     * three bytes to live in. The prefix already carries a checkout name and a
     * worker number (XIV-9, XIV-51).
     */
    private const string GOOD = 'xiv98-ok';
    private const string SECOND = 'xiv98-two';

    /** The name an operator has already taken, in *provisioning* spelling. */
    private const string CLASH = 'xiv98-clash';

    /** Every address this class writes ends with this, so cleanup can find them. */
    private const string ADDRESS_SUFFIX = '@xiv98.test';

    protected function setUp(): void
    {
        // SymfonyStyle wraps its table to the terminal and several assertions
        // below read cells out of that output — the same pin, for the same
        // reason, as TenantUsageCollectionTest.
        putenv('COLUMNS=240');

        self::bootKernel();

        // On the way in as well as on the way out: the control plane is not
        // rolled back between tests in this class, so a run that died half way
        // through would otherwise make the next one fail for a reason that has
        // nothing to do with the code.
        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * One confirmed signup, end to end.
     *
     * The tenant exists under the translated name, it is routed at the hostname
     * §8.13's form promised, its first administrator has **no password at all**,
     * an invitation is in the post — and the signup row is gone, which is
     * §8.12's promise that this table holds live signups only.
     */
    public function testAConfirmedSignupBecomesATenantWhoseFirstUserIsInvited(): void
    {
        $email = 'happy' . self::ADDRESS_SUFFIX;
        $this->confirmedSignup($email, self::GOOD, 'Acme Provisioning AG');

        $tester = $this->provision();
        $tester->assertCommandIsSuccessful();

        $tenant = $this->tenant(self::GOOD);

        // The translation, which is the whole reason this ticket needed a
        // mapping: a hyphen is illegal in an unquoted PostgreSQL identifier and
        // an underscore is illegal in a DNS label.
        self::assertSame($this->tenantSlug(self::GOOD), $tenant->getSlug());
        self::assertSame(TenantStatus::Active, $tenant->getStatus());
        self::assertSame('Acme Provisioning AG', $tenant->getName());

        // And the hostname, which is what [XIV-65]'s form showed the visitor
        // beside the name box before they had submitted anything.
        self::assertSame($this->hostname(self::GOOD), $tenant->getPrimaryDomain()?->getHostname());

        $user = $this->firstUserOf($tenant, $email);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        // **No password was generated**, which is [XIV-1]'s requirement rather
        // than a detail: one created for somebody who is about to choose their
        // own is a credential nobody ever rotates, because nobody knows it is
        // there.
        self::assertFalse($user->hasPassword(), 'a password was generated for an invited first user');
        self::assertTrue(UserInvitations::isPending($user));

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        // The concrete type, so the body can be read below without decoding the
        // whole MIME part by hand — `toString()` would quoted-printable-wrap the
        // very URL the negative assertion is about, and a negative assertion that
        // passes because the needle was folded is worse than no assertion.
        self::assertInstanceOf(Email::class, $message);
        // The address twice, because the display name *is* the address: a signup
        // knows a company name and an email and does not know what the person is
        // called, so nothing here invents one.
        self::assertEmailHeaderSame($message, 'To', sprintf('"%1$s" <%1$s>', $email));

        // The link is absolute against the *tenant's* hostname rather than
        // against DEFAULT_URI, which is `http://localhost` in this repository —
        // §8.8 left that problem open for exactly this caller, because a URL
        // generated off a cron has no request to be absolute against.
        self::assertEmailTextBodyContains($message, 'https://' . $this->hostname(self::GOOD));
        self::assertEmailTextBodyContains($message, '/invitation?user=');

        // The other half of the same claim, and the one that would still fail if
        // the override were removed: `DEFAULT_URI`'s host must not be what a
        // customer is sent to. The port is deliberately left as configuration
        // put it — see SignupProvisioner for why the host is the only part of
        // that URL this feature is entitled to decide.
        self::assertStringNotContainsString(
            'https://localhost',
            (string) $message->getTextBody(),
            'the invitation was signed against DEFAULT_URI rather than the tenant',
        );

        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail($email),
            'the signup row survives a provisioning it succeeded at',
        );
    }

    /**
     * **The acceptance criterion about retries.**.
     *
     * The fixture is what §4.1 says a run that died leaves: a registry row in
     * `provisioning`, routed at the right hostname, with no database and no role
     * behind it. `provision()` would refuse that slug outright, so the only way
     * the next run can finish the job is to clear it first — which is
     * `deprovision()`, made safe to repeat by [XIV-94] because both drops are
     * `IF EXISTS` and the registry row goes last.
     *
     * Afterwards there is exactly **one** tenant with that name, it is active,
     * and the customer has their invitation. No second registry row, no second
     * role, and nothing orphaned.
     */
    public function testAHalfProvisionedTenantIsClearedAndRebuiltByTheNextRun(): void
    {
        $email = 'halfway' . self::ADDRESS_SUFFIX;
        $this->confirmedSignup($email, self::GOOD, 'Halfway AG');
        $this->createWreckage(self::GOOD);

        // The state an operator finds on [XIV-58]'s page: a customer who is not
        // being served, sorted to the top and named in the banner. That is the
        // acceptance criterion about visibility, and it is a property of the row
        // rather than of anything this ticket added.
        self::assertFalse($this->tenant(self::GOOD)->getStatus()->servesRequests());

        $tester = $this->provision();
        $tester->assertCommandIsSuccessful();

        $rebuilt = $this->tenant(self::GOOD);
        self::assertSame(TenantStatus::Active, $rebuilt->getStatus());
        self::assertSame($this->hostname(self::GOOD), $rebuilt->getPrimaryDomain()?->getHostname());

        self::assertCount(
            1,
            self::service(EntityManagerInterface::class)
                ->getRepository(Tenant::class)
                ->findBy(['slug' => $this->tenantSlug(self::GOOD)]),
            'the retry wrote a second registry row instead of clearing the first',
        );

        // The database really is there this time, which is what distinguishes a
        // rebuild from a row somebody flipped to active.
        $this->firstUserOf($rebuilt, $email);
    }

    /**
     * **The acceptance criterion about one failure not costing the others.**.
     *
     * Two confirmed signups. The first is doomed — an operator holds the name it
     * translates to — and the second is ordinary. The run provisions the second,
     * reports the first, and exits non-zero so that cron mails somebody.
     */
    public function testOneFailingSignupDoesNotStopTheOthersAndTheRunExitsNonZero(): void
    {
        $doomed = 'doomed' . self::ADDRESS_SUFFIX;
        $fine = 'fine' . self::ADDRESS_SUFFIX;

        // Confirmed first, so `findConfirmed()`'s oldest-first order puts the
        // failure ahead of the success — a run that abandoned the queue at the
        // first failure would leave the second customer unmade and this test
        // would say so.
        $this->confirmedSignup($doomed, self::CLASH, 'Doomed AG');
        $this->confirmedSignup($fine, self::SECOND, 'Fine AG');
        $this->createRivalTenant();

        $tester = $this->provision();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a run with a failure in it says so');

        // The customer the run had every opportunity to abandon.
        $second = $this->tenant(self::SECOND);
        self::assertSame(TenantStatus::Active, $second->getStatus());
        $this->firstUserOf($second, $fine);

        // And the one that failed is still a live signup, still confirmed, still
        // holding its name — with the attempt written down. There is no `failed`
        // status, because §8.12 refused a third one.
        $failed = self::service(SignupRequestRepository::class)->findOneByEmail($doomed);
        self::assertInstanceOf(SignupRequest::class, $failed);
        self::assertSame(SignupStatus::Confirmed, $failed->getStatus());
        self::assertSame(1, $failed->getProvisioningAttempts());
        self::assertSame(SignupProvisioningStage::Preflight, $failed->getProvisioningStage());
        self::assertNotNull($failed->getProvisioningFailedAt());

        // Preflight is the one stage a retry can never get past, which is why it
        // is a case of its own.
        self::assertFalse(SignupProvisioningStage::Preflight->isWorthRetrying());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Could not be provisioned', $display);
        self::assertStringContainsString($doomed, $display, 'the cron mail has to name who failed');

        // Nothing was created for the doomed one, and nothing of the rival's was
        // touched.
        self::assertSame(
            'A Rival Customer',
            $this->tenant(self::CLASH)->getName(),
            'the run walked into a tenant it did not create',
        );
    }

    /**
     * **The sharpest thing in the ticket: the collision is prevented at the
     * intake.**.
     *
     * `tenant.slug` holds provisioning slugs, so an operator's `xiv98_clash` can
     * never equal a self-service `xiv98-clash` — the intake's own check looks up
     * the wrong string and finds nothing. Without the translated lookup this
     * submission would be accepted, the address would be confirmed, the name
     * would be reserved, and the failure would arrive days later in a cron run.
     */
    public function testANameWhoseTranslationBelongsToATenantIsRefusedAtTheIntake(): void
    {
        $this->createRivalTenant();

        $intake = self::service(SignupIntake::class);

        // The availability check first, because that is what the form asks as
        // somebody types and what decides whether they are ever shown the name
        // as theirs.
        $availability = $intake->availability(self::CLASH, 'Clashing AG');
        self::assertFalse($availability->isAvailable());
        self::assertSame(SignupError::SlugTaken, $availability->reason);

        $refused = null;

        try {
            $intake->record(SignupSubmission::fromPayload([
                'email' => 'clash' . self::ADDRESS_SUFFIX,
                'company' => 'Clashing AG',
                'slug' => self::CLASH,
                'locale' => 'en',
            ]));
        } catch (SignupRefused $e) {
            $refused = $e;
        }

        self::assertInstanceOf(SignupRefused::class, $refused, 'the colliding name was accepted');
        self::assertSame(SignupError::SlugTaken, $refused->error);

        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail('clash' . self::ADDRESS_SUFFIX),
            'a refused submission wrote a row anyway',
        );
    }

    /**
     * A hostname that is already routed at somebody is the same trap one noun
     * along.
     *
     * `provision()` takes hostnames as an explicit parameter and derives none
     * from a slug (§8.12), so an operator can perfectly well have routed
     * `xiv98-ok.localhost` at a tenant called something else entirely. The
     * refusal for that has to happen here too, and for the same reason.
     */
    public function testANameWhoseHostnameBelongsToATenantIsRefusedAtTheIntake(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $squatter = new Tenant('xiv98_elsewhere', 'Elsewhere AG', 'postgresql://nowhere/x');
        $squatter->addDomain($this->hostname(self::GOOD), true);
        $squatter->markProvisioned();
        $manager->persist($squatter);
        $manager->flush();

        $availability = self::service(SignupIntake::class)->availability(self::GOOD, 'Acme Provisioning AG');

        self::assertFalse($availability->isAvailable());
        self::assertSame(SignupError::SlugTaken, $availability->reason);
    }

    /**
     * A legal DNS label with no legal translation is refused at the door.
     *
     * The three ways the rules disagree about more than separators, each of them
     * a name a customer could reasonably want: one character, a leading digit,
     * and anything past fifty-six. Every one of them is a perfectly good
     * hostname and none of them can be an unquoted PostgreSQL identifier, so the
     * only honest moment to say so is while somebody is still choosing.
     */
    public function testANameThatCannotBecomeADatabaseNameIsRefusedAtTheIntake(): void
    {
        $intake = self::service(SignupIntake::class);

        foreach (['a', '3m', str_repeat('x', ProvisioningSlug::MAX_LENGTH + 1)] as $slug) {
            $availability = $intake->availability($slug, 'Whatever AG');

            self::assertFalse(
                $availability->isAvailable(),
                sprintf('"%s" was offered and cannot be provisioned', $slug),
            );
            self::assertSame(SignupError::InvalidSlug, $availability->reason);
        }
    }

    /**
     * An empty queue is a success, and that is a decision rather than an
     * oversight.
     *
     * `tenant:usage:collect` errors when it finds nothing, because an
     * installation with no customers is misconfigured. Here, nothing waiting is
     * the ordinary state of a healthy installation on most nights — and a cron
     * entry that mails somebody every night is one whose mail nobody reads
     * within a fortnight.
     */
    public function testAnEmptyQueueIsNotAFailure(): void
    {
        $tester = $this->provision();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No confirmed signups waiting', $tester->getDisplay());
    }

    /**
     * A pending signup is not provisioned, even when named outright.
     *
     * The gate §8.12 built is that an address typed into a form proves nothing.
     * `--email` is the one way a row could reach the command without passing
     * through the status filter in SQL, so the refusal is asserted from that
     * direction.
     */
    public function testAnUnconfirmedSignupIsNeverProvisioned(): void
    {
        $email = 'pending' . self::ADDRESS_SUFFIX;
        $this->signupRow($email, self::GOOD, 'Not Yet AG');

        $tester = $this->provision($email);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull(
            self::service(TenantRepository::class)->findOneBySlug($this->tenantSlug(self::GOOD)),
            'an address that never answered its confirmation mail was given a tenant',
        );
    }

    private function provision(?string $email = null): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester((new Application($kernel))->find('signup:provision'));
        $tester->execute($email === null ? [] : ['--email' => $email]);

        return $tester;
    }

    /**
     * A signup row written straight into the control plane and confirmed.
     *
     * Not made through the endpoint, deliberately: that path is [XIV-64]'s and
     * has its own tests, it needs the shared secret and a rate limiter with room
     * in it, and what this class is about begins after the link has been
     * clicked. The row is built the way the intake builds one so that nothing
     * here depends on a controller.
     */
    private function confirmedSignup(string $email, string $slug, string $company): SignupRequest
    {
        $signup = $this->signupRow($email, $slug, $company);
        $signup->confirm();

        self::service(EntityManagerInterface::class)->flush();

        return $signup;
    }

    private function signupRow(string $email, string $slug, string $company): SignupRequest
    {
        $manager = self::service(EntityManagerInterface::class);

        $signup = new SignupRequest(
            $email,
            $company,
            $slug,
            'standard',
            'en',
            hash('sha256', $email . $slug),
            new \DateTimeImmutable('+24 hours'),
        );

        $manager->persist($signup);
        $manager->flush();

        return $signup;
    }

    /**
     * Exactly what a provisioning run that died before `CREATE DATABASE` leaves
     * behind.
     *
     * A registry row in `provisioning`, holding the hostname this feature routes
     * that name at — which is what tells the next run the wreckage is *ours* and
     * not an operator's customer — and a DSN naming a database and a role that
     * were never created. `deprovision()` steps over both, because both drops
     * are `IF EXISTS`.
     */
    private function createWreckage(string $slug): void
    {
        $manager = self::service(EntityManagerInterface::class);

        $wreckage = new Tenant(
            $this->tenantSlug($slug),
            'Halfway AG',
            sprintf('postgresql://%1$s:@database:5432/%1$s?serverVersion=18', $this->tenantSlug($slug)),
        );
        $wreckage->addDomain($this->hostname($slug), true);
        $wreckage->setEncryptedDatabasePassword('XIV98WRECKAGECIPHERTEXT');

        $manager->persist($wreckage);
        $manager->flush();

        self::assertSame(TenantStatus::Provisioning, $wreckage->getStatus());
    }

    /**
     * An operator's own customer, holding the name a self-service slug
     * translates to.
     *
     * A registry row with no database behind it, exactly as `TenantListTest`
     * writes one: the question is whether the name is taken, and provisioning a
     * real customer to ask it would be slower and no more truthful. Its hostname
     * is deliberately *not* the one this feature would choose, which is what
     * makes it somebody else's rather than our own wreckage.
     */
    private function createRivalTenant(): void
    {
        $manager = self::service(EntityManagerInterface::class);

        $rival = new Tenant($this->tenantSlug(self::CLASH), 'A Rival Customer', 'postgresql://nowhere/x');
        $rival->addDomain('rival.xiv98.test', true);
        $rival->markProvisioned();
        $manager->persist($rival);
        $manager->flush();
    }

    /** The account the invitation was addressed to, read out of the customer's own database. */
    private function firstUserOf(Tenant $tenant, string $email): User
    {
        $user = self::service(TenantSwitcher::class)->runFor(
            $tenant,
            static fn (): ?User => self::service(UserRepository::class)->findOneByEmail($email),
        );

        self::assertInstanceOf(User::class, $user, sprintf('no first user in "%s"', $tenant->getSlug()));

        return $user;
    }

    private function tenant(string $signupSlug): Tenant
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug($this->tenantSlug($signupSlug));
        self::assertInstanceOf(Tenant::class, $tenant, sprintf('no tenant for "%s"', $signupSlug));

        return $tenant;
    }

    /** The provisioning name, through the one translation this feature has. */
    private function tenantSlug(string $signupSlug): string
    {
        $slug = ProvisioningSlug::forSignupSlug($signupSlug);
        self::assertNotNull($slug, sprintf('"%s" is not a fixture this test can use', $signupSlug));

        return $slug;
    }

    /** And the hostname, through the one function that decides it. */
    private function hostname(string $signupSlug): string
    {
        return self::service(SelfServiceTenantHostname::class)->forSignupSlug($signupSlug);
    }

    /**
     * Everything this class can have created, in the order that works.
     *
     * The tenants go through `deprovision()` — the row, the database and the
     * role, the same call an operator's command makes — because several of them
     * are real. The ones that were only ever registry rows are removed the same
     * way, which is safe: both drops are `IF EXISTS`, so a database that never
     * existed is stepped over rather than complained about.
     */
    private function removeFixtures(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->clear();

        foreach ($manager->createQuery(
            'SELECT s FROM ' . SignupRequest::class . ' s WHERE s.email LIKE :suffix',
        )->setParameter('suffix', '%' . self::ADDRESS_SUFFIX)->toIterable() as $signup) {
            $manager->remove($signup);
        }

        $manager->flush();

        $tenants = self::service(TenantRepository::class);
        $provisioner = self::service(TenantProvisioner::class);

        foreach ([self::GOOD, self::SECOND, self::CLASH] as $slug) {
            $tenant = $tenants->findOneBySlug($this->tenantSlug($slug));

            if ($tenant instanceof Tenant) {
                $provisioner->deprovision($tenant);
            }
        }

        $squatter = $tenants->findOneBySlug('xiv98_elsewhere');

        if ($squatter instanceof Tenant) {
            $manager->remove($squatter);
            $manager->flush();
        }

        $manager->clear();
    }

    /** @template T of object
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

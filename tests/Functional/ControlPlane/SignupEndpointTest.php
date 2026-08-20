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
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Exception\NoTenantResolvedException;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Xivi\ControlPlane\Controller\SignupApiController;
use Xivi\ControlPlane\Controller\SignupConfirmationController;
use Xivi\ControlPlane\Entity\SignupRefusal;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Entity\SignupStatus;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Repository\SignupRefusalRepository;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\ConfirmationToken;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupError;
use Xivi\ControlPlane\Signup\SignupHost;
use Xivi\ControlPlane\Signup\SignupRateLimits;
use Xivi\ControlPlane\Signup\SignupRefused;

/**
 * The public signup endpoint (XIV-64).
 *
 * **The happy path is the least interesting thing here**, exactly as it is for
 * the invitation one screen along. This is an anonymous, internet-facing
 * endpoint in front of the most privileged operation in the system, so what is
 * worth a test class is everything it is not allowed to do:
 *
 *   1. **It provisions nothing and cannot.** The first assertion of the happy
 *      path is that no tenant exists afterwards, and
 *      {@see testTheEndpointHoldsNoCredentialThatCanCreateADatabase} walks the
 *      service graph behind the two controllers to show that
 *      `TenantProvisioner` and `TENANT_ADMIN_DSN` are not reachable from either.
 *   2. **It reserves nothing until an address has answered.** Which is what makes
 *      squatting cost a mailbox per name, and is asserted from both sides: an
 *      unconfirmed signup does not block anybody, and a confirmed one blocks
 *      everybody.
 *   3. **It is not served anywhere else**, and it holds no credential of its own
 *      beyond one shared secret.
 *
 * **The control plane is not rolled back between tests** (see
 * `config/packages/test/dama_doctrine_test.yaml`, and the reason: a tenant
 * database is created by `CREATE DATABASE`, which no transaction can undo), so
 * the fixtures are removed by hand at both ends.
 *
 * **Nothing here puts mail on the wire.** `NonProductionMailGuard` refuses to
 * build a transport that could deliver outside production, and the message is
 * read out of Symfony's own logger in this process — the same arrangement
 * `UserInvitationTest` uses, and the reason the link these tests follow is the
 * one that was really in the mail.
 *
 * **Every test uses its own address and its own client address**, so that no
 * test can spend a rate-limit bucket another one depends on. The limiter's store
 * is a cache pool, every cache pool carries `kernel.reset`, and the container's
 * resetter runs when a request terminates — so counters do not in fact survive
 * from one request to the next here, and the two tests that are about limiting
 * spend their bucket in-process rather than by repeating a request. Both facts
 * are written down because each of them presents as the other's symptom.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupEndpointTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string COMPANY = 'Acme Signup AG';

    /** A slug both slug rules accept, so it can also stand in for a real tenant's. */
    private const string TAKEN_BY_A_TENANT = 'xiv64taken';

    /** Every address this class creates starts with this, so cleanup can find them. */
    private const string ADDRESS_SUFFIX = '@xiv64.test';

    /**
     * A real throwaway provider and a real free-mail one (XIV-125).
     *
     * Neither ends in {@see ADDRESS_SUFFIX}, and neither could: the point of both
     * is the domain, so they are cleaned up by name instead. The throwaway one
     * never produces a row at all, which is the assertion, and the free-mail one
     * produces an ordinary pending signup that {@see removeFixtures()} removes.
     */
    private const string THROWAWAY_DOMAIN = 'guerrillamail.com';
    private const string THROWAWAY_ADDRESS = 'xiv125burner@' . self::THROWAWAY_DOMAIN;
    private const string FREE_MAIL_ADDRESS = 'xiv125owner@gmail.com';

    private KernelBrowser $client;
    private string $host;
    private string $secret;

    /** @see confirmationUrl() for why the link is captured rather than looked up later */
    private ?string $lastConfirmationUrl = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request, because several tests read
        // what was written afterwards and a rebooted kernel would hand back a
        // fresh one that had never seen it.
        $this->client->disableReboot();

        $this->host = self::service(SignupHost::class)->normalisedHost();
        $this->secret = self::secret();

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    /**
     * One signup, end to end — and the assertion that matters is the last one.
     */
    public function testASignupIsRecordedConfirmedAndProvisionsNothing(): void
    {
        $email = 'happy' . self::ADDRESS_SUFFIX;

        [$status, $body] = $this->submit(['email' => $email, 'company' => self::COMPANY]);

        self::assertSame(Response::HTTP_CREATED, $status);
        self::assertSame('pending_confirmation', $body['status']);
        self::assertSame('acme-signup-ag', $body['slug'], 'derived from the company name');
        self::assertSame($email, $body['email']);
        self::assertSame('standard', $body['plan'], 'the first configured plan');
        self::assertIsString($body['confirmation_expires_at']);

        // **The mail comes from the instance, not from a tenant**: there is no
        // tenant, which is the whole point of a signup (§8.7, §8.12). The suite
        // leaves `MAILER_SENDER` unset on purpose — that is what keeps
        // `OutgoingMailTest`'s fallback assertions meaningful — so this is the
        // signup fallback, `no-reply@` at the signup host, being exercised rather
        // than a configured address being echoed back.
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'From', 'no-reply@' . $this->host);
        self::assertEmailAddressContains($message, 'To', $email);
        self::assertEmailTextBodyContains($message, 'https://' . $this->host . '/signup/confirm/');

        $signup = $this->signup($email);
        self::assertSame(SignupStatus::Pending, $signup->getStatus());
        self::assertNull($signup->getReservedSlug(), 'nothing is held before an address has answered');

        // And now the link that was really in the mail.
        $this->client->request('GET', $this->confirmationUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', self::COMPANY);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        self::service(EntityManagerInterface::class)->clear();
        $signup = $this->signup($email);
        self::assertSame(SignupStatus::Confirmed, $signup->getStatus());
        self::assertSame('acme-signup-ag', $signup->getReservedSlug(), 'the name is held now and not before');

        // **Nothing was provisioned.** No registry row, therefore no database and
        // no role. That is [XIV-98]'s work and it has not run.
        self::assertNull(
            self::service(TenantRepository::class)->findOneBySlug('acme-signup-ag'),
            'the public endpoint creates no tenant',
        );
    }

    /**
     * **The endpoint cannot create a database or a role**, which is the ticket's
     * central constraint.
     *
     * Walks the constructor graph behind both controllers and asserts two things
     * about every concrete class it reaches: none of them is
     * {@see TenantProvisioner}, and none of them is handed `TENANT_ADMIN_DSN` —
     * the credential whose own docblock says it is *"allowed to CREATE DATABASE
     * and CREATE ROLE; provisioning only"*.
     *
     * **The honest limit of this instrument**, stated here rather than left to be
     * assumed: it follows concrete classes and stops at interfaces, because an
     * interface's implementation is chosen by the container rather than by the
     * type. That is enough for what is being claimed — autowiring can only inject
     * `TenantProvisioner` by its own final class name, so it cannot arrive
     * disguised as an interface — and it is not a proof that nothing anywhere
     * downstream holds an elevated credential. The proof of *that* is [XIV-96],
     * where the process serving this endpoint stops having the variable at all.
     * §8.12 says the same thing in prose.
     */
    public function testTheEndpointHoldsNoCredentialThatCanCreateADatabase(): void
    {
        $visited = [];
        $queue = [SignupApiController::class, SignupConfirmationController::class];

        while ($queue !== []) {
            $class = array_shift($queue);

            if (isset($visited[$class])) {
                continue;
            }

            $visited[$class] = true;

            self::assertNotSame(
                TenantProvisioner::class,
                $class,
                'the public signup endpoint must not be able to reach provisioning',
            );

            $constructor = new \ReflectionClass($class)->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                foreach ($parameter->getAttributes(Autowire::class) as $autowire) {
                    self::assertStringNotContainsString(
                        'TENANT_ADMIN_DSN',
                        json_encode($autowire->getArguments(), \JSON_THROW_ON_ERROR),
                        sprintf('%s::$%s is handed the provisioning credential', $class, $parameter->getName()),
                    );
                }

                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
                    $queue[] = $type->getName();
                }
            }
        }

        self::assertGreaterThan(5, \count($visited), 'the walk reached something rather than stopping at the door');
    }

    /**
     * No secret, no signup — and no mail, which is the half that matters. An
     * endpoint that refused after sending would be a way to make us mail
     * strangers.
     */
    public function testWithoutTheSharedSecretNothingIsRecordedAndNothingIsSent(): void
    {
        $email = 'nosecret' . self::ADDRESS_SUFFIX;

        [$status, $body] = $this->submit(['email' => $email, 'company' => self::COMPANY], secret: null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $status);
        self::assertSame('unauthorized', $body['error']);
        self::assertEmailCount(0);
        self::assertNull(self::service(SignupRequestRepository::class)->findOneByEmail($email));
    }

    public function testAWrongSecretIsRefusedTheSameWay(): void
    {
        [$status, $body] = $this->submit(
            ['email' => 'wrongsecret' . self::ADDRESS_SUFFIX, 'company' => self::COMPANY],
            secret: $this->secret . 'x',
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $status);
        self::assertSame('unauthorized', $body['error']);
    }

    /**
     * **Derivation and availability are part of the contract**, so that the name
     * [XIV-65]'s form shows before submission is the name that gets recorded. Two
     * implementations of a transliteration rule disagree on the first umlaut
     * somebody types.
     */
    public function testTheNameIsDerivedAndItsAvailabilityAnswered(): void
    {
        [$status, $body] = $this->check(['company' => 'Bäckerei Müller', 'locale' => 'de']);

        self::assertSame(Response::HTTP_OK, $status);
        self::assertSame('baeckerei-mueller', $body['slug'], 'the installation’s transliteration rule');
        self::assertTrue($body['available']);
        self::assertArrayNotHasKey('reason', $body, 'a reason is present only when the answer is no');

        // And an explicit name is checked rather than derived.
        [, $body] = $this->check(['company' => 'Anything At All', 'slug' => 'www']);
        self::assertSame('www', $body['slug']);
        self::assertFalse($body['available']);
        self::assertSame('slug_taken', $body['reason']);
    }

    /**
     * **The name the preview shows is the name the submission creates** (XIV-100).
     *
     * The reported bug, reproduced as it was reported: an availability check and a
     * submission of the same company name, answering `muller-bau-ag` and
     * `mueller-bau-ag`. There was only ever one derivation and both calls already
     * went through it — what differed was the argument, because `locale` is
     * optional on both and nothing obliged a caller to send the same value twice.
     *
     * So the two calls here are made **deliberately out of step**: one carries no
     * locale, the other carries German, and one of them is the check while the
     * other is the write. That is the shape a real form takes without meaning to —
     * a keystroke-driven check with a thin body, and a submission with everything
     * on it — and it is exactly what used to disagree. If the derivation ever
     * reads the request again, this fails.
     *
     * The availability answer matters as much as the spelling: `available: true`
     * computed for a name the submission was never going to produce is not a
     * cosmetic mismatch, it is an answer about a different question.
     */
    public function testThePreviewedNameIsTheNameThatGetsCreated(): void
    {
        $company = 'Müller Bau AG';

        [$status, $preview] = $this->check(['company' => $company]);
        self::assertSame(Response::HTTP_OK, $status);
        self::assertTrue($preview['available'], 'nobody is called that yet');

        [$status, $created] = $this->submit([
            'email' => 'umlaut' . self::ADDRESS_SUFFIX,
            'company' => $company,
            'locale' => 'de',
        ]);

        self::assertSame(Response::HTTP_CREATED, $status);
        self::assertSame(
            $preview['slug'],
            $created['slug'],
            'the customer was shown one address and given another',
        );
        self::assertSame('mueller-bau-ag', $created['slug'], 'and the rule that was chosen is the German one');
    }

    /**
     * Reserved names are refused, **including this deployment's own hosts**.
     *
     * The control-plane host is the one that matters: [XIV-57] made
     * `tenant:provision` refuse to route a tenant onto it, and that refusal fires
     * when [XIV-98] runs — long after somebody has confirmed an address and been
     * told the name is theirs.
     */
    public function testAReservedNameIsRefusedIncludingTheControlPlanesOwn(): void
    {
        $controlPlaneLabel = strstr(self::service(ControlPlaneHost::class)->normalisedHost(), '.', true);
        self::assertIsString($controlPlaneLabel, 'the control-plane host has a first label to reserve');

        foreach (['www', 'admin', 'api', 'localhost', $controlPlaneLabel] as $reserved) {
            [$status, $body] = $this->submit([
                'email' => 'reserved-' . $reserved . self::ADDRESS_SUFFIX,
                'company' => self::COMPANY,
                'slug' => $reserved,
            ]);

            self::assertSame(Response::HTTP_CONFLICT, $status, $reserved);
            self::assertSame('slug_taken', $body['error'], $reserved);
        }

        self::assertEmailCount(0, 'a refused signup sends nothing');
    }

    public function testANameAnExistingTenantHasIsRefused(): void
    {
        $this->createTenantFixture();

        [$status, $body] = $this->submit([
            'email' => 'existing' . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
            'slug' => self::TAKEN_BY_A_TENANT,
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $status);
        // The same word a reserved name gets, on purpose: telling a caller *why*
        // a name is unavailable turns this endpoint into a way to discover who is
        // already a customer here.
        self::assertSame('slug_taken', $body['error']);

        // **And the sentence beside the code says no more than the code does.**
        // The refusal built inside the intake names the tenant — "a tenant is
        // already called xiv64taken" — and returning that would have undone the
        // paragraph above from inside the response it was written for.
        self::assertSame('That name is not available.', $body['message']);
        self::assertStringNotContainsString(self::TAKEN_BY_A_TENANT, (string) $body['message']);

        // Reserved, held by a confirmed signup and taken by a customer are one
        // answer, character for character. A prober learns nothing from
        // comparing them.
        [, $reserved] = $this->submit([
            'email' => 'reserved-same' . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
            'slug' => 'www',
        ]);

        self::assertSame($body, $reserved, 'two different reasons, one indistinguishable answer');
    }

    public function testASyntacticallyImpossibleNameIsARefusalThatLeaksNothing(): void
    {
        $refusals = [
            ['slug' => 'Acme_AG', 'error' => 'invalid_slug'],
            ['slug' => '-acme', 'error' => 'invalid_slug'],
            ['slug' => str_repeat('a', 64), 'error' => 'invalid_slug'],
        ];

        foreach ($refusals as $i => $case) {
            [$status, $body] = $this->submit([
                'email' => 'syntax' . $i . self::ADDRESS_SUFFIX,
                'company' => self::COMPANY,
                'slug' => $case['slug'],
            ]);

            self::assertSame(Response::HTTP_BAD_REQUEST, $status, $case['slug']);
            self::assertSame($case['error'], $body['error'], $case['slug']);
        }
    }

    public function testAnAddressThatIsNotOneIsRefusedBeforeAnythingIsLookedUp(): void
    {
        [$status, $body] = $this->submit(['email' => 'not-an-address', 'company' => self::COMPANY]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_email', $body['error']);
        self::assertEmailCount(0);
    }

    /**
     * **A signup from a throwaway provider is refused before anything exists**
     * (XIV-125).
     *
     * Three assertions and each is a separate clause of the ticket. Nothing is
     * recorded and nothing is sent, which is what "before anything is
     * provisioned" means at this end of the feature: [XIV-98] provisions from a
     * confirmed row, so a refusal that leaves no row can never become a database.
     * The refusal is *counted*, so an operator can see it and review the list
     * that produced it. And the word is `invalid_email`, shared with "that is not
     * an address at all", because a code of its own would let anybody read the
     * throwaway list back one address at a time, which is §8.12's `slug_taken`
     * argument arriving at the same answer for the other field.
     */
    public function testASignupFromAThrowawayProviderIsRefusedAndCounted(): void
    {
        [$status, $body] = $this->submit(['email' => self::THROWAWAY_ADDRESS, 'company' => self::COMPANY]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_email', $body['error'], 'one word for two situations, deliberately');
        self::assertStringNotContainsString(
            'throwaway',
            $body['message'],
            'the sentence must not confirm which addresses would work',
        );

        self::assertEmailCount(0, 'nothing is sent to a mailbox nobody keeps');
        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail(self::THROWAWAY_ADDRESS),
            'nothing is recorded, so there is nothing for signup:provision to find',
        );

        $refusal = self::service(SignupRefusalRepository::class)->findOneBy(['domain' => self::THROWAWAY_DOMAIN]);
        self::assertInstanceOf(SignupRefusal::class, $refusal, 'a refused signup is not silently dropped');
        self::assertSame(1, $refusal->getAttempts());
    }

    /**
     * **The common free-mail providers sign up like anybody else**, which is the
     * judgement the whole feature turns on (XIV-125).
     *
     * A great many one-person companies read their post at Gmail, and refusing
     * one is a customer lost who is never told why. The per-provider list is
     * asserted in `DisposableEmailDomainsTest`; what this adds is that the
     * endpoint itself accepts one end to end, so a check wired in at the wrong
     * place cannot pass that test and refuse everybody here.
     */
    public function testAFreeMailProviderSignsUpLikeAnybodyElse(): void
    {
        [$status, $body] = $this->submit(['email' => self::FREE_MAIL_ADDRESS, 'company' => 'Gmail Using AG']);

        self::assertSame(Response::HTTP_CREATED, $status, 'gmail.com is free, not disposable');
        self::assertSame('pending_confirmation', $body['status']);
        self::assertEmailCount(1);
    }

    /**
     * A field longer than its column is a documented refusal rather than a driver
     * exception turning into a 500. The endpoint's answers are a contract, and
     * "the server broke" is not one of them.
     */
    public function testAFieldLongerThanItsColumnIsRefusedRatherThanTruncatedOrThrown(): void
    {
        [$status, $body] = $this->submit([
            'email' => str_repeat('a', 200) . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_email', $body['error']);

        [$status, $body] = $this->submit([
            'email' => 'long' . self::ADDRESS_SUFFIX,
            'company' => str_repeat('Company ', 100),
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_request', $body['error']);
    }

    /**
     * **The confirmation is written in the visitor's language**, which the
     * calling site forwards because there is nowhere else to get it: this person
     * has no account here, so no stored preference, and the `Accept-Language` of
     * a server-to-server POST belongs to the calling server.
     *
     * A language this build does not have falls back to the installation's
     * default rather than being refused — the same choice the translation
     * catalogue makes one level down, and it also keeps a caller from handing an
     * arbitrary string to the translator and to a 16-character column.
     */
    public function testTheConfirmationIsWrittenInTheVisitorsLanguage(): void
    {
        $this->submit([
            'email' => 'german' . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
            'locale' => 'de',
        ]);

        $german = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $german);
        self::assertSame('Bestätige deine Xivi-Installation', $german->getSubject());

        $this->submit([
            'email' => 'klingon' . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
            'locale' => 'tlh',
        ]);

        $fallback = self::getMailerMessage(\count(self::getMailerMessages()) - 1);
        self::assertInstanceOf(Email::class, $fallback);
        self::assertSame('Confirm your Xivi installation', $fallback->getSubject());
    }

    /**
     * A company name with nothing transliterable in it and no slug supplied is a
     * refusal rather than an invented name: a suggestion nobody recognises is
     * worse than being asked to type one.
     */
    public function testACompanyNameWithNoUsableCharactersIsARefusal(): void
    {
        [$status, $body] = $this->submit(['email' => 'nothing' . self::ADDRESS_SUFFIX, 'company' => '!!! ???']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_slug', $body['error']);
    }

    /**
     * `Tenant::$plan` exists, billing does not, and the intake still refuses to
     * behave as though one plan is the only one — because that is the assumption
     * that would have to be undone first.
     */
    public function testAPlanThisInstallationDoesNotSellIsRefused(): void
    {
        [$status, $body] = $this->submit([
            'email' => 'plan' . self::ADDRESS_SUFFIX,
            'company' => self::COMPANY,
            'plan' => 'platinum-unlimited',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('unknown_plan', $body['error']);
    }

    /**
     * **A second submission from an unconfirmed address is a resend**, not a
     * conflict — otherwise the only way out of a confirmation that went to spam
     * is to own a second email address. The previous link dies with the same
     * write, which is §8.8's rule for invitations arriving from the same
     * argument.
     */
    public function testASecondSubmissionReplacesAnUnconfirmedOneAndKillsTheFirstLink(): void
    {
        $email = 'twice' . self::ADDRESS_SUFFIX;

        $this->submit(['email' => $email, 'company' => self::COMPANY]);
        $first = $this->confirmationUrl();

        [$status, $body] = $this->submit(['email' => $email, 'company' => 'Corrected Name AG']);
        self::assertSame(Response::HTTP_CREATED, $status, 'a resend is accepted rather than refused');
        self::assertSame('corrected-name-ag', $body['slug'], 'and the corrected answers are what is stored');

        $second = $this->confirmationUrl();
        self::assertNotSame($first, $second, 'a new token, so the old link cannot still be live');

        // One row, not two: the unique index on the address is what makes the
        // second submission a replacement.
        self::assertSame('corrected-name-ag', $this->signup($email)->getSlug());

        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the superseded link is gone');

        $this->client->request('GET', $second);
        self::assertResponseIsSuccessful();
    }

    /**
     * **Following a link twice is the ordinary case, not the attack.** People
     * click twice, mail is forwarded, and a corporate link scanner fetches every
     * URL in a message before its recipient has seen it — so a second click is a
     * no-op with a page of its own rather than an error.
     */
    public function testFollowingAConfirmationLinkTwiceChangesNothing(): void
    {
        $email = 'replay' . self::ADDRESS_SUFFIX;
        $this->submit(['email' => $email, 'company' => self::COMPANY]);
        $url = $this->confirmationUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        self::service(EntityManagerInterface::class)->clear();
        $confirmedAt = $this->signup($email)->getConfirmedAt();
        self::assertNotNull($confirmedAt);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('a replay is answered, not refused');

        // Counted over the replay request alone, which is the claim: the logger
        // is reset when a request terminates, so this is what *that* request
        // sent rather than what the test has sent altogether.
        self::assertEmailCount(0, 'nothing is sent again');

        self::service(EntityManagerInterface::class)->clear();
        $signup = $this->signup($email);
        self::assertSame(SignupStatus::Confirmed, $signup->getStatus());
        self::assertEquals($confirmedAt, $signup->getConfirmedAt(), 'the moment of the *first* click is kept');
    }

    /**
     * One working mailbox, one unprovisioned signup. Without this the cost of
     * holding a name is paid once and then reused for as many names as you like.
     */
    public function testAConfirmedAddressMayNotHoldASecondSignup(): void
    {
        $email = 'confirmed' . self::ADDRESS_SUFFIX;
        $this->submit(['email' => $email, 'company' => self::COMPANY]);
        $this->client->request('GET', $this->confirmationUrl());

        [$status, $body] = $this->submit(['email' => $email, 'company' => 'Second Company AG']);

        self::assertSame(Response::HTTP_CONFLICT, $status);
        self::assertSame('address_already_registered', $body['error']);
    }

    /**
     * And the other half of the same rule seen from outside: an *unconfirmed*
     * signup blocks nobody, a confirmed one blocks everybody. That asymmetry is
     * the anti-squatting property.
     */
    public function testANameIsHeldOnlyOnceItsAddressHasAnswered(): void
    {
        $mine = 'holder' . self::ADDRESS_SUFFIX;
        $theirs = 'rival' . self::ADDRESS_SUFFIX;

        $this->submit(['email' => $mine, 'company' => self::COMPANY, 'slug' => 'contested-name']);

        [, $body] = $this->check(['company' => 'x', 'slug' => 'contested-name']);
        self::assertTrue($body['available'], 'an unconfirmed signup holds nothing');

        $this->client->request('GET', $this->confirmationUrl());

        [, $body] = $this->check(['company' => 'x', 'slug' => 'contested-name']);
        self::assertFalse($body['available'], 'a confirmed one holds it');

        [$status, $body] = $this->submit([
            'email' => $theirs,
            'company' => self::COMPANY,
            'slug' => 'contested-name',
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $status);
        self::assertSame('slug_taken', $body['error']);
    }

    /**
     * A window that has closed says so — `410 Gone`, because it was there and is
     * not any more — and a token that matches nothing is `404`.
     */
    public function testAnExpiredLinkAndAnUnknownOneAreDifferentAnswers(): void
    {
        $token = ConfirmationToken::generate();
        $manager = self::service(EntityManagerInterface::class);

        // Constructed directly rather than aged by moving a clock: the expiry is
        // a constructor argument, so a row that has already timed out is one
        // `new` rather than a reflection write into a private property.
        $manager->persist(new SignupRequest(
            'expired' . self::ADDRESS_SUFFIX,
            self::COMPANY,
            'expired-name',
            'standard',
            'en',
            $token->hash(),
            new \DateTimeImmutable('-1 hour'),
        ));
        $manager->flush();

        $this->client->request('GET', $this->urlFor($token->plaintext));
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);

        $this->client->request('GET', $this->urlFor(ConfirmationToken::generate()->plaintext));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Volume is not what confirmation defends against, so there is a limiter as
     * well — and it says how long to wait, because a caller told to stop and not
     * told for how long either stops for ever or retries immediately.
     */
    public function testTooManySubmissionsAreRefused(): void
    {
        $email = 'flood' . self::ADDRESS_SUFFIX;
        $address = '203.0.113.250';
        $limits = self::service(SignupRateLimits::class);

        // **The hour's allowance is spent through the service rather than
        // through six requests**, and the reason is worth writing down because
        // the obvious version does not work. The limiter's store is a cache pool,
        // every cache pool carries `kernel.reset`, and the container's resetter
        // runs when a request terminates — so in this environment each request
        // starts with empty counters and a loop of six posts is six first
        // attempts. Spending the bucket in-process and then making one request
        // asserts the thing that is actually in question: that the controller
        // consults the limiter at all, and answers 429 with a wait when it says
        // no.
        for ($i = 0; $i < 5; ++$i) {
            $limits->consumeForSubmission($email, $address);
        }

        [$status, $body] = $this->post(
            '/api/signup/v1/requests',
            ['email' => $email, 'company' => self::COMPANY, 'client_ip' => $address],
            self::CONFIGURED_SECRET,
        );

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status);
        self::assertSame('rate_limited', $body['error']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertEmailCount(0, 'a refused request sends nothing');
        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail($email),
            'and writes nothing',
        );
    }

    /**
     * And the limiter itself, at the seam the controller uses.
     *
     * Separate from the test above because the two claims are different: that one
     * is "the endpoint consults it", this one is "it says no at the right point
     * and says how long".
     */
    public function testTheAddressBucketRefusesTheSixthAttemptInAnHour(): void
    {
        $limits = self::service(SignupRateLimits::class);
        $email = 'bucket' . self::ADDRESS_SUFFIX;

        for ($i = 0; $i < 5; ++$i) {
            $limits->consumeForSubmission($email, '203.0.113.251');
        }

        $refused = self::refusalFrom(fn () => $limits->consumeForSubmission($email, '203.0.113.251'));

        self::assertNotNull($refused, 'the sixth attempt in an hour should have been refused');
        self::assertSame(SignupError::RateLimited, $refused->error);
        self::assertNotNull($refused->retryAfterSeconds);
        self::assertGreaterThan(0, $refused->retryAfterSeconds);

        // A different address is a different bucket, which is what stops one
        // person's flood from being everybody's outage.
        self::assertNull(
            self::refusalFrom(fn () => $limits->consumeForSubmission(
                'somebody-else' . self::ADDRESS_SUFFIX,
                '203.0.113.252',
            )),
            'an untouched bucket is not refused',
        );
    }

    /** Whatever refusal a call produced, or null when it produced none. */
    private static function refusalFrom(callable $call): ?SignupRefused
    {
        try {
            $call();
        } catch (SignupRefused $refused) {
            return $refused;
        }

        return null;
    }

    /**
     * **The endpoint exists on one hostname and nowhere else**, and that is the
     * router rather than a rule: the routes carry the configured host, so the
     * same path elsewhere is not a route at all.
     */
    public function testTheEndpointIsNotServedOnAnyOtherHost(): void
    {
        foreach (['localhost', self::service(ControlPlaneHost::class)->normalisedHost()] as $elsewhere) {
            $this->client->request(
                'POST',
                sprintf('https://%s/api/signup/v1/requests', $elsewhere),
                server: $this->headers($this->secret),
                content: json_encode(['email' => 'x' . self::ADDRESS_SUFFIX, 'company' => 'X'], \JSON_THROW_ON_ERROR),
            );

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $elsewhere);
        }
    }

    /**
     * **One request, one database, and here that database is the control
     * plane's** — the property XIV-58 established for the tenant list and which
     * this endpoint has to keep for a different reason: there is no tenant to
     * open a connection to, because that is what a signup *is*.
     */
    public function testTheEndpointOpensNoTenantConnection(): void
    {
        $this->submit(['email' => 'noconnection' . self::ADDRESS_SUFFIX, 'company' => self::COMPANY]);

        self::assertNull(
            self::service(TenantContext::class)->tryGetTenant(),
            'a signup request resolves no tenant',
        );

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        self::assertFalse($connection->isConnected(), 'the tenant connection was never opened');

        $this->expectException(NoTenantResolvedException::class);
        $connection->executeQuery('SELECT 1');
    }

    /**
     * A body that is not JSON, or a field that is not a string, is refused with
     * the one code that says so rather than being coerced into an address.
     */
    public function testAMalformedBodyIsRefused(): void
    {
        $this->client->request(
            'POST',
            sprintf('https://%s/api/signup/v1/requests', $this->host),
            server: $this->headers($this->secret),
            content: 'not json at all',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_request', $this->body()['error']);

        [$status, $body] = $this->submit(['email' => ['a', 'b'], 'company' => self::COMPANY]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $status);
        self::assertSame('invalid_request', $body['error']);
    }

    /**
     * A sentinel, because `null` already means something here: "send no header
     * at all". A default of null with a `??` behind it would have made the
     * anonymous case silently authenticate, which is precisely the test that must
     * not pass by accident — and did, once, before this constant existed.
     */
    private const string CONFIGURED_SECRET = "\0configured";

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, array<string, mixed>}
     */
    private function submit(array $payload, ?string $secret = self::CONFIGURED_SECRET): array
    {
        return $this->post('/api/signup/v1/requests', $payload, $secret);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, array<string, mixed>}
     */
    private function check(array $payload): array
    {
        return $this->post('/api/signup/v1/slug', $payload, self::CONFIGURED_SECRET);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, array<string, mixed>}
     */
    private function post(string $path, array $payload, ?string $secret): array
    {
        // A client address of this test's own, so the shared per-kernel limiter
        // cannot make one test's traffic count against another's.
        $payload += ['client_ip' => '203.0.113.' . (crc32(static::class . $this->name()) % 254 + 1)];

        $this->client->request(
            'POST',
            sprintf('https://%s%s', $this->host, $path),
            server: $this->headers($secret),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $this->captureConfirmationUrl();

        return [$this->client->getResponse()->getStatusCode(), $this->body()];
    }

    /** @return array<string, string> */
    private function headers(?string $secret): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret === self::CONFIGURED_SECRET) {
            $secret = $this->secret;
        }

        if ($secret !== null) {
            $headers['HTTP_X_XIVI_SIGNUP_KEY'] = $secret;
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'every answer from this endpoint is a JSON object');

        return $decoded;
    }

    /**
     * The link out of the message that was really sent, rather than one rebuilt
     * here.
     *
     * Read at the end of the request that produced it and remembered, because
     * the message logger is a resettable service and the container's resetter
     * runs when a request terminates — so a URL fetched two requests later is
     * fetched out of an empty collection. Found the hard way, and it is worth a
     * paragraph: it looks exactly like "no mail was sent".
     */
    private function confirmationUrl(): string
    {
        self::assertNotNull($this->lastConfirmationUrl, 'no confirmation mail has been sent yet');

        return $this->lastConfirmationUrl;
    }

    /** @see confirmationUrl() for why this is captured per request rather than on demand */
    private function captureConfirmationUrl(): void
    {
        $messages = self::getMailerMessages();
        $message = end($messages);

        if (!$message instanceof Email) {
            return;
        }

        self::assertSame(
            1,
            preg_match('{https://\S+/signup/confirm/\S+}', (string) $message->getTextBody(), $found),
            'the confirmation mail carries exactly one link, on a line of its own',
        );

        $this->lastConfirmationUrl = $found[0];
    }

    private function urlFor(string $token): string
    {
        return sprintf('https://%s/signup/confirm/%s', $this->host, $token);
    }

    private function signup(string $email): SignupRequest
    {
        $signup = self::service(SignupRequestRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(SignupRequest::class, $signup, $email . ' should have a signup');

        return $signup;
    }

    /**
     * A registry row with no database behind it, exactly as `TenantListTest`
     * writes one: the question is whether the name is taken, and provisioning a
     * real customer to ask it would be slower and no more truthful.
     */
    private function createTenantFixture(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->persist(new Tenant(self::TAKEN_BY_A_TENANT, 'Already A Customer', 'postgresql://nowhere/x'));
        $manager->flush();
    }

    private function removeFixtures(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->clear();

        foreach ($manager->createQuery(
            'SELECT s FROM ' . SignupRequest::class . ' s WHERE s.email LIKE :suffix',
        )->setParameter('suffix', '%' . self::ADDRESS_SUFFIX)->toIterable() as $signup) {
            $manager->remove($signup);
        }

        // The free-mail signup, which is a perfectly ordinary row and therefore
        // not caught by the suffix above (XIV-125).
        $free = self::service(SignupRequestRepository::class)->findOneByEmail(self::FREE_MAIL_ADDRESS);

        if ($free instanceof SignupRequest) {
            $manager->remove($free);
        }

        // And the tally this class adds to. Removed by domain rather than by
        // emptying the table, because this class does not own it: the count is
        // what one of the tests above asserts, so it has to start from nothing
        // and leave nothing behind.
        $refusal = self::service(SignupRefusalRepository::class)->findOneBy(['domain' => self::THROWAWAY_DOMAIN]);

        if ($refusal instanceof SignupRefusal) {
            $manager->remove($refusal);
        }

        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::TAKEN_BY_A_TENANT);

        if ($tenant instanceof Tenant) {
            $manager->remove($tenant);
        }

        $manager->flush();
    }

    /** The configured secret, read from the same place the endpoint reads it. */
    private static function secret(): string
    {
        $key = self::service(SignupApiKey::class);
        self::assertTrue($key->isConfigured(), 'the suite runs with signup switched on');

        $secret = $_ENV['XIVI_SIGNUP_SECRET'] ?? '';
        self::assertIsString($secret);
        self::assertNotSame('', $secret);

        return $secret;
    }

    /**
     * @template T of object
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

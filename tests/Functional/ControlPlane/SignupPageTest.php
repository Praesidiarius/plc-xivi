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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RouterInterface;
use Xivi\ControlPlane\Controller\SignupPageController;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Entity\SignupStatus;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupClient;
use Xivi\ControlPlane\Signup\SignupError;
use Xivi\ControlPlane\Signup\SignupHost;
use Xivi\ControlPlane\Signup\SignupPage;
use Xivi\ControlPlane\Signup\SignupRateLimits;

/**
 * The landing page and its signup form (XIV-65).
 *
 * **The happy path is here and is still not the interesting part.** What this
 * class is for is the set of claims the page makes about itself, each of which
 * would be a paragraph in a docblock and nothing more if it were not asserted:
 *
 *   1. **The secret never reaches the browser.** Checked against the bytes of
 *      every response the page produces, rather than against the intention of the
 *      code that produces them.
 *   2. **The page really does go through the published contract.** Not by reading
 *      {@see SignupClient} and believing it: by spending a rate-limit bucket the
 *      *controller* consumes and watching the form be refused, and by building a
 *      client with no secret and watching the endpoint turn it away. Both of those
 *      are impossible if the page is quietly calling the intake directly.
 *   3. **The visitor's own address is what the limiter counts**, not this
 *      installation's, which is the difference between a bucket per visitor and a
 *      single counter for the internet.
 *   4. **The error vocabulary reaches a human as something they can act on** — the
 *      three the ticket names, in words, from the `landing` catalogue.
 *
 * The two switched-off states are asserted in `SignupRouteLoaderTest` rather than
 * here, for the reason that test already gives: the claim is about the routing
 * table, and booting a second kernel in one environment shares a compiled matcher
 * with the first.
 *
 * **The control plane is not rolled back between tests** (see
 * `config/packages/test/dama_doctrine_test.yaml`), so fixtures are removed by hand
 * at both ends, and every test uses its own address and its own client address so
 * that none of them can spend a bucket another depends on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupPageTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string COMPANY = 'Landing Page AG';

    /** Every address this class creates starts with this, so cleanup can find them. */
    private const string ADDRESS_SUFFIX = '@xiv65.test';

    /** A slug both slug rules accept, so it can stand in for a real tenant's. */
    private const string TAKEN_BY_A_TENANT = 'xiv65taken';

    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request: several tests read what was
        // written afterwards, and a rebooted kernel hands back one that never saw
        // it.
        $this->client->disableReboot();

        $this->host = self::service(SignupHost::class)->normalisedHost();

        self::assertTrue(
            self::service(SignupPage::class)->isEnabled(),
            'the suite runs with the landing page switched on',
        );

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    /**
     * The page, the form, and the address the visitor is being offered.
     */
    public function testTheLandingPageIsServedOnTheSignupHost(): void
    {
        $crawler = $this->client->request('GET', $this->url('/'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/signup"]'), 'one form, and it posts to the page');
        self::assertCount(1, $crawler->filter('input[name="company"]'));
        self::assertCount(1, $crawler->filter('input[name="slug"]'));
        self::assertCount(1, $crawler->filter('input[name="email"]'));

        // The domain the name will sit under, which is what makes a bare word
        // read as an address. `signup.e2e` in the suite, so the parent is `e2e` —
        // see SignupPage::tenantDomain() for why the first label is dropped and
        // why this is a display hint rather than a promise. The suite's signup
        // hostname is the one the browser container can resolve rather than a
        // `*.localhost` name; XIV-105 and `.env.test` say why.
        self::assertSelectorTextContains('.input-group-text', '.' . self::service(SignupPage::class)->tenantDomain());
    }

    /**
     * The shared secret is not in the page, and this asserts it against the bytes.
     *
     * §8.12's whole argument for a server-side integration is that the alternative
     * puts the credential in the page's source. This installation's own page is
     * the reference implementation of that integration, so the claim has to hold
     * for it first — and a docblock saying so is not a thing that fails a build.
     *
     * Every route the page owns is checked, in both of the states each of them
     * has, because a value that is absent from a rendered form and present in an
     * error path is absent from exactly the page nobody was looking at.
     */
    public function testTheSecretNeverReachesTheBrowser(): void
    {
        $secret = self::secret();

        $responses = [];

        $this->client->request('GET', $this->url('/'));
        $responses['the form'] = $this->body();

        $this->client->request('POST', $this->url('/signup/name'), ['company' => self::COMPANY]);
        $responses['a name check'] = $this->body();

        $this->client->request('POST', $this->url('/signup'), [
            'company' => self::COMPANY,
            'email' => 'secrecy' . self::ADDRESS_SUFFIX,
        ]);
        $responses['an accepted submission'] = $this->body();

        $this->client->request('POST', $this->url('/signup'), [
            'company' => self::COMPANY,
            'email' => 'not-an-address',
        ]);
        $responses['a refused submission'] = $this->body();

        foreach ($responses as $what => $body) {
            self::assertStringNotContainsString($secret, $body, 'the secret is in ' . $what);
            self::assertStringNotContainsString(SignupApiKey::HEADER, $body, 'the header name is named in ' . $what);
        }

        // And nothing invites a browser to call the intake itself, which is the
        // other half of the same decision: there is no CORS anywhere in this
        // feature, so a page that tried would be refused by the browser rather
        // than merely inadvisable.
        self::assertStringNotContainsString('/api/signup/v1/', $responses['the form']);
    }

    /**
     * **The page goes through the front door**, proven by the endpoint refusing a
     * caller that has no secret.
     *
     * A client built exactly as the page's is, minus the credential, is turned
     * away with the published `unauthorized` code. That is only possible if the
     * request really is being authenticated by {@see \Xivi\ControlPlane\Controller\SignupApiController}
     * — a page reaching past it into {@see \Xivi\ControlPlane\Signup\SignupIntake}
     * would have recorded the signup and never seen a header at all.
     */
    public function testTheIntakeRefusesThePagesOwnClientWhenItHoldsNoSecret(): void
    {
        $anonymous = new SignupClient(
            self::service(HttpKernelInterface::class),
            new SignupApiKey(''),
            self::service(SignupHost::class),
        );

        $outcome = $anonymous->submit(
            'nosecret' . self::ADDRESS_SUFFIX,
            self::COMPANY,
            '',
            'standard',
            'en',
            '203.0.113.10',
        );

        self::assertFalse($outcome->isAccepted());
        self::assertSame(SignupError::Unauthorized, $outcome->error);
        self::assertEmailCount(0, 'a refused call sends nothing');
        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail('nosecret' . self::ADDRESS_SUFFIX),
            'and writes nothing',
        );
    }

    /**
     * One signup, from the form to the confirmed row.
     *
     * The name is derived by the server and shown back, which is the whole point
     * of the ticket, and the address that comes out is the address the preview
     * promised — see `SignupEndpointTest::testThePreviewedNameIsTheNameThatGetsCreated()`
     * for the same claim asserted at the contract, and XIV-100 for why it was
     * worth a test at both levels.
     */
    public function testAVisitorCanSignUpFromTheFormAndConfirm(): void
    {
        $email = 'happy' . self::ADDRESS_SUFFIX;

        [, $preview] = $this->checkName(['company' => 'Bäckerei Müller']);
        self::assertSame('baeckerei-mueller', $preview['slug']);
        self::assertTrue($preview['available']);

        $this->client->request('POST', $this->url('/signup'), [
            'company' => 'Bäckerei Müller',
            'slug' => '',
            'email' => $email,
            'plan' => 'standard',
        ]);

        self::assertResponseIsSuccessful();
        // `no-store` among whatever else `Response::prepare()` decided to add: the
        // claim is that this page is not kept, not that we own the whole header.
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSelectorTextContains('body', $email, 'the page says where the confirmation went');
        self::assertSelectorTextContains('body', 'baeckerei-mueller', 'and what they will be called');
        self::assertCount(0, $this->client->getCrawler()->filter('form'), 'the form is gone once it has worked');

        // The mail is the intake's, not the page's, and it went out because the
        // page really did reach the intake.
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailAddressContains($message, 'To', $email);

        $signup = self::service(SignupRequestRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(SignupRequest::class, $signup);
        self::assertSame('baeckerei-mueller', $signup->getSlug(), 'the previewed name is the recorded one');
        self::assertSame(SignupStatus::Pending, $signup->getStatus());

        // And the link in that message lands back on the same hostname the form
        // was on, which is the reason the page does not have a host of its own.
        self::assertSame(
            1,
            preg_match('{https://\S+/signup/confirm/\S+}', (string) $message->getTextBody(), $found),
            'the confirmation mail carries exactly one link',
        );
        self::assertStringStartsWith('https://' . $this->host . '/', $found[0]);

        $this->client->request('GET', $found[0]);
        self::assertResponseIsSuccessful();

        self::service(EntityManagerInterface::class)->clear();
        $signup = self::service(SignupRequestRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(SignupRequest::class, $signup);
        self::assertSame(SignupStatus::Confirmed, $signup->getStatus());

        // Nothing was provisioned. That is [XIV-98]'s work and it has not run —
        // and the page has no more power to cause it than any other caller.
        self::assertNull(self::service(TenantRepository::class)->findOneBySlug('baeckerei-mueller'));
    }

    /**
     * The name check, which is the live half of the form.
     *
     * Both answers, because the sentence is what the visitor reads and the two are
     * written differently on purpose: one names the address, the other names the
     * action to take and pointedly not the reason.
     */
    public function testTheNameCheckAnswersInWordsAVisitorCanAct(): void
    {
        [$status, $free] = $this->checkName(['company' => self::COMPANY]);

        self::assertSame(Response::HTTP_OK, $status);
        self::assertSame('landing-page-ag', $free['slug'], 'derived by the server, never by the page');
        self::assertTrue($free['available']);
        self::assertStringContainsString('landing-page-ag', (string) $free['message']);

        $this->createTenantFixture();

        [, $taken] = $this->checkName(['company' => 'Anything', 'slug' => self::TAKEN_BY_A_TENANT]);

        self::assertFalse($taken['available']);
        self::assertSame('That address is taken. Try another one.', $taken['message']);

        // **And it does not say why.** The endpoint answers one word for "a
        // customer has it", "a confirmed signup is holding it" and "the platform
        // keeps it"; a page that distinguished them would undo that from outside.
        [, $reserved] = $this->checkName(['company' => 'Anything', 'slug' => 'www']);
        self::assertSame($taken['message'], $reserved['message'], 'two reasons, one indistinguishable sentence');

        // `no-store` among whatever else `Response::prepare()` decided to add: the
        // claim is that this page is not kept, not that we own the whole header.
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /**
     * **Slug taken**, the first of the three the acceptance criteria name, on the
     * submission rather than on the preview — because nothing is held until a
     * confirmation, so this is the refusal a visitor meets after being told the
     * name was free.
     */
    public function testANameThatHasGoneIsExplainedOnTheForm(): void
    {
        $this->createTenantFixture();

        $crawler = $this->client->request('POST', $this->url('/signup'), [
            'company' => self::COMPANY,
            'slug' => self::TAKEN_BY_A_TENANT,
            'email' => 'taken' . self::ADDRESS_SUFFIX,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSelectorTextContains('.alert-danger', 'That address is taken. Try another one.');

        // The form comes back with what was typed still in it, which is why this
        // renders in place rather than redirecting: the most likely refusal is
        // also the one where losing the form would be worst.
        self::assertSame(self::COMPANY, $crawler->filter('input[name="company"]')->attr('value'));
        self::assertSame('taken' . self::ADDRESS_SUFFIX, $crawler->filter('input[name="email"]')->attr('value'));

        self::assertEmailCount(0, 'a refused signup sends nothing');
    }

    /**
     * **Address already registered**, the second, and the one whose sentence has
     * to say something other than "try again" — trying again is exactly what will
     * not work.
     */
    public function testAnAddressThatAlreadyHasAnInstallationOnTheWayIsExplained(): void
    {
        $email = 'confirmed' . self::ADDRESS_SUFFIX;

        $this->client->request('POST', $this->url('/signup'), [
            'company' => self::COMPANY,
            'email' => $email,
        ]);
        self::assertResponseIsSuccessful();

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('{https://\S+/signup/confirm/\S+}', (string) $message->getTextBody(), $found));
        $this->client->request('GET', $found[0]);

        $this->client->request('POST', $this->url('/signup'), [
            'company' => 'A Second Company AG',
            'email' => $email,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSelectorTextContains('.alert-danger', 'already has an installation on the way');
    }

    /**
     * **Rate limited**, the third — and the test that also proves the two things
     * the page's design rests on.
     *
     * The bucket is spent *through the limiter service*, for the visitor's own
     * address and a different email each time, and then the form is submitted from
     * that address with a fresh one. It is refused, and both halves of that are
     * claims worth making:
     *
     *   * **The submission went through the API controller**, because that is
     *     where the limiter is consumed. A page calling the intake directly would
     *     have sailed past a spent bucket.
     *   * **The address forwarded is the visitor's**, not this installation's. A
     *     page that forgot to forward it would be counted under the sub-request's
     *     own `127.0.0.1`, which is a different bucket, and this would pass when
     *     it should not.
     *
     * The sentence says how long to wait, because a person told to stop and not
     * told when either gives up or reloads immediately.
     */
    public function testTooManyAttemptsFromOneVisitorAreExplainedWithAWait(): void
    {
        $visitor = '203.0.113.65';
        $limits = self::service(SignupRateLimits::class);

        // Thirty is `signup_client`'s hourly allowance. Spent in-process because
        // every cache pool carries `kernel.reset` and the container's resetter
        // runs when a request terminates — so a loop of requests would be a loop
        // of first attempts. SignupEndpointTest has the same paragraph and found
        // it the same way.
        for ($i = 0; $i < 30; ++$i) {
            $limits->consumeForSubmission(sprintf('flood%d%s', $i, self::ADDRESS_SUFFIX), $visitor);
        }

        $this->client->request(
            'POST',
            $this->url('/signup'),
            ['company' => self::COMPANY, 'email' => 'limited' . self::ADDRESS_SUFFIX],
            server: ['REMOTE_ADDR' => $visitor],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSelectorTextContains('.alert-danger', 'Too many attempts from here');
        self::assertSelectorTextContains('.alert-danger', 'minutes');

        self::assertEmailCount(0);
        self::assertNull(
            self::service(SignupRequestRepository::class)->findOneByEmail('limited' . self::ADDRESS_SUFFIX),
            'a refused submission writes nothing',
        );
    }

    /**
     * An address that is not one is refused by the intake's own validator and
     * comes back as a sentence rather than as a 500.
     */
    public function testAnAddressThatIsNotOneIsExplained(): void
    {
        $this->client->request('POST', $this->url('/signup'), [
            'company' => self::COMPANY,
            'email' => 'not-an-address',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSelectorTextContains('.alert-danger', 'does not look like an email address');
    }

    /**
     * **The page exists on one hostname and nowhere else**, and that is the router
     * rather than a rule: the routes carry the configured host, so the same path
     * on a customer's hostname or the control plane's is not a route at all.
     *
     * The control-plane host is the one worth naming. A landing page there would
     * aim the internet at the console that answers to the people who can see every
     * customer, which is the objection §8.12 raises about the endpoint and which
     * applies unchanged to the page that calls it.
     */
    public function testThePageIsNotServedOnAnyOtherHost(): void
    {
        $elsewhere = ['localhost', self::service(ControlPlaneHost::class)->normalisedHost()];

        foreach ($elsewhere as $host) {
            foreach (['/signup', '/signup/name'] as $path) {
                $this->client->request('POST', sprintf('https://%s%s', $host, $path));

                self::assertResponseStatusCodeSame(
                    Response::HTTP_NOT_FOUND,
                    sprintf('%s on %s', $path, $host),
                );
            }

            // `/` is asserted differently, and the difference is the reason
            // `config/routes.yaml` imports the signup loader first. The
            // application's dashboard is at `/` on every hostname, so the
            // question here is not whether *a* route matches — one always will —
            // but whether it is this one. It must not be: a landing page on a
            // customer's hostname would be a signup form drawn over somebody's
            // installation.
            $this->client->request('GET', sprintf('https://%s/', $host));

            // Which route matched, rather than what came back. The two hosts
            // answer `/` completely differently — one redirects to a sign-in
            // page, the other refuses before routing has even run — and neither
            // of those answers is the claim. The claim is that `signup_page` is
            // not what served it.
            self::assertNotSame(
                'signup_page',
                $this->client->getRequest()->attributes->get('_route'),
                'the landing page was drawn on ' . $host,
            );
        }
    }

    /**
     * **Every signup route in the compiled table came from the loader**, which is
     * what makes the two switches mean anything at all.
     *
     * This is the assertion that would have caught a defect XIV-65 found in
     * XIV-64's own acceptance criterion, so it is worth saying what it is rather
     * than only what is being checked. Symfony autoconfigures every class with a
     * `#[Route]` attribute into `routing.controllers`, which loaded the signup
     * controllers a second time **with no host and no scheme**. Route names are
     * unique, so the surviving copy was whichever loaded last — and that was
     * decided by the order of two keys in `config/routes.yaml`. With
     * `SIGNUP_HOST` empty, `debug:router` still listed all six routes, on every
     * hostname, over plain HTTP.
     *
     * `SignupRouteLoaderTest` could not have seen it: it asks the loader, and the
     * loader was right. So the claim has to be made against the *router*, and the
     * shape of it is that every route whose name begins `signup_` carries the
     * configured host and `https` — because nothing but the loader stamps those,
     * and anything else that registered one would show up here bare.
     */
    public function testEverySignupRouteInTheRouterCameFromTheLoader(): void
    {
        $routes = self::service(RouterInterface::class)->getRouteCollection();

        $found = [];

        foreach ($routes as $name => $route) {
            if (!str_starts_with($name, 'signup_')) {
                continue;
            }

            $found[] = $name;

            self::assertSame($this->host, $route->getHost(), $name . ' is not bound to the signup host');
            self::assertSame(['https'], $route->getSchemes(), $name . ' is not confined to TLS');
        }

        sort($found);

        self::assertSame(
            [
                'signup_api_v1_request',
                'signup_api_v1_slug',
                'signup_confirm',
                'signup_page',
                'signup_page_name',
                'signup_page_submit',
            ],
            $found,
            'the routing table holds exactly what the loader returns, and nothing has added to it',
        );
    }

    /**
     * The controller renders and forwards and does nothing else, which is what
     * makes the paragraph above about privileges true rather than aspirational.
     *
     * The same instrument `SignupEndpointTest` points at the endpoint, pointed at
     * the page: it walks the constructor graph and asserts that neither the
     * provisioner nor `TENANT_ADMIN_DSN` is reachable. Its honest limit is the
     * same one — it follows concrete classes and stops at interfaces — and so is
     * the reason that is enough: autowiring can only inject `TenantProvisioner` by
     * its own final class name.
     */
    public function testThePageHoldsNoCredentialThatCanCreateADatabase(): void
    {
        $visited = [];
        $queue = [SignupPageController::class];

        while ($queue !== []) {
            $class = array_shift($queue);

            if (isset($visited[$class])) {
                continue;
            }

            $visited[$class] = true;

            self::assertStringNotContainsString(
                'TenantProvisioner',
                $class,
                'the landing page must not be able to reach provisioning',
            );

            $constructor = new \ReflectionClass($class)->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
                    $queue[] = $type->getName();
                }
            }
        }

        self::assertGreaterThan(5, \count($visited), 'the walk reached something rather than stopping at the door');
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{int, array<string, mixed>}
     */
    private function checkName(array $payload): array
    {
        $this->client->request(
            'POST',
            $this->url('/signup/name'),
            $payload,
            server: ['REMOTE_ADDR' => '203.0.113.' . (crc32(static::class . $this->name()) % 254 + 1)],
        );

        $decoded = json_decode($this->body(), true);
        self::assertIsArray($decoded, 'the name check answers JSON');

        return [$this->client->getResponse()->getStatusCode(), $decoded];
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', $this->host, $path);
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** The configured secret, read from the same environment the page reads it from. */
    private static function secret(): string
    {
        $secret = $_ENV['XIVI_SIGNUP_SECRET'] ?? '';
        self::assertIsString($secret);
        self::assertNotSame('', $secret, 'the suite runs with signup switched on');

        return $secret;
    }

    /**
     * A registry row with no database behind it, exactly as `SignupEndpointTest`
     * writes one: the question is whether a name is taken, and provisioning a real
     * customer to ask it would be slower and no more truthful.
     */
    private function createTenantFixture(): void
    {
        $manager = self::service(EntityManagerInterface::class);

        if (self::service(TenantRepository::class)->findOneBySlug(self::TAKEN_BY_A_TENANT) === null) {
            $manager->persist(new Tenant(self::TAKEN_BY_A_TENANT, 'Already A Customer', 'postgresql://nowhere/x'));
            $manager->flush();
        }
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

        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::TAKEN_BY_A_TENANT);

        if ($tenant instanceof Tenant) {
            $manager->remove($tenant);
        }

        $manager->flush();
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

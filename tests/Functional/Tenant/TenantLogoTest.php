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

namespace App\Tests\Functional\Tenant;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\TenantProfile;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\LogoFormat;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Core\Permission\PermissionScope;

/**
 * A customer's own mark, on their pages, their sign-in page and nobody else's
 * (XIV-49).
 *
 * Three things are being proved and only one of them is "the upload works".
 *
 * The second is the **exception**. Serving this route without a session is a
 * deliberate narrowing of §8.4 and the only one there is: a logo is a public mark
 * and the sign-in page has nobody signed in to check a permission against. An
 * exception is only defensible while it stays the size it was argued at, so what
 * is tested is the boundary rather than the feature — that tenancy still applies,
 * that a system host gets nothing, and above all that the *rest* of the row this
 * logo shares with the SMTP credentials does not come out of the same door.
 *
 * The third is the **cache**. The mark is on every page, comes out of the
 * database and changes almost never, so it is cached for a year — and a year-long
 * cache that outlives a replacement is a customer looking at their old logo,
 * concluding the upload failed, and uploading it again. The fingerprint in the
 * URL is what makes both true at once, and the test that matters is the one where
 * somebody replaces it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantLogoTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_tenant_logo';
    private const string HOST = 'logo.localhost';
    private const string ADMIN = 'admin@logo.test';
    private const string MEMBER = 'member@logo.test';
    private const string PASSWORD = 'a-long-enough-password';
    private const string PATH = '/settings/profile';

    /**
     * A one-pixel PNG, written out rather than generated.
     *
     * ext-gd is not a dependency of this application and the check it would be
     * exercising — `getimagesizefromstring` — is core PHP, so a fixture built by
     * an extension that may not be installed would be testing the wrong thing on
     * the wrong machine. These bytes are a real PNG and two of them differ, which
     * is the whole of what the tests need.
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const string OTHER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<string> temporary uploads to clear up after each test */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    // -- the screen ---------------------------------------------------------

    public function testACustomerUploadsAMarkOnTheirProfileAndItIsKeptInTheirOwnDatabase(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);

        $profile = $this->profile();

        self::assertTrue($profile->hasLogo());
        self::assertSame(self::bytes(self::PNG), $profile->getLogo());
        self::assertSame('image/png', $profile->getLogoContentType());
        // The fingerprint is derived from the bytes and nothing else, which is
        // what lets the address it forms be treated as immutable.
        self::assertSame(hash('sha256', self::bytes(self::PNG)), $profile->getLogoFingerprint());
    }

    public function testItAppearsInTheirTopBar(): void
    {
        $this->signIn(self::ADMIN);

        $before = $this->client->request('GET', $this->url('/'))->filter('.navbar-brand img');
        self::assertCount(0, $before, 'nothing to draw before anybody has uploaded one');

        $this->upload(self::PNG);

        $after = $this->client->request('GET', $this->url('/'))->filter('.navbar-brand img');

        self::assertCount(1, $after);
        self::assertSame($this->logoPath(), $after->attr('src'));
        // Decorative there, because the company name is printed right beside it —
        // see _brand_mark.html.twig.
        self::assertSame('', $after->attr('alt'));
    }

    /**
     * The page the white-label claim is either true on or not.
     *
     * The mark is drawn before anybody has signed in, which works because
     * TenantRequestListener resolves the tenant from the Host header at priority
     * 100 — before authentication. The `alt` is the company name here and empty
     * in the bar, and that is not an inconsistency: on this page nothing else
     * says what the company is called, because the heading below the card is the
     * hostname, which is an address rather than a name.
     */
    public function testItAppearsOnTheLoginPageOfTheirOwnHostname(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'logo' => $this->fileOf(self::PNG)]);

        $this->client->getCookieJar()->clear();
        $card = $this->client->request('GET', $this->url('/login'))->filter('.card-body img');

        self::assertCount(1, $card);
        self::assertSame($this->logoPath(), $card->attr('src'));
        self::assertSame('Acme AG', $card->attr('alt'));
    }

    /**
     * A system host resolves no tenant, so there is nobody's mark to show.
     *
     * It falls back to the instance's own (XIV-48), which in a clean checkout and
     * in CI is *also* absent — so what is provable here is that the customer's
     * one does not leak onto a page that is not theirs. That is the half worth
     * proving anyway: the fallback being empty is XIV-48's business.
     */
    public function testASystemHostShowsNoTenantMark(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);

        $this->client->getCookieJar()->clear();
        $card = $this->client->request('GET', 'https://localhost/login')->filter('.card-body img');

        self::assertResponseIsSuccessful();

        foreach ($card->extract(['src']) as [$src]) {
            self::assertStringNotContainsString('/logo/', (string) $src);
        }
    }

    public function testItCanBeReplacedAndRemovedOnTheSameScreen(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);
        $this->upload(self::OTHER_PNG);

        self::assertSame(self::bytes(self::OTHER_PNG), $this->profile()->getLogo());

        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['logo_remove' => '1']);

        $profile = $this->profile();

        // All three facts go together: a row claiming a logo it no longer has
        // would answer the serving route with an empty image.
        self::assertFalse($profile->hasLogo());
        self::assertNull($profile->getLogo());
        self::assertNull($profile->getLogoContentType());
        self::assertNull($profile->getLogoFingerprint());
    }

    /** A save that is about something else must not quietly drop the mark. */
    public function testSavingTheRestOfTheFormLeavesTheMarkAlone(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);

        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'currency' => 'CHF']);

        self::assertSame(self::bytes(self::PNG), $this->profile()->getLogo());
    }

    // -- what is accepted ---------------------------------------------------

    /**
     * SVG is the one everybody wants and the one this refuses.
     *
     * It is a document rather than an image — it can carry script — and the route
     * below serves it to people who have not signed in, from the customer's own
     * origin. Sanitizing it is the only safe way to accept it and the only
     * credible PHP sanitizer is GPL-2.0-or-later, which an MIT project that has
     * already turned down PHPWord over LGPL is not going to take on for a logo.
     * See LogoFormat.
     */
    public function testAnSvgIsRefusedAndNothingIsStored(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';

        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'logo' => $this->file($svg, 'logo.svg'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');
        self::assertFalse($this->profile()->hasLogo());

        // And the refusal happened before anything was written, which is the rule
        // the mail settings already keep: a page saying it failed must not have
        // half-saved the form it is redrawing.
        self::assertSame('', $this->profile()->getCompanyName());
    }

    /** The extension is not what decides; the bytes are. */
    public function testSomethingRenamedToPngIsStillNotAnImage(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $this->client->submitForm('Save', ['logo' => $this->file('%PDF-1.4 not really', 'logo.png')]);

        self::assertSelectorExists('.alert-danger');
        self::assertFalse($this->profile()->hasLogo());
    }

    public function testSomethingOverTheSizeCeilingIsRefused(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        // A real PNG with enough padding after it to break the ceiling, so what
        // is being refused is genuinely the size and not the format.
        $huge = self::bytes(self::PNG) . str_repeat("\0", LogoFormat::MAX_BYTES);

        $this->client->submitForm('Save', ['logo' => $this->file($huge, 'logo.png')]);

        self::assertSelectorExists('.alert-danger');
        self::assertFalse($this->profile()->hasLogo());
    }

    // -- serving it ---------------------------------------------------------

    /**
     * The exception, stated: reachable with no session at all.
     *
     * It has to be — it is drawn on the sign-in page — and an `<img>` that 302s to
     * the login form is a broken image rather than a security control.
     */
    public function testTheMarkIsServedWithoutSigningIn(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);
        $path = $this->logoPath();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->url($path));

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame(self::bytes(self::PNG), $this->client->getResponse()->getContent());
    }

    /**
     * And nothing *else* about that row comes out of the same door.
     *
     * This is the assertion the exception lives or dies on. The logo shares
     * `tenant_profile` with the company name, the currency, the payment terms and
     * — the one that would actually matter — the SMTP host, user and encrypted
     * password. Making one column of that row public is only defensible while it
     * is one column, so the body is compared for equality with the bytes rather
     * than merely searched: an image plus anything is not an image.
     */
    public function testNothingElseAboutTheProfileIsReadableThroughThatRoute(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'currency' => 'CHF',
            'payment_terms_days' => '30',
            'mail_sender_address' => 'billing@acme.test',
            'mail_smtp_host' => 'smtp.acme.test',
            'mail_smtp_user' => 'acme-smtp-user',
            'mail_smtp_password' => 'hunter2',
            'logo' => $this->fileOf(self::PNG),
        ]);

        $path = $this->logoPath();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->url($path));

        $response = $this->client->getResponse();

        self::assertSame(self::bytes(self::PNG), $response->getContent());

        $whole = (string) $response . (string) $response->getContent();

        foreach (['Acme AG', 'CHF', 'smtp.acme.test', 'acme-smtp-user', 'billing@acme.test', 'hunter2'] as $secret) {
            self::assertStringNotContainsString($secret, $whole);
        }

        // And the page those facts *do* live on is still behind a session, so the
        // public route is the exception rather than the beginning of one.
        $this->client->request('GET', $this->url(self::PATH));
        self::assertResponseRedirects();
    }

    /**
     * Tenancy still applies, which is the half of §8.4 that was not given up.
     *
     * A system host resolves no tenant, so there is no profile to read and no
     * question of reading somebody's by guessing a fingerprint.
     */
    public function testAHostWithNoTenantHasNoMarkToServe(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);
        $path = $this->logoPath();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://localhost' . $path);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnInstallationWithNoMarkAnswersNotFound(): void
    {
        $this->client->request('GET', $this->url('/logo/' . str_repeat('a', 64)));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // -- the cache ----------------------------------------------------------

    /**
     * A replaced mark is never served from a cache — the acceptance criterion,
     * and the reason the fingerprint is in the URL at all.
     *
     * Three things have to hold together for that to be true, so all three are
     * here. The page starts pointing somewhere new, so a browser holding the old
     * bytes never asks for them again. The new address serves the new bytes and
     * says they may be kept for a year. And the *old* address — which a page
     * cached before the change may still be asking for — serves the new bytes
     * with `no-store`, because caching them under an address that has already
     * meant something else is exactly the promise `immutable` must not break.
     */
    public function testAReplacedMarkIsNotServedFromACacheAfterwards(): void
    {
        $this->signIn(self::ADMIN);
        $this->upload(self::PNG);

        $stale = $this->logoPath();

        $this->upload(self::OTHER_PNG);

        $fresh = $this->logoPath();

        self::assertNotSame($stale, $fresh, 'a different mark has to be a different address');

        // The bar has moved on: nothing on any page still points at the old one.
        $bar = $this->client->request('GET', $this->url('/'))->filter('.navbar-brand img');
        self::assertSame($fresh, $bar->attr('src'));

        $this->client->request('GET', $this->url($fresh));

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertSame(self::bytes(self::OTHER_PNG), $this->client->getResponse()->getContent());
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('public', $cacheControl);

        // The old address answers with the new bytes and forbids keeping them.
        $this->client->request('GET', $this->url($stale));

        self::assertSame(self::bytes(self::OTHER_PNG), $this->client->getResponse()->getContent());
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
            'an address that has already meant something else may not be cached',
        );
    }

    // -- who may change it --------------------------------------------------

    /**
     * The same grant that changes the company name, and no new one.
     *
     * A logo is the other half of the same answer — what the installation is
     * called, and what that looks like — set on the same screen in the same
     * submission. A permission of its own would be a second thing to grant to
     * everybody who already has the first, which is how permission catalogues
     * become the thing nobody maintains.
     */
    public function testChangingItNeedsTheSameGrantAsChangingTheCompanyName(): void
    {
        $this->signIn(self::ADMIN);
        $this->grantToTheMemberGroup(['grants[@profile][view]' => PermissionScope::All->value]);

        $this->signIn(self::MEMBER);
        $crawler = $this->client->request('GET', $this->url(self::PATH));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Save'), 'nothing to submit without the edit grant');

        $this->client->request(
            'POST',
            $this->url(self::PATH),
            ['_token' => 'whatever'],
            ['logo' => $this->uploadOf(self::PNG)],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->profile()->hasLogo());
    }

    public function testTheEditGrantIsWhatLetsSomebodyUploadOne(): void
    {
        $this->signIn(self::ADMIN);
        $this->grantToTheMemberGroup([
            'grants[@profile][view]' => PermissionScope::All->value,
            'grants[@profile][edit]' => PermissionScope::All->value,
        ]);

        $this->signIn(self::MEMBER);
        $this->upload(self::PNG);

        self::assertTrue($this->profile()->hasLogo());
    }

    // -- helpers ------------------------------------------------------------

    /** Loads the profile page and submits one file through the real form. */
    private function upload(string $base64): void
    {
        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['logo' => $this->fileOf($base64)]);
    }

    /** The path the application currently points at for this tenant's mark. */
    private function logoPath(): string
    {
        return '/logo/' . $this->profile()->getLogoFingerprint();
    }

    private function fileOf(string $base64): string
    {
        return $this->file(self::bytes($base64), 'logo.png');
    }

    /** A real file on disk, because that is what a browser sends and DomCrawler takes. */
    private function file(string $contents, string $name): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('xivi-logo-', true) . '-' . $name;
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }

    /** The same file, as the request-level upload a hand-built POST needs. */
    private function uploadOf(string $base64): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        return new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $this->fileOf($base64),
            'logo.png',
            'image/png',
            null,
            true,
        );
    }

    private static function bytes(string $base64): string
    {
        return (string) base64_decode($base64, true);
    }

    private function profile(): TenantProfile
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): TenantProfile => self::service(TenantProfileRepository::class)->current(),
        );
    }

    /** @param array<string, string> $grants */
    private function grantToTheMemberGroup(array $grants): void
    {
        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'Office']);
        $this->client->followRedirect();

        $id = (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
        $member = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): int {
            $user = self::service(UserRepository::class)->findOneByEmail(self::MEMBER);
            self::assertNotNull($user);

            return (int) $user->getId();
        });

        $this->client->request('GET', $this->url('/users/groups/' . $id));
        $this->client->submitForm('Save', [
            'label' => 'Office',
            sprintf('members[%d]', $member) => (string) $member,
            ...$grants,
        ]);
    }

    private function signIn(string $email): void
    {
        // Several of these grant something as the administrator and come back as
        // the person it was granted to, and signing in over a live session lands
        // on the dashboard instead of the form.
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

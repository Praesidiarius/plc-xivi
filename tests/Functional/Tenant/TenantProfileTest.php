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

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\TenantProfile;
use App\Tenant\Entity\User;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\PermissionScope;

/**
 * The instance's own settings, and the first permission that belongs to no
 * module (XIV-12).
 *
 * Two things are being proved together, because they only mean anything
 * together: that a customer can say what they are called and what they price in,
 * and that saying so is a grant somebody can be given without being handed a
 * module or made an administrator.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantProfileTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_tenant_profile';
    private const string HOST = 'profile.localhost';
    private const string ADMIN = 'admin@profile.test';
    private const string MEMBER = 'member@profile.test';
    private const string PASSWORD = 'a-long-enough-password';
    private const string PATH = '/settings/profile';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        // Installed so the group screen has a module to draw beside the areas —
        // the two halves of the catalogue are meant to sit on one page.
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
    }

    /** Default deny reaches here too: a profile is not something everybody gets. */
    public function testSomebodyWithNoGrantsCannotSeeIt(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url(self::PATH));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAdministratorCanSayWhatTheCompanyIsCalledAndWhatItPricesIn(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        self::assertResponseIsSuccessful();

        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'currency' => 'CHF',
        ]);

        self::assertResponseRedirects($this->url(self::PATH));

        $profile = $this->profile();

        self::assertSame('Acme AG', $profile->getCompanyName());
        self::assertSame('CHF', $profile->getCurrency());
    }

    /** The name they chose, rather than the one the operator filed them under. */
    public function testTheCompanyNameReplacesTheRegistryLabelInTheBar(): void
    {
        $this->signIn(self::ADMIN);

        $before = $this->client->request('GET', $this->url('/'))->filter('.navbar-brand')->text();
        self::assertSame($this->tenant->getName(), trim($before));

        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'currency' => 'CHF']);

        $after = $this->client->request('GET', $this->url('/'))->filter('.navbar-brand')->text();

        self::assertSame('Acme AG', trim($after));
    }

    /**
     * The point of the area being a grant: seeing the settings and deciding them
     * are separate jobs, and neither of them is "administrator".
     */
    public function testViewingAndChangingAreSeparateGrants(): void
    {
        $this->signIn(self::ADMIN);
        $this->grantToTheMemberGroup(['grants[@profile][view]' => PermissionScope::All->value]);

        $this->signIn(self::MEMBER);
        $crawler = $this->client->request('GET', $this->url(self::PATH));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Save'), 'nothing to submit without the edit grant');

        // And the route agrees with the page: posting anyway is refused.
        $this->client->request('POST', $this->url(self::PATH), [
            '_token' => 'whatever',
            'company_name' => 'Sneaky Ltd',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('', $this->profile()->getCompanyName());
    }

    public function testTheEditGrantIsWhatLetsSomebodySave(): void
    {
        $this->signIn(self::ADMIN);
        $this->grantToTheMemberGroup([
            'grants[@profile][view]' => PermissionScope::All->value,
            'grants[@profile][edit]' => PermissionScope::All->value,
        ]);

        $this->signIn(self::MEMBER);
        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'currency' => 'EUR']);

        self::assertResponseRedirects($this->url(self::PATH));
        self::assertSame('Acme AG', $this->profile()->getCompanyName());
        self::assertSame('EUR', $this->profile()->getCurrency());
    }

    /**
     * An area is not a module and a module is not an area.
     *
     * They share a column deliberately (§8.4), so the thing worth proving is that
     * sharing it grants nothing across: `@profile` cannot collide with a module
     * key, because a module key cannot start with `@`.
     */
    public function testAModuleGrantIsNotAProfileGrant(): void
    {
        $this->signIn(self::ADMIN);
        $this->grantToTheMemberGroup(['grants[contact][view]' => PermissionScope::All->value]);

        $this->signIn(self::MEMBER);
        $this->client->request('GET', $this->url(self::PATH));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('@', PermissionArea::Profile->value);
    }

    /**
     * The select is built from symfony/intl's own list, so a code that is not in
     * it came from a hand-edited request — and the answer to that is to change
     * nothing rather than to store nonsense.
     */
    public function testACurrencyNobodyHasHeardOfChangesNothing(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'currency' => 'CHF']);

        $crawler = $this->client->request('GET', $this->url(self::PATH));
        $this->client->request('POST', $this->url(self::PATH), [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'company_name' => 'Acme AG',
            'currency' => 'XYZ',
        ]);

        self::assertSame('CHF', $this->profile()->getCurrency());
    }

    /** Nobody has chosen is a real state, and the one every instance starts in. */
    public function testAFreshInstallationHasNeitherOfThem(): void
    {
        $profile = $this->profile();

        self::assertSame('', $profile->getCompanyName());
        self::assertNull($profile->getCurrency());
        // And no payment terms either, which is why nothing an untouched
        // installation sends is ever overdue (XIV-67).
        self::assertNull($profile->getPaymentTermsDays());
    }

    /**
     * Outgoing mail is configured here too (XIV-37), because it is the same kind
     * of fact as the company name: something the installation says about itself,
     * granted rather than personal. What it *means* is OutgoingMailTest's; this
     * is the page actually carrying it there and back.
     */
    public function testOutgoingMailIsConfiguredOnThisPage(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'currency' => 'CHF',
            'mail_sender_address' => 'billing@acme.test',
            'mail_smtp_host' => 'smtp.acme.test',
            'mail_smtp_port' => '587',
            'mail_smtp_user' => 'acme',
            'mail_smtp_password' => 'hunter2',
        ]);

        self::assertResponseRedirects($this->url(self::PATH));

        $profile = $this->profile();

        self::assertSame('billing@acme.test', $profile->getMailSenderAddress());
        self::assertSame('smtp.acme.test', $profile->getMailSmtpHost());
        self::assertSame(587, $profile->getMailSmtpPort());
        self::assertSame('acme', $profile->getMailSmtpUser());

        // Encrypted on the way in, and never rendered back out: the field is
        // blank on the next load whatever is stored.
        $stored = $profile->getEncryptedMailSmtpPassword();
        self::assertIsString($stored);
        self::assertStringNotContainsString('hunter2', $stored);

        $crawler = $this->client->request('GET', $this->url(self::PATH));
        self::assertSame('', $crawler->filter('#mail_smtp_password')->attr('value'));
    }

    /**
     * A server with no address to send as is refused rather than stored: our
     * domain may not claim theirs, so the address is what makes their server
     * usable at all (§8.7).
     */
    public function testAServerWithoutASenderAddressIsRefusedAndNothingIsSaved(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'mail_sender_address' => '',
            'mail_smtp_host' => 'smtp.acme.test',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');

        // Including the half of the form that would have been fine on its own:
        // the refusal happens before anything is written, so the page is telling
        // the truth when it implies nothing was saved.
        $profile = $this->profile();
        self::assertSame('', $profile->getMailSmtpHost());
        self::assertSame('', $profile->getCompanyName());
    }

    /** The area is offered on the group screen beside the modules. */
    public function testThePermissionScreenOffersTheAreaBesideTheModules(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroup();

        $crawler = $this->client->request('GET', $this->url('/users/groups/' . $id));

        self::assertCount(1, $crawler->filter('select[name="grants[@profile][view]"]'));
        self::assertCount(1, $crawler->filter('select[name="grants[@profile][edit]"]'));

        // Scope means nothing for a thing there is one of, so the cell is a yes
        // or a no and never "own".
        self::assertCount(
            0,
            $crawler->filter('select[name="grants[@profile][view]"] option[value="own"]'),
        );

        // And the verbs the area does not answer are not drawn at all.
        self::assertCount(0, $crawler->filter('select[name="grants[@profile][delete]"]'));
    }

    /**
     * How long customers get to pay is set here too (XIV-67), because it is the
     * same kind of fact as the currency: something the installation says about
     * itself, which a contact may then override and an invoice materialises once.
     * What it *means* is InvoiceDueDateTest's; this is the page carrying it.
     */
    public function testTheDefaultPaymentTermsAreSetOnThisPage(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $this->client->submitForm('Save', [
            'company_name' => 'Acme AG',
            'currency' => 'CHF',
            'payment_terms_days' => '30',
        ]);

        self::assertSame(30, $this->profile()->getPaymentTermsDays());
    }

    /**
     * Blank and zero are different answers and both are real: blank is "this
     * installation puts no due date on anything", zero is "payable on receipt".
     * A cast that read the empty box as 0 would give every customer the second
     * one without anybody choosing it.
     */
    public function testNoPaymentTermsIsNotTheSameAsZero(): void
    {
        $this->signIn(self::ADMIN);
        $this->client->request('GET', $this->url(self::PATH));

        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'payment_terms_days' => '0']);
        self::assertSame(0, $this->profile()->getPaymentTermsDays());

        $this->client->request('GET', $this->url(self::PATH));
        $this->client->submitForm('Save', ['company_name' => 'Acme AG', 'payment_terms_days' => '']);
        self::assertNull($this->profile()->getPaymentTermsDays());
    }

    // -- helpers ------------------------------------------------------------

    private function profile(): TenantProfile
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): TenantProfile => self::service(TenantProfileRepository::class)->current(),
        );
    }

    /**
     * A group holding the given cells, with the member in it.
     *
     * @param array<string, string> $grants
     */
    private function grantToTheMemberGroup(array $grants): void
    {
        $id = $this->createGroup();

        $this->client->request('GET', $this->url('/users/groups/' . $id));
        $this->client->submitForm('Save', [
            'label' => 'Office',
            sprintf('members[%d]', $this->user(self::MEMBER)->getId()) => (string) $this->user(self::MEMBER)->getId(),
            ...$grants,
        ]);
    }

    private function createGroup(): int
    {
        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'Office']);
        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private function user(string $email): User
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email): User {
            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            return $user;
        });
    }

    private function signIn(string $email): void
    {
        // Several of these tests grant something as the administrator and then
        // come back as the person it was granted to. Signing in over a live
        // session lands on the dashboard instead of the login form, so the
        // session goes first.
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

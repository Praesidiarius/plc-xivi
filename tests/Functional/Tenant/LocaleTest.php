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
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Reading the application in your own language (XIV-8).
 *
 * The chain has four links and only the last one is visible: a column, a
 * picker, a listener that reads it back on the next request, and a catalogue.
 * Any of them being wrong looks identical from the outside — an English page —
 * so this walks the whole thing rather than unit-testing the middle.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class LocaleTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_locale';
    private const string HOST = 'locale.localhost';
    private const string EMAIL = 'member@locale.test';
    private const string ADMIN = 'admin@locale.test';
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Member', self::PASSWORD, []);
        // The user list is administrators only, and that is where the icon-only
        // actions live.
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
    }

    /** English until somebody says otherwise. */
    public function testTheDefaultLanguageIsEnglish(): void
    {
        $this->signIn();

        $text = $this->client->request('GET', $this->url('/account'))->filter('main')->text();

        self::assertStringContainsString('Language', $text);
        self::assertStringNotContainsString('Sprache', $text);
    }

    /**
     * The whole chain: choose German, and the *next* page is German.
     *
     * Deliberately asserted on a following request rather than on the response
     * to the form. The listener reads the choice back off the user, so a
     * mechanism that only worked within the request that made the change would
     * pass a weaker test and fail every real use.
     */
    public function testChoosingGermanChangesTheLanguageOfLaterPages(): void
    {
        $this->signIn();

        $this->client->request('GET', $this->url('/account'));
        $this->client->submitForm('Save language', ['locale' => 'de']);

        $text = $this->client->request('GET', $this->url('/account'))->filter('main')->text();

        self::assertStringContainsString('Sprache', $text);

        // Asserted on a string that has actually been moved into the catalogues.
        // The rest of this page is still hardcoded English while the templates
        // are being worked through, and asserting the whole page were German
        // would be asserting the schedule rather than the mechanism.
        self::assertStringNotContainsString('The language this application is shown to you in', $text);
    }

    public function testTheChoiceIsRememberedOnTheUser(): void
    {
        $this->signIn();

        $this->client->request('GET', $this->url('/account'));
        $this->client->submitForm('Save language', ['locale' => 'de']);

        self::assertSame('de', $this->user()->getLocale());
    }

    /**
     * Following the default is a different answer from choosing English, and the
     * picker has to be able to say so — otherwise the only way back from a
     * choice is to make another one.
     */
    public function testTheChoiceCanBeGivenBack(): void
    {
        $this->signIn();

        $this->client->request('GET', $this->url('/account'));
        $this->client->submitForm('Save language', ['locale' => 'de']);
        self::assertSame('de', $this->user()->getLocale());

        $this->client->request('GET', $this->url('/account'));
        $this->client->submitForm('Sprache speichern', ['locale' => '']);

        self::assertNull($this->user()->getLocale());
    }

    /**
     * A language this build does not have is not stored.
     *
     * The picker cannot offer one, so this only happens to a hand-edited
     * request — and storing it would mean a person whose every page silently
     * fell back, with a setting that looked correct.
     */
    public function testALanguageThisBuildDoesNotHaveIsRefused(): void
    {
        $this->signIn();

        $this->client->request('POST', $this->url('/account'), [
            '_token' => $this->token(),
            'action' => 'language',
            'locale' => 'kli',
        ]);

        self::assertNull($this->user()->getLocale());
    }

    /**
     * The login page has nobody to ask, so it asks the browser.
     *
     * It is the one page where a German speaker would otherwise be met in
     * English every time, including the time they are trying to work out how to
     * change it.
     */
    public function testTheLoginPageFollowsTheBrowser(): void
    {
        $this->client->request('GET', $this->url('/login'), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertSame('de', $this->client->getRequest()->getLocale());
    }

    /**
     * The icon-only buttons say what they do (XIV-8).
     *
     * They already carried a visually-hidden label, so a screen reader was never
     * the problem — a sighted person hovering a row of three identical grey
     * squares was. The title is on the element whether or not the script that
     * turns it into a Bootstrap tooltip runs, so the hint survives a failed
     * asset load.
     */
    public function testIconOnlyActionsSayWhatTheyDo(): void
    {
        $this->signIn(self::ADMIN);

        $page = $this->client->request('GET', $this->url('/users'));

        $titles = $page->filter('[data-bs-toggle="tooltip"]')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => (string) $node->attr('title'),
        );

        self::assertNotEmpty($titles, 'the user list has icon-only actions');

        foreach ($titles as $title) {
            self::assertNotSame('', $title, 'every tooltip says something');
        }
    }

    /** And in the language the person reads. */
    public function testTooltipsAreTranslated(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('GET', $this->url('/account'));
        $this->client->submitForm('Save language', ['locale' => 'de']);

        $page = $this->client->request('GET', $this->url('/users'));
        $titles = $page->filter('[data-bs-toggle="tooltip"]')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => (string) $node->attr('title'),
        );

        self::assertContains('Benutzer bearbeiten', $titles);
    }

    /**
     * A module installed in German is named in German (XIV-8).
     *
     * Its label is the customer's data (§5), so it cannot follow each reader's
     * own language — two colleagues share one row. What it can do is arrive in
     * the right language, which is what the blueprint's catalogue is for: read
     * once, at install time, and then it stops having a say.
     */
    public function testAModuleInstalledInGermanIsNamedInGerman(): void
    {
        $switcher = self::service(TenantSwitcher::class);
        $blueprint = self::service(ModuleRegistry::class)->get(ContactModule::KEY);

        $module = $switcher->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install($blueprint, null, 'de'),
        );

        self::assertSame('Kontakte', $module->getLabel());

        $labels = array_map(
            static fn (FieldDefinition $f): string => $f->getLabel(),
            $module->getFields()->toArray(),
        );

        self::assertContains('Vorname', $labels);
        self::assertContains('Nachname', $labels);
    }

    /**
     * And the customer can still overrule it.
     *
     * Which is the whole reason the translation is a seed rather than a lookup:
     * a label resolved on every render would overwrite their word every page
     * load, and the screen offering the rename would be a lie.
     */
    public function testACustomerCanRenameTheirOwnModule(): void
    {
        $switcher = self::service(TenantSwitcher::class);
        $blueprint = self::service(ModuleRegistry::class)->get(ContactModule::KEY);

        $switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install($blueprint, null, 'de'));

        $renamed = $switcher->runFor($this->tenant, function () {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            self::service(MetadataEditor::class)->renameShape($module, 'Ansprechpartner');

            return $module->getLabel();
        });

        self::assertSame('Ansprechpartner', $renamed);
    }

    /** A shape with no name is a blank tab nobody can click. */
    public function testAShapeCannotBeRenamedToNothing(): void
    {
        $switcher = self::service(TenantSwitcher::class);
        $blueprint = self::service(ModuleRegistry::class)->get(ContactModule::KEY);

        $switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install($blueprint));

        $this->expectException(MetadataChangeRefused::class);

        $switcher->runFor($this->tenant, function (): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            self::service(MetadataEditor::class)->renameShape($module, '   ');
        });
    }

    // -- helpers ------------------------------------------------------------

    private function token(): string
    {
        $crawler = $this->client->request('GET', $this->url('/account'));

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function user(): User
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): User {
            $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
            self::assertInstanceOf(User::class, $user);

            return $user;
        });
    }

    private function signIn(?string $email = null): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email ?? self::EMAIL,
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

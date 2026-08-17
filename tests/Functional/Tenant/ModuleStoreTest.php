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

use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Entity\Module;
use App\Registry\Entity\ModuleState;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionManager;
use App\Tenant\Security\StoreAction;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\PermissionVerb;

/**
 * The store: a tenant installing a module without anybody running a command
 * (XIV-6).
 *
 * Four things are proven here, and only the first is the feature.
 *
 * **A module can be installed from a screen**, at a preset, and what lands in the
 * database is exactly what the preset claimed — the same shape
 * `tenant:module:install` produces, because both go through the same installer.
 *
 * **The permission axis is real, and this is where most of the weight is.**
 * Browsing and installing are their own axis (§8.4.3), which means two things
 * that both have to be tested and only one of which is visible: the button is
 * absent for somebody who may not install, *and* the request is refused when they
 * type it anyway. A missing control is not a check. And because the axis is
 * separate rather than a pair of extra ModuleActions, there is a third thing to
 * prove that would be meaningless in a one-axis model: somebody granted **every**
 * module action on **every** module still cannot install, because none of those
 * grants is about the store.
 *
 * **A module whose requirements are missing is not offered**, names what is
 * missing, and refuses a submitted install rather than failing on it (XIV-23).
 *
 * **A module already installed is not installable again**, and the store says so
 * rather than erroring — which is also what the installer would do, since a
 * preset only ever seeds a new installation (§6.1).
 *
 * **On the control plane and paratest.** Which modules the store offers is a
 * control-plane fact, and DAMA deliberately does not roll the control plane back
 * (config/packages/test/dama_doctrine_test.yaml) — so the rows written here are
 * cleaned up by hand, and this class keeps to module keys no other class writes
 * state for. {@see \App\Tests\Functional\ControlPlane\ModuleStateTest} has
 * article; these three are ours.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleStoreTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_store';
    private const string HOST = 'store.localhost';
    private const string ADMIN = 'admin@store.test';
    /** Somebody with no bypass, whose grants are the whole point of half of this. */
    private const string STAFF = 'staff@store.test';
    private const string PASSWORD = 'store-password';

    /** Published for this class, and forgotten again in tearDown. */
    private const array PUBLISHED = ['contact', 'order', 'invoice'];

    /** Rubbish, and never looked at — see {@see postInstall()}. */
    private const string NO_TOKEN = 'not-a-token';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::STAFF, 'Staff', self::PASSWORD, []);

        $this->publish(...self::PUBLISHED);
    }

    protected function tearDown(): void
    {
        $this->forgetStates();

        parent::tearDown();
    }

    // -- the store, and what it says about each module -----------------------

    /**
     * Every module this build offers, each saying whether the tenant has it.
     *
     * Presence rather than an exact list: the control plane is shared by every
     * paratest worker, so another class's module may be published while this runs
     * and asserting "these and no others" would be asserting something about
     * their test rather than about ours.
     */
    public function testTheStoreListsWhatIsOfferedAndSaysWhatTheTenantAlreadyHas(): void
    {
        $this->installForTenant('contact');
        $this->signIn(self::ADMIN);

        $store = $this->client->request('GET', $this->url('/store'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contacts', $store->filter('main')->text());
        self::assertStringContainsString('Orders', $store->filter('main')->text());

        $contact = $store->filter('.card')->reduce(
            static fn ($card): bool => str_contains($card->text(), 'Contacts'),
        );

        self::assertStringContainsString('Installed', $contact->text(), 'the tenant has this one');
    }

    /** A module in development is nobody's to install, and has no page here. */
    public function testAModuleThisBuildDoesNotOfferHasNoPage(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('GET', $this->url('/store/article'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // -- the module page, and the wizard -------------------------------------

    /** What each preset contains, in words, before anything is chosen. */
    public function testAModulesPageShowsItsPresetsAndWhatEachContains(): void
    {
        $this->signIn(self::ADMIN);

        $page = $this->client->request('GET', $this->url('/store/contact'))->filter('main')->text();

        self::assertStringContainsString('Basic', $page);
        self::assertStringContainsString('Extended', $page);
        // A field the smaller preset has and one only the larger one has, so the
        // difference between them is readable rather than implied.
        self::assertStringContainsString('Email', $page);
        self::assertStringContainsString('Birthday', $page);
        // Collections come whichever preset is chosen (§6.1), and the page says so.
        self::assertStringContainsString('Addresses', $page);
    }

    /**
     * The wizard says the choice is permanent, in as many words.
     *
     * The acceptance criterion, and the whole reason XIV-70 is not a prerequisite:
     * for this iteration the screen simply has to be honest about it.
     */
    public function testTheWizardStatesThatThePresetCannotBeChangedLater(): void
    {
        $this->signIn(self::ADMIN);

        $wizard = $this->client->request('GET', $this->url('/store/contact/install'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cannot be changed later', $wizard->filter('main')->text());
        // Both futures on the screen at once, rather than one behind a click.
        self::assertCount(2, $wizard->filter('input[name="preset"]'));
    }

    /** The feature: a preset installs exactly the fields it names, and no more. */
    public function testInstallingSeedsExactlyWhatThePresetClaims(): void
    {
        $this->signIn(self::ADMIN);

        $this->installThroughTheStore('contact', 'basic');

        $definition = $this->definitionOf('contact');

        self::assertNotNull($definition, 'the module is theirs now');
        self::assertSame(
            ['kind', 'company_name', 'first_name', 'last_name', 'email'],
            $definition->getFieldKeys(),
            'exactly the basic preset, in the blueprint\'s own order',
        );
        // Named because the wizard offered them under "Extended" and this install
        // did not choose it.
        self::assertNotContains('birthday', $definition->getFieldKeys());
        self::assertNotContains('phone', $definition->getFieldKeys());
        // A preset names fields and never collections, so this one arrives anyway.
        self::assertSame(['addresses'], $definition->getCollectionKeys());
    }

    /**
     * Installed here or by the command, it is the same module.
     *
     * Both go through `ModuleInstaller`, so this is really asserting that the
     * store did not grow a second install path — and the way to notice if it ever
     * does is to compare the two shapes rather than to read the code.
     */
    public function testAModuleInstalledFromTheStoreIsTheOneTheCommandWouldHaveInstalled(): void
    {
        $this->signIn(self::ADMIN);
        $this->installThroughTheStore('contact', 'extended');

        $fromStore = $this->definitionOf('contact');
        self::assertNotNull($fromStore);

        $blueprint = self::service(ModuleRegistry::class)->get('contact');
        $preset = $blueprint->preset('extended');
        self::assertNotNull($preset, 'the wizard offered it, so the blueprint has it');

        $expected = array_map(
            static fn ($field): string => $field->key,
            array_values(array_filter(
                $blueprint->fields,
                static fn ($field): bool => \in_array($field->key, $preset->fields, true),
            )),
        );

        self::assertSame($expected, $fromStore->getFieldKeys());
        self::assertSame($blueprint->table, $fromStore->getTableName());

        // And it works: the generic module screen opens on it, which is the only
        // "afterwards" a customer cares about.
        $this->client->request('GET', $this->url('/m/contact'));
        self::assertResponseIsSuccessful();
    }

    // -- requirements: refused with guidance, never chained -------------------

    /**
     * Invoice needs Contact and Order, and says which of them is missing rather
     * than failing on submit (XIV-23).
     */
    public function testAModuleWithMissingRequirementsIsNotOfferedAndNamesThem(): void
    {
        $this->installForTenant('contact');
        $this->signIn(self::ADMIN);

        $page = $this->client->request('GET', $this->url('/store/invoice'));
        $text = $page->filter('main')->text();

        self::assertStringContainsString('Orders', $text, 'the one they have not got is named');
        self::assertStringContainsString('Not installed', $text);
        self::assertCount(0, $page->filter('a[href$="/store/invoice/install"]'), 'and is not offered');

        // Nothing was chain-installed on the way past: each of those carries its
        // own permanent preset choice, which is somebody else's decision to make.
        self::assertNull($this->definitionOf('order'));
    }

    /** And the submitted install is refused, because the page is not the check. */
    public function testAnInstallOfSomethingWithMissingRequirementsIsRefused(): void
    {
        $this->installForTenant('contact');
        $this->signIn(self::ADMIN);

        // Order's wizard is open to them — it needs only contact, which they have
        // — so this is the token their browser would be carrying when they posted
        // an install of invoice from a page they should not have had.
        $this->postInstall('invoice', 'extended', $this->tokenFrom('order'));

        self::assertResponseRedirects();
        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('needs', $page);
        self::assertNull($this->definitionOf('invoice'), 'and nothing was written');
    }

    /**
     * The wizard asks whether the module takes follow-ups, and the answer lands
     * on the definition (XIV-80).
     *
     * On by default, and — unlike the preset this screen warns about — reversible
     * afterwards, because no table is created per module for it. Both halves are
     * asserted, since the default being right is the half nobody would notice
     * breaking.
     */
    public function testTheWizardOffersFollowUpsAndInstallsWithThem(): void
    {
        $this->signIn(self::ADMIN);

        $wizard = $this->client->request('GET', $this->url('/store/contact/install'));

        self::assertCount(1, $wizard->filter('input[name="follow_ups"][checked]'), 'ticked to begin with');

        $this->installThroughTheStore('contact', 'basic');

        self::assertTrue($this->definitionOf('contact')?->hasFollowUps());
    }

    /**
     * And unticking it installs the module without them.
     *
     * Posted by hand rather than through the crawler's form, and that is about
     * the crawler rather than about the feature: two fields of the same name are
     * collapsed to one in its registry, so unticking the box there would drop the
     * marker beside it and send exactly what a form that never asked sends. A
     * browser posts both. See ModuleStoreController::wantsFollowUps().
     */
    public function testTheWizardCanInstallAModuleWithoutFollowUps(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('POST', $this->url('/store/contact/install'), [
            '_token' => $this->tokenFrom('contact'),
            'preset' => 'basic',
            'follow_ups_asked' => '1',
        ]);
        $this->client->followRedirect();

        $definition = $this->definitionOf('contact');

        self::assertNotNull($definition, 'it still installed');
        self::assertFalse($definition->hasFollowUps());
    }

    // -- already installed ---------------------------------------------------

    /** A module the tenant has cannot be installed twice, and is told so. */
    public function testAModuleAlreadyInstalledCannotBeInstalledAgain(): void
    {
        $this->signIn(self::ADMIN);

        // The token comes off the wizard *before* the module exists, which is
        // exactly the stale tab this refusal is for: they opened it, somebody
        // else installed, and then they pressed the button.
        $token = $this->tokenFrom('contact');
        $this->installForTenant('contact', 'basic');

        // The wizard has nothing to ask any more, so it sends them to the page
        // that says why.
        $this->client->request('GET', $this->url('/store/contact/install'));
        self::assertResponseRedirects();
        self::assertStringContainsString('already have this module', $this->client->followRedirect()->filter('main')->text());

        $this->postInstall('contact', 'extended', $token);
        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('already have', $page);
        // Emphatically not upgraded to the preset they asked for: §6.1 does not
        // retro-fit, and quietly doing it here is what XIV-70 exists to do openly.
        self::assertSame(
            ['kind', 'company_name', 'first_name', 'last_name', 'email'],
            $this->definitionOf('contact')?->getFieldKeys(),
        );
    }

    // -- the permission axis, which is most of this ticket --------------------

    /** No store grant at all is no store, and the navigation does not offer one. */
    public function testSomebodyWithNoStoreGrantCannotEvenBrowse(): void
    {
        $this->signIn(self::STAFF);

        $dashboard = $this->client->request('GET', $this->url('/'));
        self::assertCount(0, $dashboard->filter('a[href$="/store"]'), 'not offered');

        $this->client->request('GET', $this->url('/store'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and not reachable either');
    }

    /**
     * The one most likely to be quietly wrong.
     *
     * Somebody who may browse is shown no install button — and, because a missing
     * control is not a check, is refused when they post the install anyway.
     * Granting the second verb turns the same request into an installation.
     */
    public function testBrowsingDoesNotCarryInstallingAndTheRefusalIsRealNotCosmetic(): void
    {
        $this->grantStore(self::STAFF, StoreAction::Browse);
        $this->signIn(self::STAFF);

        $page = $this->client->request('GET', $this->url('/store/contact'));

        self::assertResponseIsSuccessful('browsing is what they were granted');
        self::assertCount(0, $page->filter('a[href$="/store/contact/install"]'), 'and installing is not');

        $this->postInstall('contact', 'basic', self::NO_TOKEN);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'the button being absent was not the check');
        self::assertNull($this->definitionOf('contact'), 'and nothing was written');

        // The same request, once the second grant is there.
        $this->grantStore(self::STAFF, StoreAction::Install);

        $page = $this->client->request('GET', $this->url('/store/contact'));
        self::assertCount(1, $page->filter('a[href$="/store/contact/install"]'), 'now it is offered');

        $this->postInstall('contact', 'basic', $this->tokenFrom('contact'));
        $this->client->followRedirect();

        self::assertNotNull($this->definitionOf('contact'));
    }

    /**
     * Every module permission there is still does not add up to installing.
     *
     * This is the assertion that would be meaningless if the store were two more
     * ModuleAction cases, and it is the reason it is not: the authority to decide
     * what an installation consists of is not the sum of the authorities over its
     * contents.
     */
    public function testEveryModuleActionOnEveryModuleStillCannotInstall(): void
    {
        $this->installForTenant('contact');
        $this->grantEveryModuleAction(self::STAFF);
        $this->signIn(self::STAFF);

        // They can work in the module they have, comprehensively.
        $this->client->request('GET', $this->url('/m/contact'));
        self::assertResponseIsSuccessful();

        // And none of it is about the store.
        $this->client->request('GET', $this->url('/store'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->postInstall('order', 'basic', self::NO_TOKEN);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->definitionOf('order'));
    }

    /**
     * A store verb cannot be granted against a module, however the request is
     * shaped.
     *
     * The permission screens generate their cells from the customer's own
     * modules, so nothing legitimate posts `('contact', 'install')`. A hand-edited
     * request can, and storing it would leave a row in the grant table that reads
     * as an authority and confers nothing — which is a worse outcome than
     * refusing, because somebody would later believe it.
     */
    public function testAStoreVerbCannotBeGrantedAgainstAModule(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $manager = self::service(PermissionManager::class);
            $group = $manager->create('Hand edited');

            $manager->applyGrants($group, [
                'contact' => [
                    // Coherent, so the assertions below are about the incoherent
                    // pairs rather than about applyGrants having done nothing.
                    ModuleAction::View->value => PermissionScope::All->value,
                    StoreAction::Install->value => PermissionScope::All->value,
                ],
                StoreAction::SUBJECT => [
                    ModuleAction::Delete->value => PermissionScope::All->value,
                    StoreAction::Browse->value => PermissionScope::All->value,
                ],
            ]);

            self::assertSame(
                [
                    'contact' => [ModuleAction::View->value => PermissionScope::All->value],
                    StoreAction::SUBJECT => [StoreAction::Browse->value => PermissionScope::All->value],
                ],
                PermissionManager::matrixOf($group),
            );
        });
    }

    /** The store's grants show up on the group screen, as their own section. */
    public function testTheGroupScreenOffersTheStoreAsItsOwnSection(): void
    {
        $this->signIn(self::ADMIN);

        $group = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): PermissionGroup => self::service(PermissionManager::class)->create('Office'),
        );

        $form = $this->client->request('GET', $this->url('/users/groups/' . $group->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $form->filter(sprintf('select[name="grants[%s][%s]"]', StoreAction::SUBJECT, StoreAction::Install->value)),
        );
        // And the store's verbs are not offered against a module, which is the
        // screen half of the axis being separate.
        self::assertCount(
            0,
            $form->filter(sprintf('select[name="grants[contact][%s]"]', StoreAction::Install->value)),
        );
    }

    // -- helpers -------------------------------------------------------------

    /** Installs through the browser, the way a customer does. */
    private function installThroughTheStore(string $module, string $preset): void
    {
        $wizard = $this->client->request('GET', $this->url(sprintf('/store/%s/install', $module)));

        self::assertResponseIsSuccessful();

        $this->client->submit($wizard->selectButton('Install this module')->form(['preset' => $preset]));
        $this->client->followRedirect();
    }

    /**
     * The install, posted by hand.
     *
     * What somebody retyping the form does, which is the only honest way to test
     * a control that is not drawn for them.
     *
     * The token is passed in rather than minted, because where it comes from is
     * part of what each test is saying. A permission test hands it rubbish on
     * purpose: `#[IsGranted]` is checked on `kernel.controller_arguments`, before
     * the action runs at all, so a refusal that depended on the token would not be
     * the refusal being claimed.
     */
    private function postInstall(string $module, string $preset, string $token): void
    {
        $this->client->request('POST', $this->url(sprintf('/store/%s/install', $module)), [
            '_token' => $token,
            'preset' => $preset,
        ]);
    }

    /**
     * A real token, read off a wizard this session is allowed to open.
     *
     * One token id covers every install, so a token read from one module's wizard
     * is the one a browser would send for another — which is precisely the stale
     * tab the refusals exist for.
     */
    private function tokenFrom(string $module): string
    {
        $wizard = $this->client->request('GET', $this->url(sprintf('/store/%s/install', $module)));

        self::assertResponseIsSuccessful(sprintf('the %s wizard is open to this session', $module));

        return (string) $wizard->filter('input[name="_token"]')->attr('value');
    }

    /** Installs straight into the tenant, bypassing the store: a starting state. */
    private function installForTenant(string $module, ?string $preset = null): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $preset): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get($module),
                $preset,
            );
        });
    }

    private function definitionOf(string $module): ?ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?ModuleDefinition => self::service(MetadataRepository::class)->find($module),
        );
    }

    private function grantStore(string $email, StoreAction $action): void
    {
        $this->grant($email, StoreAction::SUBJECT, $action);
    }

    private function grantEveryModuleAction(string $email): void
    {
        foreach (self::service(ModuleRegistry::class)->all() as $key => $blueprint) {
            foreach (ModuleAction::cases() as $action) {
                $this->grant($email, $key, $action);
            }
        }
    }

    private function grant(string $email, string $subject, PermissionVerb $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $subject, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, $subject, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    private function publish(string ...$modules): void
    {
        $catalog = self::service(ModuleCatalog::class);

        foreach ($modules as $module) {
            $catalog->moveTo($module, ModuleState::Published);
        }
    }

    /**
     * The control plane is not rolled back, so the rows go by hand.
     *
     * Deleted rather than moved back to development: a row saying "somebody
     * decided about this" is exactly the fact §6.2 keeps, and leaving one behind
     * would be this class's opinion showing up in somebody else's run.
     */
    private function forgetStates(): void
    {
        $manager = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        $manager->createQuery('DELETE FROM ' . Module::class . ' m WHERE m.key IN (:keys)')
            ->setParameter('keys', self::PUBLISHED)
            ->execute();

        $manager->clear();
    }

    private function signIn(string $email): void
    {
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

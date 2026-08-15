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

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * A link that leaves its own module (§7.6, XIV-13).
 *
 * Contact already links to contact, which proved a link can be a field type and
 * proved nothing about crossing a module boundary — the module key was its own
 * on both sides, so every lookup happened to land somewhere it already was.
 *
 * Here an article gets a field pointing at a contact, which is the shape an
 * order line will have. Built through the metadata editor rather than by
 * changing the article module, because a customer adding a link between two
 * modules they have is exactly the case this must support (§5.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CrossModuleLinkTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_cross_links';
    private const string HOST = 'crosslinks.localhost';
    private const string ADMIN = 'admin@crosslinks.test';
    private const string MEMBER = 'member@crosslinks.test';
    private const string PASSWORD = 'crosslinks-password';
    private const string FORM = 'module_record';

    /** The field added to article, pointing at a contact. */
    private const string SUPPLIER = 'supplier';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            $installer->install($registry->get(ContactModule::KEY));
            $article = $installer->install($registry->get(ArticleModule::KEY));

            // The link itself: a customer's own field, added in the editor,
            // pointing at a module the article package knows nothing about.
            if ($article->getField(self::SUPPLIER) === null) {
                self::service(MetadataEditor::class)->addField(
                    $article,
                    self::SUPPLIER,
                    'Supplier',
                    'reference',
                    filterable: true,
                    options: ['module' => ContactModule::KEY, 'variant' => ContactModule::COMPANY],
                );
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /** The picker offers the other module's records, narrowed to its variant. */
    public function testTheFormOffersRecordsOfTheOtherModule(): void
    {
        $this->aCompany('Acme AG');
        $this->aPerson('Ada', 'Lovelace');

        $crawler = $this->client->request('GET', $this->url('/m/article/new'));
        $options = self::optionsOf($crawler, sprintf('select[name="%s"]', self::field(self::SUPPLIER)));

        self::assertContains('Acme AG', $options);
        self::assertNotContains('Ada Lovelace', $options, 'narrowed to companies');
    }

    /** And the record page names the linked record rather than printing an id. */
    public function testALinkedRecordIsNamedOnThePage(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        $page = $this->client->request('GET', $this->url('/m/article/' . $article))->filter('main')->text();

        self::assertStringContainsString('Acme AG', $page);
        self::assertStringNotContainsString('#' . $company, $page);
    }

    /**
     * The reverse, which is the half that did not exist: a contact's page lists
     * the articles naming it, across the module boundary.
     */
    public function testTheLinkedRecordListsWhatPointsAtItFromAnotherModule(): void
    {
        $company = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $company);

        $page = $this->client->request('GET', $this->url('/m/contact/' . $company))->filter('main')->text();

        self::assertStringContainsString('Articles', $page, 'grouped by the module that points here');
        self::assertStringNotContainsString('Contacts 1', $page, 'and not filed under this record\'s own module');
        self::assertStringContainsString('Desk lamp', $page);
    }

    /** Filtering through the link: "articles whose supplier is in Zürich". */
    public function testTheListCanFilterThroughALink(): void
    {
        $acme = $this->aCompany('Acme AG');
        $other = $this->aCompany('Beta GmbH');

        $this->anArticle('Desk lamp', $acme);
        $this->anArticle('Cable', $other);

        $table = $this->client->request('GET', $this->url(
            '/m/article?filter[0][path]=supplier.company_name&filter[0][op]=eq&filter[0][value]=Acme+AG',
        ))->filter('table')->text();

        self::assertStringContainsString('Desk lamp', $table);
        self::assertStringNotContainsString('Cable', $table);
    }

    /** And the filter bar offers the path, so nobody has to know the syntax. */
    public function testTheFilterBarOffersPathsThroughALink(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article'));
        $options = self::optionsOf($crawler, 'select[name="filter[0][path]"]');

        self::assertContains('Supplier: Company name', $options);
        self::assertNotContains('Supplier: Supplier', $options, 'one hop, not two');
    }

    /**
     * Following a link is reading the other module, so the other module's
     * permissions apply — otherwise a filter is a way to sift records by values
     * somebody may not see (§8.4).
     */
    public function testFilteringThroughALinkRespectsTheOtherModulesPermissions(): void
    {
        $acme = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $acme);

        // May list articles, and knows nothing about contacts.
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::List);
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::View);

        $this->signIn(self::MEMBER);

        $unfiltered = $this->client->request('GET', $this->url('/m/article'))->filter('table')->text();
        self::assertStringContainsString('Desk lamp', $unfiltered, 'the articles themselves are theirs to see');

        $filtered = $this->client->request('GET', $this->url(
            '/m/article?filter[0][path]=supplier.company_name&filter[0][op]=eq&filter[0][value]=Acme+AG',
        ))->filter('main')->text();

        self::assertStringNotContainsString('Desk lamp', $filtered, 'but not to sift by');
    }

    // -- helpers ------------------------------------------------------------

    private function aCompany(string $name): int
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=company'));
        $this->client->submitForm('Save', [self::field('company_name') => $name]);

        return $this->idOfTheRecordJustSaved();
    }

    private function aPerson(string $first, string $last): int
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $this->client->submitForm('Save', [
            self::field('first_name') => $first,
            self::field('last_name') => $last,
        ]);

        return $this->idOfTheRecordJustSaved();
    }

    private function anArticle(string $title, ?int $supplier = null): int
    {
        $this->client->request('GET', $this->url('/m/article/new'));

        $values = [self::field('title') => $title];

        if ($supplier !== null) {
            $values[self::field(self::SUPPLIER)] = (string) $supplier;
        }

        $this->client->submitForm('Save', $values);

        return $this->idOfTheRecordJustSaved();
    }

    private function idOfTheRecordJustSaved(): int
    {
        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private function grant(string $email, string $module, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $module, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, $module, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    /**
     * Every option of a select, as text.
     *
     * Crawler::text() answers for the first node only, which for a select is
     * always the placeholder — a trap worth a helper rather than a comment.
     *
     * @return list<string>
     */
    private static function optionsOf(\Symfony\Component\DomCrawler\Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector . ' option')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
        );
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
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

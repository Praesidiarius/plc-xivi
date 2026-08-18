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
use App\Registry\Pricing\ModulePrice;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\ModulePurchaseIntent;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\ControlPlane\Entity\PurchaseIntent;
use Xivi\ControlPlane\Purchase\PurchaseIntentCollector;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Repository\PurchaseIntentRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * An operator can see what customers have asked to buy (XIV-102).
 *
 * The other half of the ticket. {@see \App\Tests\Functional\Tenant\ModulePurchaseTest}
 * proves that asking installs nothing; this proves that the asking is not
 * therefore shouted into a void.
 *
 * ## The thing being tested is a boundary, not a screen
 *
 * A purchase request is written into the **customer's own database**, because
 * §4.4 grants the customer-facing instance's role `SELECT` on the registry tables
 * and no write privilege anywhere in the control plane. So the request and the
 * operator are in two different databases by construction, and
 * `tenant:purchase:collect` is the only thing that joins them — [XIV-59]'s
 * collector, reused deliberately rather than reinvented.
 *
 * Four things are proved:
 *
 *   1. **A request written by a customer turns up on the operator's screen**,
 *      after a collection and not before, with the price the customer was shown
 *      rather than whatever the module costs now.
 *   2. **Fulfilment is observed rather than reported.** Installing the module for
 *      that customer — which is what an operator does — is what marks the request
 *      done, at the next collection. There is no button and no status column.
 *   3. **A request that goes from the customer's database goes from here too**,
 *      so the queue cannot fill up with rows describing nothing.
 *   4. **The screen opens no tenant connection**, which is [XIV-58]'s boundary and
 *      is asserted the same way `TenantListTest` and `ModulePriceTest` assert it.
 *      A page that fanned out over every customer to build this list would have
 *      turned §7.4's *consequence* — one request, one tenant — into an argument to
 *      be had case by case.
 *
 * **Nothing here decides anything about a module.** The control plane is shared
 * across paratest workers and is not rolled back, so a class that priced a module
 * would be racing `ModulePriceTest` and {@see \App\Tests\Functional\Tenant\ModulePurchaseTest}
 * for a row all three would then be deleting. It does not need to: a purchase
 * request carries its own copy of the price, the collector never consults the
 * catalogue, and the operator's screen resolves a module's *name* from the build.
 * So what this class writes is a request in its own tenant's database and the
 * collected copies keyed by that tenant, and the second of those goes by hand.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PurchaseIntentTest extends WebTestCase
{
    use SharesATenant;

    /**
     * A blueprint only the test build ships, used here purely so that the screen
     * has a real module to name. Nothing in this class decides anything about it
     * — see the class docblock for why that matters.
     */
    private const string MODULE = JobModule::KEY;

    private const string PRICE = '79.00';

    private const string SLUG = 'test_intents';
    private const string HOST = 'intents.localhost';

    private const string OPERATOR = 'purchases@example.test';
    private const string OPERATOR_NAME = 'The Fulfiller';
    private const string PASSWORD = 'operator-password-102';

    private KernelBrowser $client;
    private string $controlPlaneHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request, because one test asks what
        // state the tenant connection was left in afterwards — a rebooted kernel
        // would hand back a fresh one nobody had touched.
        $this->client->disableReboot();

        $this->controlPlaneHost = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->forgetEverything();
    }

    protected function tearDown(): void
    {
        $this->forgetEverything();

        parent::tearDown();
    }

    /**
     * **The whole path, end to end**: a row in a customer's database, a
     * collection, and a line on the operator's screen.
     *
     * The screen is checked before the collection as well as after, because "it
     * appears eventually" and "it appears" are different claims and only the
     * first one is true. The collection interval is the honest cost of the
     * boundary and the page prints the moment beside every row for that reason.
     */
    public function testARequestReachesTheOperatorAfterACollection(): void
    {
        $this->request(self::PRICE);

        $before = $this->openPurchases();
        self::assertStringNotContainsString(self::SLUG, $before->filter('main')->text());

        $this->collect();

        $after = $this->openPurchases();
        $text = $after->filter('main')->text();

        self::assertStringContainsString(self::SLUG, $text, 'the customer is named');
        self::assertStringContainsString(self::MODULE, $text, 'and so is what they want');
        self::assertStringContainsString(self::PRICE, $text, 'at the price they were shown');
        self::assertStringContainsString('Waiting', $text);
    }

    /**
     * **What the operator sees is the customer's copy, carried across
     * unchanged** — the figure and the currency it was shown in.
     *
     * [XIV-101] left this as an instruction for this ticket (§6.5): nothing about
     * a sale is ever recomputed from the module row afterwards, so the number on
     * this screen has to come from the request rather than from the catalogue.
     * The collector is deliberately unable to do otherwise — it never touches
     * `ModuleCatalog` at all — and this is what would fail if somebody made it
     * "helpfully" look the current price up.
     *
     * The currency is set on the request rather than through `PRICE_CURRENCY`,
     * which is empty in this repository: the copy is what a customer was shown at
     * the time, and a deployment that changes its selling currency later must not
     * relabel a figure somebody already agreed to.
     */
    public function testTheOperatorSeesThePriceTheCustomerWasShown(): void
    {
        $this->request(self::PRICE, 'CHF');
        $this->collect();

        $collected = $this->collected();
        self::assertInstanceOf(PurchaseIntent::class, $collected);
        self::assertSame(self::PRICE, $collected->getPriceAmount());
        self::assertSame('CHF', $collected->getPriceCurrency());

        $text = $this->openPurchases()->filter('main')->text();
        self::assertStringContainsString(self::PRICE . ' CHF', preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * **Installing the module is what marks the request done**, at the next
     * collection.
     *
     * There is no button on that page and no status column behind it. The
     * customer's own database says whether they have the module (§6.1), the
     * collector is already in there, and a stored status would be a second copy
     * of that fact free to disagree with it — [XIV-98]'s argument against a
     * `provisioned` status on a signup, arriving at the same answer.
     */
    public function testInstallingTheModuleIsWhatAnswersTheRequest(): void
    {
        $this->request(self::PRICE);
        $this->collect();

        self::assertFalse($this->collected()?->isInstalled(), 'nobody has done anything yet');

        // What an operator actually does: `tenant:module:install`, by hand, for
        // that customer. The store deliberately cannot, which is the ticket.
        $this->installForTenant();
        $this->collect();

        self::assertTrue($this->collected()?->isInstalled());
        self::assertStringContainsString('Installed', $this->openPurchases()->filter('main')->text());
    }

    /**
     * A row here whose request has gone from the customer's database goes too.
     *
     * Without this the table would only ever grow, and a queue somebody works
     * through and finds half of it fictional is a queue they stop trusting.
     */
    public function testARequestThatDisappearsIsNotLeftBehind(): void
    {
        $this->request(self::PRICE);
        $this->collect();
        self::assertInstanceOf(PurchaseIntent::class, $this->collected());

        $this->withdraw();
        $this->collect();

        self::assertNull($this->collected());
    }

    /**
     * **The screen opens no tenant connection**, which is the property [XIV-58]
     * built the tenant list around and [XIV-101] kept on the pricing screen.
     *
     * It is not merely unused: a control-plane request resolves no tenant at all
     * (§8.9), so anything reaching for that connection here would have thrown
     * rather than quietly served the previous customer's database. Asserting that
     * it was never opened is what proves the fan-out happens in the collector
     * rather than on the page.
     */
    public function testTheScreenOpensNoTenantConnection(): void
    {
        $this->request(self::PRICE);
        $this->collect();

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        // Closed first, because the collection legitimately opened it — that is
        // its whole job, and it is the reason the page does not have to. What is
        // being asserted is that drawing the page opens it *again*, so the state
        // it starts from has to be the one a request on this host genuinely has.
        $connection->close();

        $this->openPurchases();

        self::assertFalse($connection->isConnected(), 'the customer databases were left alone');
    }

    /**
     * **And nobody's name crosses.** §8.11 drew the line at *how much* rather
     * than *what*, and a customer's own people are on the far side of it.
     *
     * The tenant-side row records who pressed the button — their screen shows it,
     * because it is their data — and the collected copy carries the tenant and
     * the module and stops. The honest consequence is that an operator knows
     * which company wants which module and reaches them the way they already do.
     */
    public function testWhoAskedDoesNotReachTheControlPlane(): void
    {
        $this->request(self::PRICE);
        $this->collect();

        self::assertStringNotContainsString(
            'The Asker',
            $this->openPurchases()->filter('main')->text(),
            'the person who asked is the customer\'s own data',
        );
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Writes a purchase request straight into the customer's database.
     *
     * Directly rather than through the store, on purpose: what this class is
     * about is the journey from that row to the operator's screen, and going
     * through the store would drag a signed-in tenant user and a pricing decision
     * in the control plane into a test that needs neither.
     * {@see \App\Tests\Functional\Tenant\ModulePurchaseTest} is where the store's
     * half is proved.
     */
    private function request(string $amount, ?string $currency = null): void
    {
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant(),
            function () use ($amount, $currency): void {
                $manager = self::getContainer()->get('doctrine')->getManager('tenant');
                \assert($manager instanceof EntityManagerInterface);

                $manager->persist(new ModulePurchaseIntent(
                    self::MODULE,
                    ModulePrice::of($amount),
                    $currency,
                    1,
                    'The Asker',
                ));
                $manager->flush();
            },
        );
    }

    /** Takes it away again, which nothing in the product does yet. */
    private function withdraw(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant(), function (): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $manager->createQuery('DELETE FROM ' . ModulePurchaseIntent::class . ' i')->execute();
            $manager->clear();
        });
    }

    private function collect(): void
    {
        self::service(PurchaseIntentCollector::class)->collect($this->tenant());
        $this->controlManager()->clear();
    }

    private function collected(): ?PurchaseIntent
    {
        return self::service(PurchaseIntentRepository::class)
            ->forTenantByModule($this->tenant())[self::MODULE] ?? null;
    }

    private function installForTenant(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant(), function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(self::MODULE),
            );
        });
    }

    /**
     * The class's tenant, **freshly out of the control-plane manager** every
     * time.
     *
     * `sharedTenant()` hands back the object provisioning made, and this class
     * clears the control manager repeatedly — after every collection, so that an
     * assertion is about the database rather than about the identity map. A
     * cleared manager leaves that object detached, and persisting a
     * `PurchaseIntent` pointing at a detached `Tenant` is Doctrine's "a new entity
     * was found through the relationship" rather than the row anybody wanted. So
     * the slug is what this class holds on to, and the entity is looked up again
     * each time.
     */
    private function tenant(): Tenant
    {
        $this->sharedTenant(self::SLUG, [self::HOST]);

        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::SLUG);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /** Signs an operator in and opens the purchase requests screen. */
    private function openPurchases(): Crawler
    {
        // Created once and left behind for the rest of the process, like the
        // shared tenant: the control plane is not rolled back, so making one per
        // test would be making one per test for ever.
        if (self::service(OperatorRepository::class)->findOneByEmail(self::OPERATOR) === null) {
            self::service(OperatorCreator::class)->create(self::OPERATOR, self::OPERATOR_NAME, self::PASSWORD);
        }

        $login = $this->client->request('GET', sprintf('https://%s/control/login', $this->controlPlaneHost));

        // Two tests open this page twice, and the sign-in page redirects an
        // operator who is already signed in — so submitting unconditionally would
        // fail on a crawler with no form in it. Asking whether the form is there
        // is the same question said in the shape the page answers.
        if ($login->filter('form')->count() > 0) {
            $this->client->submit($login->selectButton('Sign in')->form([
                'email' => self::OPERATOR,
                'password' => self::PASSWORD,
            ]));
        }

        $page = $this->client->request('GET', sprintf('https://%s/control/purchases', $this->controlPlaneHost));
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * The one thing this class writes outside the rollback.
     *
     * Collected rows are in the control plane, which DAMA deliberately does not
     * roll back. The tenant-side request *is* rolled back with the test, so it is
     * not here. Scoped to this class's own tenant rather than to the module key,
     * because the key is shared with the build and the tenant is not.
     */
    private function forgetEverything(): void
    {
        $manager = $this->controlManager();

        $manager->createQuery(
            'DELETE FROM ' . PurchaseIntent::class . ' p WHERE p.tenant IN ('
            . 'SELECT t.id FROM ' . Tenant::class . ' t WHERE t.slug = :slug)',
        )
            ->setParameter('slug', self::SLUG)
            ->execute();

        $manager->clear();
    }

    private function controlManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
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

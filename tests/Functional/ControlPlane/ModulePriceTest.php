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

use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Entity\Module;
use App\Registry\Entity\ModuleState;
use App\Registry\Entity\Tenant;
use App\Registry\Pricing\ModulePrice;
use App\Registry\Pricing\ModulePricing;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * A module can have a price, and setting it changes nothing anybody already has
 * (XIV-101).
 *
 * Four things are proved here, and the third is the one the ticket is actually
 * about.
 *
 *   1. **The three decisions are distinguishable, and a fourth is the absence of
 *      one.** Free, priced and not-for-sale are separate answers, and a module
 *      nobody has priced reads as none of them. That last part is the whole
 *      argument: if "no price set" collapsed into "free", every module would ship
 *      at zero on the day the column was added and nothing would say so.
 *   2. **The price gates the store together with the state, and neither alone.**
 *      Published and unpriced is withheld; published and free is offered;
 *      published and not-for-sale is withheld again.
 *   3. **Changing a price alters nothing a customer already has.** A real tenant,
 *      with the module really installed and a record really in it, is photographed
 *      before and after four price changes — including one that takes the module
 *      off the price list entirely — and every part of the photograph is
 *      identical. This is [XIV-67]'s argument about payment terms arriving at the
 *      same place: what was agreed is a fact about the transaction, not a live
 *      lookup, so raising a price must not restate what somebody already owes and
 *      must not take away what they already have.
 *   4. **The operator screen sets it, and opens no tenant connection doing so.**
 *      The boundary [XIV-58] built the tenant list around, kept on the second page
 *      of that surface.
 *
 * **This class owns the `job` key.** Module rows live in the control plane, which
 * DAMA deliberately does not roll back and every paratest worker shares, so two
 * classes deciding about one module at the same time is a race whose failure
 * lands in whichever of them lost. `ModuleStateTest` has `article`,
 * {@see \App\Tests\Functional\Tenant\ModuleStoreTest} has contact, order and
 * invoice, and `job` — a blueprint that exists only in the test build — is this
 * one's. The rows go by hand at both ends.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePriceTest extends WebTestCase
{
    use SharesATenant;

    /**
     * A blueprint only the test build ships, which is why this class can publish
     * and price it without stepping on anybody.
     */
    private const string MODULE = JobModule::KEY;

    private const string SLUG = 'test_price';
    private const string HOST = 'price.localhost';

    private const string OPERATOR = 'module-price@example.test';
    private const string OPERATOR_NAME = 'The Pricer';
    private const string PASSWORD = 'operator-password-101';

    private KernelBrowser $client;
    private ModuleCatalog $catalog;
    private string $controlPlaneHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request, because one test below asks
        // what state the tenant connection was left in afterwards — and a
        // rebooted kernel would hand back a fresh one nobody had touched.
        $this->client->disableReboot();

        $this->catalog = self::service(ModuleCatalog::class);
        $this->controlPlaneHost = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->forgetDecision();
    }

    protected function tearDown(): void
    {
        $this->forgetDecision();

        parent::tearDown();
    }

    // -- the four states, and the one that is not a decision -----------------

    /**
     * **The claim the acceptance criteria asked to be said plainly.**.
     *
     * A module nobody has priced does not read as free. Not by convention, not by
     * every caller remembering to check — the catalogue answers `unpriced`, the
     * amount is null rather than zero, and `mayBeOffered()` is false, which is
     * three separate readers unable to mistake it.
     */
    public function testAModuleNobodyHasPricedIsNotFree(): void
    {
        $price = $this->catalog->price(self::MODULE);

        self::assertSame(ModulePricing::Unpriced, $price->pricing);
        self::assertNotSame(ModulePricing::Free, $price->pricing);
        self::assertNull($price->amount, 'not zero, and not an empty string either');
        self::assertFalse($price->pricing->isDecided());
        self::assertFalse($price->mayBeOffered(), 'and so nothing offers it');
    }

    /**
     * And it stays not-free after somebody publishes it, which is the case that
     * matters: the row exists now, for the other decision, and the price column
     * has to have its own default in it.
     */
    public function testPublishingAModuleDoesNotPriceIt(): void
    {
        $this->catalog->moveTo(self::MODULE, ModuleState::Published);

        self::assertSame(ModulePricing::Unpriced, $this->fresh()->price(self::MODULE)->pricing);
        self::assertArrayNotHasKey(
            self::MODULE,
            $this->catalog->offeredInStore(),
            'published and unpriced is withheld, on purpose',
        );
    }

    public function testTheThreeDecisionsAreDistinguishable(): void
    {
        $this->catalog->priceAt(self::MODULE, ModulePrice::free());
        $free = $this->fresh()->price(self::MODULE);

        $this->catalog->priceAt(self::MODULE, ModulePrice::of('49.90'));
        $priced = $this->fresh()->price(self::MODULE);

        $this->catalog->priceAt(self::MODULE, ModulePrice::notForSale());
        $withheld = $this->fresh()->price(self::MODULE);

        self::assertSame(ModulePricing::Free, $free->pricing);
        self::assertFalse($free->costsMoney());
        self::assertTrue($free->mayBeOffered());

        self::assertSame(ModulePricing::Priced, $priced->pricing);
        self::assertTrue($priced->costsMoney());
        self::assertTrue($priced->mayBeOffered());
        self::assertSame('49.90', $priced->amount, 'a decimal string at two places, never a float');

        self::assertSame(ModulePricing::NotForSale, $withheld->pricing);
        self::assertFalse($withheld->costsMoney());
        self::assertFalse($withheld->mayBeOffered());

        // Three distinct answers to "may the store offer it", from three distinct
        // decisions — and none of the three is what an unpriced module says.
        self::assertNotSame($free->pricing, $priced->pricing);
        self::assertNotSame($free->pricing, $withheld->pricing);
        self::assertNotSame($priced->pricing, $withheld->pricing);
    }

    /**
     * Zero is refused, because a module priced at nothing is a free module said
     * in a way nothing can tell apart from an unfinished form.
     */
    public function testAPricedModuleCannotCostNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has to cost more than nothing');

        ModulePrice::of('0.00');
    }

    /** Rounded before it is judged, so 0.004 is refused as the 0.00 it would be stored as. */
    public function testAnAmountBelowHalfARappenIsNotAPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ModulePrice::of('0.004');
    }

    public function testAPriceIsRoundedAndNormalisedOnTheWayIn(): void
    {
        $this->catalog->priceAt(self::MODULE, ModulePrice::of('19.9'));

        self::assertSame('19.90', $this->fresh()->price(self::MODULE)->amount);
    }

    // -- the store gate, which needs both halves -----------------------------

    public function testTheStoreNeedsThePlatformAndTheDeploymentToBothSayYes(): void
    {
        $this->catalog->moveTo(self::MODULE, ModuleState::Published);
        $this->catalog->priceAt(self::MODULE, ModulePrice::of('49.00'));

        self::assertArrayHasKey(self::MODULE, $this->fresh()->offeredInStore());

        // The deployment withdraws it. The platform still says the module is
        // finished — nothing about the code changed — and it is still withheld.
        $this->catalog->priceAt(self::MODULE, ModulePrice::notForSale());

        self::assertArrayNotHasKey(self::MODULE, $this->fresh()->offeredInStore());
        self::assertSame(
            ModuleState::Published,
            $this->catalog->state(self::MODULE),
            'not for sale is not the same statement as unfinished, and does not become it',
        );
    }

    // -- the proof the ticket asked for --------------------------------------

    /**
     * **Changing a price touches nothing a customer already has.**.
     *
     * The instrument is a photograph rather than an assertion about the code
     * path: a real tenant, a real installation of the module, a real record in
     * it, and every observable fact about all three recorded before and compared
     * after. A test that checked "the price setter does not call the installer"
     * would pass for any number of ways of doing the wrong thing indirectly.
     *
     * The sequence deliberately includes the two changes somebody would be most
     * afraid of — a rise, and a withdrawal from the price list altogether — since
     * "we put it up and their module vanished" is the failure this exists to make
     * impossible.
     */
    public function testChangingAPriceInstallsAndUninstallsNothing(): void
    {
        $tenant = $this->tenant();

        $this->installForTenant();
        $recordId = $this->writeARecord();

        $before = $this->photograph();

        self::assertNotNull($before['definition'], 'the tenant really has the module');
        self::assertSame(1, $before['records'], 'and really has a record in it');

        $this->catalog->moveTo(self::MODULE, ModuleState::Published);
        $this->catalog->priceAt(self::MODULE, ModulePrice::free());
        $this->catalog->priceAt(self::MODULE, ModulePrice::of('49.00'));
        // The rise, and then the withdrawal. Both after the customer has it.
        $this->catalog->priceAt(self::MODULE, ModulePrice::of('99.00'));
        $this->catalog->priceAt(self::MODULE, ModulePrice::notForSale());

        $after = $this->photograph();

        // Named one at a time first, because `assertSame` on a whole array is a
        // strong assertion that reads as a weak one — and because the failure
        // message from a named assertion says which part of somebody's
        // installation moved.
        self::assertSame(self::MODULE, $after['definition'], 'the module is still installed');
        self::assertSame($before['table'], $after['table'], 'in the table it was installed into');
        self::assertSame($before['fields'], $after['fields'], 'their field definitions are untouched');
        self::assertSame($before['records'], $after['records'], 'no record appeared or vanished');
        self::assertSame($before['values'], $after['values'], 'and the record they wrote is unchanged');

        // The record is still readable and still theirs, which is the sentence a
        // customer would use rather than the one a schema comparison makes.
        self::assertNotNull($this->readRecord($recordId));

        // And then the whole photograph, which is what catches the part nobody
        // thought to name above.
        self::assertSame($before, $after, 'nothing about what the customer has moved');

        self::assertSame(
            'test_price',
            $tenant->getSlug(),
            'and this was all about one real customer, not a fixture row',
        );
    }

    /**
     * The other half of the same promise, from the store's side: a module taken
     * off the price list stops being *offered* and does not stop being *installed*.
     *
     * §6.2 already made this argument for state — a decision here says what may be
     * obtained from now on, never what is removed — and a price is the second
     * decision on the same row, so it inherits the rule rather than restating it.
     */
    public function testWithdrawingAModuleFromSaleDoesNotUninstallIt(): void
    {
        $this->tenant();
        $this->installForTenant();

        $this->catalog->moveTo(self::MODULE, ModuleState::Published);
        $this->catalog->priceAt(self::MODULE, ModulePrice::of('49.00'));
        $this->catalog->priceAt(self::MODULE, ModulePrice::notForSale());

        self::assertArrayNotHasKey(self::MODULE, $this->fresh()->offeredInStore());
        self::assertNotNull($this->photograph()['definition'], 'the customer keeps what they have');
    }

    // -- the operator screen -------------------------------------------------

    /**
     * The process the acceptance criteria asked for, driven the way an operator
     * does it: a form on the control-plane host, posted with its own token.
     */
    public function testAnOperatorSetsThePriceOnTheScreen(): void
    {
        $page = $this->openModules();

        self::assertStringContainsString('Not priced yet', $page->filter('body')->text());

        $this->submitPrice($page, 'priced', '49.00');

        self::assertResponseRedirects();
        $this->client->followRedirect();

        $price = $this->fresh()->price(self::MODULE);

        self::assertSame(ModulePricing::Priced, $price->pricing);
        self::assertSame('49.00', $price->amount);
    }

    /** The refusal reaches the operator as a sentence rather than as silence. */
    public function testTheScreenRefusesAPriceOfNothingAndSaysWhy(): void
    {
        $page = $this->openModules();

        $this->submitPrice($page, 'priced', '0');

        self::assertResponseRedirects();
        $text = $this->client->followRedirect()->filter('body')->text();

        self::assertStringContainsString('has to cost more than nothing', $text);
        self::assertSame(
            ModulePricing::Unpriced,
            $this->fresh()->price(self::MODULE)->pricing,
            'and nothing was written',
        );
    }

    /**
     * **The screen opens no tenant connection**, which is the boundary [XIV-58]
     * built the page next door around and which a second page on that surface is
     * where somebody would first break.
     *
     * Asserted the same way `TenantListTest` asserts it, including the second half
     * that keeps the first from being vacuous: the connection really would have
     * failed if anything had reached for it, because a control-plane request
     * resolves no tenant at all (§8.9).
     */
    public function testTheScreenOpensNoTenantConnection(): void
    {
        $this->openModules();

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        self::assertFalse($connection->isConnected(), 'the customer databases were left alone');
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Everything observable about what this customer has, in one array.
     *
     * Deliberately read through the tenant's own metadata and records rather than
     * through anything in `App\Registry`: §6.1 makes the customer's definitions
     * the truth once a module is installed, so this is the side of the boundary
     * the question is actually about.
     *
     * The shape is wide on purpose. A comparison that only counted records would
     * pass for a price change that silently relabelled every field, and the point
     * of a photograph is that it catches the thing nobody thought to assert.
     *
     * @return array{definition: string|null, table: string|null, fields: list<string>, records: int, values: list<array<string, mixed>>}
     */
    private function photograph(): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant(), static function (): array {
            $definition = self::service(MetadataRepository::class)->find(self::MODULE);

            if ($definition === null) {
                return ['definition' => null, 'table' => null, 'fields' => [], 'records' => 0, 'values' => []];
            }

            $fields = [];

            foreach ($definition->getFields() as $field) {
                // Key, label and requiredness, so that a change to any of the
                // three would show — a price change must not relabel a field any
                // more than it may delete one.
                $fields[] = sprintf(
                    '%s|%s|%s',
                    $field->getKey(),
                    $field->getLabel(),
                    $field->isRequired() ? 'required' : 'optional',
                );
            }

            $repository = self::service(RecordRepository::class);
            \assert($repository instanceof RecordRepository);

            return [
                'definition' => $definition->getKey(),
                'table' => $definition->getTableName(),
                'fields' => $fields,
                'records' => $repository->countAll($definition),
                'values' => array_map(
                    static fn (Record $record): array => $record->data,
                    $repository->findAll($definition),
                ),
            ];
        });
    }

    private function installForTenant(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant(), static function (): void {
            if (self::service(MetadataRepository::class)->find(self::MODULE) !== null) {
                return;
            }

            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(self::MODULE),
            );
        });
    }

    /**
     * A record the customer wrote before anybody thought about prices.
     *
     * Through `RecordWriter`, the way the application does it, rather than an
     * `INSERT`: a row nothing derived and nothing wrote history for would not be
     * the thing the assertion is about (XIV-73).
     */
    private function writeARecord(): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant(), static function (): int {
            $module = self::service(MetadataRepository::class)->get(self::MODULE);
            $record = new Record(data: [
                'title' => 'Bought before the price went up',
                'status' => JobModule::DRAFT,
            ]);

            self::service(RecordWriter::class)->save($module, $record, []);

            return (int) $record->id;
        });
    }

    /** Still there, still readable, still theirs. */
    private function readRecord(int $id): ?Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant(), static function () use ($id): ?Record {
            $module = self::service(MetadataRepository::class)->get(self::MODULE);

            return self::service(RecordRepository::class)->find($module, $id);
        });
    }

    private function tenant(): Tenant
    {
        return $this->sharedTenant(self::SLUG, [self::HOST]);
    }

    /** Signs an operator in and opens the pricing screen. */
    private function openModules(): Crawler
    {
        // Created once and left behind for the rest of the process, like the
        // shared tenant: the control plane is not rolled back, so making one per
        // test would be making one per test for ever.
        if (self::service(OperatorRepository::class)->findOneByEmail(self::OPERATOR) === null) {
            self::service(OperatorCreator::class)->create(self::OPERATOR, self::OPERATOR_NAME, self::PASSWORD);
        }

        $login = $this->client->request('GET', sprintf('https://%s/control/login', $this->controlPlaneHost));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($login->selectButton('Sign in')->form([
            'email' => self::OPERATOR,
            'password' => self::PASSWORD,
        ]));

        $page = $this->client->request('GET', sprintf('https://%s/control/modules', $this->controlPlaneHost));
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * Posts this module's row of the form, the way pressing its Save button does.
     *
     * Through the crawler's own form object rather than a hand-built POST, so the
     * CSRF token, the action and the field names all come off the page that was
     * actually rendered — a hand-built post would keep passing after the template
     * stopped agreeing with the controller.
     */
    private function submitPrice(Crawler $page, string $pricing, string $amount): void
    {
        $form = $page
            ->filter(sprintf('form[action$="/control/modules/%s/price"]', self::MODULE))
            ->form();

        $this->client->submit($form, ['pricing' => $pricing, 'amount' => $amount]);
    }

    /** Read past the identity map, so an assertion is about the database. */
    private function fresh(): ModuleCatalog
    {
        $this->controlManager()->clear();

        return $this->catalog;
    }

    private function forgetDecision(): void
    {
        $manager = $this->controlManager();

        $manager->createQuery('DELETE FROM ' . Module::class . ' m WHERE m.key = :key')
            ->setParameter('key', self::MODULE)
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

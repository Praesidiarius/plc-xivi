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
use App\Registry\Pricing\ModulePrice;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\ModulePurchaseIntent;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\ModulePurchaseIntentRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\StoreAction;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Module\GrownModule;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\PermissionVerb;

/**
 * The store shows what a module costs, and a paid install stops at a placeholder
 * (XIV-102).
 *
 * Six things are proved here, and the second is the one the whole ticket is
 * about — get it wrong and the price is decorative.
 *
 *   1. **A price is shown where there is one, and nowhere else.** A priced
 *      module's figure is on its tile and on its page; a free one says nothing at
 *      all about money, because absence of a price is the ordinary case in this
 *      store and the ordinary case should look ordinary.
 *   2. **Asking for a priced module installs nothing.** Proved by looking for the
 *      module in the customer's own database afterwards and finding it absent —
 *      not by asserting that the installer was not called, which would pass for
 *      any number of ways of doing the wrong thing indirectly. Proved twice: once
 *      through the placeholder, and once through a retyped POST to the install
 *      route, because a hidden button is not a check.
 *   3. **The placeholder is not a payment page.** It has exactly one input on it
 *      — the CSRF token — and it says in as many words that nothing is charged.
 *      A form that looks like checkout and quietly does nothing is worse than a
 *      sentence, and this is the assertion that fails when somebody makes the
 *      page "friendlier".
 *   4. **Buying is its own grant.** Somebody who may install may not thereby buy,
 *      and the refusal is real rather than a missing button — the same pair
 *      {@see ModuleStoreTest} proves for install, because a control that is not
 *      drawn is not a check.
 *   5. **A module priced after installation keeps working.** §6.5's rule from the
 *      customer's side: a price says what may be obtained from now on, never what
 *      is taken away.
 *   6. **An unset currency shows a bare number.** Which is the state this test
 *      suite genuinely runs in — `PRICE_CURRENCY` is empty in `.env` — and is a
 *      real answer rather than a gap: §8.6 refuses to guess a currency for a
 *      customer, and guessing one for a price list would be the same mistake one
 *      level up.
 *
 * **This class owns the `grown` key's pricing decision.** Module rows live in the
 * control plane, which DAMA deliberately does not roll back and every paratest
 * worker shares, so two classes deciding about one module at once is a race whose
 * failure lands in whichever of them lost. `ModulePriceTest` has `job`,
 * {@see ModuleStoreTest} has contact, order and invoice, `ModuleStateTest` has
 * article, and `grown` — which exists only in the test build and whose other user
 * ({@see \App\Tests\Functional\Engine\ModuleUpgradeTest}) never reads its state or
 * its price — is this one's. The row goes by hand at both ends.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePurchaseTest extends WebTestCase
{
    use SharesATenant;

    /** Priced by this class, and forgotten again in tearDown. */
    private const string MODULE = GrownModule::KEY;

    /** What the module costs while these tests run. Not round, so a stray "0.00" cannot pass for it. */
    private const string PRICE = '49.00';

    private const string SLUG = 'test_buy';
    private const string HOST = 'buy.localhost';

    /** Holds every store verb there is, so the price screens are reachable. */
    private const string BUYER = 'buyer@buy.test';
    /** Holds browse and install and deliberately not buy — half of criterion 4. */
    private const string INSTALLER = 'installer@buy.test';
    private const string PASSWORD = 'buy-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::BUYER, 'The Buyer', self::PASSWORD, []);
        $users->create($this->tenant, self::INSTALLER, 'The Installer', self::PASSWORD, []);

        $this->grantStore(self::BUYER, StoreAction::Browse);
        $this->grantStore(self::BUYER, StoreAction::Install);
        $this->grantStore(self::BUYER, StoreAction::Buy);

        $this->grantStore(self::INSTALLER, StoreAction::Browse);
        $this->grantStore(self::INSTALLER, StoreAction::Install);

        $this->forgetDecision();
    }

    protected function tearDown(): void
    {
        $this->forgetDecision();

        parent::tearDown();
    }

    // -- what the store says about money -------------------------------------

    /**
     * The figure is on the tile and on the module's page, in both places read
     * through the catalogue rather than through a second seam (§6.5).
     */
    public function testAPricedModuleShowsWhatItCosts(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $index = $this->client->request('GET', $this->url('/store'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::PRICE, $this->cardFor($index)->text());

        $page = $this->client->request('GET', $this->url('/store/' . self::MODULE));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::PRICE, $page->filter('main')->text());
    }

    /**
     * **And a free module is silent about it**, which is the half that makes this
     * ticket invisible to a deployment that has priced nothing.
     *
     * Not a "Free" badge and not a zero. Almost everything in this store is free
     * and a badge on every tile would be noise on every tile; more importantly, a
     * page that says "Free" everywhere is a page that has taught its reader to
     * stop reading that line, which is exactly the line that matters on the one
     * module that is not.
     */
    public function testAFreeModuleSaysNothingAboutMoney(): void
    {
        $this->price(ModulePrice::free());
        $this->signIn(self::BUYER);

        $index = $this->client->request('GET', $this->url('/store'));
        self::assertResponseIsSuccessful();

        $card = $this->cardFor($index);
        self::assertStringNotContainsString('0.00', $card->text());
        self::assertStringNotContainsString('Free', $card->text());

        $page = $this->client->request('GET', $this->url('/store/' . self::MODULE));
        self::assertResponseIsSuccessful();

        // The install button, exactly as it was before this ticket existed.
        self::assertCount(1, $page->filter(sprintf('a[href$="/store/%s/install"]', self::MODULE)));
        self::assertCount(0, $page->filter(sprintf('a[href$="/store/%s/buy"]', self::MODULE)));
    }

    // -- the placeholder, and the criterion that decides the ticket ----------

    /**
     * **Asking for a priced module records the request and installs nothing.**.
     *
     * The absence is checked in the customer's own database — §6.1 makes their
     * metadata the truth about what they have — rather than by asserting
     * something about which methods were called. The request is checked in the
     * same database, because §4.4 leaves nowhere else for a customer's own write
     * to go.
     */
    public function testAskingForAPricedModuleRecordsARequestAndInstallsNothing(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $this->ask();

        self::assertNull($this->definitionOf(self::MODULE), 'nothing was installed');

        $intent = $this->intent();
        self::assertInstanceOf(ModulePurchaseIntent::class, $intent, 'the request was written down');
        self::assertSame(self::MODULE, $intent->getModuleKey());

        // The price is a **copy** taken at the moment of asking, which [XIV-101]
        // left as an instruction rather than a suggestion (§6.5): nothing about a
        // sale is ever recomputed from the module row afterwards.
        self::assertSame(self::PRICE, $intent->getPriceAmount());
        // And a bare number, because this installation has never said what it
        // sells in. See the currency test below.
        self::assertNull($intent->getPriceCurrency());
        self::assertSame('The Buyer', $intent->getRequestedByLabel());
    }

    /**
     * **A raised price does not follow the request that was already made.**.
     *
     * The forward half of §6.5's rule, from the side that can be seen: what was
     * agreed is a fact about the transaction rather than a live lookup, exactly
     * as an invoice keeps its own totals (§5.9) and its own due date (§5.16). A
     * row that read the module's price whenever it was displayed would let a
     * request made at 49.00 be fulfilled at 149.00 with nothing anywhere
     * recording that the two figures were ever different.
     */
    public function testRaisingThePriceDoesNotRewriteARequestAlreadyMade(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);
        $this->ask();

        $this->price(ModulePrice::of('149.00'));

        self::assertSame(self::PRICE, $this->intent()?->getPriceAmount());
    }

    /**
     * **A hidden button is not a check**, so the install route is retyped.
     *
     * The store page for a priced module draws no install button at all, and this
     * is the request a browser's history, a bookmark, or a page left open while an
     * operator set a price will produce. It has to be refused by the store rather
     * than by the template, and the module has to be absent afterwards.
     */
    public function testARetypedInstallOfAPricedModuleInstallsNothing(): void
    {
        // Free first, so a real install token can be read off a wizard this
        // session is allowed to open — the token is deliberately valid, so that
        // what is being tested is the refusal and not the CSRF check.
        $this->price(ModulePrice::free());
        $this->signIn(self::BUYER);

        $wizard = $this->client->request('GET', $this->url(sprintf('/store/%s/install', self::MODULE)));
        self::assertResponseIsSuccessful();
        $token = (string) $wizard->filter('input[name="_token"]')->attr('value');

        // And now it costs money, exactly as it would if an operator had set a
        // price while this tab was open.
        $this->price(ModulePrice::of(self::PRICE));

        $this->client->request('POST', $this->url(sprintf('/store/%s/install', self::MODULE)), [
            '_token' => $token,
        ]);
        $this->client->followRedirect();

        self::assertNull($this->definitionOf(self::MODULE), 'nothing was installed');
        self::assertNull($this->intent(), 'and asking to install is not asking to buy');
        self::assertSelectorTextContains('.alert', 'has a price');
    }

    /**
     * **The placeholder collects nothing and claims nothing.**.
     *
     * One input on the page, and it is the CSRF token. No field of any kind that
     * somebody could type a card number into, disabled or otherwise; no total; no
     * word suggesting a charge has happened or is about to. This assertion is
     * blunt on purpose — it is the one that goes red when a later ticket makes the
     * page friendlier by adding a field, which is exactly how a page like this
     * turns into a page that looks like it takes money.
     */
    public function testThePlaceholderIsNotAPaymentPage(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $page = $this->client->request('GET', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseIsSuccessful();

        $inputs = $page->filter('main input, main select, main textarea');
        self::assertCount(1, $inputs, 'the only input on the page is the CSRF token');
        self::assertSame('_token', $inputs->attr('name'));
        self::assertSame('hidden', $inputs->attr('type'));

        $text = $page->filter('main')->text();
        self::assertStringContainsString(self::PRICE, $text, 'it says what the module costs');
        self::assertStringContainsString('Nothing is charged', $text);
        self::assertStringContainsString('get in touch', $text);
    }

    /**
     * And the flash afterwards says what happened rather than congratulating
     * anybody. "Requested", not "ordered"; nothing charged; a person next.
     */
    public function testAskingSaysWhatHappenedAndNotThankYouForYourPurchase(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $this->ask();

        self::assertSelectorTextContains('.alert', 'Nothing has been charged');
    }

    /**
     * Asking again rewrites the row rather than adding one.
     *
     * Somebody who presses the button twice is asking again, most likely because
     * nobody replied — and an operator's queue full of duplicates is a queue that
     * stops being read. §8.12's `reissue()` for the same reason, one layer up.
     */
    public function testAskingTwiceRewritesTheRequestRatherThanAddingOne(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $this->ask();
        $first = $this->intent()?->getId();

        $this->ask();

        self::assertSame($first, $this->intent()?->getId());
        self::assertSame(1, $this->countIntents());
    }

    // -- the permission, which is its own -------------------------------------

    /**
     * **Holding `install` does not carry the authority to buy**, and the refusal
     * is real rather than a missing button.
     *
     * The pair {@see ModuleStoreTest} draws for install, drawn again one verb
     * along, because the interesting failure is a screen that hides a control the
     * server would have honoured. The argument for why these are two grants is on
     * {@see StoreAction::Buy}: one is authority over what this installation
     * consists of, the other is authority over the company's money, and they
     * belong to the same person only by coincidence.
     */
    public function testInstallingCarriesNoAuthorityToBuy(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::INSTALLER);

        $page = $this->client->request('GET', $this->url('/store/' . self::MODULE));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter(sprintf('a[href$="/store/%s/buy"]', self::MODULE)), 'no button');

        $this->client->request('GET', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and no page behind it either');

        $this->client->request('POST', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->intent(), 'and nothing was recorded on the way past');
    }

    // -- what happens to somebody who already has it --------------------------

    /**
     * **A module that becomes priced does not stop working.**.
     *
     * §6.5 proves this against the control plane with a photograph; this is the
     * customer's side of the same rule. Somebody who installed a module while it
     * was free keeps it, their definitions are untouched, the store says it is
     * theirs, and nothing anywhere treats "priced and installed" as an anomaly to
     * correct — no buy button, no warning, no offer to pay retrospectively.
     */
    public function testAModulePricedAfterInstallationKeepsWorking(): void
    {
        $this->price(ModulePrice::free());
        $this->installForTenant();

        $before = $this->fieldsOf(self::MODULE);
        self::assertNotSame([], $before);

        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $page = $this->client->request('GET', $this->url('/store/' . self::MODULE));
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->fieldsOf(self::MODULE), 'their module is exactly as it was');
        self::assertStringContainsString('You already have this module', $page->filter('main')->text());
        self::assertCount(0, $page->filter(sprintf('a[href$="/store/%s/buy"]', self::MODULE)));
        self::assertCount(0, $page->filter(sprintf('a[href$="/store/%s/install"]', self::MODULE)));

        // And the placeholder cannot be reached by typing its URL either: there
        // is nothing to ask for.
        $this->client->request('GET', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseRedirects();
        self::assertNull($this->intent());
    }

    // -- the currency, which may legitimately be unset ------------------------

    /**
     * **An unset currency is a bare number, and the customer is told nothing
     * about why.**.
     *
     * `PRICE_CURRENCY` is empty in this repository's `.env`, so this is the state
     * the suite actually runs in rather than one arranged for the assertion.
     * §8.6 refuses to guess a currency for a customer because a guessed one is
     * wrong quietly and surfaces on the first priced thing they print; the same
     * holds for a price list, so nothing is invented.
     *
     * The operator's screen names the variable in this situation because an
     * operator can go and set it. A customer cannot, so their screen says nothing
     * — a sentence about somebody else's environment file is a deployment detail
     * in place of an answer.
     */
    public function testAnUnsetCurrencyIsABareNumberAndNotAGuess(): void
    {
        $this->price(ModulePrice::of(self::PRICE));
        $this->signIn(self::BUYER);

        $page = $this->client->request('GET', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseIsSuccessful();

        $text = $page->filter('main')->text();
        self::assertStringContainsString(self::PRICE, $text);
        self::assertStringNotContainsString('CHF', $text);
        self::assertStringNotContainsString('EUR', $text);
        self::assertStringNotContainsString('PRICE_CURRENCY', $text);
    }

    // -- helpers -------------------------------------------------------------

    /** Presses the button on the placeholder, the way a customer does. */
    private function ask(): void
    {
        $page = $this->client->request('GET', $this->url(sprintf('/store/%s/buy', self::MODULE)));
        self::assertResponseIsSuccessful();

        // Through the crawler's own form, so the action, the token and the method
        // all come off the page that was rendered — a hand-built POST would keep
        // passing after the template stopped agreeing with the controller.
        $this->client->submit($page->filter('main form')->form());

        // A write answers with a redirect, never with a page — otherwise reload
        // repeats it. Asserted here rather than assumed, because a rendered 200
        // from this route means the CSRF check silently declined and every
        // assertion after it would be about a request that did nothing.
        self::assertResponseRedirects();

        $this->client->followRedirect();
    }

    /** This module's card on the store index. */
    private function cardFor(Crawler $index): Crawler
    {
        $card = $index->filter(sprintf('.card:has(a[href$="/store/%s"])', self::MODULE));

        self::assertCount(1, $card, sprintf('"%s" is on the store index', self::MODULE));

        return $card;
    }

    /** Published *and* priced, because since [XIV-101] the store needs both. */
    private function price(ModulePrice $price): void
    {
        $catalog = self::service(ModuleCatalog::class);
        $catalog->moveTo(self::MODULE, ModuleState::Published);
        $catalog->priceAt(self::MODULE, $price);
    }

    private function intent(): ?ModulePurchaseIntent
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?ModulePurchaseIntent => self::service(ModulePurchaseIntentRepository::class)
                ->findOneByModule(self::MODULE),
        );
    }

    private function countIntents(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => \count(self::service(ModulePurchaseIntentRepository::class)->allByModule()),
        );
    }

    /** Installs straight into the tenant, bypassing the store: a starting state. */
    private function installForTenant(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(self::MODULE),
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

    /**
     * Every field the customer has on a module, by key and label.
     *
     * Enough of a photograph for the question being asked: the point is that
     * pricing a module afterwards changes nothing about what somebody already
     * has, and a comparison that only counted would pass for a change that
     * silently relabelled all of them.
     *
     * @return array<string, string>
     */
    private function fieldsOf(string $module): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module): array {
            $definition = self::service(MetadataRepository::class)->find($module);

            if ($definition === null) {
                return [];
            }

            $fields = [];

            foreach ($definition->getFields() as $field) {
                $fields[$field->getKey()] = $field->getLabel();
            }

            ksort($fields);

            return $fields;
        });
    }

    private function grantStore(string $email, StoreAction $action): void
    {
        $this->grant($email, StoreAction::SUBJECT, $action);
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

    /**
     * The control plane is not rolled back, so the row goes by hand.
     *
     * Deleted rather than moved back to development and unpriced: a row saying
     * "somebody decided about this" is exactly the fact §6.2 keeps, and leaving
     * one behind would be this class's opinion turning up in somebody else's run.
     */
    private function forgetDecision(): void
    {
        $manager = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        $manager->createQuery('DELETE FROM ' . Module::class . ' m WHERE m.key = :key')
            ->setParameter('key', self::MODULE)
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

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

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Voucher\VoucherModule;

/**
 * A customer who sells services, and the kind they are not offered (XIV-103).
 *
 * The decision under test is the one the ticket asked to be *made*: whether the
 * voucher module `requires` the article module. It does not, and this class is
 * what makes that a property rather than an intention.
 *
 * The reasoning is [XIV-23]'s, unchanged. `requires` is per module and only one
 * kind of three needs an article, so requiring it would mean a customer who wants
 * `GIVE-10` off a total cannot have vouchers at all unless they also keep a
 * catalogue — a whole module refused over a kind they were never going to use.
 * `uses` says the other thing: install it, and do not offer the part that cannot
 * work.
 *
 * **Whether hiding a kind already existed was the real question**, and it did.
 * {@see \Xivi\Core\Metadata\AvailableVariants} has hidden a variant whose
 * *required* reference points at an uninstalled module since [XIV-23], for the
 * order module's article lines; what was untested until now is that it does the
 * same for a **module's own** variants rather than only for a collection's row
 * kinds. It is the same class asked about a different shape, and both the
 * "which kind" chooser and the record form already ask it. Nothing had to be
 * built. This class is the evidence for that sentence.
 *
 * A tenant of its own, because the fact under test is the *absence* of a module
 * and the shared voucher tenant has articles in it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherWithoutArticlesTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_voucher_alone';
    private const string HOST = 'voucher-alone.localhost';
    private const string EMAIL = 'alone@example.test';
    private const string PASSWORD = 'alone-password';
    private const string FORM = 'module_record';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(VoucherModule::KEY),
            );
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Alone', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * The install succeeds, which is the whole difference between `uses` and
     * `requires` and the reason the decision matters.
     */
    public function testTheModuleInstallsWithNoArticleModuleAnywhere(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $metadata = self::service(MetadataRepository::class);

            self::assertNotNull($metadata->find(VoucherModule::KEY), 'vouchers are installed');
            self::assertNull($metadata->find(ArticleModule::KEY), 'and articles are not');
        });
    }

    /**
     * Two kinds offered, not three, and the missing one is missing rather than
     * broken.
     *
     * §7.6 has a second answer for a link into a module a customer does not have
     * — it matches nothing and reads as `#id` — and that answer is right for a
     * voucher created while articles existed and read after they were removed. It
     * is the wrong answer *here*: offering somebody a kind whose only meaningful
     * field is a picker with nothing in it is broken rather than degraded, which
     * is exactly the distinction [XIV-23] drew.
     */
    public function testTheFreeArticleKindIsNotOffered(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/voucher/new'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('a[href*="variant="]'));
        self::assertStringContainsString('variant=' . VoucherModule::ABSOLUTE, $crawler->html());
        self::assertStringContainsString('variant=' . VoucherModule::RELATIVE, $crawler->html());
        self::assertStringNotContainsString('variant=' . VoucherModule::FREE_ARTICLE, $crawler->html());
    }

    /**
     * And it cannot be reached by typing the URL either.
     *
     * The chooser hiding a kind is a page; the form refusing it is the rule. An
     * unavailable variant falls back to the chooser rather than drawing a form
     * with an empty picker on it, which is what `ModuleController::new()` does
     * with any variant that is not in the available set.
     */
    public function testTheHiddenKindCannotBeReachedByHand(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/voucher/new?variant=' . VoucherModule::FREE_ARTICLE));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What kind');
        self::assertCount(0, $crawler->filter(sprintf('[name="%s[fields][%s]"]', self::FORM, VoucherModule::ARTICLE)));
    }

    /** The other two work exactly as they do for a customer who sells things. */
    public function testTheOtherTwoKindsAreOrdinaryVouchers(): void
    {
        $absolute = $this->saveRecord(
            VoucherModule::KEY,
            ['code' => 'GIVE-10', 'kind' => VoucherModule::ABSOLUTE, 'amount' => '10.00'],
            variant: VoucherModule::ABSOLUTE,
        );

        $relative = $this->saveRecord(
            VoucherModule::KEY,
            ['code' => 'HALF-OFF', 'kind' => VoucherModule::RELATIVE, 'percentage' => '50'],
            variant: VoucherModule::RELATIVE,
        );

        self::assertGreaterThan(0, $this->savedId($absolute));
        self::assertGreaterThan(0, $this->savedId($relative));
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
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

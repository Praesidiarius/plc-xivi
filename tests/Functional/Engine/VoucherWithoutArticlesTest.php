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
 * A customer who sells services, and what they are offered (XIV-103, XIV-122).
 *
 * The decision under test is the one [XIV-103] was asked to *make*: whether the
 * voucher module `requires` the article module. It does not, and that decision is
 * unchanged. **What changed is how the consequence is arranged, and this class is
 * where the change is recorded rather than discovered.**
 *
 * ### What [XIV-103] built, and why it stopped firing
 *
 * The article link was `required: true`, and that was deliberately load-bearing
 * twice: once for its own sake, and once because
 * {@see \Xivi\Core\Metadata\AvailableVariants} hides a variant whose *required*
 * reference points at a module the customer has not installed. So a services-only
 * customer was offered two kinds of three, and the free-article kind was not there
 * to be chosen wrongly.
 *
 * [XIV-122] made the link **optional**, because that is what the feature became:
 * a line voucher may be restricted to one article or may go on any line at all,
 * custom lines included — and a custom line has no article, which is exactly why
 * the restriction cannot be the targeting mechanism. `AvailableVariants` says
 * nothing about an optional reference, correctly, so **the guard no longer
 * fires.**
 *
 * That is the right outcome rather than a regression. "Ten francs off one line" is
 * a perfectly good voucher for somebody who keeps no catalogue, and hiding it
 * would refuse them a feature that works — which is the opposite of what [XIV-23]
 * hides a kind *for*. So all four kinds are offered here, and the assertions below
 * say so on purpose.
 *
 * ### The empty picker is still prevented, one class over
 *
 * What [XIV-23] was really avoiding is a control that cannot be filled in, and
 * that has not stopped being worth avoiding. {@see \Xivi\Core\Module\AvailableFields}
 * now takes an **optional** variant-scoped reference away from a customer who
 * cannot fill it in — precisely the case `AvailableVariants` leaves alone, so the
 * two rules cover every reference exactly once between them. The kind is offered;
 * the restriction is simply not a field this customer has.
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
     * **All four kinds are offered, and that is [XIV-122]'s deliberate loss of
     * [XIV-103]'s guard.**.
     *
     * Asserted as four rather than left to be noticed, because the previous
     * version of this method asserted two and a reader comparing the two revisions
     * is reading the decision. A line voucher with no article restriction is
     * useful to somebody with no articles, so there is nothing here to hide.
     */
    public function testEveryKindIsOfferedEvenWithNoCatalogue(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/voucher/new'));

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('a[href*="variant="]'));

        foreach ([
            VoucherModule::ORDER_AMOUNT,
            VoucherModule::ORDER_PERCENTAGE,
            VoucherModule::LINE_AMOUNT,
            VoucherModule::LINE_PERCENTAGE,
        ] as $kind) {
            self::assertStringContainsString('variant=' . $kind, $crawler->html());
        }
    }

    /**
     * **And the restriction is not a field this customer has**, which is where
     * the empty picker went.
     *
     * The form for a line voucher draws every control it should and not the one it
     * cannot fill in. Both halves are asserted in the same breath, because a check
     * that only looked for the absence would pass just as happily against a page
     * that failed to render.
     */
    public function testTheArticleRestrictionIsNotInstalledAtAll(): void
    {
        $crawler = $this->client->request(
            'GET',
            $this->url('/m/voucher/new?variant=' . VoucherModule::LINE_PERCENTAGE),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::control(VoucherModule::PERCENTAGE)), 'a real voucher form');
        self::assertCount(0, $crawler->filter(self::control(VoucherModule::ARTICLE)));

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $module = self::service(MetadataRepository::class)->find(VoucherModule::KEY);

            self::assertNotNull($module);
            self::assertNull(
                $module->getField(VoucherModule::ARTICLE),
                'not merely hidden on the page — never installed, which is what makes it invisible everywhere',
            );
        });
    }

    /** All four kinds save, exactly as they do for a customer who sells things. */
    public function testEveryKindIsAnOrdinaryVoucher(): void
    {
        $saved = [
            $this->saveRecord(
                VoucherModule::KEY,
                ['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00'],
                variant: VoucherModule::ORDER_AMOUNT,
            ),
            $this->saveRecord(
                VoucherModule::KEY,
                ['code' => 'HALF-OFF', 'kind' => VoucherModule::ORDER_PERCENTAGE, 'percentage' => '50'],
                variant: VoucherModule::ORDER_PERCENTAGE,
            ),
            $this->saveRecord(
                VoucherModule::KEY,
                ['code' => 'FIVE-A-LINE', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '5.00'],
                variant: VoucherModule::LINE_AMOUNT,
            ),
            $this->saveRecord(
                VoucherModule::KEY,
                ['code' => 'TENTH-A-LINE', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
                variant: VoucherModule::LINE_PERCENTAGE,
            ),
        ];

        foreach ($saved as $response) {
            self::assertGreaterThan(0, $this->savedId($response));
        }
    }

    private static function control(string $field): string
    {
        return sprintf('[name="%s[fields][%s]"]', self::FORM, $field);
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

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
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Rows of a collection that are not all the same thing (XIV-20).
 *
 * §5.5 one level down: a contact is a person or a company, and a line is an item
 * or a comment. The mechanism is the same choice field deciding which of the
 * shape's fields apply — what was missing was a collection being allowed to
 * declare one, and a form that could ask the row rather than the shape.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionKindsTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_row_kinds';
    private const string HOST = 'rowkinds.localhost';
    private const string EMAIL = 'kinds@example.test';
    private const string PASSWORD = 'kinds-password';
    private const string FORM = 'module_record';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(JobModule::KEY),
            ),
        );

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Kinds', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * One blank row per kind, which is how a kind gets chosen without any
     * JavaScript: you type in the one you want.
     */
    public function testTheFormOffersABlankRowForEachKind(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/job/new'));

        self::assertSame(
            [JobModule::ITEM, JobModule::COMMENT],
            $crawler->filter('[name$="[fields][kind]"]')->each(
                static fn (Crawler $node): string => (string) $node->attr('value'),
            ),
        );
    }

    /** And each blank row asks only for what its kind has. */
    public function testEachBlankRowAsksOnlyForItsOwnFields(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/job/new'));

        // The item row: text and an amount. The comment row: text alone.
        self::assertCount(1, $crawler->filter(sprintf('[name="%s"]', self::line(0, 'amount'))));
        self::assertCount(1, $crawler->filter(sprintf('[name="%s"]', self::line(0, 'text'))));
        self::assertCount(0, $crawler->filter(sprintf('[name="%s"]', self::line(1, 'amount'))));
        self::assertCount(1, $crawler->filter(sprintf('[name="%s"]', self::line(1, 'text'))));
    }

    /** A derived value is shown and never taken. */
    public function testADerivedFieldIsShownWithoutBeingOffered(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/job/new'));
        $total = $crawler->filter(sprintf('[name="%s"]', self::line(0, 'line_total')));

        self::assertCount(1, $total, 'it is on the form');
        self::assertNotNull($total->attr('disabled'), 'and not for typing');
    }

    /** Rows of both kinds save, and each keeps only its own fields. */
    public function testRowsOfDifferentKindsAreSavedTogether(): void
    {
        $id = $this->aJobWithLines();

        $page = $this->client->request('GET', $this->url('/m/job/' . $id))->filter('main')->text();

        self::assertStringContainsString('Rewire the office', $page);
        self::assertStringContainsString('Cabling', $page, 'the item');
        self::assertStringContainsString('Anything below is optional', $page, 'the comment');
    }

    /** An existing row keeps the kind it was created as. */
    public function testAnExistingRowKeepsItsKind(): void
    {
        $id = $this->aJobWithLines();

        $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));

        // Row 0 is the item that was saved: it still has an amount.
        self::assertCount(1, $crawler->filter(sprintf('[name="%s"]', self::line(0, 'amount'))));
        // Row 1 is the comment: it still has none.
        self::assertCount(0, $crawler->filter(sprintf('[name="%s"]', self::line(1, 'amount'))));
        self::assertSame(
            JobModule::COMMENT,
            $crawler->filter(sprintf('[name="%s"]', self::line(1, 'kind')))->attr('value'),
        );
    }

    /**
     * A kind with nothing but text is not a special case: an empty row is still
     * an empty row, and a comment that says something is still saved.
     */
    public function testAKindWithNoPriceIsSavedLikeAnyOther(): void
    {
        $this->client->request('GET', $this->url('/m/job/new'));
        $this->client->submitForm('Save', [
            self::field('title') => 'Just a note',
            self::field('status') => JobModule::DRAFT,
            self::line(1, 'text') => 'Nothing to charge for',
        ]);

        $this->client->followRedirect();
        $page = $this->client->getCrawler()->filter('main')->text();

        self::assertStringContainsString('Nothing to charge for', $page);
    }

    // -- helpers ------------------------------------------------------------

    private function aJobWithLines(): int
    {
        $this->client->request('GET', $this->url('/m/job/new'));
        $this->client->submitForm('Save', [
            self::field('title') => 'Rewire the office',
            self::field('status') => JobModule::DRAFT,
            self::line(0, 'text') => 'Cabling',
            self::line(0, 'amount') => '240.00',
            self::line(1, 'text') => 'Anything below is optional',
        ]);

        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private static function line(int $index, string $key): string
    {
        return sprintf('%s[collections][lines][%d][fields][%s]', self::FORM, $index, $key);
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

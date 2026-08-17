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
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SavesRecords;
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
    use SavesRecords;
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
     * A button per kind, and nothing drawn until one is pressed (XIV-29).
     *
     * The form used to end with one blank row of each kind, which is how a kind
     * got chosen when the page could not add one.
     */
    public function testTheFormOffersAButtonForEachKind(): void
    {
        $page = $this->client->request('GET', $this->url('/m/job/new'));

        self::assertSame(
            [JobModule::ITEM, JobModule::COMMENT],
            $page->filter('[data-live-action-param="addRow"][data-live-collection-param="lines"]')
                ->each(static fn (Crawler $node): string => (string) $node->attr('data-live-kind-param')),
        );

        self::assertCount(0, $page->filter('[name$="[fields][kind]"]'), 'and no rows yet');
    }

    /** And a row asks only for what its kind has. */
    public function testARowAsksOnlyForItsOwnFields(): void
    {
        $item = $this->afterAdding([JobModule::ITEM]);

        // The item: text and an amount. The comment: text alone.
        self::assertStringContainsString(self::line(0, 'amount'), $item);
        self::assertStringContainsString(self::line(0, 'text'), $item);

        $both = $this->afterAdding([JobModule::ITEM, JobModule::COMMENT]);

        self::assertStringNotContainsString(self::line(1, 'amount'), $both);
        self::assertStringContainsString(self::line(1, 'text'), $both);
    }

    /** A row already typed into survives adding another. */
    public function testAddingARowKeepsWhatIsAlreadyTypedIn(): void
    {
        $html = self::liveService(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this
            ->recordForm(JobModule::KEY)
            ->call('addRow', ['collection' => 'lines', 'kind' => JobModule::ITEM])
            ->set('module_record', [
                'fields' => ['title' => 'Rewire the office', 'status' => JobModule::DRAFT],
                'collections' => ['lines' => [self::row([
                    'kind' => JobModule::ITEM,
                    'text' => 'Cabling',
                ])]],
            ])
            ->call('addRow', ['collection' => 'lines', 'kind' => JobModule::COMMENT])
            ->render()
            ->toString());

        self::assertStringContainsString('value="Cabling"', $html, 'the row that was there kept what was typed');
        self::assertStringContainsString(self::line(1, 'text'), $html, 'and the new row is there');
    }

    /** A row can be taken away again, and takes its values with it. */
    public function testARowCanBeRemoved(): void
    {
        $html = self::liveService(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this
            ->recordForm(JobModule::KEY)
            ->set('module_record', [
                'fields' => ['title' => 'Rewire the office', 'status' => JobModule::DRAFT],
                'collections' => ['lines' => [
                    self::row(['kind' => JobModule::ITEM, 'text' => 'Staying']),
                    self::row(['kind' => JobModule::COMMENT, 'text' => 'Going away']),
                ]],
            ])
            ->call('removeRow', ['collection' => 'lines', 'index' => 1])
            ->render()
            ->toString());

        self::assertStringContainsString('value="Staying"', $html, 'one row left');
        self::assertStringNotContainsString('Going away', $html);
    }

    /** A derived value is shown and never taken. */
    public function testADerivedFieldIsShownWithoutBeingOffered(): void
    {
        $html = $this->afterAdding([JobModule::ITEM]);

        self::assertStringContainsString(self::line(0, 'line_total'), $html, 'it is on the form');
        self::assertMatchesRegularExpression('#line_total[^>]*disabled#', $html, 'and not for typing');
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
        $id = $this->savedId($this->saveRecord(
            JobModule::KEY,
            ['title' => 'Just a note', 'status' => JobModule::DRAFT],
            ['lines' => [self::row(['kind' => JobModule::COMMENT, 'text' => 'Nothing to charge for'])]],
        ));

        $page = $this->client->request('GET', $this->url('/m/job/' . $id))->filter('main')->text();

        self::assertStringContainsString('Nothing to charge for', $page);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The form's HTML after pressing the add button for each kind in turn.
     *
     * @param list<string> $kinds
     */
    private function afterAdding(array $kinds): string
    {
        return self::liveService(TenantSwitcher::class)->runFor($this->tenant, function () use ($kinds): string {
            $form = $this->recordForm(JobModule::KEY);

            foreach ($kinds as $kind) {
                $form = $form->call('addRow', ['collection' => 'lines', 'kind' => $kind]);
            }

            return $form->render()->toString();
        });
    }

    private function aJobWithLines(): int
    {
        return $this->savedId($this->saveRecord(
            JobModule::KEY,
            ['title' => 'Rewire the office', 'status' => JobModule::DRAFT],
            ['lines' => [
                self::row(['kind' => JobModule::ITEM, 'text' => 'Cabling', 'amount' => '240.00']),
                self::row(['kind' => JobModule::COMMENT, 'text' => 'Anything below is optional']),
            ]],
        ));
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

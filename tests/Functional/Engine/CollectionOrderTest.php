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
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Rows in the order the customer put them in (XIV-21).
 *
 * Before this they came back by id, so a row could only ever sit where it was
 * created — which is fine for a contact's addresses and useless for lines a
 * comment is supposed to group.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionOrderTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_row_order';
    private const string HOST = 'roworder.localhost';
    private const string EMAIL = 'order@example.test';
    private const string PASSWORD = 'order-password';
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

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Order', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** Rows are numbered in tens, in the order they were typed. */
    public function testRowsAreNumberedInTheOrderTheyWereEntered(): void
    {
        $id = $this->aJobWithLines(['First', 'Second', 'Third']);

        self::assertSame([10, 20, 30], $this->positionsOf($id));
        self::assertSame(['First', 'Second', 'Third'], $this->textsOf($id));
    }

    /**
     * The point of the feature: a row can be put between two others by typing a
     * number between theirs, and it stays there.
     */
    public function testARowCanBeMovedBetweenTwoOthers(): void
    {
        $id = $this->aJobWithLines(['First', 'Second', 'Third']);

        // Send the third one to the front by numbering it below the first.
        $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        $form[self::row(2, 'position')] = '5';
        $this->client->submit($form);

        self::assertSame(['Third', 'First', 'Second'], $this->textsOf($id));
        // And renumbered in tens again, so the next insertion has room.
        self::assertSame([10, 20, 30], $this->positionsOf($id));
    }

    /** Moving a row does not change what it is: the ids stay put. */
    public function testReorderingDoesNotChangeWhatARowIs(): void
    {
        $id = $this->aJobWithLines(['First', 'Second']);
        $before = $this->idsByText($id);

        $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        $form[self::row(1, 'position')] = '5';
        $this->client->submit($form);

        $after = $this->idsByText($id);
        ksort($before);
        ksort($after);

        self::assertSame(['Second', 'First'], $this->textsOf($id));
        self::assertSame($before, $after, 'the same rows, in a different order');
    }

    /** And the history says nothing happened, because nothing about a row did. */
    public function testReorderingIsNotAChangeToTheRecord(): void
    {
        $id = $this->aJobWithLines(['First', 'Second']);
        $before = $this->timelineLength($id);

        $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        $form[self::row(1, 'position')] = '5';
        $this->client->submit($form);

        self::assertSame(['Second', 'First'], $this->textsOf($id), 'it did move');
        self::assertSame($before, $this->timelineLength($id), 'and the timeline says nothing happened');
    }

    private function timelineLength(int $id): int
    {
        return $this->client->request('GET', $this->url('/m/job/' . $id . '/history'))
            ->filter('.history-entry')
            ->count();
    }

    /** A row somebody just filled in goes to the end rather than to the front. */
    public function testANewRowGoesToTheEnd(): void
    {
        $id = $this->aJobWithLines(['First']);

        $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));
        $form = $crawler->selectButton('Save')->form();
        // The blank item row the form always ends with.
        $form[self::row(1, 'fields][text')] = 'Added later';
        $this->client->submit($form);

        self::assertSame(['First', 'Added later'], $this->textsOf($id));
    }

    // -- helpers ------------------------------------------------------------

    /** @param list<string> $texts */
    private function aJobWithLines(array $texts): int
    {
        $this->client->request('GET', $this->url('/m/job/new'));

        $values = [
            self::field('title') => 'Rewire the office',
            self::field('status') => JobModule::DRAFT,
        ];

        // The form offers one blank row per kind; this fills the item one and
        // then edits the record to add the rest, which is how somebody with no
        // JavaScript actually does it.
        $values[self::row(0, 'fields][text')] = $texts[0];
        $this->client->submitForm('Save', $values);
        $this->client->followRedirect();

        $id = (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));

        foreach (\array_slice($texts, 1) as $offset => $text) {
            $crawler = $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));
            $form = $crawler->selectButton('Save')->form();
            $form[self::row($offset + 1, 'fields][text')] = $text;
            $this->client->submit($form);
        }

        return $id;
    }

    /** @return list<int> */
    private function positionsOf(int $id): array
    {
        return array_map(
            static fn (Record $row): int => (int) $row->position,
            $this->linesOf($id),
        );
    }

    /** @return list<string> */
    private function textsOf(int $id): array
    {
        return array_map(
            static fn (Record $row): string => (string) $row->get('text'),
            $this->linesOf($id),
        );
    }

    /** @return array<string, int> text => id */
    private function idsByText(int $id): array
    {
        $ids = [];

        foreach ($this->linesOf($id) as $row) {
            $ids[(string) $row->get('text')] = (int) $row->id;
        }

        return $ids;
    }

    /** @return list<Record> */
    private function linesOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $module = self::service(MetadataRepository::class)->get(JobModule::KEY);
            $lines = $module->getCollection('lines');
            self::assertNotNull($lines);

            return self::service(RecordRepository::class)->findChildren($lines, $id);
        });
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private static function row(int $index, string $key): string
    {
        return sprintf('%s[collections][lines][%d][%s]', self::FORM, $index, $key);
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

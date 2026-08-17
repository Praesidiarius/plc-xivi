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
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_row_order';
    private const string HOST = 'roworder.localhost';
    private const string EMAIL = 'order@example.test';
    private const string PASSWORD = 'order-password';

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
        $this->renumber($id, 2, '5');

        self::assertSame(['Third', 'First', 'Second'], $this->textsOf($id));
        // And renumbered in tens again, so the next insertion has room.
        self::assertSame([10, 20, 30], $this->positionsOf($id));
    }

    /** Moving a row does not change what it is: the ids stay put. */
    public function testReorderingDoesNotChangeWhatARowIs(): void
    {
        $id = $this->aJobWithLines(['First', 'Second']);
        $before = $this->idsByText($id);

        $this->renumber($id, 1, '5');

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

        $this->renumber($id, 1, '5');

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

        // A row somebody has just added: no id and no position, which is what
        // sends it to the end rather than to the front.
        $values = self::formValuesOn($this->client->request('GET', $this->url('/m/job/' . $id . '/edit')));
        $values['collections']['lines'][] = self::row(['kind' => JobModule::ITEM, 'text' => 'Added later']);

        $this->saveRecord(JobModule::KEY, $values['fields'], $values['collections'], $id);

        self::assertSame(['First', 'Added later'], $this->textsOf($id));
    }

    // -- helpers ------------------------------------------------------------

    /** @param list<string> $texts */
    private function aJobWithLines(array $texts): int
    {
        return $this->savedId($this->saveRecord(
            JobModule::KEY,
            ['title' => 'Rewire the office', 'status' => JobModule::DRAFT],
            ['lines' => array_map(
                static fn (string $text): array => self::row(['kind' => JobModule::ITEM, 'text' => $text]),
                $texts,
            )],
        ));
    }

    /**
     * Save the record again with one row renumbered, everything else unchanged.
     *
     * Read off the page first, so what is sent back is what a person would have
     * been looking at — the rows keep their ids, which is what makes this a move
     * rather than a delete and an insert.
     */
    private function renumber(int $id, int $index, string $position): void
    {
        $values = self::formValuesOn($this->client->request('GET', $this->url('/m/job/' . $id . '/edit')));
        $values['collections']['lines'][$index]['position'] = $position;

        $this->saveRecord(JobModule::KEY, $values['fields'], $values['collections'], $id);
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

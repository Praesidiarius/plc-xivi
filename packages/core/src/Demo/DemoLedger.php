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

namespace Xivi\Core\Demo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * Which records a generator made, so that exactly those can be removed again.
 *
 * The alternative was recognising demo data by how it looks, which is a guess,
 * and the thing being guessed about is somebody's database. A record is demo
 * data because this table says so and for no other reason.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DemoLedger
{
    public const string TABLE = 'demo_record';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<int> $ids
     */
    public function record(string $shapeKey, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $values = [];
        $parameters = ['shape' => $shapeKey, 'at' => $now];

        foreach ($ids as $index => $id) {
            $values[] = sprintf('(:shape, :id%d, :at)', $index);
            $parameters['id' . $index] = $id;
        }

        // One statement per batch rather than one per record: a million rows
        // inserted one at a time is the generator's own bottleneck.
        $this->connection->executeStatement(
            sprintf('INSERT INTO %s (shape_key, record_id, generated_at) VALUES %s', self::TABLE, implode(', ', $values)),
            $parameters,
        );
    }

    /**
     * The ids this ledger holds for a shape.
     *
     * @return list<int>
     */
    public function idsFor(string $shapeKey): array
    {
        return array_map(
            static fn (mixed $id): int => (int) $id,
            $this->connection->fetchFirstColumn(
                sprintf('SELECT record_id FROM %s WHERE shape_key = :shape', self::TABLE),
                ['shape' => $shapeKey],
            ),
        );
    }

    public function countFor(string $shapeKey): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE shape_key = :shape', self::TABLE),
            ['shape' => $shapeKey],
        );
    }

    /**
     * Delete a module's generated records outright — the rows, their
     * collections' rows, their history and the ledger entries.
     *
     * A **hard** delete, unlike everything else in the engine (§5). Soft deletion
     * exists so that a customer removing a record does not lose the audit trail;
     * demo data has no trail worth keeping, and leaving a million invisible rows
     * behind would defeat the point of removing it.
     *
     * @return int how many records were removed
     */
    public function purge(ModuleDefinition $module): int
    {
        return $this->connection->transactional(function () use ($module): int {
            $ids = $this->idsFor($module->getKey());

            if ($ids === []) {
                return 0;
            }

            foreach ($module->getCollections() as $collection) {
                // By parent, not by the ledger: a collection row added by hand to
                // a generated record still belongs to a record that is going.
                $this->connection->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE %s IN (:ids)',
                        $this->quote($collection->getTableName()),
                        CollectionDefinition::PARENT_COLUMN,
                    ),
                    ['ids' => $ids],
                    ['ids' => ArrayParameterType::INTEGER],
                );

                $this->forget($collection->getKey());
            }

            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE record_id IN (:ids)', $this->quote($module->getHistoryTableName())),
                ['ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER],
            );

            $removed = $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE id IN (:ids)', $this->quote($module->getTableName())),
                ['ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER],
            );

            $this->forget($module->getKey());

            return (int) $removed;
        });
    }

    private function forget(string $shapeKey): void
    {
        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE shape_key = :shape', self::TABLE),
            ['shape' => $shapeKey],
            ['shape' => ParameterType::STRING],
        );
    }

    private function quote(string $table): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($table);
    }
}

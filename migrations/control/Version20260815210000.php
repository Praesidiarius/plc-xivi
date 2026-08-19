<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The state of each module, platform-wide (XIV-7).
 *
 * One table in the control plane, not a column per tenant: how far along a module
 * is is the same answer for everybody (docs/architecture/extensibility.md §6.2).
 *
 * Nothing is seeded. A module with no row is in development, which is the default
 * a new module is supposed to get without anybody writing it down — so an empty
 * table after this migration is the correct state of the world, not a pending step.
 */
final class Version20260815210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the platform-wide state of each module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE module (
                id SERIAL NOT NULL,
                module_key VARCHAR(64) NOT NULL,
                state VARCHAR(32) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_module_key ON module (module_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE module');
    }
}

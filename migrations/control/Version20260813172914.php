<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the tenant database password out of the DSN and into its own encrypted
 * column, so a dump of the control plane carries no usable credential.
 *
 * Expand only (docs/architecture/deployment.md §4): the column is nullable and nothing is rewritten.
 * Rows written before this migration keep a DSN that names no password and have
 * no ciphertext, so they fail loudly with TenantCredentialMissingException and
 * have to be re-provisioned — which is also what gives them a role of their own.
 */
final class Version20260813172914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store tenant database passwords encrypted, separate from the DSN';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD database_password TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP database_password');
    }
}

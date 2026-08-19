<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The tenant's own profile: what they call themselves, and their currency (XIV-12).
 *
 * One row, and the primary key is what says so — the id is a constant rather than
 * a sequence, so a second profile is a duplicate key rather than something to
 * notice later.
 *
 * The row is inserted here rather than on first save. It lands for every tenant
 * at once (docs/architecture/deployment.md §4) and carries no opinions: an empty company name and no
 * currency are exactly what "nobody has filled this in" looks like, so nothing is
 * being decided on a customer's behalf while they are not looking.
 */
final class Version20260815230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add the tenant profile: company name and the instance's currency";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_profile (
                id INT NOT NULL,
                company_name VARCHAR(255) DEFAULT '' NOT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql("INSERT INTO tenant_profile (id, company_name, currency, updated_at) VALUES (1, '', NULL, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_profile');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What each customer is using, as of the last time anybody counted (XIV-59).
 *
 * **In the control plane, and one table rather than five columns on `tenant`.**
 * The figures are read out of the customers' own databases (§4 is a database per
 * tenant, so there is no join that could produce them) and written back here by
 * `tenant:usage:collect`, so that the page an operator opens still reads one
 * database — the control plane's — exactly as it did in XIV-58.
 *
 * A row is a *collection*, not a customer. Every number in it means nothing
 * without `collected_at` beside it, the failure state is a fact about the attempt
 * rather than about the tenant, and a customer nobody has collected yet has no
 * row at all — which is the state five nullable columns on `tenant` could not
 * have said. See docs/architecture.md §8.11 and `Xivi\ControlPlane\Entity\TenantUsage`.
 *
 * Nothing is backfilled and nothing needs to be: an empty table is exactly
 * "nobody has been collected yet", the page draws that as *not collected yet*,
 * and the first run of the command fills it in.
 */
final class Version20260817230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What each tenant uses, collected periodically into the control plane';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_usage (
                id SERIAL NOT NULL,
                tenant_id INT NOT NULL,
                collected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                user_count INT DEFAULT NULL,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                record_count INT DEFAULT NULL,
                records_by_module JSON DEFAULT NULL,
                failure TEXT DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // One collection per tenant: this table holds *the* figures and how old
        // they are, not a time series. A history would need a retention policy
        // nobody has written and would answer a question — the trend — that
        // nobody has asked yet.
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_usage_tenant ON tenant_usage (tenant_id)');

        // `ON DELETE CASCADE` because a deprovisioned customer's usage row is
        // meaningless and would otherwise hold a foreign key against a row
        // `TenantProvisioner::deprovision()` is trying to remove — turning a
        // clean removal into a constraint violation somebody has to clear by
        // hand, which is precisely the class of failure XIV-72 exists to end.
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_usage
                ADD CONSTRAINT fk_tenant_usage_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_usage');
    }
}

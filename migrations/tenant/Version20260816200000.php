<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which country's conventions to write in (XIV-50).
 *
 * Separate from the language, because they are separate questions. Swiss German
 * and German German share a translation catalogue and disagree about how to
 * write a number — `1’234’500.00` against `1.234.500,00`, differing in the
 * decimal separator as well as the grouping one — so choosing "Deutsch" was
 * answering a question nobody asked.
 *
 * Two columns for the two places an answer can come from: the installation
 * (§8.6), whose people are mostly in one country, and the person, who may not
 * be. Both nullable, and null means "follow the one below" rather than a
 * country — the same promise `locale` already makes, and deliberately different
 * from naming that country, which would stop following it if the company moved.
 */
final class Version20260816200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region to tenant_profile and app_user: which country writes numbers this way';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile ADD region VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD region VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile DROP region');
        $this->addSql('ALTER TABLE app_user DROP region');
    }
}

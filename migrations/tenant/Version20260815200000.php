<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which language each person reads the application in (XIV-8).
 *
 * Nullable, and null is not the same as 'en': somebody who never chose follows
 * whatever the application defaults to, and somebody who chose English keeps
 * English if that default ever moves. Two facts, so two values.
 *
 * Per person rather than per customer. One office is not one language — a Swiss
 * company has German and French speakers in it — and a tenant-wide setting would
 * leave the colleague who does not read it with nowhere to go.
 */
final class Version20260815200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.locale, the language each person reads the application in';
    }

    public function up(Schema $schema): void
    {
        // Five characters holds a language tag with a region ("de-CH"), which
        // this does not use yet and would have to widen the column to add later.
        $this->addSql('ALTER TABLE app_user ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP locale');
    }
}

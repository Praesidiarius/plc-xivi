<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark a password the system generated as one that has to be replaced.
 *
 * A generated password (docs/architecture.md §8.5) is read off a screen by the
 * administrator who created the account and passed on by whatever means was to
 * hand. Until the owner changes it, it is a credential more than one person
 * knows — so the account is held at the password page until they do.
 *
 * Defaults to false, and nothing is backfilled: everybody who already has an
 * account has been using their password for a while, and an upgrade that
 * demanded a password change from every user of every customer would be a
 * strange thing to do to them.
 */
final class Version20260814213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.must_change_password';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD must_change_password BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN must_change_password');
    }
}

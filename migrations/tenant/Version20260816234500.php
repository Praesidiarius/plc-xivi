<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What makes an invitation link revocable (XIV-1).
 *
 * One column, and no `user_invitation` table, because the link itself is not
 * stored anywhere: it is a Symfony login link, signed with `kernel.secret` over
 * the user's id, their password hash, this value and an expiry. What goes in the
 * mail is the signature; what is written down is only one of the things signed.
 * So a dump of this database carries nothing that can be replayed — the same
 * property a hashed token table would have bought, without the table.
 *
 * The column exists because a *stateless* link cannot be revoked, and an
 * invitation has to be revocable twice over: once when it is accepted, and once
 * when an administrator sends a second one. Rotating this value does both, and
 * `app_user` is where it belongs — it is a fact about one person's account.
 *
 * Nullable with no backfill. Nobody who exists today has been invited, and the
 * signature hasher reads a null property as an empty string, so an untouched row
 * is already in the right state.
 */
final class Version20260816234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the rotating value that makes an email invitation link expire on use (XIV-1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD invitation_seed VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP invitation_seed');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A zone to read moments in, on the person and on the installation (XIV-83).
 *
 * The third setting of the shape the language and the region already have: one
 * column on `app_user` and one on `tenant_profile`, both nullable, neither
 * backfilled. Null is not a gap here — it is the answer nearly every row will
 * keep, because a customer who has chosen a country has usually chosen a zone
 * without knowing it, and `DisplayTimezone` derives it wherever that country has
 * exactly one.
 *
 * **Nothing about stored moments changes, and that is the point worth writing
 * down.** Postgres `timestamptz` normalises to UTC on write and keeps no per-row
 * zone, and every moment this application stores already goes through
 * `Types::DATETIMETZ_IMMUTABLE` — `<module>_history.occurred_at` is the oldest
 * example. So "store UTC, display local" needed no data migration at all: the
 * storage half has been right since the first table, and only the display half
 * was missing. The rule the next migration has to keep is the one that made this
 * cheap — **no new column is a zoneless `timestamp`**.
 *
 * VARCHAR(64) because an IANA identifier is a string like `America/Argentina/Rio_Gallegos`
 * and the longest one in the current database is comfortably under half of that.
 * Not an offset in minutes, which would be a number that is right for half the
 * year: the zone database knows when a country changes its clocks and an integer
 * column cannot.
 */
final class Version20260817200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the timezone moments are displayed in, per user and per installation (XIV-83)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD timezone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_profile ADD timezone VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP timezone');
        $this->addSql('ALTER TABLE tenant_profile DROP timezone');
    }
}

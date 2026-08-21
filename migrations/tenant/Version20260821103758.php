<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A field may promote its values to the top of the record page ([XIV-173]).
 *
 * One boolean on `field_definition`, defaulting to false, which is both the
 * whole of the change and the whole of the backfill: every field in every
 * tenant wakes up unpromoted, and a record page that nobody has changed is
 * character for character the page that was there yesterday. That is the same
 * shape XIV-119's `section_key` had, and for the same reason: a display
 * decision arrives switched off, because switching one on for somebody is
 * rearranging a page they read every day.
 *
 * **Why a column rather than a key in the `options` JSON beside it.** That JSON
 * holds what a *type* asks about, how long a text may be or which list a choice
 * points at, and is replaced wholesale when a field's type changes (§7.2), on
 * the argument that an answer to a question the new type does not ask is an
 * answer nobody can read. Promotion is not one of those. It is a display
 * decision like `is_listed` and `section_key`, it survives a type change as long
 * as the new type can still be promoted, and it is asked about by the arrange
 * page across every field of a shape at once, which is a query against a column
 * and a scan through JSON.
 *
 * Additive, like every tenant migration (§4.2): the default is what lets the
 * build running *before* this lands during the deploy walk go on inserting
 * `field_definition` rows without naming a column it has never heard of.
 *
 * `down()` drops the column and with it which fields a customer had promoted.
 * That is a handful of checkboxes re-tickable in a minute on the arrange page,
 * not data anybody has to reproduce from elsewhere, so going backwards is cheap
 * here in a way it is not for a column holding what somebody typed.
 */
final class Version20260821103758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A field may promote its values to the top of the record page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE field_definition
                ADD is_promoted BOOLEAN DEFAULT FALSE NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE field_definition
                DROP is_promoted
            SQL);
    }
}

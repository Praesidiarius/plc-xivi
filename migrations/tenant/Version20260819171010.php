<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fields grouped under headings on the form (XIV-119).
 *
 * Two nullable columns and nothing else, which is the whole design showing
 * through: a section is presentation, so it is a word on the module's own row
 * and a word on the field's, not a table anything can join to. §5.4 has the
 * argument at length, including why this is emphatically not §5.1's collections
 * arriving a second time.
 *
 * ## What this costs an installation that already has rows
 *
 * **Nothing, and that is checked rather than hoped.** Both columns are nullable
 * with no default and no backfill, so every `shape_definition` and
 * `field_definition` row in every customer database is left byte for byte as it
 * was: a module with `sections` null has no headings, and a field with
 * `section_key` null is drawn where it has always been drawn. There is no
 * `UPDATE` in this file and there must never be one — an existing definition
 * being untouched is one of this ticket's acceptance criteria, and a migration
 * that rewrote them would be the way to fail it.
 *
 * Additive in §4.2's sense as well, which is the stricter reading: `bin/deploy`
 * walks the customer databases one at a time with the instance still serving, so
 * for the length of that walk one build of the code meets both schemas. A build
 * that has not got these columns never names them; a build that has reads null
 * from a database that has just gained them and draws the form it always drew.
 *
 * ## `sections` on `shape_definition`, where a collection's row will never use it
 *
 * Single-table inheritance puts a `ModuleDefinition` column on the shared table,
 * so a collection's row carries this and is never given a value for it — the
 * same shape `follow_ups_enabled` and `position` already have, pointing in
 * opposite directions. The alternative is a table per subclass, which is a much
 * larger decision than a heading on a form.
 *
 * `section_key` goes the other way and *is* on every field, collection fields
 * included, where it stays null: the column is where a field says which heading
 * it is under, and a collection has no headings. Splitting it would mean a
 * second field table.
 *
 * ## Why no index and no foreign key
 *
 * There is nothing to point at — a section has no row — and nothing queries by
 * this. Both columns are read only as part of a definition that is already fully
 * loaded (`MetadataRepository` fetch-joins the lot, deliberately, so a definition
 * is safe to hold across a tenant switch). An index would be a promise that
 * something filters on it, and the day something does, a section has stopped
 * being presentation.
 *
 * ## What `down()` cannot put back
 *
 * It drops both columns, and with them every heading a customer wrote and every
 * field's membership of one. No record is touched and no value is lost — that is
 * the point of the feature — so what goes is one customer's arrangement of their
 * own form, which they would have to make again. Reversible in the sense that
 * matters and not in the sense that costs nothing.
 */
final class Version20260819171010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fields grouped under headings on the form (XIV-119)';
    }

    public function up(Schema $schema): void
    {
        // The headings themselves: label and position per section, keyed by the
        // key fields carry. JSON rather than rows for the reason at the top —
        // and beside `declined_additions`, which made the same argument for the
        // same table.
        $this->addSql('ALTER TABLE shape_definition ADD sections JSON DEFAULT NULL');

        // And which one a field is under. Null is "none", which is every field
        // that exists today.
        $this->addSql('ALTER TABLE field_definition ADD section_key VARCHAR(63) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition DROP sections');
        $this->addSql('ALTER TABLE field_definition DROP section_key');
    }
}

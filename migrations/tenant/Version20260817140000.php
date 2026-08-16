<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-ups: one shared pair of tables, an opt-out per module, and a wider verb
 * column (XIV-80).
 *
 * **A migration rather than `ModuleInstaller`, which is the decision this whole
 * file rests on.** History is created per module by the installer because §5.2
 * needs `record_id` to mean exactly one table and therefore to carry a real
 * foreign key. That argument does not survive the move: history is written
 * automatically by everybody on every save and grows without bound, while a
 * follow-up is typed by a person who decided to type it. So the size half buys
 * nothing here, and the price of per-module tables — an installer that creates
 * them, the 63-character identifier guard in `assertTableNameFits()` to widen,
 * every already-installed module to retro-fit — would be paid for nothing.
 *
 * The consequence is stated rather than hidden: **`record_id` carries no foreign
 * key and cannot**, because the table it points into depends on what `module`
 * says. That is precisely the integrity §5.2 refused to give up, given up here on
 * purpose. Two things follow, and both live in code because the database cannot
 * be asked to do them: every read joins through to the record and honours
 * `deleted_at IS NULL`, and a hard purge — when one is ever built — has to sweep
 * `follow_up` itself, since nothing will cascade into it.
 *
 * **`due_at` and `done_at` are `timestamptz`**, like `<module>_history.occurred_at`
 * and unlike the `created_at` beside them. A deadline is an instant two people in
 * two countries have to agree about; a row's own bookkeeping timestamps are the
 * server's business, and the neighbouring tables all write them without a zone.
 *
 * **Two indexes and no more.** One for the record page's question and one for the
 * widget's. Over-indexing is the other half of what made the old history table
 * hurt (§5.2), and this is the table that will be written to by hand.
 *
 * **`shape_definition.follow_ups_enabled` is nullable with a backfill**, because
 * single-table inheritance puts a subclass's column on the shared table and a
 * collection's row has no business carrying it — exactly like `position`, which
 * does the same thing from the other side.
 *
 * **`permission_grant.action` goes from 16 to 31**: `follow_up_complete` is
 * eighteen characters. The permission *catalogue* still needs no migration — it
 * is the enums crossed with the customer's modules, worked out at runtime (§8.4)
 * — but the column has to hold the word. 31 matches what a history row's `action`
 * gets, so the next verb is a naming decision rather than a schema change.
 *
 * **No grants are written and no demo data is generated**, for the same reason
 * the permission tables wrote none: a migration lands for every tenant at once
 * (§4), and deciding what a customer's people may do is not something to do to
 * them in passing. `tenant:permissions:grant-all` remains the deliberate act.
 */
final class Version20260817140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add follow-ups and their notes, the per-module opt-out, and a wider grant action column (XIV-80)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE follow_up (
                id SERIAL NOT NULL,
                module VARCHAR(63) NOT NULL,
                record_id INT NOT NULL,
                priority VARCHAR(15) NOT NULL,
                due_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                assignee_id INT DEFAULT NULL,
                assignee_label TEXT DEFAULT NULL,
                done_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_by_id INT NOT NULL,
                created_by_label TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The record page's question — what is outstanding on this contact — and
        // the only one it asks. Leading with the module because a record id on
        // its own means nothing in a table shared by all of them.
        $this->addSql('CREATE INDEX idx_follow_up_record ON follow_up (module, record_id)');

        // The dashboard widget's (XIV-81): what is still on my list, soonest
        // first. `done_at` sits in the middle because the filter is "still open"
        // and the sort is by date.
        $this->addSql('CREATE INDEX idx_follow_up_assignee ON follow_up (assignee_id, done_at, due_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE follow_up_note (
                id SERIAL NOT NULL,
                follow_up_id INT NOT NULL,
                body TEXT NOT NULL,
                author_id INT NOT NULL,
                author_label TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // A real foreign key, and no tension with the missing one above: this
        // column means `follow_up` and nothing else, forever, which is the whole
        // of §5.2's rule arriving at the opposite answer. A note with no
        // follow-up is a note about nothing.
        $this->addSql(<<<'SQL'
            ALTER TABLE follow_up_note
                ADD CONSTRAINT fk_follow_up_note_follow_up FOREIGN KEY (follow_up_id)
                REFERENCES follow_up (id) ON DELETE CASCADE
            SQL);

        // Every read of the notes is "this follow-up's thread", so one index
        // rather than two. Written out rather than left to the foreign key,
        // which does not create one in Postgres.
        $this->addSql('CREATE INDEX idx_follow_up_note_follow_up ON follow_up_note (follow_up_id)');

        // No `user_id` foreign keys anywhere in either table. The author and the
        // assignee sit beside a label captured at write time, so a follow-up
        // outlives the person it names and a rename cannot rewrite what it says —
        // the same reasoning as `<module>_history.user_label` (§5.2). Deleting a
        // user clears the *assignment* through a listener, because there is no
        // constraint to hang `ON DELETE SET NULL` on.

        // On for every module a customer already has, which is the default the
        // entity carries too. There is no production installation to be careful
        // with here (§7.2 is not in scope), and a module whose records nobody can
        // leave a note on is the surprising one.
        $this->addSql('ALTER TABLE shape_definition ADD follow_ups_enabled BOOLEAN DEFAULT NULL');
        $this->addSql("UPDATE shape_definition SET follow_ups_enabled = TRUE WHERE shape_kind = 'module'");

        $this->addSql('ALTER TABLE permission_grant ALTER COLUMN action TYPE VARCHAR(31)');
    }

    public function down(Schema $schema): void
    {
        // Back to 16 characters, which is only safe because nothing in this
        // direction should still be holding a follow-up verb: rolling this back
        // means the tables holding follow-ups are going too. Grants naming one
        // would be rows about a feature that no longer exists, so they go first
        // rather than making the ALTER fail with a truncation error.
        $this->addSql("DELETE FROM permission_grant WHERE action LIKE 'follow_up%'");
        $this->addSql('ALTER TABLE permission_grant ALTER COLUMN action TYPE VARCHAR(16)');

        $this->addSql('ALTER TABLE shape_definition DROP follow_ups_enabled');

        // Notes first: the cascade would take them, but a DROP in the order
        // things depend on each other reads as what it is.
        $this->addSql('DROP TABLE follow_up_note');
        $this->addSql('DROP TABLE follow_up');
    }
}

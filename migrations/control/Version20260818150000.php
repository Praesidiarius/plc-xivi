<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An operator can be revoked without being deleted (XIV-92, §8.9).
 *
 * XIV-57 gave `Operator` no `active` flag on purpose, and the argument was
 * sound at the time: nothing attributed anything to an operator, so revoking
 * one could be deleting the row, and a boolean with no command to write it
 * would have been a promise nothing kept. What changed is not that attribution
 * arrived — it has not yet — but that revocation was asked for, at which point
 * the question stops being *should this column exist* and becomes *what does
 * revoking do*. §8.9 argues deletion down; the short version is that it is the
 * one lifecycle step nobody can undo, taken in the one situation where people
 * are moving fast.
 *
 * **The column lands before anything references an operator, which is the whole
 * reason this migration is on this branch rather than a later one.** The moment
 * a `signup_request` or a provisioning record carries "which operator did
 * this" — [XIV-98] and [XIV-59]'s surfaces are the near ones — a deletable
 * operator forces a choice between `ON DELETE SET NULL`, an audit trail that
 * erases itself exactly when somebody is revoked in a hurry, and a foreign key
 * that refuses the revocation outright. Both of those are discovered with the
 * schema already in production.
 *
 * ## Everybody already here stays able to sign in
 *
 * `DEFAULT TRUE` on the added column, so every existing row is active and no
 * installation is locked out by running this. That is not merely convenient: the
 * control plane has no sign-up, no invitation and no password reset (§8.9), so a
 * migration that defaulted the other way would be unrecoverable from the web and
 * would present as *the password stopped working* rather than as a migration.
 *
 * **And then the default is dropped again**, which is the part worth explaining
 * because leaving it would have been the friendlier-looking choice. A hand-typed
 * `INSERT` that forgets the column is safer with a default of true than without
 * one, and that is a real if small argument for keeping it.
 *
 * It loses to a larger one. `doctrine:schema:validate --em=control` **does**
 * report a database-level default that the mapping does not declare — measured,
 * not assumed: with the default left in place the command answers *the database
 * schema is not in sync with the current mapping file* and `schema:update
 * --dump-sql` prints exactly `ALTER TABLE operator ALTER active DROP DEFAULT`.
 * [XIV-97] had just spent a whole migration turning eleven such differences off,
 * on the argument that a check which is always red is a check nobody reads. One
 * new permanent difference, added a day later for a convenience nothing in this
 * codebase depends on, would be the beginning of the same drift — Doctrine
 * writes `active` explicitly on every insert, so the only caller the default
 * serves is a human at a `psql` prompt, and this ticket exists to stop that from
 * being how an operator's lifecycle is managed.
 */
final class Version20260818150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An operator can be revoked (operator.active), instead of being deleted in psql';
    }

    public function up(Schema $schema): void
    {
        // Two statements rather than one, and the order is the whole trick: the
        // default is what fills the column for every row that is already there,
        // and dropping it afterwards leaves the schema matching the mapping.
        // Adding the column NOT NULL without a default would simply be refused
        // by Postgres on a table that has rows.
        $this->addSql('ALTER TABLE operator ADD active BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE operator ALTER active DROP DEFAULT');
    }

    /**
     * The way back, which drops the distinction rather than acting on it.
     *
     * Worth stating because it is the one thing this `down()` cannot preserve:
     * an installation that reverses this migration turns every revoked operator
     * back into one who can sign in. There is nowhere else for that fact to
     * live — the column *is* the fact — so the alternative would be a `down()`
     * that refuses when any row is revoked, and a migration that cannot be
     * reversed while the situation it exists for is happening is worse than one
     * that says what reversing costs. Restore access deliberately with
     * `control:operator:restore` first if that is what is wanted.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operator DROP active');
    }
}

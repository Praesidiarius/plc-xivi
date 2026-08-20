<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * record who was told their signup is stalled
 *
 * XIV-108, §8.14. A signup that fails at `preflight` fails at it for ever, and
 * until now everything that noticed was addressed to the operator: a non-zero
 * exit, a half-made tenant on the tenant list, and the three provisioning
 * columns on this row. The person who confirmed their address and was told their
 * installation was being prepared heard nothing at all. These two columns are
 * what an operator's apology leaves behind.
 *
 * **Two columns and no status.** The reflex is a third `SignupStatus` case, or a
 * boolean called `apologised`, and both are the mistake §8.12 refused when it
 * declined a `provisioned` state: a status here is a second copy of a fact
 * something else already owns, free to disagree with it. What is actually being
 * recorded is an act, so it is recorded the way §8.16 and §8.17 record one, as
 * the moment it happened and the name of whoever did it. Nothing derives a state
 * from these columns; the only question asked of them is whether
 * `apology_sent_at` is NULL, and that question is *has this person already been
 * written to*, which is exactly what the column says.
 *
 * **`apology_sent_by` is a copy of a name, not a foreign key to `operator`.**
 * The same decision `notice.author_label` and `support_request.replied_by` made,
 * for the same reason one step along: an operator whose access is withdrawn has
 * their row removed, and withdrawing somebody's access must not rewrite or erase
 * the record of what they did while they had it. A `SET NULL` foreign key would
 * turn "Marie wrote to them on Tuesday" into "somebody wrote to them on
 * Tuesday", which is the one thing this column exists to prevent a second
 * operator from having to guess at.
 *
 * **Nullable, with no back-fill, and there is nothing to back-fill.** Before
 * this ticket no such message could be sent, so every existing row is correctly
 * NULL: nobody has been written to. An installation running this migration on a
 * table with a hundred signups in it acquires two NULL columns and no other
 * change, and no row's behaviour anywhere else moves.
 *
 * **What `down()` cannot put back.** Dropping these columns loses the record
 * that a person was written to, and the consequence is specific rather than
 * abstract: the button on the tenant list is offered again for a signup that has
 * already had its message, so the waiting customer is apologised to twice. The
 * mail itself is gone by then, and nothing else in either database remembers it.
 * A deployment reversing this ticket should expect that and is better off
 * leaving the columns in place.
 */
final class Version20260820155833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'record who was told their signup is stalled';
    }

    public function up(Schema $schema): void
    {
        // One statement rather than two, because these columns are only ever
        // written together and a table that acquired one of them and not the
        // other would be a table on which the record is half true.
        //
        // `TIMESTAMP(0) WITHOUT TIME ZONE` is what every other moment in this
        // database is, and the process runs in UTC (§8.4.4), so there is no zone
        // to lose. 255 for the name, matching `operator.name`, because the value
        // is a copy of that column and a narrower one here would silently
        // truncate somebody.
        $this->addSql(<<<'SQL'
            ALTER TABLE signup_request
                ADD apology_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD apology_sent_by VARCHAR(255) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup_request DROP apology_sent_at, DROP apology_sent_by');
    }
}

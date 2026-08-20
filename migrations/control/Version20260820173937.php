<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * a notice has a reach and a priority
 *
 * XIV-166, §8.16. Until now an operator's notice landed on the customer's
 * dashboard and nowhere else, at one weight, so a planned maintenance window and
 * a failed payment were the same object drawn the same way. These two columns are
 * the two questions that were missing, and they are not equally interesting.
 *
 * **`reach` is the one that matters.** A notice on the dashboard is read when the
 * customer chooses to open it; a notice on every page is one they cannot get away
 * from. Those are two channels rather than one channel with a flag, and the
 * column is what a query filters on so that the quiet one costs nothing on any
 * page but the dashboard. **`priority` only draws anything on the loud channel**,
 * which is why §8.16's old *"no severity"* bullet is overturned for it and kept
 * for the widget.
 *
 * ## `NOT NULL` with a default, then the default dropped
 *
 * Both columns are `NOT NULL`, because neither has a meaning for "unset": every
 * notice appears somewhere and is drawn in some weight, and a NULL here would be
 * a third state every reader has to translate into one of the two real ones.
 *
 * So the column arrives carrying its default, which back-fills every existing row
 * in the same statement, and **the default is then dropped**. The back-fill is
 * deliberate and is the whole of the compatibility story: `'dashboard'` is not a
 * migration's guess about those rows, it is precisely the behaviour they already
 * had, and `'info'` is the one weight everything was drawn in. Nobody has to open
 * a published notice and re-save it, and nothing a customer is looking at moves.
 *
 * The `DROP DEFAULT` is not tidiness. `SchemaMatchesTheMappingTest` compares this
 * database against the Doctrine mapping, and the mapping declares no column
 * default: a `DEFAULT` left behind in the database is a permanent difference on a
 * check whose whole value is that it is green, which is the failure XIV-97 spent
 * a ticket undoing. The default belongs in the entity constructor, where
 * {@see \App\Registry\Entity\Notice} sets it, because that is the one place a new
 * notice is made.
 *
 * ## No index, and that is measured rather than assumed
 *
 * The shell's read on every page filters `reach = 'every_page'` alongside the
 * `published_at`/`expires_at` window and the audience, and it is tempting to
 * index the new column because "every page" sounds expensive. It is not indexed,
 * because `notice` is a table an operator types into by hand: a busy
 * installation holds tens of rows, not thousands, and PostgreSQL will sequential
 * scan a page-sized table whatever indexes it is offered. An index there would
 * cost a write on every publish, occupy space in every backup, and never be
 * chosen by the planner. §8.16 records the measured plan rather than this
 * paragraph's opinion of it.
 *
 * ## `down()`
 *
 * Dropping the columns is lossless in the sense that matters: reach and priority
 * are the operator's own words about a notice and nothing derives from them, so
 * an installation reversing this ticket loses the distinction and every notice
 * goes back to being a dashboard card. What it does *not* do is warn the operator
 * that a banner they published to every page has quietly become a card, which is
 * the reason to withdraw the loud ones before going backwards rather than after.
 */
final class Version20260820173937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'a notice has a reach and a priority';
    }

    public function up(Schema $schema): void
    {
        // One statement rather than two. The two columns are written together by
        // the only thing that writes them, and a table that acquired one of them
        // and not the other would be a table on which half the answer exists.
        //
        // VARCHAR(32) for both, matching `audience` beside them, because all
        // three are backed enums whose stored value is a short lower-case word
        // and the column widths in this table should not have to be read one at
        // a time.
        $this->addSql(<<<'SQL'
            ALTER TABLE notice
                ADD reach VARCHAR(32) DEFAULT 'dashboard' NOT NULL,
                ADD priority VARCHAR(32) DEFAULT 'info' NOT NULL
            SQL);

        // And now the defaults go, having done their one job. See the class
        // docblock: the mapping declares none, and a difference the schema check
        // reports for ever is a check that stops being read.
        $this->addSql(<<<'SQL'
            ALTER TABLE notice
                ALTER reach DROP DEFAULT,
                ALTER priority DROP DEFAULT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notice DROP reach, DROP priority');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A dashboard a person and an installation can arrange (XIV-66).
 *
 * The fourth setting of the shape the language, the region and the zone already
 * have (§8.4.2, §8.4.4): one column on `app_user` and one on `tenant_profile`,
 * both nullable, neither backfilled. Null is not a gap — it is the answer nearly
 * every row will keep, because a person who has never opened the picker follows
 * the installation's layout and an installation that has never set one shows every
 * widget in the order the code declares. That is what every tenant had the day
 * before this landed, which is the property the bottom of one of these chains
 * always has.
 *
 * **`JSON` rather than a table of `(user_id, widget_key, position)`**, and this is
 * the decision worth writing down. The relational shape is what a schema-first
 * instinct reaches for and it buys nothing here:
 *
 *   - There is nothing to join *to*. A widget key names a class in the build, not
 *     a row anywhere — it can be a module this customer has uninstalled, a widget
 *     a later deploy renamed, or a class somebody deleted — so the foreign key
 *     that would justify a table cannot exist. §7.6 already made the same call
 *     about a `reference` pointing at a deleted record: the link goes stale and
 *     is read as text rather than being prevented.
 *   - Nothing queries it. The layout is read whole, for one person, on one page,
 *     and never filtered, aggregated or joined across. A table would be three
 *     columns, an index and a delete-then-insert on every save, so that a list
 *     could be read back as a list.
 *   - A widget going away migrates nothing. With a table it would either leave
 *     orphan rows or need a cleanup step somebody has to remember at every
 *     uninstall; here it is a string that stops resolving, which the dashboard
 *     already has to survive.
 *
 * `roles` on this same table is the precedent, and `enabled_modules` on the
 * registry is the same argument one database over.
 *
 * **Null and `[]` are different answers and the column has to be able to say
 * both.** Null is "has never chosen" and follows the layer below; an empty array
 * is a dashboard somebody deliberately cleared. That is why the column is nullable
 * rather than defaulting to `'[]'` — a `NOT NULL DEFAULT '[]'` would have made
 * every existing row look like a customer who had chosen to see nothing, which is
 * the one wrong answer available here and would have shipped as a blank landing
 * page for every user on the installation.
 *
 * **Additive, so a deploy can walk the customer databases with the instance still
 * serving** (§4.2): one build of the code meets both schemas for the length of
 * that walk, and a build that has not read these columns yet is unaffected by
 * their being there. `down()` drops them, which loses every arrangement anybody
 * made — recoverable only in the sense that the dashboard goes back to showing
 * everything, which is a working page and not the one somebody set up.
 */
final class Version20260818150003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the dashboard layout, per user and per installation (XIV-66)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD dashboard_layout JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_profile ADD dashboard_layout JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP dashboard_layout');
        $this->addSql('ALTER TABLE tenant_profile DROP dashboard_layout');
    }
}

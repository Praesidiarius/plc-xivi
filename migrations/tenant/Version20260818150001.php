<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to remember that a customer said no to an addition (XIV-70,
 * docs/architecture/open-questions.md §7.2.1).
 *
 * One nullable column, and the reasoning behind it is entirely in
 * {@see \Xivi\Core\Entity\ShapeDefinition::$declined} rather than repeated here.
 * The short version: an upgrade offers what a module's blueprint has grown and
 * this customer has not got, and the engine cannot tell a field somebody deleted
 * on purpose from one they never had — §5.4's removal leaves nothing behind to
 * tell them apart with. So the answer is recorded when it is given instead of
 * being inferred later, and this is where it goes.
 *
 * **Nullable, with no default and no backfill**, which is three deliberate
 * decisions and not an omission:
 *
 *   - *Nullable* because §4.2 lets a tenant migration only add. A `NOT NULL`
 *     column would need a default for the rows already there, and the default
 *     would then have to be declared in the mapping as well or every
 *     `tenant:schema:validate` from here on reports a difference nobody meant
 *     (XIV-97 is what that costs when it accumulates).
 *   - *No default* because null already means the only thing an untouched row
 *     can mean — nobody has declined anything — and an empty JSON object saying
 *     the same thing is a second way to say it.
 *   - *No backfill*, and this is the one worth being explicit about. Every
 *     existing installation starts with **nothing declined**, so the first time
 *     an administrator opens the upgrade screen they are shown everything their
 *     module has gained, including anything somebody deleted back when there was
 *     nowhere to write the decision down. That is the honest behaviour: a
 *     migration cannot know what those deletions meant, and asking once is a
 *     smaller imposition than either nagging for ever or silently deciding on
 *     somebody's behalf.
 *
 * It lands on `shape_definition` rather than on modules alone because a
 * collection can be offered a field too, and single-table inheritance puts both
 * kinds of shape in this table — the same reason `follow_ups_enabled` and
 * `position` live here and mean nothing on one of the two kinds.
 */
final class Version20260818150001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember which blueprint additions a customer has declined';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition ADD declined_additions JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition DROP declined_additions');
    }
}

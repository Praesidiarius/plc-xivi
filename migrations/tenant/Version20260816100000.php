<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How wide a field is drawn (XIV-43).
 *
 * **Nullable, and left null.** Null means "whatever this kind of field wants",
 * which is the answer for almost every field and the one that keeps following
 * the field type as its default improves. Backfilling each row with its type's
 * number instead would freeze today's guess into every customer's data and make
 * every field look like a decision somebody made.
 *
 * So nothing is written here. Existing forms change appearance the day this
 * lands — that is the feature — but no definition row is touched, and a customer
 * who then sets a width is the first person to put a number in this column.
 *
 * `smallint` because the range is 1 to 12 and the entity clamps to it; the
 * column is storage, not the rule.
 */
final class Version20260816100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field_definition.width: how many twelfths of a row a field is drawn in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition ADD width SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP width');
    }
}

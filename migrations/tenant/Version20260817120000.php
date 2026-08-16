<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The bottom of the three layers a payment term can come from (XIV-67).
 *
 * One column on the profile, beside the currency and the region it is the third
 * of: what this installation gives a customer to pay, in whole days, when nothing
 * on the customer says otherwise.
 *
 * **Nullable with no backfill, and no default of thirty.** A term nobody chose is
 * not a term, and inventing one here would put a deadline on the next invoice
 * every existing tenant sends — for a date nobody in that company agreed to give.
 * Null flows all the way down to an invoice with an empty due date, and a document
 * with no due date is not overdue, which is the safe direction to be wrong in.
 *
 * The invoice's own `due_date` and the contact's own `payment_terms` need no
 * migration at all: both are ordinary fields of a metadata-driven module (§5),
 * stored in the JSONB their records already have, and installed from the module
 * blueprint rather than from a schema change.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the default payment terms a tenant gives its customers (XIV-67)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile ADD payment_terms_days INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile DROP payment_terms_days');
    }
}

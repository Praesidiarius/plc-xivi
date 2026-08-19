<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What this installation's prices are quoted in (XIV-116).
 *
 * One column on the profile, beside the currency, the region and the payment
 * term it is the fourth of: whether the numbers this customer types into a price
 * field already have the VAT in them. A shop in Zurich prices a lamp at 19.95
 * including 8.1%, because that is the number on the shelf; a consultancy prices
 * net and adds tax on the invoice. Until this column there was only the second.
 *
 * **Nullable with no backfill, and no default of `excluded`.** That is the same
 * call the currency and the payment term above it both make, and here it is the
 * one that keeps every existing record correct. Null means the question has never
 * been put to this customer, so nothing is written onto the documents they create
 * and every order and invoice they already have goes on reading exactly as it
 * read yesterday — prices net, VAT added on top, totals untouched.
 *
 * **No total is recomputed anywhere by this migration, and none could be.** Money
 * totals are derived once and then *stored* (§5.9), precisely so that a change to
 * the rules cannot restate a document somebody has been sent; a migration that
 * wrote into `net_total` would be a migration that restates somebody's invoice.
 * What a document is priced in is materialised onto the document itself as an
 * ordinary field of a metadata-driven module (§5), stored in the JSONB its
 * records already have and offered to existing customers through the module
 * upgrade path (§7.2.1) rather than forced on them — so the orders and invoices
 * need no schema change at all, and a record written before this feature carries
 * nothing there, which the engine reads as "prices exclude VAT".
 *
 * `down()` gives back a column and nothing else, which is honest: the setting is
 * a default for documents yet to be created, so dropping it loses the customer's
 * answer and touches no document. The `vat_mode` values already materialised onto
 * their orders and invoices survive it, and would be read as excluded by a build
 * old enough not to know the word — which is the same safe direction the column
 * being nullable buys going forwards.
 */
final class Version20260819095229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add whether a tenant quotes prices with VAT included (XIV-116)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile ADD vat_mode VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile DROP vat_mode');
    }
}

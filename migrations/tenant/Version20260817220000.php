<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The customer's own mark, on the row that already says what they are called
 * (XIV-49).
 *
 * **BYTEA, in the tenant's own database, exactly as `document_template.content`
 * is** (§5.7). Nothing new is being decided here: templates settled the question
 * of where a customer's small files live, and a logo is a smaller one of them —
 * there is precisely one, it belongs to nobody else, and the per-customer backup,
 * restore and export-on-churn that §4 hands us keep working with nothing added.
 * The general file-storage design attachments will need is still not being
 * started.
 *
 * **Three columns rather than one**, and each earns its place:
 *
 *   logo — the bytes.
 *   logo_content_type — what they turned out to be when they were decoded on the
 *     way in. Stored because the route serving them has to name a type in a
 *     header, and re-deciding it per request is asking the same question of the
 *     same bytes forever.
 *   logo_fingerprint — a SHA-256 of the bytes, which is the whole caching story.
 *     The mark appears on every page including the sign-in one and changes almost
 *     never, so it wants a long cache lifetime; a long lifetime that outlives a
 *     replacement means a customer uploads a new logo and is shown the old one.
 *     Putting the hash in the URL settles both at once — a different logo is a
 *     different address — and storing it means the page that builds that address
 *     never has to hash half a megabyte to do it. VARCHAR(64) is the hex length,
 *     exactly.
 *
 * All three nullable, none backfilled, and null is the state every existing
 * installation stays in until somebody uploads something. Expand-only, as §4
 * requires of a change landing for every tenant at once.
 */
final class Version20260817220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add the tenant's own logo to the instance profile (XIV-49)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile ADD logo BYTEA DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_profile ADD logo_content_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_profile ADD logo_fingerprint VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile DROP logo');
        $this->addSql('ALTER TABLE tenant_profile DROP logo_content_type');
        $this->addSql('ALTER TABLE tenant_profile DROP logo_fingerprint');
    }
}

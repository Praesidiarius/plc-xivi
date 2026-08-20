<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The tenant profile learns its QR-bill creditor data (XIV-152).
 *
 * Six columns on the one-row `tenant_profile`: the IBAN a QR-bill pays into,
 * the reference type that account can carry, and the structured postal address
 * the payment part prints as the creditor. The country is deliberately not
 * among them: the existing `region` column is the country (§8.6), and a second
 * one could only agree with it or be wrong.
 *
 * Additive only, like every tenant migration (§4.2), and the defaults *are* the
 * backfill: an existing installation wakes up with an empty IBAN, which the
 * feature reads as "no payment part yet", exactly the behaviour it had the day
 * before. The one non-empty default is the reference type, `SCOR`, because it
 * is the one setting whose default cannot be wrong for anybody: it works on
 * every ordinary IBAN that can receive a QR-bill at all (the argument lives on
 * `ReferenceType::DEFAULT`). Nothing here is `NOT NULL` without a default, so
 * the build that runs before this migration lands keeps inserting profile rows
 * happily during the deploy walk.
 *
 * The string lengths are the QR payload's own field widths (70/16/16/35 for
 * the address, 34 for an IBAN, 4 for the type token), so nothing storable can
 * later be refused by the payment part for length. The entity's setters
 * enforce the same ceilings, and the two saying different numbers would be a
 * truncation nobody sees until it prints.
 *
 * `down()` drops the six columns and with them whatever a customer typed into
 * them; the values are re-enterable from their e-banking and their letterhead,
 * so going back costs a form visit, not data anybody cannot reproduce.
 */
final class Version20260820100532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The tenant profile learns its QR-bill creditor data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_profile
                ADD payment_iban VARCHAR(34) DEFAULT '' NOT NULL,
                ADD payment_reference_type VARCHAR(4) DEFAULT 'SCOR' NOT NULL,
                ADD address_street VARCHAR(70) DEFAULT '' NOT NULL,
                ADD address_building_number VARCHAR(16) DEFAULT '' NOT NULL,
                ADD address_postal_code VARCHAR(16) DEFAULT '' NOT NULL,
                ADD address_city VARCHAR(35) DEFAULT '' NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_profile
                DROP payment_iban,
                DROP payment_reference_type,
                DROP address_street,
                DROP address_building_number,
                DROP address_postal_code,
                DROP address_city
            SQL);
    }
}

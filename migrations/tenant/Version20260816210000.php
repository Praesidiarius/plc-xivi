<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Who a tenant's mail comes from, and which server it leaves through (XIV-37).
 *
 * In the customer's own database rather than the control plane, because it is
 * their setting, edited by them on their own settings page (§8.6). The control
 * plane holds what the operator knows about them; this is what they say about
 * themselves.
 *
 * `mail_smtp_password` holds ciphertext, never a password: the same
 * `v1:<key-id>:<payload>` shape the control plane stores tenant database
 * passwords in, so `tenant:rotate-secrets` can walk it and a dump of a tenant
 * database carries no usable credential.
 *
 * Every column is nullable or defaulted, so this is additive for tenants that
 * already exist and nothing has to be backfilled — empty is exactly what
 * "nobody has configured mail" looks like.
 */
final class Version20260816210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sender identity and SMTP credentials a tenant sends mail with';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant_profile ADD mail_sender_address VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE tenant_profile ADD mail_smtp_host VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE tenant_profile ADD mail_smtp_port INT DEFAULT NULL');
        $this->addSql("ALTER TABLE tenant_profile ADD mail_smtp_user VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE tenant_profile ADD mail_smtp_password TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_profile DROP mail_sender_address');
        $this->addSql('ALTER TABLE tenant_profile DROP mail_smtp_host');
        $this->addSql('ALTER TABLE tenant_profile DROP mail_smtp_port');
        $this->addSql('ALTER TABLE tenant_profile DROP mail_smtp_user');
        $this->addSql('ALTER TABLE tenant_profile DROP mail_smtp_password');
    }
}

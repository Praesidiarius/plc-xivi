<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The permission model: groups, membership, and grants (§7.5).
 *
 * **Structural only — this writes no grants.** A migration lands for every tenant
 * at once (§4), so the one thing it must never do is decide what a customer's
 * people may do. Installations that predate this get their access back through
 * `tenant:permissions:grant-all`, run deliberately, per customer, by somebody who
 * meant it.
 *
 * There is no table of available permissions here, and that absence is the
 * design: the catalogue is ModuleAction crossed with the modules a customer has
 * installed, worked out at runtime. Nothing to seed when a module is installed,
 * nothing to migrate when a new action ships, and therefore nothing that can
 * drift away from the code.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add permission groups, membership and grants (structure only, no grants)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE permission_group (
                id SERIAL NOT NULL,
                group_key VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // "key" is spelled group_key because KEY is a keyword in enough dialects
        // to be worth never quoting, the same way app_user avoids "user".
        $this->addSql('CREATE UNIQUE INDEX uniq_permission_group_key ON permission_group (group_key)');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_group (
                user_id INT NOT NULL,
                group_id INT NOT NULL,
                PRIMARY KEY(user_id, group_id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_user_group_group ON user_group (group_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group
                ADD CONSTRAINT fk_user_group_user FOREIGN KEY (user_id)
                REFERENCES app_user (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group
                ADD CONSTRAINT fk_user_group_group FOREIGN KEY (group_id)
                REFERENCES permission_group (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE permission_grant (
                id SERIAL NOT NULL,
                group_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                module_key VARCHAR(63) NOT NULL,
                action VARCHAR(16) NOT NULL,
                scope VARCHAR(8) NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // One table for both kinds of grant, because resolving a person is a
        // union of the two and two tables would mean writing that union twice.
        // Exactly one holder is set, and the database is what enforces it —
        // "the application always sets one" is the kind of promise that holds
        // until the first import.
        $this->addSql(<<<'SQL'
            ALTER TABLE permission_grant
                ADD CONSTRAINT chk_permission_grant_one_holder
                CHECK ((group_id IS NULL) <> (user_id IS NULL))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE permission_grant
                ADD CONSTRAINT fk_permission_grant_group FOREIGN KEY (group_id)
                REFERENCES permission_group (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE permission_grant
                ADD CONSTRAINT fk_permission_grant_user FOREIGN KEY (user_id)
                REFERENCES app_user (id) ON DELETE CASCADE
            SQL);

        // Partial, because exactly one of the two columns is null on any row. One
        // holder may hold one grant per module and action; widening it is
        // changing that row's scope, never adding a second row that has to be
        // reconciled with the first.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_permission_grant_group
                ON permission_grant (group_id, module_key, action)
                WHERE group_id IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_permission_grant_user
                ON permission_grant (user_id, module_key, action)
                WHERE user_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE permission_grant');
        $this->addSql('DROP TABLE user_group');
        $this->addSql('DROP TABLE permission_group');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somebody who can sign in to the control plane (XIV-57).
 *
 * The first table in this database that holds an identity, and the first one in
 * the system that holds an identity belonging to nobody in particular's
 * customer. Every other person who can sign in is a row in `app_user` inside one
 * tenant's own database (docs/architecture/identity-and-access.md §8.1); this is the exception the
 * brief argues for in §8.9, and the reason it is an exception rather than a
 * relaxation of the rule is that its subject matter is the set of tenants and no
 * tenant's database is the right place for that.
 *
 * Nothing is seeded, and there is no sign-up. The first operator is created by
 * `control:operator:create` and by nothing else, so an installation that has run
 * this migration has a table with no rows in it — which is the correct state of
 * the world rather than a pending step. Until somebody runs the command, the
 * control plane's sign-in page refuses everybody, and that is the right default
 * for a page whose reader can see every customer.
 */
final class Version20260817090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Somebody who can sign in to the control plane';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE operator (
                id SERIAL NOT NULL,
                email VARCHAR(180) NOT NULL,
                name VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        // The login has to be unique, and it is unique across the whole
        // installation rather than within a customer — which is the one place the
        // asymmetry with `app_user` shows up in the schema. A tenant user's email
        // is only unique inside their own database (§8.2), and that is precisely
        // why a session carries the tenant it was minted for. An operator has no
        // such qualifier and needs none.
        $this->addSql('CREATE UNIQUE INDEX uniq_operator_email ON operator (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE operator');
    }
}

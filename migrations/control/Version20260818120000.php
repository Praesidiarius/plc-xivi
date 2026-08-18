<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What happened the last time [XIV-98] tried to make a tenant out of a signup.
 *
 * Three columns and no new table, because there is no new thing here — a signup
 * that failed to provision is the *same* row it was a minute earlier, still
 * confirmed, still holding its name by the unique index on `reserved_slug`, and
 * still the only record that anybody is waiting for an installation. §8.12
 * refused a third `SignupStatus` on the grounds that a state here would be a
 * second copy of a fact `tenant.slug` already holds; these columns are careful
 * not to be one. They say nothing about whether a tenant exists. They say how
 * many times the attempt has been made, when it last stopped, and at which of
 * the five steps — which is a fact about this table's own work and is recorded
 * nowhere else.
 *
 * `provisioning_stage` is a stage rather than the exception's message, and that
 * is [XIV-59]'s rule applied a second time: a driver's words name hosts, ports
 * and roles, which belongs in the terminal of somebody who already holds the
 * DSN rather than on a row something might one day draw on a page. The run
 * prints the words; this remembers the decision.
 *
 * **Backfilled to zero and NULL, which is the truth for every existing row.**
 * Nothing has ever tried to provision one of these, because until this migration
 * ran there was nothing that could.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record what happened the last time a confirmed signup failed to become a tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE signup_request
                ADD provisioning_attempts INT DEFAULT 0 NOT NULL,
                ADD provisioning_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD provisioning_stage VARCHAR(16) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE signup_request
                DROP provisioning_attempts,
                DROP provisioning_failed_at,
                DROP provisioning_stage
            SQL);
    }
}

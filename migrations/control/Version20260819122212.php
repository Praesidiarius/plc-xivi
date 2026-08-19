<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A customer's question, as an operator meets it (XIV-123).
 *
 * The control-plane half of a pair. A customer raises a ticket in their own
 * database — `Version20260819122216`, the tenant migration beside this one —
 * because §4.4 gives a customer-facing instance `SELECT` on the registry and no
 * write privilege anywhere in this database, so a write made by a customer's
 * request has exactly one home. `tenant:support:collect` copies the question
 * here, and **everything the operator adds is added here**: the status, the
 * reply, who wrote it and when.
 *
 * ## Which half of this database it is in, and it is the whole decision
 *
 * **The registry half**, so `support_request` is an `App\Registry\Entity` class
 * and not one of `Xivi\ControlPlane\Entity`'s. That is not filing.
 * `App\Deployment\RegistryGrants` derives the tables a customer-facing instance
 * may read by walking the mapping for that namespace and no other, so the
 * namespace *is* the grant — and this row has to be readable by a customer,
 * because the status and the reply on it are the answer they came back to look
 * at.
 *
 * That is where this parts company with [XIV-102]'s `purchase_intent`, which is
 * an administration-surface table: a purchase request is collected for an
 * operator to *read*, and a support ticket is collected so that an operator can
 * *answer*. An answer has to reach the person who asked.
 *
 * **So a deploy has to run `bin/console deploy:registry-grants` again**, and an
 * installation that skips it gets a customer-facing instance whose role cannot
 * read this table. The failure is immediate, loud and total for that instance
 * rather than latent — the support page 500s — which is the same trade
 * [XIV-120] made for `notice` and wrote down the same way. `CHANGELOG.md` names
 * it as an action, and
 * `tests/Functional/Deployment/SupportGrantsTest.php` proves the grant works
 * against a real role rather than trusting this paragraph.
 *
 * ## The reference, and why the match is on a pair
 *
 * `reference` is 128 random bits generated in the customer's database, and the
 * collector matches on `(tenant_id, reference)`. Two reasons, and the second is
 * the sharper one:
 *
 * * A customer's row ids are a sequence in *their* database, so a database
 *   rebuilt from scratch (`tenant:reset`) starts again at 1 — and a collector
 *   matching on the id would find "ticket 1" and overwrite the row holding an
 *   operator's answer to a different question.
 * * A reference is a value produced inside a customer's database. Matching on it
 *   alone would let one customer name another's row by producing the same
 *   string, so the tenant is half of the key and half of the unique index.
 *
 * ## `down()` drops the table and loses every answer
 *
 * Nothing else holds them: the operator's reply and the status exist in this
 * table and in no other database, by design. What survives is the customers'
 * own tickets, which are rows in their own databases — so a re-`up()` into an
 * empty table plus one collection gives an installation every question back with
 * none of the answers. That is the honest reading, and it is why the reply is
 * the one thing in this feature worth a database backup.
 */
final class Version20260819122212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A customer raises a support ticket (XIV-123)';
    }

    public function up(Schema $schema): void
    {
        // `subject`, `body` and `raised_at` are copies of the customer's row,
        // rewritten by every collection and edited by nobody here. `status`,
        // `reply`, `replied_at` and `reply_author_label` are the operator's and
        // are **never touched by the collector** — a collection that rewrote the
        // whole row would discard an answer whenever it overlapped with somebody
        // typing one, on a job that runs every five minutes.
        //
        // `status` is a short string rather than a boolean because the case that
        // earns the column is the middle one: *somebody has picked this up* is
        // the sentence a waiting customer most wants and that nothing else on the
        // row can say. There is deliberately no `answered` state — a reply is
        // visible two columns away, and a status naming it would be a second copy
        // of a fact free to disagree with it (§8.15's argument, §8.17's version).
        //
        // `reply_author_label` is a copy of the operator's name and **not** a
        // foreign key to `operator`: §4.4 gives the customer-facing role no access
        // to that table at all, so a join would be unreadable by the only party
        // the value is for. `notice.author_label` is the same column for the same
        // two reasons.
        $this->addSql(<<<'SQL'
            CREATE TABLE support_request (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                tenant_id INT NOT NULL,
                reference VARCHAR(32) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                raised_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                collected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status VARCHAR(32) NOT NULL,
                reply TEXT DEFAULT NULL,
                replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                reply_author_label TEXT DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The pair, never the reference alone — see the class docblock. This is
        // also what makes the collector's "seen before?" lookup an index seek
        // rather than a scan, once per ticket per run.
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_support_request_reference ON support_request (tenant_id, reference)',
        );

        // The customer's own support page asks "everything for this tenant", and
        // the unique index answers it only when the reference is known first,
        // which is the other direction.
        $this->addSql('CREATE INDEX idx_support_request_tenant ON support_request (tenant_id)');

        // The operator's page sorts by when it was asked, which is the number
        // that says how long somebody has been waiting.
        $this->addSql('CREATE INDEX idx_support_request_raised ON support_request (raised_at)');

        // `ON DELETE CASCADE` for `notice_recipient`'s and `purchase_intent`'s
        // reason word for word: a deprovisioned customer's tickets are
        // meaningless, and a foreign key left standing turns a clean removal into
        // a constraint violation somebody clears by hand at exactly the wrong
        // moment.
        $this->addSql(<<<'SQL'
            ALTER TABLE support_request
                ADD CONSTRAINT fk_support_request_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE support_request');
    }
}

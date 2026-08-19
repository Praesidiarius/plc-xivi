<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Xivi\Core\Period\PeriodPrecision;
use Xivi\Core\Period\PeriodSql;

/**
 * Every table a customer's database starts with, in one file (XIV-151).
 *
 * ## Why there is one of these and not thirty-seven
 *
 * This replaces the thirty-seven tenant migrations written between 2026-08-13
 * and 2026-08-19 — fifteen of them on the last of those days. They are gone from
 * the working tree and they are **not** gone from the repository: git has every
 * one of them, with the argument each was written with, and the way to read them
 * is
 *
 * ```
 * git log --diff-filter=D --name-only -- migrations/tenant   # when they went
 * git show <the commit before>:migrations/tenant/Version20260818150002.php
 * ```
 *
 * **The reason this was possible is that nothing was deployed anywhere.** A
 * migration has two jobs — build a schema, and walk an existing installation
 * from one version of it to the next — and on 2026-08-19 the second job had no
 * installation to do it for. XIV-61's deploy definition was still open, the first
 * deployment had not happened, and every database the whole history had ever
 * touched was a development tenant that could be dropped and rebuilt. §4.2
 * records that, and records that the window shut behind this commit: from the
 * first real deployment onwards a squash would mean telling a live database that
 * a version it never ran is applied, and this is the last file that ever gets to
 * be written this way.
 *
 * ## What was carried over, and what deliberately was not
 *
 * What a squash throws away is not schema — a diff proves the schema — it is the
 * *reasoning*, which is the failure XIV-149 spent the same day guarding the brief
 * against: keeping the conclusion and losing the argument. So every group below
 * names the ticket it came from and the section of the brief that holds its
 * argument, and the notes explaining **why a column exists at all** are repeated
 * here rather than cited, because a reader who has to run `git show` to find out
 * why a nullable column is nullable will not run it.
 *
 * Three kinds of statement from those thirty-seven files are **not** here, and
 * their absence is a fact about a fresh database rather than an omission:
 *
 *  * **Retro-fits over rows that do not exist yet.** `Version20260818150002`
 *    (XIV-109) read each customer's own `field_definition` rows and built a
 *    unique expression index for every field already marked unique;
 *    `Version20260815260000` (XIV-21) added `position` to every collection table
 *    a customer had; `Version20260818091000` (XIV-97) converted eleven `SERIAL`
 *    columns to identity. On an empty database all three are no-ops — the second
 *    logged "was executed but did not result in any SQL statements" on every
 *    provision — because there is nothing to read and the tables below are
 *    created in their final shape. What XIV-109 *established* is not lost:
 *    {@see \Xivi\Core\Record\UniqueIndex} builds those indexes at runtime when a
 *    module is installed or a field is marked unique, which is where a fresh
 *    tenant's expression indexes over `data ->> 'key'` come from and always was.
 *  * **The expand half of an expand/contract pair whose contract half also ran.**
 *    A column added in one migration and widened in a later one is created wide
 *    here, once. `permission_grant.action` is the example: 15 characters until
 *    XIV-80's follow-up actions did not fit, 31 from then on and 31 below.
 *  * **The `EXCLUDE USING gist` constraints themselves.** They are built by
 *    {@see \Xivi\Core\Record\OverlapExclusion} when a customer marks a period
 *    field exclusive within something, never by a migration, so a freshly
 *    provisioned tenant has none of them. What a migration *does* have to
 *    install is what they are built *over*, and that is the first thing `up()`
 *    does.
 *
 * ## The order is the order the dependencies force
 *
 * The extension and the two functions first, because nothing else may reference
 * them; then `app_user` and the metadata tables, which everything with a foreign
 * key points at; then the per-feature tables; then the indexes and the foreign
 * keys, together at the end, where they read as one list rather than as a trail.
 *
 * It is one long `up()` rather than six tidy private methods, and that is on
 * purpose: `tests/Unit/TenantMigrationsAreAdditiveTest.php` reads the body of
 * `up()` and nothing else, so SQL moved into a helper is SQL that guard cannot
 * see. A baseline is exactly the file big enough to be worth splitting and
 * exactly the file that must not be.
 *
 * ## Two names this file deliberately does not reproduce
 *
 * `module_definition` became `shape_definition` on 2026-08-14, and Postgres kept
 * the old name on everything the rename could not touch: the primary key stayed
 * `module_definition_pkey`, the identity sequence stayed
 * `module_definition_id_seq`, and five columns kept `NOT NULL` constraints named
 * `module_definition_*`. Nothing in the application, the suite or the mapping
 * refers to any of them — they were visible only in `pg_dump` — and a baseline
 * that has never heard of a `module_definition` should not carry its name
 * forward for another year. They are spelled the obvious way here, and that is
 * the only intentional difference between the schema this builds and the schema
 * the thirty-seven built.
 *
 * `down()` drops the schema. It is the one migration in this set for which that
 * is honest: there is no earlier state to go back to, so "reverse the baseline"
 * and "there is no tenant database" are the same sentence.
 */
final class Version20260819200755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The tenant schema, as one baseline (XIV-151)';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------------
        // btree_gist and the two IMMUTABLE range functions (XIV-136)
        // ---------------------------------------------------------------------
        //
        // The only objects in a fresh tenant database that are not a table, an
        // index or a constraint — and therefore the part of this baseline most
        // easily lost by generating one from the Doctrine mapping, which knows
        // about tables and knows nothing about either of these.
        //
        // A period field may say what it is exclusive within, and the engine then
        // refuses two overlapping periods for the same thing *in the database*,
        // with `EXCLUDE USING gist`. That constraint is an index, and an index
        // needs both of these before it can exist:
        //
        //   * `btree_gist`, because GiST has no operator class for plain equality
        //     on text — "the same room *and* an overlapping period" is half
        //     equality and half range, and the two have to sit in one index.
        //     Trusted since Postgres 13, so the tenant's own role installs it and
        //     no operator walks the cluster by hand.
        //   * an IMMUTABLE way to read a stored period as a range, because every
        //     expression in an index must be immutable and `(data ->> 'stay')::date`
        //     is not: `date_in` is only *stable*, since it reads `DateStyle` and
        //     accepts `today`.
        //
        // Installed for every tenant whether or not that customer has a period
        // field today, for the reason the original migration gave: the
        // alternative is a `CREATE EXTENSION` inside the transaction of a
        // metadata edit, on a connection whose role may no longer be allowed to
        // run one. It costs a few kilobytes per database, once.
        //
        // The bodies are imported rather than copied. What is shared with
        // `PeriodFieldType::comparableSql()` is a *spelling* two places must
        // agree on, and a copy is precisely how they come to disagree. The cost
        // is named in PeriodSql and is worth repeating: Postgres does not
        // re-evaluate an index when a function it was built over changes, so
        // editing a body there is not an edit — it is a new migration that
        // replaces the function *and* rebuilds every constraint over it.
        foreach (PeriodSql::definitions() as $statement) {
            $this->addSql($statement);
        }

        // ---------------------------------------------------------------------
        // People, groups and grants (§7.5, §8.5)
        // ---------------------------------------------------------------------
        //
        // `app_user` is the oldest table here and was added to eleven times,
        // which is why its columns are not in a tidy order: they are in the order
        // they arrived, and reordering them now would be a change to the schema
        // made for the benefit of nobody.
        //
        // `must_change_password` (§8.5) is an invited account's state until the
        // person chooses their own. `invitation_seed` (XIV-1) is the rotating
        // value that makes an invitation link expire the moment it is used,
        // rather than at a time somebody has to remember to set.
        //
        // `locale` (XIV-8), `region` (XIV-50, §8.6) and `timezone` (XIV-83) are
        // nullable on purpose and all mean the same thing when NULL: fall back to
        // the installation's setting on `tenant_profile`. A default here would be
        // this file deciding what language a person reads in.
        //
        // `dashboard_layout` (XIV-66, §7.6) is the person's own arrangement,
        // taking precedence over the installation's; NULL means they have not
        // moved anything yet.
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                password VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                active BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                email VARCHAR(180) NOT NULL,
                name VARCHAR(255) NOT NULL,
                must_change_password BOOLEAN DEFAULT FALSE NOT NULL,
                locale VARCHAR(5) DEFAULT NULL,
                region VARCHAR(2) DEFAULT NULL,
                invitation_seed VARCHAR(64) DEFAULT NULL,
                timezone VARCHAR(64) DEFAULT NULL,
                dashboard_layout JSON DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE permission_group (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                group_key VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_group (
                user_id INT NOT NULL,
                group_id INT NOT NULL,
                PRIMARY KEY (user_id, group_id)
            )
            SQL);

        // A grant belongs to a group or to a person, never to both and never to
        // neither. That is the one hand-written CHECK constraint in this
        // database, written as an exclusive-or over the two nullable keys rather
        // than left to the application: two nullable foreign keys and a hope is
        // how a grant that belongs to nobody gets written by a bug and then read
        // by the authorisation code as though it meant something.
        //
        // `action` is 31 characters rather than the 15 it was created with —
        // XIV-80's follow-up actions did not fit, and widening a column is one of
        // the few alterations §4.2's window allows in a single release.
        $this->addSql(<<<'SQL'
            CREATE TABLE permission_grant (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                group_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                module_key VARCHAR(63) NOT NULL,
                action VARCHAR(31) NOT NULL,
                scope VARCHAR(8) NOT NULL,
                CONSTRAINT chk_permission_grant_one_holder CHECK ((group_id IS NULL) <> (user_id IS NULL)),
                PRIMARY KEY (id)
            )
            SQL);

        // ---------------------------------------------------------------------
        // What this customer's data *is* (§5)
        // ---------------------------------------------------------------------
        //
        // The two tables the engine is built on. A shape is a module or a
        // collection inside one — `shape_kind` says which, `parent_id` points at
        // the module a collection belongs to — and its fields are rows rather
        // than columns, which is what lets one customer add a field without a
        // migration and without touching anybody else's database.
        //
        // These are a customer's *own* definitions and they outrank the
        // blueprints the modules ship (§6.1). Installing does not retro-fit, so a
        // tenant may lack a field a blueprint declares or carry one they renamed;
        // code that wants to know what a tenant has reads these tables and never
        // the module class.
        //
        // `table_name` is where that shape's records live, and it is stored
        // rather than derived because the derivation changed twice and the
        // records did not move.
        //
        // The nullable columns are each an answer to "what does this mean for a
        // shape created before the feature existed":
        //
        //   * `position` — the order modules are listed in; NULL sorts last.
        //   * `icon`, `variant_field` (§5.2, §5.5) — absent rather than empty.
        //   * `follow_ups_enabled` (XIV-80, §7.2) — the per-module opt-out. NULL
        //     is "not decided", which the engine reads as on. The mapping wants a
        //     default of TRUE here and does not get one; that is one of the
        //     sixteen differences `tenant:schema:validate` reports, and §9.2 owns
        //     the whole list rather than this file.
        //   * `declined_additions` (XIV-70, §7.2.1) — which blueprint additions
        //     this customer has said no to, so that saying no once is enough.
        //   * `sections` (XIV-119, §5.1) — the headings fields are grouped under
        //     on the form. NULL is a form with no headings, which is every form
        //     that existed before the feature.
        $this->addSql(<<<'SQL'
            CREATE TABLE shape_definition (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                installed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                shape_key VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                table_name VARCHAR(63) NOT NULL,
                shape_kind VARCHAR(31) NOT NULL,
                parent_id INT DEFAULT NULL,
                position INT DEFAULT NULL,
                icon VARCHAR(63) DEFAULT NULL,
                variant_field VARCHAR(63) DEFAULT NULL,
                follow_ups_enabled BOOLEAN DEFAULT NULL,
                declined_additions JSON DEFAULT NULL,
                sections JSON DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // `options` is the per-type configuration (§5.9) and carries, among other
        // things, the numbering pattern §5.10's document numbers are drawn from.
        // `variants` is which variants of a shape the field appears on. `width`
        // (XIV-43) is how many twelfths of a row it is drawn in, NULL meaning
        // "the type decides". `is_derived` (XIV-20) marks a value the record
        // computes rather than takes — writing one by hand suppresses the engine
        // and produces a record that looks plausible and is wrong (XIV-73).
        //
        // `is_unique` is the flag that builds a unique expression index over
        // `data ->> 'key'` at runtime (XIV-109). The flag is here; the index is
        // not, because on a fresh database there is no field yet to build one
        // for. `section_key` names which of the parent shape's headings the field
        // sits under (XIV-119).
        $this->addSql(<<<'SQL'
            CREATE TABLE field_definition (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                options JSON NOT NULL,
                field_key VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                field_type VARCHAR(63) NOT NULL,
                required BOOLEAN NOT NULL,
                is_unique BOOLEAN NOT NULL,
                filterable BOOLEAN NOT NULL,
                position INT NOT NULL,
                is_system BOOLEAN NOT NULL,
                shape_id INT NOT NULL,
                is_listed BOOLEAN NOT NULL,
                is_title BOOLEAN NOT NULL,
                variants JSON NOT NULL,
                is_derived BOOLEAN DEFAULT FALSE NOT NULL,
                width SMALLINT DEFAULT NULL,
                section_key VARCHAR(63) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // ---------------------------------------------------------------------
        // The installation's own settings (§8.6)
        // ---------------------------------------------------------------------
        //
        // One row, id 1, which is why this is the one table here whose primary
        // key draws from nothing: there is never a second row to number.
        // Everything nullable means "not configured" and falls back to the
        // environment.
        //
        // `mail_*` (XIV-37) is the identity and credentials this tenant sends
        // mail with; `mail_smtp_password` is TEXT because it holds a ciphertext
        // (§8.9), not a password, and a ciphertext has no length a column should
        // promise. `region` (XIV-50) is which country writes numbers this way.
        // `currency` and `vat_mode` (XIV-116, §5.9) are whether a quoted price
        // already has the VAT in it. `payment_terms_days` (XIV-67) is the default
        // a customer's invoices are due in. `logo` with its content type and
        // fingerprint (XIV-49, §5.7) is what a generated document is stamped
        // with — the fingerprint so a template cache can tell it has changed
        // without reading the bytes back out.
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_profile (
                id INT NOT NULL,
                company_name VARCHAR(255) DEFAULT '' NOT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                region VARCHAR(2) DEFAULT NULL,
                mail_sender_address VARCHAR(255) DEFAULT '' NOT NULL,
                mail_smtp_host VARCHAR(255) DEFAULT '' NOT NULL,
                mail_smtp_port INT DEFAULT NULL,
                mail_smtp_user VARCHAR(255) DEFAULT '' NOT NULL,
                mail_smtp_password TEXT DEFAULT NULL,
                payment_terms_days INT DEFAULT NULL,
                timezone VARCHAR(64) DEFAULT NULL,
                logo BYTEA DEFAULT NULL,
                logo_content_type VARCHAR(64) DEFAULT NULL,
                logo_fingerprint VARCHAR(64) DEFAULT NULL,
                dashboard_layout JSON DEFAULT NULL,
                vat_mode VARCHAR(16) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // **The one row of data in this file, and it is not optional.** The
        // profile is a singleton — id 1, always — and every setting on it is
        // written by `UPDATE`, never by an upsert. Without this row an
        // administrator can save the company name, the currency, the SMTP
        // credentials or the installation's dashboard layout and have the save
        // report success while changing nothing, because `UPDATE … WHERE id = 1`
        // against no rows is not an error.
        //
        // It is called out at this length because it is the one thing in the
        // XIV-151 squash that a schema comparison cannot check. `pg_dump
        // --schema-only` of a database built by the old thirty-seven and of one
        // built by this file are identical whether or not this statement is here;
        // what caught its absence was the suite — `DashboardLayoutTest` setting an
        // installation layout that then did not apply. A baseline is a schema
        // *plus* whatever rows the schema is meaningless without, and this is the
        // only such row in either database.
        $this->addSql("INSERT INTO tenant_profile (id, company_name, currency, updated_at) VALUES (1, '', NULL, NOW())");

        // ---------------------------------------------------------------------
        // The per-feature tables
        // ---------------------------------------------------------------------

        // XIV-15, §5.10. The counters document numbers are drawn from: one row
        // per (shape, field, period), and `next_value` moves in one statement and
        // only forward, which is what makes two documents with the same number
        // impossible by arithmetic. `period` is the reset window written out, so
        // a yearly sequence and a never-resetting one are the same table.
        //
        // Arithmetic is not a constraint, though — it is complete about the
        // numbers this counter gave out and blind to everything else that can
        // reach the column — which is why XIV-109 also marks a numbered field
        // unique, and why the engine builds an index off that flag.
        $this->addSql(<<<'SQL'
            CREATE TABLE number_sequence (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                shape_key VARCHAR(63) NOT NULL,
                field_key VARCHAR(63) NOT NULL,
                period VARCHAR(15) NOT NULL,
                next_value BIGINT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-4, §5.7. The .docx templates records are generated from, stored as
        // bytes in the tenant's own database rather than on a disk somewhere: a
        // customer's template is customer data, and the export that leaves with
        // them on churn is a database dump (§4).
        $this->addSql(<<<'SQL'
            CREATE TABLE document_template (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                module_key VARCHAR(63) NOT NULL,
                variant VARCHAR(63) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                content BYTEA NOT NULL,
                uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                uploaded_by VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-38, §5.13. Subject and Markdown body. `updated_by` is a label and
        // not a foreign key — here and in every table below that has one — for a
        // reason worth stating once and applying throughout: the person who wrote
        // this may have been removed since, and "written by somebody who no
        // longer works here" has to survive that. A cascade would delete the
        // record; a restrict would forbid removing the person.
        $this->addSql(<<<'SQL'
            CREATE TABLE email_template (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                module_key VARCHAR(63) NOT NULL,
                variant VARCHAR(63) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_by VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-80, §7.2, §8.4. A follow-up hangs off a record in any module —
        // `(module, record_id)` rather than a foreign key, because records are not
        // Doctrine entities and the table they live in depends on the shape (§5).
        //
        // `due_at` and `done_at` are WITH TIME ZONE while almost everything else
        // here is not, and that is deliberate: a due date is a moment somebody is
        // reminded at, and §8.4 makes the instant the stored thing rather than
        // the wall clock. The assignee and the author are each stored twice, an
        // id and a label, for the reason given on `email_template` above.
        // `created_by_id` is NOT NULL and its label with it, because a follow-up
        // nobody raised is not a follow-up.
        $this->addSql(<<<'SQL'
            CREATE TABLE follow_up (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                module VARCHAR(63) NOT NULL,
                record_id INT NOT NULL,
                priority VARCHAR(15) NOT NULL,
                due_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                assignee_id INT DEFAULT NULL,
                assignee_label TEXT DEFAULT NULL,
                done_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_by_id INT NOT NULL,
                created_by_label TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE follow_up_note (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                follow_up_id INT NOT NULL,
                body TEXT NOT NULL,
                author_id INT NOT NULL,
                author_label TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-12. The ledger of generated demo data, so `tenant:reset` and the
        // demo generator can tell a generated record from one a person typed and
        // remove only the first. `id` is BIGINT because this is the one table
        // whose row count is a function of how often somebody ran a generator
        // rather than of how big the business is.
        $this->addSql(<<<'SQL'
            CREATE TABLE demo_record (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                shape_key VARCHAR(63) NOT NULL,
                record_id INT NOT NULL,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-103, §5.19. The guarded counter behind a voucher usage limit. One
        // row per voucher, which the unique index below is what makes true: the
        // count is incremented in the same statement that reads it, so two
        // redemptions arriving together cannot both see the limit unreached.
        $this->addSql(<<<'SQL'
            CREATE TABLE voucher_redemption (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                voucher_id INT NOT NULL,
                redeemed_count INT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-102, §6.5. A customer asking to buy a priced module. The price is
        // copied in rather than looked up, because what an operator needs to see
        // is the price the customer was shown — the catalogue may have moved
        // since. `price_currency` is nullable for the installation that has not
        // set one, which is why `PRICE_CURRENCY` is pinned empty in the test
        // environment rather than to a plausible three letters.
        $this->addSql(<<<'SQL'
            CREATE TABLE module_purchase_intent (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                module_key VARCHAR(63) NOT NULL,
                price_amount NUMERIC(12, 2) NOT NULL,
                price_currency VARCHAR(3) DEFAULT NULL,
                requested_by_id INT DEFAULT NULL,
                requested_by_label TEXT NOT NULL,
                requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-120, §8.16. An operator writes a notice; a reader dismisses it, and
        // this is the record of who. `notice_id` is an id in the *control-plane*
        // database and therefore cannot be a foreign key — the two are separate
        // databases (§3.1), and this is one of the few places that shows in the
        // schema rather than only in the code.
        $this->addSql(<<<'SQL'
            CREATE TABLE notice_dismissal (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                notice_id INT NOT NULL,
                user_id INT NOT NULL,
                dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-123, §8.17. A customer raising a support ticket. `reference` is what
        // both sides quote at each other, and it is unique here and unique *per
        // tenant* in the control plane's own copy — two customers may both be
        // looking at their ticket 7.
        $this->addSql(<<<'SQL'
            CREATE TABLE support_ticket (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                reference VARCHAR(32) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                raised_by_id INT DEFAULT NULL,
                raised_by_label TEXT NOT NULL,
                raised_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // XIV-127, §5.4. A list a customer keeps — the values behind a choice
        // field, edited by them rather than shipped by a module. `parent_id` on an
        // entry is what makes a list hierarchical (a canton inside a country).
        $this->addSql(<<<'SQL'
            CREATE TABLE value_list (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                list_key VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE value_list_entry (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                list_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                entry_value VARCHAR(63) NOT NULL,
                label VARCHAR(255) NOT NULL,
                tone VARCHAR(31) DEFAULT NULL,
                icon VARCHAR(63) DEFAULT NULL,
                position INT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // ---------------------------------------------------------------------
        // Indexes
        // ---------------------------------------------------------------------
        //
        // The plain `idx_*` ones are foreign-key lookups and the one or two
        // orderings the application actually makes: `idx_follow_up_assignee` is
        // `(assignee_id, done_at, due_at)` because "my open follow-ups, soonest
        // first" is one query and wants one index.
        //
        // Four are partial, and each WHERE is load-bearing:
        //
        //   * `uniq_permission_grant_user` and `_group` — one live grant per
        //     holder per module per action. Partial because half the rows have a
        //     NULL in the column being made unique, and NULL does not collide
        //     with NULL in a btree: a plain UNIQUE (group_id, module_key, action)
        //     would let a hundred user-grants pile up on a NULL group (§7.5).
        //   * `uniq_shape_definition_module_key` — a *module's* key is unique
        //     among modules, WHERE parent_id IS NULL. A collection's key is
        //     unique only within its parent, which is what
        //     `uniq_shape_definition_collection_key` says, because two modules
        //     may each have a `lines` collection.
        //
        // The names are chosen here rather than left to Doctrine because the
        // application and the engine quote them in their own error handling. That
        // is also why `tenant:schema:validate` reports six `ALTER INDEX … RENAME
        // TO IDX_…` differences: the mapping would prefer its generated names.
        // §9.2 owns that disagreement, and it predates this file by a week.
        foreach ([
            'CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)',
            'CREATE INDEX idx_user_group_group ON user_group (group_id)',
            'CREATE UNIQUE INDEX uniq_permission_group_key ON permission_group (group_key)',
            'CREATE UNIQUE INDEX uniq_permission_grant_user ON permission_grant (user_id, module_key, action) WHERE user_id IS NOT NULL',
            'CREATE UNIQUE INDEX uniq_permission_grant_group ON permission_grant (group_id, module_key, action) WHERE group_id IS NOT NULL',

            'CREATE UNIQUE INDEX uniq_shape_definition_table ON shape_definition (table_name)',
            'CREATE UNIQUE INDEX uniq_shape_definition_module_key ON shape_definition (shape_key) WHERE parent_id IS NULL',
            'CREATE UNIQUE INDEX uniq_shape_definition_collection_key ON shape_definition (parent_id, shape_key)',
            'CREATE INDEX idx_shape_definition_parent ON shape_definition (parent_id)',
            'CREATE UNIQUE INDEX uniq_field_definition_shape_key ON field_definition (shape_id, field_key)',
            'CREATE INDEX idx_field_definition_shape ON field_definition (shape_id)',

            'CREATE UNIQUE INDEX uniq_number_sequence ON number_sequence (shape_key, field_key, period)',
            'CREATE INDEX idx_document_template_module ON document_template (module_key)',
            'CREATE INDEX idx_email_template_module ON email_template (module_key)',
            'CREATE INDEX idx_follow_up_record ON follow_up (module, record_id)',
            'CREATE INDEX idx_follow_up_assignee ON follow_up (assignee_id, done_at, due_at)',
            'CREATE INDEX idx_follow_up_note_follow_up ON follow_up_note (follow_up_id)',
            'CREATE INDEX idx_demo_record_shape ON demo_record (shape_key)',
            'CREATE UNIQUE INDEX uniq_voucher_redemption ON voucher_redemption (voucher_id)',
            'CREATE UNIQUE INDEX uniq_module_purchase_intent_module ON module_purchase_intent (module_key)',
            'CREATE UNIQUE INDEX uniq_notice_dismissal ON notice_dismissal (notice_id, user_id)',
            'CREATE INDEX idx_notice_dismissal_user ON notice_dismissal (user_id)',
            'CREATE UNIQUE INDEX uniq_support_ticket_reference ON support_ticket (reference)',
            'CREATE UNIQUE INDEX uniq_value_list_key ON value_list (list_key)',
            'CREATE UNIQUE INDEX uniq_value_list_entry_value ON value_list_entry (list_id, entry_value)',
            'CREATE INDEX idx_value_list_entry_parent ON value_list_entry (parent_id)',
        ] as $statement) {
            $this->addSql($statement);
        }

        // ---------------------------------------------------------------------
        // Foreign keys
        // ---------------------------------------------------------------------
        //
        // All named, and all saying what a delete means. ON DELETE CASCADE
        // everywhere except one, and the exception is the one worth reading:
        // `fk_value_list_entry_parent` is SET NULL, because removing a parent
        // entry from a hierarchical list must promote its children rather than
        // delete them (XIV-127).
        //
        // There is deliberately no foreign key from `follow_up`,
        // `notice_dismissal`, `support_ticket` or `module_purchase_intent` to
        // `app_user`. Each stores an author or an assignee as an id *and* a label
        // so that removing a person leaves the history readable with a name on
        // it: the label is the record, and the id is a convenience for as long as
        // that person exists.
        foreach ([
            'ALTER TABLE user_group ADD CONSTRAINT fk_user_group_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
            'ALTER TABLE user_group ADD CONSTRAINT fk_user_group_group FOREIGN KEY (group_id) REFERENCES permission_group (id) ON DELETE CASCADE',
            'ALTER TABLE permission_grant ADD CONSTRAINT fk_permission_grant_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
            'ALTER TABLE permission_grant ADD CONSTRAINT fk_permission_grant_group FOREIGN KEY (group_id) REFERENCES permission_group (id) ON DELETE CASCADE',
            'ALTER TABLE shape_definition ADD CONSTRAINT fk_shape_definition_parent FOREIGN KEY (parent_id) REFERENCES shape_definition (id) ON DELETE CASCADE',
            'ALTER TABLE field_definition ADD CONSTRAINT fk_field_definition_shape FOREIGN KEY (shape_id) REFERENCES shape_definition (id) ON DELETE CASCADE',
            'ALTER TABLE follow_up_note ADD CONSTRAINT fk_follow_up_note_follow_up FOREIGN KEY (follow_up_id) REFERENCES follow_up (id) ON DELETE CASCADE',
            'ALTER TABLE value_list_entry ADD CONSTRAINT fk_value_list_entry_list FOREIGN KEY (list_id) REFERENCES value_list (id) ON DELETE CASCADE',
            'ALTER TABLE value_list_entry ADD CONSTRAINT fk_value_list_entry_parent FOREIGN KEY (parent_id) REFERENCES value_list_entry (id) ON DELETE SET NULL',
        ] as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        // Children before parents, so nothing has to be told to cascade. The
        // record tables a module installs are not here and cannot be: they are
        // named in `shape_definition`, which this is about to drop, and they are
        // the engine's to remove (`tenant:deprovision` drops the database, which
        // is the honest way to undo a baseline).
        foreach ([
            'value_list_entry', 'value_list', 'support_ticket', 'notice_dismissal',
            'module_purchase_intent', 'voucher_redemption', 'demo_record',
            'follow_up_note', 'follow_up', 'email_template', 'document_template',
            'number_sequence', 'tenant_profile', 'field_definition', 'shape_definition',
            'permission_grant', 'user_group', 'permission_group', 'app_user',
        ] as $table) {
            $this->addSql(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        // The extension stays: it is shared, harmless, and may have been
        // installed by somebody else entirely. The functions go, and dropping one
        // an exclusion constraint still depends on fails outright — which is the
        // right way round, because a rollback that quietly took a booking rule
        // with it would be the worst thing this file could do.
        foreach (PeriodPrecision::cases() as $precision) {
            $this->addSql(sprintf('DROP FUNCTION IF EXISTS %s(text)', $precision->rangeFunction()));
        }
    }
}

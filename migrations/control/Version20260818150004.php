<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A module can have a price, and this deployment is the one that sets it (XIV-101).
 *
 * Two columns beside `state` on the module row, for the reason [XIV-7] put
 * `state` there: a blueprint is code and ships identically to every deployment,
 * so a price in `packages/invoice/` would be a price every installation inherits
 * and none of them chose (docs/architecture.md §6.5, §6.2).
 *
 * ## The two defaults are different, and the difference is the whole migration
 *
 * `pricing` is added `NOT NULL DEFAULT 'free'` and the default is then dropped
 * in the very next statement. That looks like a trick and is not; it is two
 * facts being written by one column, and they are genuinely different facts.
 *
 * * **Rows that already exist become `free`.** Every module in this repository
 *   is free today and §6.3 says so in as many words — "every module is free in
 *   this iteration". Writing that down is recording a fact, not inventing one,
 *   and it is what keeps this migration from silently taking every published
 *   module out of the store of an installation that already has customers
 *   browsing it. The `DEFAULT` on the `ADD COLUMN` is how PostgreSQL backfills
 *   an existing table in one pass.
 * * **Rows created after this become `unpriced`.** That is `ModulePricing`'s
 *   PHP default, and it is deliberately *not* free. "Free" and "nobody has said
 *   yet" are different, and collapsing them is how the next module somebody
 *   publishes ships at zero without anybody deciding it should. So the column
 *   default is dropped rather than left at `free`: the database now insists that
 *   whoever writes a row states the answer, and the only thing that writes one
 *   is `ModuleCatalog`, which states `unpriced`.
 *
 * A `CHECK` constraint tying `price_amount` to `pricing = 'priced'` was
 * considered and left out. The invariant is real and is enforced — in
 * `App\Registry\Pricing\ModulePrice`, on the way in *and* on the way out, so a
 * row that somehow breaks it throws when it is read rather than being rendered
 * as something plausible. A constraint would add a second statement of the same
 * rule in a language that cannot say the rest of it (that zero is not a price,
 * that the amount is rounded to two places), and this table has one writer.
 *
 * ## What an installation has to do after this, and what it must not read into it
 *
 * **Nothing is uninstalled and nobody is charged.** A price is what a module
 * costs from here on; §6.2 already settled that a decision on this row says what
 * may be obtained from the store and never what is taken away from a customer
 * who has it. Payment does not exist yet either — that is [XIV-102].
 *
 * What an operator does have to do is price the *next* module they publish,
 * because a published module with no price is not offered in the store. The
 * screen at `/control/modules`, `module:list` and `module:state` all say so at
 * the moment it matters.
 *
 * `down()` drops both columns, which loses every price anybody has set. That is
 * the honest behaviour for a column that is going away and it is why this is a
 * control migration rather than a tenant one: §4.2's additive-only window is
 * about the walk across customer databases with the instance still serving, and
 * there is one control-plane database, moved by `bin/deploy` before the serving
 * containers are replaced.
 */
final class Version20260818150004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A module can have a price';
    }

    public function up(Schema $schema): void
    {
        // Backfills every existing row to `free` in one pass — see the class
        // docblock for why that is a fact rather than a guess.
        $this->addSql("ALTER TABLE module ADD pricing VARCHAR(32) DEFAULT 'free' NOT NULL");

        // And then rows written from here on have to say. The mapping declares no
        // default either, so `doctrine:schema:validate` has nothing new to report.
        $this->addSql('ALTER TABLE module ALTER COLUMN pricing DROP DEFAULT');

        // Money as §5.9 stores it: a fixed-point decimal at two places, which
        // Doctrine hands PHP as a string. Never a float, at any point on the path
        // between this column and a rendered price.
        $this->addSql('ALTER TABLE module ADD price_amount NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module DROP price_amount');
        $this->addSql('ALTER TABLE module DROP pricing');
    }
}

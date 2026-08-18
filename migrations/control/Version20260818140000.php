<?php

declare(strict_types=1);

namespace DoctrineMigrations\ControlPlane;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What each customer actually has installed, beside what we counted (XIV-95).
 *
 * `tenant.enabled_modules` says what the control plane arranged for a customer.
 * It is not what their database has: §6.1 lets the two differ, and names the ways
 * they do — a module installed straight from the console that provisioning never
 * wrote down, a module the registry lists whose tables a run that died part-way
 * never created (§4.1), a module whose definitions the customer has since moved
 * on from. Reconciling them needs each customer's own metadata, and the tenant
 * list opens no tenant connection (§8.10), so the reconciliation has to be done
 * by something that legitimately does.
 *
 * That something already exists and was already reading the answer.
 * `tenant:usage:collect` walks every customer's metadata to know which shapes to
 * count; this column is where that walk's other result now goes. Same row, same
 * `collected_at`, same three states — never collected, could not be read, read at
 * a time — because a list of installed modules with no collection time beside it
 * would be a claim about a customer's database made by a page that never opened
 * one (§8.11).
 *
 * **Nullable, and nothing is backfilled.** A row written by a run older than this
 * column has genuinely never had its modules read, and null is exactly that
 * sentence; the page draws it as *not collected yet* until the collector next
 * runs, which for a nightly cron is tonight. Filling it in from
 * `tenant.enabled_modules` would have been the tempting thing and the wrong one —
 * it would manufacture perfect agreement for every existing customer, which is
 * precisely the claim this column exists to stop being assumed.
 */
final class Version20260818140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which modules a tenant actually has installed, alongside its usage figures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_usage ADD installed_modules JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_usage DROP installed_modules');
    }
}

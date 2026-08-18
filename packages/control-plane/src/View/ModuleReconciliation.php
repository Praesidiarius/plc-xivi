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

namespace Xivi\ControlPlane\View;

/**
 * One module, as two sources describe it, and how much is in it (XIV-95).
 *
 * **The point of this class is the pair of booleans, not the count.** Xivi has
 * two answers to "which modules does this customer have" and §6.1 makes it
 * legitimate for them to differ:
 *
 *   * `enabled` — the `enabled_modules` array on the registry row. What the
 *     control plane arranged for this customer, current as of this request,
 *     because it is a column of the database this page is reading anyway.
 *   * `installed` — what that customer's own database says, read out of their
 *     metadata by `tenant:usage:collect` and as old as the collection it came
 *     from. The page prints that age beside the list; there is no way to make it
 *     fresher that does not end with a tenant list opening forty tenant
 *     connections, which is the thing §8.10 exists to prevent.
 *
 * A module the customer installed from the console and nobody recorded is
 * `installed` and not `enabled`. A module a provisioning run wrote into the
 * registry before dying is `enabled` and not `installed` (§4.1). Both are drawn,
 * both are named, and **neither is drawn as an error** — that is the decision this
 * class exists to hold rather than merely to express. A module installed by hand
 * is a legitimate thing for an operator to have done, and a page that told them
 * off for it would teach them to stop reading the column. So there is no
 * `isBroken()`, no severity and no suggestion of what to do about it: this object
 * reports two facts and their disagreement, and the operator supplies the
 * judgement. Reconciling the two would be a different feature with a much higher
 * bar than a list has to clear.
 *
 * ## Why the comparison is made here and not stored
 *
 * The tempting alternative is to have the collector work out the difference and
 * write it down, which would be one array instead of two and no work at render
 * time. It would also be a comparison between a database read last night and a
 * registry column that anybody can change this morning — so an operator who
 * enables a module at ten would still be told at eleven that the registry does
 * not know about it, and an operator who *disables* one would be told everything
 * agrees. Half of this comparison is genuinely current and half of it genuinely
 * is not, and the honest arrangement is to store only the half that was observed
 * and to say how old it is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleReconciliation
{
    private function __construct(
        /** The module key, which is all of a module's definitions that reaches this page. */
        public string $key,
        /** The customer's own metadata had it, as of the collection. */
        public bool $installed,
        /** The registry row lists it in `enabled_modules`, as of this request. */
        public bool $enabled,
        /**
         * Live records in it, or null when there is no number to give.
         *
         * Null for a module the registry lists and the customer's database has
         * not got — there is nothing to count — and also, deliberately, for the
         * case that should not arise: a module in the metadata with no count
         * beside it. **Not zero in either case.** A zero is a finding about a
         * customer, and this page's whole vocabulary depends on never spelling
         * "we did not learn this" the same way (§8.11, and [XIV-39] before it).
         */
        public ?int $records,
    ) {
    }

    /**
     * Every module either source knows about, in the order the page wants them.
     *
     * **Disagreements first, then alphabetically.** The same reasoning §8.10 gives
     * for ordering the table by `attentionRank()`: a list whose interesting entry
     * is in alphabetical position is one where the interesting entry is wherever
     * it happens to fall, and the cell shows only the first few of a long list. A
     * customer with twelve modules and one disagreement would otherwise hide the
     * one thing worth reading behind a disclosure control.
     *
     * @param list<string>       $enabled   `tenant.enabled_modules`
     * @param list<string>       $installed what the last collection read from the tenant
     * @param array<string, int> $records   live records per module, from the same collection
     *
     * @return list<self>
     */
    public static function of(array $enabled, array $installed, array $records): array
    {
        $keys = array_values(array_unique([...$installed, ...$enabled]));

        $modules = array_map(
            static fn (string $key): self => new self(
                $key,
                \in_array($key, $installed, true),
                \in_array($key, $enabled, true),
                $records[$key] ?? null,
            ),
            $keys,
        );

        // `usort` is stable in PHP 8, but nothing is being leaned on here: the
        // key is compared as the tie-break, so the order is total and a rebuild
        // of the same two lists cannot produce a different one.
        usort(
            $modules,
            static fn (self $a, self $b): int => [$a->agrees(), $a->key] <=> [$b->agrees(), $b->key],
        );

        return $modules;
    }

    /** Both sources have it, which is the ordinary case and the quiet one. */
    public function agrees(): bool
    {
        return $this->installed && $this->enabled;
    }

    /**
     * The customer has it and the registry does not list it.
     *
     * `tenant:module:install` against a tenant does not write the registry row, so
     * this is what an operator installing a module by hand leaves behind — and it
     * is the direction that is easiest to miss, because everything works.
     */
    public function installedOnly(): bool
    {
        return $this->installed && !$this->enabled;
    }

    /**
     * The registry lists it and the customer's database has not got it.
     *
     * A provisioning run that wrote the row and died before creating the tables
     * leaves exactly this (§4.1), and so does a module removed from a tenant by
     * hand. It is also what the customer's users see as a module that is simply
     * not there, which is why it is worth a line on this page.
     */
    public function enabledOnly(): bool
    {
        return $this->enabled && !$this->installed;
    }
}

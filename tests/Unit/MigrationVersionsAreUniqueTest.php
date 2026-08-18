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

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No two migrations answer to the same version, across both sets.
 *
 * ## What went wrong
 *
 * On 2026-08-18 [XIV-92] and [XIV-95] both chose `Version20260818140000` for a
 * control migration. It was caught by hand while merging, which is the only
 * place it could have been caught: each branch was green on its own, and the
 * second one to merge would have arrived as a file git could not place beside
 * the first.
 *
 * The cause is not carelessness. Migrations here are hand-written — there is no
 * `doctrine:migrations:diff` in the loop for most of them — so the version is a
 * timestamp somebody types, and people type `…140000` far more often than they
 * type `…143327`. Two authors working the same afternoon are choosing from a
 * handful of round numbers, not from 86,400.
 *
 * ## Why a test rather than a note
 *
 * `AGENTS.md` has a line about it now, and it is worth having, but a note is the
 * weakest thing available here: the collision happened between two agents that
 * had both read that file. What is wanted is the failure arriving in `bin/ci`
 * rather than in somebody's head at merge time, which is what this is.
 *
 * The same-path collision — the one that actually happened — is also a merge
 * conflict, so git does surface it eventually. This makes the surfacing happen
 * on the branch instead, before anybody has built anything on top of it, and it
 * covers the case git cannot: two files with the same version in *different*
 * directories, which merge perfectly cleanly.
 *
 * ## The decision: one numbering space, both sets
 *
 * The control-plane and tenant migrations are separate Doctrine configurations
 * against separate connections and separate databases, and Doctrine stores the
 * version fully qualified — `doctrine_migration_versions` holds
 * `DoctrineMigrations\ControlPlane\Version20260818140000`, namespace and all.
 * So the same digits in both sets is **not** a technical conflict, and nothing
 * would break. That is exactly why this had to be decided rather than left to be
 * discovered: the answer is not forced, so two people would answer differently.
 *
 * The answer here is that **a version is unique across the whole repository**,
 * and it is chosen on three grounds.
 *
 * A version is quoted by its digits, not by its namespace, in every place a
 * person actually meets one: in conversation, in a branch name, in a `psql`
 * prompt reading a half-applied `doctrine_migration_versions`. Making the digits
 * ambiguous costs the most in exactly the situation [XIV-106] is about — working
 * out which migration a stale row refers to — and saves nothing anywhere.
 *
 * It costs nothing to obey. Timestamps to the second are not scarce, and
 * `bin/new-migration` picks a free one across both sets without anybody
 * thinking about it.
 *
 * And it is the rule that needs no explaining. "Unique" is a sentence; "unique
 * within its own directory, and a duplicate across directories is fine because
 * the namespace disambiguates it" is a paragraph, and a rule nobody can state
 * from memory is one that gets applied by guess.
 *
 * ## What this deliberately does not catch
 *
 * Two branches that add a column to the same table with *different* timestamps.
 * They merge cleanly, both run, and the second one fails or duplicates work at
 * `tenant:migrate` time rather than here. There is no honest static check for
 * that — it is a question about what the SQL means, not about what the files are
 * called — and the outcome is caught downstream by
 * `tests/Functional/ControlPlane/SchemaMatchesTheMappingTest.php` and by
 * `tenant:schema:validate`, which compare the schema that resulted against the
 * mapping. Worth knowing that this file is not that check.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MigrationVersionsAreUniqueTest extends TestCase
{
    /**
     * Every migration in the repository, as version => list of files claiming it.
     *
     * Both sets are read into one map on purpose; that map *is* the decision
     * documented above. Splitting it in two would make this test pass on the
     * collision it is written to refuse.
     *
     * @return array<int|string, list<string>>
     */
    private static function versions(): array
    {
        $found = [];

        foreach (['control', 'tenant'] as $set) {
            foreach (glob(\dirname(__DIR__, 2) . '/migrations/' . $set . '/*.php') ?: [] as $file) {
                $name = basename($file);

                // `Version20260818150000.php`. Anything else in the directory is
                // not a migration as far as Doctrine's own finder is concerned,
                // so it is not one here either.
                if (preg_match('/^Version(\d+)\.php$/', $name, $matches) !== 1) {
                    continue;
                }

                $found[$matches[1]][] = $set . '/' . $name;
            }
        }

        return $found;
    }

    /**
     * That there is anything to check at all.
     *
     * The same guard `MigrationsUseIdentityColumnsTest` carries, for the same
     * reason: renaming the directories, or changing the file-name shape matched
     * above, would empty the map and leave every assertion below passing by
     * describing nothing. A green that means "not checked" is worse than a red
     * one — `deptrac` spent four months proving it (XIV-60).
     */
    public function testThereAreMigrationsToCheck(): void
    {
        self::assertGreaterThan(20, \count(self::versions()));
    }

    public function testNoVersionIsClaimedTwice(): void
    {
        $collisions = array_filter(self::versions(), static fn (array $files): bool => \count($files) > 1);

        self::assertSame(
            [],
            $collisions,
            "Two migrations answer to the same version.\n\n"
            . self::describe($collisions)
            . "\nA version is unique across `migrations/control` and `migrations/tenant` alike,\n"
            . "even though the two run against different databases and Doctrine records them\n"
            . "under different namespaces. The reasoning is in this file's docblock; the short\n"
            . "version is that a version is quoted by its digits everywhere a person meets one.\n\n"
            . "Renumber the newer of the two. `bin/new-migration <set>` picks a free version\n"
            . "for you and writes the file, which is the way not to meet this again — rename\n"
            . "the file *and* the class inside it if you are doing it by hand.\n",
        );
    }

    /**
     * A migration's class is named after its file.
     *
     * Doctrine's own finder builds the class name from the file name and throws
     * when the file does not declare it, so this is not a new rule — it is that
     * rule arriving in milliseconds instead of out of a bootstrap. It earns its
     * place next to the check above because the two failures come from the same
     * hand: renumbering a migration to resolve a collision means renaming the
     * file *and* the class, and doing one of the two is the obvious way to get
     * it half right.
     */
    public function testAMigrationClassIsNamedAfterItsFile(): void
    {
        $wrong = [];

        foreach (self::versions() as $version => $files) {
            foreach ($files as $file) {
                $source = file_get_contents(\dirname(__DIR__, 2) . '/migrations/' . $file);
                self::assertIsString($source, sprintf('cannot read %s', $file));

                if (preg_match('/\bclass\s+Version' . $version . '\b/', $source) !== 1) {
                    $wrong[] = $file;
                }
            }
        }

        self::assertSame(
            [],
            $wrong,
            sprintf(
                "A migration does not declare the class its file name promises:\n\n    %s\n\n"
                . "Doctrine finds migrations by globbing the directory and expecting\n"
                . "`Version<file name>` inside, so this is a migration that will not be found —\n"
                . "which presents as a version silently never running, or as one running twice\n"
                . "under two names. Rename the class to match the file.\n",
                implode("\n    ", $wrong),
            ),
        );
    }

    /**
     * The collisions, as something readable rather than as a var_dump.
     *
     * @param array<int|string, list<string>> $collisions
     */
    private static function describe(array $collisions): string
    {
        $lines = '';

        foreach ($collisions as $version => $files) {
            $lines .= sprintf("    %s\n        %s\n", $version, implode("\n        ", $files));
        }

        return $lines;
    }
}

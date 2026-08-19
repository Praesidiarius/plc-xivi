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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No tenant migration's `up()` takes anything away (XIV-61, docs/architecture/deployment.md §4.2).
 *
 * ## The window this is about
 *
 * A deploy moves N customer databases one at a time — `bin/deploy` walks the
 * registry — and the instance does not stop while it does. So there is a window,
 * minutes long at fifty customers, in which some customers are on the new schema
 * and some are on the old one, and **the code serving all of them is the same
 * code**. §4.2 decides that window rather than removing it: the alternative is
 * taking the whole installation down for the duration of a migration run whose
 * length is a function of how many customers there are, which is a business
 * decision made worse by every sale.
 *
 * The price of keeping the instance up is that migrations may only **add**.
 * Expand in this release, contract in a later one, once every customer is past
 * the first. That constraint is what makes the ordering in `bin/deploy` safe —
 * the schema moves ahead of the code, so old code meets a schema that has only
 * gained things — and it is what this class refuses to let anybody forget.
 *
 * ## Why a test and not a line in AGENTS.md
 *
 * AGENTS.md says it, `config/migrations/tenant.php` says it, `TenantMigrator`
 * says it and §4 has said it since the brief was written. It has been said four
 * times and checked zero times, which is the exact shape of the two failures
 * this codebase has already had: `deptrac` green for four months because its
 * layers were empty, and `SERIAL` in eleven migrations because nothing but prose
 * objected (see {@see MigrationsUseIdentityColumnsTest}, which this is modelled
 * on and which is worth reading first).
 *
 * A destructive tenant migration does not fail in CI. It fails for one customer,
 * during a deploy, in the minutes between their database moving and the next
 * one's — and it fails as an ordinary query error in a log, which is the hardest
 * kind of failure to trace back to its cause.
 *
 * ## What it can and cannot see
 *
 * It reads the SQL as written, which catches the four statements that account
 * for essentially every accidental break: dropping a table, dropping a column,
 * renaming either, and tightening a column to `NOT NULL` while code that does
 * not write it is still running. Deliberately blunt patterns rather than a
 * parser, for the reason its sibling gives: a cleverer rule is one somebody
 * edges past.
 *
 * It cannot see everything, and pretending otherwise would be worse than the
 * gap. **Narrowing a type** (`varchar(255)` to `varchar(64)`), **adding a
 * `UNIQUE` constraint** that old code can still violate, and **a data migration
 * that rewrites rows the old code will read back** are all destructive across
 * the window and all invisible here. The rule is the author's; this catches the
 * cases the rule is most often broken by accident in.
 *
 * ## `down()` is not checked, and `migrations/control` is not either
 *
 * Only `up()` is read. A `down()` is a rollback, run deliberately by somebody
 * who has decided to go backwards, and it is *supposed* to remove what `up()`
 * added — checking it would forbid every migration from being reversible.
 *
 * `migrations/control` is left alone, and that is a scope line rather than a
 * judgement: it is one database, migrated inside one transaction at container
 * start, so its window is a different and much shorter argument that belongs
 * with the deploy definition XIV-61 still has open.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantMigrationsAreAdditiveTest extends TestCase
{
    /**
     * The statements that take something away, and what each one breaks.
     *
     * Written as "what the old code does when it meets this" rather than as a
     * rule, because the failure message is read by somebody who is about to
     * argue that their case is different — and quite often it is, which is what
     * `EXEMPT` below is for.
     *
     * @var array<string, string>
     */
    private const array FORBIDDEN = [
        '/\bDROP\s+TABLE\b/i' => 'code still running will SELECT from that table until every '
            . 'container is replaced, and the containers are replaced after this runs',
        '/\bDROP\s+COLUMN\b/i' => 'code still running will SELECT and INSERT that column, and Doctrine '
            . 'names every column in its INSERTs rather than relying on defaults',
        '/\bRENAME\s+(?:TO|COLUMN)\b/i' => 'a rename is a DROP and an ADD in one statement, so it breaks '
            . 'old code exactly as a drop does — add the new name, write both, drop the old one a '
            . 'release later',
        '/\bSET\s+NOT\s+NULL\b/i' => 'code still running does not know it has to write that column, so '
            . 'its INSERTs start failing the moment this lands — backfill and add the constraint in '
            . 'a later release, once nothing writes a NULL',
    ];

    /**
     * Migrations shipped destructive on purpose, left exactly as they are.
     *
     * **Empty, and it was not always.** It carried
     * `Version20260814084512.php` — the rename of `module_definition` to
     * `shape_definition` on 2026-08-14, exempt because a migration is a record
     * of what was run and rewriting one changes only what a *fresh* database
     * gets. XIV-151 squashed the whole set to a baseline while nothing was
     * deployed (§4.2), which removed the file and with it the only thing this
     * list was for: there is no longer a run to be a record of, and the baseline
     * creates `shape_definition` under its own name in one `CREATE TABLE`.
     *
     * The list stays rather than being deleted, because the next author to need
     * it needs it on the day they are about to break the rule, and a mechanism
     * that has to be invented at that moment is one that gets skipped instead.
     *
     * **Listed by name rather than cut off at a version number**, which is the
     * one place this differs from its sibling. A version cut-off would make this
     * check depend on how migrations are numbered; a name list depends on
     * nothing and — the part that matters — a *new* migration is checked by
     * default rather than by being numbered high enough. Adding to this list is
     * meant to feel like what it is: writing down that a destructive migration
     * was shipped on purpose, in a file somebody will read.
     *
     * @var list<string>
     */
    private const array EXEMPT = [];

    /**
     * Every tenant migration the rule applies to, named by file so a failure says
     * which one without opening anything.
     *
     * @return iterable<string, array{string}>
     */
    public static function tenantMigrations(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2) . '/migrations/tenant/*.php') ?: [] as $file) {
            $name = basename($file);

            if (\in_array($name, self::EXEMPT, true)) {
                continue;
            }

            yield $name => [$file];
        }
    }

    /**
     * That there is anything to check at all.
     *
     * The same guard its sibling carries, for the same reason: renaming the
     * directory would empty the provider and leave every assertion below passing
     * by describing nothing. A green that means "not looked" is the failure this
     * whole family of tests exists because of.
     *
     * **It asked for more than one file until XIV-151 and now asks for at least
     * one**, because the squash to a baseline left exactly one — and a threshold
     * that a correct repository fails is a threshold that gets edited rather than
     * read. One is the honest floor here: `migrations/tenant` is never legitimately
     * empty, because a tenant database has to be built by something.
     */
    public function testThereAreTenantMigrationsToCheck(): void
    {
        self::assertGreaterThan(0, iterator_count(self::tenantMigrations()));
    }

    #[DataProvider('tenantMigrations')]
    public function testAMigrationOnlyAddsToATenantSchema(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source, sprintf('cannot read %s', $file));

        $up = self::upBody($source);

        // Not a formality. If the shape of a Doctrine migration ever changes —
        // or if this extraction is broken by an edit — an empty body would make
        // every assertion below trivially true, silently, for every file at
        // once. That is the one way this check can fail without anybody noticing.
        self::assertNotSame('', $up, sprintf(
            '%s has no readable up() body, so nothing below actually checked it. '
            . 'Either the migration does not follow the usual shape or upBody() needs fixing.',
            basename($file),
        ));

        foreach (self::FORBIDDEN as $pattern => $consequence) {
            self::assertDoesNotMatchRegularExpression($pattern, $up, sprintf(
                "%s takes something away in up(), and %s.\n\n"
                . "Tenant migrations are additive only (docs/architecture/deployment.md §4.2). A deploy walks the\n"
                . "customer databases one at a time with the instance still serving, so for the length\n"
                . "of that walk the same code meets both schemas — and the containers carrying the new\n"
                . "code are replaced only afterwards. Expand in this release, contract in a later one.\n\n"
                . "down() is not checked; going backwards deliberately is what a down() is for.\n"
                . 'If this really has to ship as it is, add the file name to EXEMPT and say why.',
                basename($file),
                $consequence,
            ));
        }
    }

    /**
     * The body of the migration's `up()`, with PHP comments removed.
     *
     * **Comments are stripped for the reason its sibling strips them**: these
     * files argue with themselves at length, and a migration that explains why it
     * is *not* dropping a column would otherwise fail on its own docblock. PHP's
     * own lexer does it rather than a regular expression, because comment syntax
     * inside a heredoc and heredoc syntax inside a comment are both things these
     * files contain.
     *
     * **`up()` only, by brace depth.** The alternative — splitting the file text
     * at `function down(` — happens to work today and stops working the first
     * time somebody writes a `preUp()` or puts a helper method between the two.
     * Counting braces from the `{` that opens `up()` is the same amount of code
     * and is not a coincidence.
     */
    private static function upBody(string $source): string
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }

            // The name follows the `function` keyword after whitespace and any
            // comment somebody wedged in between.
            $name = $i + 1;
            while ($name < $count) {
                $candidate = $tokens[$name];
                if (\is_array($candidate) && \in_array($candidate[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    ++$name;

                    continue;
                }

                break;
            }

            if (!\is_array($tokens[$name] ?? null) || $tokens[$name][1] !== 'up') {
                continue;
            }

            return self::bodyFrom($tokens, $name, $count);
        }

        return '';
    }

    /**
     * Everything between the first `{` at or after $from and the `}` that closes
     * it, comments dropped on the way through.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function bodyFrom(array $tokens, int $from, int $count): string
    {
        $depth = 0;
        $body = '';

        for ($i = $from; $i < $count; ++$i) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                ++$depth;
            }

            if ($depth > 0 && !(\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true))) {
                $body .= $text;
            }

            if ($text === '}') {
                --$depth;

                if ($depth === 0) {
                    return $body;
                }
            }
        }

        return '';
    }
}

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

namespace App\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * `importmap.php` still says what we chose, after whatever last rewrote it.
 *
 * ## Why this exists rather than a comment
 *
 * The file is **generated**. `importmap:require` writes it, and so does a Flex
 * recipe when a package is added — from a template, rather than by editing what
 * is there. So anything hand-written in it is collateral, by design and not by
 * accident.
 *
 * It used to carry a comment explaining that Tom Select offers four stylesheets
 * and this application takes one. Flex dropped that comment **twice in two
 * days** — once adding `packages/voucher` (XIV-103), once adding
 * `symfony/http-client` (XIV-126) — and both times it was noticed by somebody
 * reading a diff, which is luck rather than a process.
 *
 * The reasoning now lives in `docs/architecture.md` §5.4, where nothing
 * regenerates it. **This holds the fact.** It is the same split XIV-111 argues
 * for `config/bundles.php`: move the prose somewhere permanent, and let a check
 * hold the invariant, because a regenerated file cannot hold either.
 *
 * ## What it actually checks, and what it deliberately does not
 *
 * That exactly one Tom Select stylesheet is listed. The recipe offers four —
 * default, Bootstrap 4, Bootstrap 5, and a bare one — and three of them would be
 * downloaded into `assets/vendor/` and served to nobody, because
 * `assets/controllers.json` picks one.
 *
 * It does **not** check which one. That is a design choice somebody may
 * legitimately revisit — a Bootstrap 4 skin is a decision, not a mistake —
 * whereas *four* is never a decision. A test that pinned the name would fail on
 * an intentional change and teach people to edit tests, which is worse than the
 * bloat it prevents.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ImportmapHoldsWhatWeChoseTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function importmap(): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = require \dirname(__DIR__, 3) . '/importmap.php';

        return $map;
    }

    public function testThereIsAnImportmapToCheck(): void
    {
        // The guard `deptrac` spent four months earning (XIV-60): a test that
        // finds nothing passes just as loudly as one that finds everything, so
        // the first assertion is that there is something here at all.
        self::assertGreaterThan(5, \count(self::importmap()));
    }

    public function testOnlyOneTomSelectStylesheetIsPulledIn(): void
    {
        $stylesheets = array_filter(
            array_keys(self::importmap()),
            static fn (string $name): bool => str_starts_with($name, 'tom-select/dist/css/'),
        );

        self::assertCount(
            1,
            $stylesheets,
            'importmap.php lists ' . \count($stylesheets) . " Tom Select stylesheets, and this application can use one.\n\n"
            . implode("\n", array_map(static fn (string $s): string => '    ' . $s, $stylesheets)) . "\n\n"
            . "The recipe offers four and `assets/controllers.json` picks one; the rest are downloaded\n"
            . "into assets/vendor/ and served to nobody. If a package was just added, a Flex recipe has\n"
            . "regenerated this file — check `git diff importmap.php` and keep only the one in use.\n"
            . "The reasoning is in docs/architecture.md §5.4.\n",
        );
    }
}

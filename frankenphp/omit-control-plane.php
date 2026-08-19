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

/*
 * **Takes the administration surface out of an already-installed vendor tree**
 * (XIV-96, docs/architecture/deployment.md §4.4).
 *
 * Run once, inside the `frankenphp_public_builder` stage, between
 * `composer install` and `composer dump-autoload`. It touches exactly one file —
 * `vendor/composer/installed.json` — and its whole job is to leave Composer
 * able to regenerate an autoloader for a package tree the package is no longer
 * in.
 *
 * ## Why this exists rather than a Composer command
 *
 * The obvious way to build an image without a package is not to require it, and
 * that is not available here. `xivi/control-plane` is in the root
 * `composer.json`'s `require` because the internal image genuinely needs it, and
 * the two images are built from **one repository with one lock file** — which is
 * the point of the two-target approach (§4.4) and the thing that stops the two
 * builds from drifting apart. A second `composer.json` would mean a second lock
 * file, two dependency graphs to keep in step, and a customer-facing image built
 * from resolutions nobody reviewed.
 *
 * `composer remove --no-update` was the next candidate. It rewrites
 * `composer.json` inside the image and leaves the lock describing a package that
 * is no longer required, so the resulting image reports a state that is not
 * true — and the next person to run `composer install` in it gets the package
 * back. Removing the *installed* record instead says what actually happened.
 *
 * ## What Composer needs from us, and why doing nothing is not an option
 *
 * `composer dump-autoload` builds its class map by walking the autoload
 * declarations of every package in `vendor/composer/installed.json`. Delete
 * `packages/control-plane` and leave the record, and the dump fails outright
 * with `Could not scan for classes inside "…/src" which does not appear to be a
 * file nor a folder`. Leave the record *and* the files and the classes stay in
 * the image, which is the thing the whole ticket is about.
 *
 * The alternative to this file was post-processing the four generated
 * `vendor/composer/autoload_*.php`, which is strictly worse: they are Composer's
 * output format rather than its input, `--classmap-authoritative` means a
 * classmap entry pointing at a missing file is a fatal error rather than a
 * fallback, and an editing mistake surfaces as a class that cannot be loaded at
 * runtime. Editing the input and letting Composer generate the output keeps
 * exactly one thing hand-written, and it is the one Composer documents.
 *
 * ## Deliberately noisy about doing nothing
 *
 * If the package is not in `installed.json`, this exits non-zero rather than
 * shrugging. A silent no-op here is a customer-facing image that still contains
 * the administration surface and a build log that says it does not — which is
 * the exact failure [XIV-56] was: something shipping inside the production image
 * that was never meant to be there, discovered long afterwards.
 */

const PACKAGE = 'xivi/control-plane';

$installed = 'vendor/composer/installed.json';

if (!is_file($installed)) {
    fwrite(\STDERR, sprintf("%s: not found. Run this from the application root, after composer install.\n", $installed));

    exit(1);
}

$contents = file_get_contents($installed);

if ($contents === false) {
    fwrite(\STDERR, sprintf("%s: could not be read.\n", $installed));

    exit(1);
}

/** @var array{packages?: list<array{name?: string}>, dev?: bool, dev-package-names?: list<string>} $data */
$data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

if (!isset($data['packages']) || !\is_array($data['packages'])) {
    fwrite(\STDERR, sprintf("%s: has no \"packages\" key; Composer's format has changed.\n", $installed));

    exit(1);
}

$before = \count($data['packages']);

$data['packages'] = array_values(array_filter(
    $data['packages'],
    static fn (array $package): bool => ($package['name'] ?? null) !== PACKAGE,
));

$removed = $before - \count($data['packages']);

if ($removed === 0) {
    // See the note above: the build must fail here rather than produce an image
    // that quietly still has the thing this stage exists to remove.
    fwrite(\STDERR, sprintf(
        "%s is not installed, so there is nothing to remove — and this stage's only reason to exist is to remove it.\n"
        . "Refusing to build a \"customer-facing\" image that was never given the administration surface to take out.\n",
        PACKAGE,
    ));

    exit(1);
}

// `dev-package-names` lists which of the above were pulled in by require-dev.
// The package is not one of them, but the key is part of the file's contract and
// a stale name in it would confuse the next `composer install` — so it is
// filtered with the same predicate rather than left to chance.
if (isset($data['dev-package-names']) && \is_array($data['dev-package-names'])) {
    $data['dev-package-names'] = array_values(array_filter(
        $data['dev-package-names'],
        static fn (mixed $name): bool => $name !== PACKAGE,
    ));
}

// Composer writes this file with four-space indentation, unescaped slashes and a
// trailing newline. Matching that is not tidiness: `composer install` rewrites
// the file wholesale, but anything comparing it — a diff in a build log, a
// person reading it out of the image — should see one changed list rather than a
// reformatted document.
$encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

if (file_put_contents($installed, $encoded . "\n") === false) {
    fwrite(\STDERR, sprintf("%s: could not be written.\n", $installed));

    exit(1);
}

printf("Removed %s from %s; %d packages remain.\n", PACKAGE, $installed, \count($data['packages']));

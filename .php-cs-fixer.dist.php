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
 * Formatting, and the file header.
 *
 * The ruleset is Symfony's, because that is the style this codebase was already
 * written in — turning it on changed twenty files, not a hundred, and most of
 * what it changed was genuinely wrong rather than merely different.
 *
 * Two of its rules are off, and they are the two that would have accounted for
 * most of a hundred: see below. They are not corrections, they are Symfony
 * preferring the other option, and this codebase already picked one.
 *
 * There is no second formatter. ECS wraps PHP CS Fixer and PHP_CodeSniffer, and
 * running the same fixers through a second front end buys nothing here.
 *
 * What none of this can do is the other half of the convention: nothing in PHP
 * CS Fixer adds an @author tag, so a new class still needs one written by hand.
 *
 * Generated code is left out. Doctrine writes the migrations and a Symfony
 * recipe writes config/reference.php; a header there would be removed the next
 * time either is regenerated.
 */

$header = <<<'TXT'
    This file is part of the Xivi package.

    (c) Praesidiarius <praesidiarius@proton.me>

    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.
    TXT;

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/packages/core/src',
        __DIR__ . '/packages/contact/src',
        __DIR__ . '/packages/xivi-mate/src',
        __DIR__ . '/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,

        // `$tenant === null`, not `null === $tenant`. Yoda conditions guard
        // against a typo — `=` where `==` was meant — that this codebase cannot
        // make: it compares with `===` and runs at PHPStan level 8, which
        // rejects an assignment in a condition anyway. That leaves the cost,
        // which is that every comparison reads backwards.
        'yoda_style' => false,

        // `'tenant_' . $slug`, not `'tenant_'.$slug`. Pure taste, and 30 files
        // already made the other choice.
        'concat_space' => false,

        // This one collapses a `throw` onto one line however long it gets, which
        // turned seven readable multi-line messages into 200-character ones. The
        // messages here explain a refusal and are meant to be read in the source.
        'single_line_throw' => false,

        'header_comment' => [
            'header' => $header,
            'comment_type' => 'comment',
            // Before `declare(strict_types=1)`, not after it. Symfony has no
            // precedent — none of its files declare strict types — so this
            // follows Doctrine and API Platform instead.
            'location' => 'after_open',
        ],
    ])
    ->setFinder($finder);

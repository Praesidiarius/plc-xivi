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
 * One rule, on purpose.
 *
 * This is here so the file header stays true as files are added, not to impose a
 * coding style: the codebase already has one, and turning on @Symfony would
 * rewrite every file to argue about spacing instead. If a style ruleset is ever
 * wanted, that is a separate decision made deliberately.
 *
 * What it cannot do is the other half of the convention: nothing in PHP CS Fixer
 * adds an @author tag, so a new class still needs one written by hand.
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
        __DIR__ . '/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
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

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

namespace Xivi\Article;

use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * The article module: the things a customer sells (XIV-11).
 *
 * Three fields and a declaration, which is the whole module — no entity, no
 * repository, no form class, no controller, and nothing added to the engine to
 * make room for it beyond the two field types it needed. Contact showed the
 * engine could describe a module; this one shows it was not quietly built around
 * that particular module.
 *
 * Deliberately small. "A simple article with a title, a description and a price"
 * is the whole brief, and the fields a customer's catalogue actually needs —
 * a number, a supplier, a stock level — are theirs to add in the editor (§5.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArticleModule implements ModuleProvider
{
    public const string KEY = 'article';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'article',
            fields: [
                new FieldBlueprint(
                    key: 'title',
                    label: 'field.title',
                    type: 'text',
                    required: true,
                    filterable: true,
                    // What an article is called, and therefore the heading on its
                    // page and the link in every list (§5.4).
                    title: true,
                    position: 10,
                    options: ['max_length' => 180],
                ),
                new FieldBlueprint(
                    key: 'description',
                    label: 'field.description',
                    type: 'textarea',
                    filterable: true,
                    // Not a column: a paragraph in a table squeezes every other
                    // column into nothing, and the list is for finding an article
                    // rather than for reading about it.
                    listed: false,
                    position: 20,
                ),
                new FieldBlueprint(
                    key: 'price',
                    label: 'field.price',
                    type: 'currency',
                    filterable: true,
                    position: 30,
                    // A negative price is a refund, which is a different thing
                    // with a different name; the field refuses one rather than
                    // letting a typo become a credit.
                    options: ['min' => 0],
                ),
            ],
            // No presets. Three fields is already the smallest honest version of
            // this module, so a "basic" one could only leave out the price —
            // which is the thing an article is for (§6.1).
            icon: 'box-seam',
        );
    }
}

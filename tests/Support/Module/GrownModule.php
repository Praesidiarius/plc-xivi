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

namespace App\Tests\Support\Module;

use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * A module that exists only in the test environment, to have a blueprint that
 * has *grown* (XIV-70).
 *
 * Registered under `when@test` in config/services.yaml and shipped in no build,
 * exactly like {@see JobModule} and for the same kind of reason: what XIV-70
 * needs to test is a customer whose installed module is older than the module,
 * and the honest way to produce one is to install a smaller blueprint and then
 * ask the registry what the module says now.
 *
 * The test does the first half itself — {@see ModuleInstaller::install()} takes
 * a blueprint rather than a key, so a reduced copy of this one installs a
 * yesterday's shape — and this class is the "now". Everything about it is chosen
 * to put one of the upgrade's decisions under a test:
 *
 *   * `owner` is **required**, so a module that already has records cannot take
 *     it with that rule on and it has to arrive optional.
 *   * `serial` is **unique**, which a field that is empty everywhere can keep —
 *     the case that proves relaxing is decided by counting rather than by a
 *     blanket "new fields lose their rules".
 *   * `total` is **derived**, so there is something the engine owns and this
 *     code must not fill in.
 *   * `parts` is a **collection**, which is the addition that needs a table and
 *     is therefore the one a customer could never add for themselves.
 *
 * Real modules could have been used for most of this, and the ones that grew are
 * exactly the ones a test must not depend on the current shape of: Contact's
 * `payment_terms` is the example XIV-70 was written about, and a test asserting
 * that it is offered would fail the day somebody adds the next field.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GrownModule implements ModuleProvider
{
    public const string KEY = 'grown';
    public const string TABLE = 'grown';
    public const string COLLECTION = 'parts';
    public const string COLLECTION_TABLE = 'grown_part';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'Grown',
            table: self::TABLE,
            fields: [
                // The one field the test installs to begin with, so that
                // everything else in this list is something the module gained
                // afterwards.
                new FieldBlueprint(
                    key: 'name',
                    label: 'Name',
                    type: 'text',
                    required: true,
                    title: true,
                    position: 10,
                ),
                new FieldBlueprint(
                    key: 'owner',
                    label: 'Owner',
                    type: 'text',
                    required: true,
                    position: 20,
                ),
                new FieldBlueprint(
                    key: 'serial',
                    label: 'Serial',
                    type: 'text',
                    unique: true,
                    position: 30,
                ),
                new FieldBlueprint(
                    key: 'notes',
                    label: 'Notes',
                    type: 'text',
                    position: 40,
                ),
                // Nothing derives it, and nothing needs to: what is under test is
                // that a field the engine owns arrives empty rather than being
                // guessed at by whatever added it.
                new FieldBlueprint(
                    key: 'total',
                    label: 'Total',
                    type: 'currency',
                    position: 50,
                    derived: true,
                ),
            ],
            collections: [
                new CollectionBlueprint(
                    key: self::COLLECTION,
                    label: 'Parts',
                    table: self::COLLECTION_TABLE,
                    fields: [
                        new FieldBlueprint(
                            key: 'label',
                            label: 'Label',
                            type: 'text',
                            required: true,
                            position: 10,
                        ),
                        new FieldBlueprint(
                            key: 'amount',
                            label: 'Amount',
                            type: 'currency',
                            position: 20,
                        ),
                    ],
                    position: 10,
                ),
            ],
            icon: 'box-seam',
        );
    }
}

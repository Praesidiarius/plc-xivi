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

use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Lifecycle\LifecycleTransition;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * A module that exists only in the test environment, to have a lifecycle to test
 * (XIV-14).
 *
 * Registered under `when@test` in config/services.yaml and shipped in no build.
 * The alternative was giving contact or article a lifecycle, which would have
 * meant inventing product behaviour nobody asked for — and inventing it in a
 * module customers already have, where adding a field does not retro-fit into
 * existing installations anyway (§7.2).
 *
 * It doubles as the plainest possible statement of §1's claim: a module with a
 * lifecycle is still a declaration and nothing else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class JobModule implements ModuleProvider
{
    public const string KEY = 'job';

    public const string DRAFT = 'draft';
    public const string ACTIVE = 'active';
    public const string DONE = 'done';
    public const string CANCELLED = 'cancelled';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'Jobs',
            table: 'job',
            fields: [
                new FieldBlueprint(
                    key: 'title',
                    label: 'Title',
                    type: 'text',
                    required: true,
                    filterable: true,
                    title: true,
                    position: 10,
                ),
                // The state is an ordinary field, which is the whole point: it
                // filters, lists, exports and shows up in history for free, and
                // the lifecycle is a rule over it rather than a second store.
                new FieldBlueprint(
                    key: 'status',
                    label: 'Status',
                    type: 'choice',
                    required: true,
                    filterable: true,
                    position: 20,
                    options: ['choices' => [
                        self::DRAFT => 'Draft',
                        self::ACTIVE => 'Active',
                        self::DONE => 'Done',
                        self::CANCELLED => 'Cancelled',
                    ]],
                ),
            ],
            icon: 'clipboard-check',
            lifecycle: new Lifecycle(
                field: 'status',
                initial: self::DRAFT,
                transitions: [
                    new LifecycleTransition('start', [self::DRAFT], self::ACTIVE, label: 'Start'),
                    new LifecycleTransition('finish', [self::ACTIVE], self::DONE, label: 'Finish'),
                    // From either, because plans are cancelled at any point.
                    new LifecycleTransition('cancel', [self::DRAFT, self::ACTIVE], self::CANCELLED, label: 'Cancel'),
                ],
                // Where it stops: a finished job is a record of what happened.
                locked: [self::DONE, self::CANCELLED],
            ),
        );
    }
}

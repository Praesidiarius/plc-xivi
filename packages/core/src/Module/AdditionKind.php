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

namespace Xivi\Core\Module;

/**
 * The two things a blueprint can have gained since a customer installed it
 * (XIV-70, §7.2.1).
 *
 * A closed set of two, and it stays two for a reason rather than by accident:
 * these are exactly the changes that **add** and therefore cannot destroy
 * anything. A field the blueprint dropped is not here, because §5.4 already
 * decided that removal keeps the values and nothing may take a customer's field
 * away on a module author's say-so; a field whose *type* changed is not here
 * either, because that is the half of §7.2 nobody has an honest answer for. If a
 * third case ever wants to join, the question to ask it first is whether a
 * customer who takes it can be left worse off than before, and both of these
 * answer no.
 *
 * The values are stored — they are the keys of the map on `shape_definition`
 * that remembers what somebody has said no to — so renaming one is a migration
 * rather than a refactor.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum AdditionKind: string
{
    /** A field the shape does not have: new in the blueprint, or left out by a preset. */
    case Field = 'field';

    /** A whole collection, which means a table, which is why only the installer can make one. */
    case Collection = 'collection';
}

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

namespace Xivi\Core\Record;

/**
 * What happened to a record, as history records it (§5.2).
 *
 * The root record's own verb. Adding an address to a contact that already exists
 * is an *update of the contact* — the collection's rows are part of what the
 * contact is, not separate things with separate lives (§5.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum RecordAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}

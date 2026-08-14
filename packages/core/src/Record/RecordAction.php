<?php

declare(strict_types=1);

namespace Xivi\Core\Record;

/**
 * What happened to a record, as history records it (§5.2).
 *
 * The root record's own verb. Adding an address to a contact that already exists
 * is an *update of the contact* — the collection's rows are part of what the
 * contact is, not separate things with separate lives (§5.1).
 */
enum RecordAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}

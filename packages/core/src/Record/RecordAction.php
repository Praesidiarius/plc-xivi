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

    /**
     * A document was generated from one of the module's templates (XIV-4).
     *
     * The first verb here that changed nothing — see §5.2 for why it is recorded
     * anyway, and why "who read this record" still is not.
     */
    case DocumentGenerated = 'document_generated';

    /**
     * The record moved through its lifecycle (XIV-14).
     *
     * Its own verb rather than an ordinary update, because "somebody sent this
     * invoice" and "somebody corrected a typo in it" are different facts about a
     * document and a timeline that called both "updated" would bury the first.
     */
    case Transitioned = 'transitioned';

    /**
     * An email about this record went out (XIV-39).
     *
     * The second verb that changes nothing, and the stronger case of the two
     * §5.2 admits: a document can be regenerated and a mail cannot be recalled.
     */
    case EmailSent = 'email_sent';

    /**
     * An email about this record was attempted and did not go (XIV-39).
     *
     * **Its own verb rather than a flag inside the entry**, and that is the
     * whole point of recording failures at all. "Nothing in the timeline" and
     * "it went out" must not look the same, and they still would if a failure
     * were an `email_sent` row somebody has to open to discover it did not
     * happen. A timeline is read by scanning the left-hand column, so the
     * difference has to be in the column — the same argument that made
     * {@see self::Transitioned} its own verb rather than an update.
     */
    case EmailFailed = 'email_failed';
}

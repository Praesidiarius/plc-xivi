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

use Xivi\Core\Field\Enumerates;

/**
 * That this module's index reads better as one card per value of a field than as
 * a page of rows (XIV-168).
 *
 * A knowledge base is the case that asked for it. Twenty-five rows with a pager
 * under them answer "which entry is this" and answer nothing at all about what
 * is written down, which is the question somebody arriving at a knowledge base
 * has. One card per topic, holding the entries' titles, answers it in a glance:
 * the shape of what exists, before any of it is opened.
 *
 * ## It is a declaration, not a knowledge-base feature
 *
 * §5.3's index is one generic page over every module and it stays one. What
 * changes is that a module may now say **"group me"**, the way it already says
 * where its mail goes ({@see \Xivi\Core\Mail\MailRecipient}), when its records
 * fall due ({@see \Xivi\Core\Payment\PaymentTerms}) and what a document of one
 * may carry. The engine reads the declaration and the template branches on
 * whether there is one, never on which module it is looking at. A second module
 * that wants it, articles by category or contacts by kind, writes one line in
 * its blueprint and gets the whole thing.
 *
 * ## What may be grouped by, and why the answer is this narrow
 *
 * **A field whose type {@see Enumerates} its own values, and nothing else.** Two
 * properties come out of that and both are load-bearing:
 *
 *  * **the cards are known before the records are read.** The field can be asked
 *    what its values are, so the page is a fixed set of headings that the
 *    records fall into, rather than a set discovered by scanning a table. That
 *    is what keeps a `SELECT DISTINCT` off the page.
 *  * **the set is small and the customer chose it.** A choice field's options
 *    are a short arranged list (§5.20). Grouping by a text field would draw a
 *    card per distinct string, which for a title field is a card per record.
 *
 * A `reference` is the obvious next candidate and is deliberately not allowed
 * here yet: the values are records, the set is unbounded, and reading a label
 * for each one is the query-per-card this design refuses. It would need a
 * different resolver and it should be asked for as its own ticket.
 *
 * ## What the declaration deliberately does not carry
 *
 * **No cap.** How many records fit on a card is a property of the page rather
 * than of the module, so the caller passes it in and every grouped module gets
 * the same one ({@see \App\Controller\ModuleController::LINKED_ON_RECORD}).
 *
 * **No sort.** Records inside a card are ordered by the module's own title
 * fields, because a card of titles that is not in title order is a card nobody
 * can scan. The URL can still carry a sort and it is honoured; there is simply
 * no column header left to offer one.
 *
 * **No say over the empty cases.** Whether a value nobody has used draws a card,
 * and where records holding no value at all go, are answered once for every
 * grouped module in {@see \Xivi\Core\Record\RecordGrouper}. A module that could
 * answer them differently would be a module whose index is its own code again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class GroupedList
{
    /**
     * @param string $field the key of the field whose values become the cards.
     *                      A field on the module itself, and one whose type
     *                      enumerates. It is checked against the *customer's*
     *                      definitions at render time rather than against the
     *                      blueprint, so a tenant who converted the field to
     *                      something else gets the ordinary list back instead of
     *                      a page that throws.
     */
    public function __construct(
        public string $field,
    ) {
    }
}

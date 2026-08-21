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
 * A module's answer to {@see IndexBodyProvider}: which template draws its
 * records, what that template is given, and what it turned out to be looking at
 * (XIV-178).
 *
 * **A template name and an array rather than a rendered string**, which is
 * {@see \Xivi\Core\Dashboard\WidgetPanel}'s oldest decision and is the same one
 * here. A provider that returned HTML would be a service building markup, and it
 * would need the translator, the router and the escaper injected to do it, which
 * is the reasons Twig exists rebuilt once per module. Handing back a name and its
 * data keeps rendering in the templating layer.
 *
 * The template is included rather than extended, with `with_context = false`, so
 * {@see $values} really is the body's whole world. A body cannot quietly start
 * reading a variable the index happens to have in scope, which is what keeps
 * this a seam rather than a shared namespace; the globals every template has,
 * `chrome` and `app`, are unaffected. It is the same mount
 * `templates/components/DashboardPanel.html.twig` gives a widget, and for the
 * same reason.
 *
 * ## Why the records and the total come along
 *
 * They look like the page's business and they are the body's, because only the
 * body knows what it read.
 *
 * **The records**, because §5.3's priming (XIV-54) is told about a *set*: the
 * index calls {@see RecordPrimer::prime()} once with everything about to be
 * rendered, so whatever those records name is fetched in one query per target
 * module however the body arranges them. A body that kept its records to itself
 * would either lose that or make the page prime once per card. It is the flat
 * list rather than the body's own arrangement, because priming has no opinion
 * about arrangement.
 *
 * **The total**, because a body may be looking at a different set from a page of
 * rows. The count under the index says how many records match, and §5.3 spends a
 * paragraph on two counts of one set being able to disagree; a body that read
 * its records with one statement and let the page count them with another would
 * be exactly that. So the body reports the number it actually counted and the
 * page prints it.
 *
 * **And a body has no pager**, which follows from the two above rather than
 * being declared: a body is handed the whole query and reports the whole total,
 * so there is no page of many for a pager to move between. How a body bounds
 * itself is the body's own business, and not a thing the engine can help with,
 * because the engine does not know what the body is drawing. The knowledge cards
 * stop each card at a ceiling and link past it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class IndexBody
{
    /**
     * @param string               $template the Twig template that draws these
     *                                       records. A module's own, reached
     *                                       through its bundle's namespace
     *                                       (`@XiviKnowledge/…`), or one in the
     *                                       application's `templates/module/index/`
     *                                       once a second module wants the same
     *                                       one. See {@see IndexBodyProvider}
     * @param array<string, mixed> $values   the body's whole world, since the
     *                                       template is included with no context
     * @param list<Record>         $records  everything the body is about to
     *                                       draw, flat, so the page can prime
     *                                       what it names in one call (XIV-54)
     * @param int                  $total    how many records match, counted by
     *                                       whatever statement the body ran, so
     *                                       that the count under the index and
     *                                       the body cannot be two opinions
     */
    public function __construct(
        public string $template,
        public array $values,
        public array $records,
        public int $total,
    ) {
    }
}

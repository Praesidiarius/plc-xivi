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

namespace Xivi\Invoice\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Xivi\Core\Dashboard\DashboardWidget;
use Xivi\Core\Dashboard\WidgetPanel;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Direction;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Record\RecordPageUrl;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;

/**
 * The money that has gone out and not come back (XIV-66).
 *
 * **This is the widget the ticket exists to make possible**, and the reason is
 * where it lives rather than what it draws. Every dashboard widget before it was
 * in `src/Dashboard/Widget/` — the application — because `DashboardWidget` was an
 * application interface and deptrac forbids a module package from importing one.
 * So the single most useful thing this product could put on a landing page was
 * unreachable from the only package that knows what an invoice is. The seam moved
 * into `packages/core`; this is the first thing through it, and nothing in this
 * file imports `App\` anything.
 *
 * ## A list, not a number
 *
 * "12 unpaid invoices" tells somebody there is work. Twelve links *are* the work,
 * and the difference is a click and a search box. So the panel hands over records
 * with their titles, their dates and an address for each, and the reader goes
 * straight to the one they were thinking of.
 *
 * The address is the one thing this package cannot build for itself — a route
 * name belongs to the application — so it asks {@see RecordPageUrl}, which is the
 * same seam `RecordAccessProvider` and `InstanceCurrency` sit on and is why this
 * class can hand out links without learning that `module_show` exists.
 *
 * ## Unpaid is `sent`, and overdue is a read on top of it
 *
 * The lifecycle is draft → sent → paid, with cancelled off to one side, so
 * "issued and not settled" is exactly one state and needs no negation: a draft was
 * never asked for and a cancelled one never will be. Which of those are *late* is
 * §5.16's question and is not a fifth state — the calendar performs it, nothing
 * else does — so the row is flagged at render time by core's own `is_overdue()`,
 * against the record already in hand. Nothing here has to know what a payment
 * term is.
 *
 * Sorted by due date ascending, which puts the latest first and the ones nobody
 * ever agreed a deadline for last: an empty column sorts after every date in
 * Postgres, and an invoice with no due date is not overdue (§5.16), so the order
 * says something true without a second condition.
 *
 * ## Permissions, and the trap XIV-81 walked into once already
 *
 * **Every row and the total come out of one query carrying the reader's own
 * `RecordAccess`** (§8.4, XIV-52), so somebody scoped to their own records sees
 * their own and the count under them agrees — the number and the list cannot
 * disagree, because the compiler builds one predicate for both.
 *
 * **The verb is `View` and not `List`**, which is a real choice and worth the
 * sentence. This widget names individual records and links to each of them, and
 * §7.6's rule is that a link is offered only where the reader may open the target
 * — a record somebody may not view answers 404, so a link there sends them to a
 * page saying the thing does not exist. `List` would be the verb if this offered
 * the module's list page; it offers twelve record pages. Requiring `List` as well
 * would also produce the odd result of hiding a card from somebody who may open
 * every invoice on it.
 *
 * **And this widget genuinely is gated on "what may this person see"**, which is
 * the opposite call from the one the follow-up widget makes and is not a
 * contradiction. XIV-81's near-miss was gating a list of *work somebody was given*
 * on whether they could still open the module — wrong, because revoking a View
 * grant does not unassign anybody's outstanding follow-ups, and hiding the card
 * would take that work off the screen. Nothing is assigned here. An unpaid invoice
 * is a record, "which of these are unpaid" is a question about records, and
 * somebody with no grant on invoices has no version of this card that could say
 * anything. *What may this person see* and *what is this person responsible for*
 * are different questions, and this one is the first kind.
 *
 * ## Cheap to offer, expensive only to draw
 *
 * `panel()` asks two questions that cost no round trip worth counting — is the
 * module installed (the metadata repository's per-request cache, XIV-53) and does
 * this reader have any access to it (the permission resolver's, resolved once per
 * request for every check on the page) — and hands back the rows as a promise
 * rather than as rows. The two queries behind them run when, and only when, the
 * panel is actually drawn: not for a reader who has hidden the card, and not on
 * the page load at all, because the panel defers.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsTaggedItem(priority: 5)]
final readonly class UnpaidInvoicesWidget implements DashboardWidget
{
    /**
     * What a saved layout writes down.
     *
     * Namespaced by module, because a key is global across every widget on the
     * installation and `unpaid` on its own is a word several modules could want.
     */
    public const string KEY = 'invoice.unpaid';

    /**
     * How many lines fit before the card stops being a glance.
     *
     * The same judgement the follow-up card makes at ten, one lower because these
     * rows carry a date and a total each. What does not fit is counted and said
     * out loud, which is the half that stops a cap from being a lie.
     */
    private const int MOST = 8;

    public function __construct(
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private RecordAccessProvider $access,
        private RecordPageUrl $urls,
    ) {
    }

    public function panel(): ?WidgetPanel
    {
        $module = $this->metadata->find(InvoiceModule::KEY);

        // Not installed here. §6.2's rule — a widget for a module the customer
        // does not have is not offered — needs no enforcement anywhere else,
        // because this is the only place that fact is known and null is already
        // how a widget says "this does not apply to you".
        if ($module === null) {
            return null;
        }

        $access = $this->access->accessFor(InvoiceModule::KEY, ModuleAction::View);

        // No grant at all. Not an empty card: there is no reading of this widget
        // that could ever say anything to this person, which is exactly the
        // condition null is for.
        if ($access->matchesNothing()) {
            return null;
        }

        return new WidgetPanel(
            key: self::KEY,
            template: '@XiviInvoice/dashboard/unpaid.html.twig',
            nameKey: 'dashboard.unpaid',
            // A promise. See the class docblock: `panel()` is asked of every
            // widget on every render and this one is drawn only when the reader
            // kept it, so the two queries below belong on the far side of that
            // decision.
            data: fn (): array => $this->outstanding($module, $access),
            // The module's own catalogue, which is the other half of what makes
            // this shippable from a package: naming the card would otherwise mean
            // adding a key to the application's translation file.
            domain: InvoiceModule::KEY,
            // Two queries against a table that grows for ever, on the first page
            // every user loads after signing in. This is the case deferring was
            // built for.
            defer: true,
        );
    }

    /**
     * The rows, and how many did not fit.
     *
     * Two queries and a fixed number of them: one page and one count, both from
     * the same compiled predicate, so the total under the list is arithmetic on
     * the same rows rather than a second opinion about them.
     *
     * @return array<string, mixed>
     */
    private function outstanding(ModuleDefinition $module, RecordAccess $access): array
    {
        $query = new RecordQuery(
            filters: [new Filter(InvoiceModule::STATUS, Operator::Equals, InvoiceModule::SENT)],
            sorts: [new Sort(InvoiceModule::DUE_DATE, Direction::Ascending)],
            perPage: self::MOST,
        );

        $rows = $this->records->findBy($module, $query, $access);
        $total = $this->records->countBy($module, $query, $access);

        // Built here rather than in the template, which is the whole point of
        // RecordPageUrl: the template is this package's too and must not know a
        // route name either.
        $urls = [];

        foreach ($rows as $record) {
            $urls[(int) $record->id] = $this->urls->forRecord(InvoiceModule::KEY, (int) $record->id);
        }

        return [
            'module' => $module,
            'rows' => $rows,
            'urls' => $urls,
            'more' => max(0, $total - \count($rows)),
            // Which fields the row draws, resolved once rather than per row. They
            // are the customer's own definitions rather than the blueprint's
            // (§6.1), so any of them may be null — a customer who deleted the due
            // date field is a customer whose card simply does not show one.
            'dueDate' => $module->getField(InvoiceModule::DUE_DATE),
            'total' => $module->getField(InvoiceModule::GROSS_TOTAL),
        ];
    }
}

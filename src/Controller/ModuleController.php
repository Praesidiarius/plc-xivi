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

namespace App\Controller;

use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\ModuleRecord;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Settings\DisplayTimezone;
use App\View\LinkedRecords;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\Decoration;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Core\Document\DocumentGenerator;
use Xivi\Core\Document\DocumentTemplateRepository;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\History\HistorySection;
use Xivi\Core\Lifecycle\Lifecycles;
use Xivi\Core\Lifecycle\TransitionRefused;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Mail\Recipient;
use Xivi\Core\Mail\RecipientProblem;
use Xivi\Core\Mail\RecipientResolver;
use Xivi\Core\Metadata\AvailableVariants;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\RecordQueryFactory;
use Xivi\Core\Query\UnsupportedQuery;
use Xivi\Core\Record\IndexBody;
use Xivi\Core\Record\IndexBodyProvider;
use Xivi\Core\Record\InheritedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordPrimer;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Seed\Seeder;

/**
 * Browsing and editing records, for every module.
 *
 * One controller, no matter how many modules exist — a module that needed its
 * own would mean the engine had failed to describe it. Which module is being
 * served comes from the URL, and its shape from that customer's definitions.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}', requirements: ['module' => '[a-z][a-z0-9_]*'])]
final class ModuleController extends AbstractController
{
    /** How much of the timeline the record page itself shows (XIV-3). */
    private const int HISTORY_ON_RECORD = 5;

    private const int HISTORY_PER_PAGE = 25;

    /** What the send modal starts with when there is no send to offer (XIV-39). */
    private const array NO_EMAIL_DRAFT = [
        'template' => 0,
        'subject' => '',
        'recipient' => '',
        // Nothing attached, which is the ordinary send (XIV-40).
        'document' => 0,
        'format' => 'pdf',
        // And nothing ticked on it (XIV-164): the form ticks what it offers,
        // and a draft for a send that is not being offered offers nothing.
        'decorations' => [],
    ];

    /**
     * How many linked records one card shows (XIV-52).
     *
     * A card is a glance at what points here, the way the history card is a
     * glance at what happened — the module's own list is where somebody goes to
     * read all 207 of them, and the card now says so and links there. Public
     * because the test for that case has to build more records than this, and a
     * cap the test hard-codes is a cap that stops being tested the day it moves.
     *
     * XIV-168 had the grouped index reuse it, by passing it down into the
     * engine's grouper, and XIV-178 stopped: a module owns its own body now
     * ({@see IndexBodyProvider}) and a module may not name a class in `App\`
     * (§3). So {@see \Xivi\Knowledge\Index\TopicCards::PER_CARD} is a second ten,
     * arrived at by the same argument rather than by reference: a card of the
     * entries under a topic is the same object as a card of the orders under a
     * contact, and neither can make an argument against the other's number.
     */
    public const int LINKED_ON_RECORD = 10;

    /**
     * The URL parameter that asks this index for its rows rather than for
     * whatever the module draws (XIV-168, moved here by XIV-178).
     *
     * **"Give me the plain list" is a property of the page**, which is why it
     * lives on the page. §5.3's index draws the body a module offers
     * ({@see IndexBodyProvider}) and the table when there is none; this is how
     * somebody says *the table, please*, and it is the only thing in the
     * application that overrides a module's own layout.
     *
     * The one thing that writes it is a link inside a body that has more than it
     * is showing, built through {@see \Xivi\Core\Record\RecordListUrl}. A body
     * with a ceiling has to point past that ceiling, and narrowing this page to
     * the same value gives back the same body with the same ceiling on it, so
     * the link would point at itself. Without the parameter the way out would
     * have to be a route of its own or a toggle above the body, and §5.3 has one
     * index route and a filter bar that is already how a list is narrowed.
     *
     * It carried its own constants on a `Xivi\Core\Record\RecordGroup` value
     * object for a day, which put a word about *cards* in the engine; XIV-177
     * took the card back and the parameter stayed, because it was never about
     * cards.
     *
     * **Whether it should exist at all is still open**: XIV-168 flagged it as
     * its one assumption and XIV-178 deliberately did not settle it.
     */
    public const string VIEW = 'view';

    /** What {@see self::VIEW} has to say for the rows to come back. */
    public const string AS_LIST = 'list';

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        // Every write goes through the writer, never the repository: it owns the
        // transaction and the history entry (§5.2).
        private readonly RecordWriter $writer,
        // What a submitted form means: which rows were really typed in, whether
        // it is valid, and what gets written (XIV-30).
        private readonly Seeder $seeder,
        private readonly HistoryRepository $history,
        private readonly RecordQueryFactory $queries,
        private readonly RecordExporter $exporter,
        private readonly UserRepository $users,
        private readonly PermissionResolver $permissions,
        private readonly TranslatorInterface $translator,
        private readonly DocumentTemplateRepository $templates,
        // What this record could send and where it would go (XIV-39). Two
        // collaborators rather than one because they answer two questions that
        // do not imply each other: which templates apply to this kind of record,
        // and whether there is anybody to send them to.
        private readonly EmailTemplateRepository $emailTemplates,
        private readonly RecipientResolver $recipients,
        private readonly Lifecycles $lifecycles,
        private readonly InheritedValues $inherited,
        private readonly AvailableVariants $variants,
        // Which zone the timeline's day boundaries are drawn in (XIV-83). The
        // rendered timestamps get it from Twig, which the listener has already
        // set; the *grouping* happens here in PHP, so this is where it has to be
        // asked for a second time.
        private readonly DisplayTimezone $timezones,
        // Told about a set of records once, before any of it is rendered
        // (XIV-54). Every page here that shows more than one record calls it;
        // none of them has to, which is the property that keeps it from being a
        // rule somebody has to remember.
        private readonly RecordPrimer $primer,
        // Which comparison finds the records pointing at this one (XIV-113).
        // Only the reverse-link card needs it, and it needs the *type* rather
        // than the definition: a field naming several records is found by
        // containment where one naming a single record is found by equality.
        private readonly FieldTypeRegistry $fieldTypes,
        // What a PDF of this record could carry beyond the template (XIV-164),
        // for the ticks in both modals. Through the generator, so this page
        // learns what is on offer without learning what any of it is.
        private readonly DocumentGenerator $generator,
        /**
         * Whoever might draw their own records in place of the table (XIV-178).
         *
         * The application collects and core declares the seam, exactly as with
         * {@see \App\Dashboard\Dashboard} and `DashboardWidget`: this controller
         * serves every module there is, so it asks rather than decides, and what
         * it learns is a template name and some data. It deliberately learns
         * nothing about what any of them draws.
         *
         * @var iterable<IndexBodyProvider>
         */
        #[AutowireIterator(IndexBodyProvider::TAG)]
        private readonly iterable $indexBodies,
    ) {
    }

    #[Route('', name: 'module_index', methods: ['GET'])]
    #[IsGranted(ModuleAction::List->value, subject: 'module')]
    public function index(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $query = $this->queries->fromQueryParameters($request->query->all());

        // The attribute above said they may list; this says which ones. Both
        // seams, because they answer different questions and neither implies
        // the other (§7.5).
        $access = $this->accessFor($definition, ModuleAction::List);

        // **Whether somebody asked for the rows rather than for whatever this
        // module draws.** The only link that writes this parameter is the one
        // inside a body that has more than it is showing, and what it wants back
        // is the page this has always been: rows, sorted, paged. See self::VIEW.
        $asRows = $request->query->get(self::VIEW) === self::AS_LIST;

        try {
            [$records, $total, $body] = $this->listing($definition, $query, $access, $asRows);
        } catch (UnsupportedQuery $e) {
            // The query is in the URL, so it can be hand-edited into something
            // the engine will not answer. That is a message and an unfiltered
            // list, not a 500 — and the exception already explains itself.
            $this->addFlash('warning', $e->translatable()->trans($this->translator));

            $query = new RecordQuery();
            [$records, $total, $body] = $this->listing($definition, $query, $access, $asRows);
        }

        // A page of records is in hand and every one of them is about to be
        // rendered, so whatever they name is read now rather than column by
        // column (XIV-54). Worth little here — a page is 25 records and the
        // saving is 24 round trips on a page already measured in the low tens of
        // milliseconds — and done because at this point it is one line. The case
        // it was built for is the record page below, where the rows have no
        // ceiling at all.
        //
        // Handed a body's whole set at once rather than card by card, which is
        // the point of priming being told about a *set*: one call, so a page of
        // twelve topics costs what a page of one does. That is also why
        // IndexBody carries its records: a body that kept them to itself would
        // either lose this or make the page ask once per card.
        $this->primer->prime($definition, $records);

        return $this->render('module/index.html.twig', [
            'module' => $definition,
            'columns' => self::listedFields($definition),
            // Every field the name is built from: sorting by only the first
            // would order a list of people by a field only companies have.
            'nameSort' => implode(',', array_map(
                static fn (FieldDefinition $f): string => $f->getKey(),
                $definition->getTitleFields(),
            )),
            'records' => $records,
            // What the module handed back to draw its own records with, or null
            // for the table (XIV-178). The template branches on this and on
            // nothing else, and what it knows about the far side of the branch
            // is a template name and an array.
            'body' => $body,
            // Nobody's name is drawn by a module's own body, because the owner
            // column belongs to the table, so the query that resolves them is
            // not made for one.
            'owners' => $body === null ? $this->ownerNames($records) : [],
            'total' => $total,
            'query' => $query,
            'filterable' => $this->queries->filterablePaths($definition),
            'operators' => Operator::cases(),
            'pages' => (int) ceil($total / max(1, $query->perPage)),
        ]);
    }

    /**
     * What the index is looking at: the records, how many there are, and the
     * body drawing them when a module offered one (XIV-178).
     *
     * One method because the index calls it twice, once for the query in the URL
     * and once for the empty query it falls back to when that one cannot be
     * answered. Two copies of this drifted apart the moment one of them learned
     * about a second way of reading.
     *
     * **A body reports its own total and the page does not count again.** §5.3
     * spends a paragraph on two counts of one set being able to disagree, and a
     * body may well be looking at a different set from a page of rows: the
     * knowledge cards read every topic's first few and every topic's real total
     * in one statement, and a `countBy()` beside it could only ever agree or
     * disagree. A page of rows still counts separately, because its records are
     * one page of many and cannot be added up.
     *
     * **The first provider that answers wins, and the rest are not asked.** A
     * body is a fact about the module rather than about the reader, so at most
     * one of them is ever about this page; iterating rather than looking up by
     * key is what keeps this controller from holding a map of modules to
     * layouts, which is the thing §1 says the engine may not learn.
     *
     * @return array{list<Record>, int, ?IndexBody}
     */
    private function listing(
        ModuleDefinition $definition,
        RecordQuery $query,
        RecordAccess $access,
        bool $asRows,
    ): array {
        $body = $asRows ? null : $this->bodyFor($definition, $query, $access);

        if ($body === null) {
            return [
                $this->records->findBy($definition, $query, $access),
                $this->records->countBy($definition, $query, $access),
                null,
            ];
        }

        return [$body->records, $body->total, $body];
    }

    /**
     * The first module body that applies to this page, or null for the table.
     *
     * Null for every module but one, today and probably for a while, which is
     * the ordinary answer rather than a failure: §5.3's table is what an index
     * is unless somebody said otherwise about their own records.
     */
    private function bodyFor(ModuleDefinition $definition, RecordQuery $query, RecordAccess $access): ?IndexBody
    {
        foreach ($this->indexBodies as $provider) {
            $body = $provider->bodyFor($definition, $query, $access);

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * A new record, of a kind chosen before the form is drawn (§5.5).
     *
     * A company has no first name, so the form cannot be built until it knows
     * which variant it is for — and switching the fields as somebody picks would
     * need JavaScript, which the forms here do not depend on. Asking first is
     * both simpler and how a CRM usually puts it: "new person" or "new company".
     */
    /**
     * The records you are looking at, as a spreadsheet.
     *
     * The same query the list ran, so a filtered export contains what the filter
     * showed — anything else would be a file that quietly disagrees with the page
     * it came from. Written to a temporary file and streamed, because a
     * spreadsheet is a zip and the writer needs to seek.
     */
    #[Route('/export', name: 'module_export', methods: ['GET'])]
    #[IsGranted(ModuleAction::Export->value, subject: 'module')]
    public function export(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $query = $this->queries->fromQueryParameters($request->query->all());

        $path = (string) tempnam(sys_get_temp_dir(), 'xivi-export-');
        // Scoped by the export permission rather than by the list one: they are
        // separate grants, and somebody may reasonably be allowed to read every
        // record on screen and take only their own away.
        $this->exporter->toFile($definition, $query, $this->accessFor($definition, ModuleAction::Export), $path);

        $response = $this->file($path, sprintf('%s-%s.xlsx', $module, date('Y-m-d')))
            ->deleteFileAfterSend(true);

        // Looked up rather than sniffed. Left to itself the response would ask
        // libmagic what the bytes are — an answer that depends on which libmagic
        // the image happens to ship, for a file we wrote ourselves and named
        // .xlsx. The extension is the fact; symfony/mime holds the table.
        $response->headers->set('Content-Type', MimeTypes::getDefault()->getMimeTypes('xlsx')[0]);

        return $response;
    }

    #[Route('/new', name: 'module_new', methods: ['GET', 'POST'])]
    #[IsGranted(ModuleAction::Add->value, subject: 'module')]
    public function new(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        // Only the kinds this customer can actually fill in (XIV-23).
        $variants = $this->variants->of($definition);
        $record = new Record();

        if ($variants !== []) {
            $chosen = (string) $request->query->get('variant', '');

            if (!isset($variants[$chosen])) {
                return $this->render('module/choose_variant.html.twig', [
                    'module' => $definition,
                    'variants' => $variants,
                ]);
            }

            // Seeded rather than left to the form, so the record knows what it is
            // before anything asks which fields it has.
            $record->set((string) $definition->getVariantField(), $chosen);
        }

        // Made from a record of another module (XIV-19): an invoice from an
        // order. What comes back is a *form*, filled in — somebody still reads
        // it and presses save, because an invoice that appeared the moment a
        // button was pressed is a document nobody checked.
        $seeded = $this->seeded($definition, $request);
        $record->data = [...$record->data, ...$seeded['fields']];

        return $this->edit($definition, $record, $request, $seeded['rows'], $seeded['fields']);
    }

    /**
     * What a new record starts with when it is being made from another one.
     *
     * Empty for an ordinary "new" — and empty too when the source cannot be
     * read: seeding from a record somebody may not open would copy its lines
     * onto a page they are allowed to see (§8.4).
     *
     * @return array{fields: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    private function seeded(ModuleDefinition $definition, Request $request): array
    {
        $from = $request->query->getInt('from');
        $seed = $this->seeder->seedOf($definition->getKey());

        if ($from <= 0 || $seed === null) {
            return ['fields' => [], 'rows' => []];
        }

        $source = $this->definition($seed->from);

        return $this->seeder->fill($definition, $seed, $source, $this->recordFor($source, $from, ModuleAction::View));
    }

    /**
     * One record, read-only: its own values, the rows of its collections, and
     * what has happened to it.
     *
     * Everything here is rendered from the customer's definitions, so a field
     * they added shows up with nothing touched — the same claim the form makes,
     * on a page that only reads.
     */
    #[Route('/{id}', name: 'module_show', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    #[IsGranted(ModuleAction::View->value, subject: 'module')]
    public function show(string $module, int $id): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id, ModuleAction::View);

        $children = [];
        foreach ($definition->getCollections() as $collection) {
            $children[$collection->getKey()] = $this->records->findChildren($collection, $id);

            // **This is what XIV-54 exists for.** `findChildren()` has no LIMIT
            // — an invoice with 500 lines renders 500 rows — and each row names
            // an article through a reference. Rendered one at a time that is a
            // lookup per row, and the drift check below is a second one; primed
            // here it is one query per target module, and the page costs the
            // same number of queries whether the invoice has five lines or five
            // hundred. The rows are already in hand, which is the only reason
            // this can be done at all: nothing during rendering knows every id.
            $this->primer->prime($collection, $children[$collection->getKey()]);
        }

        // And the record's own references — one record, so this saves nothing
        // measurable and is done because leaving it out would mean the page
        // primed some of what it draws and not the rest, which is the kind of
        // half-rule that gets copied.
        $this->primer->prime($definition, [$record]);

        $lifecycle = $this->lifecycles->for($definition->getKey());

        // The templates this record could be written onto (XIV-4). Asked for only
        // when they may generate one, so the query is not run to fill a card
        // nobody is shown — and read once rather than twice, because the send
        // screen offers the same list again as attachments (XIV-40).
        $documents = $this->isGranted(ModuleAction::Document->value, $module)
            ? $this->templates->forRecord($definition->getKey(), $definition->variantOf($record->data))
            : [];

        // What a PDF of this record could carry, as ticks (XIV-164). Asked only
        // when there is a document to put one on, so a record page with no
        // templates does not read the company profile to decide the contents of
        // a modal nobody is shown, which is the care every other line here
        // takes.
        $decorations = $documents === []
            ? []
            : $this->generator->decorations($definition, $record, DocumentFormat::Pdf);

        return $this->render('module/show.html.twig', [
            'module' => $definition,
            'record' => $record,
            // The record's own fields, in the runs the customer put them in
            // (XIV-119). **The very method the record form calls**, which is the
            // point rather than a tidy-up: two templates reading the same
            // definitions is exactly where grouping quietly diverges, and a form
            // in four sections beside a record page in one flat list would be
            // worse than not grouping at all. With no sections it yields one run
            // holding every field in its own order, which is what this page has
            // always drawn.
            'groups' => $definition->getFieldGroupsFor($definition->variantOf($record->data)),
            'children' => $children,
            // Which of a row's inherited values no longer match what they came
            // from (XIV-18) — a negotiated price and a stale copy look the same
            // until something says which is which.
            'drifted' => $this->driftedRows($definition, $children),
            // And which of the *record's* own inherited values have (XIV-18,
            // [XIV-133]). One shape up from the line above and the same
            // sentence: an article that is a variant of another article takes
            // that article's unit and VAT rate, and a copy nobody can tell has
            // gone stale is worse than no copy. Labels rather than fields, which
            // is what the row half hands the template and what the template
            // compares against.
            'driftedFields' => array_map(
                static fn (FieldDefinition $field): string => $field->getLabel(),
                $this->inherited->driftedIn($definition, $record->data),
            ),
            'linked' => $this->linkedTo($definition, $record),
            // What this record can be turned into, and how much of it is left to
            // turn (XIV-19). Only modules this person may add to: offering a
            // button that leads to a 403 is worse than offering nothing.
            'seeds' => $this->seedsOn($definition, $record),
            'outstanding' => $this->outstandingOn($definition, $record),
            'owner' => $record->ownerId === null ? null : ($this->ownerNames([$record])[$record->ownerId] ?? null),
            // The latest few and how many there are in total (XIV-3). A record
            // page renders the same small number whether its timeline is six
            // entries or six hundred; the rest is a page of its own.
            'history' => $this->history->findFor($definition, $id, self::HISTORY_ON_RECORD),
            'historyTotal' => $this->history->countFor($definition, $id),
            'historyShown' => self::HISTORY_ON_RECORD,
            'documents' => $documents,
            'formats' => DocumentFormat::cases(),
            'decorations' => $decorations,
            // What could be sent from this record, and where it would go
            // (XIV-39). One value rather than three, because the page's three
            // states — no button, a reason instead of one, the button — are one
            // decision made from all of it.
            'emails' => $this->emailsOn($definition, $record, $documents, $decorations),
            // Null for a module that simply is (XIV-14); the page then draws no
            // status at all rather than an empty one.
            'lifecycle' => $lifecycle,
            // **Every move this record's state allows, refused ones included**
            // (XIV-110). Not the enabled ones: a guard's refusal is a sentence
            // the module wrote for whoever is looking at this page, and dropping
            // the button without it would leave that sentence reachable only by
            // retyping the URL it is the answer to. The page draws a button or
            // the reason there is none, which is the shape the send card above
            // already has for a recipient it cannot resolve (XIV-39).
            //
            // Asked only when they may move the record at all, so the guards —
            // which may read the record's rows — are not evaluated to decide the
            // contents of a card nobody is shown.
            'transitions' => $lifecycle === null || !$this->isGranted(ModuleAction::Transition->value, $module)
                ? []
                : $lifecycle->offeredFor($record),
        ]);
    }

    /**
     * The whole timeline, a page at a time (XIV-3).
     *
     * Its own page because a record's own content should not get shorter as its
     * history gets longer, and because five hundred entries want the width — the
     * card beside the record shows the latest few and links here.
     *
     * Granted by `view`, the same as the record: history is a way of reading the
     * record, and a separate permission would be a second answer to a question
     * that already has one.
     */
    #[Route('/{id}/history', name: 'module_history', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    #[IsGranted(ModuleAction::View->value, subject: 'module')]
    public function history(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        // Through the same check as the record page: a timeline of a record
        // somebody may not open is the record, told slowly.
        $record = $this->recordFor($definition, $id, ModuleAction::View);

        $total = $this->history->countFor($definition, $id);
        $pages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        // Clamped rather than trusted: the page number is in the URL, and asking
        // for page 900 of 21 should show the last page, not an empty one.
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        $entries = $this->history->findFor(
            $definition,
            $id,
            self::HISTORY_PER_PAGE,
            ($page - 1) * self::HISTORY_PER_PAGE,
        );

        return $this->render('module/history.html.twig', [
            'module' => $definition,
            'record' => $record,
            // Grouped by the reader's own days, not by UTC's (XIV-83). An entry
            // made at half past midnight in Zurich is under "today" for the
            // person who made it, which is the only reading of "today" anybody
            // has.
            'sections' => HistorySection::of($entries, $this->displayTimezone()),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * What this record can send, and to whom (XIV-39).
     *
     * Nothing is asked for at all without the grant, so neither the templates
     * nor the linked contact are read to fill a control nobody is shown — the
     * same care the documents line above takes.
     *
     * The recipient is resolved here rather than inside the modal because it
     * decides whether there *is* a modal: a record whose address cannot be
     * worked out offers no send and says why instead, which is a decision the
     * page has to have made before it draws the button.
     *
     * The documents it could carry are the caller's list again under a *second*
     * grant (XIV-40): attaching means generating, so the picker is drawn only
     * for somebody who holds `document` on this record as well as `send_email`.
     * Record-scoped rather than module-scoped, because `document` is scopable
     * and "only my own customers" is a grant somebody can hold — the send
     * screen refuses on the same terms, so what is offered here and what is
     * accepted there cannot disagree. The module-level check the caller already
     * made is the weaker of the two, so its list is a superset and nothing has
     * to be read a second time.
     *
     * The ticks come along on the same terms (XIV-164), and for the same reason
     * they are drawn on the download: a choice that existed on one and not the
     * other would be incoherent, and the mailed copy is the one a customer pays
     * from. They are the caller's list again as well, since what a PDF of this
     * record could carry does not depend on which button produced it.
     *
     * @param list<\Xivi\Core\Entity\DocumentTemplate> $documents
     * @param list<Decoration>                         $decorations
     *
     * @return array{templates: list<\Xivi\Core\Entity\EmailTemplate>, recipient: Recipient, draft: array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>}, attachments: list<\Xivi\Core\Entity\DocumentTemplate>, decorations: list<Decoration>}
     */
    private function emailsOn(ModuleDefinition $definition, Record $record, array $documents, array $decorations): array
    {
        if (!$this->isGranted(ModuleAction::SendEmail->value, $definition->getKey())) {
            return [
                'templates' => [],
                'recipient' => Recipient::missing(RecipientProblem::NotDeclared),
                'draft' => self::NO_EMAIL_DRAFT,
                'attachments' => [],
                'decorations' => [],
            ];
        }

        $recipient = $this->recipients->for($definition, $record);
        $offered = $recipient->isOffered();
        $attachments = $offered && $this->isGranted(ModuleAction::Document->value, new ModuleRecord($definition, $record))
            ? $documents
            : [];

        return [
            'templates' => $offered
                ? $this->emailTemplates->forRecord($definition->getKey(), $definition->variantOf($record->data))
                : [],
            'recipient' => $recipient,
            'draft' => [
                ...self::NO_EMAIL_DRAFT,
                'recipient' => $recipient->address ?? '',
                // Every offer ticked (XIV-164), the same blank draft the send
                // chooser's own page starts from: the modal and the page are
                // one form, so they have to start in one state.
                'decorations' => $attachments === []
                    ? []
                    : array_map(static fn (Decoration $decoration): string => $decoration->key, $decorations),
            ],
            'attachments' => $attachments,
            // No picker, no ticks: a decoration is something added to an
            // attached document, so offering one where nothing can be attached
            // would be a control with no object (XIV-164).
            'decorations' => $attachments === [] ? [] : $decorations,
        ];
    }

    /**
     * The modules a record of this one can be made into (XIV-19).
     *
     * @return list<array{module: ModuleDefinition, seed: \Xivi\Core\Seed\Seed}>
     */
    private function seedsOn(ModuleDefinition $definition, Record $record): array
    {
        if ($record->isNew()) {
            return [];
        }

        return array_values(array_filter(
            $this->seeder->offeredOn($definition),
            fn (array $offer): bool => $this->isGranted(ModuleAction::Add->value, $offer['module']->getKey()),
        ));
    }

    /**
     * How much of each of this record's rows nothing has taken yet, by
     * collection and row id (XIV-19).
     *
     * Read from whatever has already been made from it, so an order that has
     * been half invoiced says so on the row rather than in a total nobody can
     * check against a line.
     *
     * Keyed by collection, and carrying the field the figure belongs beside —
     * "2 left" means nothing except next to the quantity it is left of.
     *
     * @return array<string, array{field: string, rows: array<int, string>}>
     */
    private function outstandingOn(ModuleDefinition $definition, Record $record): array
    {
        $left = [];

        foreach ($this->seedsOn($definition, $record) as $offer) {
            $rows = $offer['seed']->rows;

            if ($rows === null || $rows->outstanding === null) {
                continue;
            }

            $found = $this->seeder->outstanding($offer['module'], $offer['seed'], $definition, (int) $record->id);
            $left[$rows->from] ??= ['field' => $rows->outstanding, 'rows' => []];

            foreach ($found as $id => $amount) {
                $left[$rows->from]['rows'][$id] = (string) $amount;
            }
        }

        return $left;
    }

    /**
     * Per collection and row id, the labels of the fields that have drifted from
     * the record they were copied out of.
     *
     * @param array<string, list<Record>> $children
     *
     * @return array<string, array<int, list<string>>>
     */
    private function driftedRows(ModuleDefinition $definition, array $children): array
    {
        $drifted = [];

        foreach ($definition->getCollections() as $collection) {
            foreach ($children[$collection->getKey()] ?? [] as $row) {
                $fields = $this->inherited->driftedIn($collection, $row->data);

                if ($fields !== []) {
                    $drifted[$collection->getKey()][(int) $row->id] = array_map(
                        static fn (FieldDefinition $field): string => $field->getLabel(),
                        $fields,
                    );
                }
            }
        }

        return $drifted;
    }

    /**
     * Records elsewhere that point at this one (§7.6).
     *
     * The reverse of a reference, and read rather than stored: a person carries
     * its company, so a company's list of people is a query over that field.
     * Storing both sides would be two records of one fact, which is two things
     * to keep in step and one of them eventually wrong.
     *
     * Only self-references for now — a link from another module would mean
     * looking through every installed module for fields pointing here, which is
     * §7.6's remaining half.
     *
     * @return list<LinkedRecords>
     */
    private function linkedTo(ModuleDefinition $module, Record $record): array
    {
        $linked = [];

        // Every installed module, not only this one (XIV-13). A company's people
        // are contacts pointing at a contact; a contact's orders are a different
        // module pointing at this one, and the page cannot know in advance which
        // modules those are — the customer decides that by installing them.
        foreach ($this->metadata->all() as $other) {
            foreach ($other->getFields() as $field) {
                $type = $this->fieldTypes->get($field->getType());

                // **Every type that points at a module, not the one that used to
                // be the only one** (XIV-113). A field naming several records is
                // as much a link as a field naming one, and a card that counted
                // only `reference` would report "no contacts" for a company four
                // people are tagged with. That is a wrong answer that looks
                // exactly like a right one.
                if (!$type instanceof PointsAtAModule || ReferenceFieldType::targetModule($field) !== $module->getKey()) {
                    continue;
                }

                // Scoped like the other module's own list is, and by *its*
                // permission rather than this one's: these are that module's
                // records, and being allowed to open a contact is not being
                // allowed to read the orders that name it.
                $access = $this->accessFor($other, ModuleAction::View);

                if ($access->matchesNothing()) {
                    continue;
                }

                // The comparison is the type's to name: equality where one id is
                // stored, containment where several are (XIV-113). Asked rather
                // than assumed, because a caller choosing it would be a switch on
                // field type whose failure mode is a count of zero.
                $query = new RecordQuery(
                    [new Filter($field->getKey(), $type->findsTargetBy(), (int) $record->id)],
                    [],
                    1,
                    self::LINKED_ON_RECORD,
                );

                $found = $this->records->findBy($other, $query, $access);

                if ($found !== []) {
                    // Counted separately, the way the list does it (XIV-52). The
                    // card is capped, so the length of what came back is how much
                    // fits rather than how much there is — and a badge reading 10
                    // over a customer with 207 orders is not a rounding error, it
                    // is a different customer.
                    //
                    // The same query and the same access predicate: a count taken
                    // any other way would answer for records the reader cannot
                    // open (§8.4).
                    $total = $this->records->countBy($other, $query, $access);

                    // Keyed by module and field, because two modules may both
                    // call their link "Contact" and one would otherwise replace
                    // the other silently.
                    $linked[] = new LinkedRecords($other, $field, $found, $total, (int) $record->id);
                }
            }
        }

        return $linked;
    }

    /**
     * The columns after the name: the fields marked for the list (§5.4), minus
     * the ones that make up the name itself.
     *
     * The name is always the first column, so a list of mixed variants reads —
     * people and companies are named differently, and neither has the other's
     * fields (§5.5). It also means a table can never end up with no columns.
     *
     * @return list<FieldDefinition>
     */
    private static function listedFields(ModuleDefinition $module): array
    {
        $titles = $module->getTitleFields();
        $listed = [];

        foreach ($module->getFields() as $field) {
            // Title fields are not columns: the first column *is* the record's
            // name, which with variants is the only thing every row has (§5.5) —
            // a company has no first name to put under "First name".
            if ($field->isListed() && !\in_array($field, $titles, true)) {
                $listed[] = $field;
            }
        }

        return $listed;
    }

    #[Route('/{id}/edit', name: 'module_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
    #[IsGranted(ModuleAction::Edit->value, subject: 'module')]
    public function editRecord(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id, ModuleAction::Edit);
        $lifecycle = $this->lifecycles->for($definition->getKey());

        // A state can end editing (XIV-14): a sent invoice is a document, not a
        // draft. The page hides the button; this is what makes it true, because
        // a hidden button is a courtesy and a URL is not.
        if ($lifecycle !== null && $lifecycle->isLocked($record)) {
            $this->addFlash('warning', $this->translator->trans('module.locked', [
                '%state%' => $lifecycle->stateOf($record),
            ]));

            return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
        }

        return $this->edit($definition, $record, $request);
    }

    /**
     * Moving a record along its lifecycle (XIV-14).
     *
     * Its own route and its own permission: sending an invoice is a different
     * authority from correcting a typo in one, even though both write the same
     * table. The transition is applied in memory and then saved through the
     * writer like any other change, so it lands in one transaction with one
     * history entry — and the entry says the record *moved* rather than that a
     * field happened to differ (§5.2).
     */
    #[Route('/{id}/transition/{transition}', name: 'module_transition', requirements: ['id' => Requirement::POSITIVE_INT, 'transition' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    #[IsGranted(ModuleAction::Transition->value, subject: 'module')]
    public function transition(string $module, int $id, string $transition, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id, ModuleAction::Transition);

        if ($this->isCsrfTokenValid('transition-record-' . $id, (string) $request->request->get('_token'))) {
            $lifecycle = $this->lifecycles->for($definition->getKey());

            if ($lifecycle === null) {
                throw $this->createNotFoundException();
            }

            try {
                $lifecycle->apply($record, $transition);
                $this->writer->save($definition, $record, as: RecordAction::Transitioned);

                $this->addFlash('success', $this->translator->trans('flash.transitioned', [
                    '%state%' => $lifecycle->stateOf($record),
                ]));
            } catch (TransitionRefused $e) {
                // A stale page, usually: somebody pressed a button that was legal
                // when it was drawn. The message says what is possible now.
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
    }

    #[Route('/{id}/delete', name: 'module_delete', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    #[IsGranted(ModuleAction::Delete->value, subject: 'module')]
    public function delete(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id, ModuleAction::Delete);

        if ($this->isCsrfTokenValid('delete-record-' . $id, (string) $request->request->get('_token'))) {
            $this->writer->delete($definition, $record);
            $this->addFlash('success', $this->translator->trans('flash.deleted'));
        }

        return $this->redirectToRoute('module_index', ['module' => $module]);
    }

    /**
     * The page a record is edited on.
     *
     * **It renders, and nothing else** (XIV-33). Everything that used to happen
     * here on a POST — building the form, adding and removing rows, validating,
     * merging, saving, redirecting — belongs to the Live Component now, so this
     * route exists to put that component on a page with the props it needs.
     *
     * There is no POST to this route any more. A second way to save would be a
     * second place for the rules to live, and the one nobody exercises is the
     * one that rots.
     *
     * @param array<string, list<array<string, mixed>>> $seeded rows a new record starts with (XIV-19)
     * @param array<string, mixed>                      $values its own values, likewise
     */
    private function edit(
        ModuleDefinition $definition,
        Record $record,
        Request $request,
        array $seeded = [],
        array $values = [],
    ): Response {
        return $this->render('module/form.html.twig', [
            'module' => $definition,
            'record' => $record,
            'seeded' => $seeded,
            'seededFields' => $values,
        ]);
    }

    /**
     * Owner names for a page of records.
     *
     * The engine stores an owner id and deliberately knows nothing about users —
     * there is not even a foreign key, because core has no idea what a user is.
     * Resolving those ids to people is the application's job, and doing it here
     * in one query keeps that boundary intact.
     *
     * @param list<Record> $records
     *
     * @return array<int, string>
     */
    private function ownerNames(array $records): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (Record $record): ?int => $record->ownerId, $records),
        )));

        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            $names[(int) $user->getId()] = $user->getName();
        }

        return $names;
    }

    /**
     * One record, if this person is allowed it — and the right kind of no if not.
     *
     * **404 when they may not view it, 403 when they may.** Somebody scoped to
     * their own records who guesses at ids must not be able to learn which ones
     * exist, so a colleague's record answers exactly as an id that was never
     * there. Once they can see it, hiding the refusal would be worse than
     * useless: "you may look but not change this" is a real and comprehensible
     * answer, and pretending the record vanished would only send them to look
     * for it again.
     */
    private function recordFor(ModuleDefinition $definition, int $id, ModuleAction $action): Record
    {
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();
        $subject = new ModuleRecord($definition, $record);

        if ($this->isGranted($action->value, $subject)) {
            return $record;
        }

        throw $this->isGranted(ModuleAction::View->value, $subject)
            ? $this->createAccessDeniedException()
            : $this->createNotFoundException();
    }

    /**
     * Which records this person may be shown, for one action, as something the
     * query layer can compile (§7.5).
     *
     * The route attribute has already refused anybody with no grant at all, so
     * in practice this narrows rather than empties — but it is written to fail
     * closed anyway, because a second line that trusts the first is not one.
     */
    private function accessFor(ModuleDefinition $definition, ModuleAction $action): RecordAccess
    {
        return RecordAccess::fromPermissions(
            $this->permissions->forUser($this->getUser()),
            $definition->getKey(),
            $action,
            $this->currentUserId(),
        );
    }

    /**
     * The zone whoever is reading this page reads moments in (XIV-83).
     *
     * Resolved rather than read back off Twig, even though the listener has just
     * put the same answer there: asking the resolver is one call and reading it
     * out of a template engine's extension is a trick that would tie a grouping
     * decision to the fact that this page happens to be rendered by Twig.
     */
    private function displayTimezone(): \DateTimeZone
    {
        $user = $this->getUser();

        return $this->timezones->of($user instanceof User ? $user : null);
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            // Not installed for *this* customer is a 404, not an error: another
            // customer may well have it.
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}

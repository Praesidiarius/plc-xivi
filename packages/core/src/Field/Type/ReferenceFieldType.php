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

namespace Xivi\Core\Field\Type;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\LinksToRecord;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\PrimesFromRecords;
use Xivi\Core\Field\RecordLink;
use Xivi\Core\Form\RecordReferenceType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordTitle;
use Xivi\Core\Record\ReferenceTargets;

/**
 * A link to another record, stored as its id (§7.6).
 *
 * §7.6 asked whether a link should be a field type. This is the answer: yes,
 * because then the widget, the display and the filter behaviour all come from
 * the type, exactly like every other kind of value, and nothing above has to
 * learn what a link is.
 *
 * The id is a plain integer in the payload, so a reference is a real value
 * pointing at a real primary key — not a type/id pair. That is only possible
 * because a contact is one module whose records may be people or companies
 * (§5.5); two modules would have made this polymorphic, which is the shape that
 * cannot carry a key and the reason the old history table rotted.
 *
 * `options` say where it points:
 *
 *     ['module' => 'contact', 'variant' => 'company']
 *
 * The variant is optional and narrows the candidates, so a person's employer
 * offers companies rather than every contact in the database.
 *
 * A third option says whether somebody types to find the record rather than
 * scrolling for it (XIV-36):
 *
 *     ['module' => 'contact', 'variant' => 'company', 'autocomplete' => 'never']
 *
 * **This is the type that needed it.** A `choice` holds a dozen options in the
 * page already; a reference points at records and capped its dropdown at two
 * hundred, which is the picker that is actually broken at scale and the only one
 * worth a server round trip. It is still an option and not a type of its own —
 * see {@see Autocomplete} — and everything below this line is untouched by it:
 * the storage, the (deliberately absent) constraints, the operators, the SQL and
 * the display are what a reference *means*, and none of them can tell which
 * widget was used to pick the id.
 *
 * **The target is the customer's to set** (XIV-144), which is
 * {@see PointsAtAModule}. Before that it was a `module` key that only a module's
 * own blueprint could write, so the editor's add-field select offered this type
 * and a customer choosing it got a field pointing nowhere: an empty picker, and
 * `#41` where a record's name belongs. Moving it afterwards is the one answer
 * here that is refused rather than drawn — an id means nothing outside the
 * module it came from.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ReferenceFieldType implements Autocompletes, LinksToRecord, PointsAtAModule, PrimesFromRecords, ResetInterface
{
    public const string MODULE = 'module';
    public const string VARIANT = 'variant';

    /**
     * How many existing records a generated link picks between. Enough for the
     * links to look spread out, few enough to be one query.
     */
    private const int CANDIDATES = 200;

    /**
     * Titles already built this request.
     *
     * A list of fifty records each showing a link would otherwise build fifty
     * names out of the same handful of records. This is not a cache in any
     * durable sense — it lives and dies with the request, so it cannot serve one
     * tenant's data to another (§7.4), and {@see self::reset()} says so to the
     * framework rather than trusting the process to end.
     *
     * The *records* the names are read off are memoised one layer down, in
     * {@see ReferenceTargets}, because the name is not the only question asked
     * about them (XIV-54).
     *
     * @var array<string, string>
     */
    private array $titles = [];

    /**
     * Ids a generated link may point at, per field. Same lifetime as the titles
     * above and for the same reason (§7.4): it dies with the request.
     *
     * @var array<string, list<int>>
     */
    private array $candidates = [];

    /**
     * The record repository still arrives as a closure, and that is not
     * fussiness.
     *
     * Reading records goes through RecordRepository, which needs the field type
     * registry to hydrate values — which builds this type. A real cycle, and the
     * container recurses until it gives up. Deferring one edge of it until the
     * moment a record is actually wanted breaks the loop without pretending the
     * dependency is not there.
     *
     * It is only the *demo generator's* candidates that still come through here
     * (XIV-54): every read that names a record now goes through
     * {@see ReferenceTargets}, which carries the same deferred closure for the
     * same reason and adds the memo three callers share. Picking candidates is
     * not that — it is a filtered page of a module rather than a lookup by id,
     * it happens once per field rather than once per value, and nothing renders
     * it.
     *
     * @param \Closure(): RecordRepository $records
     */
    public function __construct(
        private readonly MetadataRepository $metadata,
        #[AutowireServiceClosure(RecordRepository::class)]
        private readonly \Closure $records,
        private readonly RecordAccessProvider $access,
        private readonly ReferenceTargets $targets,
    ) {
    }

    public function key(): string
    {
        return 'reference';
    }

    public function label(): string
    {
        return 'Link to a record';
    }

    public function constraints(FieldDefinition $field): array
    {
        // Deliberately none beyond the type: whether the id exists is a question
        // about another table, and answering it here would validate on every
        // save what a foreign key should be answering once (§7.6, still open).
        return [];
    }

    /**
     * The id of a record that actually exists, or nothing.
     *
     * A generated link has to point somewhere real or the demo data is a page
     * full of broken references — which is exactly the thing this type exists to
     * render nicely, so a demo that only exercised the broken case would be
     * testing the wrong half.
     *
     * The candidates are read once and reused for the rest of the run. Picking
     * randomly from the whole table per record would be a query each time, and
     * the point of the generator is to produce a million rows.
     */
    public function sample(FieldDefinition $field, int $sequence): ?int
    {
        // Half of optional links left empty: a person may have no employer, and
        // a column that is always filled never shows what an empty one looks like.
        if (!$field->isRequired() && mt_rand(1, 2) === 1) {
            return null;
        }

        $candidates = $this->candidates[$field->getKey()] ??= $this->findCandidates($field);

        return $candidates === [] ? null : $candidates[mt_rand(0, \count($candidates) - 1)];
    }

    /**
     * Ids this field could point at: records of its target module, narrowed to
     * its target variant when it has one.
     *
     * @return list<int>
     */
    private function findCandidates(FieldDefinition $field): array
    {
        try {
            $module = $this->metadata->get(self::targetModule($field));
        } catch (ModuleNotInstalled) {
            return [];
        }

        $filters = [];
        $variant = self::targetVariant($field);
        $variantField = $module->getVariantField();

        if ($variant !== null && $variantField !== null) {
            $filters[] = new Filter($variantField, Operator::Equals, $variant);
        }

        // Unrestricted, and deliberately not scoped like the picker is (XIV-13):
        // this feeds the demo generator, which runs from a console command with
        // nobody signed in. Scoping it would mean generated links that always
        // point nowhere.
        $records = ($this->records)()->findBy(
            $module,
            new RecordQuery($filters, [], 1, self::CANDIDATES),
            RecordAccess::unrestricted(),
        );

        return array_map(static fn (Record $record): int => (int) $record->id, $records);
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?int
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        return \is_numeric($value) ? (int) $value : null;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?int
    {
        return \is_numeric($value) ? (int) $value : null;
    }

    public function formType(): string
    {
        return RecordReferenceType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            'target_module' => self::targetModule($field),
            'target_variant' => self::targetVariant($field),
            'required' => $field->isRequired(),
            // Read from the definition here and resolved against the candidate
            // count by the form type (XIV-36), because "how many are there" is a
            // question about the database and a field type is handed a
            // definition rather than a connection — the same division that put
            // the picker in a form type in the first place.
            'autocomplete_mode' => Autocomplete::of($field),
        ];
    }

    /**
     * The record's own name, from its module's title fields (§5.4) — which is
     * what those exist for, and why a company with no first name still reads as
     * something.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        $id = $this->fromStorage($value, $field);

        if ($id === null) {
            return '';
        }

        $module = self::targetModule($field);
        $key = $module . '#' . $id;

        return $this->titles[$key] ??= $this->titleOf($module, $id);
    }

    public function operators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /**
     * Compared as the stored id. Filtering by the *name* of the linked record
     * would be a join, and §7.3 does not reach across a reference yet.
     */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /**
     * The one question a reference is not a reference without, and its one
     * answer (XIV-144).
     *
     * The variant is deliberately not here: a reference that says nothing about
     * it offers every record of its target module, which is a working field
     * rather than a broken one.
     *
     * The nesting is [XIV-127]'s and costs this type nothing — one question,
     * still exactly one way of answering it. Where a `choice` field acquired a
     * second answer to *its* question, a reference has none in prospect: an id
     * is only meaningful in the module it came from, so "which module" cannot be
     * answered by anything but naming one.
     *
     * @return list<non-empty-list<string>>
     */
    public function needs(): array
    {
        return [[self::MODULE]];
    }

    public static function targetModule(FieldDefinition $field): string
    {
        $module = $field->getOption(self::MODULE);

        return \is_string($module) ? $module : '';
    }

    public static function targetVariant(FieldDefinition $field): ?string
    {
        $variant = $field->getOption(self::VARIANT);

        return \is_string($variant) && $variant !== '' ? $variant : null;
    }

    private function titleOf(string $moduleKey, int $id): string
    {
        try {
            $module = $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            // A link whose target module this customer does not have. §7.6 lists
            // that as open; until it is answered, saying so beats a stack trace.
            return sprintf('#%d', $id);
        }

        $record = $this->targetOf($moduleKey, $id);

        if ($record === null) {
            // Soft-deleted or gone. The link is stale rather than broken, and a
            // page that renders is more useful than one that does not.
            return sprintf('#%d', $id);
        }

        // Built where every other caller builds it (XIV-36). Three places used
        // to assemble a record's name out of its title fields and were about to
        // become four; the rules about what a name may contain are subtle enough
        // — no references, scalars only, never blank — that copies of them drift
        // rather than staying identical. See RecordTitle.
        return RecordTitle::of($module, $record);
    }

    /**
     * The record a value names, looked up once per request.
     *
     * Read **unscoped**, and that is a decision rather than an oversight (§8.4).
     * The name of a linked record is shown to anybody who may see the record
     * pointing at it: an order whose customer read `#14` would be an order
     * nobody can use, and whoever may open the order can already see what it is
     * for. What the reader's own permissions decide is whether they are offered
     * a *link* — see {@see self::linkOf()}.
     *
     * One line, because the memo behind it belongs to more than this type
     * (XIV-54) — and unchanged in what it answers: a primed target and one
     * looked up here are the same record read under the same rule, which is what
     * lets priming be an optimisation rather than a second access policy.
     */
    private function targetOf(string $moduleKey, int $id): ?Record
    {
        return $this->targets->of($moduleKey, $id);
    }

    /**
     * Read every record this set of rows will be named after, in one query per
     * target module (XIV-54).
     *
     * **Where this earns its keep is a collection, not a list.** A record page
     * draws every row a collection has and `findChildren()` has no LIMIT, so an
     * invoice with 500 lines naming 500 articles made 500 lookups on the record
     * page and 500 more on the document path, where the rows are drawn again
     * into a .docx. A list is capped at a page of 25 and was never the problem —
     * it is primed too, because by the time this exists doing so is one call.
     *
     * Ids are collected per *target module* rather than per field, which is the
     * whole reason a type is handed all of its fields at once: an order line
     * pointing at an article and a contact is two queries, and two fields both
     * pointing at contacts is one. Duplicates collapse on the way in, because a
     * collection where every line sells the same article should ask about it
     * once.
     */
    public function primeFrom(array $fields, array $records): void
    {
        /** @var array<string, array<int, int>> $ids */
        $ids = [];

        foreach ($fields as $field) {
            $module = self::targetModule($field);

            if ($module === '') {
                // A reference with no target: a field half-configured in the
                // editor. It renders as nothing today and primes nothing here.
                continue;
            }

            foreach ($records as $record) {
                $id = $this->fromStorage($record->get($field->getKey()), $field);

                if ($id !== null) {
                    $ids[$module][$id] = $id;
                }
            }
        }

        foreach ($ids as $module => $found) {
            $this->targets->prime($module, array_values($found));
        }
    }

    /**
     * The names go with the request that asked for them (§7.4).
     *
     * Symfony's services resetter calls this on `kernel.terminate` — the
     * `kernel.reset` tag comes from autoconfiguration, so there is nothing to
     * register. Under a classic request the process was going to end anyway;
     * under a worker, or a test that keeps one kernel across a dozen requests,
     * this is the difference between a memo and a leak that shows one customer's
     * record names on another's page.
     *
     * The candidate ids go too. They are a development-only convenience — a
     * generated link has to point somewhere real — and a run that outlives a
     * request has no business remembering which ids were plausible in a database
     * it may no longer be connected to.
     */
    public function reset(): void
    {
        $this->titles = [];
        $this->candidates = [];
    }

    /**
     * Where this value points, when the reader may go there (XIV-42).
     *
     * Null for everything that should be text rather than an anchor: nothing
     * filled in, a module this customer does not have, a record that is gone
     * (§7.6), and a record this reader may not open.
     *
     * **That last one keeps the name and drops the link.** Hiding the name would
     * make the record holding it unreadable; offering the link would be offering
     * a door that answers 404, since a record somebody may not view is one this
     * application says does not exist rather than one it refuses (§8.4). Showing
     * what it is called and not pretending it can be opened is the honest half
     * of both.
     *
     * The permission is answered from the record already loaded for the name, so
     * this costs no query a page was not making anyway.
     */
    public function linkOf(mixed $value, FieldDefinition $field): ?RecordLink
    {
        $id = $this->fromStorage($value, $field);

        if ($id === null) {
            return null;
        }

        $moduleKey = self::targetModule($field);
        $record = $this->targetOf($moduleKey, $id);

        if ($record === null) {
            return null;
        }

        $access = $this->access->accessFor($moduleKey, ModuleAction::View);

        if ($access->matchesNothing()) {
            return null;
        }

        // Scoped to their own: a link is offered only to the records that scope
        // actually reaches, which is the same answer the list would give.
        if ($access->isRestricted() && $record->ownerId !== $access->ownerId()) {
            return null;
        }

        return new RecordLink($moduleKey, $id);
    }

    /**
     * It shows a record's name (§5.4), and a name can be a company with three
     * words in it — so the same width as the text it is standing in for.
     */
    public function defaultWidth(): int
    {
        return 6;
    }
}

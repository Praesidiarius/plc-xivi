## 5. Data model: metadata-driven, not EAV

**Storage shape per entity**: fixed system columns (`id`, `created_at`,
`updated_at`, `owner`, soft-delete), a JSONB `data` column for the custom long
tail, and **column promotion** (hot/unique/heavily-filtered fields become real
columns per tenant, with backfill; still unbuilt).

**The metadata layer is the actual product.** Per-tenant definitions drive the
form, the validation, the storage and the query layer from one source of truth.

**Field types are a closed registry** of tagged services. A type owns its
storage mapping, form type, constraints, normalizer and filter/sort behaviour.
Adding one is a deliberate code change, never customer config. **A widget is an
option, not a type** (XIV-36): the test for the next candidate is whether
turning it on changes what is stored, validates, filters or exports; if not, it
is an option. Which types offer an option is the type's declaration (§5.4).

**A type may need an answer only the application has** (XIV-11): core declares
an interface (`InstanceCurrency`) and the application answers it. The seam
family grew with the engine: `InstanceCurrency`, `DocumentContext`,
`PdfConverter`, `RecordAccessProvider`, `DefaultPaymentTerms`, `DefaultVatMode`,
`InstanceRegion`, `ReaderTimezone`.

**Money is a decimal string, never a float** (a lost hundredth of a cent turns
up on an invoice), and **the currency is not stored beside the amount**: one per
installation, or a column of prices stops adding up.

**Relations stay relational**: real link tables, real foreign keys, described in
metadata and stored relationally.

**Records are not Doctrine entities.** Their shape is per tenant at runtime;
they go through DBAL in one repository, the only place that knows where a field
physically lives (the seam column promotion lands in). Users and the metadata
definitions stay entities.

**A module's table is created per customer by the installer**, not per deploy;
metadata tables themselves are ordinary migrations.

**Definitions are read fully loaded**: an object that outlives the tenant
context it was loaded in lazily loads on whatever connection is current, which
is §7.4's bug in entity form.

### 5.1 Shapes: modules and collections

A **shape** is a set of fields describing one table's rows. A **module** is
browsable (URL, navigation, owner on its rows); a **collection** is reachable
only through its parent (parent id instead of owner, edited inside the parent's
form, soft-deleted with it). Everything else is shared: same definitions, field
registry, repository, validator, form builder.

- **A collection's rows may come in kinds** (§5.5's variant mechanism): adding a
  row is choosing its kind, a button per kind; a kind is fixed once the row
  exists and travels hidden; a collection without kinds keeps one blank row; a
  blank row carries its kind and stays blank. Row fields are rebuilt at
  PRE_SUBMIT from what was sent, or values typed into kind-specific fields would
  be dropped on the way in.
- **Rows keep the customer's order** (`position`, numbered in tens, renumbered
  each save, typed rather than moved by buttons). Moving a row is not a change
  to it: no history entry, because nothing about the row changed.
- **A field may be inherited from the record it points at** (XIV-18): copied
  when the line is written, never read through afterwards; the record page shows
  a drift marker when the source has moved on.
- **Numbers come in three kinds** (XIV-22): `integer` counts, `decimal`
  measures, `currency` is money; the last two share storage and differ in what
  they print. Decimal places are the field's setting, clamped, not refused.
- **Width is a proportion in twelfths, never a class name**; ordering plus width
  *is* the layout, and the grid wrapping past twelve is why no layout editor
  exists.
- **A collection is deliberately not a link between modules**: both sides of a
  link exist independently; conflating them is how orphaned addresses happen.

**A collection's supported size is 400 rows**, refused at write time, with
`memory_limit` at 256M so 400 renders (XIV-68, measured: the edit form builds a
Symfony form per row and the cost is the forms, not the queries; the reference
priming and autocomplete work removed the query and byte costs but not the
per-row form weight). The read view keeps no bound: alarming at 10,000, fine at
400. **The row count is checked before the form is built** (XIV-90): a cap
enforced by first building the thing it forbids peaked at 273 MB against a 256M
allowance; the early refusal costs 1.9 MB. The post path costs ~0.62 MB/row,
roughly double the render, and a save at exactly 400 sits at ~98% of the
allowance: accepted deliberately (a real order is well under a hundred lines,
and the failure is a refusal sentence, not the half-rendered exhaustion the cap
removed). If it ever bites, move the limit, not the cap: 400 is a promise to
customers, `memory_limit` is an ini line. Still open: the 401st "add row" click
is not refused.

### 5.2 History is per module, and per action

- **One history table per module** (`contact_history`), never one polymorphic
  table: v1's shared 60M-row table could not carry a foreign key, which is what
  rotted it. A collection's events go in its parent module's table (nobody wants
  an address's timeline).
- **Fixed shape, not metadata-driven**; created by the installer beside the
  module's table.
- **One entry per action, not per row touched**; an import touching 500 records
  writes 500 entries (the grouping key is the record).
- **`RecordWriter` is the only supported way to write a record**; the
  repository's mutating methods are internal to it, or the first direct caller
  writes no history, and a history with holes is worse than none because it is
  trusted.
- Merge rules: twice-changed fields record first-from to last-to; a value ending
  where it started is not a change; an empty diff writes nothing (`created` and
  `deleted` always write); adding an address is an update of the contact;
  deletes cascade silently.
- **Values only, no reads.** One deliberate exception: generating a document,
  which is rare, deliberate and attributable; the next candidate must argue the
  same three properties (§5.14's send does, harder).
- **Reading is paged**, grouped by period, diffs behind `<details>`, ordered by
  `occurred_at` with id breaking ties (a backfilling import writes old
  timestamps).
- **Because it stores values, it is also a time series** (XIV-121): the chain of
  any field's values is unbroken from creation, which is what the price chart
  reads (oldest first). Retention therefore has two consumers now: pruning the
  far past is pruning the half of a trend that carries the shape, and it must be
  decided in front of that. Also open: range partitioning, and a way for a field
  type to say "do not record this value" before the first sensitive type ships.

### 5.3 Asking questions: the query layer

A `RecordQuery` (conditions, ordering, one page) compiled against the customer's
own definitions.

- **Nothing from a user is concatenated**; a filter the engine cannot answer
  raises rather than silently doing nothing.
- **A condition on a collection is a semi-join** (`EXISTS`, never `JOIN`);
  **sorting by a collection is refused**.
- **The field type owns its comparisons**; the compiler has no switch on type,
  which is what lets column promotion change the accessor untouched.
- **Every ordering ends on the record id** (a LIMIT without a total order shows
  one record twice and another never).
- Not built: `OR` trees, keyset paging. Never built: expression filters
  (XIV-88): they evaluate over loaded records, the wrong side of the LIMIT and
  the counted total. One closed disjunction exists (XIV-36's `Search`): one
  string over a fixed set of the shape's own fields, composing with nothing.
- **Once a set of records is in hand, what it names is primed** (XIV-54): one
  `WHERE id IN (…)` per target module fills a shared memo (`ReferenceTargets`)
  that the reference display, the link and the drift check all read. Record
  pages went from four queries per collection row to a flat count;
  `ReferencePrimingTest` asserts `assertSame` between two sizes so growth fails
  rather than slows.

### 5.4 The metadata editor

**Definitions are read once per tenant** (XIV-53), in a cache emptied whenever
the tenant context moves, in the same breath as the identity map and the
connection; writers empty it too. There is deliberately no tenant *key*: keying
it would make keeping entries across a switch look safe, and it is the hazard.

Admin-only; edits any shape, collections included. **What it refuses is the
feature:**

- **A field's type cannot be changed** (no `setType()` exists); §7.2 carries the
  paper decision and XIV-146 the build.
- **A key cannot be changed** (the key is where the value lives); the label is
  free.
- **A rule existing records would fail is refused with the count**, and for
  `unique` the shared values are named (a count alone gives nobody a search
  term). Ticking `unique` builds the index in the same transaction (§7.2);
  relaxing is always allowed.
- **A module's own fields cannot be removed**; only customer-added ones.
- **What the form does not mention, it does not touch** (XIV-26): options are
  the declarative half of the engine, and the form draws only what it has
  controls for.

**A type says which of its options are the customer's to set**: one declared
list of option-to-capability-interface (`autocomplete`, `sequence`, `country`,
`choices`/`list`, `module`+`variant`, `exclusive_within`), resolved once against
the registry; a new option is an interface, a line in that list and a control.
`NeedsAnAnswer` marks options a field is not finished without; `needs()` is a
list of *questions*, each with the options that answer it (a choice field's
values come from its own options *or* a shared list, §5.26), and every way of
answering must be drawable. `EditorConfiguresEveryTypeTest` walks the
container's registry, asserts the comparison, and plants a violation, because an
invariant nobody has watched fail is one nobody knows is connected.

**Options on a module's own `choice` field: add and rename, never remove.** A
variant field's options are the variants, a status field's are the lifecycle's
states, and losing one breaks the module from a table cell. The refusal covers
customer-added options too, because nothing records which options the installer
wrote (per-option provenance stays open, and a shared list cannot hand it over:
nothing seeds a list, §5.26). A module's own field also cannot be pointed at a
shared list, or a table cell elsewhere could do what this paragraph refuses.

**Option values are derived from the label once** (`AsciiSlugger` pinned to
`de`, XIV-100's argument) and never touched again; renaming changes the page and
moves no record; a typo is permanent in a key nobody sees.

**Removing an option records hold is refused with the values named and
counted** (the record would fail its own validation on the next unrelated
save). **Retirement** (valid for holders, out of the picker) is the genuinely
better answer and deliberately unbuilt: it is a third state every reader of both
mechanisms must understand, and it has to arrive for field options and shared
lists at once or a customer meets one picker that can and one that cannot.

**A reference's target is refused once anything points through it** (repointing
leaves valid integers naming the wrong records, the quietest failure here);
repointable while empty; must name an installed module, checked on the write
path; moving a target clears the `variant`. A module's own reference is refused
outright.

**Sections** (XIV-119): a heading and a number, emphatically not a collection: a
field in a section is the same field in the same JSON under the same key, and
the form tree stays flat (grouping through `inherit_data` would reach the
submitted array's shape and violation mapping); the template draws the runs.
Membership is on the field (`section_key`, null for none: two nullable columns,
no backfill); the section lives on the module row (`sections` map), because it
must exist while empty. Ordering is a number the customer sets (inferring from
the first field would vanish an empty heading between two clicks). Ungrouped
fields draw first, under no heading, so existing shapes render exactly as
before; a stale key reads as ungrouped. The record page groups from the same
method (`ModuleDefinition::getFieldGroupsFor()`), and the test asserts the two
pages against each other. Section names are the customer's words; removal
removes the heading and nothing else, confirmed with the field count. Not
built: collapsing, blueprint-declared sections, tabs/wizards/conditional
visibility (the last is a rule about a record, XIV-88 territory).

**Numbering is a page of its own** (§5.10), not a cell: it writes numbers into
existing records while every table control is instantaneous and reversible.
**A field can say it names the record** (the title flag; the guess from
required-field order survives only as fallback). **A field can be on the list,
or not** (a UI hint; module fields default on, added ones off; nothing ticked
falls back to the first field). **Removing a field takes the definition and
leaves the values** (reversible by construction; the confirmation states the
count; purge is a separate explicit operation that does not exist yet, so
"remove" means "hide" and the UI says the word).

### 5.5 Variants: one shape, more than one kind of record

**One module, not two**, and the deciding argument is the reference: two
modules make "select a contact" a polymorphic column, the shape that cannot
carry a foreign key (§5.2 refused it once already). **A shape names one choice
field as the decider, and the variants are that field's options**, so there is
no second list to disagree; a field names the variants it belongs to, empty
meaning all. The form asks for one variant's fields and the validator checks
that variant's rules; **storage is untouched** (another variant's value stays in
the payload and travels, because it is somebody's data). Adding a record asks
which kind first. The list names records rather than showing fields, since the
name is the one thing every row has.

### 5.6 Getting data out, and later back in

- **One sheet per shape**, child sheets carrying `parent_id`; **headers are
  field keys** (the one thing that cannot change), import accepts key or label;
  **values are in storage form** (importable beats pretty).
- **An export carries the query the list was showing**, children of exactly
  those records included.
- **Import validates every row through the existing validator and applies in one
  transaction or not at all**, writing through `RecordWriter` (history for
  free). **A check is the import, rolled back**: same statements, same
  connection, one commits (DBAL savepoints make it nest under the suite's
  transaction); it is the only way to catch what only a write can, like two file
  rows claiming one unique value.
- **`id` decides create or update**: numeric updates, unknown numeric is refused
  (a typo would duplicate the record it meant to correct), empty creates, and
  any other string is a file-local name that lets child sheets point at parents
  created in the same file.
- **A collection sheet speaks for the whole collection** (an unlisted row is
  removed, or a round trip could never delete an address); no sheet leaves the
  collection alone; removal is counted separately and said in words. A child
  row naming an unlisted parent is refused.
- Export and import are tested against each other (round trip changes nothing).
  The file is read into memory (sheets must be cross-referenced first);
  admin-only until §7.5.

### 5.7 Documents from templates (XIV-4)

A customer writes a .docx with `[markers]`, uploads it, downloads a filled copy
as .docx or PDF.

- **The placeholder list is derived, not documented**: the customer's own
  definitions plus general markers, computed by one class that also fills them
  (two functions computing list and values separately is a feature that breaks
  on the first rename). Values render through the field type's `display()`.
- **Two marker kinds**: record markers per variant; general markers
  (`[today]`, `[tenant.name]`) namespaced, declared by core as an interface the
  application answers with whole markers.
- **Uploading and generating are two permissions**: designing the invoice and
  producing one are different jobs.
- **Libraries decided by licence**: `anourvalar/office` (MIT; PHPWord is LGPL)
  fills the .docx and handles Word splitting a placeholder across runs;
  Gotenberg (MIT, via bundle) makes the PDF, because no pure-PHP PDF library can
  read a .docx and the HTML detour approximates the layout (dompdf/mPDF/TCPDF
  fail on licence anyway). Core declares `PdfConverter`; the engine never learns
  the converter is a service on a network.
- **Templates live in the tenant's own database (bytea)**: small, few,
  unmistakably one customer's; the general attachments question is deliberately
  not answered here.
- **Choosing is one page shown as a modal**; one route, template and format as
  query parameters. A converter that is down offers the .docx and says so.
- **Word's `showingPlcHdr` flag is dropped on the way out**, or LibreOffice
  renders nothing for untouched content controls: Word and LibreOffice agreeing
  a file is valid is not agreeing what to draw.
- **An unknown token is reported, never refused and never blanked-without-word**
  (XIV-25): known markers fill (empty beats printed brackets), unknown ones
  print as typed and a sentence beside the template says so, re-checked on every
  templates-page render because a template goes stale when a field is removed.
  The scan is `TemplateTokens`, extracted so three scanners cannot disagree
  about what a marker is. Unused markers are deliberately not reported (noise
  teaches the reader to skip the line that matters).
- **`[tenant.logo]` draws a picture** (XIV-89): DrawingML written by hand, the
  marker's run replaced by an element (the opposite of the one operation
  everything else uses), OPC bookkeeping per part in `DocumentImages`. Natural
  size at 96 dpi, capped to 40 × 20 mm, never enlarged (fit-not-stretch is why
  one upload suffices). Format decided by decoding bytes; PNG and JPEG, §8.6's
  list for §8.6's licence reason; no dependency added. No logo means blank, and
  the marker stays in the vocabulary either way. The image pass runs after
  `RepeatingBlocks` (each copy needs its own drawing id) and before the library.
  The reference list badges the marker with a *kind* (the next one is plausibly
  a barcode); the email page filters it out entirely. Documents are generated
  without a browser (`InstanceContext::images()` reads the column; no request
  in flight). The PDF is proved by searching for an image XObject, both ways
  (a no-logo document asserts none).

### 5.8 Lifecycles (XIV-14)

A module declares states and moves. **On symfony/workflow**, adapted twice: the
state lives in an ordinary `choice` field the module declares (a lifecycle is a
rule over a value, not a second store, so filtering, export and history come
free), and definitions are built from the blueprint, not `framework.workflows`
(which modules a customer has is a runtime question). Two component traps:
`StateMachine`, not `Workflow`; and two `from` places mean "from both at once",
so "from either" is two transitions sharing a name.

- **Moving a record is its own permission**, per module, not per transition
  (sending an invoice is not correcting a typo in one; per-transition waits for
  somebody who needs it).
- **A state can end editing**: the button is a courtesy, the URL is the rule;
  the first engine refusal on a module's say-so, and it narrows §7.1 without
  answering it (a declared rule, not a subscriber's veto).
- **The timeline gets its own verb** for a transition.

**Customer-authored expressions are declined** (XIV-88), with two rules that
decide most candidates: *not where the answer must become a WHERE clause*
(permissions, filters, validation counts: an expression runs over loaded
records, the wrong side of the LIMIT and the total), and *not where the engine
reads the thing rather than runs it* (numbering patterns are statically
derived). Guards pass both rules and are still PHP: §7.1's narrowing is about a
rule **a module** declared, a module is code, and against code an expression
string is strictly worse than a typed predicate (no static analysis, silent
breakage on a renamed key). The component earns its keep only where the author
cannot ship PHP, and a customer cannot author a lifecycle at all. What would
change the answer: a customer actually blocked, plus a home in tenant metadata,
an editor page to XIV-27's standard, and a written decision about what an
expression may see. symfony/workflow's own expression guards are not adopted:
they dispatch through events, and these state machines are built with no event
dispatcher on purpose.

**The condition itself: `TransitionGuard`** (XIV-110). One method, `refusal()`,
null or a translation key; constructed inline in the blueprint beside the
transition it conditions; one guard per transition (a module wanting two
conditions writes one guard, which is also how it chooses whose sentence wins).
The button and the enforcement are the same predicate asked twice
(`offeredFor()`; hiding a button is not enforcement, a retyped POST is not a
button). A refused move is shown with the module's reason where the button would
be, in the module's own catalogue ("an order needs at least one line", which
only the module can write). `GuardedRecord` hands the guard the record and lazy,
memoised rows; a list page never asks a lifecycle anything, so the bill is one
query on a page already loading those rows. `RecordWriter` still validates
nothing: a guard is a condition on a move, not on a write, and a record may be
saved in a state a guard would refuse to move it out of. The placement rule:
**never on the only way out** (the order guards `confirm`, emphatically not
`cancel`). The guard does not refuse a zero total, only no lines at all: an
order can legitimately come to nothing.

### 5.9 Derived values, and the money that needed them (XIV-16)

`ValueDeriver` is handed the record's fields **and its rows**, inside the save's
transaction; what it writes is what lands, what history describes, what the next
reader sees. **This answers the non-veto half of §7.1**: a module may take part
in a save by deriving, and there is deliberately nothing to cancel with. Rows
arrive in storage order (subtotals need "the lines since the previous
subtotal"). **A collection missing from the derivation is one the save is not
touching** (an empty list means "no rows" and deletes; without the distinction a
transition would zero the totals). A collection nobody can type into is derived,
read off its fields, and is off the form, import, export and history.

**The money model:**

- **Totals are stored, not computed on read**: "orders over 5000" is a WHERE
  clause, and what a confirmed order came to is a fact about that day.
- **VAT is per line**: the article carries the rate (a number, not a Swiss
  enum; empty means no VAT and no VAT table), the line copies it, the per-rate
  breakdown is a derived collection that cannot disagree with the tax total.
- **Rounding has one answer**, in `Money\Amount` and nowhere else: line totals
  rounded at two places as computed, VAT grouped per rate before rounding. No
  five-rappen rounding (that is about paying cash).
- **A discount is a line with a negative price**, never a header percentage
  (only a line can say which VAT base it reduces).
- **The live preview runs the same derivers** (XIV-32, `DerivedValues`):
  recomputing in the browser would be a second rounding implementation, and the
  place they disagree is a rappen shown to the person deciding to send. Nothing
  about the preview is validated (somebody who typed `2.` is mid-number).
- **A line contributes if it has a price, not if it is the right kind**, so a
  fifth kind needs no arithmetic; a subtotal is the one thing asked by kind.

**Inclusive prices** (XIV-116): a shop prices at 19.95 *including* VAT, and
deriving gross from a derived net produces 19.96. **The mode is a value on the
document, materialised from the tenant's setting when the document is created**
(per line would make one column mean two things halfway down; per tenant alone
would reprice every draft the day the setting changes; per document from the
tenant is both, the §5.16 chain again). Null at the top: an unasked installation
writes nothing, which is not the same as answering "excluded" even though both
derive net-priced. An invoice takes the mode from the order it was seeded from.
The arithmetic is the same loop the other way: the lines sum to the gross,
per-rate net is `gross ÷ (1 + rate)` rounded once, and **tax is the remainder,
so the gross the customer typed is the gross that prints**. The generalising
rule: *the figure somebody typed is exact; the derived figure absorbs what is
left*. No remainder crosses rates, because VAT was already grouped per rate
before rounding. `Amount::withoutPercent()` is the one operation that rounds
inside itself, because division cannot defer. No stored total re-reads
differently; existing tenants take the field through §7.2.1; two values, not
three ("no VAT" is a rate of nothing); the shipped labels are whole sentences,
because `[vat_mode]` prints an option label with no field name beside it.

### 5.10 Document numbers (XIV-15, XIV-27)

Two fatal failures: a number that changes after somebody read it out, and two
documents carrying one number.

- **An option, not a field type**: `ORD-{year}-{number:4}` spreads into a text
  field's options, per customer, editable by the customer.
- **One pattern instead of three settings**: `{year}` in the pattern *is* the
  annual reset, so the third setting cannot be set wrongly; the width is what
  makes text sort numerically.
- **The counter is a table and allocation is one statement**
  (`INSERT … ON CONFLICT DO UPDATE … RETURNING` on (shape, field, period)). A
  Postgres `SEQUENCE` cannot restart yearly without a raceable `ALTER` and
  survives rollback. Allocation happens inside the save's transaction (§5.9's
  seam), so a failed save gives its number back.
- **Gaps, decided**: assigned on first save (a draft needs a name), a deleted
  record keeps its number, and soft deletion means the document behind a missing
  number is still there. The year is the allocation year, never a record date
  (backdating must not reach into a closed book).
- **The pattern page shows the number it will produce, live** (XIV-27): every
  failure mode of the small language is quiet, and the answer is rendering from
  the pattern as typed. The syntax stays a template, not ExpressionLanguage:
  `NumberFormat` reads the pattern without running it (`{number}` decides
  numbered-at-all, `{year}` decides which counter), and an evaluator can only
  answer by evaluating. A pattern with no counter is refused on the write path
  (silence in place of an answer otherwise ends at the first blank invoice).
  Switching patterns draws from a different counter and resets nothing; the page
  says so. The counter's next value is settable, **forward only**, guarded
  inside the statement (`WHERE next_value <= :next`), because migrating
  businesses arrive mid-sequence. Which types may be numbered is declared
  (`Numbers` on `TextFieldType` alone: a document number is a string in every
  part of itself).
- **Turning numbering on backfills, in creation order, once** (XIV-91):
  fill-on-any-save would number records in the order somebody happens to open
  them. Behind a confirmation naming the pattern, the count, the first and last
  number, and irreversibility, required in the controller; deliberately not
  through `RecordWriter` (one administrative act is not three hundred edits, and
  stamping `updated_at` on the whole table is the confusion being prevented).
  Values somebody already typed are recognised by running the pattern
  *backwards* (recognition and production one rule), and the counter is floored
  with `GREATEST`; unrecognisable values are left alone, safely (the counter can
  never produce them). **A numbered field becomes `derived`**, and the pair
  moves together; only a text field nothing else fills may be numbered (two
  derivers on one column is a race decided by declaration order). Turning it
  off is a page: numbers stay, the counter is kept (deleting it would walk a
  future re-enable straight through printed numbers), and an emptied pattern is
  still refused rather than read as "off".
- **A numbered field is a unique field** (XIV-109): the arithmetic is complete
  about the counter's own numbers and blind to every other way a string reaches
  the column, so numbering-on marks the field `unique`, refusing where the
  column already holds duplicates. The index build's `SHARE` lock is the first
  step, so the backfill scan reads a table nobody may change: the race window is
  gone, not narrowed. **Un-numbering leaves the field unique**, which is exactly
  when the index earns its keep; a customer who means it unticks the box.

### 5.11 Repeating blocks in templates (XIV-17)

**A table row containing a collection marker draws itself once per row.** The
rows are multiplied before the library sees the document; the library only ever
substitutes markers. No open/close syntax: writing `[lines.description]` in a
cell is what makes the row repeat, and `<w:tr>` is the unit because it is the
unit Word gives a person. How much the template cares about kinds is the
template's business (the engine pre-formatting rows would decide how somebody's
invoice looks). Consecutive blocks for one collection are a group, replaced as a
whole in collection order. **An email does none of this on purpose** (§5.13.1):
in Word the layout is the deliverable; in an email there is nothing to take.

### 5.12 One record made from another (XIV-19)

`Seed` declares the source module, the linking field, and the fields and rows to
bring along, rather than a class per module pair.

- **Copied, never read through**: the new record owns its values, which is what
  lets an invoice stay correct after the order changes. The link is kept beside
  the copy for reporting.
- **Seeding is not saving**: what comes back is a filled form somebody reads and
  changes; the seeded page is the ordinary new-record form.
- **What is left is read, not stored**: each seeded row records which source row
  it came from, and "outstanding" is quantity minus what every document took; a
  stored "quantity invoiced" column disagrees the first time somebody deletes an
  invoice. The row reference is a plain number, not a `reference` (a collection
  row has no page; this is arithmetic, not a link).
- **Through the reader's own permissions**: no grant on the target module reads
  as wholly uninvoiced, the safe direction.
- **A sent document is corrected by another document** (credit note): the
  lifecycle's editing lock doing the work, not a special case.
- **Line totals and subtotals are deliberately not copied**: derived on save, so
  a partial invoice restates its own subtotals.
- The invoice module came out as a declaration and a translation file; the one
  engine cost was `LineTotals` moving to core, because two modules needing
  identical sums is the engine's problem (§1).

### 5.13 Email templates, written here rather than uploaded (XIV-38)

A document template is a .docx because layout is design work; an email has no
layout worth designing, so a template is a form: name, subject, Markdown body,
text in the customer's database. **The base wrapper ships in code, one of it**
(a second frame waits for two emails needing different frames). **The markers
are `DocumentMarkers`**, the same class and vocabulary as documents.

- **Raw HTML is escaped at parse (`html_input: escape`) and the output is
  sanitized**, two layers against different things: escaping closes the route
  from a record value into markup at the point where text and markup are still
  distinguishable (substitution happens on the Markdown *source*, before
  parsing); the sanitizer (`symfony/html-sanitizer`) covers what CommonMark
  itself emits, like `[click](javascript:…)`.
- **The Markdown source is the plain-text part** (nothing strips tags to fake
  one, which is the quiet argument for Markdown over a rich-text editor).
- `league/commonmark` is BSD-3-Clause; its nette transitive deps are
  BSD-or-GPL disjunctions with BSD taken, recorded because a grep for GPL finds
  them.
- **Writing templates is its own permission** beside `templates` and
  `document`. **Core answers with the contents and stops** (`EmailRenderer`
  returns subject, HTML and text, never a `Mime\Email`: from and to are the
  application's facts).

#### 5.13.1 A collection in an email body (XIV-62)

**`[lines]` is one marker rendering the whole collection as a table whose shape
ships in code.** Markdown has no repeating unit, and every candidate
(pipe-table rows, list items, `[lines]…[/lines]`) exists to let a tenant
hand-build the table, which takes back §5.13's "no layout worth designing" three
paragraphs after making it; the attachment (§5.15) carries the properly laid-out
lines anyway, and a message wants a summary. The grammar extends the document's
own production: `[lines]`, `[lines:kind]`, `[lines.col,col]`, both. **It expands
to Markdown before CommonMark parses**, keeping the escaping property and the
text part (an HTML expansion would arrive after the escaping decision). Cell
escaping: backslash first, then pipe; newlines become spaces. `TableExtension`
is named individually, not the GitHub bundle. Mixed kinds go into one table in
collection order with the union of columns (per-kind tables re-sort the invoice;
default-kind-only can be *wrong*). The kind discriminator and inherited-source
fields are left out of default columns; nothing else is capped. The token panel
says what `[lines]` does (it sits beside `[first_name]` looking identical and
expands to a table). A collection marker in a subject is blanked. The
substitution is one left-to-right `preg_replace_callback` pass that never
re-reads its output (a name containing `[today]` stays literal), collections
asked first. The wrapper gained its one scoped `<style>` block for the bare
table CommonMark emits.

### 5.14 Sending one from a record (XIV-39)

One button and a chooser (never a button per template). The fast path's safety
is not a dialog: the **resolved recipient and subject are on screen before the
button**; the preview renders the real message including who it will appear to
be from (§8.7 makes that vary).

- **The module declares where the address lives** (`MailRecipient('email')`, or
  `through: 'contact'` for one hop through a reference). Guessing (first email
  field) silently picks the wrong address for the first customer who adds an
  `invoice_email`. **One hop, and a second is deliberately impossible**: the
  seeded copy (§5.12) is what keeps one hop enough. The hop is read unscoped
  (XIV-42's split: whoever may send an invoice may reach the address it is
  for). The declaration comes from the blueprint; whether it still applies is
  read from the customer's definitions.
- **The address is shown, editable, and never written back**: sending a mail
  somewhere once is not a correction to the contact. It is a correction, not a
  substitute: an unresolvable recipient offers no send and refuses a hand-posted
  one, with the reason in the customer's own field labels ("the Customer this
  record names has no Email"); a module that never declared a recipient draws
  nothing at all.
- **The timeline entry stores the recipient** (editing the contact next year
  must not rewrite who was mailed). **A failure is `email_failed`, its own
  verb**, written by the object that performs the send (put it in the caller and
  the catch block gets a flash message instead of an entry).
- **`send_email` is its own permission**, the sharpest of the four (a wrongly
  generated document stays on a laptop; a wrongly sent mail is in a customer's
  inbox), and the one that names a record, so it scopes.

### 5.15 The invoice goes with the mail (XIV-40)

- **Attaching means generating, so it takes both grants**: `document` is asked
  again at the moment an attachment is requested, on the **record** (it scopes),
  because "the picker was not on their screen" is not a check.
- **One timeline entry; the attachment is a key on it.** Two entries would be
  indistinguishable from two acts that really happened (a download, then an
  unrelated mail), and "was the invoice actually on that mail" must stay
  answerable. The generator's `contents()` path does not announce, which is also
  what lets the preview build the document without a history entry.
- **Failure is two-sided**: the document could not be made means nothing is sent
  and nothing written (no send happened; the person is told in the document
  layer's words); the send failed after means `email_failed` naming the
  attachment. The document is generated before a `Mime\Email` exists, so "a
  failed generation sends nothing" is true by construction.
- **A ceiling, 7 MiB (`XIVI_MAX_ATTACHMENT_BYTES`)**, chosen against receiving
  servers (base64 makes 7 MiB into ~9.5, inside the common 10 MB limit): a
  bounce arrives hours later at nobody's inbox and the invoice simply never
  arrives. Configurable because the authority is the deployment's own relay.
  Checked on the document (the part that varies by orders of magnitude). The
  preview generates too, because "the converter is down" and "too big" are
  exactly the surprises that must not wait for the irreversible button.

### 5.16 When an invoice falls due, and what makes it late (XIV-67)

- **The due date is stored, and this is §5.9's argument applied to a date**:
  terms change, and computing on read would make every past invoice due earlier
  the day somebody edits a customer's terms. A `ValueDeriver`, **materialised at
  the transition to `sent`** (where the lifecycle already locks, and the first
  moment a deadline means anything), **written only into an empty field** (sent
  twice does not extend; paying or cancelling touches nothing). No backfill: a
  missing due date means **not overdue**, never overdue.
- **Overdue is a read, not a fifth state**: every stored state is something a
  person performs, and nothing performs overdue; the calendar does, and there is
  no worker. `status = sent AND due_date < today`, strictly before (an invoice
  due today is due today), expressed once and compiled both as a question and as
  query conditions (counting by loading every invoice is the dashboard's N+1).
- **Three layers, each overriding**: tenant profile, contact, then the invoice's
  own materialised date. The invoice stores the **date**, not the days. **Null
  at the top, not thirty**: a term nobody chose is not a term. Days only: early-
  payment discounts are two amounts and a §5.9 change; end-of-month is a later
  rounding option; free text cannot be compared to a calendar; zero is a real
  term.
- Reading the customer's terms is one unscoped hop through the reference, like
  §5.14's recipient, read once at send; what is kept is a date on the invoice.
  Not built: partial payments, credit notes (§5.12 already answers), dunning
  letters (§5.14 plus a template).

### 5.17 Demo data a field can have an opinion about (XIV-24)

The generator asks each field's type for a value and knows nothing about
meaning, which fills customer-added fields for free and produced 63.90 as a VAT
rate. **One option, `samples`**: a list of values drawn from, read in one place
(`FieldSampler`); a field declaring nothing behaves exactly as before, and the
seeded sequence keeps `--seed` repeatable. Weighting is repetition. A `null`
among a required field's samples, and the whole list on a unique field, are
dropped: the generator's promise is that everything it makes passes validation.
A sample is a literal, so it is meaningless on a `reference` (ids are one
tenant's) and the sampler does not switch on type. No form control yet (that is
§5.4's capability question); existing installations keep their definitions.

**A derived field the generator says nothing about** (XIV-73): sampling it
suppressed the fill-if-empty derivers (numbers, due dates) and spent numbering
nobody could give back; the suite asserts the counter equals the records
generated. **Demo data drives the lifecycle rather than assigning a state**: the
sampled state is a destination, walked from the initial state through
`Lifecycle::pathTo()` and the same `apply()`/`save()` a click uses, because a
tenant of nothing but drafts exercises no transition and has no due dates at
all. Costs a save per transition; the distribution is a `samples` list on the
status field; a guard that refuses (§5.8) stops the walk and leaves the record.

### 5.18 Follow-ups, and where §5.2's argument stops (XIV-80)

A follow-up is something somebody decided to do about one record by one date:
priority, due date, optional assignee, a thread of notes, a reversible done
stamp.

- **One shared pair of tables**, the opposite of history: history is written
  automatically and grows without bound, a follow-up is typed by a person.
  **`record_id` therefore carries no foreign key and cannot** (the target table
  depends on `module`), given up deliberately with two stated consequences:
  every read joins through to the record and honours `deleted_at IS NULL` (as a
  second query; the module table is not a mapped entity), and a future hard
  purge has to sweep `follow_up` itself. The note's FK is real and cascades.
- **Users are denormalised even though they could join**: a task outlives its
  assignee, and a captured label keeps saying who they were. Deleting a user
  clears the assignment (listener) and keeps the creator.
- **Two verbs per module**: `follow_up_create` (including notes: a task nobody
  may comment on is taped shut) and `follow_up_complete` (both directions; done
  is a nullable timestamp, and whoever can close can reopen, which is what makes
  closing safe). Reading follows the module's `view`.
- **A note is editable and deletable by its author and nobody else, including
  administrators**: the one place `ROLE_ADMIN` is not a bypass, expressed
  against the stored author id (a deleted user's notes become nobody's).
- **Assignment requires the assignee may view the record**, checked at
  assignment through `PermissionResolver`; **revoking later is deliberately not
  retroactive** (silently unassigning somebody's work with no record is worse),
  and the residue is shown without the record's title or link.
- **`FollowUpManager` is the fourth seam** under §8.4's three: imports, console
  commands and future APIs do not pass a route, and own-records scoping is
  honoured there through the same `RecordAccess`.
- **Per module, a boolean on `ModuleDefinition`, on by default, reversible**:
  switching off deletes nothing and hides everything; on brings it back.
- `due_at`/`done_at` are `timestamptz` (a deadline is an instant two countries
  agree on); `updated_at` means last activity on the thread. Two indexes, no
  more.

**The dashboard widget** (XIV-81): three lenses that are upper bounds and nest
(today, this week, all). **Today includes today, deliberately the inverse of
§5.16** (a note to yourself due at 16:30 belongs on the 09:00 dashboard; the
disagreement is stated at the line). **No lower bound**: overdue work stays in
every lens, sorted first; the only way off is done. The week starts where ICU
says the reader's region starts it; boundaries are drawn in the reader's zone,
never in seconds (a 604800-second week ends an hour early across a clock
change). Resolving records is batched per module (`findAny()`), cost = modules
with work, asserted by growing the list tenfold and requiring the query count
not to move. A record the reader may not view shows the follow-up without title
or link; a soft-deleted record's follow-up is excluded entirely; a switched-off
module drops out. No unassigned-work lens: that is a queue, a different screen.

**The record page** (XIV-82): the panel sits above the record's fields, full
width (a claim on attention below the fold has been missed), and never on
lists. **The component owns no writes**: a `#[LiveAction]` dispatches through
`/_components/…`, which carries no module and is invisible to
`PermissionCoverageTest`'s by-URL surface, so the six mutations are ordinary
POST routes with `#[IsGranted]`. The archive is a counter, not a section.
**Done is a state**: while set, only reopening is permitted (notes refuse);
double-done is refused (a second stamp would overwrite when it was settled);
reopen is exempt, which keeps it reversible. Checks live on the write path, not
only in the panel (the page open across somebody else's Done is the case a
hidden button cannot address). Priority renders through one Twig function
(`follow_up_tone()`), the mapping written in full because two of three tones
agree by coincidence and the loudest one does not. A follow-up has no text of
its own; the first note is what it is about; notes read oldest first. A module
switched off renders nothing at all. `datetime-local` input is read in the
reader's zone before storing. Overdue styling is deliberately absent here (the
widget owns what "due" means). One rule lives in the controller: the follow-up
in the path must be on the record in the path (404), or the grant and the
manager would be talking about different records. `ENFORCED_WITHOUT_A_ROUTE` is
gone; the next engine-first ticket should put it back rather than weaken the
check.

### 5.19 Vouchers, and a counter with a rule in it (XIV-103)

A code, worth something, good between two dates, redeemable a bounded number of
times. Applying one is §5.24/§5.25; the kinds were reshaped by §5.25 (four
modes, `free_article` dissolved), which cost a blueprint edit because everything
structural here held.

- **The kind is a variant** (§5.5): the fields depend on the answer. Three
  modules would make "which voucher" polymorphic (§5.2's refused shape);
  nullable fields per kind would ask four questions where one is meant.
- **`uses: [article]`, not `requires`** (XIV-23): only some kinds need a
  catalogue. `AvailableVariants` hides a variant whose required reference points
  at an uninstalled module, asked by both the form and the kind chooser; the
  stale-link `#id` fallback is for a voucher created before articles were
  removed, not the primary mechanism.
- **Case is decided by folding on the way in** (`toStorage()` uppercases), never
  by comparing: the unique index is case-sensitive, and a case-insensitive PHP
  rule would disagree with the database about what a duplicate is. A field type
  rather than an option on `text` (a type owns its rules; the cost, stated: the
  registry is global, so "Voucher code" appears in every tenant's type
  dropdown).
- **Two alphabets**: typed codes are wide (`GIVE-10` needs `I`, `1`, `0`);
  generated ones are Crockford's set, eight characters in two groups, from
  `random_int()`. **Not a sequence**: whoever holds `AB-0004` could guess
  `AB-0005`, and that is somebody else's money. Generating is leaving the box
  empty (a deriver fills it once, not `SafeToPreview`, or typing speed would
  mint a code per keystroke); a generate button was weighed and costs three
  shared-surface changes to replace one sentence of help text.
- **Unlimited is nothing stored, not a sentinel**: a sentinel is a value
  arithmetic happily compares, wrong on the day a promotion outruns the
  constant; absence forces the rule to be `IS NULL` in the one statement that
  matters. Floor is 1 (zero redemptions is a voucher switched off, which the
  dates express).
- **The counter is engine bookkeeping in its own table** (`voucher_redemption`,
  unique per voucher, created by a tenant migration since the module table may
  never exist; no FK possible, and a counter row outliving its voucher is what
  soft deletion already does). Not the customer's field: nobody may rename,
  edit, or import over it. **One statement, the limit inside it** (`ON CONFLICT
  … DO UPDATE … WHERE`), in the caller's transaction, so a failed checkout gives
  the use back. No row back *is* the refusal.
- **The race is tested with two real connections** (`#[SkipDatabaseRollback]`,
  own committed tenant), interleaved statement by statement, both endings
  checked. What that cannot prove, a statement-count test does: DBAL's logging
  middleware asserts a redemption is exactly one statement carrying both the
  `ON CONFLICT` and the `WHERE` (verified by temporarily rewriting the guard as
  read-then-write, which only that test caught).
- **Expiry is a read** (§5.16's argument verbatim): nothing performs expiry, the
  calendar does. Empty dates are unbounded in both directions; both ends
  inclusive (these are two fields about when a *rule* applies, deliberately
  unlike §5.27's occupancy periods). No "currently valid" filter: it is a
  conjunction of disjunctions and §7's `OR` limitation is honest about it;
  faking it would drop every voucher with no end date.

### 5.20 A unit belongs to the article (XIV-118)

- **The unit is a field on the article; the line takes a copy** through XIV-18's
  inheritance (re-pricing the catalogue does not rewrite placed orders; the
  drift marker watches it like the price). The invoice gets it by seed, because
  nothing on an invoice line reads through the article.
- **A shipped set of seven, seeded** (hours, days, pieces, kg, m, m², litres):
  the only shape that gives a new customer something on day one. A managed table
  would be a third of §5.26 built early; a bare choice field gives every
  installation its own spelling. Customers add options now (XIV-144); a module's
  own options are add-and-rename-never-remove (§5.4); the field is deliberately
  not pointed at a shared list (inherited copies are compared against these
  values).
- **Values are keys (`m2`), labels are the customer's**; the values live once in
  core (`Xivi\Core\Field\Units`) because article, order line and invoice line
  must agree and modules may not depend on each other; labels stay per module
  catalogue.
- **No plurals, decided**: installed labels are the customer's text with no key
  left to look a plural form up under, so a unit is a short invariant label in
  the form a line usually needs (the plural where the word has one; `2.5 hour`
  is a worse error than `1 hours`).
- Custom lines get the field and type into it; comment and subtotal lines are
  not offered one (no quantity), falling out of the variants. **Optional**,
  load-bearing: existing articles have no unit and their lines must read exactly
  as before; existing customers take it via §7.2.1, and the accepted cost is a
  wrapped form row until they narrow the description themselves (an upgrade
  only adds; re-laying-out somebody's form is the refused retro-fit). No
  conversion between units (a factor, a direction and a rounding rule per pair,
  and it changes what a price means).

### 5.21 A field with formatting in it (XIV-131)

**Markdown, because the dangerous half was already built**: §5.13's safety
property (substitute into source, `html_input: escape`, sanitizer behind it). A
rich-text editor storing HTML arrives on the far side of the escaping decision.

- **A new type, not an option on `textarea`**: four things diverge (widget,
  record page, document, list cell); whether a value is markup-bearing must be
  readable from the type (`instanceof HoldsFormattedText`, answered once, not a
  boolean every caller re-asks); and a checkbox is retroactive, reinterpreting
  every stored value at once with no migration and no history. The accepted
  cost: no path from an existing `textarea` (that is a §7.2 conversion with a
  screen). **XIV-113 is told to follow this**: a `multiple` option on
  `reference` would change the storage shape, the retroactivity argument at its
  strongest.
- **One converter, one sanitizer policy** (`MarkdownRenderer` in core, policy
  renamed `email` → `markdown`, deliberately the strictest caller's: relative
  links and `data:` media stay dropped). Two configurations is how one ends up
  unescaped, quietly, for a year.
- **The editor is a textarea and a preview**, free because the record form
  already round-trips every keystroke (XIV-32); a form theme block off the
  type's prefix, since `RecordForm` renders fields wholesale.
- **What a value is worth per destination, each decided**: the record page gets
  rendered markup (the only place a record's value reaches a page as markup,
  safe because it went through the one renderer), full row width; a document
  gets the words with the marks taken off (punctuation printed on an invoice is
  punctuation nobody meant); a list cell the same; an export gets the source
  untouched (importable, and the one way to get formatting back out); filters
  and search match the source. The plain rendering walks the parsed document
  and reads literals (never regex-stripping, never tags-removed-then-unescaped),
  asserted by handing the renderer a sanitizer that throws.
- Not in: images (XIV-115; the policy already refuses them), extensions beyond
  `TableExtension`, collaborative editing. The first blueprint consumer is the
  knowledge module, a *new* module, because installing does not retro-fit.

### 5.22 An internal knowledge base, and how much of it was already here (XIV-132)

A very simple wiki: experienced staff write entries, everybody reads. **The
engine work this needed was none**, and that is the finding: `packages/knowledge`
is a blueprint, a translation file and a bundle. Who wrote and changed it is
§5.2 plus the system columns (no `author` field on purpose: a date somebody
forgot is a record confidently wrong about itself); write-vs-read is §8.4;
search is `contains` over a filterable field; the body is §5.21's `markdown`.

- **Topics are a plain `choice`**, seeded at install; this module is §5.26's
  recorded first consumer and still ships a plain choice after it landed,
  because a module's own field may not point at a customer's list. Customers add
  topics (XIV-144); the module's six cannot be removed. Not required (somebody
  writing at half past five should not be stopped by a dropdown).
- **Linking: no, and "no" is the decision**: a link earns its way in from both
  ends, and the read-back half ("what do we know about this customer") is a
  panel on every record page in the system, which nobody asked for. Consequence
  worth having: the first module that installs into a completely empty tenant.
- **Staleness beats emptiness as the failure mode**, and the defence is the age
  on the screen, not a review date (a lapsed review date looks exactly like an
  unlapsed one). The module list grew a **Changed** column beside Owner, on
  every module's list: both are system columns, neither sorts (a `RecordQuery`
  orders on the customer's definitions).
- **The search ceiling is stated**: `ILIKE '%word%'`, no stemming, no ranking,
  no index; at a few thousand entries somebody wants `tsvector` and a GIN index,
  which is a ticket, and the test asserts the ceiling (the plural failing to
  find the singular) so the upgrade has a red line to point at.
- **Writing is granted deliberately** (the platform default is already deny):
  wrong knowledge people act on is worse than none, and the other direction
  cannot be undone by a setting.
- **Not a wiki** (no trees, cross-link syntax, namespaces, revision diffs) and
  **not customer-facing**, kept by the declaration: no `mailRecipient` means
  §5.14 has nothing to resolve and draws no send button.

### 5.23 A phone number is one number (XIV-114)

`phone` stores **E.164** via `toStorage()` and refuses what it cannot read.
The seam property: the form, the importer and the query compiler cannot
disagree about what a phone number is, because none of them has an opinion
(proved by going in one door and out another). Consequences taken: `unique`
starts working; imports of old data will refuse rows; and the library's
metadata moves, so a `composer update` can change what is acceptable in both
directions (nothing revalidates on read: a stored number is a fact about a
customer).

- **The country comes from the existing chain** (`InstanceRegion`, the fourth
  instance of the seam, delegating to `FormattingLocale::instanceRegion()`
  rather than reading the profile again). **The person is deliberately not in
  the parsing chain**: a French colleague at a Swiss company typing a local
  number is typing a Swiss number, and asking who is looking would store the
  same digits as two customers. Display takes the opposite rule (national where
  local to the reader, international where not).
- **A per-field country override** (`AssumesACountry`): an option with a
  default; changing it decides how the next value is read and rewrites nothing.
- **Extensions are refused** because E.164 has no room and `format(E164)` drops
  them silently (measured, asserted); a second field is the answer and the
  editor adds one.
- The dependency is the lite build (2.8 MB vs 25 MB; the 22 MB is geocoding and
  carrier data nothing touches), argued in the file that uses it; the first
  Apache-2.0 dependency in a production image, with its notice section.
- Contact's blueprint declares the type for new installs; existing tenants keep
  their text field (§6.1; changing it for them is XIV-146's conversion).
  Nothing is sent to a number: this is a field type.

### 5.24 A voucher on an order (XIV-104)

**A discount is a derived value, and derived values are the engine's** (§5.9):
it belongs in the deriver's path, never written by hand. Two ticket decisions
followed, not re-argued: the voucher applies **before VAT**, and **a discount is
its own line** (this rule governs the order mode; §5.25's line mode reduces the
line instead). Every kind is a line, which is why the implementation is small
and why nothing downstream (document, seeded invoice, VAT grouping) knows which
kind it was; the customer's document shows what they were quoted with the
discount stated separately, never `1 × Widget @ 100.00 = 90.00`.

- **Mixed rates get one discount line per rate**, pro rata on each rate's own
  net, visible on the document; **the last line takes the balance** (shares
  before it are computed and subtracted), agreeing with XIV-116's remainder
  rule, and no remainder crosses a rate. Inclusive VAT needed no case: a
  discount line sits in the price column like any other. A voucher worth more
  than the order is capped by it (a negative total is money owed back, which
  nothing hands over).
- **One deriver, and a seam rather than a second one**: deriver order is
  deliberately unspecified, and discount-vs-totals have a strict order in both
  directions, so a second deriver would be wrong half the time.
  `Money\DocumentDiscounts` is the one-method seam core defines and the voucher
  package implements; core's half carries no voucher vocabulary. The package
  finds the order's voucher field by reading the shape for a reference into
  `voucher` (works for customer-built modules too). Three answers: `null` (not
  mine), an empty discount (mine, worth nothing today), a discount; collapsing
  the first two would strip copied discount lines off invoices or leave a
  removed voucher's discount forever.
- **The engine owns the generated lines**: the deriver rewrites them on every
  save reusing their ids (a forged, edited or deleted row is restated), the
  form draws them disabled, and the kind is not offered by `AvailableVariants`.
  Taking them off the form entirely would churn ids and fill the timeline.
- **Stored, not re-read**: the amount is a fact about the document; deleting the
  voucher changes nothing on the order. The deriver recomputes on every *save*
  while the lifecycle has not locked the record, the same window every derived
  figure has had since XIV-16. A voucher that cannot be read leaves the lines
  alone (absence is not "no discount").
- **Redemption is a subscriber on `RecordChanged`, inside the writer's
  transaction**: a use is taken when the order commits (the live form re-derives
  per keystroke, so any earlier would burn a voucher per character), a failed
  save takes nothing, and a refusal takes the save down. **The count is the
  number of documents carrying the voucher**: naming takes, un-naming gives
  back, deleting gives back; a cancelled order keeps its use (it still carries
  the voucher and is locked), the one visibly imperfect edge.
- **Refusals name which**: expired, not started, exhausted, unreadable are four
  sentences. `RecordRefused` is the missing half of §7.1's subscriber question,
  shaped like `DuplicateValue`: it names the field and lands on the control with
  the form intact. The deriver still cannot refuse. Validity is checked once,
  when the use is taken (re-checking every save would strip a draft somebody
  merely opened after the promotion ended); deliberately no transition guard
  (confirming would be refused because the shop took too long).
- **The field exists only where both modules do** (`Module\AvailableFields`): a
  definition that does not exist is invisible everywhere at once, which no
  per-screen rule matches. The upgrade offer asks the same question; a customer
  who buys vouchers later is offered the field; `ModuleInstallOrder` follows
  `uses` edges within one requested set. Narrowed to unscoped `reference`
  fields, because variant-scoped ones are `AvailableVariants`' business.
- **The invoice needed almost nothing**: a discount is a line and the seed
  copies lines; the invoice gains the `discount` kind as an ordinary kind
  (nothing generates one there; a copy is editable, and what to bill is decided
  on the invoice). Open, its own ticket: a partial invoice takes the whole
  discount (XIV-147). The discount line appears at first save (inserting
  deriver-invented rows into a form mid-typing is a bigger change than the thing
  shown; the totals follow live).

### 5.25 Two ways to apply a voucher (XIV-122)

**A voucher has a mode: order mode adds its own line (§5.24's rule), line mode
reduces the chosen line** (it has a line already; a second line beside it says
the same thing twice). Not a tension, and written down because the reading was
reasonable.

- **Mode and kind are one variant field with four options**
  (`order_amount`, `order_percentage`, `line_amount`, `line_percentage`): which
  combinations exist is a list, and the absent fifth (an order voucher
  restricted to an article: an eligibility rule, a different feature) is refused
  by not being offered, which is §5.5 doing validation's work.
- **The line is chosen by naming the voucher on it** (the line collection
  carries a `voucher` reference): an article-hunting design cannot reach a
  custom line, which is exactly where a negotiated discount lands. The article
  reference survives as an optional restriction. *Free article* dissolves into
  "line mode, restricted, 100%": the article goes on the order like any other,
  at a chosen quantity, priced from the catalogue.
- **The optional reference flipped a guard off, and the rule was rehalved**: a
  *required* variant-scoped reference is `AvailableVariants`' to hide; an
  *optional* one is `AvailableFields`' to take away. All four kinds are offered
  to every customer (ten francs off one line needs no catalogue); the
  restriction simply is not a field a catalogue-less tenant has.
  `AvailableFields` learned collections, or every order line in a voucherless
  tenant would carry an empty picker.
- **The reduction is a derived column on the line** and the line total is what
  is left, so the recipient can check it. The derived flag protects it: here a
  column is exactly what needs protecting, because the customer owns and edits
  the row.
- **Two passes in `DerivesTotals`**: what each row charges, one ask of the seam,
  then reductions taken and money placed; same statements one level down.
  Before VAT in both modes; a line reduction joins exactly one rate by being
  part of it, so no apportionment. **One seam, one method still**: a line
  voucher is a second *answer* (`Discount` carries `off` and `perLine`) rather
  than a second source, because both modes are decided from one record in one
  save. When both are on one document, line reductions happen first and an order
  percentage is a percentage of what is left, the only non-arbitrary reading.
- **Bounds**: percentage capped at 100 by the field; a fixed amount larger than
  the line is floored at the line, not refused (twenty off a fifteen-franc line
  plainly means fifteen off).
- **One voucher on several lines is one use**: the count stays "documents
  carrying the voucher" (per-line counting spends a five-customer promotion on
  the first shopper, and needs a second counter that must agree with the
  first). **The diff is a set** of vouchers carried, before and after,
  reconstructed from `RecordChanges` (the rows are already written when the
  subscriber runs); moving a voucher between lines does nothing.
  `findChildren()` gained `includeDeleted` for the delete path, found by a test.
- **The mode is enforced at the write with a sentence naming the fix**; the
  deriver treats a misplaced voucher as worth nothing and never guesses (an
  order voucher dropped on a line is not "probably meant for the order"). It
  could not be a field constraint: whether this voucher may go on this line
  reads both records.
- Accepted cost, asserted: the Discount column names no module, so an
  installation with orders and no vouchers has a column nothing fills; the
  field editor removes it. Not in: stacking (the field is a single reference,
  so the question cannot be asked), a "best line" suggestion, a reason on the
  reduction.

### 5.26 A list a customer keeps, beside the fields that use it (XIV-127)

A `choice` field's own options are right for a closed set belonging to one
field and wrong for a set belonging to the business: bare strings, per-field
lists that drift, nothing that tidies `Zürich`/`Zurich`/`zurich`.

- **A core concept, not a module**: nobody browses a region, and a module may
  not depend on another module, so a list every module's fields point at can
  only live in core (§5.20's `Units` argument with rows). Two tenant tables,
  a screen of its own under `/lists`, admin-only on §5.4's reasoning.
- **An option on `choice`, not a field type, and §5.21's objection is
  answered**: nothing about the stored value changes (the escape clause §5.21
  wrote), almost nothing that follows differs, and the retroactivity that is
  real is refused: pointing a populated field at a list missing its values is
  counted first and refused with the values named, both directions. A type
  would have cost the point: unifying three existing "Region" fields would mean
  three fields recreated. `PointsAtAList` is the sixth capability; `needs()`
  became a list of questions each with answering options ("own options or a
  list" is two complete answers to one question), and `configurable()` requires
  every way of answering to be drawable.
- **Colour is one of eight**: exactly the tones Bootstrap redefines in dark
  mode, because a customer picks against a white page and the dark theme still
  has to read it; `text-bg-*` is not used (fixed brand colours are identical in
  both themes, which is not what surviving dark mode means). Icons are a
  bounded set of twelve (the name is interpolated into a class attribute, and a
  wrong free string renders nothing). Drawn through `value_badge(field, value)`,
  asking the field rather than switching on type.
- **Hierarchy is one level** (a parent must itself be a root, so cycles are
  impossible by construction), and **it changes the picker and nothing else**:
  filtering on "Switzerland" matches records holding Switzerland, because the
  count, the refusal and the merge all count exactly, and a filter counting
  differently is a second notion of "records holding this". Subtree matching,
  if wanted, is an operator of its own (§5.3).
- **Merge is XIV-91's backfill in a different hat**: rewrites a value on every
  record holding it, across modules and collections, irreversibly. It inherits
  the rules: a page of its own, a per-field plan that keeps the empty fields
  ("Orders → Region: none" is a fact about the change), confirmation required
  in the controller, **no history entry and `updated_at` untouched** (one
  administrative act, not four hundred edits). Reported figures come from the
  statements, not the plan.
- **Removals follow §5.4's rule**, with the reach named: the refusal lists
  fields as well as values and counts, because a shared list breaks records in
  modules the remover is not looking at. A list cannot be deleted while fields
  point at it; an entry with children cannot be removed while they sit under
  it. Retirement stays unbuilt (the both-mechanisms-at-once condition); the
  merge removes the commonest reason to ask. Provenance turned out to be a
  non-question here: nothing seeds a list, so every entry is the customer's.
- **The XIV-113 settlement**: a multi-value field points at the same
  `value_list` rows through the same `list` option and the same capability, and
  inherits every refusal for free, because `ValueListUsage` finds fields by
  capability rather than type name; `ValueListReachesEveryTypeTest` holds that
  promise over the container's registry and plants the violation.
- Not here: retirement; a list a module can seed (a blueprint writes
  definitions, and a list is not one); a module's own field pointed at a list
  (refused outright: a customer-maintained list defeats
  add-and-never-remove by a longer route); deeper nesting; colour anywhere but
  the record list and record page (documents and exports get `display()`).

### 5.27 A period as one thing, and two of them that cannot overlap (XIV-136)

Prompted by a care home on the previous engine: the one thing the engine could
not express is a period and the rule that two of them cannot overlap. That is
engine work by definition (every module would write the query differently, and
double booking cannot be prevented at all).

- **A type, not a pair of dates the engine understands as belonging together**:
  overlap prevention needs one value in one key to constrain.
  `DateRangeFieldType` and `DateTimeRangeFieldType`; `Period` is the value.
- **Stored as one ISO-8601 interval string** (`2026-08-01/2026-08-05`,
  `…/..` open-ended): a value that stops being a scalar changes the export,
  the history diff, the importer, `IS EMPTY` and the accessor at once; as a
  string none of them learned anything and it sorts by start date as text.
  **Two types rather than a precision option**, decided by the engine's own
  seam: `comparableSql()` is handed an accessor and nothing else, and a
  precision in options would be invisible exactly where the SQL must choose
  `daterange` or `tsrange`. It also keeps §5.4's no-type-change rule intact.
- **The end bound is exclusive, `[from, until)`**: the only bound meaning the
  same at both precisions, Postgres' own canonical form, and no ±1 anywhere.
  The surprise (a last day of the 5th is entered as the 6th) is paid where it
  is felt: help text on the field and boundary-day tests in both directions.
  Deliberately not an option: a per-customer bound would make two dates mean
  different things on two modules of one installation. (Contrast §5.19's
  voucher dates: two inclusive fields about when a rule applies; nothing
  overlaps there.)
- **Open at the end, never at the start, never by accident**: three controls
  (from, until, a no-end checkbox). A typed end beats the tick; a ticked blank
  is `[from, ∞)`; **a blank nobody ticked is refused**, because a control
  meaning opposite things depending on intent reports nothing.
- **The constraint is the only opinion**: the read-then-book window cannot be
  closed in PHP, so there is deliberately no application-level overlap check at
  all (a validator would catch almost everything and tempt the next reader into
  believing it were the rule). `EXCLUDE USING gist` is XIV-109's partial unique
  index with equality replaced by an operator, partial three times (soft-
  deleted, no scope, no period), built in the transaction that writes the
  definition.
- **What a period is exclusive within is a per-field option**
  (`exclusive_within`, the seventh capability, `ExcludesOverlaps`), and the
  option is the on switch: no overlap rule is a statement about a *resource*
  (one room, one carer), never about a module, and a project's durations should
  overlap freely. No composite scope (the cost is in the editor and the
  refusals, not Postgres), no "nowhere" (one-resource modules get a scope field
  with one option), not on collections (within-one-parent vs whole-table is
  §7's refused guess). Switching on over existing overlaps is refused with the
  **pairs** named, because an overlap is a relationship and neither record is
  wrong alone.
- **Index expressions must be immutable and date parsing is not**:
  `(data ->> 'stay')::date` cannot be indexed, so the ranges are built from
  integer offsets into the fixed-width ISO string by two SQL functions per
  tenant (`PeriodSql`). **The rule that costs**: Postgres never re-evaluates an
  index when a function changes, so editing `PeriodSql` in place leaves every
  constraint enforcing the old rule silently; a change there is a new migration
  redefining the functions and rebuilding every constraint. Datetimes are
  `tsrange` over naive UTC (everything stored is UTC, §8.4.4; `tstzrange`
  reads the session `TimeZone` and is not immutable).
- **One operator, `overlaps`** (`&&`), the only comparison about two stretches;
  the compiler applies the type's `comparableSql()` to the bound parameter as
  well as the column, so one definition of "a period" is used twice. A lone
  date reads as that whole day, making "which of these overlap today" a typable
  URL. Answered in the query, asserted by arrangement (the matching records are
  the oldest, off the first page).
- **Timezones**: a date range is zoneless; a datetime range is UTC read in the
  reader's zone through `ReaderTimezone`, delegating to `DisplayTimezone`
  (§8.4.4's second-reader warning). The zone decides which day a period is
  filed under, and a period whose ends fall on one day *in the reader's zone*
  is written with the date once.
- Not a booking module (no availability search, no calendar, no pricing); no
  existing data moved (converting two date fields is a §7.2 type change); demo
  data places each record in its own week and never generates an open end on an
  exclusive field, because one overlapping pair costs the whole generated
  tenant.

---

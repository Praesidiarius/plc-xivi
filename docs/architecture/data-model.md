## 5. Data model: metadata-driven, not EAV

- **Storage per entity**: fixed system columns (`id`, timestamps, `owner`,
  soft-delete), a JSONB `data` column, and column promotion per tenant
  (unbuilt).
- **The metadata layer is the product**: per-tenant definitions drive form,
  validation, storage and query from one source of truth.
- **Field types are a closed registry** of tagged services; a type owns
  storage, form type, constraints, normalizer and filter behaviour. **A widget
  is an option, not a type** (XIV-36): it is a type only if it changes what is
  stored, validated, filtered or exported.
- **Core asks, the application answers** (XIV-11): interfaces like
  `InstanceCurrency`, `DocumentContext`, `PdfConverter`,
  `RecordAccessProvider`, `InstanceRegion`, `ReaderTimezone`.
- **Money is a decimal string, never a float**, and the currency is not stored
  beside the amount (one per installation, or columns stop adding up).
- **Relations stay relational**: real tables, real foreign keys.
- **Records are not Doctrine entities**: DBAL, one repository that alone knows
  where a field physically lives. Module tables are created per customer by the
  installer, not by migrations.
- **Definitions are read fully loaded**: an object outliving its tenant context
  lazily loads on the wrong connection (§7.4).

### 5.1 Shapes: modules and collections

A **module** is browsable (URL, navigation, owner); a **collection** is
reachable only through its parent (parent id, edited in the parent's form,
soft-deleted with it). Everything else is shared: definitions, registry,
repository, validator, form builder.

- Collection rows may come in **kinds** (§5.5's mechanism): a button per kind,
  the kind fixed once the row exists, row fields rebuilt at PRE_SUBMIT from
  what was sent.
- Rows keep the customer's **order** (`position` in tens, renumbered per save);
  moving a row is not a change and writes no history.
- A field may be **inherited** from the referenced record: copied at write,
  never read through; a drift marker shows when the source moved.
- Three number kinds (`integer` counts, `decimal` measures, `currency` is
  money); places are the field's setting, clamped.
- Width is a proportion in twelfths; ordering plus width is the layout.
- A collection is deliberately **not** a link between modules (§7.6 is).
- **400 rows is the supported collection size**, refused at write, with
  `memory_limit` 256M so 400 renders (XIV-68: the cost is one Symfony form per
  row, not queries). The count is checked **before** the form is built
  (XIV-90). The post path costs about double the render per row; a save at 400
  sits near the allowance, accepted because real documents are far smaller.
  If it bites, move the limit, not the cap. The read view keeps no bound.

### 5.2 History is per module, and per action

- **One history table per module**, never one polymorphic table: v1's shared
  table could not carry a foreign key, which is what rotted it. A collection's
  events go in its parent's table.
- Fixed shape, installer-created. **One entry per action, not per row.**
- **`RecordWriter` is the only supported way to write a record**, or the first
  direct caller writes no history, and a history with holes is trusted anyway.
- Merge rules: first-from to last-to; unchanged values and empty diffs write
  nothing (`created`/`deleted` always write); deletes cascade silently.
- **Values only, no reads.** One exception, generating a document, admitted on
  three properties (rare, deliberate, attributable) the next candidate must
  argue too.
- Reading is paged, diffs behind `<details>`, ordered by `occurred_at` with id
  tiebreak.
- **It is also a time series** (XIV-121): the value chain per field is unbroken,
  which the price chart reads. Retention is therefore a trend decision, not a
  log decision, and stays open with partitioning and a
  do-not-record-this-value flag for sensitive types.

### 5.3 Asking questions: the query layer

- **Nothing from a user is concatenated**; an unanswerable filter raises.
- A condition on a collection is `EXISTS`, never `JOIN`; **sorting by a
  collection is refused**.
- The field type owns its comparisons; the compiler has no switch on type.
- **Every ordering ends on the record id** (LIMIT needs a total order).
- Not built: `OR` trees, keyset paging. Never built: expression filters
  (XIV-88; wrong side of the LIMIT and the counted total). `Search` is one
  closed disjunction over the shape's own fields.
- **Once a set is in hand, what it names is primed** (XIV-54): one
  `WHERE id IN (…)` per target module into the shared `ReferenceTargets` memo;
  query counts are asserted flat with `assertSame` between two sizes.

### 5.4 The metadata editor

**Definitions are cached once per tenant** (XIV-53), emptied whenever the
tenant context moves and by writers; deliberately no tenant key (keying makes
keeping entries across a switch look safe, and it is the hazard).

Admin-only; edits any shape. **The refusals are the feature**:

- no type change (§7.2; XIV-146 builds the conversion), no key change;
- a rule existing records would fail is **refused with the count and, for
  `unique`, the values**; ticking `unique` builds the index in the same
  transaction; relaxing is always allowed;
- a module's own fields cannot be removed;
- **what the form does not mention, it does not touch** (XIV-26).

**A type declares which options are the customer's to set**: one
option-to-capability list, resolved against the registry; `needs()` is a list
of questions each with its answering options, every way of answering drawable.
`EditorConfiguresEveryTypeTest` asserts the comparison over the container's
registry and plants a violation.

- **A module's own `choice` field: add and rename options, never remove**
  (variants and lifecycle states are options; nothing records which options the
  installer wrote). Such a field also cannot be pointed at a shared list.
- Option values derive from the first label (`AsciiSlugger`, `de`) and never
  move; renaming is free; a typo is permanent in a key nobody sees.
- **Removing an option records hold is refused, values named and counted.**
  Retirement (valid for holders, out of the picker) is deliberately unbuilt
  until it can arrive for field options and shared lists at once.
- **A reference's target is refused once anything points through it**
  (repointing leaves valid integers naming wrong records); repointable while
  empty; must name an installed module, checked on the write path; moving a
  target clears the `variant`.

**Sections** (XIV-119): a heading and a number, not a collection. Membership on
the field (`section_key`), the sections on the module row; **the form tree
stays flat** and the template draws the runs (grouping in the tree would reach
the submitted array and violation mapping). Ordering is set, not inferred;
ungrouped fields draw first, so existing shapes render unchanged; the record
page groups from the same method, tested against the form. Removing a section
removes the heading and nothing else. Not built: collapsing,
blueprint-declared sections, conditional visibility.

Also: **numbering is a page of its own** (§5.10); a field can be the record's
**title** (flag; the required-order guess is only fallback); a field can be
**listed** or not (a UI hint); **removing a field keeps the values**
(reversible by construction; purge is a separate explicit operation that does
not exist yet, so the UI says "hide").

### 5.5 Variants: one shape, more than one kind of record

**One module, not two**: two modules make every reference polymorphic, the
shape that cannot carry a foreign key. **The variants are the variant field's
options** (no second list to disagree); a field names the variants it belongs
to, empty meaning all. The form and validator see one variant; **storage is
untouched** (another variant's value stays and travels, because it is data).
Adding a record asks which kind first; lists name records, since the name is
what every row has.

### 5.6 Getting data out, and later back in

- One sheet per shape, child sheets carry `parent_id`; **headers are field
  keys**; **values are in storage form**; an export carries the list's query.
- Import validates through the existing validator and applies **in one
  transaction or not at all**, through `RecordWriter`. **A check is the import,
  rolled back** (same statements, one commits; savepoints nest it under the
  suite), the only way to catch what only a write can.
- `id` decides: numeric updates, unknown numeric refused, empty creates, any
  other string is a file-local name letting child sheets reference new parents.
- **A collection sheet speaks for the whole collection** (unlisted rows are
  removed, counted separately and said); no sheet leaves it alone; a child row
  naming an unlisted parent is refused.
- Round-trip tested (export, re-import, nothing changes). Read into memory;
  admin-only until §7.5.

### 5.7 Documents from templates (XIV-4)

A customer uploads a .docx with `[markers]` and downloads a filled copy as
.docx or PDF.

- **The marker list is derived from the customer's own definitions**, computed
  by one class that also fills it; values render through `display()`. Record
  markers per variant; general markers namespaced, declared by core as an
  interface.
- **Uploading and generating are two permissions.**
- Libraries by licence: `anourvalar/office` (MIT; PHPWord is LGPL) fills;
  Gotenberg converts (no pure-PHP library reads a .docx); core declares
  `PdfConverter`.
- **Templates live in the tenant database (bytea)**; the general attachments
  question is deliberately not answered here.
- One chooser page shown as a modal; a converter that is down offers the .docx.
- Word's `showingPlcHdr` flag is dropped (LibreOffice renders nothing for it):
  agreeing a file is valid is not agreeing what to draw.
- **Unknown tokens are reported, never refused and never silently blanked**
  (XIV-25): known markers fill (blank beats brackets), unknown print as typed
  with a sentence beside the template, re-checked on every render (templates go
  stale when fields are removed). One scanner, `TemplateTokens`. Unused markers
  are deliberately not reported.
- **`[tenant.logo]` draws a picture** (XIV-89): hand-written DrawingML replacing
  the marker's run; natural size capped to 40 × 20 mm, never enlarged; format
  decided by decoding bytes (PNG/JPEG, §8.6's licence reason); no logo means
  blank; runs after `RepeatingBlocks`, before the library. The reference list
  badges the marker's *kind*; the email page filters it out. Documents generate
  without a browser; the PDF is proved by searching for an image XObject both
  ways.

### 5.8 Lifecycles (XIV-14)

On symfony/workflow, adapted twice: **the state lives in an ordinary `choice`
field** (a lifecycle is a rule over a value, not a second store), and
definitions build from the blueprint, not `framework.workflows`. Component
traps: `StateMachine`, not `Workflow`; two `from` places mean "both at once",
so "from either" is two transitions sharing a name.

- Moving a record is **one permission per module**, not per transition.
- **A state can end editing**: the button is a courtesy, the URL is the rule.
- The timeline gets its own verb for a transition.
- **Customer-authored expressions are declined** (XIV-88), on two rules: not
  where the answer must become a WHERE clause (permissions, filters, validation
  counts), and not where the engine reads the thing rather than runs it
  (numbering patterns). Guards are PHP because modules write code, and the
  component earns its keep only where the author cannot ship PHP; a customer
  cannot author a lifecycle. symfony/workflow's own expression guards are not
  adopted (they need the event dispatcher these machines deliberately lack).
- **`TransitionGuard`** (XIV-110): one `refusal()` method returning null or a
  translation key, declared inline beside its transition, one guard per
  transition. **The button and the enforcement are the same predicate asked
  twice** (hiding a button is not enforcement); a refused move is shown with
  the module's reason from the module's catalogue. `GuardedRecord` hands over
  lazy, memoised rows; list pages never ask a lifecycle anything.
  `RecordWriter` still validates nothing: a guard conditions a move, not a
  write, and a record may be saved in a state a guard would refuse to leave.
  **Never guard the only way out** (confirm, not cancel). The order guard
  refuses no lines, not a zero total (an order can legitimately come to
  nothing).

### 5.9 Derived values, and the money that needed them (XIV-16)

`ValueDeriver` gets fields **and rows**, inside the save's transaction, with
**nothing to cancel with** (the non-veto half of §7.1). A collection missing
from the derivation is one the save is not touching (an empty list deletes). A
collection nobody can type into is derived and off the form, import, export and
history.

**The money model**: totals are **stored, not computed on read**; **VAT is per
line** (the article carries the rate, a number, empty meaning none; the
per-rate breakdown is a derived collection); **rounding has one answer** in
`Money\Amount` (line totals at two places as computed, VAT grouped per rate
before rounding, no five-rappen); **a discount is a line**, never a header
percentage. The live preview runs the **same derivers** (XIV-32), because a
browser copy of the rounding rule disagrees by a rappen on an invoice; the
preview validates nothing. A line contributes by having a price, not by kind.

**Inclusive prices** (XIV-116): the mode is **a value on the document,
materialised from the tenant's setting at creation** (per line makes one column
mean two things; per tenant alone reprices every draft when the setting moves).
Null at the top; an invoice takes the mode from its seeding order. The lines
sum to the gross, net per rate is `gross ÷ (1 + rate)` rounded once, and **tax
is the remainder: the gross the customer typed is the gross that prints**. The
general rule: *the typed figure is exact, the derived figure absorbs the
remainder*. No remainder crosses rates. Labels are whole sentences, because
`[vat_mode]` prints alone.

### 5.10 Document numbers (XIV-15, XIV-27)

Two fatal failures: a number that changes after being read out, and two
documents with one number.

- An **option on a text field** (`ORD-{year}-{number:4}`), customer-editable;
  `{year}` in the pattern *is* the annual reset; the width makes text sort.
- **The counter is a table; allocation is one statement**
  (`INSERT … ON CONFLICT DO UPDATE … RETURNING`), inside the save's transaction
  (a failed save returns its number). A `SEQUENCE` cannot reset yearly without
  a race and survives rollback.
- Gaps: assigned on first save; a deleted record keeps its number (soft delete
  keeps the document behind the gap). The year is the allocation year.
- **The pattern page renders the next number live** (XIV-27); the syntax stays
  a template because the engine reads it without running it. A counterless
  pattern is refused on the write path. Switching patterns draws from a
  different counter and resets nothing; the page says so. The counter is
  settable **forward only**, guarded inside the statement. Only `text` declares
  `Numbers`.
- **Turning numbering on backfills once, in creation order** (XIV-91), behind a
  confirmation required in the controller; deliberately not through
  `RecordWriter` (one administrative act, no `updated_at` stamp). Existing
  values are recognised by running the pattern backwards and the counter
  floored with `GREATEST`. **A numbered field becomes `derived`**; only a text
  field nothing else fills may be numbered. Turning it off keeps the numbers
  and the counter (re-enabling must not walk through printed numbers).
- **A numbered field is `unique`** (XIV-109): arithmetic is blind to strings
  arriving by other routes. The index build's `SHARE` lock is the first step,
  closing the backfill race entirely. Un-numbering leaves the field unique,
  which is exactly when the index earns its keep.

### 5.11 Repeating blocks in templates (XIV-17)

**A table row containing a collection marker draws itself once per row**,
multiplied before the library sees the document. No open/close syntax; the
`<w:tr>` is the unit Word gives a person. Kind layout is the template's
business (the engine must not decide how an invoice looks). Consecutive blocks
are a group, replaced in collection order. Emails deliberately differ
(§5.13.1): in Word the layout is the deliverable, in an email there is nothing
to take.

### 5.12 One record made from another (XIV-19)

`Seed` declares source module, linking field, and what to copy, rather than a
class per module pair.

- **Copied, never read through** (an invoice stays correct after the order
  changes); the link is kept for reporting.
- **Seeding is not saving**: a filled form somebody reads and edits.
- **What is left is read, not stored**: seeded rows record their source row;
  outstanding = quantity minus what every document took. The row reference is
  a plain number (a collection row is not a record).
- Read through the reader's own permissions; no grant reads as wholly
  uninvoiced, the safe direction.
- **A sent document is corrected by another document** (credit note): the
  lifecycle's lock doing the work.
- Line totals and subtotals are not copied (derived on save, so a partial
  invoice restates its own).
- The invoice module is a declaration and a translation file; the one engine
  cost was `LineTotals` moving to core (§1's rule).

### 5.13 Email templates, written here rather than uploaded (XIV-38)

An email has no layout worth designing, so a template is a form: name, subject,
Markdown body, in the customer's database. The base wrapper ships in code, one
of it. The markers are `DocumentMarkers`, the same vocabulary as documents.

- **Raw HTML is escaped at parse and the output sanitized**, two layers:
  substitution happens on the Markdown *source* before parsing
  (`html_input: escape`), closing the route from a record value into markup;
  the sanitizer covers what CommonMark itself emits
  (`[click](javascript:…)`).
- **The Markdown source is the plain-text part** (nothing fakes one by
  stripping tags).
- Own permission (`email_templates`). Core returns subject, HTML and text,
  never a `Mime\Email` (from and to are the application's facts).

#### 5.13.1 A collection in an email body (XIV-62)

**`[lines]` is one marker rendering the collection as a table whose shape ships
in code**: any tenant-built repeating construct would take back "no layout
worth designing", and the attachment carries the laid-out lines anyway. The
grammar extends the document's own production (`[lines:kind.col,col]`). **It
expands to Markdown before parsing**, keeping the escaping property and the
text part; pipes escaped after backslashes, newlines become spaces;
`TableExtension` named individually. Mixed kinds go in one table, collection
order, union of columns; the kind discriminator and inherited-source fields are
left out. The token panel says `[lines]` expands to a table; in a subject it is
blanked. One left-to-right `preg_replace_callback` pass that never re-reads its
output, collections asked first. The wrapper's one scoped `<style>` block
styles the bare table.

### 5.14 Sending one from a record (XIV-39)

One button and a chooser. The fast path's safety is that the **resolved
recipient and subject are on screen before the button**; the preview renders
the real message including who it appears to be from.

- **The module declares where the address lives** (`MailRecipient`, optionally
  `through:` one reference hop; guessing picks the wrong address for the first
  customer with a second email field). **One hop, a second impossible**: the
  seeded copy keeps one hop enough. The hop is read unscoped (XIV-42's split).
- **The address is shown, editable, never written back** (a send is not a
  correction to the contact). An unresolvable recipient offers no send and
  refuses a hand-posted one, with the reason in the customer's own field
  labels; an undeclared module draws nothing.
- The timeline stores the recipient as sent; **a failure is `email_failed`,
  its own verb**, written by the object performing the send (a caller's catch
  block forgets).
- **`send_email` is its own permission**, the one of the four that names a
  record and scopes.

### 5.15 The invoice goes with the mail (XIV-40)

- **Attaching means generating, so both grants**: `document` is asked again at
  attachment time, on the record (it scopes); a hidden picker is not a check.
- **One timeline entry; the attachment is a key on it** (two entries would be
  indistinguishable from a download plus an unrelated mail). The generator's
  `contents()` path does not announce, which also keeps previews out of
  history.
- **Failure is two-sided**: document could not be made means nothing sent,
  nothing written (no send happened); send failed means `email_failed` naming
  the attachment. The document is built before the `Mime\Email`, so half-
  success is impossible by construction.
- **A ceiling, 7 MiB** (`XIVI_MAX_ATTACHMENT_BYTES`), chosen against receiving
  servers (base64 inflation inside the common 10 MB limit): a bounce is the
  failure being bought off. Checked on the document; the preview generates too,
  so "converter down" and "too big" surface before the irreversible button.

### 5.16 When an invoice falls due, and what makes it late (XIV-67)

- **The due date is stored** (§5.9's argument on a date: terms change, and
  computing on read would retroactively re-deadline every past invoice). A
  deriver, **materialised at the transition to `sent`, into an empty field
  only**; no backfill, and a missing due date means **not overdue**.
- **Overdue is a read, not a fifth state** (nothing performs overdue; the
  calendar does, and there is no worker): `status = sent AND due_date < today`,
  strictly before, expressed once as both a record question and query
  conditions.
- **Three overriding layers**: tenant profile, contact, the invoice's own
  date. The invoice stores the date, not the days. **Null at the top, not
  thirty.** Days only (early-payment discounts change the money model; free
  text cannot be compared to a calendar; zero is a real term).
- Reading terms is one unscoped hop, once, at send. Not built: partial
  payments, credit notes, dunning letters.

### 5.17 Demo data a field can have an opinion about (XIV-24)

The generator knows types and bounds, not meaning. **One option, `samples`**,
read in one place (`FieldSampler`); a field declaring nothing behaves as
before, and `--seed` stays repeatable. Weighting is repetition; a `null` among
a required field's samples and the whole list on a unique field are dropped
(everything generated must pass validation). A sample is a literal, so
meaningless on a `reference`; no form control yet (§5.4's capability question);
no retro-fit.

- **Derived fields are skipped** (XIV-73): sampling them suppressed the
  fill-if-empty derivers and spent numbering nobody could give back; the suite
  asserts the counter equals the records generated.
- **Demo data drives the lifecycle**: the sampled state is a destination walked
  through legal transitions via the real `apply()`/`save()` (a tenant of drafts
  exercises nothing and has no due dates). A refusing guard stops the walk. The
  distribution is a `samples` list on the status field.

### 5.18 Follow-ups, and where §5.2's argument stops (XIV-80)

A follow-up: priority, due date, optional assignee, a thread of notes, a
reversible done stamp, about one record.

- **One shared pair of tables** (history grows unbounded and automatically; a
  follow-up is typed by a person). **`record_id` carries no foreign key and
  cannot**; the stated consequences: every read joins through and honours
  `deleted_at IS NULL` (second query), and a future hard purge must sweep
  `follow_up`. The note's FK is real and cascades.
- Users are denormalised even though they could join: a task outlives its
  assignee; deleting a user clears the assignment (listener) and keeps the
  creator.
- **Two verbs per module**: `follow_up_create` (notes included) and
  `follow_up_complete` (both directions; whoever can close can reopen).
- **A note belongs to its author, and nobody else may edit or delete it,
  including administrators**: the one place `ROLE_ADMIN` is not a bypass,
  expressed against the stored author id.
- **Assignment requires the assignee may view the record**, checked at
  assignment; **revoking later is not retroactive** (the residue shows without
  title or link).
- **`FollowUpManager` is the fourth enforcement seam** (imports and commands
  pass no route); own-records scoping honoured there.
- Per module, a boolean on `ModuleDefinition`, on by default, reversible; off
  hides and deletes nothing. `due_at`/`done_at` are `timestamptz`; `updated_at`
  is last thread activity; two indexes only.

**Dashboard widget** (XIV-81): three nesting lenses (today, week, all).
**Today includes today, deliberately the inverse of §5.16** (a note to
yourself due at 16:30 belongs on the 09:00 list; stated at the line). **No
lower bound**: overdue stays in every lens, sorted first. The week starts where
ICU says the reader's region starts it, boundaries in the reader's zone.
Record resolution is batched per module; a tenfold list must not move the query
count (asserted). A record the reader may not view shows without title or
link; a soft-deleted record's follow-up is excluded; a switched-off module
drops out. No unassigned lens (that is a queue).

**Record page** (XIV-82): the panel sits above the fields, full width, never on
lists. **The component owns no writes**: LiveActions dispatch through a
module-less endpoint invisible to `PermissionCoverageTest`, so the six
mutations are POST routes with `#[IsGranted]`. The archive is a counter.
**Done is a state**: only reopening is permitted while set, double-done is
refused (it would overwrite when it was settled), reopen is exempt. Checks are
on the write path, not only the panel. Priority renders through one Twig
function (`follow_up_tone()`), the mapping written in full. The first note is
what a follow-up is about; notes read oldest first. `datetime-local` input is
read in the reader's zone. Overdue styling is absent here (the widget owns
"due"). The controller checks the follow-up is on the record in the path
(404). `ENFORCED_WITHOUT_A_ROUTE` is gone; the next engine-first ticket puts it
back rather than weakening the check.

### 5.19 Vouchers, and a counter with a rule in it (XIV-103)

A code, a worth, two dates, a bounded number of uses. §5.25 reshaped the kinds
(four modes, `free_article` dissolved); everything structural here held.

- **The kind is a variant** (the fields depend on the answer; separate modules
  would make "which voucher" polymorphic).
- **`uses: [article]`, not `requires`**; `AvailableVariants` hides a variant
  whose required reference points at an uninstalled module.
- **Case is folded on the way in** (`toStorage()` uppercases), never compared
  case-insensitively: the unique index is case-sensitive and would disagree
  about what a duplicate is. A field type, not an option on `text`; the stated
  cost is that the global registry shows it in every tenant's dropdown.
- **Two alphabets**: typed codes wide (`GIVE-10`); generated ones Crockford,
  eight characters, `random_int()`, **not a sequence** (a guessable code is
  somebody else's money). Generating is leaving the box empty (a deriver fills
  once; not `SafeToPreview`).
- **Unlimited is nothing stored, not a sentinel** (a sentinel compares happily
  and fails the day a promotion outruns the constant); the floor is 1.
- **The counter is engine bookkeeping in its own table**, created by tenant
  migration (no FK possible; a counter row may outlive its voucher). Nobody can
  rename, edit or import over it. **One statement with the limit inside**
  (`ON CONFLICT … DO UPDATE … WHERE`), in the caller's transaction; no row back
  is the refusal.
- The race is proved with two real committed connections, and what that cannot
  prove a statement-count test does: a redemption is exactly one statement
  carrying the `ON CONFLICT` and the `WHERE`.
- **Expiry is a read** (§5.16's argument): empty dates are unbounded, both ends
  inclusive (two fields about when a rule applies, deliberately unlike §5.27).
  No "currently valid" filter: §7's `OR` limitation, and faking it would drop
  every voucher with no end date.

### 5.20 A unit belongs to the article (XIV-118)

- **A field on the article; the line takes a copy** through inheritance; the
  invoice gets it by seed (nothing on an invoice line reads through the
  article).
- **A shipped set of seven, seeded** at install: the only shape giving a new
  customer something on day one. Customers add options (XIV-144); a module's
  own options are never removed (§5.4); deliberately not a shared list
  (inherited copies compare against these values).
- **Values are keys (`m2`) living once in core** (`Units`; modules may not
  depend on each other); labels are the customer's, per module catalogue.
- **No plurals**: installed labels are the customer's text with no key left to
  look a plural up under, so a unit is a short invariant label in the plural
  form a line usually needs.
- Custom lines get the field; comment and subtotal lines are not offered one
  (no quantity; falls out of the variants). **Optional, load-bearing**:
  existing articles have none and must read as before; existing customers take
  it via §7.2.1, accepting a wrapped form row until they narrow the description
  (an upgrade only adds). No unit conversion (it changes what a price means).

### 5.21 A field with formatting in it (XIV-131)

**Markdown, because the dangerous half was already built** (§5.13's escaping
property); a rich-text editor storing HTML arrives on the far side of the
escaping decision.

- **A new type, not an option on `textarea`**: markup-bearing must be readable
  from the type (`HoldsFormattedText`, answered once, not a boolean every
  caller re-asks), and a checkbox is retroactive, reinterpreting every stored
  value silently. The accepted cost: no path from an existing `textarea` (a
  §7.2 conversion). **XIV-113 must follow this** (a `multiple` option would
  change the storage shape, retroactivity at its strongest).
- **One converter, one sanitizer policy** (`MarkdownRenderer` in core; policy
  is the strictest caller's; two configurations is how one ends up unescaped
  for a year).
- **The editor is a textarea and a preview**, free because the form already
  round-trips every keystroke; a form theme block off the type's prefix.
- **Per destination, decided**: record page gets rendered markup (the only
  place a record value reaches a page as markup, via the one renderer), full
  width; documents and list cells get the words with the marks taken off;
  exports get the source untouched; filters match the source. The plain
  rendering walks the parsed document, never regex-stripping, asserted by a
  sanitizer that throws.
- Not in: images (XIV-115), extensions beyond `TableExtension`, collaboration.
  The first blueprint consumer is the knowledge module, a new module, because
  installing does not retro-fit.

### 5.22 An internal knowledge base, and how much of it was already here (XIV-132)

A very simple wiki. **The engine work was none, and that is the finding**:
`packages/knowledge` is a blueprint, a translation file and a bundle. History
and system columns answer who/when (no `author` field on purpose: a forgotten
date field is a record confidently wrong about itself); §8.4 answers
write-vs-read; `contains` answers search; §5.21 answers the body.

- Topics are a plain seeded `choice`, §5.26's recorded first consumer, still
  not pointed at a list (a module's own field may not be); customers add
  topics; not required (writing at half past five should not be stopped by a
  dropdown).
- **Linking: no** (a link must earn its way in from both ends, and the
  read-back half is a panel on every record page in the system). Consequence:
  the first module that installs into a completely empty tenant.
- **Staleness beats emptiness as the failure mode**; the defence is the age on
  the screen, not a review date. The module list grew a **Changed** column
  beside Owner, on every module's list; system columns, neither sorts.
- **The search ceiling is stated and tested**: `ILIKE '%…%'`, no stemming,
  ranking or index; full text is a ticket (`tsvector` + GIN), and the test
  asserting the plural fails to find the singular is its red line.
- **Writing is granted deliberately** (default deny already does it): wrong
  knowledge acted on beats none, and the other direction cannot be undone.
- Not a wiki (no trees, cross-links, namespaces, revision diffs) and not
  customer-facing, kept by the declaration: no `mailRecipient`, so no send
  button exists to misuse.

### 5.23 A phone number is one number (XIV-114)

`phone` stores **E.164** via `toStorage()` and refuses what it cannot read; the
form, importer and query compiler cannot disagree because none has an opinion.
Consequences taken: `unique` works; old-data imports will refuse rows; the
library's metadata moves, so updates change acceptability in both directions
(nothing revalidates on read: a stored number is a fact about a customer).

- **The country comes from the existing chain** (`InstanceRegion` delegating to
  `FormattingLocale`); **the person is deliberately not in the parsing chain**
  (asking who is looking would store the same digits as two customers); display
  takes the opposite rule (national where local to the reader).
- A per-field country override (`AssumesACountry`): decides how the next value
  is read, rewrites nothing.
- **Extensions are refused** (E.164 has no room and `format(E164)` drops them
  silently, asserted); a second field is the answer.
- The lite build (2.8 MB vs 25 MB); the first Apache-2.0 production dependency,
  with its notice section. Contact's blueprint declares it for new installs
  only (§6.1; converting existing tenants is XIV-146). Nothing is sent to a
  number.

### 5.24 A voucher on an order (XIV-104)

**A discount is a derived value, and derived values are the engine's**: it
lives in the deriver's path, never written by hand. It applies **before VAT**
and **is its own line** (this governs the order mode; §5.25's line mode
reduces the line). **Every kind is a line**, so nothing downstream knows which
kind it was, and the document shows the quoted lines with the discount stated
separately.

- **Mixed rates: one discount line per rate**, pro rata on each rate's net,
  **the last line taking the balance** (XIV-116's remainder rule); no remainder
  crosses a rate. Inclusive VAT needed no case. A voucher worth more than the
  order is capped by it.
- **One deriver, one seam**: deriver order is deliberately unspecified, and
  discount-vs-totals have a strict mutual order, so `Money\DocumentDiscounts`
  is a one-method seam core defines and the voucher package implements, with no
  voucher vocabulary in core. The package finds the order's voucher field by
  reading the shape for a reference into `voucher`. Three answers: null (not
  mine), empty (worth nothing today), a discount; collapsing the first two
  breaks copied invoice lines or leaves removed discounts forever.
- **The engine owns the generated lines**: the deriver rewrites them each save
  reusing ids, the form draws them disabled, the kind is not offered. Removing
  them from the form entirely would churn ids and flood the timeline.
- **Stored, not re-read**: deleting the voucher changes nothing on the order;
  the deriver recomputes on save while the lifecycle has not locked the record,
  the same window every derived figure has. An unreadable voucher leaves the
  lines alone.
- **Redemption is a subscriber on `RecordChanged`, inside the writer's
  transaction**: taken at commit (the live form re-derives per keystroke), a
  failed save takes nothing, a refusal takes the save down. **The count is the
  number of documents carrying the voucher**: un-naming and deleting give the
  use back; a cancelled order keeps its use (locked, still carrying it), the
  one imperfect edge.
- **Refusals name which** of four situations, via `RecordRefused` (the missing
  half of §7.1's subscriber question, shaped like `DuplicateValue`: names the
  field, lands on the control with the form intact). The deriver still cannot
  refuse. Validity is checked once, at take; deliberately no transition guard.
- **The field exists only where both modules do** (`Module\AvailableFields`): a
  definition that does not exist is invisible everywhere at once. The upgrade
  offer asks the same question; `ModuleInstallOrder` follows `uses` edges
  within one requested set. Narrowed to unscoped references (variant-scoped is
  `AvailableVariants`' business).
- The invoice gains the `discount` kind as an ordinary kind (copies stay
  editable: what to bill is decided on the invoice). Open, its own ticket
  (XIV-147): a partial invoice takes the whole discount. The discount line
  appears at first save; the totals follow live.

### 5.25 Two ways to apply a voucher (XIV-122)

**Order mode adds its own line; line mode reduces the chosen line.** Not a
tension with §5.24: its rule governs the mode where no line exists for the
money to belong to.

- **Mode and kind are one variant field with four options**; the absent fifth
  (an order voucher restricted to an article: an eligibility rule, a different
  feature) is refused by not being offered.
- **The line is chosen by naming the voucher on it** (a reference on the line
  collection): article-hunting cannot reach a custom line, which is where a
  negotiated discount lands. The article reference survives as an optional
  restriction; *free article* dissolves into "line mode, restricted, 100%".
- The now-optional reference re-split the hiding rule: a **required**
  variant-scoped reference is `AvailableVariants`' to hide, an **optional** one
  is `AvailableFields`' to take away; `AvailableFields` learned collections, or
  voucherless tenants' order lines would carry an empty picker.
- **The reduction is a derived column on the line**, and the line total is what
  is left, so the recipient can check it; the derived flag protects the column
  (the customer owns the row).
- **Two passes in `DerivesTotals`** (what each row charges, one ask of the
  seam, reductions taken); before VAT in both modes; a line reduction joins
  exactly one rate. Still **one seam, one method**: a line voucher is a second
  *answer* (`off` and `perLine` on one `Discount`), because both modes are
  decided from one record in one save. Line reductions happen first; an order
  percentage is a percentage of what is left.
- Bounds: percentage capped at 100; an amount larger than the line is floored
  at the line, not refused (twenty off a fifteen-franc line means fifteen).
- **One voucher on several lines is one use**: the count stays "documents
  carrying the voucher" (per-line counting spends a five-customer promotion on
  one shopper and needs a second counter that must agree with the first).
  **The diff is a set** of carried vouchers, reconstructed from
  `RecordChanges`; moving a voucher between lines does nothing.
  `findChildren()` gained `includeDeleted` for the delete path.
- **Misplacement is refused at the write with a sentence naming the fix**; the
  deriver treats it as worth nothing and never guesses. It could not be a field
  constraint (it reads both records).
- Accepted and asserted: the Discount column exists in voucherless
  installations (the field editor removes it). Not in: stacking (the field is
  a single reference, so the question cannot be asked), best-line suggestions,
  a reason on the reduction.

### 5.26 A list a customer keeps, beside the fields that use it (XIV-127)

A `choice` field's own options are right for a closed per-field set and wrong
for a set belonging to the business (bare strings, per-field drift, nothing
tidies `Zürich`/`Zurich`).

- **A core concept, not a module**: nobody browses a region, and a module may
  not depend on a module, so a list every module points at can only live in
  core. Two tenant tables, a `/lists` screen, admin-only.
- **An option on `choice`, not a type**, inside §5.21's own escape clause:
  nothing about the stored value or its escaping changes, and the real
  retroactivity (pointing a populated field at a list missing its values) is
  **refused with the values named**, both directions. A type would have cost
  the point: unifying three existing Region fields. `PointsAtAList` fits
  XIV-144's shape; `needs()` became questions-with-answers ("own options or a
  list"), every answer drawable.
- **Colour is one of eight**: exactly the tones Bootstrap redefines in dark
  mode (a customer picks against a white page; the dark theme must still read
  it); `text-bg-*` is not that. Icons are a bounded twelve (the name lands in a
  class attribute). Drawn through `value_badge(field, value)`.
- **Hierarchy is one level** (a parent must be a root: no cycles by
  construction) **and changes the picker and nothing else**: filters stay
  exact, because the count, the refusal and the merge count exactly; subtree
  matching would be an operator of its own.
- **Merge is XIV-91's backfill in a different hat**: irreversible rewrite
  across modules and collections, so it inherits the rules: a page of its own,
  a per-field plan keeping the empty fields, confirmation required in the
  controller, **no history entry and `updated_at` untouched**, figures reported
  from the statements.
- **Removals follow §5.4's rule with the reach named** (fields as well as
  values and counts: a shared list breaks records the remover is not looking
  at). A list cannot be deleted while fields point at it; a parent entry cannot
  go while children sit under it. Retirement stays unbuilt (both mechanisms at
  once, or one picker can and one cannot); provenance is a non-question here
  (nothing seeds a list).
- **The XIV-113 settlement**: a multi-value field points at the same rows
  through the same option and capability, inheriting every refusal, because
  `ValueListUsage` finds fields by capability; a registry test holds the
  promise and plants the violation.
- Not here: retirement, module-seeded lists (a blueprint writes definitions and
  a list is not one), a module's own field pointed at a list, deeper nesting,
  colour beyond the record list and page.

### 5.27 A period as one thing, and two of them that cannot overlap (XIV-136)

The one thing the engine could not express for a care home or hotel: a period,
and the rule that two cannot overlap. Engine work by definition.

- **A type, not a pair of dates** (overlap prevention needs one value in one
  key to constrain): `DateRangeFieldType`, `DateTimeRangeFieldType`, value
  `Period`.
- **Stored as one ISO-8601 interval string** (`2026-08-01/2026-08-05`,
  `…/..`): a non-scalar value changes the export, diff, importer, `IS EMPTY`
  and accessor at once; a string changes none and sorts by start. **Two types
  rather than a precision option**: `comparableSql()` sees no options, exactly
  where the SQL must choose `daterange` or `tsrange`; and §5.4's
  no-type-change rule stays intact.
- **The end bound is exclusive, `[from, until)`**: the only bound meaning the
  same at both precisions, Postgres' canonical form, no ±1. The surprise (a
  last day of the 5th is entered as the 6th) is paid in help text and boundary
  tests, and is deliberately not an option. (§5.19's voucher dates are two
  inclusive fields about a rule; nothing overlaps there.)
- **Open at the end only, never by accident**: a no-end checkbox; a typed end
  beats the tick; **a blank nobody ticked is refused** (a control meaning
  opposite things by intent reports nothing).
- **The constraint is the only opinion**: the read-then-book window cannot be
  closed in PHP, so there is deliberately no application-level check at all (a
  validator would tempt readers into believing it were the rule).
  `EXCLUDE USING gist`, partial three times (soft-deleted, no scope, no
  period), built in the definition's transaction.
- **What a period is exclusive within is a per-field option and the on switch**
  (`exclusive_within`, `ExcludesOverlaps`): no-overlap is a statement about a
  resource, never a module, and unscoped periods overlap freely. No composite
  scope, no "nowhere" (a one-resource module gets a scope field with one
  option), not on collections (§7's within-parent/whole-table refusal).
  Switching on over existing overlaps is refused with the **pairs** named (an
  overlap is a relationship; neither record is wrong alone).
- **Index expressions must be immutable and date parsing is not**: the ranges
  are built from integer offsets into the ISO string by two per-tenant SQL
  functions (`PeriodSql`). **The rule that costs**: Postgres never re-evaluates
  an index over a changed function, so a change there is a new migration
  redefining the functions and rebuilding every constraint. Datetimes are
  `tsrange` over naive UTC (`tstzrange` reads the session zone and is not
  immutable).
- **One operator, `overlaps`** (`&&`); the compiler applies `comparableSql()`
  to the bound parameter as well as the column, so one definition of a period
  is used twice; a lone date reads as that whole day. Asserted in-query by
  arrangement (the matches are off the first page).
- **Timezones**: date ranges are zoneless; datetime ranges are UTC read through
  `ReaderTimezone`, delegating to `DisplayTimezone`; the reader's zone decides
  which day a period is filed under.
- Not a booking module; no existing data moved (converting two date fields is a
  §7.2 type change); demo data places each record in its own week and never
  generates an open end on an exclusive field.

---

## 5. Data model: metadata-driven, not EAV

- **Storage per entity**: fixed system columns (`id`, timestamps, `owner`,
  soft-delete), a JSONB `data` column, and column promotion per tenant
  (unbuilt).
- **The metadata layer is the product.** Per-tenant definitions drive form,
  validation, storage and query from one source of truth.
- **Field types are a closed registry** of tagged services. A type owns
  storage, form type, constraints, normalizer and filter behaviour. **A widget
  is an option, not a type** (XIV-36): it earns a type only if it changes what
  is stored, validated, filtered or exported.
- **Core asks, the application answers** (XIV-11), through interfaces like
  `InstanceCurrency`, `DocumentContext`, `PdfConverter`,
  `RecordAccessProvider`, `InstanceRegion` and `ReaderTimezone`.
- **Money is a decimal string, never a float**, and the currency is not stored
  beside the amount. One currency per installation, or columns stop adding up.
- **Relations stay relational**: real tables, real foreign keys.
- **Records are not Doctrine entities.** They go through DBAL, in one
  repository that alone knows where a field physically lives. The installer
  creates module tables per customer; migrations do not.
- **Definitions are read fully loaded**, because an object outliving its
  tenant context lazily loads on the wrong connection (§7.4).

### 5.1 Shapes: modules and collections

A **module** is browsable, with a URL, a navigation entry and an owner on its
rows. A **collection** is reachable only through its parent: parent id instead
of owner, edited in the parent's form, soft-deleted with it. Everything else
is shared, from definitions and registry to repository, validator and form
builder.

- Collection rows may come in **kinds** (§5.5's mechanism). Adding a row is a
  button per kind, the kind is fixed once the row exists, and row fields are
  rebuilt at PRE_SUBMIT from what was sent.
- Rows keep the customer's **order** (`position` in tens, renumbered per
  save). Moving a row is not a change to it and writes no history.
- **The order is dragged, not typed** (XIV-165). Typing a number beat move-up
  and move-down only while each press was a form submission; §8.3 spent that
  reason, so the arrange page moves rows and derives the tens from where they
  land. The number stayed, hidden: one save, `updateField()` per field, every
  §5.4 refusal intact, and no write per drop. A field dropped under a heading
  joins that section; a heading does not move, because section order is the
  module's row and this form writes fields. **Dragging is never the only
  way**: the same move is two buttons, keyboard-reachable and announced.
- A field may be **inherited** from the referenced record: copied at write,
  never read through, with a drift marker when the source moves on.
- Three number kinds: `integer` counts, `decimal` measures, `currency` is
  money. Decimal places are the field's setting, clamped rather than refused.
- Width is a proportion in twelfths, and ordering plus width is the layout.
- A collection is deliberately **not** a link between modules; §7.6 is.
- **400 rows is the supported collection size**, refused at write, with
  `memory_limit` at 256M so 400 renders. XIV-68 measured the cost as one
  Symfony form per row, not queries. XIV-90 moved the check to **before** the
  form is built, because a cap enforced by first doing the forbidden thing is
  not a cap. The post path costs about double the render per row, and a save
  at 400 sits near the allowance; that is accepted, because real documents are
  far smaller. If it bites, move the limit, not the cap. The read view keeps
  no bound.

### 5.2 History is per module, and per action

- **One history table per module**, never one polymorphic table. v1's shared
  table could not carry a foreign key, and that is what rotted it. A
  collection's events go in its parent's table.
- Fixed shape, created by the installer. **One entry per action, not per row.**
- **`RecordWriter` is the only supported way to write a record.** Otherwise
  the first direct caller writes no history, and a history with holes is
  trusted anyway.
- Merge rules: a twice-changed field records first-from to last-to, unchanged
  values and empty diffs write nothing, `created` and `deleted` always write,
  and deletes cascade silently.
- **Values only, no reads.** One exception, generating a document, admitted on
  three properties (rare, deliberate, attributable) that the next candidate
  must argue too.
- Reading is paged, diffs sit behind `<details>`, and ordering is
  `occurred_at` with the id breaking ties.
- **It is also a time series** (XIV-121). The value chain per field is
  unbroken, which is what the price chart reads. Retention is therefore a
  trend decision, not a log decision, and it stays open along with
  partitioning and a do-not-record-this-value flag for sensitive types.

### 5.3 Asking questions: the query layer

- **Nothing from a user is concatenated**, and an unanswerable filter raises.
- A condition on a collection is `EXISTS`, never `JOIN`. **Sorting by a
  collection is refused.**
- The field type owns its comparisons; the compiler has no switch on type.
- **Every ordering ends on the record id**, because a LIMIT needs a total
  order.
- Not built: `OR` trees and keyset paging. Never built: expression filters
  (XIV-88), which run on the wrong side of the LIMIT and the counted total.
  `Search` is one closed disjunction over the shape's own fields.
- **Once a set is in hand, what it names is primed** (XIV-54): one
  `WHERE id IN (…)` per target module fills the shared `ReferenceTargets`
  memo. Query counts are asserted flat with `assertSame` between two sizes, so
  growth fails rather than slows.

### 5.4 The metadata editor

**Definitions are cached once per tenant** (XIV-53). The cache empties
whenever the tenant context moves, and writers empty it too. There is
deliberately no tenant key, because keying would make keeping entries across a
switch look safe, and that is the hazard.

Admin-only; edits any shape. **Three doors, not one page** (XIV-163): add a
field, edit a field, arrange the form, one set per shape. Adding asks the
*type* first, from the registry, then draws that type's own options and nothing
else. Editing lists the shape's fields and draws the same per-type form for
one. Arranging is order, width, section and `listed` for every field at once,
and is where section management is reached. One form carrying every type's
options is the shape XIV-144 fought, so the combined form is deleted rather
than kept beside these: two editors drift with every option added after today.
**The doors are presentation, and the refusals never were.**

**The refusals are the feature:**

- no key change, because a key is where the value lives;
- the editor refuses a rule existing records would fail, counting first and
  naming the values for `unique`; ticking `unique` builds the index in the
  same transaction, and relaxing is always allowed;
- a module's own fields cannot be removed;
- **what the form does not mention, it does not touch** (XIV-26).

**A type declares which options are the customer's to set**: one
option-to-capability list, resolved against the registry, where a new option
costs an interface, a line in the list and a control. **The list has no
exceptions since XIV-163**, which promoted `max_length`, `min` and `max` out
of a second list drawn for every field: a form per type makes "the options this
type declares" the whole content of a form, so a setting outside the
declarations would have to be on every form or on none. `needs()` is a list of
questions, each with its answering options, and every way of answering must be
drawable **on that type's own add form**, where it is asked rather than refused
after submit. `EditorConfiguresEveryTypeTest` asserts the comparison over the
container's registry, opens every type's form to see the controls are really
there, and plants a violation.

- **A module's own `choice` field: add and rename options, never remove.**
  Variants and lifecycle states are options, and nothing records which options
  the installer wrote. Such a field also cannot be pointed at a shared list.
- Option values derive from the first label (`AsciiSlugger`, `de`) and never
  move. Renaming is free, and a typo is permanent in a key nobody sees.
- **Removing an option records hold is refused, with the values named and
  counted.** Retirement, valid for holders and out of the picker, is
  deliberately unbuilt until it can arrive for field options and shared lists
  at once.
- **A reference's target is refused once anything points through it**, because
  repointing leaves valid integers naming wrong records. It is repointable
  while empty, must name an installed module (checked on the write path), and
  moving a target clears the `variant`.

**Sections** (XIV-119) are a heading and a number, not a collection. The
membership lives on the field (`section_key`), the sections on the module row.
**The form tree stays flat** and the template draws the runs, because grouping
in the tree would reach the submitted array and the violation mapping.
Ordering is set, not inferred; ungrouped fields draw first, so existing shapes
render unchanged; the record page groups from the same method, tested against
the form. Removing a section removes the heading and nothing else. Not built:
collapsing, blueprint-declared sections, conditional visibility.

**A field's type can change, on a page of its own** (XIV-146, §7.2). Every value
is read by the type moving in, behind a dry run over the whole column: all rows
survive and it happens, one fails and the whole change is refused with the values
named, and emptying those rows is the customer's explicit second choice. Each
converted or emptied value is written to the record's history first, so the old
spelling outlives the column. Reversibility is stated before it runs, computed by
reading the value back. A `unique` breach is reported rather than attempted; a
`derived` field is refused; a module that derives re-derives what was touched. A
shipped module may not request one, because §7.2.1's every write is an insert and
this restates what somebody typed. Not offered: converting *into* a type that has
to be told what its values mean, `choice` and `reference`.

Also here: **numbering is a page of its own** (§5.10). A field can be the
record's **title** (a flag; the required-order guess is only the fallback). A
field can be **listed** or not, a UI hint, set on the arrange page with the
rest of what is cross-field. **Removing a field keeps the values**, reversible
by construction; purge is a separate explicit operation that does not exist
yet, so the UI says "hide".

### 5.5 Variants: one shape, more than one kind of record

**One module, not two**, because two modules make every reference polymorphic,
the shape that cannot carry a foreign key. **The variants are the variant
field's options**, so there is no second list to disagree. A field names the
variants it belongs to, and empty means all. The form and validator see one
variant; **storage is untouched**, since another variant's value is somebody's
data and travels. Adding a record asks which kind first. Lists name records,
because the name is what every row has.

### 5.6 Getting data out, and later back in

- One sheet per shape, child sheets carrying `parent_id`. **Headers are field
  keys**, **values are in storage form**, and an export carries the list's
  query.
- Import validates through the existing validator and applies **in one
  transaction or not at all**, through `RecordWriter`. **A check is the
  import, rolled back**: same statements, one commits, savepoints nest it
  under the suite. It is the only way to catch what only a write can.
- `id` decides. Numeric updates, unknown numeric is refused, empty creates,
  and any other string is a file-local name that lets child sheets reference
  parents created in the same file.
- **A collection sheet speaks for the whole collection.** An unlisted row is
  removed, counted separately and said in words; no sheet leaves the
  collection alone; a child row naming an unlisted parent is refused.
- The two halves are tested against each other: export, re-import, nothing
  changes. The file is read into memory, and importing is admin-only until
  §7.5.

### 5.7 Documents from templates (XIV-4)

A customer uploads a .docx with `[markers]` and downloads a filled copy as
.docx or PDF.

- **The marker list is derived from the customer's own definitions**, computed
  by one class that also fills it, with values rendered through `display()`.
  Record markers exist per variant; general markers are namespaced and
  declared by core as an interface.
- **Uploading and generating are two permissions.**
- The libraries were decided by licence. `anourvalar/office` (MIT; PHPWord is
  LGPL) fills the file. Gotenberg converts, because no pure-PHP library reads
  a .docx. Core declares `PdfConverter`.
- **Templates live in the tenant database (bytea)**; the general attachments
  question is deliberately not answered here.
- One chooser page, shown as a modal. A converter that is down offers the
  .docx and says so.
- The generator drops Word's `showingPlcHdr` flag, because LibreOffice renders
  nothing for it. Agreeing that a file is valid is not agreeing what to draw.
- **Unknown tokens are reported, never refused and never silently blanked**
  (XIV-25). Known markers fill, because blank beats brackets; unknown ones
  print as typed, with a sentence beside the template. The check runs on every
  render of the templates page, because a template goes stale when a field is
  removed. One scanner, `TemplateTokens`. Unused markers are deliberately not
  reported.
- **`[tenant.logo]` draws a picture** (XIV-89): hand-written DrawingML
  replacing the marker's run, natural size capped to 40 × 20 mm and never
  enlarged, format decided by decoding the bytes (PNG and JPEG, §8.6's licence
  reason). No logo means blank. The pass runs after `RepeatingBlocks` and
  before the library. The reference list badges the marker's *kind*, and the
  email page filters it out. Documents generate without a browser, and the PDF
  is proved by searching for an image XObject in both directions.

### 5.8 Lifecycles (XIV-14)

On symfony/workflow, adapted twice. **The state lives in an ordinary `choice`
field**, because a lifecycle is a rule over a value, not a second store. And
definitions build from the blueprint, not `framework.workflows`. Component
traps: `StateMachine`, not `Workflow`, and two `from` places mean "both at
once", so "from either" is two transitions sharing a name.

- Moving a record is **one permission per module**, not per transition.
- **A state can end editing.** The button is a courtesy; the URL is the rule.
- The timeline gets its own verb for a transition.
- **Customer-authored expressions are declined** (XIV-88), on two rules: not
  where the answer must become a WHERE clause (permissions, filters,
  validation counts), and not where the engine reads the thing rather than
  runs it (numbering patterns). Guards are PHP because modules write code, and
  the component earns its keep only where the author cannot ship PHP; a
  customer cannot author a lifecycle. symfony/workflow's own expression guards
  are not adopted, because they need the event dispatcher these machines
  deliberately lack.
- **`TransitionGuard`** (XIV-110): one `refusal()` method returning null or a
  translation key, declared inline beside its transition, one guard per
  transition. **The button and the enforcement are the same predicate asked
  twice**, because hiding a button is not enforcement. A refused move is shown
  with the module's reason, from the module's catalogue. `GuardedRecord` hands
  the guard lazy, memoised rows, and list pages never ask a lifecycle
  anything. `RecordWriter` still validates nothing: a guard conditions a move,
  not a write, and a record may be saved in a state a guard would refuse to
  leave. **Never guard the only way out** (confirm, not cancel). The order
  guard refuses no lines, not a zero total, because an order can legitimately
  come to nothing.

### 5.9 Derived values, and the money that needed them (XIV-16)

`ValueDeriver` gets fields **and rows**, inside the save's transaction, with
**nothing to cancel with**; that is the non-veto half of §7.1. A collection
missing from the derivation is one the save is not touching, since an empty
list means "delete the rows". A collection nobody can type into is derived and
stays off the form, the import, the export and the history.

**The money model.** Totals are **stored, not computed on read**. **VAT is per
line**: the article carries the rate, a number, with empty meaning none, and
the per-rate breakdown is a derived collection. **Rounding has one answer** in
`Money\Amount`: line totals round at two places as computed, VAT groups per
rate before rounding, and there is no five-rappen rounding. **A discount is a
line**, never a header percentage. The live preview runs the **same derivers**
(XIV-32), because a browser copy of the rounding rule disagrees by a rappen on
an invoice; the preview validates nothing. A line contributes by having a
price, not by kind.

**Inclusive prices** (XIV-116). The mode is **a value on the document,
materialised from the tenant's setting at creation**. Per line would make one
column mean two things, and per tenant alone would reprice every draft when
the setting moves. Null sits at the top, and an invoice takes the mode from
its seeding order. The lines sum to the gross, net per rate is
`gross ÷ (1 + rate)` rounded once, and **tax is the remainder, so the gross
the customer typed is the gross that prints**. The general rule: *the typed
figure is exact, and the derived figure absorbs the remainder.* No remainder
crosses rates. The labels are whole sentences, because `[vat_mode]` prints
alone.

### 5.10 Document numbers (XIV-15, XIV-27)

Two fatal failures: a number that changes after being read out, and two
documents with one number.

- An **option on a text field** (`ORD-{year}-{number:4}`), customer-editable.
  `{year}` in the pattern *is* the annual reset, and the width is what makes
  text sort.
- **The counter is a table, and allocation is one statement**
  (`INSERT … ON CONFLICT DO UPDATE … RETURNING`), inside the save's
  transaction, so a failed save returns its number. A `SEQUENCE` cannot reset
  yearly without a race and survives rollback.
- Gaps are decided. The number is assigned on first save, and a deleted record
  keeps its number, because soft delete keeps the document behind the gap. The
  year is the allocation year.
- **The pattern page renders the next number live** (XIV-27). The syntax stays
  a template, because the engine reads it without running it. A counterless
  pattern is refused on the write path. Switching patterns draws from a
  different counter and resets nothing, and the page says so. The counter is
  settable **forward only**, guarded inside the statement. Only `text`
  declares `Numbers`.
- **Turning numbering on backfills once, in creation order** (XIV-91), behind
  a confirmation the controller requires, and deliberately not through
  `RecordWriter`: one administrative act, no `updated_at` stamp. Existing
  values are recognised by running the pattern backwards, and the counter is
  floored with `GREATEST`. **A numbered field becomes `derived`**, and only a
  text field nothing else fills may be numbered. Turning it off keeps the
  numbers and the counter, because a re-enable must not walk through printed
  numbers.
- **A numbered field is `unique`** (XIV-109), because arithmetic is blind to
  strings arriving by other routes. The index build's `SHARE` lock is the
  first step, which closes the backfill race entirely. Un-numbering leaves the
  field unique, which is exactly when the index earns its keep.

### 5.11 Repeating blocks in templates (XIV-17)

**A table row containing a collection marker draws itself once per row**,
multiplied before the library sees the document. There is no open/close
syntax; the `<w:tr>` is the unit Word gives a person. Kind layout is the
template's business, because the engine must not decide how an invoice looks.
Consecutive blocks are a group, replaced in collection order. Emails
deliberately differ (§5.13.1): in Word the layout is the deliverable, and in
an email there is nothing to take.

### 5.12 One record made from another (XIV-19)

`Seed` declares the source module, the linking field, and what to copy, rather
than a class per module pair.

- **Copied, never read through**, which is what keeps an invoice correct after
  the order changes. The link is kept for reporting.
- **Seeding is not saving.** What comes back is a filled form somebody reads
  and edits.
- **What is left is read, not stored.** Seeded rows record their source row,
  and outstanding is quantity minus what every document took. The row
  reference is a plain number, because a collection row is not a record.
- The reading goes through the reader's own permissions, and no grant reads as
  wholly uninvoiced, the safe direction.
- **A sent document is corrected by another document**, a credit note. That is
  the lifecycle's lock doing the work.
- Line totals and subtotals are not copied. They derive on save, so a partial
  invoice restates its own.
- The invoice module is a declaration and a translation file. The one engine
  cost was `LineTotals` moving to core, §1's rule in action.

**A discount is shared across the documents, not copied onto each of them**
([XIV-147]). §5.24's discount is a line and §5.25's is a column, and a copy of
either is right only for a bill for the whole order: a discount line has
quantity 1, so the first invoice took all of it and the rest took none, and a
line reduction is an amount on the line, so every partial bill copied the whole
of it and two of them came to twice the voucher.

- **Each document takes the share of what is left that matches what it bills,
  and the one that closes the source out takes the balance.**
  `share = (D − T) × L ÷ (S − B)`, over money for the order mode and over
  quantities for the line mode. The shares add back to the source's own
  discount exactly, on splits that do not divide, because the last document is
  handed the remainder rather than its own division — XIV-116's rule one
  document further out. Nothing can be over-applied either: what is left is the
  ceiling of every answer.
- **The generated rows are not seeded and do not draw down.** A row the source's
  engine wrote is skipped by `Seeder`, which also stops "1.00 left" appearing
  beside a voucher on the order's page.
- **This reads through, and that is the same reading as `outstanding`.** What is
  *agreed* stays copied; what is *left* was always read. It is not read from the
  voucher, which §5.12 forbids and [XIV-104] designed against: it is read from
  the discount the order stored.
- **The rows are the engine's on the target too.** The invoice names a
  `discountKind`, so the arithmetic owns those rows and the column beside them:
  the form draws them disabled, the kind is not offered, and a forged figure is
  restated. That was [XIV-104]'s protection arriving one document late.
- The three answers of `Discount` earn their keep here. An unreadable source
  says *nothing* and leaves the copies alone, so deleting an order does not
  restate the drafts billed from it; a document made from nothing says *worth
  nothing* and loses its rows.
- The share is worked out from every sibling document, **not** through the
  saver's own grants. The permission-scoped read above is right for an offer on
  a form and wrong for a figure that gets stored: money on a document must not
  depend on who pressed save.

### 5.13 Email templates, written here rather than uploaded (XIV-38)

An email has no layout worth designing, so a template is a form: name,
subject, Markdown body, in the customer's database. The base wrapper ships in
code, and there is one of it. The markers are `DocumentMarkers`, the same
vocabulary as documents.

- **Raw HTML is escaped at parse and the output sanitized**, two layers.
  Substitution happens on the Markdown *source* before parsing
  (`html_input: escape`), which closes the route from a record value into
  markup. The sanitizer covers what CommonMark itself emits, such as
  `[click](javascript:…)`.
- **The Markdown source is the plain-text part.** Nothing fakes one by
  stripping tags.
- Writing templates is its own permission (`email_templates`). Core returns
  subject, HTML and text, never a `Mime\Email`, because from and to are the
  application's facts.

#### 5.13.1 A collection in an email body (XIV-62)

**`[lines]` is one marker rendering the collection as a table whose shape
ships in code.** Any tenant-built repeating construct would take back "no
layout worth designing", and the attachment carries the laid-out lines anyway.
The grammar extends the document's own production
(`[lines:kind.col,col]`). **It expands to Markdown before parsing**, which
keeps the escaping property and the text part. Pipes are escaped after
backslashes, newlines become spaces, and `TableExtension` is named
individually. Mixed kinds go in one table, in collection order, with the union
of columns; the kind discriminator and inherited-source fields are left out.
The token panel says that `[lines]` expands to a table; in a subject it is
blanked. The substitution is one left-to-right `preg_replace_callback` pass
that never re-reads its output, with collections asked first. The wrapper's
one scoped `<style>` block styles the bare table.

### 5.14 Sending one from a record (XIV-39)

One button and a chooser. The fast path's safety is that the **resolved
recipient and subject are on screen before the button**, and the preview
renders the real message, including who it appears to be from.

- **The module declares where the address lives** (`MailRecipient`, optionally
  `through:` one reference hop), because guessing picks the wrong address for
  the first customer with a second email field. **One hop, and a second is
  impossible**: the seeded copy keeps one hop enough. The hop is read
  unscoped, XIV-42's split.
- **The address is shown, editable, and never written back**, because a send
  is not a correction to the contact. An unresolvable recipient offers no send
  and refuses a hand-posted one, with the reason in the customer's own field
  labels. A module that declared no recipient draws nothing.
- The timeline stores the recipient as sent. **A failure is `email_failed`,
  its own verb**, written by the object performing the send, because a
  caller's catch block forgets.
- **`send_email` is its own permission**, the one of the four that names a
  record and scopes.

### 5.15 The invoice goes with the mail (XIV-40)

- **Attaching means generating, so it takes both grants.** `document` is asked
  again at attachment time, on the record, since it scopes; a hidden picker is
  not a check.
- **One timeline entry, with the attachment as a key on it.** Two entries
  would be indistinguishable from a download plus an unrelated mail. The
  generator's `contents()` path does not announce, which also keeps previews
  out of history.
- **Failure is two-sided.** A document that could not be made sends nothing
  and writes nothing, because no send happened. A send that failed is
  `email_failed`, naming the attachment. The document is built before the
  `Mime\Email`, so half-success is impossible by construction.
- **A ceiling, 7 MiB** (`XIVI_MAX_ATTACHMENT_BYTES`), chosen against receiving
  servers: base64 inflation lands inside the common 10 MB limit, and a bounce
  is the failure being bought off. The check runs on the document, and the
  preview generates too, so "converter down" and "too big" surface before the
  irreversible button.

### 5.16 When an invoice falls due, and what makes it late (XIV-67)

- **The due date is stored**, §5.9's argument applied to a date. Terms change,
  and computing on read would retroactively re-deadline every past invoice. A
  deriver **materialises it at the transition to `sent`, into an empty field
  only**. There is no backfill, and a missing due date means **not overdue**.
- **Overdue is a read, not a fifth state.** Nothing performs overdue; the
  calendar does, and there is no worker. `status = sent AND due_date < today`,
  strictly before, expressed once as both a record question and query
  conditions.
- **Three overriding layers**: tenant profile, contact, then the invoice's own
  date. The invoice stores the date, not the days. **Null at the top, not
  thirty.** Days only, because early-payment discounts change the money model,
  free text cannot be compared to a calendar, and zero is a real term.
- Reading the terms is one unscoped hop, once, at send. Not built: partial
  payments, credit notes, dunning letters.

### 5.17 Demo data a field can have an opinion about (XIV-24)

The generator knows types and bounds, not meaning. **One option, `samples`**,
read in one place (`FieldSampler`). A field declaring nothing behaves as
before, and `--seed` stays repeatable. Weighting is repetition. A `null` among
a required field's samples and the whole list on a unique field are dropped,
because everything generated must pass validation. A sample is a literal, so
it is meaningless on a `reference`. There is no form control yet, §5.4's
capability question, and nothing retro-fits.

- **Derived fields are skipped** (XIV-73). Sampling them suppressed the
  fill-if-empty derivers and spent numbering nobody could give back; the suite
  asserts the counter equals the records generated.
- **Demo data drives the lifecycle.** The sampled state is a destination
  walked through legal transitions via the real `apply()` and `save()`,
  because a tenant of drafts exercises nothing and has no due dates. A
  refusing guard stops the walk. The distribution is a `samples` list on the
  status field.

### 5.18 Follow-ups, and where §5.2's argument stops (XIV-80)

A follow-up is a priority, a due date, an optional assignee, a thread of
notes, and a reversible done stamp, about one record.

- **One shared pair of tables.** History grows unbounded and automatically; a
  follow-up is typed by a person. **`record_id` carries no foreign key and
  cannot**, with two stated consequences: every read joins through and honours
  `deleted_at IS NULL` as a second query, and a future hard purge must sweep
  `follow_up`. The note's FK is real and cascades.
- Users are denormalised even though they could join, because a task outlives
  its assignee. Deleting a user clears the assignment through a listener and
  keeps the creator.
- **Two verbs per module**: `follow_up_create`, notes included, and
  `follow_up_complete` in both directions, because whoever can close can
  reopen.
- **A note belongs to its author, and nobody else may edit or delete it,
  including administrators.** The one place `ROLE_ADMIN` is not a bypass,
  expressed against the stored author id.
- **Assignment requires that the assignee may view the record**, checked at
  assignment. **Revoking later is not retroactive**, and the residue shows
  without title or link.
- **`FollowUpManager` is the fourth enforcement seam**, because imports and
  commands pass no route; own-records scoping is honoured there.
- Per module, a boolean on `ModuleDefinition`, on by default, reversible; off
  hides and deletes nothing. `due_at` and `done_at` are `timestamptz`,
  `updated_at` is last thread activity, and there are two indexes only.

**The dashboard widget** (XIV-81) has three nesting lenses: today, week, all.
**Today includes today, deliberately the inverse of §5.16**, because a note to
yourself due at 16:30 belongs on the 09:00 list; the disagreement is stated at
the line. **There is no lower bound**: overdue stays in every lens, sorted
first. The week starts where ICU says the reader's region starts it, with
boundaries drawn in the reader's zone. Record resolution is batched per
module, and a tenfold list must not move the query count, asserted. A record
the reader may not view shows without title or link, a soft-deleted record's
follow-up is excluded, and a switched-off module drops out. No unassigned
lens; that is a queue.

**The record page** (XIV-82). The panel sits above the fields, full width, and
never on lists. **The component owns no writes**: LiveActions dispatch through
a module-less endpoint invisible to `PermissionCoverageTest`, so the six
mutations are POST routes with `#[IsGranted]`. The archive is a counter.
**Done is a state**: while set, only reopening is permitted, double-done is
refused because it would overwrite when the item was settled, and reopen is
exempt. Checks live on the write path, not only in the panel. Priority renders
through one Twig function (`follow_up_tone()`), with the mapping written in
full. The first note is what a follow-up is about, and notes read oldest
first. `datetime-local` input is read in the reader's zone. Overdue styling is
absent here, because the widget owns "due". The controller checks that the
follow-up is on the record in the path and answers 404 otherwise.
`ENFORCED_WITHOUT_A_ROUTE` is gone; the next engine-first ticket puts it back
rather than weakening the check.

### 5.19 Vouchers, and a counter with a rule in it (XIV-103)

A code, a worth, two dates, and a bounded number of uses. §5.25 reshaped the
kinds into four modes and dissolved `free_article`; everything structural here
held.

- **The kind is a variant**, because the fields depend on the answer, and
  separate modules would make "which voucher" polymorphic.
- **`uses: [article]`, not `requires`.** `AvailableVariants` hides a variant
  whose required reference points at an uninstalled module.
- **Case is folded on the way in** (`toStorage()` uppercases), never compared
  case-insensitively, because the unique index is case-sensitive and would
  disagree about what a duplicate is. It is a field type rather than an option
  on `text`, and the stated cost is that the global registry shows it in every
  tenant's dropdown.
- **Two alphabets.** Typed codes are wide (`GIVE-10`); generated ones are
  Crockford's set, eight characters, from `random_int()`, and **not a
  sequence**, because a guessable code is somebody else's money. Generating
  means leaving the box empty; a deriver fills it once, and it is not
  `SafeToPreview`.
- **Unlimited is nothing stored, not a sentinel.** A sentinel compares happily
  and fails the day a promotion outruns the constant. The floor is 1.
- **The counter is engine bookkeeping in its own table**, created by a tenant
  migration; no foreign key is possible, so a counter row may outlive its
  voucher. Nobody can rename, edit or import over it. **One statement with the
  limit inside** (`ON CONFLICT … DO UPDATE … WHERE`), in the caller's
  transaction, and no row back *is* the refusal.
- The race is proved with two real committed connections, and what that cannot
  prove, a statement-count test does: a redemption is exactly one statement
  carrying both the `ON CONFLICT` and the `WHERE`.
- **Expiry is a read**, §5.16's argument. Empty dates are unbounded, and both
  ends are inclusive, two fields about when a rule applies, deliberately
  unlike §5.27's occupancy periods. There is no "currently valid" filter, §7's
  `OR` limitation; faking it would drop every voucher with no end date.

### 5.20 A unit belongs to the article (XIV-118)

- **A field on the article; the line takes a copy** through inheritance. The
  invoice gets it by seed, because nothing on an invoice line reads through
  the article.
- **A shipped set of seven, seeded at install**, the only shape that gives a
  new customer something on day one. Customers add options (XIV-144), a
  module's own options are never removed (§5.4), and the field is deliberately
  not a shared list, because inherited copies compare against these values.
- **Values are keys (`m2`) living once in core** (`Units`), since modules may
  not depend on each other. Labels are the customer's, per module catalogue.
- **No plurals.** Installed labels are the customer's text, with no key left
  to look a plural up under, so a unit is a short invariant label in the
  plural form a line usually needs.
- Custom lines get the field; comment and subtotal lines are not offered one,
  having no quantity, which falls out of the variants. **Optional, and that is
  load-bearing**: existing articles have none and their lines must read as
  before. Existing customers take it via §7.2.1, accepting a wrapped form row
  until they narrow the description, because an upgrade only adds. No unit
  conversion, which would change what a price means.

### 5.21 A field with formatting in it (XIV-131)

**Markdown, because the dangerous half was already built**: §5.13's escaping
property. A rich-text editor storing HTML arrives on the far side of the
escaping decision.

- **A new type, not an option on `textarea`.** Whether a value is
  markup-bearing must be readable from the type (`HoldsFormattedText`,
  answered once, not a boolean every caller re-asks), and a checkbox is
  retroactive, silently reinterpreting every stored value. The accepted cost:
  there is no path from an existing `textarea`; that is a §7.2 conversion.
  **XIV-113 must follow this**, because a `multiple` option would change the
  storage shape, retroactivity at its strongest.
- **One converter, one sanitizer policy** (`MarkdownRenderer` in core, the
  policy being the strictest caller's), because two configurations is how one
  ends up unescaped for a year.
- **The editor is a textarea and a preview**, free because the form already
  round-trips every keystroke; a form theme block off the type's prefix.
- **Each destination is decided.** The record page gets rendered markup, the
  only place a record value reaches a page as markup, via the one renderer,
  at full width. Documents and list cells get the words with the marks taken
  off. Exports get the source untouched. Filters match the source. The plain
  rendering walks the parsed document, never regex-stripping, asserted by
  handing the renderer a sanitizer that throws.
- Not in: images (XIV-115), extensions beyond `TableExtension`, and
  collaboration. The first blueprint consumer is the knowledge module, a new
  module, because installing does not retro-fit.

### 5.22 An internal knowledge base, and how much of it was already here (XIV-132)

A very simple wiki. **The engine work was none, and that is the finding**:
`packages/knowledge` is a blueprint, a translation file and a bundle. History
and the system columns answer who and when; there is no `author` field on
purpose, because a forgotten date field is a record confidently wrong about
itself. §8.4 answers write-vs-read, `contains` answers search, §5.21 answers
the body.

- Topics are a plain seeded `choice`, §5.26's recorded first consumer, and
  still not pointed at a list, because a module's own field may not be.
  Customers add topics. Not required, since writing at half past five should
  not be stopped by a dropdown.
- **Linking: no.** A link must earn its way in from both ends, and the
  read-back half is a panel on every record page in the system. The
  consequence is worth having: the first module that installs into a
  completely empty tenant.
- **Staleness beats emptiness as the failure mode**, and the defence is the
  age on the screen, not a review date. The module list grew a **Changed**
  column beside Owner, on every module's list; both are system columns, and
  neither sorts.
- **The search ceiling is stated and tested**: `ILIKE '%…%'`, with no
  stemming, ranking or index. Full text is a ticket (`tsvector` plus GIN), and
  the test asserting the plural fails to find the singular is its red line.
- **Writing is granted deliberately**, which default deny already does. Wrong
  knowledge acted on beats none, and the other direction cannot be undone.
- Not a wiki (no trees, cross-links, namespaces, revision diffs) and not
  customer-facing, kept by the declaration: no `mailRecipient` means no send
  button exists to misuse.

### 5.23 A phone number is one number (XIV-114)

`phone` stores **E.164** via `toStorage()` and refuses what it cannot read.
The form, the importer and the query compiler cannot disagree, because none of
them has an opinion. Consequences taken: `unique` works, old-data imports will
refuse rows, and the library's metadata moves, so updates change acceptability
in both directions. Nothing revalidates on read, because a stored number is a
fact about a customer.

- **The country comes from the existing chain** (`InstanceRegion`, delegating
  to `FormattingLocale`). **The person is deliberately not in the parsing
  chain**, because asking who is looking would store the same digits as two
  customers. Display takes the opposite rule: national where local to the
  reader.
- A per-field country override exists (`AssumesACountry`). It decides how the
  next value is read and rewrites nothing.
- **Extensions are refused**, because E.164 has no room for one and
  `format(E164)` drops it silently, asserted. A second field is the answer.
- The dependency is the lite build, 2.8 MB against 25 MB, and the first
  Apache-2.0 production dependency, with its notice section. Contact's
  blueprint declares the type for new installs only (§6.1; converting existing
  tenants is XIV-146). Nothing is sent to a number.

### 5.24 A voucher on an order (XIV-104)

**A discount is a derived value, and derived values are the engine's.** It
lives in the deriver's path, never written by hand. It applies **before VAT**
and **is its own line**; that governs the order mode, while §5.25's line mode
reduces the line. **Every kind is a line**, so nothing downstream knows which
kind it was, and the document shows the quoted lines with the discount stated
separately.

- **Mixed rates get one discount line per rate**, pro rata on each rate's net,
  with **the last line taking the balance**, XIV-116's remainder rule. No
  remainder crosses a rate. Inclusive VAT needed no case of its own. A voucher
  worth more than the order is capped by it.
- **One deriver, one seam.** Deriver order is deliberately unspecified, and
  discount and totals have a strict mutual order, so `Money\DocumentDiscounts`
  is a one-method seam that core defines and the voucher package implements,
  with no voucher vocabulary in core. The package finds the order's voucher
  field by reading the shape for a reference into `voucher`. Three answers:
  null for "not mine", empty for "worth nothing today", and a discount.
  Collapsing the first two would strip copied invoice lines or leave removed
  discounts forever.
- **The engine owns the generated lines.** The deriver rewrites them each save
  reusing their ids, the form draws them disabled, and the kind is not
  offered. Removing them from the form entirely would churn ids and flood the
  timeline.
- **Stored, not re-read.** Deleting the voucher changes nothing on the order.
  The deriver recomputes on save while the lifecycle has not locked the
  record, the same window every derived figure has. An unreadable voucher
  leaves the lines alone.
- **Redemption is a subscriber on `RecordChanged`, inside the writer's
  transaction.** The use is taken at commit, because the live form re-derives
  per keystroke; a failed save takes nothing, and a refusal takes the save
  down. **The count is the number of documents carrying the voucher.**
  Un-naming and deleting give the use back. A cancelled order keeps its use,
  locked and still carrying it, the one imperfect edge.
- **Refusals name which** of four situations, via `RecordRefused`, the missing
  half of §7.1's subscriber question, shaped like `DuplicateValue`: it names
  the field and lands on the control with the form intact. The deriver still
  cannot refuse. Validity is checked once, at take, and there is deliberately
  no transition guard, which would refuse confirmation because the shop took
  too long.
- **The field exists only where both modules do** (`Module\AvailableFields`),
  because a definition that does not exist is invisible everywhere at once.
  The upgrade offer asks the same question, and `ModuleInstallOrder` follows
  `uses` edges within one requested set. The rule is narrowed to unscoped
  references, since variant-scoped ones are `AvailableVariants`' business.
- The invoice gains the `discount` kind. It was an ordinary kind at first,
  generated by nothing and editable, with the split across several bills left
  open as its own ticket; [XIV-147] closed that and made it the engine's row
  there too, worked out from the discount this order stored. §5.12 has the
  rule. The discount line appears at first save; the totals follow live.

### 5.25 Two ways to apply a voucher (XIV-122)

**Order mode adds its own line; line mode reduces the chosen line.** That is
not a tension with §5.24, whose rule governs the mode where no line exists for
the money to belong to.

- **Mode and kind are one variant field with four options.** The absent fifth,
  an order voucher restricted to an article, is an eligibility rule and a
  different feature, refused by not being offered.
- **The line is chosen by naming the voucher on it**, a reference on the line
  collection. Article-hunting cannot reach a custom line, which is exactly
  where a negotiated discount lands. The article reference survives as an
  optional restriction, and *free article* dissolves into "line mode,
  restricted, 100%".
- The now-optional reference re-split the hiding rule. A **required**
  variant-scoped reference is `AvailableVariants`' to hide; an **optional**
  one is `AvailableFields`' to take away. `AvailableFields` learned
  collections, or voucherless tenants' order lines would carry an empty
  picker.
- **The reduction is a derived column on the line**, and the line total is
  what is left, so the recipient can check it. The derived flag protects the
  column, because the customer owns the row.
- **Two passes in `DerivesTotals`**: what each row charges, one ask of the
  seam, then reductions taken. Before VAT in both modes, and a line reduction
  joins exactly one rate by being part of it, so nothing is apportioned. Still
  **one seam with one method**: a line voucher is a second *answer* (`off` and
  `perLine` on one `Discount`), because both modes are decided from one record
  in one save. Line reductions happen first, and an order percentage is a
  percentage of what is left, the only non-arbitrary reading.
- Bounds: the field caps a percentage at 100, and an amount larger than the
  line is floored at the line rather than refused, because twenty off a
  fifteen-franc line plainly means fifteen off.
- **One voucher on several lines is one use.** The count stays "documents
  carrying the voucher"; per-line counting would spend a five-customer
  promotion on one shopper and need a second counter that must agree with the
  first. **The diff is a set** of carried vouchers, reconstructed from
  `RecordChanges`, and moving a voucher between lines does nothing.
  `findChildren()` gained `includeDeleted` for the delete path, found by a
  test.
- **Misplacement is refused at the write with a sentence naming the fix.** The
  deriver treats it as worth nothing and never guesses; an order voucher
  dropped on a line is not "probably meant for the order". It could not be a
  field constraint, because the rule reads both records.
- Accepted and asserted: the Discount column exists in voucherless
  installations, and the field editor removes it. Not in: stacking (the field
  is a single reference, so the question cannot be asked yet), best-line
  suggestions, and a reason on the reduction.

### 5.26 A list a customer keeps, beside the fields that use it (XIV-127)

A `choice` field's own options are right for a closed per-field set and wrong
for a set belonging to the business: bare strings, per-field drift, and
nothing that tidies `Zürich`, `Zurich` and `zurich` back into one.

- **A core concept, not a module.** Nobody browses a region, and a module may
  not depend on a module, so a list every module points at can only live in
  core. Two tenant tables, a `/lists` screen, admin-only.
- **An option on `choice`, not a type**, inside §5.21's own escape clause.
  Nothing about the stored value or its escaping changes, and the real
  retroactivity, pointing a populated field at a list missing its values, is
  **refused with the values named**, in both directions. A type would have
  cost the point, since unifying three existing Region fields would mean three
  fields recreated. `PointsAtAList` fits XIV-144's shape; `needs()` became
  questions with answers ("own options or a list"), and every answer must be
  drawable.
- **Colour is one of eight**, exactly the tones Bootstrap redefines in dark
  mode, because a customer picks against a white page and the dark theme still
  has to read it. `text-bg-*` is not that. Icons are a bounded twelve, since
  the name lands in a class attribute. Chips draw through
  `value_badge(field, value)`, which asks the field rather than switching on
  type.
- **Hierarchy is one level**, and a parent must itself be a root, so cycles
  are impossible by construction. **It changes the picker and nothing else.**
  Filters stay exact, because the count, the refusal and the merge all count
  exactly; subtree matching, if ever wanted, is an operator of its own.
- **Merge is XIV-91's backfill in a different hat**: an irreversible rewrite
  across modules and collections. It inherits the rules: a page of its own, a
  per-field plan that keeps the empty fields, a confirmation the controller
  requires, **no history entry and `updated_at` untouched**, and figures
  reported from the statements rather than the plan.
- **Removals follow §5.4's rule, with the reach named.** The refusal lists
  fields as well as values and counts, because a shared list breaks records in
  modules the remover is not looking at. A list cannot be deleted while fields
  point at it, and a parent entry cannot go while children sit under it.
  Retirement stays unbuilt, on the both-mechanisms-at-once condition; the
  merge removes the commonest reason to ask. Provenance turned out to be a
  non-question here, because nothing seeds a list.
- **The XIV-113 settlement**: a multi-value field points at the same
  `value_list` rows through the same option and capability, and inherits every
  refusal, because `ValueListUsage` finds fields by capability rather than
  type name. A registry test holds that promise and plants the violation. What
  XIV-113 built points at a *module* rather than a list (§5.29), so the promise
  stands unexercised; the test now says so about that type by name, which is
  what keeps "not declared" a decision rather than an omission.
- Not here: retirement, module-seeded lists (a blueprint writes definitions,
  and a list is not one), a module's own field pointed at a list, deeper
  nesting, and colour beyond the record list and page.

### 5.27 A period as one thing, and two of them that cannot overlap (XIV-136)

The one thing the engine could not express for a care home or a hotel: a
period, and the rule that two cannot overlap. That is engine work by
definition, because every module would write the query differently, and double
booking cannot be prevented at all.

- **A type, not a pair of dates**, because overlap prevention needs one value
  in one key to constrain. `DateRangeFieldType` and `DateTimeRangeFieldType`;
  the value is `Period`.
- **Stored as one ISO-8601 interval string** (`2026-08-01/2026-08-05`,
  `…/..` for an open end). A non-scalar value changes the export, the diff,
  the importer, `IS EMPTY` and the accessor at once; a string changes none of
  them and sorts by start date as text. **Two types rather than a precision
  option**, decided by the engine's own seam: `comparableSql()` sees no
  options, exactly where the SQL must choose `daterange` or `tsrange`, and
  §5.4's no-type-change rule stays intact.
- **The end bound is exclusive, `[from, until)`**: the only bound meaning the
  same at both precisions, Postgres' canonical form, and no ±1 anywhere. The
  surprise, that a last day of the 5th is entered as the 6th, is paid in help
  text and boundary tests, and it is deliberately not an option. §5.19's
  voucher dates are two inclusive fields about a rule, and nothing overlaps
  there.
- **Open at the end only, and never by accident**: a no-end checkbox, with a
  typed end beating the tick. **A blank nobody ticked is refused**, because a
  control meaning opposite things depending on intent reports nothing.
- **The constraint is the only opinion.** The read-then-book window cannot be
  closed in PHP, so there is deliberately no application-level overlap check
  at all; a validator would tempt readers into believing it were the rule.
  `EXCLUDE USING gist`, partial three times over (soft-deleted, no scope, no
  period), built in the definition's transaction.
- **What a period is exclusive within is a per-field option and the on
  switch** (`exclusive_within`, `ExcludesOverlaps`). No-overlap is a statement
  about a resource, never about a module, and unscoped periods overlap freely.
  No composite scope, no "nowhere" (a one-resource module gets a scope field
  with one option), and not on collections, §7's within-parent versus
  whole-table refusal. Switching on over existing overlaps is refused with the
  **pairs** named, because an overlap is a relationship and neither record is
  wrong alone.
- **Index expressions must be immutable, and date parsing is not.** The ranges
  are built from integer offsets into the ISO string by two per-tenant SQL
  functions (`PeriodSql`). **The rule that costs**: Postgres never
  re-evaluates an index over a changed function, so a change there is a new
  migration redefining the functions and rebuilding every constraint.
  Datetimes are `tsrange` over naive UTC, because `tstzrange` reads the
  session zone and is not immutable.
- **One operator, `overlaps`** (`&&`). The compiler applies `comparableSql()`
  to the bound parameter as well as the column, so one definition of a period
  is used twice, and a lone date reads as that whole day. Answered in the
  query, asserted by arrangement: the matches sit off the first page.
- **Timezones**: date ranges are zoneless, and datetime ranges are UTC read
  through `ReaderTimezone`, which delegates to `DisplayTimezone`. The reader's
  zone decides which day a period is filed under.
- Not a booking module. No existing data moved, because converting two date
  fields is a §7.2 type change. Demo data places each record in its own week
  and never generates an open end on an exclusive field.

### 5.28 The Swiss QR-bill on an invoice (XIV-152)

Since 2022 the QR-bill is Switzerland's payment slip; an invoice PDF without
one is retyped into e-banking by hand. The library is `sprain/swiss-qr-bill`
(MIT; its tree is MIT and BSD-2-Clause, checked in THIRD-PARTY-NOTICES.md,
including the one package that declares MIT but ships no licence text). It
brought `bcmath` and `gd` into the image.

- **Composed onto the PDF, not a template marker.** Core gained a third
  application-answered seam beside `PdfConverter` and `DocumentContext`:
  `PdfDecorator`, applied after conversion inside the generator, so the
  download and the mailed copy carry the same payment part. A marker was
  rejected because the payment part's geometry is normative to the millimetre
  and a customer-editable .docx cannot promise it; the .docx goes out
  undecorated on purpose. The slip is rendered from the library's own HTML by
  the Gotenberg container already in the stack (Chromium for the page, the
  merge endpoint for the stapling), because every PHP library that can stamp
  an existing PDF was rejected on licence. The payment part is therefore an
  additional last page, which the guidelines allow.
- **The tenant is the creditor, never the platform** (§8.6). The profile
  gained the IBAN, a reference type, and a structured address whose column
  widths are the payload's own field widths; the country is the existing
  `region`. IBAN and type pairing are refused at save time (QRR needs a
  QR-IBAN, a QR-IBAN forces QRR, CH/LI only); address gaps are reported at
  generation time, listing the missing fields, and the invoice still ships,
  without a payment part.
- **Reference type is a tenant setting, SCOR by default**: the ISO 11649
  reference works on every ordinary IBAN, so it is the one default that
  cannot be quietly wrong. QRR is offered for tenants whose bank issued a
  QR-IBAN; NON exists because the standard has it. The invoice number carries
  the reference (digits only under QRR) and rides along as free text besides.
- **CHF and EUR only**, the standard's own bound. Any other currency produces
  the invoice without a payment part plus a flash and a log line saying why;
  never an invalid QR. The library's `isValid()` is the last gate before
  anything renders.
- **The debtor is left open** (a standard-blessed variant: the payer's app
  fills it). Reading the contact's address was rejected on §6.1 (the fields
  may not exist as shipped) and on the free-text street a structured payload
  cannot honestly split.
- The one float on a money path is the hand-off into the library's own
  `float` API, argued at the call site: converted once at the edge, exact for
  every two-decimal value under the standard's ceiling, nothing computed from
  it.
- **A decoration is offered, not applied** (XIV-164). `PdfDecorator` answers a
  second question, "what would you add here and what is it called", and the
  generic chooser draws a tick per answer, ticked, on the download and on the
  send alike. The reason is §1: a chooser that knew to offer a payment slip on
  an invoice would be module-specific code in the engine, and the module is the
  only thing that can say. Nothing is stored on the record, because the choice
  is a property of one generation. The tick asks and does not promise: the
  refusals above are unchanged, and are the same predicate that decides whether
  a tick is drawn at all, so an installation that cannot make a payment part is
  told why rather than shown a box. The .docx offers nothing, and the timeline
  records the offer and its answer, since "no" is what a reader is checking for.

### 5.29 A field that names several records (XIV-113)

The tags on a contact, the people on a project, the categories an article is
in. A `reference` holds exactly one record, and plenty of real relationships
are not like that.

- **A type, not a `multiple` option on `reference`**, inside §5.21's rule and
  settled by XIV-127 before this was opened: one value becoming a list changes
  the storage shape of every record already holding one, which is retroactivity
  at its strongest. `reference` is untouched, and there is no path from a
  populated one that is not a §7.2 conversion, which works, because a single id
  reads as a list of one; the way back does not.
- **Stored as a JSON array of ids**, said out loud in the type. A joined string
  would make a containment filter a `LIKE` that finds 13 when asked about 3.
- **Order is not meaningful.** De-duplicated and sorted ascending on save, so
  two spellings of one set are one stored value and the history diff, which
  compares storage forms with `===`, cannot report a reordering as a change.
  Display follows the stored order, because ordering names is a collation
  decision this engine has taken nowhere else. An arranged list is a third type,
  never an option here.
- **`unique` is refused**, with the reason said. XIV-109's partial index is over
  `data ->> 'key'`, which for an array is the array's *text*: it would build
  happily and mean "no two records hold the same whole set", which nobody asks
  for and the validator in front does not check. Refused in the editor, in the
  installer, and keyed on `HoldsSeveralValues` rather than on the type's name;
  the checkbox is not drawn either.
- **Filtering is containment**: `includes` and `excludes`, one value each,
  compiled as `@>`. **"Has any of these" is not offered**, because it is §7.3's
  missing `OR`; two `includes` mean *and*, like every other pair. **A hop
  through it is not offered**, because through one link a hop is "the record it
  names" and through a set it is "any of them", which reads the same and is not.
  **Sorting is refused** on §5.3's collection argument, and the list header
  draws a heading rather than a link so nobody meets the refusal by clicking.
- **Priming survives** (XIV-54), which is where the multiplier lands: a page of
  25 records with four links each is a hundred names. Ids collapse per target
  module, and the count is asserted flat with `assertSame` across two set sizes,
  with the unprimed count asserted to climb so the flat one means something.
- **A marker prints the names, comma-separated**, in a document and an email
  alike, through `display()` and with no new grammar. §5.13.1 gave a collection
  a table because its rows have columns; a set of names has one column, and a
  one-column table is a list somebody has to style for nothing.
- **Export writes one cell of ids** separated by a comma, which import reads
  back; §5.6's round trip holds. **No escape, because the alphabet is closed**:
  ids are digits. **An item that is not an id is kept rather than dropped**, so
  the type's own constraints name it and an import refuses the file, which is
  `integer`'s answer to `"12abc"` one arity up.
- **The reverse-link count sees inside arrays** (XIV-52). Which comparison finds
  a record is the *type's* to name (`PointsAtAModule::findsTargetBy()`), because
  a caller choosing it would fail by counting zero, and a card reading "no
  contacts" is indistinguishable from the truth.
- **One picker, two arities.** `RecordReferenceType` takes `multiple`, so
  XIV-35's cap, its apology and XIV-36's search box are the same code rather
  than the same decision taken twice; `symfony/ux-autocomplete`'s controller
  reads `select.multiple` and configures Tom Select from it.
- **Records sharing a title are told apart by one rule, and both of a pair carry
  the id** (XIV-167). The dropdown and the box above it fill from different
  ends, a page ordered `id DESC` against a stored set sorted ascending, so a
  rule that spelled only the *second* one gave the two halves of one field
  opposite answers about which record is "the" one. Scoped to what is shown
  beside it: alone, a record keeps its plain name.
- Not here: a relationship carrying fields of its own, which is a collection
  (§5.1); an anchor per name, since the door exists on the other side; a shared
  list as the target (§5.26); and any ordering somebody arranges.

### 5.30 A file on a record, and where the bytes live (XIV-115)

- **Metadata in the tenant's database, bytes on the filesystem**, behind
  `league/flysystem` (MIT), one directory per tenant. The tenant database nearly
  wins on "a tenant is one database", and loses because attachments are many,
  large and long-lived: single-digit GB per tenant lands inside `pg_dump`, and a
  20 GB dump is slow to take and slow to restore, which turns the thing an
  operator reaches for at 2am into the thing they cannot afford to run. §5.7's
  blob in the tenant database is deliberately not extended: it was bounded, this
  is not.
- **The interface goes in now, not later.** Adopting Flysystem after the fact
  would be a migration of the bytes and of every caller; with it, S3 is a change
  to `config/packages/flysystem.yaml`.
- **Isolation stops being physical, and the mitigation is structural.** §4's
  boundary is a connection that cannot reach another customer's data; a directory
  is not that, and a path prefixed with the tenant is the application being
  careful. So: **one class touches a filesystem, it resolves the tenant itself,
  and no method on it takes a path or a tenant** (`AttachmentStore`). The
  directory is derived from the database name in the stored DSN, the same string
  §4.1 resolves a drop from, so files and database cannot disagree. `deptrac`
  forbids every other layer from depending on `League\Flysystem`, checked by
  planting one.
- **A stored value is one string**, `token:size:type:name`, on §5.27's argument:
  a value that stops being a scalar changes the export, the history diff, the
  importer and `data ->> 'key'`. The token is 32 hex characters and is not a
  path; the name travels beside it and is never used as one.
- **A download is a permission, not an address.** It goes through the
  application, is checked against the record it hangs off, and answers 404 for a
  reader who may not open that record (§8.4). Nothing is served off disk.
- **One file per field**, on §5.29's rule; several would be a type of its own.
  **A collection row cannot hold one**, because a download is addressed by module
  and record id and a row has no address, refused in the editor and the
  installer and keyed on the capability.
- **Nothing reads a whole file into memory**, in or out: streams and a 64 KB
  buffer, measured at 0.32 MB of difference between a 4 KB and a 10 MB upload and
  0.14 MB to read one back. The media type is read from a 4 KB sample rather than
  by handing `finfo` a path, which measured at 14.6 MB.
- **Four upload limits, ordered from the inside out** and asserted by a test: the
  application's 10 MB, then `upload_max_filesize`, then `post_max_size`, then
  Caddy's request body limit. Only the innermost can name itself, and PHP
  discarding a body over `post_max_size` reaches the application as a form that
  submitted nothing.
- **A file is written before the record naming it is validated**, so a refused
  save leaves a file no record claims. Accepted: the alternatives are holding
  10 MB across validation or a second staging area, and §4.7's check is the
  standing answer to two things that can disagree.
- Not built: virus scanning, thumbnails, previews, versioning a replaced file
  (the old bytes go, the history keeps the old name), de-duplication.

### 5.31 A field that holds several options (XIV-169)

The fourth square of §5.29's grid: one record, several records, one option,
several options. The languages somebody speaks, the certifications a supplier
holds, the channels a customer agreed to be contacted on, all of them a
comma-separated text field until this existed.

- **`HoldsSeveralValues` was written expecting this, and it held.** Declaring
  the capability inherited the `unique` refusal (editor and installer), the
  sorting refusal (compiler and list header), the containment filter and the
  export separator, with none of those places learning a second type name. The
  add form, the per-type controls and the shared-list scan came free the same
  way, because each asks a capability. **Three places did have to change and
  none of them was one of the six**: they are all one seam, below.
- **The seam that was missing: which comparison finds a holder.** §5.4 refuses
  removing an option records hold and enforces it with a count, and that count
  was `data ->> 'key' = 'pallet'`, which for an array is the array's own text
  and matches no option there has ever been. It would not have raised. It would
  have counted zero, allowed the removal, and stranded every record holding the
  option, silently, which is a rule switched off rather than a rule that passed.
  So `Enumerates::findsHoldersBy()` is `PointsAtAModule::findsTargetBy()` one
  capability over, the callers ask it, and `RecordRepository` compiles one
  expression per answer: `data ->> 'key'` for a scalar,
  `jsonb_array_elements_text(data -> 'key')` for a set. The **same** seam fixes
  the count printed beside each option, the shared list's removal refusal, the
  check that a populated field's values survive being pointed at a list, and the
  merge, which was one `UPDATE … WHERE data ->> 'key' = …` and would have
  reported rewriting nothing while leaving half of somebody's data saying the
  old thing for ever, the exact outcome §5.26 says the merge exists to prevent.
- **Canonical order is the field's option order, and this diverges from §5.29 on
  purpose.** That section sorts ids ascending because ids mean nothing and
  ordering names is a collation decision taken nowhere else. Options are the
  other case: the field already carries the order the customer arranged, and it
  is the order in the dropdown they picked from, so sorting keys would put
  `urgent` after `low` and read as a bug. Stored de-duplicated and canonicalised
  into that order, which keeps what §5.29's rule is actually for, that two
  spellings of one set are one stored value so the history diff cannot report a
  reordering as a change. **Display reads the field's current options rather
  than the stored order**, so rearranging options rewrites no record at all.
- **Chips, not one comma-separated line, and `value_badge()` became
  `value_badges()`.** One function returning a list, with the single case a list
  of one, because two spellings would be two for every template to keep in step.
  A multi-valued field draws a chip per value whether or not anybody coloured
  it, which is the opposite of §5.26's rule for a lone value and is decided by
  the labels: an option may be called `Zurich, CH`, so a comma between two of
  them is ambiguous on a page in a way it is not in a spreadsheet cell.
  `display()` still joins with a comma, because a .docx, an export cell and a
  record's own title cannot carry a chip. **The ceiling is the list column's,
  three then a count**, stated in the template that has the room rather than in
  the type: the record page draws all of them and is where the count sends
  somebody.
- **A plain `<select multiple>`, never the autocomplete endpoint.**
  `multi_reference` needs a search endpoint because a module holds nine thousand
  records; a choice list is closed, small and already in the page, so XIV-36's
  client-side half narrows it and nothing is fetched. `expanded` is false at
  every size: checkboxes are right for four channels and a page and a half for
  four hundred regions.
- **Both answers to §5.26's question**, the field's own options or a shared
  list, through the same two option names, which is what makes `ValueListUsage`
  find it. That section's promise about a multi-value field pointing at a
  `value_list` is exercised at last, and the registry test now says so by name.
- **`choice` → `multi_choice` is lossless at the seam §7.2 names**, because
  `toStorage()` reads one option as a set of one; the dry run reports every row
  surviving and the change reversible while every record holds one.
  **Neither direction is offered on the conversion page**, and that is §5.4's
  standing rule rather than this type's: nothing converts *into* a type that has
  to be told what its values mean, which is why `reference` → `multi_reference`
  is not offered either despite §5.29 reading as though it were. Worth a ticket
  of its own; the honest fix is to carry an already-answered option across
  rather than to widen the refusal.
- **A collection row may hold one**, on XIV-113's answer rather than XIV-115's:
  a file is refused on a collection because a download is addressed by module
  and record id and a row has no address, which is a fact about files. A set of
  keys is a JSON array in a JSON payload and a row has the same payload (§5.1).
- **`ChoiceFieldType::toStorage()` learned to read a list**, which is the one
  line of the single type that moved. Only the dry run's reversibility check can
  hand it one, and it used to cast it with `(string)`, producing the word
  `Array` and a PHP warning nobody saw underneath a report a customer read. One
  option survives, several are kept in the separator's spelling so the `Choice`
  constraint names them. No other caller can reach the branch.
- Not here: an arranged order, which is a third type on §5.29's rule; "has any
  of these", which is §7.3's missing `OR`; a hop through the field; and
  promotion to the record header, which is XIV-173 and sits on top of this.

---

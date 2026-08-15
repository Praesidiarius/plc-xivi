# Xivi 17 — Architecture Brief

Metadata-driven CRM/ERP engine in Symfony, plus a working CRM built on top of it.
Ground-up rebuild of its predecessor, called **v1** throughout this document — the
version numbers are not a sequence. Solo project.

This file is the authoritative context for design decisions. When a decision here
conflicts with a convenient shortcut, follow this file or raise the conflict explicitly.

---

## 1. What this is

Two deliverables, built together, in this order:

1. **The engine** — metadata layer, storage, dynamic forms, validation, plugin surface.
   Proven by one thin `Contact` module.
2. **The CRM** — real modules built on the engine, which is what keeps the engine honest.

The engine is not allowed to grow features that no module actually needs. Earn the
abstraction: a second concrete use case before a generalization.

---

## 2. Lessons from v1 (hard constraints, not preferences)

v1 was ~90 repositories. The concept was sound; the implementation had specific
failure modes that must not reappear:

- **No public static state.** v1 used static properties as a global service locator.
  Everything goes through DI. If something needs global reach, it's a service.
- **No stringly-typed hook registry.** Extension points are typed event classes and
  tagged services, not string keys looked up in a static array.
- **No implicit subclass contracts.** If a module must implement something, it's an
  interface, checked at compile time — not a documented convention.
- **No EAV.** See §5.
- **Testable by construction.** v1 was near-untestable because of the static design.
  If a piece of the engine is hard to test, that's a design defect, not a testing problem.

---

## 3. Code organization: monorepo

Single repository. Modules live as Symfony bundles under `packages/`, wired via
Composer path repositories.

**Rationale:** v1's multi-repo setup imposed a permanent version-matrix tax —
coordinated releases across N repos for any cross-cutting change, N CI pipelines,
N dependency bumps per PHP upgrade.

**Boundary enforcement replaces repo boundaries.** The one real benefit of separate
repos was that cross-module imports were physically impossible. That is recovered with
**deptrac** (or PHPArkitect) running in CI, enforcing:

- modules may depend on core, never on each other directly
- core may never depend on any module
- cross-module communication goes through events and interfaces only

Boundaries are a CI check, not a distribution decision.

**If external distribution is ever needed**, use the `symfony/symfony` model: monorepo
with automated read-only subtree splits. Add it later; do not pay for it now.

**Per-customer module availability is a runtime concern** — "is this module enabled for
this tenant" — not a packaging concern.

**Which is where modules are allowed to need each other** (XIV-23). The rule above forbids
a *code* dependency: no bundle importing another bundle's classes, and the order module
obeys it by naming contact and article as string keys. What it says nothing about is the
runtime question — an order names a contact, so a customer with no contacts cannot have
orders — and that gap let `order` install into a tenant where every order then failed
validation on a required reference with an empty picker. Broken rather than degraded, and
not the case §7.6 covers: a link nobody can *create* is not a link that has gone stale.

So a module declares two lists. It **requires** what it cannot work without, and installing
without one is refused, naming what to install first. It **uses** what it works better
with, and installing without one succeeds — the parts that depend on it are simply not
offered, so an order in a tenant with no articles has no "article line" kind and its other
three kinds behave as normal. Both hard would have been simpler and would have made
"required" mean "including the things you can work without", which is how a requirement
list stops being read.

---

## 4. Deployment topology: single instance, database per tenant

One deployed codebase serving all customers. Tenant resolved per request from the
`Host` header (`customer1.1plc.ch`).

**Explicitly rejected: per-domain `.env` files.** That reintroduces v1's configuration
drift in a new form — N config files on disk that nobody audits.

**Instead: a control-plane database.** One row per tenant:

- domain(s)
- database DSN
- enabled modules
- plan / status (active, suspended, trial)
- provisioning metadata

Provisioning a customer becomes a console command, not a filesystem ritual.

Not everything out here is per tenant. The control plane is also where the
platform keeps decisions that are the same for everybody — today, how far along
each module is (§6.2). One row per tenant is what it started as, not what it is.

**Database per tenant, not shared tables with `tenant_id`:**

1. **Isolation is physical.** A forgotten `WHERE tenant_id = ?` becomes a bug, not a
   cross-customer data leak. Relevant for CH/EU customers.
2. **Backup, restore, and export-on-churn** are per-customer operations for free.
3. **Column promotion is inherently per-tenant** (see §5). Customer A promotes `email`
   to a real column, customer B does not. Incoherent in a shared table; natural with
   a database each.

**Accepted costs, to be designed around, not ignored:**

- Blast radius: one bad deploy affects everyone.
- Noisy neighbour: one tenant's heavy query can starve shared resources.
- No per-tenant version pinning — a customer cannot freeze their version.
- Migrations must be **expand/contract**, never destructive in a single step, because
  every schema change lands for every tenant.

**Escape hatch, free by construction:** a customer demanding a dedicated instance gets
the same codebase pointed at a tenant registry containing one row. A config choice,
never a fork.

---

## 5. Data model: metadata-driven, not EAV

**Storage shape per entity:**

- Fixed system columns: `id`, `created_at`, `updated_at`, `owner`, soft-delete, etc.
- A JSONB `data` column for the custom long tail.
- **Column promotion**: fields that are hot, unique, or heavily filtered get promoted to
  real columns per tenant, with backfill.

**Metadata layer is the actual product.** Per-tenant definitions of modules and fields —
type, validation rules, UI hints, required/unique/filterable — drive the form, the API
contract, the validation, and the storage shape from one source of truth.

**Field types are a closed registry**, implemented as tagged services. Each field type
owns:

- its storage mapping (JSONB representation and promoted-column type)
- its form type
- its validation constraints
- its normalizer/denormalizer
- its filter/sort behavior in the query layer

Definition rows carry the UI hints beside the rules: `required`, `unique`,
`filterable`, and whether the list shows a column for the field (§5.4).

Closed, not open: adding a field type is a deliberate code change, not customer config.

**A type may need an answer only the application has** (XIV-11). `currency` shows
the price in the currency this installation works in, which lives in the tenant
profile (§8.6) — and core is handed a connection without ever learning whose it
is. So core declares the question as an interface (`InstanceCurrency`) and the
application answers it, the same shape as the entity manager and the connection
being bound in `config/services.yaml`. A field type reaching into a customer's
settings table on its own would be the boundary in §3 quietly gone.

Two consequences of that type worth writing down. **Money is stored as a decimal
string, never a float** — 19.90 has no exact binary representation, and the place
a lost hundredth of a cent turns up is an invoice. And **the currency is not
stored beside the amount**: one per installation means a column of prices adds
up, where per record it would need exchange rates behind it to mean anything.

The widget itself is Symfony's `MoneyType` under the Bootstrap theme, which
already draws the input group and already knows which side of the amount a
currency goes on in each language. **Framework first, wherever it reaches**: a
hand-rolled widget sitting next to Symfony's own is a widget somebody has to keep
next to it, and the first thing it stops matching is the locale handling nobody
wrote down. Where the framework has an answer, this codebase takes it and spends
its own opinions on the parts that are actually the product.

**Relations stay relational.** Real link tables, real foreign keys. Relations are the one
thing both EAV and JSON are bad at, and a CRM is relational at its core. Relations are
*described* in metadata but *stored* relationally. See §5.1 for the first kind of relation
that exists.

**Validation** is built dynamically from metadata using Symfony's `Collection`
constraint plus per-field constraints, including a custom unique-field constraint.

**Records are not Doctrine entities.** Their shape is decided per tenant at
runtime, and mapping that through the ORM means fighting it; the fixed-shape
things — users, and the metadata definitions themselves — stay entities. Records
go through DBAL, in one repository that is the only place knowing *where* a field
physically lives. That is the seam column promotion lands in later without
anything above it noticing, and the query layer (§7.3) will want to build SQL
anyway.

**A module's table is created per customer, not per deploy.** Migrations describe
what every tenant shares; a module's table exists only where that module is
enabled, so the installer creates it when the module is installed for that
customer. Metadata tables themselves are ordinary migrations, since every tenant
has them.

**Definitions are read fully loaded.** A definition fetched inside one tenant's
context and touched outside it would lazily load its fields on whatever
connection is current — throwing when no tenant is resolved, and quietly reading
another customer's database when one is. §7.4 is not only about caches: any
object that outlives the context it was loaded in is the same bug. A module's
collections and *their* fields are loaded with it for the same reason.

### 5.1 Shapes: modules and collections

A **shape** is a set of fields describing the rows of one table. There are two
kinds, and they differ only in what reaches them:

- A **module** is browsable. It has a URL, it is in the navigation, and its
  records stand on their own. Its rows carry an owner.
- A **collection** is not. A contact's addresses have no URL, appear nowhere in
  the navigation, and cannot be reached except through the contact that owns
  them. Its rows carry a parent instead of an owner, are edited inside the
  parent's form, and are soft-deleted with it.

Everything else is shared: the same `field_definition` rows, the same field-type
registry, the same record repository, the same validator, the same form builder.
Adding addresses to Contact added a declaration, a table, and the composition of
two form types — no second repository, no address entity, no address controller.
That is the claim in §1 being tested by something harder than one flat module,
and it is why the two kinds share a base rather than the engine growing a
parallel path for children.

**A collection's rows may come in kinds** (§5.5, XIV-20). An order line is an
item, a comment or a subtotal, and which fields it carries follows from which it
is — a comment line has no price. This is the same mechanism a module's variants
use and it needed almost nothing: `ShapeDefinition` carried a variant field all
along and `CollectionDefinition` simply never passed one up, which is what §5.5
meant by describing *shapes* rather than modules.

Three decisions fell out of building it:

- **Adding a row is choosing its kind**, so the form ends with one blank row per
  kind rather than one blank row. Switching a row's fields as somebody picks
  would need JavaScript; asking first is the same answer §5.5 gives a level up.
- **A kind is fixed once the row exists**, and travels hidden rather than as a
  select. Offering to change it is offering to make a row disagree with the
  fields it is showing. (A *module's* variant is still editable on its form —
  that is a separate question, and nobody has asked it yet.)
- **A blank row carries its kind and is still blank.** Otherwise saving any
  record would mint one empty line of every kind, which is the bug this rule
  exists to have already fixed.

**Rows keep the order the customer put them in** (XIV-21), on a `position`
column beside the parent id — a system column, not a field: it is not theirs to
name or delete, and every read of a collection sorts by it. Numbered in **tens**
and renumbered on every save, so a row can be moved between two others by typing
a number between theirs and the next insertion still has room. Typing a number
rather than pressing move-up and move-down, because those are a form submission
each where this is one save.

**Moving a row is not a change to it.** The id does not move and the history says
nothing happened, because nothing about the row did — where it sits is a property
of the list. That also makes an import keep the order of its file for free: rows
are numbered as they arrive, whether they arrive from a form or a spreadsheet.

The column is added to existing collection tables by a **data-driven migration**,
which is unusual here and unavoidable: a collection's table is created per
customer by the installer rather than by a migration, so only the tenant's own
`shape_definition` knows which tables exist.

**A field may be inherited from the record it points at** (XIV-18). An order line
names an article and shows its description and price — *copied* when the line is
written, not read through afterwards. Three properties follow, and all three are
the point rather than side effects:

- an order confirmed at 19.90 says 19.90 next year, and says it after the article
  is deleted;
- nothing ever copies over a value somebody typed, so a negotiated price is an
  edit rather than a defect;
- and because of the second, the page has to say **which fields have since
  drifted** from what they were copied out of — a negotiated price and a stale
  copy are otherwise indistinguishable, and telling them apart is a conversation
  with a customer.

It is **declared on the field**, not coded in the module: the alternative was the
order module carrying a hook that fills in its own lines, and a module with code
in it is what §1 exists to avoid. One option, and it works for any field of any
shape pointing anywhere.

**Numbers come in three kinds, and the difference is meaning rather than
storage** (XIV-22): `integer` for things you count, `decimal` for things you
measure, `currency` for money. The last two are the same string in the database
and differ in what they print — a currency symbol beside a number of hours is
wrong in a way no amount of formatting fixes, which is why the engine grew the
middle one rather than letting quantities borrow the money type.

**A field can be derived rather than typed** — a line's total, a subtotal's
figure. It is shown and never offered for editing, enforced with `disabled` so a
hand-edited request cannot type over it either. A derived value somebody can type
over is a default with extra steps.

**A collection is deliberately not a link between modules.** Contact → Company is
a different thing: both sides exist independently, either can be browsed, and the
target module may not even be installed for that customer (§3). Conflating the
two is how a CRM ends up with orphaned addresses nobody can reach. When
module-to-module links arrive they are their own mechanism; §7 tracks them.

**Uniqueness on a collection field is refused, not guessed.** Unique across the
whole table and unique within one parent are different rules, and which one a
customer means is not something the installer should decide for them. It waits
for the same decision §7.5 is waiting for.

### 5.2 History is per module, and per action

Every change to a record is recorded: who, when, and what changed.

**One history table per module, not one for the system, and not one per shape.**
`contact_history`, `order_history`. A single `history(entity_type, entity_id)`
table is the design this project has already watched fail: at 60M rows the
relating id meant an order, a contact or an article depending on the row, so it
**could not carry a foreign key** — no integrity, no cascade, orphans
accumulating, and a planner with nothing useful to narrow on. Splitting per
module is what makes `record_id` mean one thing, and therefore what makes a real
foreign key possible. Size is the lesser half of the argument.

§4 does the other half of the work here without being asked: history lives in one
customer's database, so the table that was 60M rows shared is now many small ones.

A collection's events go in **its parent module's** table, tagged with which
collection and which row. An address has no independent life (§5.1), nobody asks
for an address's timeline, and the timeline anyone does want — the contact's —
stays a single indexed read instead of a union.

**Fixed shape, not metadata-driven.** The columns are identical for every module,
so this is an ordinary table created by the installer alongside the module's own,
with no field definitions. Making history describable would buy nothing and cost
every index.

    id           bigint identity      -- bigint from the start; this is what grows
    record_id    int  NOT NULL        -- FK → contact(id) ON DELETE CASCADE
    occurred_at  timestamptz NOT NULL
    user_id      int  NULL            -- no FK: core still does not know what a user is
    user_label   text NULL            -- who they were at the time
    action       varchar(31)          -- created | updated | deleted
    changes      jsonb NOT NULL

One index, on `(record_id, id DESC)`, because that is the only question anyone
asks. Over-indexing is the other half of what made the old table hurt.

**One entry per action, not per row touched.** Fixing an email and adding an
address in one save is one line in the timeline, not three. The grouping key is
the *record*, so an import touching 500 contacts still writes 500 entries.

That granularity is why writing goes through **`RecordWriter`**, a unit of work
that owns the transaction, takes the before-images, merges the diff and dispatches
one event per root record. The obvious alternative — a request-scoped buffer
flushed at the end — is the §7.4 hazard wearing a hat: state outliving the context
it was made in, waiting to flush one tenant's changes into another's database on a
console command that serves several in sequence. An explicitly scoped object
cannot do that.

`RecordWriter` is the *only* supported way to write a record; `RecordRepository`'s
mutating methods are internal to it. Otherwise the first import to call the
repository directly would silently write no history, and a history with holes in
it is worse than none, because it is trusted. PHP has no package-private, so this
is `@internal` plus the fact that nothing else calls it — enforced by review, not
by the compiler.

**Merge rules**, so the timeline stays readable:

- The same field changed twice in one action records first `from` to last `to`.
- A value that ends where it started is not a change.
- An empty diff writes no entry at all. "Edited, nothing changed" is most of what
  makes these logs unreadable. `created` and `deleted` are always recorded.
- `action` is the root record's own verb: adding an address to an existing contact
  is an update *of the contact*.
- Deleting a record writes one entry; its collections cascade silently.

**The diff is structured, and mirrors the form** — the same `fields` and
`collections` branches the form and validator already use:

    {"fields": {"email": {"label": "Email", "from": "…", "to": "…"}},
     "collections": {"addresses": [{"action": "added", "child_id": 7, …}]}}

Labels are captured **at write time**. History is a record of what happened, so
renaming a field later must not rewrite the past — the same reason `user_label` is
denormalised rather than joined. Descriptions are rendered at read time through
the field type when the definition still exists, and fall back to the stored label
when it does not, because history outlives the schema that produced it. That is
§7.2 arriving from a new direction.

**Values only, no reads.** Recording who *looked* at a record is a different
feature with roughly a hundred times the volume and a different retention answer;
it is an optional extra later, not part of this.

**One exception, and it is deliberate: generating a document** (§5.7, XIV-4). It
changes nothing, so by the rule above it does not belong here — but a letter that
went out is a fact about the record's life in a way that opening a page is not,
and it is rare, deliberate and attributable where a page view is none of those.
The entry names the template and the format, because a timeline saying only
"document generated" answers the least interesting half of the question. It is
dispatched as the same `RecordChanged` event a change dispatches, so there is one
answer to "who did this, and when" and one listener that knows how to write it
down. That the rule now has an exception is the thing to watch: the next
candidate should have to argue the same three properties.

**Reading it back is paged, and an entry is one line** (XIV-3). A timeline is the
one part of a record that grows without limit, so the record page shows a fixed
handful and says how many there are, and the whole thing is a page of its own —
twenty-five at a time, grouped into today, this week, this month, this year and
earlier, with anything older than a month opening closed. What each entry
*changed* sits behind a native `<details>` rather than under every row: printing
every diff is what made fifty entries unreadable, and it is also what made the
page's cost grow with the record's age instead of with the window being shown.

It is ordered by **when things happened**, with the id breaking ties. Ordering by
id alone gives the same answer for as long as rows are only appended as things
happen — and a different one the moment anything writes an entry with an older
timestamp, which a backfilling import reasonably would. That was invisible while
the page was one flat list and is not once it draws a boundary between days.

Still to decide: retention and whether `occurred_at` wants range partitioning.
Cheap now, expensive at 60M rows. And field types will need a way to say "do not
record this value" before the first sensitive type ships.

### 5.3 Asking questions: the query layer

A `RecordQuery` — conditions, ordering, one page — compiled to SQL against the
customer's own definitions. It is what §7.3 called the highest-risk component,
and it was built after collections precisely so that it was designed against a
to-many relation rather than retrofitted with one.

**Nothing from a user is concatenated.** Field names are resolved against
definition rows and bound as parameters; comparisons are a closed enum; the only
text interpolated is a table name from a definition row. A filter the engine
cannot answer raises rather than being dropped — a condition that silently does
nothing shows a list that looks like a result and is not one, which is worse than
an error because somebody acts on it.

**A condition on a collection is a semi-join.** `EXISTS`, never `JOIN`: a contact
with two addresses in Zürich is one contact, and a join would return them twice
and inflate every count on the page. The child's own soft delete is honoured, so
a removed address stops keeping its contact in that city's results.

**Sorting by a collection is refused.** Two addresses are two cities and there is
no answer, so `Sort` cannot express one and the compiler raises. Refusing is the
feature; quietly picking one would be a wrong answer that looks right.

**The field type owns its comparisons**, as §5 always said it would: which
operators it accepts, and how its stored value has to be read to compare —
`::numeric` for a whole number, nothing for text, and nothing for a date either,
since ISO-8601 compares and sorts as text, which is why dates are stored that
way. The compiler therefore has no switch on field type, and column promotion
will change the accessor without touching any of it.

**Every ordering ends on the record id.** Without a total order two records
sharing a sort value may swap between pages, so one is shown twice and another
never. Any list with a LIMIT needs that tiebreaker.

Deliberately not built: `OR` between conditions, which needs a tree and a UI to
build one rather than the list of ANDs that covers the honest 90%; and keyset
paging, which is the answer when someone is on page 400 and until then costs a
sort key in every URL. LIMIT/OFFSET is correct, and slower the deeper it goes.

---

### 5.4 The metadata editor

A customer changing the shape of their own module, without SQL and without a
deploy. A field added here appears in the form, the list, the validation and the
filter bar at once, because all four read the same rows — which is §5's claim
stopping being an argument and becoming a page.

Admin only. That makes it the first thing in the application to need more than
"signed in": §8.4 leaves the real model open, and changing what a module *is*
seemed the wrong place to keep waiting for it.

It edits any shape, so a collection's fields are editable exactly like a
module's (§5.1).

**What it refuses, and why each refusal is the feature:**

- **A field's type cannot be changed.** Not a disabled control — there is no
  `setType()`. Stored values may not survive a new type, and "convert what you
  can" is data loss on a click. This is the half of §7.2 still open.
- **A field's key cannot be changed.** The key is where the value lives, so
  renaming one would orphan every value it names. The label is the part people
  read, and that is freely editable.
- **A rule cannot be switched on if existing records would fail it.** Making a
  field required or unique is a promise about data that already exists; applying
  it blind leaves records nobody can save until they work out why. The editor
  counts first and refuses with the number. Relaxing a rule is always allowed,
  because it cannot invalidate anything.
- **A module's own fields cannot be removed.** Only the ones the customer added.
  §7.2's other half, unchanged.

**A field can say it names the record.** Something has to decide what a record is
*called* — the heading on its page today, and whatever names it in a link or a
picker once §7.6 arrives. The metadata used to have no answer, so the record page
guessed from the required fields, first two: right for a contact, wrong for an
invoice whose required fields are `status` and `issued_on`, and tied to field
order, so reordering fields in the editor silently renamed every record. It is a
flag now, and the guess survives only as the fallback for anyone who has not
marked one — a wrong heading beats a blank one.

**A field can be on the list, or not.** Without that, every field a customer adds
widens the table until nothing is readable — a strange punishment for using the
engine as intended. It is a UI hint and nothing more: the value is still on the
record, still in the form, still validated and still queryable. A module's own
fields are its designed shape and appear by default; one added later does not
until somebody ticks the box, because an addition should not silently rearrange a
list people read every day. With nothing ticked the list falls back to the first
field, since a table with no columns is not a table.

**Removing a field takes the definition and leaves the values.** This is the
answer to the half of §7.2 that is settled. Deleting the data too would be
irreversible on a click; leaving it means adding a field with the same key brings
it back, so removal is reversible by construction. The confirmation says so
plainly and says how many records still hold a value — somebody clicking Remove
reasonably assumes the data goes with it, and finding out afterwards would be too
late. For a product sold on data protection that also means the opposite promise
has to be available: **purging values is a separate, explicit operation, and it
does not exist yet.** Until it does, "remove" means "hide", and the UI says the
word.

---

### 5.5 Variants: one shape, more than one kind of record

A contact is a person or a company. They share an email, a phone number and an
address; they do not share a first name, and a company cannot satisfy a rule that
says one is required.

**One module, not two.** The deciding argument is not tidiness, it is the
reference: "select a contact anywhere you select a contact" has to work for both,
and with two modules that selection is a **polymorphic** column — an id plus a
type saying which table it points at. That is the shape that cannot carry a
foreign key, and §5.2 already refused it once for exactly that reason. One module
makes every link a plain key into one table. Shared machinery follows for free:
addresses, history, filtering and the record page are declared once.

**A shape names one choice field as the one that decides**, and the variants
*are* that field's options — so adding "Partner" is adding an option, and there is
no second list to disagree with it. A field then names the variants it belongs
to; empty, the default and the common case, means all of them.

    contact.variant_field = kind
    kind      choice: person | company     (all variants)
    first_name                             [person]
    company_name                           [company]
    email, phone, addresses[]              (all variants)

**Where it applies, and where it deliberately does not.** The form asks for one
variant's fields, the validator checks that variant's rules, and the record page
shows what the record actually has. Storage is untouched: a value belonging to
another variant stays in the payload and travels across saves, because it is
somebody's data — the same reason removing a field leaves its values alone
(§7.2). Validation lets those keys through unchecked while still rejecting a key
the shape has never heard of.

**Adding a record asks which kind first.** The fields depend on the answer, and
switching them as somebody picks would need JavaScript, which these forms do not
depend on. "New person" or "new company" is also how a CRM usually puts it.

**The list names records rather than showing their fields.** With variants the
only thing every row has is its name (§5.4), so that is the first column and it
sorts across every field a name is built from — ordering people by a field only
companies have was the first thing that went wrong here.

---

### 5.6 Getting data out, and later back in

A module's records as a spreadsheet: a customer's backup, and the way data
arrives from whatever system they were on before.

**One sheet per shape**, mirroring the storage — a contact has many addresses and
they cannot share its row (§5.1), so the child sheet carries `parent_id` and the
file can be read back as the structure it left as.

**Headers are field keys, not labels.** A key is the one thing about a field that
cannot change; the editor refuses to rename one (§5.4). A file exported today
therefore still matches its module after somebody relabels a column. Import will
accept either — lenient in, stable out.

**Values are in storage form**: an ISO date, a choice's stored value rather than
its label, a reference's id rather than the record's name. A file that reads
beautifully and cannot be imported would be the wrong trade.

**An export carries the query the list was showing**, including the children of
exactly those records — a filtered export that quietly included everybody else's
addresses would be worse than no filter at all.

Variants need nothing: every field is a column, `kind` says which apply, the rest
are blank (§5.5). And nothing in the exporter knows what a contact is — the
columns come from the customer's definitions, so a field added in the editor
appears in the file with no code changed.

`openspout`, which is MIT and streams rather than building a workbook in memory.
(PhpSpreadsheet is also MIT since v2 — it was LGPL — but it holds whole documents
in memory, and none of its formulas or charts are wanted here.)

**Import is the other half, and is built.** It parses, matches columns to fields,
validates every row through the existing validator — which is already
variant-aware — and applies the file in one transaction or not at all. Half an
import is a state nobody can reason about. It writes through `RecordWriter` like
everything else, so every imported row gets a history entry attributed to whoever
imported it, for free (§5.2). It needs an upload but no file *storage*: parse and
discard, which sidesteps §7 entirely.

**A check is the import, rolled back.** `check()` and `apply()` run the same
statements on the same connection; one commits and one does not. A dry run down a
separate path would be a dry run of something else, trusted right up until the day
the two disagreed — and it is the only way to catch what only a write can, such as
two rows of one file claiming the same unique email. The second write finds that,
because by then the first one is really there. DBAL 4 nests transactions with
savepoints, so this works underneath a test suite that is itself a transaction.

**Lenient in, stable out.** A header may be a field's key or its label. A field
with no column keeps the value it had — a three-column file corrects three things
rather than blanking everything else — while a column that is present and empty
does clear its field, because that is what deleting a cell means.

**`id` decides create or update.** A numeric id updates that record, and one that
names nothing is refused rather than quietly inserted: a mistyped id would
otherwise duplicate the record it was meant to correct. An empty id creates.
Anything else — `acme-1` — is a name the file made up for a record it is creating,
which is what lets a migration from another system bring children with it, since
the child sheet can point at a parent that does not exist yet.

**A collection sheet speaks for the whole collection.** For every record the file
names, a row the sheet does not list is a row that gets removed — otherwise a
round trip could never delete an address. A collection with *no* sheet is left
alone, because saying nothing is not the same as saying "none". Removal is the one
thing an import destroys, so the check counts it separately and the page says so
in as many words. A collection row naming a parent the file does not contain is
refused: attaching it would mean loading a record the file never mentioned, which
is a two-line file reaching into anything.

**Both halves are tested against each other** — export a module, import the file
back unedited, and nothing may change or double. Either half can be correct on its
own and still disagree about a sheet name, a header or a stored value.

Two things deliberately left: the file is read into memory before anything is
written, because the sheets must be cross-referenced before the first row is saved
and a spreadsheet promises no order — fine for what a customer exports and edits,
and wrong for hundreds of thousands of rows. And importing is admin-only for now.
It is not a more dangerous way to edit a record than the form is, but it is a much
faster one, and §7.5 is where that gets a real answer.

### 5.7 Documents from templates (XIV-4)

A record, on paper: the customer writes a .docx in Word, puts `[markers]` where
the values go, uploads it, and downloads a filled-in copy of it as a .docx or a
PDF from any record it applies to.

**The placeholder list is derived, not documented.** It is the customer's own
field definitions crossed with a handful of markers every template ends up
wanting — the record's number, its dates, today — so a field added this morning
is a marker this afternoon and one removed stops being offered. One class
computes both the reference list and the values that fill it, because a screen
somebody writes their template against and the substitution that happens later
have to agree about every word; two functions computing them separately is a
feature that works until a field is renamed. Values are rendered through the
field type, so a date reads as a date and a price as "CHF 19.90" — the same
`display()` the list already uses.

**Two kinds of marker, and the difference is what they are about.** A record
marker describes the contact being written to, and there is one list per variant
because a person and a company hold different fields. A general marker —
`[today]`, `[tenant.name]`, `[user.name]` — describes the moment, and belongs
under none of the variants; listing them per variant read as something the
contact *has*. General keys are namespaced, because a customer's fields become
markers under their own names and the contact module already ships a
`company_name`. Core declares the general ones it cannot know as an interface and
the application answers with whole markers rather than values, so the next one
needs no change to the engine.

**A template may name a variant** (§5.5): a letter to a person is a different
document from a letter to a company, and one naming no variant is offered
everywhere.

**Uploading and generating are two permissions.** They fall out of §8.4 for free
— the enum crossed with the modules — and they are genuinely different jobs:
whoever designs the invoice is not whoever sends one, and a template decides what
every future document of that kind looks like, which is a larger thing to hand
out than the documents.

**Three libraries, and the licence decided two of them.** This project is MIT and
its dependencies have to be usable on those terms:

- **`anourvalar/office`** fills the .docx. PHPWord is the obvious choice and is
  LGPL-3.0; this one is MIT and does the part that is actually hard, which is
  that Word splits a placeholder somebody typed by hand across several runs in
  the XML, where a naive string replace finds nothing.
- **Gotenberg**, through `sensiolabs/gotenberg-bundle`, makes the PDF. Both MIT.
  It is a container wrapping LibreOffice rather than a library, and that is the
  point: **no pure-PHP PDF library can read a .docx at all.** They render HTML, so
  the pipeline with one would be docx → HTML → PDF, and the header, the footer,
  the page numbering and the fonts of the template are approximations by the end
  of it. LibreOffice is what a person would use to export the document
  themselves. (dompdf is LGPL-2.1, mPDF GPL-2.0, TCPDF LGPL-3.0 — the licences
  rule them out before the fidelity does.)
- Core declares `PdfConverter` and the application answers it with Gotenberg, the
  same seam as `InstanceCurrency` (§5): the engine fills a template and never
  learns that the converter is a service on a network.

**Uploaded templates live in the tenant's own database**, in a bytea column.
These are the first files the system keeps, and the general file-storage question
— the one attachments will ask — is deliberately *not* answered here. Templates
are small and few and unmistakably one customer's, so the isolation §4 already
provides costs nothing extra: no volume, no bucket, no path to get wrong, and
backup, restore and export-on-churn keep working per customer with nothing added.
Attachments are many, large and long-lived, and will want a different answer;
this one is bounded on purpose.

**Choosing is a page, shown as a modal.** A record carries one button, not a
list: fifty templates on a contact would be a column of a hundred buttons. The
button links to a chooser page and Bootstrap opens that same form in a modal when
its JavaScript is there — so the download is an ordinary GET form either way,
which is also why the route takes its template and format as query parameters
rather than in the path.

**Word's placeholder text has to be settled before converting.** A letterhead is
mostly content controls — the boxes somebody clicks into — and one nobody has
typed in yet carries `showingPlcHdr`. Word displays that text and prints it;
LibreOffice renders nothing for it, so a document came out complete as a .docx
and missing its whole sender block as a PDF. The generator drops the flag on the
way out, which is all it takes: the words are the control's own content, and
without the flag every reader treats them as ordinary text. The first bug this
feature had, and a fair warning about the class: **Word and LibreOffice agreeing
about a file is not the same as their agreeing about what to draw.**

**A converter that is down is not a broken record.** The .docx is offered beside
the PDF for exactly that case, and the page says so rather than showing a stack
trace.

*Repeating blocks, once still to decide, are §5.11 — a template can lay out a
contact's addresses or an invoice's lines, and a table row carrying a collection
marker is what grows.*

### 5.8 Lifecycles (XIV-14)

A module may declare the states its records move through and the moves allowed
between them: draft → active → done, cancelled from either.

**On symfony/workflow**, because the framework has this and a hand-rolled state
machine would be a second one to maintain (§5.7's rule, applied again). Two
things had to be adapted, and both are worth knowing:

- **A record is not an entity**, so neither marking store the component ships
  fits — `MethodMarkingStore` wants a getter this class of object cannot have.
  The replacement is nine lines, because **the state lives in an ordinary
  `choice` field the module already declares**. That is the design decision
  underneath: a lifecycle is a *rule over a value*, not a second place the state
  is kept. The state filters, lists, exports and shows up in history for free,
  and there is no second store to disagree with the first.
- **Definitions are built from the blueprint**, not from `framework.workflows`.
  That YAML would have to name every module every customer might install, and
  which modules a customer has is a runtime question (§3). `DefinitionBuilder`
  exists for exactly this.

**Two traps the component sets, both found by tests rather than by reading.**
`StateMachine` is the right class — a record is in one state, where `Workflow` is
a Petri net whose subject holds several places at once. And **a transition with
two `from` places means "from both at once"**, not "from either": "cancel, from
draft or from active" is spelled as two transitions sharing a name, which is what
the builder here does. Neither class name nor signature says any of this.

**Moving a record is its own permission** (§8.4): one grant per module, not per
transition. Sending an invoice is a different authority from correcting a typo in
one, which is why it is not `edit` — but "may confirm and not cancel" would need
the grant table to carry a third thing, so it waits for somebody who actually
needs it.

**A state can end editing.** A finished record loses the edit button and the
route behind it; the button is a courtesy and the URL is the rule. That is the
first time the engine refuses a save on a module's say-so, which narrows §7.1
without answering it: this is a declared rule, not a subscriber's veto.

**The timeline gets its own verb for it.** "Somebody sent this invoice" and
"somebody fixed a typo in it" are different facts about a document, and an audit
trail that called both "updated" would bury the first.

---

### 5.9 Derived values, and the money that needed them (XIV-16)

A module may work values out while a record is being saved. `ValueDeriver` is
handed the record's fields **and its rows**, before anything is written and
inside the save's transaction; whatever it puts there is what lands in the table,
what the history entry describes, and what the next reader sees.

**This answers the non-veto half of §7.1.** A module may take part in a save, and
what it may do there is *derive*. It may not cancel, and there is deliberately
nothing to cancel with — no return value, no stoppable event, no flag. A save
that fails for a reason the page cannot name is the failure mode that question
was written about; a save that produces more than was typed is not.

**Rows as well as fields**, because the interesting derived values need both: an
order's total is a fact about its lines, and a subtotal line is a fact about the
lines *above* it. Rows arrive in the order they will be stored in (XIV-21), which
is what makes "the lines since the previous subtotal" computable at all.

**A collection missing from the derivation is one the save is not touching**, and
that distinction is load-bearing rather than pedantic: an empty list means "no
rows", which deletes what is there. A lifecycle transition writes the header
alone, and without the distinction confirming an order would zero its totals.

**A collection nobody can type into is derived**, read off its fields rather than
stored as a flag — the same trick §5.5 plays with the variant field. Such a
collection is off the form, out of the import and export, and out of the history:
its rows restate what other rows already say, and the change that moved them is
in the same entry anyway.

#### What the money model decided

The order module is the first thing to use this, and four decisions came with it.
They belong here rather than in that package because the invoice module has to
make the same ones.

- **Totals are stored, not computed when read.** "Orders over 5000" has to be a
  `WHERE` clause, and what a confirmed order came to is a fact about that day
  rather than the result of running today's code over yesterday's lines.
- **VAT is per line, not per document.** A document mixing 8.1% and 2.6% is an
  ordinary week in Switzerland. The article carries the rate, the line copies it
  like the price (§5.1's inherited values), and the per-rate breakdown is stored
  as a derived collection — so it cannot disagree with the tax total beside it.
- **Rounding has one answer**, written down in `Money\Amount` and nowhere else: a
  line total is rounded to two places as it is computed, so the printed column
  adds up to the printed total; **VAT is grouped per rate before it is rounded**,
  because a hundred lines each losing half a rappen is fifty rappen of tax nobody
  owes. Halves go away from zero. Rounding to five rappen is deliberately absent:
  that is a rule about paying cash, not about what an invoice says.
- **A discount is a line with a negative price**, not a percentage on the header.
  A discount reduces the VAT base it was given against, and only a line can say
  which rate that was — a header field would be guessing on any document with two
  rates.

**A line contributes if it has a price, not if it is the right kind.** Comment
lines and subtotal lines fall out of the summing for having no quantity and no
unit price, which is a fact about the line rather than a branch about its kind —
so a fifth kind of line needs no arithmetic written for it. A subtotal is the one
thing asked about by kind, because a subtotal is defined by being one.

---

### 5.10 Document numbers (XIV-15)

A field may be numbered from a sequence: `ORD-2026-0001`, `INV-2026-0001`. Two
things can go wrong with a document number and both are fatal — one that changes
after somebody has read it down the phone, and two documents carrying the same
one — so the mechanism is small and the decisions are written down.

**Declared as an option, not as a field type.** A number is a string; what is
special about it is *who fills it in*, which is a fact about the field rather
than about the kind of value. So `NumberFormat::from('ORD-{year}-{number:4}')`
spreads into any text field's options, the way inherited values do (§5.1), and a
customer can change the pattern in the metadata editor without a deployment.

**One pattern instead of three settings.** Prefix, padding and "resets each year"
were never independent: a year in the number that did not reset would look absurd
by 2028, and a reset without the year in it would hand out `0001` twice. So **the
pattern decides the period** — a number containing `{year}` resets each year, one
without it does not — and the third setting cannot be set wrongly because it
cannot be set at all. The width earns its keep twice: it is what makes sorting
the text sort the numbers.

**The counter is a table, and allocation is one statement.**
`INSERT ... ON CONFLICT DO UPDATE ... RETURNING` against a unique index on
(shape, field, period). Read-then-increment in PHP is the textbook race — two
requests read 41, two invoices go out as 42 — and that is the one bug here that
cannot be cleaned up afterwards, because both documents may already have been
sent. A Postgres `SEQUENCE` was the other candidate and loses on both counts: it
cannot restart each year without an `ALTER` that two January transactions race
through, and `nextval` survives a rollback.

**Allocated inside the save's transaction**, through the §5.9 seam — the first
thing to use it that is not a module, which is the useful confirmation that the
engine needed exactly what a module needed. A save that fails gives its number
back. The cost is a row lock held until that transaction ends, so two people
creating an order at the same moment take turns; for a table written once per
document that is the right way round.

**Gaps, decided.** The number is assigned on the **first save**, not when a
document is issued: it is what the record is *called* in lists and links (§5.4),
and a draft with nothing to be called by is a worse problem than a gap. A record
that is created and later deleted **keeps its number** and the sequence moves on.
Records are soft-deleted (§5), so that is a hole in a list rather than a hole in
the books — the document behind the missing number is still there to be looked
at, which is exactly what somebody asking about it wants.

**The year is the year the number is allocated in**, never a date on the record.
Otherwise backdating an order to December reaches into last year's numbering,
which is a book that is closed.

---

### 5.11 Repeating blocks in templates (XIV-17)

An invoice whose template cannot list its lines is not an invoice. §5.7 left this
open and it is now closed: **a table row containing a collection marker draws
itself once per row of that collection.**

`anourvalar/office` does not do it — its repeating rows are a spreadsheet
feature, and for a .docx it substitutes flat markers and nothing else. So the
document is preprocessed before the library ever sees it: **the rows are
multiplied first and substituted second**, and the library still only ever
substitutes markers. Doing it the other way round would mean copying rows that
have already lost the markers saying which row they were.

**No syntax to open and close a block.** Writing `[lines.description]` in a cell
is what makes that row repeat, because a marker naming a collection can only mean
"once per row of it". The `<w:tr>` is the unit because it is the unit Word gives
a person: they build the row they want and it comes out that many times.

**How much the template cares about kinds is the template's business**, which is
the decision the ticket asked for and it is deliberately not a single answer:

- a row whose markers name a kind — `[lines:article.description]` — is drawn only
  for lines of that kind, so a template can lay out one row per kind and give the
  comment line no money columns and the subtotal line a bold figure;
- a row whose markers name no kind is drawn for every line.

So the simple template stays one row and the careful one is possible. The
rejected alternative was for the engine to hand each row a pre-formatted set of
markers so that one block fits all — less to lay out, and it would have meant the
engine choosing how somebody's invoice looks, which is the one thing a template
is for.

**Consecutive blocks for one collection are a group**, replaced as a whole by the
rows in the order the collection holds them (XIV-21). They have to be: a comment
sits *between* two article lines, so drawing all the article rows and then all
the comment rows would sort the invoice by kind. A row no block was laid out for
is not drawn at all — falling back to another kind's row prints a comment through
an article line's columns, and a template that lists only what it has a row for
is a template somebody meant.

**An empty collection leaves nothing behind**, not one blank row: the table's
heading is still there, which is the sensible page for a document with no lines.
The record's own markers — the totals, the number — sit outside the table and are
written once, because they are values rather than columns.

Markers are found in the row's *text* and replaced tolerantly of markup, because
Word cuts a placeholder somebody typed in one go across several runs. That is the
same problem §5.7 describes and the same technique answers it.

---

## 6. Extensibility

Three composable layers, all "one codebase, no forks":

- **Fields** extend an entity's *shape* → metadata rows, no code.
- **Events** extend an entity's *behavior* → module bundles with EventDispatcher
  subscribers.
- **View extensions** extend an entity's *UI* → tagged services contributing panels.

### 6.1 Where a shape comes from

Three sources, and the discipline is knowing which one a given need belongs to:

- **Preset** — a named field set for *one module*, shipped with that module, in
  code, versioned alongside it. `basic` and `extended` for Contact. There are a
  handful per module, they are identical for every customer who picks one, and
  changing one means the module changed — so a release is the honest way to ship
  it. *Built:* a preset names a subset of the blueprint's own fields rather than
  redeclaring them, so there is one description of what a contact can hold and a
  couple of answers to how much of it you want. `tenant:module:install` takes
  `--preset` and lists the choices when you do not.

  **Fields only, never collections**, and not arbitrarily: a field a preset left
  out can be added back in the editor (§5.4), so choosing the smaller preset is
  reversible. Nothing can add a *collection* back — that needs a table, which only
  the installer creates — so a preset omitting one would be a decision the
  customer could never undo. Every collection a module declares is installed every
  time, until §7.2's additive upgrade exists.

  Nothing records which preset was used. Storing it would only invite something to
  re-apply it later, and a preset is a seed with no further say.
- **Template** — how a customer is set up *across* modules: install these modules
  with these presets, then add these fields. "Dentist practice" is a template, not
  a preset; nothing about it belongs to any single module. It is data, in the
  control plane next to plan and enabled modules, because adding one means a new
  market rather than a code change — and needing a deploy to onboard a vertical is
  v1's compiled-in module list wearing a different hat.
- **Metadata rows** — anything one specific customer needs. The moment a preset is
  named after a customer, it has stopped being a preset.

A preset is a seed, not a type. Once installed, the tenant's definitions are the
truth and the preset has no further say — which is also why presets do not make
§7.2 worse: customers are *designed* to diverge from each other, so "we do not
retro-fit blueprint changes" is the stated model rather than a limitation.

Templates reference presets instead of duplicating them, which is why they need
nothing new from the engine: a template is a list of installations it already
knows how to perform, plus rows it already supports.

### 6.2 How far along a module is (XIV-7)

A module has a **state**, platform-wide: `development` or `published`, a closed
set that grows by adding a case — early access is the obvious next one.

**Global, never per tenant.** Whether a module is finished is a fact about the
module. A customer being offered a half-built one because somebody flipped a row
on their tenant is §4's configuration drift in a new costume, so there is nowhere
to say it per tenant and nothing to keep in step.

**In the control plane, not in the blueprint.** The tempting alternative is a
field on `ModuleBlueprint`, which would be global for free and impossible to
disagree with the build. It was rejected on the same rule that puts presets in
code and templates in data (§6.1): a preset changing *is* the module changing,
whereas publishing is a decision about whether customers may have it — the same
kind of decision as a tenant's plan or status, and those live in the control
plane. It also means a module can be pulled back out of the store without a
deploy, on the day that matters.

**A module with no row is in development.** That is what makes "a new module
starts in development" free rather than a sync step whose only job is to write
down the default. A row appears the first time somebody decides otherwise, and
survives being moved back, because when a module left the store is worth knowing.

The two halves — what exists (the build, via `ModuleRegistry`) and what state each
is in (the control plane) — are joined in exactly one place, `ModuleCatalog`. It
is what knows that a state row can name a module this deploy no longer ships:
listed and flagged, never offered, since the store cannot install what the build
does not carry.

**It gates the store (§6, XIV-6) and nothing else.** Installing is deliberately
not gated: a module is developed by installing it somewhere, which is the exact
case the state exists to describe, so `tenant:module:install` names the state and
proceeds. Nor does taking a module out of the store uninstall it anywhere — a
state says what may be installed from here, never what is removed.

---

## 7. Open design questions

Not yet decided. Decide deliberately rather than by accident.

1. **Veto events.** May a subscriber cancel a host action (e.g. `BeforeSave`)? Stoppable
   events give modules real power but make host behavior depend on what's installed.
   *Narrowed by §5.8.* A lifecycle now refuses a save — an edit of a locked record — but
   it is the engine refusing on a rule the module *declared*, not a subscriber vetoing at
   runtime. The question is still open for subscribers; what a module can say
   declaratively is turning out to cover more of it than expected.
   *Half answered by §5.9 — XIV-16.* A module may now take part in a save, and what it may
   do there is **derive**: fill in values that follow from what was typed, on the record and
   on its rows. `ValueDeriver` has nothing to cancel with, on purpose. So the answer is
   asymmetric and deliberately so — taking part is allowed, refusing is not, and the half
   still open is exactly the half that makes host behaviour depend on what is installed.
2. **Metadata migration.** What happens when a field changes type, or is deleted while
   data exists in it? Needs a real answer before the metadata editor ships.
   *Half settled — see §5.4.* Deleting a field is now decided: the definition goes and
   the values stay, which makes removal reversible and destroys nothing. Switching on a
   rule that existing records would fail is refused with a count. What remains open is the
   genuinely hard part — a field **changing type** — plus purging values, which is the
   deliberate counterpart to leaving them.
   *Also covers a blueprint that grows.* Contact gained an addresses collection after
   customers already had the module, and installing does not retro-fit it — §6.1 says the
   customer's definitions are the truth once installed, so silently adding to them here
   would overrule that. Adding a table and definition rows destroys nothing, so an
   explicit, additive-only upgrade is the obvious first slice of this question; it is not
   built yet.
3. **Query layer.** Filtering, sorting, and pagination across mixed real-column and JSONB
   storage, without degenerating into concatenated SQL. This is the highest-risk
   component in the system.
   *Built — see §5.3.* Both consequences collections predicted held: a condition on a
   child is a semi-join, and sorting by one is refused. What is still open is narrower
   than the question was: `OR` between conditions, and keyset paging.
4. **Doctrine multi-tenancy hazards.** Entity manager, metadata cache, result cache, and
   any warmed pools must not leak across tenants within a worker process. Critical under
   FrankenPHP/RoadRunner-style long-running workers.
   *Partly settled — see §9.2. The runtime is deliberately not a worker, which removes
   this class of bug for web requests. It remains open for shared caches and for any
   process that serves several tenants in sequence: console commands, and message
   consumers when they arrive.*
5. **Authorization model.** Roles, permissions, per-module access, and record-level
   rules. Entangled with §7.3: "only the records I own" is a WHERE clause, not a
   check performed after loading. See §8.4. Collections inherit the answer rather
   than needing their own: a child's access resolves through its parent, which is
   why its rows carry a parent and no owner of their own (§5.1).
   *Settled — see §8.4.* The entanglement was real and load-bearing: the record-level
   half could not be a voter, because a voter is handed one subject and a list is
   twenty-five plus a total. What remains open is narrower than the question was:
   whether a reference picker should be scoped, and what a permission means for a
   module the customer has since uninstalled.
6. **Links between records.** *Half built.* A link **is** a field type — that question is
   answered: `reference` stores the target's id, and the widget, the display and the
   filtering all come from the type like any other value. A person points at their company
   (§5.5), and the company's list of people is the reverse read back by query rather than
   stored twice. Optionally narrowed to a variant, so a picker offers companies rather
   than everybody.
   *Built across modules too — XIV-13.* A reference may point at any module the customer
   has, and four things follow from it:
   - **The reverse list crosses modules.** A record's page asks every installed module
     which of its fields point here, and groups what it finds by the module doing the
     pointing. Named by module rather than by field, because an order and an invoice may
     both call their link "Contact" and a list keyed by label alone silently shows one.
   - **A filter may take one hop through a link**, compiled as `EXISTS` over the target's
     table. One hop: `order.contact.city` from an invoice is a second join and a cost
     nobody can estimate from the URL. **The target module's own permissions apply inside
     the subquery** — following a link is reading the other module, and a filter that
     ignored that would sift records by values somebody may not see (§8.4). No grant there
     means the predicate matches nothing, which is an answer rather than an error.
   - **A link into a module the customer does not have matches nothing and reads as
     `#id`.** Not installed is a runtime fact about that customer (§3), not a broken
     reference, and a page that renders beats one that does not.
   - **Deleting a record that others point at is allowed, and the link goes stale.**
     Refusing would mean a contact can never be deleted once anything has ever named it,
     and there is no foreign key on a JSONB value to enforce it with anyway. Records are
     soft-deleted, so nothing is destroyed; the link says `#id` rather than pretending,
     which is the same honesty the display already had.

   Still open: nothing enforces that the id points at something, which is the price of the
   above and is deliberate rather than forgotten.

*Numbering is stable — code comments cite these by number, so a settled question keeps
its slot and gains a note rather than being removed.*

---

## 8. Identity and access

### 8.1 Users live in the tenant database

Not in the control plane. Pooling them centrally would put every customer's names,
emails and password hashes into one shared database while claiming physical
isolation for everything else (§4), and it would stop export-on-churn from being a
single `pg_dump`. The cost is that one person at two customers is two rows, which
for a B2B CRM is the honest representation anyway.

The security provider is therefore bound to the tenant entity manager: "who is
this email" is only ever answered by the database of the customer being served.

### 8.2 Identifiers are only unique within a tenant

This is the sharp edge of the decision above. The session holds a user
*identifier* and the provider reloads the user from it on each request — so a
session minted for one customer and replayed against another, where the same email
exists, would authenticate as that other customer's user. Emails collide in
practice: one person setting up several customers is `admin@…` in all of them.

Sessions therefore carry the tenant they were created for, and a mismatch
invalidates the session rather than trusting the identifier. Anything else that
outlives a request and names a user has the same obligation.

### 8.3 The UI is server-rendered, in this repository

Form login, session cookie, Twig. Explicitly not a separate SPA: in v1 the
frontend was its own build, which meant a per-customer `yarn build` at signup, the
enabled-module list compiled into each customer's bundle, and customers landing on
whatever commit was current the day they signed up. §3 wants module availability to
be a runtime concern; a build artefact per customer is the opposite of that.

### 8.4 Authorization: grants, resolved per person

Waiting was the right call. The record-level half turned out to be a query
problem rather than a security-layer one, and designing it before the query layer
existed would have produced a check performed after loading — which is the wrong
answer in a way that looks right.

**What can be done is a closed enum**: view, list, add, edit, delete, export,
import, per module. That closure is what makes the *catalogue* free — it is the
enum crossed with the modules a customer has installed, worked out at runtime —
so there is no table of available permissions to seed when a module is installed
and none to migrate when a new action ships. Nothing can drift out of step with
the code, because nothing is written down twice. It is §5's field-type registry
argument applied to a second thing.

**Not everything grantable is a module** (XIV-12). The tenant profile (§8.6) is
the first thing worth granting that no module owns, so the catalogue is the enum
crossed with the customer's modules **and** a closed set of *areas* — still
worked out at runtime, still nothing seeded and nothing migrated. An area is
stored in `permission_grant.module_key`, which needs no schema change because
that column was never a join: it holds a string precisely so a grant can name
something the definitions do not have. Area keys begin with `@`, which a module
key cannot, so the two can never collide however a customer names a module. The
verbs stay ModuleAction's, and scope does not apply — there is one profile and it
is nobody's own. When something wants a verb this enum has not got — the store's
browse and install (XIV-6) — that is the moment to add a second axis, with a real
second case to design it against rather than a guess.

**Only grants are stored.** A grant says one holder may do one action to one
module's records, this far. The holder is a group or a person, in one table with
a check constraint enforcing exactly one — resolving somebody is a union of the
two, and two tables would mean writing that union twice and having it disagree
once.

**Scope is all records or only your own**, and it applies to every action that
names a record which already exists. Adding and importing name none, so the enum
says they cannot be scoped and every screen asks it rather than knowing.

**Nothing can deny.** Grants are additive, so resolution is a maximum rather than
a precedence table, and therefore order-independent. "Why can this person still
see that" never becomes a question with a complicated answer. The cost is that
"everything except one thing" has to be expressed as a smaller grant, which is
the trade every deny-list eventually wishes it had made.

**`ROLE_ADMIN` stays a bypass, not a group.** A group somebody can be removed
from would reintroduce exactly the lock-out §8.4.1 was built to refuse, and there
is no support desk behind this.

**Three enforcement seams, and the third is why this was entangled with §7.3:**

- A route carries `#[IsGranted]`, checked before the action runs.
- A record is decided by a voter, which is what a voter can do.
- **A list is decided by a WHERE clause**, which is what a voter cannot. By the
  time a voter runs, the page is fetched and the total is counted separately — a
  restriction reaching one and not the other prints the number of records
  somebody may not see directly underneath the ones they may. The predicate sits
  beside the soft-delete one in the compiler, exactly where §5.3 reserved the
  slot. The export carries it too, being the fastest way to leave with records
  you were shown one page of.

The two seams must agree, and the shape of their disagreement is the
vulnerability: a record kept out of a list that can still be opened by typing its
id is not protected, merely inconvenient to find. Refusing it answers 404 rather
than 403, so guessing ids reveals nothing; a record you may view but not change
answers 403, because that is true.

**Default deny, and the upgrade path is a command rather than a migration.**
Before this, anybody who could sign in could do anything. The migration that
added the tables writes no grants: it lands for every tenant at once (§4), and
deciding what a customer's people may do is not something to do to them in
passing. `tenant:permissions:grant-all` is the deliberate act, and also the way
back into an installation that has locked itself out.

**The build fails when a route names no permission.** The catalogue needs no
maintenance, but nothing in PHP makes somebody annotate a new route, and an
unprotected one is invisible — it works, for everybody, which is what a correct
one looks like. The surface is defined by the URL rather than by a list of
controllers, so a new controller is covered the day it is written.

`ROLE_ADMIN` still gates the metadata editor (§5.4), user management and the
permission screens themselves — the last because gating them with a module
permission would be circular. Importing is no longer among them: it is its own
grant, which is the answer §5.6 said this section would give it.

**A reference picker is scoped** (XIV-13), which answers what this section used to
leave open. An unrestricted picker is a way to read the names of records somebody
may not open — point at one, read the label back — so the candidates go through
the same `RecordAccess` a list does. The cost is real and worth stating: somebody
scoped to their own records will see a picker that omits the answer they wanted,
with no message saying why. That is the safer half of the trade, and the half that
can be widened by a grant instead of by a deploy.

Core asks for that answer through `RecordAccessProvider`, because a query
following a link cannot know in advance which module it will land in — the same
seam as `InstanceCurrency`, one level further out.

Still open: what a grant means for a module the customer has uninstalled, which is
inert today and deliberately not deleted.

### 8.4.1 Managing users, before managing permissions

Permissions need something to be granted *to*, and until there was a screen for
users the only way to have a second one was a console command against the
customer's database — which is not a thing a customer has. So the user manager
came first, deliberately, and is where the model of §8.4 attached: group
membership and a person's own grants are edited on the same page as their name.

The same argument ran a second time and produced the group screens. A permission
model with no screen is one only its author can use, and "run this command
against your customer's database" is not an answer.

**Deactivate, never delete.** Records carry the id of whoever owns them and
history carries the id of whoever made each change, so deleting a row leaves
records belonging to nobody and a timeline pointing at an absence. Deactivating
locks the person out, keeps every record attributable, and is reversible.

**`User::active` had to be made to mean something first.** The column existed
from the beginning and nothing read it: no user checker, no query filtering on
it. A deactivate button on top of that would have been worse than none, because
somebody would have relied on it. It now takes **two** mechanisms, and neither
covers the other's case:

- `ActiveUserChecker` refuses the sign-in.
- `DeactivatedUserListener` ends a session that already exists. A user checker is
  *not* consulted when a session is restored: `ContextListener` compares
  identifier, password and roles, and nothing else. Without the listener,
  withdrawing access would take effect whenever the session happened to expire.
  `EquatableInterface` is the other way to do this and was not taken — it replaces
  the framework's whole change comparison, so the application would silently
  become responsible for the password-change case too.

**Every refusal is about lock-out.** An administrator cannot deactivate their own
account, cannot take administrator away from themselves, and cannot leave the
installation with no active administrator at all. There is no support desk behind
this: getting back in would mean a console command against the customer's
database.

### 8.4.2 Language

Each person picks the language they read the application in, stored on their own
row rather than the tenant's: one office is not one language, and a Swiss company
has German and French speakers in it. Resolved per request from the user and
never parked in the session, which would be state outliving the request that made
it (§7.4) — the one hazard this runtime otherwise does not have. The login page
has nobody to ask and follows the browser.

**A customer's own words are not translated.** Module labels, field labels and
choice options are their data (§5); two colleagues share one row, so a label that
changed with who was looking would have stopped being data. What a *blueprint*
ships is different — that is code, and it was English. Its labels are keys now,
resolved **once at install time** from the module's own catalogue and then
written down. Seeded, exactly like the preset they arrive with (§6.1), and silent
afterwards: a label looked up on every render would overrule the customer's rename
every page load, which would make the screen offering that rename a lie.

Which is why renaming a shape had to exist. Fields were always relabelable
(§5.4); the module holding them was not, so one installed in the wrong language
could not be corrected at all.

The engine and each module ship their own catalogues, so core can name a filter
operator without reaching into the application's file, and a module can name
itself without either of them.

**A missing translation fails the build.** It is the quietest bug available here:
the fallback keeps the page working and merely serves one paragraph of it in the
wrong language, on somebody else's screen, in a country nobody is looking at.

### 8.5 The first user comes from provisioning

`tenant:provision --admin-email=…` creates an admin and prints a generated password
once; `tenant:user:create` adds more. Passwords are `random_bytes`, never derived
from the clock — v1 generated them with `date +%s | sha256sum`, which reduces the
search space to "which second was this account created in".

That printed password is the one credential in the system a human has to read. It
exists because there is no mailer yet; when there is one, this becomes an invite
link and the printing goes away.

**A generated password has to be replaced before the account is usable.** It is a
way in rather than a credential: the administrator read it off a screen and passed
it on by whatever means was to hand — chat, a phone call, an email that will sit
in a mailbox for years — so at least two people know it. `app_user.must_change_password`
is set whenever the system generates one and cleared when the owner picks their
own, and until then every page redirects to the account page. Signing out stays
allowed: somebody who cannot change their password right now must still be able to
leave.

A hold rather than a first-run wizard, so there is no separate flow to keep in
step with the ordinary one. And it applies only to passwords *this* generated — a
password handed in by provisioning or a console command was chosen by whoever ran
it, and demanding they change it immediately would be telling them their own
decision was wrong.

The screens work the same way and share the same code path: adding a user
generates a password and shows it once, and an administrator never types one.
An administrator who picks a colleague's password knows their colleague's
password, which is a different system from the one this is trying to be. Changing
it afterwards belongs to the account owner, on `/account`, and needs the current
one — not because a password is secret from its owner, but because an unattended
session should not be enough to take an account over.

### 8.6 The instance's own settings (XIV-12)

One profile per customer: what they call themselves, and the currency they work
in. `/account` is one person's settings and everybody has one; this is the
installation's, and it is granted.

**In the tenant's database, not the control plane.** It is the customer's data,
edited by the customer, and the request already holds that connection. The
control plane's `tenant.name` stays what it always was — the operator's label in
the registry, which the customer cannot see and has no business renaming — and
the profile's company name is what they call themselves. Two facts rather than
one, and the chrome shows the second when it exists and the first until then, so
they never look like they disagree.

**One row, enforced by the primary key**, which is a constant rather than a
sequence: a second profile is a duplicate key rather than something to notice
later. The migration inserts it for every tenant carrying no opinions — an empty
name and no currency are exactly what "nobody has filled this in" looks like.

**The currency is a code, never a symbol or a formatted string.** symfony/intl
turns ISO 4217 into either, in whatever language is being read, and the list of
what exists is not ours to keep. Null rather than a default, because a currency
guessed for a customer is wrong quietly — it would surface on the first priced
thing they ever printed.

**Read and change are separate grants** (§8.4). Somebody may need to look up what
the instance prices in without being the person who decides it, so the page shows
its fields disabled rather than refusing them outright.

---

## 9. Status

### 9.1 What is built

**Moved out of this document.** A list of what exists is a changelog, and keeping
one inside a design brief made it drift: it contradicted §9.3 twice before anybody
noticed, because nothing forces a prose list to stay true.

See **[CHANGELOG.md](../CHANGELOG.md)**, which is now the record of what was built
and when, and which also explains how the version number works — `17` is a
generation rather than a semver major, and the number moves on release rather than
on feature.

What stays here is the part a changelog cannot carry: *why* each of those
decisions was taken, in the sections above, and what is still open, below.

### 9.2 Decided since this brief was written

- **The runtime is classic PHP, not a worker.** One long-lived kernel serving every
  tenant is the §7.4 hazard in its most dangerous form: state that survives a
  request boundary. Booting per request removes it structurally, for a few
  milliseconds a request. Worker mode is a performance flag to revisit once
  tenant-scoped caching is a discipline — not before.
- **Per-tenant database roles.** Each tenant database has its own Postgres role and
  revokes all rights from `PUBLIC`. This is what makes §4's isolation enforced by
  the database rather than by the application being careful: a wrong DSN fails to
  connect instead of reading another customer's data.
- **Tenant credentials are encrypted at rest**, separately from the DSN, under keys
  that stored values name individually so rotation is resumable. This protects
  dumps, snapshots and replicas of the control plane. It does not protect against a
  compromised application process — per-tenant roles are the answer there.
- **A module and a child collection are the same kind of thing** — see §5.1. Chosen
  over letting Contact hand-roll its addresses, because more than one module will
  want children, and over making an address a module in disguise, because it is not
  one: nothing can reach an address except the contact that owns it.
- **Collections were built before the query layer, on purpose.** The order was the
  other way round until it became clear that the query layer's central abstraction
  is what counts as a filterable thing. Building the compiler against one flat table
  first would have baked that assumption into its signature; see the note under
  §7.3.
- **History is per module and per action** — see §5.2. Per module because a shared
  polymorphic table cannot carry a foreign key, which is what made the last one
  rot; per action because a timeline nobody can read is a feature nobody uses.
- **The query layer was built last of the three, on purpose.** Collections first so
  it met a to-many relation while its central abstraction was still soft, then
  history, then this. Both things collections predicted about it turned out to be
  load-bearing.
- **Events arrived without §7.1 being answered.** History only observes, so it
  needed a dispatch point and not a decision about whether a subscriber may cancel
  a host action. Worth noticing: the passive half of §6 was usable all along, and
  the veto question was never actually blocking it.
- **Permissions are grants, and record-level access is a WHERE clause** — see
  §8.4. The design was blocked on §7.3 for a real reason rather than a cautious
  one: a voter is handed one subject, and a list is a page plus a total counted
  by a second query.
- **Tests are isolated by a transaction, one tenant database per test class.** Each
  test runs inside a transaction on the tenant connection and is rolled back after
  it, so nothing it writes — records, definitions, users, history — can reach the
  next test. Provisioning stays outside that transaction, because `CREATE DATABASE`
  cannot run inside one; the database is therefore made once for the class and not
  dropped when it finishes, since the connection holding the transaction outlives
  the class. The next run reclaims it.

  This needed one thing that is specific to database-per-tenant. DAMA keys its
  static connection per *configured* connection, and here one configured connection
  serves every tenant — so all of them would have shared whichever tenant's
  connection was opened first, and a test could have read another tenant's database
  while believing it had proved §4's isolation. A test-only middleware sitting
  between DAMA and `TenantDriver` derives the key from the resolved database name
  instead. The cross-tenant tests in `tests/Functional/Engine` are the canary:
  remove that middleware and they fail rather than quietly agreeing.

  The tests that provision and drop tenants of their own carry
  `#[SkipDatabaseRollback]`, which takes them out of the mechanism entirely — what
  they assert is precisely what is committed.

  What this replaced was a truncate between tests. It cleared records but not
  definitions, so a class that edited metadata could not share a database and had
  to provision per test method. Isolation is now the same for every class, and
  nothing has to remember which kind it is.

### 9.3 Next

**The permission system is built** (§8.4, §7.5). What is left of it is small and
named at the end of that section.

**Templates** (§6.1), the other half of provisioning: which modules a
customer gets, with which presets. They need nothing new from the engine — a
template is a list of installations it already knows how to perform — but they do
need somewhere in the control plane to live.

The two halves of §7.2 still open are a field changing type, and purging a removed
field's values. They are opposites and probably want deciding together: one is
data loss nobody asked for, the other is data loss somebody explicitly asked for.

Deliberately still missing, and each one needs a decision rather than an
implementation: column promotion, the remaining half of links between modules
(§7.6), and §7.2 — what happens to stored data when a field changes type or is
removed. Installing a module today refuses to touch an existing installation for
exactly that reason, which also means a customer who installed Contact before
addresses existed gets neither those nor a history table.

**§7.2 blocked two features in a row**, which was the argument for building the
additive-only half of it: a new table and new definition rows destroy nothing, and
both collections and history needed exactly that.

Two things to keep honest while templates land: the metadata layer will want a
per-tenant cache, which is §7.4 in a new costume; and file storage is now half
answered rather than not at all. Document templates (§5.7) are the first feature
that keeps a file, and they keep it in the tenant's own database — a bounded
answer for something small, few and unmistakably one customer's. Attachments are
many, large and long-lived, and will still want a real one.

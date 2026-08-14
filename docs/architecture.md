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
  it.
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

---

## 7. Open design questions

Not yet decided. Decide deliberately rather than by accident.

1. **Veto events.** May a subscriber cancel a host action (e.g. `BeforeSave`)? Stoppable
   events give modules real power but make host behavior depend on what's installed.
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
6. **Links between modules.** Contact → Company: both sides independent, both browsable,
   and the target module possibly not installed for that customer (§3). Distinct from the
   collections of §5.1, which are settled. Open: whether a link is a field type, what
   happens to a link whose target module is uninstalled, and whether the query layer can
   reach across one.

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

### 8.4 Authorization is deliberately unfinished

Authenticated or not, plus `ROLE_ADMIN`. The real model — roles versus
permissions, per-module access, record-level rules such as "may I see contacts I do
not own" — is an open question, because record-level rules become WHERE clauses and
so cannot be designed apart from §7.3. Deciding it before a single module exists is
the speculative generalisation §1 warns against.

### 8.5 The first user comes from provisioning

`tenant:provision --admin-email=…` creates an admin and prints a generated password
once; `tenant:user:create` adds more. Passwords are `random_bytes`, never derived
from the clock — v1 generated them with `date +%s | sha256sum`, which reduces the
search space to "which second was this account created in".

That printed password is the one credential in the system a human has to read. It
exists because there is no mailer yet; when there is one, this becomes an invite
link and the printing goes away.

---

## 9. Status

### 9.1 Built

The tenancy layer of §4, and the engine of §5 as far as one module with a child
collection exercises it.

- Kernel request listener resolving the tenant from `Host`, before routing. An
  unknown host is a 404; a suspended tenant is a 503. A short list of system hosts
  (dev tooling, health checks) is served without a tenant.
- A DBAL driver middleware that substitutes the tenant's connection parameters at
  connect time, on a second `tenant` connection. Without a resolved tenant it
  refuses to connect rather than falling back to anything.
- Control-plane entities (`Tenant`, `TenantDomain`), repository, and the commands
  `tenant:provision`, `tenant:list`, `tenant:migrate`, `tenant:rotate-secrets`.
- Two migration sets: `migrations/control` runs once per deploy, `migrations/tenant`
  once per tenant.
- Authentication per §8: users in the tenant database, form login, sessions bound to
  the tenant that minted them, first admin created by provisioning.
- The engine, in `packages/core`: metadata definitions, a closed field-type
  registry, DBAL record storage with soft delete, and validation built from the
  definitions including per-tenant uniqueness. `packages/contact` is the first
  module and is nothing but a declaration — no entity, no repository, no form.
- The module UI: one generic controller and one generic form building every page
  from the customer's own definitions.
- Child collections per §5.1, proven by a contact's addresses: their own table with
  a real foreign key, edited inline with the parent, validated by their own
  definitions, soft-deleted with the record they belong to. Contact declares them
  and still contains no code.
- History per §5.2: `RecordWriter` as the only write path, one entry per action in
  a per-module table, and the record's timeline on its page. The first use of §6's
  event layer — core dispatches what changed, the application adds who did it.
- The five things you can do to a record: list, view, add, edit, remove. The record
  page is read-only, built from the same definitions as the form, and is where a
  record's collections and its history are read.
- The query layer of §5.3: filtering, sorting and paging compiled from the
  customer's definitions, with a filter bar and sortable columns on the list.
- The metadata editor of §5.4: adding, editing and removing fields on any shape,
  admin only, with every change that could strand data refused rather than made.
- Module boundaries enforced by deptrac in CI.

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

### 9.3 Next

Module presets (§6.1) — `basic` and `extended` for Contact — which is small, and
the first thing that makes installing a module a choice rather than a fixed shape.

Then a real title field in the definitions, which the record page currently stands
in for with required fields, and which the editor now has an obvious home for.

The two halves of §7.2 still open are a field changing type, and purging a removed
field's values. They are opposites and probably want deciding together: one is
data loss nobody asked for, the other is data loss somebody explicitly asked for.

Deliberately still missing, and each one needs a decision rather than an
implementation: column promotion, links between modules (§7.6), the metadata
editor, and §7.2 — what happens to stored data when a field changes type or is
removed. Installing a module today refuses to touch an existing installation for
exactly that reason, which now also means a customer who installed Contact before
addresses existed gets neither those nor a history table.

**§7.2 has now blocked two features in a row**, which is the argument for building
the additive-only half of it next: a new table and new definition rows destroy
nothing, and both collections and history needed exactly that. The destructive
half — a field changing type, a field deleted with data in it — can stay open.

**Nothing says which field names a record.** The record page needs a heading, and
the metadata has no answer, so it uses the fields the module marks required — the
ones always there to print — capped at two. That is a stand-in, and it will be
wrong for the first module whose required fields are not what a person calls the
record by. A real title belongs in the definitions; adding that flag on the way to
a detail page would have been deciding it by accident, which is what §7 exists to
prevent.

Two things to keep honest while that lands: the metadata layer will want a
per-tenant cache, which is §7.4 in a new costume; and file storage has not been
designed at all, which is a cross-tenant surface as soon as uploads exist.

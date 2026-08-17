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

### 4.1 Removing a tenant, and why `suspended` is not a prerequisite

Provisioning being a console command is only half a lifecycle. Until XIV-72,
undoing one meant reading `TenantProvisioner::deprovision()` and reimplementing
it by hand in `psql` — which gets the details wrong in exactly the ways that
method exists to get right: it clears the tenant switcher first, so our own open
connection cannot block the `DROP DATABASE`, and it resolves the database and the
role **out of the stored DSN** rather than assuming they follow the slug. A DSN
that disagrees with the slug is not exotic; it is what the `--dsn` option on
`tenant:provision` is for.

So there is a `tenant:deprovision`, and it **ships** — it is not excluded from the
production image the way the demo commands are. That is the decision, and it is
not the comfortable one. The argument is that an operator who cannot remove a
customer from the console will remove them from `psql` instead, and the `psql`
version is the failure this replaces rather than a fallback it can afford to push
people towards. What ships is therefore made hard to do *by accident*, not hard to
do: it names the database, the role, the hostnames and how many records are in
there before it asks, the interactive default is *no*, and an unattended run is
**refused outright** unless `--force` was typed. `--no-interaction` on its own is
specifically not enough, because Symfony answers an unanswered question with its
default and a default is not consent.

**Rejected: requiring `TenantStatus::Suspended` before a tenant may be removed.**
The idea is sound in shape — removal as two deliberate acts, with a state in the
registry between them that somebody might notice. It was not adopted, for three
reasons that compound:

1. **It is a speed bump the same hand can remove.** Nothing stops an operator
   suspending and deprovisioning in the same second, so the ceremony buys no
   delay and no second opinion. A guard that only the careful obey is a guard
   that only inconveniences the careful.
2. **It would block the case the command is most needed for.** A tenant whose
   provisioning died halfway is `provisioning`, not `suspended`, and a row whose
   database was never created cannot meaningfully be suspended at all. A hard
   prerequisite would leave exactly the wreckage nothing else can clear — which
   is why the record count is best-effort and an unreadable database is reported
   rather than treated as a refusal.
3. **Its most frequent caller would route around it.** `tenant:reset` would have
   to suspend first on every run, and a rule whose busiest user's first act is to
   satisfy it mechanically is one nobody reads as meaning anything.

What is kept instead is the information the rule was trying to force somebody to
notice, delivered where the decision is made: when the tenant still serves
requests, the confirmation says so in as many words before asking. Suspending
first remains good practice for a real customer removal — it stops the service
while a final export is taken (§4 makes export-on-churn a per-customer operation)
— but it is practice, not a gate, and the command says so rather than pretending
to enforce it.

`tenant:reset` — deprovision, provision, install modules, generate demo records,
print the admin password — is the development counterpart and is **excluded from
the production image** in `config/services.yaml`, beside the demo commands. Note
that the two exclusions are not the same argument: the demo commands are excluded
because generating fiction into a customer's database is dangerous, while
`tenant:reset` is excluded because it is *meaningless* where the records are
real. Neither is "it is destructive" — the destructive one of the pair is the one
that ships. It resolves module install order from each blueprint's own `requires`
(§6, `Xivi\Core\Module\ModuleInstallOrder`) rather than from the order somebody
typed, and every refusal it can make it makes **before** the existing tenant is
touched: an unknown module, a requirement missing from the requested set, a
hostname another tenant owns. A reset that destroys a database and then discovers
it cannot spell "invoice" has left the developer worse off than the state they
asked to leave.

#### Rejected: building the replacement under a temporary slug and swapping

The refusals above cover everything a reset can *know* about in advance, and
XIV-74 was the day something it could not know about happened anyway: the run ran
out of memory in Doctrine's profiler query log, having already dropped the
tenant. The obvious repair is to stop destroying first — provision the
replacement under a temporary slug, then swap it into place — and it was
considered and not adopted.

The reason is that a tenant's identity is not one thing that can be handed over
atomically. It is a slug, a set of hostnames, a Postgres database, a Postgres
role and an encrypted DSN naming both, and the old tenant holds every one of them
until it is dropped. A swap is therefore not one operation but five: drop the old
tenant, `ALTER DATABASE … RENAME`, `ALTER ROLE … RENAME`, re-encrypt and rewrite
the DSN, and move the hostnames across — all of it *after* the destruction, none
of it transactional, and each step with its own failure. The window in which a
reset can destroy and then die is narrowed, not closed, and what it leaves behind
when it does die is strictly harder to clear by hand than what it leaves today: a
database named after a temporary slug, or a role whose rename invalidated the
password stored against it, rather than "the tenant is gone, run the command
again". For a command that exists only in development and whose entire subject
matter is disposable data, that is machinery bought at the price of the thing it
was meant to buy.

**So the destruction stays first and the command owes precision instead.** A run
that dies after the drop prints what is gone for good, what is standing right now
— read back out of the control plane rather than inferred from how far it got,
because provisioning persists its row before it creates the database and the two
can disagree — which modules were installed, which were filled, which were never
reached, and the command line that starts over. The confirmation says the same
thing before the drop, in one sentence, so nobody learns it from the wreckage.
The exception itself is re-thrown rather than turned into a tidy message: how an
unexpected error is rendered is Symfony's business, and swallowing it would cost
the stack trace `-v` exists to show.

#### The memory itself: one process, three accumulators

The failure was not the generator. `tenant:demo:generate` had never hit it at
5,000 records because each module was a process of its own; folding six commands
into one leaves every debug collector in the process filling for the whole run.
Two of them do it expensively — Doctrine's profiler query log, which keeps each
statement with its parameters *and* a backtrace, and Monolog's debug processor,
which keeps a record for every one of the same statements logged to the `doctrine`
channel. Both are emptied at every seam of the reset and after every generated
batch, which makes their cost a function of the batch size rather than of
`--records`. Emptying only the first merely moved the wall: with the limit halved
the same run then died inside Monolog.

**Not turned off, because there is no supported way to turn it off** from inside a
running command — the middleware is composed into the DBAL driver when the
connection is built, and `reset()` is the only lever the holder exposes. A
subclass registered over `doctrine.debug_data_holder` with a mute switch would be
a service whose purpose is to lie to the profiler, and it would buy nothing:
resetting at every seam is already flat in `--records`. The third collector, the
profiler's stopwatch, is deliberately left alone: its only lever throws the
sections away wholesale while `ConsoleProfilerListener` holds one open across the
whole command, so resetting it would trade slow growth for a reliable explosion
after the work had succeeded. It costs about a quarter of a kilobyte per
statement, which puts the remaining ceiling tens of thousands of records past the
count that broke it.

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

**A widget is an option, not a type** (XIV-36). Autocomplete was the first thing to
test that line, and the answer is worth writing down because the question will be
asked again about the next control somebody wants. A field type owns what a value
*means*; how somebody picks it is not part of the meaning. An
`autocomplete_choice` type would have copied `choice`'s storage, its constraints,
its operators and its display and differed in one method — and from the day it
existed, a customer wanting to switch a field over would have been doing a **data
migration through the metadata editor** (§5.4 refuses changes that strand data)
instead of ticking a box.

The rule this follows is XIV-22's, from when the engine grew `decimal`: `integer`,
`decimal` and `currency` "are the same string in the database and differ in what
they print", and what earned a new type there was a difference in *meaning*, not
in appearance. So the test to apply to the next candidate is: **does turning it on
change what is stored, what validates, how the field filters, or how it exports?**
If not, it is an option on the existing type. If so, it may be a type. A type per
widget is how a small closed set stops being one.

The option itself is `auto` / `always` / `never` rather than a boolean, and
defaults to auto. The engine knows how many candidates there are, so it decides:
a plain select while the count is small, a search box once it is not — a customer
should not have to discover a setting because their contact list grew past a
number they never see. The override earns its place because the count is not the
only reason to want typing, and `never` is what a field with four options wants
forever. Which types offer it is the *type's* declaration, which is a first step
toward what §5.4 says the real shape is: a type saying which of its options are
the customer's to set.

It lands very differently on the two types that have it. On `choice` the options
are a closed list in the field's own settings and are already in the page, so
autocomplete is client-side filtering: no endpoint, no permission question, no
ceiling. On `reference` it is the half that was actually broken at scale, and it
needed a server round trip — see §7.6.

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

- **Adding a row is choosing its kind**, and since XIV-29 that is a **button per
  kind** rather than a blank row of each. The old arrangement existed because
  switching a row's fields as somebody picks needed scripting and the forms did
  not depend on any; the guarantee is gone (§8.3) and this is the first thing it
  was holding up. The rule underneath survives unchanged — a row's fields follow
  from its kind, so the kind is settled before the fields are drawn, and which
  button was pressed is how it gets settled.
  A collection *without* kinds keeps its one blank row: one row to type an
  address into is an affordance, and it was the plural that made the other a
  mess.
  **The buttons are live actions on the component that owns the form** (§8.3),
  so pressing one re-renders it with a row more and nothing else happens: adding
  or removing a row is explicitly *not* a save, because somebody halfway through
  a form has asked for neither writing nor validating.
  **A row that arrives from the browser gets its fields from what was sent.**
  `allow_add` builds a submitted row from nothing, so the kind is not there to
  read at PRE_SET_DATA time and the row would come back holding only the fields
  every kind shares — dropping, on the way in, values somebody had typed. The
  fields are built again at PRE_SUBMIT, where the kind is legible.
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
rather than pressing move-up and move-down, which used to be the difference
between a form submission each and one save. Since §8.3 that reason is spent and
the choice is open again — though typing 15 between 10 and 20 is a thing people
already know how to do, and buttons that swap two rows are a worse fit for moving
one line past nine others.

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
middle one rather than letting quantities borrow the money type. **How many
places is the field's own setting** — hours want two, kilos might want three —
and a scale beyond what the storage promises is clamped rather than refused, so
forty places means "lots" instead of an error about a number nobody was going to
type. A *unit* is deliberately absent: it belongs to the article rather than to
the line, and one that only decorates the number is worse than none.

**Thousands are grouped where nothing is typed back** (XIV-47). `display()` has
always grouped, so a record's page and a printed document read `1.234.500,00` to
a German reader. A *form* did not, and the totals on an order are read there now
that they follow the typing (XIV-32) — so a **derived** money or decimal field is
grouped there too. It is `disabled`, so nothing parses the grouped string back;
the same change on a field somebody edits would put separators into the value
being typed, which is the round trip XIV-44 was a bug in.

`integer` is deliberately **not** grouped, and that is not an oversight. The type
covers things that are counted and things that are merely written as digits, and
the engine cannot tell them apart: grouping turns the year 2026 into `2.026` and
the postcode 8001 into `8.001`. Being right about a quantity is not worth being
wrong about a year, and the only integer this codebase ships is a row reference.

**A field can be derived rather than typed** — a line's total, a subtotal's
figure. It is shown and never offered for editing, enforced with `disabled` so a
hand-edited request cannot type over it either. A derived value somebody can type
over is a default with extra steps.

**How wide a field is drawn is the field type's answer, until somebody disagrees**
(XIV-43). A type already owns storage, validation, the form control and the
display; how much room it needs is the same kind of knowledge. So `text` asks for
half a row, `textarea` for all of it, a count for a quarter — and a form of twelve
short fields stops being twelve rows without any module declaring anything.

One blanket number would not have been a neutral default but a wrong one, correct
for `textarea` and wrong for everything else, leaving every module to fix it by
hand.

The stored width is **nullable, and null is not the same as storing the type's
number**: null means "whatever this kind of field wants" and keeps following it,
so improving a type's default reaches every field nobody has an opinion about. A
number means somebody chose. The same promise `User::locale` makes with null
(§8.4.2), for the same reason.

It is a **proportion, in twelfths, never a class name** — what the grid is called
belongs to §8.3 and outlives whichever framework renders it — and it is always a
full row below `md`, because a column of half-width fields on a phone is
unusable. Ordering (XIV-21) plus width *is* the layout: the grid wraps a line once
its columns pass twelve, which is why this needed no layout editor, no rows as an
entity, and no drag surface in the metadata editor.

**A collection is deliberately not a link between modules.** Contact → Company is
a different thing: both sides exist independently, either can be browsed, and the
target module may not even be installed for that customer (§3). Conflating the
two is how a CRM ends up with orphaned addresses nobody can reach. When
module-to-module links arrive they are their own mechanism; §7 tracks them.

**Uniqueness on a collection field is refused, not guessed.** Unique across the
whole table and unique within one parent are different rules, and which one a
customer means is not something the installer should decide for them. It waits
for the same decision §7.5 is waiting for.

#### How long a collection can get, measured (XIV-68)

**Nothing bounds one.** `findChildren()` has no `LIMIT`, the record page draws
every row it returns, and so does the form. Everything else that reads records
has a ceiling — a list is 25 a page, a reference picker stops at 200 and says so
— and this is the one path with none. XIV-68 named three possible bounds and
refused to choose between them until somebody had a number. This is the number.

Measured by `tests/Measurement/CollectionCeilingTest.php`, which builds an order
of N article lines against a catalogue of 250 articles and asks for the two pages
that draw them. It is in no test suite: `bin/ci` should not spend four minutes
building ten thousand order lines. **This section is the evidence and nothing
else**; the decision taken from it is the next one, and everything below was
written before it was taken — the numbers are what they were on the day, with the
corrections named where they landed.

Per request, `APP_DEBUG=0`, memory counted at the allocator:

| rows | read ms | read bytes | read queries | read MB | edit ms | edit bytes | edit queries | edit MB |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 60 | 32 K | 50 | 0.5 | 226 | 132 K | 31 | 5.7 |
| 100 | 240 | 186 K | 391 | 1.7 | 1 392 | 1.2 M | 205 | 45.6 |
| 500 | 682 | 870 K | 1 600 | 7.0 | 5 878 | 5.8 M | 973 | 221 |
| 1 000 | 785 | 1.7 M | 2 896 | 13.4 | 11 756 | 11.6 M | 1 933 | 444 |
| 5 000 | 3 505 | 8.6 M | 14 416 | 66.4 | 59 463 | 58.3 M | 9 613 | 2 206 |
| 10 000 | 7 714 | 17.1 M | 28 816 | 132.5 | 125 017 | 116.6 M | 19 213 | 4 396 |

Everything is linear in the row count, which is the first thing worth saying: the
page does not degrade at some threshold, it scales exactly, and the constant is
large. **13 KB of memory per row on the read view and 0.44 MB per row on the
form** — a factor of thirty-three between two pages showing the same rows.

**Where it falls over is memory, and the number is 128M**: what a PHP request is
allowed, which is the stock default and is not raised anywhere here. The **edit
form crosses it at roughly 250 lines** and the **read view at roughly 9 500**.
Above that the page does not get slow, it answers 500 — and not a 500 anybody can
read: pinned at 512M to watch it happen, the form dies with "Allowed memory size
exhausted" inside Twig, half-rendered.

**Two hundred and fifty lines is a real document**, which is what makes this
finding worth acting on. Ten thousand is not.

**Neither view is expensive for the reason the ticket predicted.** XIV-68 blamed
the row of inputs; the inputs are not what costs.

- **The read view's queries were the drift check, and XIV-54 removed them.** An
  order line inherits three values from its article (§5.1's copied values) and
  `InheritedValues::driftedIn()` resolved the reference once per inherited field
  with no memo — **three identical `SELECT`s per line**, which was 28 800 of the
  read view's 28 816 queries at ten thousand rows. The table above was measured
  before XIV-54 landed, and predicted that ticket would remove "a percent or so"
  and leave the page O(N). **That prediction was wrong**, because XIV-54 widened
  its own scope to cover exactly this: `InheritedValues` reads through the shared
  `ReferenceTargets` memo now rather than going to `RecordRepository` per field.

  Re-measured on the merged tree, same catalogue of 250 articles:

  | rows | read queries before | read queries after | read ms before | read ms after |
  | ---: | ---: | ---: | ---: | ---: |
  | 10 | 50 | **18** | 47 | 47 |
  | 100 | 391 | **18** | 240 | 91 |
  | 500 | 1 600 | **18** | 682 | 323 |
  | 1 000 | 2 896 | **18** | 785 | 555 |

  Flat, not merely bounded by the catalogue. What remains linear on the read view
  is bytes and memory, which is the rendering rather than the reading — so the
  read view's ceiling is still where the table above puts it, and it is no longer
  a query problem at all.
- **The form's queries were the picker, and XIV-87 removed them without moving
  the ceiling.** `RecordReferenceType` resolved its candidate list through a lazy
  option, which the resolver computes per form *instance* — and a collection row
  is a form, so five hundred lines rebuilt the same list five hundred times.
  Reading it once per request took the 500-line form from **973 queries to 13**,
  the same 13 a 100-line form makes, and about a third off the render time.

  **It moved memory from 221 MB to 212 MB and the byte count not at all** —
  5 830 106 before, 5 830 105 after. That is the finding worth keeping: every row
  still *renders* two hundred `<option>` elements whether the list behind them
  was read once or five hundred times, so the edit form's limit is a **rendering**
  cost rather than a query cost. XIV-68's estimate that fixing the picker would
  move the ceiling from ~250 lines to ~400 was extrapolated from shrinking the
  *catalogue*, which also removes the options from the HTML; batching the reads
  does not. What would actually move it is a control that never emits the options
  — which is XIV-36's autocomplete, arriving from a direction nobody chose it for.

- **And it did.** XIV-36 makes a picker with more than twenty candidates a search
  box, and a catalogue of 250 articles is exactly such a picker — so a 500-line
  order form stopped emitting 200 `<option>` elements per row without anybody
  choosing that as the fix. Measured on one machine, both branches back to back,
  the same 500 lines against the same 250 articles:

  | | bytes | peak MB | queries | ms |
  | --- | ---: | ---: | ---: | ---: |
  | before (XIV-87) | 5 829 901 | 268.9 | 13 | 4 186 |
  | after (XIV-36) | **2 173 433** | **233.6** | 15 | **3 032** |

  **Bytes −63%, memory −13%, time −25%, and two queries more.** The two are the
  candidate list `auto` decides on — read once for the request, not once per row
  — and one priming statement; both are flat in the row count. Memory moves far
  less than bytes because §5.1's other finding still holds: the weight of the
  form is the *forms*, one per row with a `FormView` behind it, and no widget
  changes that. So this raises the ceiling somewhat and does not remove it, which
  is the honest reading of the same table that predicted it.

  **It needed the memo to be true.** An autocompleting picker has no candidate
  list to name the linked record out of, so each row asked separately what its
  article was called — 494 queries on the first measurement, worse than what
  XIV-87 had just fixed. Reading through `ReferenceTargets` and priming the
  rows in `RecordFormData` (§5.3's argument, applied to the form rather than the
  page) is what brings it to 15.

- **The form's weight is the form.** Every row is a Symfony form of eight
  controls with a `FormView` behind it, and that is the bulk of the 0.44 MB.
  Measured against a catalogue of 25 articles instead of 250 — which shrinks the
  picker from 200 options to 25 — the same 500-line form drops from 5.8 MB and
  221 MB to 2.3 MB and 168 MB. So the picker is 60% of the *bytes* and only a
  quarter of the *memory*: `RecordReferenceType` re-resolves its candidates per
  form instance, which is two queries and 200 `<option>`s per row and is worth
  fixing, but fixing it moves the ceiling from ~250 lines to ~400, not to
  thousands. A page that builds one form per row is expensive per row, and no
  amount of query work changes that.

**The record page has a second unbounded render nobody has counted.** The history
card shows five entries (§5.2), and `_history.html.twig` draws every collection
change inside each of them — so the entry recording the creation of a
10 000-line order is 10 000 list items on the record page, beside the 10 000 rows
of the lines table. Bounding `findChildren()` alone would leave that standing.

Two things that turned out **not** to be the problem, recorded so nobody
re-measures them: writing a long document is fast — 2.4 s for ten thousand lines
with the derivers running, two per cent of what drawing the form costs — and the
row data itself is small. The rows are not the weight; what the rows make the
page build is.

#### Four hundred rows, and the room to draw them (XIV-68)

The decision the table above was taken for. **Four hundred rows is the supported
size of a collection**, refused above that at write time, and a request is
allowed 256M so that four hundred renders.

**Why four hundred and not a number the measurement points at.** It is not a
ceiling, it is a promise: orders and invoices are usually well under a hundred
lines, so four hundred is ample for the documents this is for, and it is a round
number somebody can hold in their head. What the measurement decides is not the
cap but what has to be true underneath it — and on that it was decisive.

**The cap alone was not sufficient, which is the part that is easy to miss.**
Re-measured on the merged tree after XIV-36 took 63% off the form's bytes, same
250-article catalogue, `APP_DEBUG=0`, per request:

| rows | edit form peak |
| ---: | ---: |
| 100 | 35.9 MB |
| 300 | 105.9 MB |
| **400** | **140.3 MB** |
| 500 | 173.1 MB |

The 100 and 400 rows were taken again after the cap and the ini change landed —
35.9 MB and 140.3 MB, both answering 200 — and 500 cannot be taken again, because
the cap now refuses to build the fixture. That is the right way round: the tool's
job is no longer finding the ceiling but confirming that the supported size still
draws, so its default sizes stop at 400 and measuring past it is a deliberate act.

About **0.35 MB per row on a base near 1 MB**, straight-line as everything here
has been. Nothing in `frankenphp/conf.d` set `memory_limit`, so a request ran on
PHP's stock **128M** — which puts the genuine ceiling at **~365 rows**. A 400-line
order would have answered 500 at exactly the number being called supported.

So `memory_limit = 256M`, in `frankenphp/conf.d/10-app.ini`, with the measurement
named in a comment beside it. That is the whole of "make 400 work": one line and
no product code. It puts a 400-row form at **55% of its allowance**, which is
headroom for the per-row constant to drift as the form grows rather than a figure
sitting just above the requirement. **Raising the cap means moving this with it**,
at about a third of a megabyte a row.

**Refused at write time, not truncated at render time.** A record page that
quietly drew 400 of 500 lines would be a document lying about itself, and a
document that lies is worse than a save that refuses. Three paths write rows —
the record form, the importer (XIV-26) and anything holding `RecordWriter` — and
they meet at `RecordWriter::save()`, so **the check is there and there is no
fourth path to remember**. The form and the importer each ask first anyway, and
that is not the check repeated: it is each of them turning the same refusal into
what its reader is looking at, a message on the form and a problem against a
sheet. The number and the sentence live once, in `Core\Record\CollectionLimit`.

The message names the limit *and* the attempted count and says what to do —
the shape XIV-35's truncation notice established, where a bound reads as a bound
rather than as something having gone wrong.

**Shape C — paginating the edit form — was declined rather than forgotten.** It
was the right answer while the ceiling looked like 250 rows and a legitimate
3 000-line order looked plausible. Against a 400-row cap it is a large change to
what a partial submit means: positions are renumbered across the whole list
(XIV-21), a collection the writer is handed is *the* contents of that collection
and anything missing is removed, and every derived total on the record is a fact
about all of its lines at once (§5.9). Paying for all three to serve a case that
does not arise is the trade being refused.

**The read view keeps no bound of its own**, and that is a decision rather than an
omission. It is 18 queries flat at every measured size (XIV-54) and about 15 KB
per row, so it survives to roughly 9 500 rows; with writes capped at 400 it is
never within an order of magnitude of trouble, and a second limit would be a
number somebody has to keep in step with the first for no benefit. The same
argument retires the history render noted above: `_history.html.twig` draws every
collection change inside each of the five shown entries, so the creation entry of
an N-line order is N list items — alarming at 10 000 and unremarkable at 400.

**Nothing is rejected retroactively.** The cap is a rule about writes. A record
holding more than it still reads, and nothing can have produced one, because the
genuine ceiling was below the cap until the memory limit moved.

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

**One closed disjunction, which is not that `OR`** (XIV-36). A `RecordQuery` may
carry a `Search`: one string, looked for across a fixed set of the shape's own
fields, compiled as a single parenthesised group and ANDed with everything else.
It exists because a record is named by its *title fields* — plural — so a search
that could only look in one of them would find Ada by "Ada" and not by
"Lovelace", which nobody would call a search. What the paragraph above refuses is
a tree: something that composes, that a URL can express, and that needs an
interface to build. This composes with nothing, its fields come from the
definitions rather than from a request, and a field whose type cannot answer
"contains" is skipped. The parentheses are the load-bearing part — without them
the group's last term would bind to the `AND` chain and the soft-delete and
access predicates would stop applying, which is a permission bug wearing a syntax
error's clothes.

#### Once a set of records is in hand, read what it names (XIV-54)

A reference renders as the *name* of the record it points at (§7.6), and a name
is a second row from a second table. Asked for one value at a time — which is how
a template renders — that is a lookup per value.

**The number that matters is a collection's, not a list's.** XIV-46 measured this
against a 25-row list and XIV-53 then removed most of what it measured, so both
earlier conclusions were about the wrong number. `findChildren()` has **no
LIMIT**: a record page draws every row a collection has, so an invoice with 500
lines draws 500 rows and each one names an article. Measured on an order page
before this existed: 34 queries at 5 lines, 214 at 50, **2014 at 500** — four per
row, because a line asks about its article twice over (the reference for its name,
the drift check for whether the price copied off it still matches, §5.9) and the
drift half had no memo at all. The same rows drawn into a .docx cost 503 for 500
lines, which is the worse place to pay it: the request is already waiting on a
converter.

**The objection that had blocked batching was about rendering and not about
data.** There is indeed no moment during rendering at which every id is known —
`display()` is called per value, one row at a time. But both call sites hold the
whole set *before* rendering starts: a list has its page back from `findBy()`, a
record page has every row back from `findChildren()`. So the priming pass has an
obvious home, and the shape is one `WHERE id IN (…)` per target module — the same
move `findChildrenOfAny()` already makes one level up, copied rather than
reinvented.

Four decisions hold it together:

- **Priming is an optimisation and never a requirement.** Every reader still
  falls back to a single memoised lookup, so a caller that forgets is slower and
  never wrong. A seam that breaks when nobody calls it would be worse than the
  queries it saves, because forgetting is silent and every new call site is a
  chance to forget. `RecordPrimer::prime()` is therefore called from the list, the
  record page and the document path, and none of them has to.
- **The primer does not know what a reference is.** It groups a shape's fields by
  type and hands each type its own; a type that can use a whole set says so by
  implementing `PrimesFromRecords`. Batching *per target module* is then the
  type's decision, which is knowledge the primer would need a switch on field type
  to have — the thing field types exist to prevent, and the same argument
  `LinksToRecord` makes about drawing an anchor.
- **One memo, three readers.** The records live in `ReferenceTargets`, which the
  name, the link and the drift check all read. That removes a duplicate lookup
  that predates the batching: the page asked the database for the same article
  twice per row. A record looked for and *not* found is remembered as missing, so
  a collection full of stale links stays bounded too.
- **The memo dies with the request** (§7.4). It holds one customer's records, so
  anything longer-lived would eventually name their articles on somebody else's
  page — a wrong label rather than an error, which is the kind that ships. It
  implements `ResetInterface`, so Symfony's services resetter empties it on
  `kernel.terminate` rather than the guarantee resting on the process ending.
  That was not true before: under `disableReboot()` the old memo visibly survived
  from one request into the next, which is exactly the shape §7.4 warns about.

**Priming reads under exactly the rule `titleOf()` already read under, which is
unscoped** (§8.4, XIV-42). The name of a linked record is shown to anybody who may
read the record pointing at it; whether they are offered a *link* is the separate
question, and it is still answered per reader, from the record already in memory.
Stating it rather than quietly widening or narrowing it is the point: a batched
read is where a permission changes by accident, and the ticket asked for the
existing rule and not a second one.

Afterwards, the same pages: **16 queries at 5, 50 and 500 lines** — flat, and the
assertion in `ReferencePrimingTest` is `assertSame` between two sizes precisely so
that a bound which starts growing again fails rather than merely gets slower. The
document path: 503 → 4. The list, which was never the case this was built for and
was primed because by then it was one line: 32 → 8 for 25 rows naming 25
contacts.

Not touched here, and named so it is not confused with this: the unbounded row
count itself is XIV-68. Priming makes 500 rows cheap to *name*; it does not make
drawing 500 rows a good idea.

---

### 5.4 The metadata editor

**Definitions are read once per tenant** (XIV-53). Every field type asks for its
own shape, every reference for its target's, every reverse-link group again — so
metadata was the largest single source of queries on every page measured
(XIV-46). A record list naming twenty-five different contacts made 83 queries and
now makes 33.

The lifetime is the whole design, because a cache of one customer's definitions
handed to another is §7.4's hazard and would look like wrong labels rather than
like an error. It is **emptied whenever the tenant context moves**, in the same
breath as dropping the identity map and closing the connection, because they are
one fact about one boundary — a web request is a process and cannot outlive
itself, but a console command walking every tenant can. Writers empty it too: a
page still showing the shape somebody has just edited would look like the edit
had failed.

There is deliberately no tenant *key*. Keying it would make it look safe to keep
entries across a switch, and a definition kept across that boundary would load
its fields on whatever connection is current — the hazard, not the fix.


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

**What the form does not mention, it does not touch** (XIV-26). Options are where
the declarative half of the engine lives — a choice field's `choices`, a
reference's `module`, an order line's `inherit`, a numbered field's `sequence` —
and this form draws three settings. It used to save the whole options array,
which meant renaming a label wiped everything the form had never heard of: a
module's states, a shape's variants, a link's target, none of it typeable back in
because the editor has no control for any of it. Saving now names only what it
drew, and a setting it means to *empty* it names as null — the difference between
"not mentioned" and "mentioned as nothing" is what lets a form both leave alone
what it does not know and still clear the boxes it does.

That is a patch over the real shape, which is that a **type** should say which of
its options are the customer's to set — the same way it already owns its
validation, its storage and its widget. Then the editor could draw the right
controls per type instead of three fixed ones, and a numbering pattern would be
editable by the person whose numbers they are.

**The first step in that direction is taken** (XIV-36). Autocomplete is a setting
that clearly belongs to the customer and means nothing on most types, so the
editor draws its control only for the types that declare they have it, and names
it in the save only for those — a `text` field's save therefore cannot clear a
setting it never had. It is deliberately one option and an `instanceof` rather
than the general declaration above: generalising an interface from a single
example is guessing at XIV-27's shape with one example's worth of evidence. What
XIV-27 has to do is replace that check with a declared list, not invent the idea.

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

**Adding a record asks which kind first.** The fields depend on the answer, so
something has to settle it before the form is drawn. This used to be forced —
switching them as somebody picked would have needed scripting, which the forms
did not depend on — and since §8.3 it is a choice, kept because "new person" or
"new company" is how a CRM usually puts it and a record's kind is a bigger
decision than a row's.

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
button links to a chooser page and Bootstrap opens that same form in a modal —
the modal and the page are one form, which is why the route takes its template
and format as query parameters rather than in the path. That arrangement was
built so the download worked either way (§8.3); what it is worth now is that
there is one route and one form rather than two.

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

**A placeholder nothing will fill is now said out loud** (XIV-25). An order
template printed `[contacŧ]` into a finished document instead of the customer's
name — the last character being U+0167, `t` with a stroke, which is AltGr and the
key beside `t` on a Swiss layout and at body-text size is indistinguishable from
the letter it is not. Nothing is called that, so the generator left the words
alone. **That behaviour is right and the silence was not.** Blanking an unknown
marker would swallow the mistake, and the rule above already fills every marker
the engine *knows* with the empty string precisely so that nothing prints its own
brackets by accident. What was missing is that nobody was told: a bracket in a
finished PDF has two readings — "the engine failed to replace it" and "you typed
something else" — which look identical on the page, and the first is where
everybody starts.

**It is a comparison, and both halves already existed.** `TemplateTokens` reads
the `[tokens]` out of the .docx and `DocumentMarkers::keysFor()` says what this
module's vocabulary is — its fields across every variant, the general markers,
and the collection markers including the per-kind forms (§5.11). What the second
does not answer, the first reports. The scan was **extracted rather than
written**: `RepeatingBlocks` was already stripping the markup and reading
brackets out of the resulting text, because Word cuts a placeholder somebody
typed in one go across several runs, and a third private copy of that trick is
how three scanners come to disagree about what a marker is — with the
disagreement surfacing as a report that calls a good template broken, or misses
the one that is. The extraction moved where the `strip_tags` lives and changed
nothing the generator decides.

**It reports and it does not refuse.** Square brackets in a letter are legal
prose, a customer may be half-way through writing a template, and a token nobody
recognises may well be one somebody meant. So the upload is accepted and a second
sentence appears beside it, and **the wording says what will happen to the text
rather than what is wrong with it**: "`[contacŧ]` will be printed just as it is —
there is no placeholder by that name."

**Said beside the template, not only at upload.** The check runs for every
template each time the templates page is drawn, which is what covers the case
upload-time checking cannot: a template written against `[vat_number]` goes stale
the afternoon somebody removes that field, nothing about the file changed, and
the one moment a check on upload would have caught it is long past. The cost is
one unzip per template on a rarely-visited page holding few of them, which is
affordable exactly because templates are small and few for the reasons given
above. The upload also says it in a flash, so the same sentence appears twice on
that one screen — kept on purpose, because the flash is about the upload somebody
just made and the line on the row is about the template from then on, and a
template sorted into the middle of a long list would otherwise say this where
nobody has a reason to look. One translation key writes both.

**The vocabulary, not the record.** A template naming no kind of record and using
`[company_name]` is not reported, even though that marker comes out blank on a
person. It is a real marker of the module; the reason it is empty is the record
in front of it rather than the template. Reporting it would put the upload page
in an argument with the reference list printed beside it, which is a worse
wrongness than the one it would catch.

**Unused markers are deliberately not reported**, and the ticket left that open.
A template that never mentions the record it belongs to may well be a mistake,
but "you did not use `[status]`" belongs on every upload of every template and is
therefore noise nobody reads twice — and a reader who has learned to skip that
line skips the unknown tokens sitting next to it. The two are not the same kind
of fact: an unknown token has a wrong answer behind it, an unused one is a
preference about how somebody's letter reads. If it is ever wanted it wants a
quieter place than this one.

**And no copy button on the reference list**, which the ticket also raised. It
would help only at the moment somebody types a *new* token, and the more common
way a template goes wrong — a field renamed under a template written months ago —
is untouched by it; the report catches both. It would also mean an interactive
control inside `_markers.html.twig`, which the email templates page (§5.13) draws
from the same macro and has no upload to protect. Worth doing on its own ticket
if hand-typed tokens turn out to be a recurring source of this, rather than as a
reflex attached to the one that reports them.

*Repeating blocks, once still to decide, are §5.11 — a template can lay out a
contact's addresses or an invoice's lines, and a table row carrying a collection
marker is what grows.*

**A marker that resolves to an image is a change to this pipeline, not a key in a
list — so it is XIV-89 and not XIV-49.** The decision is written here because the
reasoning belongs next to the pipeline it is about.

Every marker above resolves to **text**. `DocumentMarkers::dataFor()` returns
`array<string, string>` and `DocumentGenerator::fill()` hands that straight to
`anourvalar/office`, whose `DocumentService` runs on a `ZipDriver`: it opens the
.docx as a zip and replaces strings inside the XML parts. There is no image path
in that library at all — it has a driver for the spreadsheet side and nothing
equivalent for a Word drawing. So `[tenant.logo]` is not another entry in
`InstanceContext::markers()`; it is DrawingML written by hand, which means a media
part in the package, a relationship with an `rId` that cannot collide with the
ones the customer's own template already uses, a `[Content_Types].xml` override, an
extent in EMU and therefore a decision about how large a logo is on a page, and —
the part that actually inverts the design — **replacing the marker's run with an
element instead of substituting its text**, in a file where the marker may be split
across several runs, which is the exact case this library was chosen to handle.
`RepeatingBlocks` walks the same file first (XIV-17) and `TemplateReview` (XIV-25)
would call the key unfillable until it learned about a marker that is not text.

That is enough to be its own ticket, and the alternative was worse in a way this
project has an opinion about: half of XIV-49 shipped properly and a second ticket
somebody can read beats both halves rushed. What did ship is the logo itself — the
upload, the storage, the bar and the sign-in page (§8.6).

One constraint carries over and is easy to lose: **documents are generated without
a browser.** Whatever draws the image reads the bytes out of `TenantProfile`
directly, never over HTTP and never through the public route §8.6 added, which
exists for a page and not for the engine.

*Emails are §5.13, and are deliberately not this. They are written in the
application rather than uploaded, and the reason is worth reading next to the
paragraphs above rather than assumed from them.*

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
  The rate on the article is **a number, not a choice of the three Swiss ones**:
  those are this year's rates and this is not a Swiss engine. Empty means no VAT,
  which is the right answer for a customer who is not registered for it, and such
  a customer sees no VAT table at all rather than one full of zeroes.
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

**The same arithmetic runs twice, and only once exists** (XIV-32). The form shows
these figures while somebody is typing, before anything is saved, and it gets
them by running the *same derivers* over values that are not going to be stored —
`DerivedValues` is that call, extracted from the writer when the second caller
appeared. The alternative was recomputing in the browser, which would be a second
implementation of the rounding rule above; the two would agree until they did
not, and the place they disagreed would be a rappen on an invoice, shown to the
person deciding whether to send it. What differs between the callers is what they
do afterwards, which is what should differ.

Nothing about that preview is validated. Somebody who has typed `2.` is
mid-number, not wrong, and the shape validation belongs to the save.

**A line contributes if it has a price, not if it is the right kind.** Comment
lines and subtotal lines fall out of the summing for having no quantity and no
unit price, which is a fact about the line rather than a branch about its kind —
so a fifth kind of line needs no arithmetic written for it. A subtotal is the one
thing asked about by kind, because a subtotal is defined by being one.

**The first decision above is not only about money.** §5.16 applies the same
argument to a date: an invoice's due date is derived and then stored, because
payment terms that change must not restate a deadline somebody was already given.

---

### 5.10 Document numbers (XIV-15)

A field may be numbered from a sequence: `ORD-2026-0001`, `INV-2026-0001`. Two
things can go wrong with a document number and both are fatal — one that changes
after somebody has read it down the phone, and two documents carrying the same
one — so the mechanism is small and the decisions are written down.

**Declared as an option, not as a field type.** A number is a string; what is
special about it is *who fills it in*, which is a fact about the field rather
than about the kind of value. So `NumberFormat::from('ORD-{year}-{number:4}')`
spreads into any text field's options, the way inherited values do (§5.1), so it
is per customer and changeable without a deployment.
*Not changeable by the customer themselves yet* — the editor's form draws three
settings and this is not one of them (XIV-27). The mechanism is theirs; the
control is missing.

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

### 5.12 One record made from another (XIV-19)

An invoice is made from an order, a delivery note from an order, an order from a
quotation. It is the commonest thing an ERP does and it is always the same thing:
copy a header, copy the lines, keep a link back, and never take the same line
twice. So it is **declared** — `Seed` names the source module, the field holding
the link, and the fields and rows to bring along — rather than written once per
pair of modules, which would be a class per pair.

**Copied, never read through.** The new record holds its own values from the
moment it exists. That is what lets an invoice stay correct after the order is
edited, and what lets a second invoice hold different lines from the first. Once
issued, an invoice is a document and stops following anything. The link is kept
beside the copy so reporting still knows where it came from — the same shape as
an order line's article (§5.1), one level up.

**Seeding is not saving.** What comes back is a *form*, filled in, that somebody
reads and changes before pressing save. A document that appeared fully formed the
moment a button was pressed is a document nobody checked. It is also why the
seeded page is the ordinary new-record form: a seeded form and an edited one are
the same page, which is what makes the seeded one editable at all.

**What is left is read, not stored.** A "quantity invoiced" column on the order
line, kept in step by whoever writes an invoice, is a second record of a fact the
invoices already hold, and the two disagree the first time somebody deletes one.
So each seeded row records **which row it came from**, and what a source row has
left is its quantity minus what every document made from it took. A row with
nothing left is not offered again; the order's own page shows what is still
outstanding on the line rather than in a total nobody can check against a line.

That row reference is a plain number rather than a `reference` field, because a
reference points at a *record* and a collection row is not one — it has no page
and no life of its own (§5.1). What it is for is arithmetic, not a link somebody
follows.

**Through the reader's own permissions.** Working out what is left means reading
the other module's records, and being allowed to open an order is not being
allowed to read the invoices made from it (§8.4). Somebody without that grant is
told the order is wholly uninvoiced — the safe direction to be wrong in, and they
cannot make an invoice either way.

**A sent document is not edited; it is corrected by another document.** An
invoice that has gone out loses both the transition back to draft and the right
to be changed, because the customer is holding a copy and the two would disagree.
Correcting one is a credit note — a second document that says what it corrects —
which is also the only version of the fix an auditor can follow. This is the
lifecycle rule (§5.8) doing the work rather than a special case: a state that
ends editing already existed, and *sent* is one.

**Two things are deliberately not copied**: a line's total and a subtotal's
figure. Both are derived on save (§5.9), so a partial invoice restates its own
subtotals instead of repeating the order's — which on an invoice for half the
lines would be the most convincing wrong number in the system.

*What this cost the engine is the measure of the six tickets before it.* The
invoice module is a declaration and a translation file: no controller, no entity,
no form, no class the engine calls. The one thing it did cost was moving the
order module's totals into core behind a `LineTotals` declaration — two modules
needing identical sums is the engine's problem rather than theirs, and the
alternative was the same hundred lines twice, drifting apart the first time
somebody fixed a rounding bug in one of them.

---

### 5.13 Email templates, written here rather than uploaded (XIV-38)

The counterpart to §5.7, and the ticket that says why the two are not the same
shape. A document template is a .docx because a letter's layout is somebody's
design work and Word is where that work happens. An email has no layout worth
designing — it is text — so asking somebody to edit it in Word, upload it, and
upload it again to fix a typo would be ceremony bought with nothing. An email
template is therefore **a form in this application**: a name, a subject and a
body in **Markdown**, kept as text in the customer's own database rather than as
the blob a .docx needs.

**The base template ships in code and a tenant cannot edit it.** A customer
writes the content part; the wrapper — the HTML skeleton, the footer, the sender
block — is ours. That is §6.1's existing rule rather than a new one: presets live
in code, templates live in data. Somebody who could edit the wrapper could break
every email they send, and the wrapper is not the thing they wanted to change.

**There is one of it, not a named set**, and this was decided rather than
assumed. A second base template only earns its place when two emails need
different frames, and nothing needs that yet: what varies between a reminder and
an order confirmation is the words, which is exactly the part a tenant already
writes. A set would also have to be chosen from — a column on `email_template`
whose only value would be "the default", plus a picker beside the fields somebody
actually came to fill in. When a real second frame turns up it brings its own
requirements with it, and adding a column then is a smaller thing than guessing
at one now.

**The markers are `DocumentMarkers`, not a second implementation.** The same
class, the same keys, the same values rendered through the same field types
(§5.7), including the general ones the application answers through
`DocumentContext`. So a field added this morning is a marker in an email this
afternoon, and there is no second vocabulary to keep in step with the first. That
reuse is most of why this feature is small.

**Repeating blocks are deliberately out of scope.** `RepeatingBlocks` (§5.11)
scans `<w:tr>` elements out of Word's XML: the table row is the unit because it
is the unit Word gives a person. Markdown has no equivalent, and choosing one —
a list item? a table row? a fenced block? — is a design question rather than a
port. A collection marker written into an email comes out blank, which is the
same "blank beats brackets" call any unfilled marker gets, and the page does not
offer the tokens. An email that lists an invoice's lines is a real want and gets
its own ticket once somebody has decided what a repeating block *is* here.

**Markdown, and the two things that follow it.** `league/commonmark`
(BSD-3-Clause) turns the body into HTML — permissive, which is the bar §5.7 set
when PHPWord was rejected on LGPL. It brings `league/config` (BSD-3-Clause) and
through it `nette/schema` and `nette/utils`, which are offered under
BSD-3-Clause *or* GPL-2.0 *or* GPL-3.0: a disjunction the licensee chooses from,
so the BSD terms are the ones taken. Worth writing down because a grep for "GPL"
in the lock file finds them and says nothing about which licence is in force.

**Raw HTML is disabled *and* the output is sanitized**, which is one more than
the ticket asked for, and the two defend against different things.

- **Disabled** (`html_input: escape`) is the primary decision, and it is not
  really about the template author — they are a signed-in colleague. It is about
  the *values*: markers are substituted into the Markdown source **before** it is
  parsed, so a contact whose company name contains a script tag would otherwise
  be a route from one customer's record into the markup of an email. Escaping at
  parse time closes it at the point where "text somebody typed" and "markup" are
  still distinguishable. Substituting after parsing was the alternative and is
  the unsafe one — it would mean hand-escaping each value at the moment the code
  has stopped thinking about escaping. The price is that a value containing `*`
  or `_` can read as Markdown, which is a formatting oddity in one email against
  a script tag in every one.
- **Sanitized** (`symfony/html-sanitizer`, MIT) is the second layer and is not
  ceremony. CommonMark emits markup of its own from ordinary Markdown, and
  `[click](javascript:…)` is a link somebody can type with no raw HTML involved
  at all. The sanitizer is what makes the allowed elements, attributes and URL
  schemes a *policy* — Symfony's component and Symfony's configuration rather
  than an allow-list written here — and what keeps the pipeline honest if raw
  HTML is ever turned back on for a reason nobody has thought of yet.

**The Markdown source is the plain-text part.** A well-formed email carries both,
and here the thing somebody typed *is* the text alternative, markers filled in.
Nothing generates it by stripping tags out of the HTML, which is the step that
quietly produces a text part nobody wants to read — and that is the quiet
argument for Markdown over a rich-text editor, which would have left us doing
exactly that. A textarea is the whole editor; the preview belongs to XIV-39.

**Writing templates is its own permission**, `email_templates`, beside
`templates` and `document` rather than folded into either. Whoever words the
dunning letter is not whoever designs the stationery, and neither is whoever
presses send — which is XIV-39's third permission, and the sharpest of the three.

**Core answers with the contents and stops.** `EmailRenderer` hands back a
subject, an HTML document and a text alternative, not a `Symfony\…\Mime\Email`:
building the message would mean core deciding who it is from and who it goes to,
and it knows neither. Those are the application's facts and XIV-37's subject.

### 5.14 Sending one from a record (XIV-39)

The ticket where §5.13's contents and §8.7's transport meet, and the shape is
§5.7's on purpose: **one button on the record and a chooser behind it**, never a
button per template. A contact with fifty templates would otherwise carry a
column of fifty buttons, which is the layout the document chooser already
replaced once. The modal and the page are one form, for the same reason they are
there — one description of what a send asks for rather than two that agree today.

**Two ways out of the chooser, and the fast one is the one that needed care.**
"Send" without a preview is what somebody wants on the tenth invoice of the
morning, and it is right to offer it. It is also irreversible with no undo, so
what makes it safe is not a confirmation dialog nobody reads: it is that the
**resolved recipient and the subject are on the screen before the button is
pressed**. "Preview and send" is the same form posting somewhere else, and what
it renders is the base template with this record's markers filled in — the only
honest way to find out that `[contacŧ]` was typed with the wrong letter before a
customer reads it. The preview shows who the message will appear to be from as
well as what it says, because a customer with their own SMTP server and one
without get different answers (§8.7) and a preview that omits it is a preview of
something else.

#### Where the recipient comes from, which is the weight of this

The engine does not know which field holds an email address and cannot: a module
is a declaration, and "the customer's address" is a fact about *that* module's
shape. So a module **declares** it, the way `Seed` declares where a record is
made from and `LineTotals` declares which fields are money. Guessing instead —
the first field of type `email`, or one literally called `email` — is a rule that
works on the contact module and silently picks the wrong address for the first
customer who adds an `invoice_email` beside the one they use.

Two cases, because both are ordinary:

    new MailRecipient('email')                      // a contact's own address
    new MailRecipient('email', through: 'contact')  // an invoice has none; its contact does

**One hop through a `reference` (§7.6), and a second is deliberately
impossible.** It is the same rule the query layer already holds for filtering
through a link, arrived at from another direction: `invoice.order.contact.email`
is two joins whose cost cannot be estimated from the declaration, and it makes
"where did this address come from" a three-part answer on a screen where somebody
is about to send a customer a bill. The case that would have wanted two hops does
not arise, because an invoice already *copies* its contact from the order it was
seeded from (§5.12) — the same copying that keeps an invoice correct after the
order is edited is what keeps this to one hop.

**The hop is read unscoped, and that is XIV-42's split arriving again.** There,
the *name* of a linked record is read unscoped because an order whose customer
reads `#14` is an order nobody can use, while the *link* is offered only where
the reader could open the target. An address is the first half: whoever may send
an invoice may reach the address that invoice is for, or "may send invoices"
would quietly be two grants with the second one unnameable. What protects the
address is that the send grant is on the module holding the link.

**The declaration is read from the blueprint; whether it still applies is read
from the customer's definitions.** A tenant who deleted their email field has a
shape that does not send mail, and that is answered once for the module rather
than as a problem repeated on every one of its records.

#### The address is shown, editable, and never written back

A wrong address is not recoverable and the person pressing the button is the last
check there is, so it is a field rather than a label. Editing it is emphatically
not an edit of the record: **sending a mail somewhere once is not a correction to
the contact**, and a screen whose "send" quietly rewrote a customer's email
address would be the worst kind of surprise.

It is a *correction* rather than a *substitute*, which is why **a record whose
address cannot be resolved offers no send** — and is refused if the send is
posted by hand anyway. Allowing a free-typed address on a record that names
nobody would make the declaration optional and turn the send screen into a way to
mail anybody at all from inside somebody else's ERP.

**And it says why, in the customer's own words.** "No recipient" sends somebody
looking at the wrong record; "The Customer this record names has no Email" says
which record is missing what, and both nouns are the tenant's own field labels
(§6.1) rather than anything the engine could have written down. Five cases — no
link, a stale link, an empty field, a value that is not an address, and a module
that never declared one — and the last of those draws **nothing at all**: an
articles list has nobody to write to, and a page apologising for the absence of a
feature it does not have is noise on every record of it.

#### The timeline, and why a failure is a verb rather than a flag

§5.2 admitted one entry that changes nothing — a document generated — and warned
that the next candidate should have to argue the same three properties. A send
argues them harder: it is rare, deliberate and attributable, and unlike a
document it **left the building and cannot be recalled**. Recorded: who, when,
which template, to what address, and what the subject said, with the recipient
stored rather than resolved again later, so editing the contact next year does
not rewrite who a mail was sent to.

**A failure is `email_failed`, a verb of its own, not an `email_sent` row with a
flag inside it.** A timeline is read by scanning its left-hand column, so a
failure that only announced itself in the detail is a failure somebody reads
past — and "nothing in the timeline" and "it went out" would still look the same,
which is exactly what §8.7 said must not happen. It is written by the object that
performs the send rather than by the controller, because that is the only place
that cannot forget one of the two outcomes: put the history write in the caller
and the happy path gets an entry while the `catch` block gets a flash message.
The person who pressed the button is told either way — that is what `MailSendFailed`
being thrown rather than swallowed is for.

**Sending is its own permission**, `send_email`, beside `templates`, `document`
and `email_templates`. The third of that row and the sharpest: a document that
should not have been generated stays on somebody's laptop, and a mail that should
not have been sent is in a customer's inbox. It is also the one of the four that
names a record, so it can be scoped to "only my own" where the template
permissions cannot.

*Attaching the document to the send is §5.15, and the seam described above is
where it arrived: one more argument on the method that builds the `Mime\Email`,
one more key on the named constructor for the timeline entry.*

### 5.15 The invoice goes with the mail (XIV-40)

The two features meeting, and the thing anybody actually wants an ERP to do with
an email. §5.7 already turns a template, a record and a format into a document
and §5.14 already sends one of §5.13's messages from a record, so the picker
gains a document and a format and the file goes out attached. That part is small
and is not what this section is about.

**Attaching means generating, so it takes both grants.** `send_email` is on the
route already; `document` is asked for again at the moment an attachment is
actually requested, and refused with a 403. The reason is the one §5.7 gave for
splitting `templates` from `document` in the first place, arriving one step
further along: somebody may be trusted to write to a customer and not to produce
that customer's invoice, and a send that quietly generated one would make the
second grant unenforceable through the first. "The picker was not on their
screen" is not a check — the form is a POST anybody can retype.

The second grant is asked on the **record**, not the module, because `document`
is scopable (§8.4). A check against the module alone would answer yes for
somebody scoped to their own customers and hand them everybody's.

#### One entry, and the attachment is a key on it

A document generated in order to be attached is **not a second event**. The
timeline records the send, and the send names what went with it:

    {"email": {"template": "Order confirmation", "recipient": "…", "subject": "…",
               "attachment": {"template": "Invoice", "format": "pdf"}}}

`attachment` holds exactly the pair a `document_generated` entry would have held,
which is the decision stated in the data rather than argued in prose.

Both entries was the alternative and it is worse in both directions. Reading
forwards, one button press would produce two lines — "document generated",
"email sent" — describing a single act, and §5.2 admitted the document entry on
the argument that it is *rare, deliberate and attributable*, which a side effect
of pressing Send is not on its own. Reading backwards, the pair would be
**indistinguishable from two acts that really happened**: somebody downloading a
PDF and then, for their own reasons, writing to the customer. Those are different
facts and a timeline that renders them identically has lost the one it was kept
for. Naming the attachment inside the send keeps "was the invoice actually on
that mail" answerable, which two adjacent rows never could.

What makes it mechanical rather than a rule to remember is that the generator has
two ways out: `pdf()`/`docx()`, which announce, and `contents()`, which does not.
The attachment path takes the second. That is also what lets the preview build
the very document it is previewing without a preview appearing in somebody's
history, which the announcing methods could not have done at all.

#### Failure is two-sided, and the two sides look different

- **The document could not be made** — a template that will not fill, a converter
  that is down. Nothing is sent, and **nothing is written to the timeline.** No
  message was ever built, so there was no send to have failed, and an
  `email_failed` row would assert an attempt that did not happen — §5.14 spent
  its argument on the verb being true, and this is the same argument used to
  refuse an entry rather than to add one. It joins the refusals §5.14 already
  makes silently: an unresolved recipient, an address that is not one, no
  template chosen. The person is told on the screen, in the document layer's own
  words, so the sentence is visibly about a document rather than about mail.
- **The send failed after the document was made.** That is `email_failed` exactly
  as §5.14 wrote it — and the entry **names the attachment**, which is what tells
  the two apart a year later: an `email_failed` carrying a document is a document
  that was made and a mail server that refused it, a different afternoon
  entirely from one that could not be produced.

Neither half half-succeeds, and the ordering is what guarantees it rather than
care: the document is generated and measured *before* a `Mime\Email` exists, so
"a failed generation sends nothing at all" is true by construction.

#### A ceiling, because a bounce is the worst outcome

Seven mebibytes of document, configurable as `XIVI_MAX_ATTACHMENT_BYTES`.

The number is chosen against what **receiving** servers accept, not against what
this one can produce, because that is where the failure being prevented happens.
Attachments travel base64-encoded — four bytes on the wire for every three — so
seven MiB of PDF arrives as a message of roughly nine and a half, inside the
10 MB that is both the most common conservative limit and Postfix's own default.
Gmail and Exchange Online take twenty-five; choosing against *their* number would
mean a document this installation is happy with and a quarter of the internet
bounces.

A bounce is what the ceiling buys off, and it is expensive: it arrives hours
later, at an address that is frequently nobody's inbox, about a message the
sender has stopped thinking about. The invoice simply does not arrive and nobody
finds out. A refusal on the screen naming the size and the ceiling is a worse
minute and a far better afternoon.

**Configurable because the honest answer is that we cannot know.** The authority
is the relay a deployment actually sends through, and an operator who runs their
own knows a number this project does not. The default is for the deployment that
has not thought about it, which is most of them. The check is on the document
rather than the assembled message: it is the part that varies by three orders of
magnitude — an email's words are kilobytes, the same shape every time — and a
limit somebody can compare against a file size they can see is one they can act
on.

**The preview generates too.** It costs a second conversion on the
preview-then-send path, and it is worth it: the preview exists so that what
arrives holds no surprises, and "the converter is down" and "this is too big to
send" are precisely the two surprises that would otherwise wait until the
irreversible button. The file name and the size on that screen are the real ones.

---

### 5.16 When an invoice falls due, and what makes it late (XIV-67)

An invoice had `issued_on` and a status and **no due date**, so "is this late" had
no answer — no widget, no list, and no dunning letter, which is the obvious thing
an ERP is expected to do with mail it can now send (§5.15). Two decisions make it
answerable, and both are the general shape rather than an invoice-specific one.

#### The date is stored, and this is §5.9's argument applied to a date

The tempting version computes it on read: `issued_on` plus the customer's terms,
worked out whenever somebody asks. It is wrong, and quietly. **Terms change.** The
day somebody edits a customer from thirty days to fourteen, every invoice ever
sent to them silently becomes due earlier — some retroactively overdue, for a
deadline that was never agreed. The other direction is worse: tightening terms
would make an invoice that was paid on time look late in its own history.

**What was agreed is a fact about that document.** §5.9 already argues this about
money: totals are derived and then *stored*, because a price list that changes
must not restate an invoice somebody has already been sent. A due date is the same
argument about a different kind of value, so it is the same mechanism — a
`ValueDeriver`, writing into an ordinary derived field, inside the save's
transaction and visible in the history entry that save produces.

**Materialised at the transition to `sent`**, which is not a tidy choice. That is
where the lifecycle already locks (§5.8) — the module's own words are "sent is the
end of editing… the customer has the document now" — and it is the first moment a
deadline means anything to anybody. A draft has no due date and does not need one:
nobody owes anything for a document that has not left the building.

**Written only into an empty field**, which is what "agreed once and never
restated" reduces to — the same rule §5.10 follows for a document number. So an
invoice cannot acquire a later deadline by being sent twice, and marking one paid
or cancelling it leaves the state and touches nothing. That last part is what keeps
an invoice predating this feature from quietly acquiring a due date, out of today's
terms, on the day somebody settles it.

**Existing invoices are not backfilled.** The column is nullable and a missing due
date means **not overdue**, never overdue. Backfilling would mean guessing which
terms were in force months ago, and guessing wrong in the direction that tells
somebody a paid invoice was late is worse than an empty column.

#### Overdue is a read, not a fifth state

The other tempting version is a state beside draft, sent, paid and cancelled. It
should not be one, for a reason that is structural rather than aesthetic: **every
existing transition is something a person performs** — send, pay, cancel. Nothing
performs *overdue*; the calendar does.

A state would need something to move invoices into it on a schedule, which is a job
mutating a customer's documents with no human act behind it — and there is no
worker process here, a constraint §8.7 and XIV-59 both settled around. It would
also be a state that can be *wrong*: a record is overdue the instant midnight
passes, and one whose lateness is a stored flag is late only once the job has run.

So overdue is `status = sent AND due_date < today`, evaluated when read. Cheap,
always correct, needs no job, and cannot drift out of step with the calendar.
Nothing is stored, so refining the definition later migrates nothing. It is
expressed twice from one declaration — as a question about a record in hand, which
is what a page drawing one wants, and as query conditions, which is what a *list*
of them wants, because counting overdue invoices by loading every invoice and
asking each one is the N+1 that a dashboard cannot afford on the first page after
signing in.

Strictly before today, not on or before: an invoice due today is due today, and
telling somebody their customer is late on the morning the bill falls due is how a
dunning list loses its credibility.

#### Three layers, and a payment term is a number of days

A term is a property of the *relationship* rather than of a document, so it lives
where the relationship does and defaults downward — the shape §8.4.2's language
and region settings already use, arrived at a third time:

- **the tenant's**, on the profile beside currency and region (§8.6);
- **the contact's**, which overrides it;
- **the invoice's own date**, materialised from whichever applied at the time.

The layer above always *overrides* rather than combines, so reading the effective
value is a `??` chain and never an arithmetic nobody can reproduce from the screens
it was typed on. The invoice stores the resulting **date** and not a copy of the
number of days: the date is what was agreed, and the days are the rule it came from
rather than a second fact about the document.

**Null at the top, rather than thirty.** A term nobody chose is not a term, and a
default here would put a deadline on the next invoice every existing tenant sends
— for a date nobody in that company agreed to give. It is the same call §8.6 makes
about the currency, and it lands in the safe direction: no term means no due date,
and no due date means not overdue.

**Days, and what that rejects.** "2/10 net 30" — a discount for paying early — is
two deadlines with two different amounts behind them, which the money model has no
room for while `status` is binary; a document settleable for less than its gross
total is a change to §5.9 rather than a change to a date. "Net 30, end of month" is
real and common and is a *rounding rule applied to the answer this already
produces*, so it can arrive later as an option on the same field without restating
anybody's terms. A free-text term — "on receipt", "before delivery" — is
unfilterable and uncomparable, which defeats the whole point, since the question
being answered is which of these is late and text cannot be compared to a calendar.
Zero is a real term and not an absence: payable on receipt.

#### Reading the customer's terms crosses no boundary

`invoice` declares `requires: [order, contact]`, which is a **metadata**
requirement (XIV-23) and not a code dependency — deptrac forbids one module package
importing another. So the declaration takes **one hop through a `reference`**
(§7.6) and names the field by key, exactly as §5.15's mail recipient does and for
the same reason: an invoice has no payment terms of its own and never will, because
they belong to the customer being invoiced. One hop, and a second is deliberately
impossible; the invoice already copies its contact down from the order it was
seeded from (§5.12), which is what keeps one hop enough.

Following it is an unscoped read of the other module, the same split XIV-42 made:
whoever may send an invoice may know when it falls due, or "may send invoices"
would quietly be two permissions with the second one unnameable. Nothing leaks in
the other direction either — the term is read once, at the moment the document is
sent, and what is kept afterwards is a date on the invoice rather than a restatement
of that customer's terms on every document ever addressed to them.

**What this deliberately does not do.** Partial payments (`status` is binary and
changing that is a much larger change to the money model), credit notes (§5.9's
module already says correcting a sent invoice is a second document), and dunning
letters — that is §5.14 plus a template. This only makes it possible to know who to
write to.

---

### 5.17 Demo data a field can have an opinion about (XIV-24)

The generator walks a module's definitions and asks each field's *type* for a
value, which is the whole reason it is worth having: it fills a field somebody
added in the editor this morning without having heard of that field (§5.4), and
a new field type gets demo data by implementing one method rather than by
editing a generator. Being dumb is the feature.

It is also the complaint. **The generator knows a field's type and its bounds and
nothing about what it means.** `tax_rate` allows anything from 0 to 100, so a
uniform draw across that range produced 63.90, 40.55, 15.10 — every value valid,
almost none a VAT rate, and a set of invoice totals that are arithmetically
perfect and tell you nothing about whether the feature works, which is what the
data was generated for. From the other direction, an article's `title` came out
"Kuhn GmbH", because the vocabulary has names and nothing tells it that a name on
an article is not a person's.

**A range is not a distribution.** Real numeric fields cluster hard, and the
uniform draw across everything allowed is the one shape real data never has.

So the question was not "how does the generator guess better" — that road ends in
a table of special cases keyed on field names, a second place that knows what an
article is beside the article module, which is the tax §5 exists to remove. The
question is **what is the smallest thing a field can say about itself so that the
guess is good**, and the precedent was already there: inherited values, number
formats and column widths are all declarations on the field.

**Hence one option: `samples`, a list of values the field's demo data is drawn
from.** Read in one place, `Xivi\Core\Demo\FieldSampler`, which sits between the
generator and the type registry. No field type changed, and a field that declares
nothing reaches its type by a path that — deliberately — draws no random number
of its own, so it consumes the seeded sequence exactly as it did before. That is
the criterion protecting every field nobody has said anything about, and it is
asserted rather than assumed: the suite samples every field of a module that
declares nothing, through the sampler and through the types directly, from the
same seed, and compares value for value.

**Which record gets which value stays the seed's business.** The draw uses the
same `mt_rand` sequence as everything else, so `--seed` still makes a run
repeatable with declarations in play.

**A declared value is treated as though somebody had typed it.** Nothing converts
it on the way in; the write goes through `RecordWriter` like every other write, so
`8.1` on a decimal is stored `8.10` exactly as the form would store it. That also
sets the standard of care: a declared value the field would refuse is a value the
generator will write, in the same way a `min` above a `max` is.

**Weighting is repetition, not a second concept.** "Some articles with no VAT at
all" wants either weights beside the values or an empty value among them, and the
second is smaller: the list stays a list, the draw stays uniform, and a value that
should come up more often is written twice. `[8.1, 8.1, 8.1, 2.6, 3.8, null]` is
half a catalogue at the standard rate and one article in six sold without VAT.
`FakerSampleValues::country()` has drawn from `['CH', 'CH', 'CH', 'DE', …]` since
it was written, so this is the project's own idiom rather than a new one.

**Two declarations are dropped rather than trusted**, because both would break the
promise the generator is actually measured on — that everything it makes passes
the module's own validation. A `null` among a *required* field's samples: empty is
a real value and belongs in a list, but a required field is the one place it
cannot be, and the field already says so. And the whole list on a *unique* field:
a fixed list is the one thing that cannot fill a unique column, since the second
record drawn from it collides — the type's own sample knows to put the sequence
number on the end, so the honest answer is to let it.

**What a sample means, per type.** A literal value, everywhere, which is why the
mechanism needed no type to cooperate: text and textarea take strings, decimal,
currency and integer take numbers, date takes an ISO string, and a choice takes
one of its own keys — a choice already has its options, but a list of them is how
you say that four in five orders are `draft`. It is meaningless on a `reference`,
whose values are record ids belonging to one tenant's database, and no module
declares it there; a collection is not a field at all, and its rows' fields carry
their own declarations one level down like any other field. Nothing is
half-supported: the sampler does not switch on type, so the only question is
whether a literal in code means the same thing in every tenant, and for a
reference it does not.

**Code-only, in the sense that no form draws it.** The option is stored like every
other and the editor already leaves alone what it does not draw (§5.4), so a
tenant that acquires one keeps it — but there is no control, and adding one is
the deferred work §5.4 already names: a *type* saying which of its options are
the customer's to set, and how they are typed in. Until then a "sample values"
box would have to guess how to parse `8.1, 2.6` for a decimal, a date and a
choice from one textarea. The customer who adds a field in the editor is not left
out by this: they get exactly what they got before, which is a valid value for a
field nobody has described. Plausibility requires somebody to say what the field
means, and the person who knows is whoever declared it.

**Existing installations keep their definitions**, as ever (§6.1): a blueprint is
a seed, installing is idempotent, and nothing retro-fits a changed declaration
onto a customer who already has the module. A tenant installed before this keeps
the uniform tax rates it was given; a tenant installed after gets rates somebody
can read an invoice off. Migrating them would be the engine overruling the rule
that the customer's definitions are the truth, for demo data.

#### And a field the engine owns, the generator says nothing about (XIV-73)

The other half of the same question, found on a freshly generated tenant whose
orders were numbered `Distinctio voluptatem dolorum praesentiu`. The generator
had always skipped a *collection* the definition marks derived — its rows follow
from the others — and had never asked the same about a **field**, so every value
the engine computes was overwritten with a random one before the engine saw the
record.

**A deriver that always recomputes survives that; one that fills only when the
field is empty is defeated by it.** `DerivesTotals` is the first kind, which is
why the totals were never actually wrong. `AssignsNumbers` (§5.10) and
`DerivesDueDate` (§5.16) are the second, because "assigned once and never
restated" reduces to exactly that condition — so the invented value did not lose
an argument with them, it *suppressed* them. That is the pattern worth carrying
away: a derived value nothing can be typed over is safe, and a derived value that
is agreed once has to be protected at the point where values are made up.

**It also spent numbering nobody could give back.** The handful of records whose
invented value happened to come out empty *did* allocate, so three hundred
generated orders left the tenant's counter reading 29, with two hundred and
seventy-one records in front of the next genuine order carrying no number at all.
Clearing the demo records does not undo that. Generating demo data must leave a
counter at exactly the number of records generated, and the suite asserts the
counter rather than the numbers alone.

#### Demo data drives the lifecycle rather than assigning a state

The state is not derived and never will be — it is an ordinary `choice` field a
person moves through a workflow (§5.8) — so skipping it is not the answer to the
same question. But sampling it wrote records that were `cancelled` or `paid`
having never been cancelled or paid: no history, and states the lifecycle would
not have allowed anything to reach directly.

**The decision is that the generator walks the lifecycle.** The sampled state is
read as a *destination*: the record is created in the module's initial state and
then moved along the shortest run of legal transitions, through the same
`RecordLifecycle::apply()` and `RecordWriter::save()` that a person's click goes
through. `Lifecycle::pathTo()` is the graph search that answers "how does
something get from here to there", and it lives on the lifecycle because that is
where the transitions are declared.

The alternative — accept the initial state, leave every demo record a draft — is
cheaper and was rejected on what it fails to produce. A tenant of nothing but
drafts exercises no transition, locks no record, and has **no due dates at all**,
because §5.16 materialises one on the way into `sent` and on no other save. The
feature this ticket found broken would have had no demo data to be broken in.
Driving the lifecycle also turns the generator into the broadest test of the
engine there is: it is the only caller that writes every field of every module,
and now the only one that moves every record through every module's workflow.

**It costs a save per transition**, and that is the honest price: 5,000 contacts
are unchanged at about 2.5 seconds, while 5,000 orders went from 3.5 to 5.2 —
roughly half as long again, for 1.3 extra saves per record on average and a
history entry each. A module with no lifecycle pays nothing.

**How far a record gets is the module's business, not the generator's.** Drawing
uniformly over an order's four states would make a quarter of every demo tenant
cancelled, which is not a business anybody runs — so the distribution is declared
where every other opinion about demo values is declared, as a `samples` list on
the status field, weighted by repetition like all the others. The generator picks
a destination and walks to it, and knows neither what a draft is nor how many
there should be.

### 5.18 Follow-ups, and where §5.2's argument stops (XIV-80)

A follow-up is something somebody decided to do about one record, by one date:
call them back on Friday, chase this invoice next week. A priority, a due date,
an optional assignee, a thread of notes, and a done stamp that can be taken off
again.

**One shared pair of tables, which is the opposite of what history does.** §5.2
splits history per module for two reasons, and only one of them survives the move
here. The integrity reason is real and is given up deliberately (below); the
*size* reason is not: history is written automatically, on every save, by
everybody, and grows without bound, whereas a follow-up is typed by a person who
decided to type it, and a customer producing a thousand a year is a busy
customer. Paying for per-module tables — an installer that creates them, the
63-character identifier guard in `ModuleInstaller::assertTableNameFits()` to
widen, every already-installed module to retro-fit — buys nothing in return. So
`follow_up` and `follow_up_note` are ordinary Doctrine entities in the tenant
database, created by a `migrations/tenant` migration beside `User` and
`PermissionGroup`.

**`record_id` therefore carries no foreign key, and cannot**, because the table it
points into depends on what `module` says. That is precisely the property §5.2
refused to give up, given up here with the reason written down: this table is
small, hand-written, and always read with a module definition already in hand, so
nothing ever has to work out which table a row means from the row alone. Two
consequences belong to code rather than to the database, and both are stated in
the migration and at the entity:

- **Every read joins through to the record and honours `deleted_at IS NULL`.**
  Records are soft-deleted (§5), so a cascade would have nothing to fire on even
  if there were one, and a follow-up on a deleted record would otherwise surface
  on a widget about a customer somebody removed last month. The check is a second
  query rather than a join, and that is forced: the module's table is named at
  runtime and is not a mapped entity, so DQL cannot reach it.
- **A hard purge, when one is ever built, has to sweep `follow_up` itself.**
  Nothing in Postgres will remind whoever writes it.

**The note's foreign key is real and cascades**, which is the same rule producing
the opposite answer one level down: `follow_up_note.follow_up_id` means one table
forever.

**Users are denormalised, and here they did not have to be.** Core stores an owner
id without a constraint because it genuinely does not know what a user is; these
entities live next to `User` in the same database and *could* have joined. The
answer is still no, for two reasons pointing the same way: a task should outlive
the person it was assigned to, and a label captured at write time keeps saying who
they were after a rename — §5.2's argument for `user_label`, reused. Deleting a
user clears the *assignment* and keeps the name, through a listener rather than
`ON DELETE SET NULL`, since there is no constraint to hang that on. Who *made* a
follow-up is not touched: that is a fact about something that happened, like a
history row's `user_id`, while the assignee is a live claim on somebody's
attention and a person who is gone has none.

**Two new verbs, granted per module like everything else** (§8.4).
`follow_up_create` covers opening one and writing a note on it — a note is what a
follow-up is *for*, and somebody who may create the task but not say anything
about it has been given a feature with its mouth taped shut. `follow_up_complete`
covers marking done **and** reopening, because done is a nullable timestamp rather
than a state and the two directions are one edit pointing two ways; anybody who
can close a follow-up they should not have can undo it, which is what makes
closing safe. Reading follows the module's own `view`: a follow-up says nothing
the record does not already say to whoever may open it. Adding these cost one
schema change nobody predicted — `permission_grant.action` was `varchar(16)` and
`follow_up_complete` is eighteen characters, so it is 31 now, as wide as a history
row's `action`.

**A note is editable and deletable by its author and by nobody else, including an
administrator.** The one place this feature departs from §8.4, and the only place
in the application where `ROLE_ADMIN` is not a bypass. A note is a sentence
somebody said; editing it under their name is putting words in their mouth, and
there is no configuration of a permission system that should make that possible.
It follows that a deleted user's notes become nobody's to edit — the correct end
state, and the reason the rule is expressed against the stored author id rather
than against a relation.

**A follow-up may only be assigned to somebody who may view its record.**
Otherwise a task lands on a list whose owner cannot open what it is about, and a
dashboard is left choosing between leaking the record's title and silently hiding
work somebody was given. Checked at assignment, through the same
`PermissionResolver` every other check uses — which is why the write path takes
its actor as a parameter rather than reading the token: resolving somebody *other*
than the current user was already the shape of the code. **Revoking the grant
afterwards is deliberately not retroactive.** There is no cascade and no listener
on grant changes: a screen about people must not silently unassign somebody's
outstanding work with no record of having done it. The residue is handled where it
shows, by listing such a follow-up without a link to its record.

**The rules live in a service, underneath §8.4's three seams.** A route carries
`#[IsGranted]`, a voter decides a record, a WHERE clause decides a list — and all
three are things a form post goes through. An import, a console command and a
future API are not, and this is exactly the kind of feature that grows one of
them, so `FollowUpManager` is the fourth seam and the one that cannot be walked
around. Grants scoped to "own records" are honoured there through the same
`RecordAccess` the list compiles from, so a record kept out of somebody's list
cannot have a follow-up put on it by typing its id.

**Per module, opt-in, on by default, and reversible** — which is the one thing
that makes it unlike a preset (§6.1). Because no table is created per module,
the switch is a boolean on the customer's `ModuleDefinition` rather than DDL, so
it can be turned round for as long as the installation lives; the store asks at
install time as a courtesy, not because it is the last chance. It lives there
rather than in a table of its own because "what this customer has, and how it is
set up" is already one row with one answer, and a second table keyed by module key
would be a second place to say a module exists. Switching it off deletes nothing:
existing follow-ups stop being offered and come back if it is switched on again, a
toggle that threw rows away being one nobody would dare use.

**`due_at` and `done_at` are `timestamptz`**, like `<module>_history.occurred_at`.
A deadline is an instant two people in two countries have to agree about. The
row's own `created_at`/`updated_at` are zoneless like every neighbouring table's,
and `updated_at` means *last activity on the thread* rather than the last edit of
the follow-up's own fields — writing or editing a note bumps it, since a
timestamp standing still while three people argue underneath it answers a question
nobody asked.

**Two indexes and no more**: `(module, record_id)` for the record page and
`(assignee_id, done_at, due_at)` for the dashboard. Over-indexing is the other
half of what made the old history table hurt, and this is the table people write
by hand.

#### Reading them back: three ceilings and no floor (XIV-81)

The dashboard asks one question — what is on my list — through three lenses that
are **upper bounds and nest**: due today, due this week, all. Narrowing only ever
removes rows from the far end, which is what makes three links read as one
control with a range rather than as three different questions.

**Today means up to the end of today, which is deliberately the inverse of
§5.16.** An invoice is overdue *strictly before* today, because telling a
customer they are late on the morning their bill falls due is how a dunning list
loses its credibility. A follow-up is the other kind of deadline entirely: it is a
note somebody wrote to themselves, and what is due at 16:30 is exactly what they
want on their dashboard at 09:00. The two predicates disagree on purpose, and the
one in `FollowUpRepository::openFor()` says so at the line, because the
inconsistency is the sort a later reader tidies away.

**And there is no lower bound at all.** `AND due_at >= …` would look like the
missing half of a range and would mean a follow-up somebody *missed* dropping off
the widget at the moment it started to matter. A missed follow-up quietly
disappearing is the worst behaviour available here, so overdue work stays in every
lens including *today*, sorted to the top, and the only way off the list is
marking it done.

**Which day the week starts on is a locale question, not a constant.** ICU
answers it — Sunday for an American reader, Monday for a Swiss one — through
`IntlCalendar`, asked with the locale `FormattingLocale` composes, since it is the
*region* half that decides. symfony/intl is this codebase's usual door onto CLDR
and has no opinion here: `Countries`, `Currencies` and `Timezones` are lists of
things, and the first day of the week is a rule rather than a list. So ICU is
asked which day it is and the remaining arithmetic — how many days back that is —
stays one subtraction modulo seven. Boundaries are then drawn on
`DateTimeImmutable` in the zone `DisplayTimezone` resolved (§8.4.4), never in UTC
and never in seconds: a week measured in 604800 seconds ends an hour early across
a spring clock change.

**Resolving a follow-up back to its record is the expensive half, and it is
batched per module.** Finding the follow-ups is one indexed read; naming them is
not, because `record_id` means a different table per `module` value and none of
those is a mapped entity. So the work is grouped by module and read in batches —
`RecordRepository::findAny()`, the sibling of `findChildrenOfAny()` — and the cost
is the number of modules somebody has work in rather than the number of
follow-ups they are carrying. §5.16 names that N+1 as the one a dashboard cannot
afford on the first page after signing in. It is **asserted rather than believed**:
a test grows the list tenfold and requires the query count not to move, because
the way this regresses is somebody writing a perfectly readable loop.

**A follow-up whose record the reader may no longer view is shown without its
record.** Its own text, due moment and priority appear; the title is not rendered
and there is no link. That is the residue of revocation not being retroactive, and
it is the same split XIV-42 made between a reference's *name* and a *link* to it,
arrived at from the other direction: there the name is shown to everybody because
whoever sees the referring record can already see what it refers to, and here
nothing about the record has been disclosed yet, so the name goes with the link.
A grant scoped to *own records* over somebody else's record gives the same answer
for the same reason. **A follow-up on a soft-deleted record is excluded entirely**
rather than anonymised — there is nothing to open, and "shown without a link" and
"not shown" are answers to different questions.

**A module whose follow-ups have been switched off drops out too**, which is what
"existing follow-ups stop being offered" above means when something goes looking
for them. Nothing is deleted and turning the switch back brings them back.

**Not built: a lens for unassigned follow-ups.** The widget is *mine*. A view of
work nobody has taken is a different screen with a different question behind it,
closer to a queue than to a dashboard, and it should be built when somebody asks
for the queue.

#### The record page, and a Live Component that owns no writes (XIV-82)

The panel sits **above the record's own fields, at full width, and nowhere else**.
A follow-up is a claim on somebody's attention and a claim below the fold is one
that has been missed — so it is not in the right-hand column, which is where the
things you may want to read live rather than the things you have to. It is
emphatically **not on the list**: twenty-five records each asking what is
outstanding on them is the N+1 §5.16 warned about, and a list is for scanning
records rather than for reading the work outstanding on them.

**The component decides what is on the screen; routes do the writing.** This is
the one place in the application where a Live Component does *not* own its own
save, and it reverses what `RecordForm` established (§8.3), so it is worth the
paragraph. `PermissionCoverageTest` defines the enforcement surface **by the
URL** — every route carrying `{module}` must name a permission, and a permission
no route names is reported as a control that lies. A `#[LiveAction]` is
dispatched through the library's endpoint at `/_components/…`, which carries no
module, so a write living only there would be invisible to the one check that
exists because unprotected things are invisible. `FollowUpController` therefore
holds six ordinary POST routes with `#[IsGranted]` on them — which is also what
XIV-80 promised twice while building the engine — and the record page already
worked this way for its other two mutations, since the lifecycle transitions and
the delete are plain posted forms.

What is left to the component is what a component is for. Three pieces of state —
the archive, the create form, and which note is being rewritten — and each earns
its keep by keeping markup **out of the document** rather than by hiding it with
CSS. That is the whole reason it is not a `<details>`, which is what the linked
records on the same page use: a `<details>` still has to be sent the forty settled
follow-ups it is hiding, and the create form's assignee picker costs a permission
resolution per user in the tenant that most record pages should never pay for.

**The archive is a counter, not a section**, for the same reason. A record with
forty settled follow-ups must not push its own fields off the screen, so what sits
on the page is one small button saying how many there are.

**And what is in it is history, which does not change** (XIV-85). This shipped
wrong: the archive drew the same note thread the open list does, so a settled
follow-up came with an add box, an edit link and a delete link, and every one of
them worked — an edit even bumped the follow-up's `updated_at`, so something
finished last month reported activity today. `done_at` is now a state and not
merely a flag on a list: while it is set, the only thing permitted is reopening,
and adding a note, rewriting one, removing one and reassigning all refuse.

Two details follow from calling it a state rather than a filter. **Marking done
something already done is refused rather than treated as a no-op**, because a
second stamp would overwrite the moment it was actually settled — the one fact the
archive exists to keep. And **reopening is deliberately not subject to the rule it
enforces**, which is what makes this reversible rather than a trapdoor: an item
put back on the list is ordinary again in every respect.

**The check is on the write path and not only in the panel**, the same split this
section already makes for note authorship. A screen that stops drawing the note
box helps whoever is looking at it and does nothing for the page that was open
across somebody else pressing Done — which is the case that produced the bug
report, and the only one a hidden button cannot address.

**Priority renders as a coloured left border, and the mapping is not an
identity**: `info → info`, `warning → warning`, `important → danger`. Two of the
three agree by coincidence, which is the trap — a template printing the stored
word would look correct until `important`, which Bootstrap has no context for, and
the loudest priority would render with no colour at all. The table is written out
in full including the two identities, so that the arrow to `danger` reads as a
decision and a fourth priority fails to compile.

It lives in **one Twig function, `follow_up_tone()`**, which both screens that
draw a priority go through: this panel and XIV-81's dashboard widget. Two copies
were already drifting — the widget shipped first with a `{% set %}` of its own as
an explicit stopgap, and that copy read `info → secondary` — which is exactly the
failure a non-identity mapping invites. It is a Twig function rather than a method
on `FollowUpPriority` because the enum is what the database holds and `text-bg-*`
is the template's vocabulary; the enum's own docblock makes that argument, and
`can()`, `display()`, `record_title()` and `is_overdue()` are the precedent for
handing a template a computed answer.

**A follow-up has no text of its own; its first note is what it is about.** That
is why `create()` takes a note, and why the panel renders the whole thread rather
than a title with a conversation underneath it. Notes read oldest first — the one
place in this application where newest-first would be wrong — and each carries its
author label and its timestamp. Edit and delete are drawn for the author alone;
the hiding is a courtesy over the manager's rule, never the rule.

**A module with follow-ups switched off renders nothing at all** — no panel, no
counter, no empty state. The switch is reversible by design, and a customer who
turned the feature off is entitled to a page with no trace of it; a box saying "no
follow-ups" is the feature refusing to leave. The page asks before it mounts the
component, and the component asks again for the page that was open across the
moment somebody switched it.

**Timestamps render through an ordinary `|date`**, because XIV-83's listener has
already told Twig which zone the reader is on (§8.4.4). The input half is the
mirror image and does need code: `datetime-local` sends a wall-clock reading with
no zone attached, so the controller reads it *in the reader's zone* before storing
it into a `timestamptz`. Getting that wrong is invisible in the country the server
sits in and an hour or nine out everywhere else.

**Overdue styling is deliberately absent.** The due moment is shown and never
coloured late: what "due" means belongs to the dashboard widget (XIV-81), and two
screens deciding it separately is two answers to one question.

**One rule lives in the controller rather than in the manager**, and only one:
that the follow-up named in the path is on the record named in the path. The
`#[IsGranted]` votes on the module in the URL while the manager resolves the
module off the follow-up row, so without the check the two would be talking about
different records and both be satisfied. It answers 404, so that a wrong id and
somebody else's id stay indistinguishable (§8.4).

**`ENFORCED_WITHOUT_A_ROUTE` is gone.** XIV-80 shipped the engine before any
screen called it, so its two verbs were granted, enforced by a service and named
by no route — held in a documented list with the ticket that would empty it
written into each entry. These routes emptied it, and the mechanism went with the
entries: a hatch nothing goes through is one that rots open, and an empty list
makes the test guarding it an assertion that cannot fail. The next engine-first
ticket of that shape should put it back rather than weaken the check.

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

### 6.3 The store, and installing without a shell (XIV-6)

A customer who signs up lands in an empty installation. Until the store, the only
way to put anything in it was `tenant:module:install` — the operator's shell, not
theirs — which made self-service onboarding (XIV-64) half a feature.

**Browsing reads the control plane; installing writes only the tenant's
database.** That split is the load-bearing one and it is not new: what may be
offered is `ModuleCatalog::offeredInStore()`, published *and* present in this
build (§6.2), while "does this customer have module X" is answered from their own
metadata and nowhere else. `Tenant::$enabledModules` is not involved and must not
become involved — a control-plane write on a tenant-facing install path is exactly
what [XIV-60] is separating out.

**One installer, two front doors.** The store calls the same
`ModuleInstaller::install($blueprint, $preset, $locale)` the command does. A
headless deployment keeps its path, and a module installed from a screen is the
same module — which is a property worth having a test compare rather than a claim
worth repeating.

**The preset is permanent and the wizard says so.** §6.1 does not retro-fit, and
the additive upgrade that would make the choice reversible is [XIV-70], which is
deliberately *not* a prerequisite: there is nothing installed today that needs
upgrading, and building the store first is what tells anybody whether the smaller
preset is ever chosen. So for this iteration the screen is simply honest — every
preset's fields listed in full, radios rather than a select so both futures are on
the screen at once, and the sentence *this choice cannot be changed later* above
the choice rather than under it. A friendly dropdown is the worst possible
presentation of an irreversible decision.

**Requirements are refused with guidance, never chained.** Invoice needs Contact
and Order; the installer already refuses and names what is missing (XIV-23). The
store checks first so the install is not *offered*, because finding out on submit
— after choosing a preset nothing can change — is a worse way to learn it. It
does not chain-install, and the reason is the paragraph above: each chained module
carries its own permanent preset choice, so a chain makes two irreversible
decisions on somebody's behalf while they think they are making one.

**Nothing about a module is written here.** Presets, requirements, collections and
labels all come off the blueprint, so a module added to a future build appears in
the store complete. Whether it appears at all is its state (§6.2), which is a row
somebody can change without a deploy.

Not in it, on purpose: payment, since every module is free in this iteration and
the state enum already anticipates more states; and uninstalling, which means
deciding what happens to the records and is a larger question than installing one.

### 6.4 Asking an installation what it is (XIV-76)

§6.1 has a consequence that only shows up when somebody new arrives: **the
repository cannot describe a tenant.** A module's blueprint is the shape a
customer was installed with, their own definitions are the truth from that moment
on, and nothing retro-fits a blueprint change into them. So reading
`ContactModule.php` and assuming it describes a contact is reading the *starting*
shape — and being wrong about it silently, in exactly the way [XIV-70] is about.

That makes "what fields does this customer's `contact` have, of which types, with
which options, which variants, which collections, which of them are derived" a
question with **no answer in the repository and, until this, no command behind it
either**. It is per tenant, it is structured, and a table in a terminal is a poor
shape for it.

#### The introspector, and the two front doors on it

One service, `App\ControlPlane\Introspection\TenantInspector`, answers three
questions as plain arrays: which tenants exist and whether each one's schema is
current; what one tenant's installed modules actually look like; and what the
module catalogue holds. It reads through the application's own services —
`TenantRepository`, `ModuleCatalog`, `MetadataRepository` behind `TenantSwitcher`
— and writes no SQL of its own, because a second way of asking the engine what it
holds is a second thing to keep in step with the engine.

Two callers: `bin/console tenant:inspect`, and a committed MCP extension. **The
command is not an afterthought.** Nothing an agent can ask may be tool-only —
Mate's server is a process that can drop mid-session, and an agent told to prefer
tools it can no longer see is worse off than one that never had them. `--json`
prints the structure the tools return, byte for byte, so a wrong tool result can
be told apart from wrong data.

#### Where a project's own MCP tools live, and why it is a package

Mate discovers extensions from `vendor/composer/installed.json`, by
`extra.ai-mate` in a package's own `composer.json`. It also always loads the
*root* project as a pseudo-extension, whose scan directory defaults to
`mate/src` — and `mate/` is gitignored, which is the whole reason the setup here
delivered nothing to a second developer.

So the extension is an ordinary composer package, `packages/xivi-mate`, reached
through the path repository the modules already use. It is committed, it reaches
a fresh clone, and it earns three things a directory could not: it appears in
`mate debug:extensions` and can be switched off in `mate/extensions.php`, its
`INSTRUCTIONS.md` is aggregated into the server's MCP handshake, and — the
decisive one — **it is a `require-dev` package, so `composer install --no-dev`
leaves it out of the production image entirely.** That is a stronger dev-only
guarantee than the `exclude:` list in `config/services.yaml`, which the
introspector and `tenant:inspect` get as well.

It is a fourth deptrac layer sitting *above* the application rather than beside
the modules, and the direction is the point: the tools may depend on the app, and
nothing in the app may depend on the tools.

**The bridge boots a fresh kernel per call and shuts it down.** Mate's server is
its own process with its own container, so a tool reaches this application by
constructing `App\Kernel` — the project's autoloader is already in scope. Caching
that kernel is the obvious optimisation and is wrong three times over: a held
tenant connection and metadata cache is §7.4's cross-tenant leak in a process that
lives for an afternoon; a held connection **blocks `DROP DATABASE`**, which is
what the lifecycle tools do; and a container compiled before an edit answers
questions about the code after it, which is [XIV-63]'s stale-artifact failure
disguised as a broken tool.

#### Destructive tools are exposed, and the argument for withholding them fails

The instinct is to expose reads only. It does not survive contact:

- **An agent with a shell can already run every one of these commands.**
  Withholding them changes ergonomics, not authority.
- **It pushes agents toward improvising.** Before [XIV-72], rebuilding a test
  tenant here meant hand-written `DELETE`, `DROP DATABASE` and `DROP ROLE`, which
  is strictly more dangerous than a tool that names the database, the role and the
  record count before it acts.
- **The commands already ship their guardrails**, and a tool that *calls the
  command* reuses them rather than reimplementing them: the confirmation defaults
  to no, an unattended run is refused outright without `--force`, and
  `tenant:reset` refuses an unsatisfiable module set before touching anything.

What a terminal did for free was somebody *reading the warning*. Nobody reads it
here, so both lifecycle tools take a census before acting — database, role,
hostnames, installed modules — and return it in the result under `destroyed`, or
under `would_have_destroyed` when the command refused. An agent that has removed
the wrong tenant can say which one, out of the same message that told it the call
worked.

Provisioning, installing a module, migrating and creating users are deliberately
**not** tools. `bin/console list tenant` already prints them with their
descriptions, and wrapping a command that describes itself buys ergonomics while
doubling the surface to keep in step.

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

   - **A reference is a way to get to what it names, and the name is shown either way**
     (XIV-42). Two questions, answered separately and on purpose. *The name* is read
     unscoped: whoever may see the record holding the link can read what it points at, and
     an order whose customer said `#14` would be an order nobody can use. *The link* is
     offered only where the reader may actually open the target, because a record somebody
     may not view answers **404** rather than 403 (§8.4) — so an anchor there would send
     them to a page saying the thing does not exist, which is worse than not offering one.
     A stale reference and one into an uninstalled module are the same: text, never an
     anchor.

     The link is a **second seam** rather than something `display()` returns, because that
     method's output goes into .docx templates, spreadsheet cells and the record titles
     the picker shows — an `<a>` in there is markup printed on a letter.

   - **What a set of records names is read before it is rendered** (XIV-54). Naming a
     record is a second row from a second table, and a collection has no LIMIT — so the
     cost that mattered was a record page's rows rather than a list's. One
     `WHERE id IN (…)` per target module, filled into a memo the name, the link and the
     inherited-value drift check share, under exactly the access rule above. See §5.3 for
     the argument and the numbers.

   - **A picker somebody may type into, and the endpoint behind it** (XIV-36). A
     reference's dropdown was capped at two hundred and said so (XIV-35), which is honest
     and is not a way to find the nine thousandth contact. Past a threshold the control
     becomes a search box that pages through the endpoint instead, so under `auto` a
     truncated picker cannot happen: the ceiling is replaced rather than raised, and the
     notice survives for `never`, where the ceiling survives too. Whether it does this is
     an option on the field and not a field type (§5).

     **The endpoint is the sharp part, and it is scoped exactly as the picker is**
     (XIV-13, §8.4). An unrestricted search is strictly worse than the unrestricted
     picker that ticket closed: a picker leaks the names it happens to render, once, on a
     page somebody was allowed to open, where a search box lets them enumerate a module a
     letter at a time. Same `RecordAccess`, same `View` check on the target module, no
     exception for administrators written into the query. One route, generic over module
     and variant and sorting and paging by the same title fields the dropdown used — a
     module-specific search route would be the code the engine exists not to have — and
     what the widget may *find* and what the form will *accept* go through one reading, or
     a record somebody clicks comes back as an invalid choice.

     The reading is shared with `CandidateLists` (XIV-87) for that reason, and core learns
     the URL through an interface the application answers, the same seam as
     `InstanceCurrency` and `RecordAccessProvider`.

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

**It assumes JavaScript, and it did not use to** (XIV-28). The old rule was that
the forms worked with scripting turned off, and it earned its keep: it kept the
UI honest, and several decisions here are the better for having been made under
it. It was dropped because of what it cost at the other end — a collection form
ending in one blank row of every kind, because switching a row's fields as
somebody picks needs scripting. At four kinds that is a mess, and the number of
kinds only grows.

**What replaces it is Symfony UX Live Components, and the distinction worth
keeping is exact.** Server-rendered stays true: every page and every re-render is
Twig, and nothing in `assets/` builds HTML. What is gone is "works with
JavaScript off". The two were always separate claims and only the second has been
given up.

**It was htmx first, and the swap is worth recording rather than quietly
rewriting** (XIV-28, then XIV-33). htmx did the job for one button and did it
well. Three things decided against it for the next one:

The morphing argument below was a prediction when it was written. **XIV-32 built
the feature it was predicting and it held**: typing a price updates the line
total and the document's totals around a caret that does not move, with a browser
test on the caret specifically, because the caret is the whole claim and nothing
server-side can see it. The decision is no longer on probation.

- **Morphing.** A form that redraws while somebody is typing in it has to update
  the changed nodes, not replace the region — swap the block a quantity field
  sits in and the caret goes with it, mid-number. Live Components morphs by
  default; htmx swaps, and preserving the caret means the idiomorph extension
  plus hand-managed `hx-preserve`.
- **State.** htmx has no model for state that is not in the markup or the URL. A
  component holds props, which is what a wizard step, a "show advanced" toggle or
  a dependent field needs.
- **One vendor.** The UI library being the framework's own is worth something on
  a codebase that reaches for the framework first everywhere else (§5.7).

The cost, accepted with open eyes: **the write path is now a function of the UI
library**, and the tests that used to press what a person presses now call the
component instead — which is why the browser layer below is not optional.

Three spike branches did the comparison rather than an argument: the whole form
as a component, only a collection as one, and the documented shape with the save
included. The middle one works and is not a pattern the library documents; the
last one is what shipped.

**A refused save answers 200, not 422.** A component that re-rendered is a
successful render, so only the body says no. That is a real loss — anything
speaking HTTP could previously tell a rejection from an acceptance — and it is
recorded here rather than discovered by whatever reads these responses next.

**What a submitted record form *means* is not the controller's** (XIV-30), and
that is why the move above was a change of caller rather than a rewrite. Four
things are held outside it: what a form starts with, which submitted rows were
really typed into, whether the submission is valid, and what gets written. None
of them is a fact about HTTP. What a form starts with is core's, since it follows
from the shape; what a submission *means* needs the validator, the writer and
Symfony's form errors together, so it lives in the application. The rule to keep:
whatever renders the form — a controller, a component, an import, whatever comes
next — asks the same service, and none of them gets its own idea of what a valid
record is.

**One browser runs, and only over what only a browser can see** (XIV-31). Every
other test calls the component directly and learns nothing about whether the page
it sits on does anything. Three assertions in a real browser close
that — the library is loaded, a row appears without the page reloading, and what
was already typed survives it. Deliberately three: an end-to-end layer is where
flakiness lives, flaky tests get skipped, and a skipped safety net is worse than
none because everybody believes it is there.

Two things it cannot share with the rest of the suite, and both are the same
fact. The browser is another process making real requests, so **the transaction
rollback everything else depends on is invisible to it** (§9.2's speed work) — its
tenant is committed and reclaimed on the next run. And it needs a hostname that
resolves *both* from the browser's container and from the application's own,
because the web server binds to the name it will later be asked for; the service
name will not do, because that one is deliberately served without a tenant.

**The cost to watch is the components, not the library.** A live action is a
second way into the write path and a second place permissions have to be
answered, and the temptation is one component per module the moment something
does not quite fit. A component stays **generic over module, record and shape**
like everything else here, and mounts on a module key and an id rather than on
anything a particular module knows. One component that renders any record form is
fine; a `OrderForm` beside it would quietly become the module-specific code §1
exists to avoid — a finding about the engine rather than a shortcut worth taking.

### 8.3.1 The dashboard is its widgets (XIV-81)

The landing page shipped as a placeholder — a tile per module, two empty states,
and a docblock promising it would be replaced "once there are modules to show".
The first real thing to show up was a list of due follow-ups, and the cheap way
to add it was an `{% if %}` in the dashboard template with a variable the
controller passed down. That is the shape which makes the *second* widget a
rewrite rather than a file, so the seam was cut while there was one implementation
to cut it around.

**A widget is a service that decides whether it has anything to say, and if so
names a template and hands it data.** Nothing more: no registry to configure, no
per-user arrangement, no layout engine, nothing persisted. Discovery and ordering
are Symfony's tagged iterator — `#[AutoconfigureTag]` on the interface,
`#[AsTaggedItem(priority:)]` on the implementation — which is the reach-for-the-
component rule applied to a problem that would otherwise have grown a
`dashboard.yaml`. Nothing keeps a list of widgets, so nothing can disagree with the
classes that exist.

**A template name and an array, never a rendered string.** A widget that returned
HTML would need the translator, the router and the escaper injected to build it —
the reasons Twig exists, rebuilt once per widget. Headings are translation *keys*
for the same reason a permission action hands out a label key rather than a label.

**The module tiles were converted rather than left in place**, and that is most of
what makes this real. One interface with one implementation and a template that
still knew the answer would have been a special case wearing an abstraction. It is
also the widget that answers "what does a customer with no modules see": it never
returns null, because the two empty states — nothing installed, which an
administrator can act on, and nothing yours, which they cannot — are exactly what
a dashboard with no modules has to say.

**Returning null is "this does not apply to you", not "I am empty".** The
follow-up widget draws itself with a sentence when the lens has nothing in it, and
stays off the page only when no module in the installation takes follow-ups at
all. The condition is deliberately *not* "any module this reader may view": a
reader can hold follow-ups on a module they can no longer open, and hiding the
widget would take that work off the screen entirely — the one outcome the feature
is built to prevent. The price is that somebody with no grants sees an empty box,
and "nothing on your list" is a true sentence.

**A widget that throws takes the page down, and is allowed to.** The tempting
try/catch per panel is refused: a dashboard that silently omits one is a dashboard
nobody can trust to be complete, and the follow-up widget in particular is a list
of work somebody was given.

**A widget's own controls are its own state, not the URL's** (XIV-84). The
follow-up lens shipped as three links carrying `?follow_ups=today`, on the
argument that a GET which changes what a page shows is a GET. That argument is
sound and it answered the wrong question. **Narrowing a summary is not
navigation**: nobody wants a history entry for it, nobody sends a colleague a link
to their own follow-up list, and — the part that only shows up with a second
widget — the address bar is shared, so every widget with a control on it would
have been negotiating for room on one URL. A page of five widgets whose state is
five query parameters is a page whose back button means nothing in particular.

So a widget that has a control owns it, as a Live Component (§8.3), and the panel
it hands the dashboard is the mount. The line this draws is worth stating because
it is not "components are nicer": **the dashboard decides whether a card exists,
the card decides what is in it.** Whether this customer does follow-ups at all is
a fact about the installation, settled before anything renders and unchanged by
anybody looking at it; which of them are due this week changes while they look.
Those are different lifetimes, and the widget interface is the seam between them —
which is why `panel()` still returns a template and an array, and the array is now
empty.

The URL keeps what it was always for: which page you are on.

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

**And so is the endpoint it became** (XIV-36). Once a picker can be typed into, the
same argument applies harder: a search box is a way to enumerate a module by
letters, where a dropdown only leaked the page it drew. The route carries
`view` on the target module and the query carries the same `RecordAccess`
predicate a list compiles — both seams, because neither implies the other, and
there is a test that a reader scoped to their own records cannot find a
colleague's by name. It answers 404 for a module the customer does not have, like
every other module route.

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

**Language and region are two settings** (XIV-50). Which words somebody reads and
which country's conventions they write by are independent questions, and one
picker was answering both: choosing "Deutsch" got German-from-Germany, so a Swiss
reader was shown `1.234.500,00` where their country writes `1’234’500.00` — a
different decimal separator, not only a different grouping one. An
English-speaking colleague at a Swiss company is an ordinary hire, and wants
English words with Swiss figures.

So the language is chosen from the catalogues that exist and the region from the
countries there are, and `FormattingLocale` puts them back together — `de` and
`CH` make `de_CH`. Nothing downstream learns a new concept: `Request::setLocale()`
also sets PHP's own default, which is what every formatter already reads.

**A region costs no translation work.** Symfony falls a locale back to its
language, so `de_CH` finds the `de` catalogue. That is most of why the two are
stored apart and joined at the point of use rather than offered as one long list
of every combination.

The chain is the familiar one, and each step is a different promise: the person,
then the installation (§8.6, whose people are mostly in one country), then
nothing — where nothing leaves the bare language, which is what every
installation had before this existed.

**Dates are shown locally and stored as ISO**, and those are two formats with two
names. A date is kept as an ISO string because it then sorts and compares as text
without a cast (§5); the reader's form is computed from the locale's short
pattern with the year widened, since CLDR mostly writes it as two digits and a
record saying `15.08.26` is one somebody has to think about. Reaching for the
storage constant to localize a display is precisely the mistake `CurrencyFieldType`
made in XIV-47, where one method both formatted and normalized and localizing it
made every save refuse its own totals.


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

### 8.4.3 A second permission axis, for the store (XIV-6)

§8.4 predicted this one by name: *"when something wants a verb this enum has not
got — the store's browse and install (XIV-6) — that is the moment to add a second
axis, with a real second case to design it against rather than a guess."* The
store is that case, and the guess would have been wrong, so it is worth writing
down why there are now two axes rather than one.

**Every ModuleAction is something done to a module's records**, and a grant on
one names the module whose records they are. That sentence is the whole model,
and it is what makes the catalogue free — the enum crossed with the customer's
installed modules, worked out at runtime. Neither of the store's verbs fits it:

- **Browse** is about no module whatsoever. It is about the shop window.
- **Install** is about a module the customer specifically does **not** have,
  which is the sharp end: a per-module grant has nothing to attach to. "May
  install invoice" would be grantable only by somebody who could already see that
  invoice exists on a tenant where it does not, and would need granting again for
  every module ever shipped. The authority is not a list about modules; it is one
  sentence about the business — *may decide what this installation consists of*.

Adding them as ModuleAction cases would also have made §8.4's areas incoherent.
The areas' premise (XIV-12) is that the *verbs* stay ModuleAction's and only the
**subject** changes — a profile is viewed and edited like anything else. Here the
subject is fixed and the **verbs** are what changed, which is the other axis of
the same table.

**What is actually second is the vocabulary, not the machinery.** `StoreAction`
is a second enum of verbs; `PermissionVerb` is what it and `ModuleAction` have in
common, and it is deliberately tiny — a stored value, whether the verb can be
scoped, and how to label it. Everything else stays exactly as it was: one grant
table, one resolver, one resolved `PermissionSet`, additive grants, a maximum
rather than a precedence table.

**And it costs no migration**, which is the same argument §8.4 made about the
catalogue, one level up. `permission_grant` was already "a subject, a verb, a
scope" and had opinions about none of them: `module_key` was never a join, so it
already held `@profile`, and now holds `@store` on the same rule that `@` cannot
collide with a module key. The `action` column is 16 characters of string, and
`browse` and `install` fit in it.

The one thing that did change is the column's *mapping*: `enumType:` names exactly
one enum class, so a column holding a verb from either vocabulary cannot use it.
The typing moved one layer out — the column is a string and `PermissionGrant`
hands back a `PermissionVerb`. That works only while the two vocabularies share no
word, so `PermissionCoverageTest` fails the build if they ever collide; a
collision would not throw, it would silently resolve to whichever enum was tried
first, for grants somebody had already been given.

**Two voters, one per axis.** `ModulePermissionVoter`'s whole subject is a
module's records; teaching it a second vocabulary would have made it the class
that knows about both axes, which is a job `PermissionVerbs` already does in one
place. `StorePermissionVoter` is the same shape one axis over, because the model
underneath genuinely is the same.

**A verb from the wrong axis is not stored.** The permission screens generate
their cells from what the customer has, so nothing legitimate posts
`('contact', 'install')` — but a hand-edited request can, and the row would sit in
the table reading as an authority and conferring nothing. `PermissionVerbs`
answers which verbs a subject accepts, derived from the subject rather than
listed, and the manager drops the rest. Same policy as an unknown module key:
ignored rather than explained.

**Nobody has these grants on upgrade, and that is deliberate.**
`tenant:permissions:grant-all` does not hand them out. Its contract is every
action on every *installed module*, and the store's install verb decides what the
installation consists of — permanently, since there is no uninstall — which a
command whose job is undoing a lock-out has no business granting in passing.
Administrators reach the store through the `ROLE_ADMIN` bypass; everybody else is
given it on the permission screens, by somebody who meant to.

### 8.4.4 A timezone to read moments in (XIV-83)

**Storage needed no change at all, and that is the finding worth keeping.**
Postgres `timestamptz` normalises to UTC on write and holds no per-row zone, the
engine has always written moments through `Types::DATETIMETZ_IMMUTABLE` —
`<module>_history.occurred_at` is the oldest example — and the process runs with
`date.timezone = UTC`. So "store UTC, display local" was never a migration
waiting to happen; the storage half has been right since the first table and the
*display* half simply did not exist. Nothing converted anywhere. The one rule
that keeps it cheap is the one to hold going forward: **no new column is a
zoneless `timestamp`.**

That gap was invisible for as long as the only moments on screen were history
timestamps, because an hour's error in a label is cosmetic. It stops being
invisible the moment anything groups by day: the same hour crossing a calendar
boundary **moves** an entry rather than mislabelling it, and §5.2's timeline was
drawing "today, this week, this month" on UTC midnights. Somebody in Zurich
saving a record at 00:30 found it filed under yesterday on a page they had made
half an hour ago. The fault was already shipped and simply had nobody looking at
it.

**Timezone is the third setting of the shape §8.4.2 established**, and the region
is emphatically not it: `CH` is a country, `Europe/Zurich` is a zone, and a
country code says nothing about clocks on its own. `DisplayTimezone` walks the
familiar chain with one extra link, each a different promise — the person, then
the installation (§8.6), then **whatever the effective region implies**, then UTC.

**The third link is what makes this free for most customers.** They have already
chosen a country, and for most of the countries this serves that answer is
unambiguous: Switzerland is `Europe/Zurich` and there is nothing left to ask. A
Swiss company will never open this setting, which is the whole reason it derives
rather than demanding an answer on day one.

**Derive only where the country has exactly one zone, and never take the first of
several.** The head-of-list rule is the trap here, and it is a quiet one:
CLDR orders by identifier, so Spain's list opens `Africa/Ceuta` and America's
opens `America/Adak` — a Madrid office silently filed in North Africa and a New
York one in the Aleutians. Where the country is ambiguous nothing is derived and
the setting becomes one somebody answers, because **a wrong zone is worse than an
unanswered question**: nothing on screen reveals it, since a timestamp in the
wrong zone still looks exactly like a timestamp. Which is also why both pickers
name the zone that is currently in force beside their empty option — the
cheapest available way to make an unnoticed default noticeable.

Germany's two zones are the same offset — Büsingen is a German exclave inside
Switzerland keeping Swiss time — and it is still asked. Collapsing zones that
*happen* to agree today would mean keeping a list of "close enough" pairs that is
true only until one of them changes its rules, which is a maintenance liability
bought with one saved click. The rule stays arithmetic.

**One departure, and it is a different question wearing the same clothes.** India
lists `Asia/Calcutta` beside `Asia/Kolkata`: two names for one zone, because the
tz database keeps the old name as a link after a city is renamed. Counting
identifiers would have made India ambiguous and offered an Indian customer a
choice between Calcutta and Kolkata, which is not a choice. Recognising a link is
not the judgement rejected above — Berlin and Büsingen are two zones that agree,
Calcutta and Kolkata were never two zones — but telling them apart needs the tz
database rather than CLDR, so symfony/intl still says which zones a country has
and PHP's own `DateTimeZone::listIdentifiers()` is asked the narrow question of
which identifiers are canonical.

**Rendering is one setting on Twig rather than a filter on every template.**
Twig's `date` filter already converts into a configured zone before formatting —
that is what `twig.date.timezone` sets — so a request-scoped listener turning the
same knob per reader covers every moment on every page with no new Twig
extension and no `|date(…, timezone)` threaded through a dozen templates.
`date_default_timezone_set()` was the alternative and is rejected: it would also
change what gets *written*, and since those are absolute instants that would still
store correctly, the damage would be quiet rather than loud. The application runs
in UTC and keeps running in UTC.

The grouping is the one thing the Twig setting cannot reach, because it happens in
PHP before anything renders. `HistorySection::of()` takes a `\DateTimeZone` and
applies it to `now`; `HistoryPeriod` then draws midnight where `now` is, and the
entries themselves need no conversion at all, since comparing two moments compares
instants rather than wall clocks. Core takes a zone rather than asking who is
reading — the engine still does not know what a user is (§5.2).

**A console command has no user and may have no tenant, and neither is an
error.** `TenantContext::tryGetTenant()` returning null is the ordinary condition
in `bin/console` and on the login page, so the chain simply runs out of things to
ask and lands on UTC — the handling `FormattingLocale::instanceRegion()` already
demonstrated.

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

#### The customer's own mark (XIV-49)

What they are called was half the answer; what that looks like is the other half.
Distinct from the *instance* logo (XIV-48), which is Xivi's own and is supplied by
the deployment as a file: this one is the customer's, uploaded by them, and the
two only resemble each other in ending up inside the same `<img>`.

**In the tenant's database, in a `bytea` column, exactly as a document template
is** (§5.7). Nothing new is decided here — templates already settled where a
customer's small files live, and a logo is a smaller one of them. There is
precisely one, it is unmistakably theirs, and the per-customer backup, restore and
export-on-churn §4 hands us keep working with nothing added. The general
file-storage design attachments will need is still not being started.

**PNG and JPEG, and SVG is refused.** This is the decision worth the most words,
because SVG is what everybody wants for a logo and is the one candidate that is
not an image: it is an XML document, with a `<script>` element, event handlers on
every node and external references. Served in an `<img>` a browser will not run
it — but nothing keeps it in an `<img>`, and the route below is deliberately
readable without signing in, from the customer's own origin, which is the origin
their session cookie belongs to. Accepting it safely means sanitizing it, and
**the sanitizer is what settles it**: the one credible SVG sanitizer in PHP is
`enshrined/svg-sanitize`, GPL-2.0-or-later. This project is MIT and turned PHPWord
down over LGPL-3.0; a copyleft dependency is not a thing to take on for a nicer
logo. `symfony/html-sanitizer` is MIT and is not an answer — it parses HTML, and
an SVG through it comes out as either nothing or something that no longer draws.
Writing our own would be maintaining a security component over a format with
namespaces, entities and `xlink:href`, which is the thing "reach for the
framework's own first" exists to prevent. A customer with only an SVG exports a
PNG, which is one step in their design tool and not a step anybody here has to be
right about. WebP and AVIF are left out for a much smaller reason — they are safe,
they are simply not what anybody hands over — and could be added any time.

Half a mebibyte, and no larger than four thousand pixels in either direction. The
size ceiling is not only about a logo being small: the bytes sit on
`tenant_profile`, which is read on nearly every page, so it is also the extra row
every request carries once a customer has uploaded one. The pixel ceiling is not
about our own memory at all — nothing here decodes the image — but about not
handing a decompression bomb to the browser drawing the sign-in page. Both are
decided by reading the header, never by the file name or the `Content-Type` the
upload claimed, which is the same call §5.7's `.docx` check makes.

**Nothing is re-encoded.** What comes back out is byte for byte what went in,
against the obvious alternative of normalising everything through GD: re-encoding
is how a crisp wordmark acquires artefacts, and a customer whose logo came back
looking worse has no way of knowing we did it. The price is that the accepted list
has to be safe to serve untouched, which is what the paragraph above is about.

**The sign-in page carries it, and that reverses what XIV-49 first said.** The
original objection was disclosure — showing Acme's mark at `acme.xivi.app` tells a
visitor whose installation they have found. That objection was overtaken by
XIV-79, which made the login card's `<h1>` the hostname: the page says it in words
already, above the picture. What is left is the thing that matters, which is that
an installation should read as the customer's product from the first screen rather
than as ours with their name in the corner. It works because tenancy is resolved
before authentication — `TenantRequestListener` runs on the `Host` header at
priority 100 — so a tenant is in scope with no session at all. A system host
resolves no tenant and falls back to Xivi's own mark, which is right, because that
page is Xivi's.

**Serving it is the one narrowing of §8.4 in the application, and it is stated
rather than incidental.** The route is tenancy-scoped and not permission-gated,
because it cannot be both on a page where nobody has signed in; a logo is a public
mark by definition, printed on the customer's letterhead and website, and treating
it as a secret would be protecting something they publish. What is *not* given up
is tenancy: the action can only ever reach the profile of the host it was asked on.
And nothing else on that row comes out of the same door — the response is the image
and its type, which matters because the SMTP host, user and encrypted password
(§8.7) live on the very same row. There is a test that compares the body for
equality with the bytes rather than merely searching it, because an image plus
anything is not an image.

**Changing it is the profile's `edit` grant and not one of its own.** It is the
same act as changing the company name, on the same screen, in the same submission;
a permission of its own would be a second thing to grant to everybody who already
has the first, which is how a permission catalogue becomes the thing nobody
maintains.

**The cache is the fingerprint, and the fingerprint is in the URL.** The mark is on
every page including the sign-in one, comes out of the database and changes almost
never, so it wants a long lifetime — and a long lifetime that outlives a
replacement means a customer uploads a new logo, is shown the old one, and
reasonably concludes the upload failed. Putting a SHA-256 of the bytes in the path
gets both: a different logo is a different address, so the old address is never
asked for again and the bytes behind the new one may be declared `immutable` for a
year. A path segment rather than a query string, because caches are entitled to
ignore a query string when deciding what a URL means. The remaining case is a page
that was itself cached before the change and still asks for the old address; that
is answered with the current bytes and `no-store`, because caching them under an
address that has already meant something else is exactly the promise `immutable`
must not break. Symfony's session listener would otherwise stamp
`private, must-revalidate` over all of this, correctly, so the opt-out is explicit
on that one response.

**`alt` differs between the two places it is drawn, and the rule is what generates
the difference.** A mark that repeats adjacent text is decorative; a mark that is
the only statement of identity is not. In the top bar the company name is printed
beside it, so it is `alt=""` and a screen reader is not made to say "Acme AG, Acme
AG". On the sign-in page nothing else names the company — the heading below the
card is the *hostname*, which is an address rather than a name — so there it is
named. Xivi's own mark stays decorative in both places, unchanged from XIV-48.

**One upload, not two**, as XIV-49 asked. A wide wordmark suits a bar and a square
one suits a letterhead, and the honest position is that this will be found out
rather than predicted; when it is, that is a second field and not a redesign. The
favicon was considered and left as Xivi's: a wordmark makes a poor sixteen-pixel
square, and the tab is the one place the reader is choosing between applications
rather than reading inside one.

**The document half is XIV-89**, split out deliberately — see §5.7.

### 8.7 Who a tenant's mail comes from (XIV-37)

An email is sent *by a customer*, to their customer, and it has to look like it.
That is one question with three possible answers, and the difference between them
is entirely about **who owns deliverability** — who publishes the SPF record, who
holds the DKIM key, and whose reputation is spent when a recipient marks a message
as spam.

- **From this instance's domain, with the customer's name on it.** Works on day
  one and needs nothing from them. It is also honest in a way the third option is
  not: the mail really did come from us, and says so.
- **Through the customer's own SMTP server.** Genuinely from them, because their
  provider is the one entitled to claim their address. SPF, DKIM and reputation
  are theirs, which is the correct place for all three.
- **As the customer's domain, from our infrastructure.** Needs DNS records they
  have to add, and is the option that fails *silently into spam folders* when they
  do not. Rejected: the failure mode is invisible to everybody who could fix it.

**The second, with the first as the fallback.** A customer who has named an SMTP
server sends through it, under their own address. A customer who has not sends
through this instance's transport — and then their address is the **`Reply-To`,
never the `From`**, because our domain is not entitled to claim it and SPF exists
precisely to say so. Their *name* is still on the message, so a recipient sees who
it is from; a reply still reaches them. The feature works before a customer has
configured anything and becomes correct once they have, and the upgrade is one
form field rather than a migration.

**The name on the mail is the name in the bar.** There is no separate "sender
name" setting: it is `InstanceName` (§8.6) — the company name where they have set
one, the registry's label until then. A company with two names for itself is a
problem nobody has, and a second field would only be a way for the two to disagree.

**The instance's own address** is `MAILER_SENDER`, and it may be left empty. The
fallback is then `no-reply@` at the tenant's own primary domain, which is not a
guess: that hostname *is* this installation as far as that customer is concerned
(§4), so it is the literal truth of "sent from our infrastructure".

**The SMTP credential is stored the way tenant database passwords are stored** —
encrypted with `TenantSecretCipher`, the stored value naming the key it was
written with, so several keys are valid at once and rotation is a resumable job
(§9.2). Reused rather than reinvented: a second encryption mechanism is a second
thing to rotate and a second thing to forget to rotate. Which is exactly what
happened here in miniature, and is worth recording: this secret lives in the
**customer's own database** rather than the control plane, because it is their
setting edited on their settings page (§8.6) — so `tenant:rotate-secrets` now walks
every tenant database as well as the registry. A rotation that had not would have
reported "everything is on the active key", the operator would have dropped the old
one on the strength of it, and every customer's mail password would have become
unreadable — quietly, until the next invoice somebody tried to send. **The tenant's
database is rotated first**, because the control-plane row is the key to it: moving
that first and dying leaves the door on a key the next attempt may no longer hold.

**Sending is synchronous, and this is a decision rather than a stage.** Messenger
with an async transport wants a consumer process, and this runtime is FrankenPHP in
classic mode with no worker on purpose (§9.2), so nothing runs between requests. A
queue with nothing draining it is worse than a slow request: the mail simply never
goes. So a slow SMTP server is a slow request, accepted, and this is revisited when
there is a reason to run a process — one that is about more than mail. Nobody
should have to re-derive that from the runtime again.

**A failed send is never swallowed.** A document that fails to generate wastes
somebody's minute; an email is outbound and irreversible, and a send that failed
silently is a customer sitting there believing their invoice went out. Every
failure inside `TenantMailer` becomes `MailSendFailed` and is thrown on, so the
person who pressed the button is told and XIV-39 can write the attempt to the
timeline as a failure — "nothing happened" and "it went out" must not look the same.

**Dev and test cannot send real mail, and that is a transport decision rather than
a configured DSN.** §9.2 already recorded why the catcher is not a guarantee: it
sees what is pointed at it, and a DSN naming a real server is believed. With
per-tenant credentials that gap stopped being theoretical — the suite provisions
real tenants, so one fixture storing a real SMTP password would have been one send
from mailing an actual person. So `App\Mail\NonProductionMailGuard` is registered
ahead of every transport factory symfony/mailer ships, and outside production
nothing that could deliver is ever **built**: not from `MAILER_DSN`, and not from a
tenant's credentials, because those go through the same factory rather than
constructing a transport by hand. Its only concession is a short list of hosts that
accept mail and deliver none — the compose catcher in dev, and *nothing at all* in
test, where §9.2 had already refused to read from the catcher because eight
paratest workers against one inbox is a shared mutable thing. `sendmail` and
`native` are refused with everything else: neither names a host, so no allowlist
could have saved them, and both hand the message to whatever MTA the machine has.

### 8.8 An invitation instead of a password read off a screen (XIV-1)

§8.5 recorded the printed password as a placeholder and said what would replace
it: *"That printed password is the one credential in the system a human has to
read. It exists because there is no mailer yet; when there is one, this becomes
an invite link."* XIV-37 built the mailer, so this is that sentence being kept.

Adding a colleague now asks **how they get in the first time**, and the two
answers are genuinely different rather than one with a flag on it. The invitation
path **generates no password at all** — which is the ticket's own requirement and
the load-bearing part of it. A generated password created for somebody who is
about to choose their own is a credential sitting on the account that nobody will
ever rotate, because nobody knows it is there. So the hash stays empty, which is a
state nothing can authenticate against from either direction: Symfony refuses an
empty presented password before the hasher is reached, and `password_verify()`
against an empty hash is false whatever is presented.

**The link is Symfony's, not ours, and that was the decision worth making
slowly.** `security-http` ships exactly the object this ticket describes: a signed
URL carrying a user identifier and an expiry, verified by HMAC over
`kernel.secret` together with a chosen set of the user's own properties. Writing
an `invitation` table with a hashed token, an expiry column and a controller
comparing digests would have been re-implementing `SignatureHasher`, including the
parts that are quiet to get wrong — comparing in constant time, checking the
expiry before touching the database, and running the user checker on the way in.
It is also strictly worse in one respect that matters here: **a token table stores
something replayable and a signature stores nothing at all.** A dump of a tenant
database carries no invitation anybody can use.

What is left over after taking the framework's version is small, and it is the
honest departure to declare:

- **An invitee has no password, so a login link is not sufficient by itself.** It
  gets them through the door; `must_change_password` and `MustChangePasswordListener`
  then hold them at `/account` until they have chosen one. Both existed already,
  for generated passwords, and neither needed changing — the feature composes out
  of parts that were here, which is most of the argument for this shape. The one
  thing that did need adding is that the account page cannot ask an invited person
  for their *current* password, because there is none; what stands in for that
  proof is the signed link they arrived on, and the manager refuses that path
  outright for an account that already has a password.
- **A stateless link cannot be revoked, and an invitation has to be revoked
  twice** — when it is used, and when a second one supersedes it. That is what
  `app_user.invitation_seed` is for. It is one of the signature properties, so
  rewriting it invalidates every link already in a mailbox, and rewriting it is
  one `UPDATE`. It is not the token: it is one input to an HMAC keyed with the
  application secret, so what is written down is not a credential.

**Symfony's own answer to single-use was considered and rejected.** `max_uses` is
enforced with a *cache pool*, and a cache is evictable — an eviction would quietly
restore a consumed invitation. A security property that un-enforces itself under
memory pressure is not one. The seed does the same job in the tenant's own
database, transactionally with the acceptance, where it cannot evaporate.

**Inviting somebody twice retires the first link and restarts the 24 hours.**
There is never more than one live invitation per person. The alternative — letting
both run — would mean "I sent them a new one" was not a way to fix an invitation
that leaked, which is the situation the feature most needs to have an answer for.
Reissuing has to exist at all because 24 hours is short: somebody who reads their
mail on Monday cannot be told to have read it on Sunday.

**The seed is spent after the user checker, not before.** Acceptance rotates it
from a listener on `LoginSuccessEvent`, by which point `ActiveUserChecker` has
already had its say — so a deactivated person's click is refused *and does not
consume their link*. Reactivating them inside the window makes the invitation they
were already sent work, instead of having silently burnt it on a refusal they
never saw. Deactivation is covered from both ends: the link is refused at the
door, and an invitation is refused at the point of sending, because a link that
would be turned away is a promise the sign-in page then breaks.

**An invitation is not offered for an account that already has a password.** It
signs its holder in without one, so offering it there would make "invite" a
quieter version of "reset password" that the account owner never sees happen.
Resetting is the tool for that and always was. The converse escape hatch is
deliberately left open: an account awaiting an invitation can still be given a
generated password, which is the way out of an installation whose mail is not
working yet.

**A refused link lands on the sign-in page and says so**, rather than answering a
blank 403 to somebody who has no account here to sign in to. Symfony's own
sentence names the cause and one line of ours says what to do about it. A
deactivated account gets `ActiveUserChecker`'s message instead and deliberately
*not* the offer of a fresh invitation — that would send them back to somebody who
cannot help until the account is reactivated.

**The mail goes out through `TenantMailer` with no exception carved for it**, so a
customer with their own SMTP server sends it from their own address and a customer
without one sends it through this instance (§8.7). The argument for the other
answer is real and was weighed: an invitation is a message *about an account on
this installation* rather than a customer's correspondence with their customer, so
the instance identity is arguably the truthful one. It loses on three counts. The
recipient is a colleague at the customer's own company, which makes it their
internal mail and not ours; §8.7's whole point is that **one** place decides who a
message is from, and a second rule is a second thing to disagree with the first;
and the case that would have needed the exception is already covered without one —
a freshly provisioned tenant has configured no SMTP, so the instance fallback
applies of its own accord. Which is XIV-64's first user, where §8.7's "works on day
one" and this feature meet.

**The message is a system message and its content lives in code** — the ordinary
translation catalogue, in the frame every other email from this application uses
(§5.13). Not an XIV-38 email template, and each of that mechanism's three defining
properties is a reason why: those are per-module and this belongs to none, they are
customer-facing and this goes to a colleague, and they are tenant-editable — where
a tenant who edited the link out of this one would lock somebody out of an account
they have no other way to reach. It also has to work for a tenant that has
installed nothing and written nothing, which is exactly XIV-64's first user again.
It is sent in the language of whoever pressed the button: the invitee has no
account they have ever opened, and so no language on file.

**This is a dependency of XIV-64, not a nicety.** Self-service signup provisions a
tenant with nobody watching a terminal, so there is no screen to print a first
password to. One consequence is recorded here because it is not obvious from
either ticket: the autowired `LoginLinkHandlerInterface` is *firewall-aware* and
works the firewall out from the current request, so it throws outright when there
is not one. The `main` firewall's handler is therefore injected by name, and an
invitation can be sent from a console command. What still has to be answered when
that ticket arrives is the router's request context — a URL generated off a cron
has no hostname to be absolute against, and a tenant's hostname is the one thing
that link cannot get wrong.

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

**A checkout is the unit of isolation for the test stack** (XIV-51). Everything
here assumed one working copy: one compose project, one set of ports, and tenant
databases namespaced per parallel worker (XIV-9). That last one is the sharp
part — the worker token is paratest's, numbered from one, so two *runs* are
handed the same eight numbers and fight over one set of databases, which is
XIV-9's original failure arriving a layer up and looking like nothing in
particular.

So the compose project, the published ports, the bind mount and the tenant prefix
are all derived from the directory. A git worktree is then a first-class
checkout: its own stack, its own databases, no files copied anywhere. The main
checkout keeps every name and port it had, so a single-checkout run is unchanged.

What actually keeps two checkouts apart today is that each has its own stack,
including its own database server — so the tenant names never meet in the first
place. The prefix carries the checkout regardless, which is belt and braces now
and the only thing that would work if anyone later pointed both at one server to
save the tmpfs. It also has to be handed to the container explicitly: `docker
compose exec` carries none of the host's environment, so a variable exported in
`bin/ci` alone is a mechanism that looks switched on and is not.

**And a derivation only one script knows is only true where that script runs**
(XIV-55). The block above lived in `bin/ci`, which made the suite correct and
everything else silently not: a worktree's `docker compose exec php …` got the
main checkout's ports and an empty tenant prefix, and said nothing about it. The
derivation moved to `bin/lib/stack-env.sh`, sourced by `bin/ci` and by a
`bin/compose` wrapper that forwards to `docker compose`, so the two readers
cannot disagree.

Two things are worth keeping from working out what actually broke. The first is
that Compose *does* default its project name from the directory, so the failure
is not the one it looks like — the ports collide loudly and `TEST_RUN` goes empty
quietly, which is the dangerous half. The second is that Compose's sanitising and
ours are not the same rule: it deletes characters outside `[a-z0-9_-]` where we
replace them, so a worktree named `xiv-37.transport` is `xiv-37-transport` to the
wrapper and `xiv-37transport` to a bare `docker compose` — a third project
belonging to nobody. Directories named after branches are exactly where that bites.

The wrapper does not *refuse* when it cannot tell which stack is meant, which the
ticket asked about, because there is no such case: sourcing the fragment always
decides. The guessing only happens in a `docker compose` typed by hand, which no
wrapper can intercept — so what it does instead is name the stack it is acting on
whenever that is not the main checkout. Prevention was not available; visibility
was.

**The image was the name that isolation forgot** (XIV-71). XIV-51 made the
project, the ports, the bind mount and the tenant prefix per-checkout and stopped
one line short of the artifact all of them run: `compose.override.yaml` named the
dev image `${IMAGES_PREFIX:-}xivi-php-dev`, one fixed name for every checkout on
the machine. So a branch that touched the `Dockerfile`, or anything it copies in,
rebuilt the image every *other* checkout was already running — and the entrypoint
is copied in. XIV-63 changed it to call `bin/reconcile` and rebuilt; the worktree
next door, on a branch predating that script, was handed an entrypoint calling a
file its tree did not contain and crash-looped under `set -e`.

**The crash was the lucky outcome**, and that is the whole reason this is worth a
paragraph rather than a line. A loud failure gets worked around in ten minutes.
The same mechanism can hand a worktree an image that differs *quietly* — an
extension one branch added, a changed `php.ini`, a different base tag — and
everything comes up healthy while `bin/ci` is green or red for reasons that have
nothing to do with the branch. That is XIV-63's complaint one layer down: an
artifact that does not match the tree being checked. It also breaks the property
that made parallel checkouts worth having, since the two are meant to be unable
to disturb each other and neither could tell that one had.

The hook already existed — `IMAGES_PREFIX`, in the compose file, defaulting to
empty — so the fix is `bin/lib/stack-env.sh` deriving it like everything else,
empty for the main checkout so `xivi-php-dev` keeps its name. It needs a *third*
sanitising, for the same reason there are already two: a Docker reference is
neither a compose project name nor a database identifier, and forbids the mixed
separators (`foo-_bar`) that a project name allows.

**The disk cost is the objection, and it is 29 kB.** One image per worktree
sounds expensive and is not, because a worktree that changes nothing about the
build produces the same layers: measured on a second checkout, all 27 layers
shared, 28.66 kB unique against a 2 GB image, and the total across all images
unmoved. A worktree pays for what it actually changed and only from the changed
layer down. The image *ID* does differ even when the layers do not — buildx
exports a fresh manifest with a provenance attestation each time — so identical
content is not identical identity, which matters only if you go looking for it.

Two smaller decisions came with it. `xivi-prod-check`, which `bin/ci` builds at
the end of every run under a fixed name, **got the same treatment** on a much
weaker argument: that tag is written and never read, so two concurrent runs
racing on it only meant the loser's tag pointed at the winner's build. It is one
expansion, and a name that is merely *nearly* meaningless is a poor thing to
leave for whoever first runs something out of it.

And cleanup is **deliberately not automated**. The trigger is `git worktree
remove`, which nothing here wraps; `bin/compose down` is not that trigger and
teaching it to drop the image would cost everybody who uses `down` to free a port
a full rebuild on the next `up`. Worse, the name is derived from the directory,
so once the worktree is gone nothing can work out what to delete. So the image
name is printed by `bin/compose` with no arguments — read it before you remove
the directory — and the README says to `docker image rm` it. An accepted cost
that is written down beats an automatic one that surprises people.

**A warm stack believes things about a tree it has not read** (XIV-63). `docker
compose up -d --wait` on a stack that is already running is a no-op, so `bin/ci`
inherited whatever the last install and the last kernel boot left behind:
vendor/ from before a merge changed `composer.lock`, and a compiled dev container
from before a merge changed `security.yaml`. Nothing compared either to the tree.
`composer validate` reads composer.json against composer.lock and never looks at
disk; PHPStan reads the container's XML dump directly and boots nothing.

Both instances turned up in one afternoon, both on merge and never on the branch,
and both arrived disguised as static analysis about code — eight `class.notFound`
for packages that were in the lock and not on disk, and a `serviceNotFound` for
an authenticator that was in the configuration and not in the container. A
worktree's stack is cold on its first run (XIV-51), so the branch really was
green and the integration step looked broken, which is backwards from where
anybody looks first.

`bin/ci` now reconciles its inputs instead of assuming them, in `bin/reconcile`,
after the stack starts and before the first check that consumes them. Fixing
rather than refusing is the part worth recording: a check names the command it
could have run, and everybody aliases past it — and the objection that a CI run
should not write to the working tree was already lost to an entrypoint that
installs and a suite that writes databases. The same script runs from the
container entrypoint, replacing a test for `vendor/` being *empty* that could
only ever be right once.

Three things came out of building it that the ticket had not asked about. The
quiet direction is **removal**: a package dropped from the lock and left in
vendor/ keeps resolving, so the branch is green everywhere except a clean build,
and `composer install` is the answer precisely because it removes. The compiled
container needs **no clearing, only a boot** — debug mode records every file that
went into it and rebuilds when one is newer, which is the four-second
`cache:clear` in a fifth of the time, with the one edge that its freshness test
has one-second granularity and calls "same second" fresh. And **PHPStan's result
cache is a fourth artifact**: it tracks the container XML, measured, but not the
packages installed, so a package it has just called unknown stays unknown after
being installed. It gets told, from a hash of the installed set written next to
the run that changed it.

A warm, already-correct run costs about a second, which `bin/ci` prints every
time so the claim the design rests on stays checkable.

**The test tmpfs was enlarged three times, and the thing growing was the
ceiling** (XIV-78). The test database server keeps everything in RAM (XIV-10) on
a capped tmpfs, and that cap went 1g, 2g, 3g as `bin/ci` kept running out of
disk. The third bump is where the number stopped being the problem: a clean full
run measures **440 MB across 48 databases, 17% of 3g**. The size was never
close.

What accumulated was runs, not a run. A class's tenant database is not dropped
when the class ends — DAMA holds the transaction, and therefore a connection,
until the process does, and Postgres will not drop a database somebody is
connected to — so a run leaves its databases behind and the next one reclaimed
whatever it asked for again *by slug*. But the name carries paratest's worker
number, and a class does not land on the same worker twice. A class that ran on
worker 3 yesterday and worker 5 today leaves two, and over enough runs each class
spreads across all eight. The set saturates at **classes × workers**, which is
eight times one run and grows eight times faster than the suite does: 48 × 8 ×
9 MB is 3.6 GB against a 3.0 GB volume. Measured, four consecutive runs from
empty: 48 → 92 → 134 → 170 databases, 17% → 29% → 43% → 54%. It fills on the
seventh.

So the fix is not a fourth number. **`bin/ci` drops every database and role
matching this checkout's test prefix before the suite starts**, which leaves the
steady state at one run's worth and keeps 3g at roughly seven times what a run
needs. On the *start* rather than at the end, because that also covers the case
where leftovers are worst — a run that crashed or was killed, which has no
teardown to reach.

Three things about it are worth keeping.

**It is free, which was the one thing worth checking rather than assuming.** The
obvious objection is that this gives up the "next run reclaims by slug" warm
start `SharesATenant` was written for. There was no warm start: that trait
*deprovisions and re-provisions* whatever it finds, so a database waiting under
the right slug was never reused, only deleted a moment later. Measured — a run
straight after a full reclaim took 23.9s, against 23.6s and 24.5s for runs that
found their databases waiting, and the reclaim itself is 0.3–0.7s.

**Terminating sessions first is part of it, not a refinement.** The same
subsystem breaks a run the other way: a Panther web server left running by an
earlier browser suite holds a connection to a tenant database it knows nothing
about, and every class that reclaims that tenant fails with `SQLSTATE[55006] …
is being accessed by other users`. `TenantProvisioner::deprovision()` clears the
switcher so its *own* connection cannot block a drop, which does nothing about
somebody else's. A start-of-run reclaim that issued a plain `DROP` and hoped
would have traded one confusing failure for another — so it terminates the
backends over `pg_stat_activity` and then drops `WITH (FORCE)`: belt for the
connection already there, braces for the client that reconnects in between. The
roles go with the databases, because a role left behind without one is the same
shape of failure and `CREATE ROLE` has no `IF NOT EXISTS`.

**And a full volume now says it is a full volume.** That is the reason the number
was chased three times: it does not present as a disk. Postgres aborts its
checkpointer, the server restarts, and a hundred tests fail with "no connection
to the server". `bin/ci` takes one `df` after reclaiming and refuses above 80%,
which turns twenty minutes of reading test output into one line. The reclaim also
asserts it is pointed at the throwaway server before dropping anything — `SHOW
fsync` must be `off`, which `compose.override.yaml` sets precisely because this
data is disposable, and which is a better gate than trusting a service name to
still mean what it means today.

One thing this deliberately does not touch. The suite's *control-plane*
databases (`app_test<worker>`) live on the `database` server rather than the test
one, because `DATABASE_URL` is set in the php container's environment and a real
environment variable outranks `.env.test`. They are small, on disk, and no part
of the tmpfs problem — but it means a registry row can outlive the tenant
database the reclaim dropped, which is why `deprovision()` being `DROP … IF
EXISTS` on both objects is load-bearing rather than defensive. Moving them onto
the test server is a separate question and has not been answered here.

**A mail catcher is visibility, and only that** (XIV-41). Development sends to
Mailpit, a container that accepts everything and delivers nothing, because the
mail this application is about to grow — Markdown rendered to HTML, wrapped in a
base template — cannot be reviewed from a log line. Its web UI is published on
the loopback and its port is derived per checkout like every other, so a second
worktree does not collide; SMTP is not published at all, since only the php
container speaks it.

The distinction to hold on to is that this is **not** a guarantee that nothing
escapes. A catcher sees what is pointed at it; a DSN naming a real server is
believed, and the catcher never learns of it. Making it structurally impossible
for a non-production environment to reach a real address is a transport question
and is XIV-37's. Reading this service as a safety net is the mistake it invites.

`symfony/mailer` became a runtime dependency here rather than in the ticket that
first sends something, and not by choice: `framework.mailer` is refused outright
when the component is absent, so there is no way to name a transport with
configuration alone. The ticket that first sends something is XIV-39 (§5.14),
which arrived two tickets later.

The test suite deliberately does not read from the catcher, and the reason is the
one this section keeps arriving at: eight paratest workers against one inbox is
one mutable shared thing again, so a worker would find or delete another's mail.
Tests assert through Symfony's message logger, which collects in the sending
process before the transport, with the DSN set to `null://null` — per process,
isolated by construction, and it works *because* nothing is delivered.


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
  the class. The next run reclaims it — `bin/ci` drops the lot before it starts,
  and the trait still reclaims by slug for a run that goes another way (XIV-78).

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

**An avatar is generated here, never fetched** (XIV-77). The top bar's right-hand
end became one menu under the signed-in person's name, and a menu under a name
wants a face beside it. There were three ways to get one and only one of them is
a design decision:

- **Initials in a circle**, on a hue derived from the email address. No storage,
  no upload, no dependency, and it works for every user the moment it ships. This
  is what was built.
- **Gravatar**, which is nearly free and was refused. The README promises a
  customer's browser makes no CDN calls; this would send every signed-in user's
  email hash to a third party on every page load, telling them who is at work
  today. That is the same argument `assets/app.js` makes about scripts, and it is
  a privacy decision rather than a styling one. If it is ever wanted it has to be
  opt-in and argued here first.
- **An uploaded picture**, which is the honest answer eventually and is blocked
  on something bigger. Avatars are per user and kept forever, which is exactly
  the attachments shape this section calls *half answered* — document templates
  (§5.7) sit in the tenant's own database because they are small, few and
  unmistakably one customer's, and that answer was bounded on purpose. A top bar
  is not where the general one gets decided by accident, so the seam is left
  where it belongs (`App\Twig\Avatar` would grow a source; the template would
  draw an `<img>` where it now draws initials) and the question is left open.

The other half of that ticket is worth one line, because the file it changed
argues the opposite and the argument was sound: three always-visible links really
did beat a menu, and then there were five. The premise moved rather than the
principle, and the replacement comment in `_topbar.html.twig` says so, so nobody
re-litigates it without counting first. A shut menu still names the page you are
on — that item's icon and label ride on the button — because a bar that stops
saying where you are has given up its other job.

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

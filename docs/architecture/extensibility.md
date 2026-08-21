## 6. Extensibility

Three composable layers, all "one codebase, no forks":

- **Fields** extend an entity's *shape*: metadata rows, no code.
- **Events** extend an entity's *behavior*: module bundles with EventDispatcher
  subscribers.
- **View extensions** extend an entity's *UI*: tagged services contributing
  panels.

### 6.1 Where a shape comes from

Three sources, and the discipline is knowing which one a given need belongs to:

- **Preset.** A named subset of one module's blueprint fields (`basic` and
  `extended` for Contact), in code, shipped and versioned with the module,
  identical for every customer who picks it. `tenant:module:install --preset`.
  **Fields only, never collections.** A field a preset left out can be added
  back in the editor (§5.4) or taken later (§7.2.1), so the smaller preset is
  reversible; only the installer creates tables, so omitting a collection
  would be a decision the customer could never undo. Every collection a module
  declares is installed every time. Nothing records which preset was used,
  because storing it would invite something to re-apply it.
- **Template.** How a customer is set up *across* modules: install these
  modules with these presets, then add these fields. "Dentist practice" is a
  template, never a preset. It is data, in the control plane, because a new
  vertical must not need a deploy; otherwise v1's compiled-in module list
  returns in a new hat. Its second half, the "then add these fields" clause,
  is a **shape pack**, and §6.6 defines what one may contain.
- **Metadata rows.** Anything one specific customer needs. A preset named
  after a customer has stopped being a preset.

**A blueprint is a seed, not a type.** From the moment a module is installed,
the customer's own definitions are the truth, and nothing retro-fits a
blueprint change into them. §7.2.1 is the one sanctioned exception, and it is
an explicit, additive, per-item *offer* the customer takes or dismisses;
nothing can take away what was installed. Customers are designed to diverge,
so "we do not retro-fit" is the model, not a limitation.

Templates reference presets rather than duplicating them. They need one thing
from the engine before the control plane needs a table: the editor must be
able to set the options a vertical is made of (§6.6).

### 6.2 How far along a module is (XIV-7)

A module has a **state**, `development` or `published`, a closed set that
grows by adding a case.

- **Global, never per tenant.** Whether a module is finished is a fact about
  the module, and a per-tenant flag is §4's configuration drift in a new
  costume.
- **In the control plane, not the blueprint.** Publishing is a decision about
  whether customers may have it, the same kind as a tenant's status, and
  pulling a module back out of the store must not need a deploy. **A module
  with no row is in development**, which makes the default free rather than a
  sync step.
- The build (`ModuleRegistry`) and the states meet in exactly one place,
  `ModuleCatalog`. It also handles a state row naming a module this deploy no
  longer ships: listed and flagged, never offered.
- **It gates the store and nothing else.** `tenant:module:install` names the
  state and proceeds, because a module is developed by installing it
  somewhere. Leaving the store never uninstalls anything; a state says what
  may be obtained from now on, never what is removed.
- The row also carries the price (§6.5). State and price are separate axes,
  and "published and not for sale" is a real and useful combination; either
  saying no withholds the module from the store.

### 6.3 The store, and installing without a shell (XIV-6)

- **Browsing reads the control plane; installing writes only the tenant's
  database.** "Does this customer have module X" is answered from their own
  metadata and nowhere else. `Tenant::$enabledModules` is not involved and
  must not become involved.
- **One installer, two front doors.** The store calls the same
  `ModuleInstaller::install($blueprint, $preset, $locale)` the command does,
  and a test compares the two rather than the claim being repeated.
- **The preset choice is presented in full**, every preset's fields listed,
  radios rather than a select, with the true sentence above it: nothing
  rewrites what is installed here, and a smaller preset can be grown later
  (§7.2.1).
- **Requirements are refused with guidance, never chain-installed.** Each
  chained module carries its own preset choice, so a chain makes two
  irreversible decisions on somebody's behalf while they think they are making
  one. A module whose requirements this tenant lacks **stays visible**, with
  the missing piece named and the way there stated. Hiding was rejected
  (2026-08-20) because it makes the store a different catalogue per tenant,
  and the customer who would want the module never learns it exists.
  Withholding by *deployment* decision, §6.2's state and §6.5's `unpriced` and
  `not_for_sale`, is a different axis and stays.
- **Nothing about a module is written in the store.** Presets, requirements,
  collections and labels all come off the blueprint.
- **A module nobody has priced is withheld**, because free is a decision
  somebody made, not the absence of one (§6.5). A priced module cannot be
  installed from here at all; the button records a purchase request an
  operator answers (§8.15), and "install it and mark it unpaid" was rejected,
  not deferred. Uninstalling is not in the store, because it means deciding
  what happens to the records.

### 6.4 Asking an installation what it is (XIV-76)

§6.1 means the repository cannot describe a tenant. A blueprint is the
starting shape, and customers diverge by design. "What does this customer's
`contact` actually hold" is answered by
`Xivi\ControlPlane\Introspection\TenantInspector`, which reads through the
application's own services (`TenantRepository`, `ModuleCatalog`,
`MetadataRepository` behind `TenantSwitcher`) and writes no SQL of its own.
Two front doors, and the rules that shaped them:

- `bin/console tenant:inspect` and the committed MCP extension
  (`packages/xivi-mate`). **Nothing an agent can ask may be tool-only.** The
  MCP server is a process that can drop mid-session, so the command is the
  durable interface, and `--json` prints the tools' structure byte for byte.
- `packages/xivi-mate` is a `require-dev` composer package, so
  `composer install --no-dev` leaves it out of the production image entirely,
  a stronger dev-only guarantee than a service exclusion. It is a deptrac
  layer *above* the application; the tools may depend on the app, never the
  reverse.
- **The bridge boots a fresh kernel per call.** A cached kernel is §7.4's
  cross-tenant leak in a long-lived process, holds a connection that blocks
  `DROP DATABASE`, and answers from a container compiled before the last edit.
- **Destructive tools are exposed.** An agent with a shell already has every
  command, and withholding tools pushes it toward improvised SQL, which is
  strictly worse than a tool that reuses the command's own guardrails. The
  lifecycle tools take a census before acting and return it under `destroyed`
  or `would_have_destroyed`. Provisioning, installing, migrating and user
  creation are deliberately *not* tools; `bin/console list tenant` already
  describes them, and wrapping a self-describing command doubles the surface.

### 6.5 A module can have a price, and the operator sets it (XIV-101)

**What a module costs is not the code's business.** The deploying company
decides what its customers pay, so the price lives on the control-plane
`module` row beside `state`, never on `ModuleBlueprint`. There is nothing in
the constructor to put one in, and that absence is the enforcement.

- **Four pricing cases**: `unpriced` (nobody decided; withheld from the
  store), `free`, `priced`, and `not_for_sale` (this deployment does not sell
  a finished module; not listed at all, because a row nobody can act on is an
  advertisement for a refusal). A null price is not free. Zero is refused as a
  price, and rounded before it is judged, so the boundary between `free` and
  `priced` cannot be reached by typing a number.
- **One-off, not recurring.** Recurring would be a `period` column with no
  billing system behind it. Periods, renewal, proration, grace, dunning and
  failed-renewal handling are each their own ticket, and a screen offering
  "per month" is a promise somebody will be charged monthly. When recurring is
  wanted, the period hangs off a purchase record, where a term of a
  transaction belongs. `Tenant::$plan` is a label nothing reads and must not
  become a billing input.
- **The currency is a deployment parameter**, `PRICE_CURRENCY`, and
  deliberately not `InstanceCurrency`. That interface's implementation reads
  the *tenant's own* profile currency, and a control-plane request resolves no
  tenant, so it answers null there forever. Same ISO code, same
  `Money\Amount`; `PriceCurrency` deliberately does not implement the
  interface, so nothing can autowire it into a field type. Unset renders bare
  numbers. Changing the selling currency invalidates every figure at once,
  which is a re-pricing exercise with a person in it, so deploy-level friction
  is correct.
- **The money is §5.9's money**: decimal strings, `NUMERIC(12, 2)`, arithmetic
  through `Money\Amount` on brick/math, never a float anywhere on the path.
- **Changing a price touches nothing anybody already has.** A customer's
  modules live in their own database and are read from their own metadata;
  nothing on that path consults the price column. A purchase records the price
  as a **copy** on the customer's own row (§8.15), exactly as an invoice
  stores its own due date and totals. `ModulePriceTest` proves it with a
  photograph of everything observable, rather than asserting the setter avoids
  the installer.
- **Reading and setting sit on opposite sides of the XIV-96 split.** Reading
  is customer-facing (`App\Registry`, compiled into the public image, drawn by
  the store); the screen that sets a price is in the control-plane package and
  absent from that image. The guarantee underneath is §4.4's grant, not
  routing: the public instance's role has `SELECT` on the registry tables and
  nothing else, so PostgreSQL refuses an `UPDATE module …` from the
  internet-facing process whatever a controller does. `ModuleCatalog::priceAt()`
  joins `moveTo()` on §4.4's short list of writers living in `src/` that only
  the package calls.
- **One seam.** `ModuleCatalog::price()` mirrors `state()`, and
  `CatalogEntry::isOfferedInStore()` holds the whole rule: in the build,
  published, and for sale. There is no second service reading the `module`
  table, and there must not be. `module:price` exists beside the screen,
  because a page is not a reason to take a command away.

### 6.6 A vertical as data, and whether it can be uploaded (XIV-141)

§3.2 closed modules. A vertical is mostly *shape*, shape is data, and data
needs no deploy. So can a file turn a customer's Contact into a law firm's?
**Not yet, and the obstacle is not the file format.**

- The thing wanted is §6.1's template's second half, named a **shape pack**:
  the list of edits applied to installed modules' definitions after the
  modules are in. A preset can only ever mean "Contact with fewer fields"; a
  vertical is "Contact with *different* fields".
- **The boundary: a pack may do nothing a customer could not do by hand in the
  metadata editor.** That is what makes it data rather than code. There is
  nothing to execute, no new privilege (whoever applies it is already an
  administrator who could make the same edits one at a time), it is reviewable
  by reading, and it runs as a sequence of `MetadataEditor` calls that
  inherits every refusal the editor already makes.
- **Today that boundary encloses almost nothing.** The editor cannot set a
  choice field's `choices` or a reference's `module` and `variant`, and those
  two are what a vertical is made of. The prerequisite is §5.4's unfinished
  sentence: a type says which of its options are the customer's to set. Until
  it lands, a pack is a file that can rename things.
- **What the format has nowhere to put**, on purpose: collections, variants,
  lifecycles, derivers, document templates, number series, validation beyond
  `required` and `unique`, and translated catalogues. A pack carries literal
  label maps or installs in one language.
- **Fields only, never collections.** That is §6.1's line, reached
  independently. There a collection cannot be added back; here it cannot be
  *taken* back. A table name in an uploaded file is a permanent claim on an
  identifier in the customer's database: `createRecordTable()` refuses to
  adopt tables it did not create, so a pack creating `contact_matter` makes a
  future Matters module of ours permanently uninstallable for that tenant.
- **Applied once, never a standing authority.** An audit line records the
  apply; from that instant the definitions are the truth and the file has no
  further say. A stored, re-appliable pack is a second schema authority, the
  retro-fit this brief refuses arriving as a file instead of a deploy. Drift
  at apply time is a report naming the skipped changes, not a break, because
  nothing is left holding anything.
- **Anybody may author a pack; nobody but us may publish one.** A tenant
  administrator applying one into their own installation is the editor's own
  authority with a full preview. Listing one in the store is vouching for it,
  which reopens what §3.2 closed. It is called a **field pack** and never
  named after a trade, because a file with four fields and a rename sold as
  *Law Firm* delivers less than it promises; the trade name stays with
  XIV-139's packages, where a module is behind it.
- **The decision: not built, and not refused.** The order: first the editor
  learns `choices` and a reference's `module` and `variant`; then templates
  land with the shape pack as their second half, ours, in the control plane;
  uploading is a separate, later decision onto the same format, and nothing
  forecloses it.

---

### 6.7 The engine owns the clock (XIV-155)

§1's second half names recurrence as its first test: recurring invoices
(XIV-156) and memberships (XIV-157) both want *"do this, for this record, for
this period, inside this tenant"*, so neither module may own the clock. It
landed before either consumer, deliberately, because the point of the rule is
to collect the abstraction before the first copy of it is written.

**A module declares, the engine executes.** `RecurringWork` is a tagged
service with five methods: a key, the module it belongs to, a `CatchUp`, a
`due()` that answers what is outstanding, and a `run()` that does one
occurrence. **No module ships a command, a cron entry or a loop.** `due()` is
a question and must be safe to ask twice, about periods that already happened,
in a tenant whose customer has renamed the field it reads; the engine filters
and remembers, which is what stops each module keeping its own bookkeeping.

**An occurrence is (work, subject, period)**, and those three are also the
unique index. `subject` is the record it recurs for, a string the engine never
interprets; `period` is an absolute instant, not a label. There is **no
foreign key** to what `subject` names, on §5.10's and §5.19's argument:
deleting a definition must not make last month's invoice due again.

**Running twice is answered by a record, not by asking modules to be
idempotent**, which is an instruction to every author for ever, checked by
nobody.
`due_work` carries one row per occurrence, claimed by one
`INSERT … ON CONFLICT DO NOTHING RETURNING id`, XIV-103's lesson applied
again. **The claim is written in the same transaction as the work**, which is
the whole design: work that throws takes its own record with it, so *an
attempt is not a run* and no lease timeout or reaper is needed. The stated
cost: **at-most-once holds for effects in the tenant's database and nowhere
else.** A `run()` that sends mail may send it twice; neither consumer has one,
and when one arrives it wants §5.15's two-phase shape rather than a change
here.

**Catch-up is declared per work kind**, because the answers differ and both
are right: a monthly invoice wants the months it missed
(`EveryMissedPeriod`), a sweep wants to happen once (`OnlyTheLatest`). A
passed-over occurrence is **written down**, per subject, not skipped: a module
answers from its own records, so a silent skip would be re-offered on every run
for ever.

**Time.** The schedule is evaluated in **the tenant's own zone**: §8.4.4's
chain with nobody reading, so the installation's setting (§8.6), then what its
region implies, then UTC. Never a person's, because a boundary must not depend
on who typed the command, and never the server's, because a Zurich business
billing on the 1st at UTC midnight bills on the 31st. The instant is taken **once for
the whole walk**, so a run over fifty databases cannot put the first customer
on one side of a month boundary and the last on the other. A customer who
changes their zone moves future boundaries; occurrences already recorded keep
theirs and never re-run.

**What it does not promise, said rather than implied.** Nothing runs between
requests (§9.2), so work happens at the **first run of the clock after it fell
due** and never at the instant it did; the cadence is the lateness. Hourly is
the §4.5 entry, chosen because customers are not in one timezone and any fixed
nightly hour is a different distance from local midnight for each of them. A
module needing something to happen *at* a moment cannot have it here.

**The walk is `tenant:work:run`**, in the control plane, §4.2's exit codes
(0 / 1 / 3) and `tenant:migrate`'s rule that one customer's failure costs
nobody else theirs. Two departures, both deliberate: **an empty registry exits
0**, because this runs hourly and unattended and a failure mail every hour on
an installation awaiting its first signup is §4.5's own failure arriving
through the channel built to prevent it; and **a tenant not serving requests
is skipped**, because `provisioning` may have no schema and `suspended` is
somebody's decision that this customer's instance does nothing (§4.6).

**Within one subject, a failed period stops that subject for the run** and
resumes in order next time, so a failure cannot hand August July's place in a
numbered series (§5.10). Other subjects, other jobs and other tenants are
unaffected. Honest limit: a failure that closes the tenant's entity manager
leaves that tenant's remaining occurrences failing too; they are reported and
still outstanding, and the walk resets at the next customer.

**Deliberately not built**: a catalogue screen of what is due, retry counts,
dead-lettering, per-occurrence payloads, and any way to schedule a one-off.
None of the two consumers asks for one, and §1 is the reason.

---

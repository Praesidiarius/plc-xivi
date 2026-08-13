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

Closed, not open: adding a field type is a deliberate code change, not customer config.

**Relations stay relational.** Real link tables, real foreign keys. Relations are the one
thing both EAV and JSON are bad at, and a CRM is relational at its core. Relations are
*described* in metadata but *stored* relationally.

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
object that outlives the context it was loaded in is the same bug.

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
3. **Query layer.** Filtering, sorting, and pagination across mixed real-column and JSONB
   storage, without degenerating into concatenated SQL. This is the highest-risk
   component in the system.
4. **Doctrine multi-tenancy hazards.** Entity manager, metadata cache, result cache, and
   any warmed pools must not leak across tenants within a worker process. Critical under
   FrankenPHP/RoadRunner-style long-running workers.
   *Partly settled — see §9.2. The runtime is deliberately not a worker, which removes
   this class of bug for web requests. It remains open for shared caches and for any
   process that serves several tenants in sequence: console commands, and message
   consumers when they arrive.*
5. **Authorization model.** Roles, permissions, per-module access, and record-level
   rules. Entangled with §7.3: "only the records I own" is a WHERE clause, not a
   check performed after loading. See §8.4.

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

The tenant resolution layer of §4, and nothing of the engine itself yet.

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

### 9.3 Next

A UI for Contact, built from the same definitions — which is what proves the
metadata layer drives the form as well as the storage. Then §7.3, the query layer,
with a real table and real rows to be correct about.

Deliberately still missing, and each one needs a decision rather than an
implementation: column promotion, relations between modules, the metadata editor,
and §7.2 — what happens to stored data when a field changes type or is removed.
Installing a module today refuses to touch an existing installation for exactly
that reason.

Two things to keep honest while that lands: the metadata layer will want a
per-tenant cache, which is §7.4 in a new costume; and file storage has not been
designed at all, which is a cross-tenant surface as soon as uploads exist.

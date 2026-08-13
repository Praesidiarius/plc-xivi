# onePlace v2 — Architecture Brief

Metadata-driven CRM/ERP engine in Symfony, plus a working CRM built on top of it.
Ground-up rebuild of a prior system (v1). Solo project.

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

---

## 6. Extensibility

Three composable layers, all "one codebase, no forks":

- **Fields** extend an entity's *shape* → metadata rows, no code.
- **Events** extend an entity's *behavior* → module bundles with EventDispatcher
  subscribers.
- **View extensions** extend an entity's *UI* → tagged services contributing panels.

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
   *Partly settled — see §8.2. The runtime is deliberately not a worker, which removes
   this class of bug for web requests. It remains open for shared caches and for any
   process that serves several tenants in sequence: console commands, and message
   consumers when they arrive.*

---

## 8. Status

### 8.1 Built

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

### 8.2 Decided since this brief was written

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

### 8.3 Next

The engine: §7.3 query layer, then the metadata layer of §5 proven by one thin
`Contact` module. The tenant databases have no tables yet.

Two things to keep honest while that lands: the metadata layer will want a
per-tenant cache, which is §7.4 in a new costume; and file storage has not been
designed at all, which is a cross-tenant surface as soon as uploads exist.

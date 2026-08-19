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
- the administration surface may depend on the application; the application may never
  depend on it (§3.1)

Boundaries are a CI check, not a distribution decision.

**And the check has to be checked** (XIV-60). Every layer in `deptrac.yaml` was collected by
`type: directory` with a pattern anchored as `^src/…`, and deptrac matches those against the
file's *absolute* path — `/app/src/…` in the container CI runs in. So no file was ever in
any layer, and `composer deptrac` had been reporting no violations for the same reason an
empty configuration would. It was found by planting a deliberately illegal import and
watching nothing happen. The collectors are `classLike` on the namespace now, which is the
same statement in the language the layers were always about, and turning the check on for
the first time found zero real violations across seven layers — the good outcome, and not
the one to have assumed. **Plant a violation whenever a layer is added here.** A boundary
check is the one kind of test that passes just as convincingly when it is not running.

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

### 3.1 The administration surface is a package; the registry is not (XIV-60)

`packages/control-plane` is the fourth kind of thing in this repository, after core, the
modules and the application, and it is the first one that sits **above** the application
rather than beneath it. What follows is what it is allowed to depend on, what may depend on
it, and — because the answer is not the one the ticket started with — why the boundary
falls where it does.

**The obvious split does not exist.** "Move the control plane out of the instance that
serves customers" is unsatisfiable as stated, and finding that out was most of the work.
`TenantResolver` reads the control-plane database to turn a `Host` header into a customer;
`TenantConnectionParameters` reads the same row to get the DSN and decrypt the password it
connects with. Every tenant request begins in the control-plane database. An instance
without it cannot answer the first question it is asked.

**So the thing that is separable is not the control plane; it is the administration
surface.** Two halves, in one database, with opposite audiences:

- **The registry** — `App\Registry`, in `src/`. The `tenant` row and its domains, the
  encrypted credential, the status, and the module catalogue the store reads. It is what a
  *customer's* request needs in order to be served at all, it is read on every request, and
  it stays in the application. It writes nothing: provisioning is the only thing that
  creates a tenant, and provisioning is not in here.
- **The surface** — `Xivi\ControlPlane`, in `packages/control-plane`. Provisioning,
  deprovisioning, tenant migrations, secret rotation, the module catalogue's *write* side,
  operator identity and its firewall (§8.9), the tenant list (§8.10), usage collection
  (§8.11), and the introspector §6.4 exposes. Nothing here is on the path of a customer's
  request; everything here is on the path of somebody entitled to see every customer at
  once.

**The rule, and it runs the opposite way from the modules'.**

- The control plane **may** depend on the application: on `App\Registry`, on `App\Tenancy`
  and on `App\Tenant`. Provisioning a customer means creating a database, connecting to it
  *as* that customer, running the tenant migrations and installing modules into it, so it
  necessarily drives tenancy and the tenant application's own user and permission services.
  It may also depend on core, because counting a tenant's records means reading their
  metadata.
- The application **may not** depend on the control plane. Not "should not": there is no
  `use Xivi\ControlPlane\…` under `src/`, deptrac fails the build on one, and the intent is
  that an instance serving customers can be built with no administration surface compiled
  into it at all.
- A module may not depend on it either, and it may not depend on a module. It reaches
  modules the way everything else does — by key, through the registry and the engine.

That is the same shape `packages/xivi-mate` has (§6.4): a package above the application
rather than beside the modules. The ruleset in `deptrac.yaml` is what says which of the two
a given package is, and it is the only place that can say it — Composer cannot express
"depends on the application", because the application is a project rather than a package.

**What resisted.** Three commands and two screens had to move out of `src/` with the rest,
because they were administration wearing the application's directory: `tenant:reset` and
its progress object, and the two Twig templates the operator pages render. Three things
deliberately did not move, and each is a coupling the deployment half will have to answer
rather than a tidiness problem:

- `app.control_plane_host` is a parameter in the application's `config/services.yaml`,
  read by a class in the package. It is a *deployment's* fact, so a bundle default would be
  answering a question about where it is installed. [XIV-96] left it there deliberately and
  §4.4 says why: it also feeds `app.system_hosts`, which is the application's and which
  [XIV-93] composes into the trusted-host pattern.
- `security.yaml` names `Xivi\ControlPlane\Security\ControlPlaneHost` as the control-plane
  firewall's request matcher and `Xivi\ControlPlane\Entity\Operator` as its provider. The
  application's security configuration therefore does not compile without the package
  present. Removing the package from a build is consequently not yet a matter of dropping a
  Composer requirement. **[XIV-96] is where that was solved** (§4.4): the firewalls moved
  into the package and the application splices them in behind a `class_exists()`, and three
  further obstacles of the same kind — the entity mapping, the signup route loader's type,
  and three dev-and-test service registrations — turned up behind this one.
- The operator screens extend the tenant application's `base.html.twig` and read their
  strings from its `messages` domain. That is the allowed direction, and it does mean the
  surface is not renderable on its own.

**One suite, one stack, and — since [XIV-96] — two image builds.** `bin/ci` runs a single
PHPUnit suite over both halves and the dev stack is still one `bin/compose up`; `tests/Functional/ControlPlane/` was not moved into the
package and is not going to be, because the invariant those tests assert — that the
control-plane firewall matches on host and is ordered above `main` (§8.9) — is a property of
the *assembled application*, and a test living inside the package could not see it.

**The migrations did not move and must not.** `migrations/control/` stays where it is under
`DoctrineMigrations\ControlPlane`, which is the namespace recorded in the
`doctrine_migration_versions` table; the tables did not move either, so the split is
invisible to the schema. What changed is that the `control` entity manager now has two
mappings over one database — `App\Registry\Entity` and `Xivi\ControlPlane\Entity` — which
is the split stated in the one place Doctrine reads.

### 3.2 Only we publish modules (XIV-141)

**Decided: closed.** Every module in a Xivi installation is one we wrote, ship in
the image and maintain. There is no third-party module, no plugin registry, no
side-loaded bundle, and none of that is a gap waiting for a ticket.

It is written down here rather than left to the trajectory, because the trajectory
was going to answer it anyway and answering it by default is the failure mode the
question was raised against. The argument is not that open is unworkable; it is
that open is five separate products — distribution, review, versioning, revenue
and support — and each of them is a permanent obligation rather than a feature
with a finish line. A half-maintained third-party Resident module is worse for a
care home than no Resident module, for exactly the reason a half-maintained
first-party one would be: they bought it.

Four things follow, and each is a consequence rather than a restatement.

**Core is not a public API, and it does not have a deprecation policy.** This is
the sentence the decision exists to make sayable. `Xivi\Core` may be renamed,
narrowed, split or deleted in any release, `ModuleBlueprint`'s constructor may
grow a required argument, and `FieldTypeRegistry` may change what it hands back —
because every caller is in this repository and every caller is fixed in the same
commit. `docs/architecture.md` is a brief and not a contract, and it is now
explicitly not a contract rather than ambiguously one. The obligation this
*replaces* is real and is not weaker: a change to core has to leave `bin/ci`
green across every module in the tree, which is a stricter check than a
deprecation notice and it runs on every commit rather than on somebody else's
upgrade schedule.

**Which verticals we own is a list, not a reflex.** Breadth is bounded by one
person's time, so it has to be spent deliberately: a vertical earns a module when
it needs *behaviour* — a lifecycle, a deriver, a document, a number series — and
not merely different words on a contact. That test is the whole of §6.6, and it
is what stops "somebody asked for it" from being a roadmap. Most requests are
refused, and refusing them is the decision working rather than failing.

**The store is designed for a curated set and may assume it.** Thirty tiles is a
problem [XIV-140] solves with grouping; three thousand would be a problem
grouping does not touch, and a store that has to defend against an unbounded
catalogue is a different piece of software. `ModuleCatalog` may keep joining the
build against the control plane in memory, `StoreOffer` may keep carrying a
blueprint, and neither has to grow a search index it does not need. [XIV-139]'s
packages are the curation made visible: a customer sees *Hotel* rather than
thirty tiles, and *Hotel* is a set we chose.

**And the boundaries stay, on their own merits.** `packages/*` are separate
Composer packages with their own `composer.json`, and deptrac enforces that a
module may reach core and never another module (§3). That is most of a plugin
architecture, and it was built before this question was asked — so a future reader
finding it is owed the answer that **it was not an abandoned plugin plan**. The
boundary earns its keep with nobody outside at all: it is what made [XIV-96]'s
two-image split possible, it is why the invoice module could be almost nothing but
a blueprint, and it is the only thing standing between five modules and a mesh in
which every one of them imports two others. A boundary is worth having because of
what it prevents *inside* the repository; that it would also have supported an
outside is a coincidence, and one that is now on the record as a coincidence.
**The `symfony/symfony` subtree-split escape hatch above is unchanged** — it is
about distributing our own packages, not about accepting somebody else's.

What this decision deliberately does **not** close is whether a *vertical* can
arrive without a deploy. That is a question about data rather than code, the brief
has always answered it with §6.1's templates, and §6.6 is where [XIV-141] examined
whether such a thing could be uploaded as a file.

---

## The rest of the brief

§4 to §8 are in `docs/architecture/`, one file per area. They were split out on
2026-08-19 ([XIV-149]): this file had reached 13,539 lines, and the standing
instruction to read "the brief" before starting work had turned it into ~210,000
tokens of reading before anybody wrote a line of code.

**Section numbers did not change.** §5.9 is §5.9 wherever it lives, and every
reference to one elsewhere in this repository still names the section it always
did — only the file it sits in is new.

| | |
| --- | --- |
| §4 | [Deployment topology](architecture/deployment.md) — single instance, database per tenant; the control plane, the registry grants, the deploy |
| §5 | [Data model](architecture/data-model.md) — metadata-driven, not EAV; fields, collections, derived values, money, the metadata editor |
| §6 | [Extensibility](architecture/extensibility.md) — modules, presets, the store, and what may not be published |
| §7 | [Open design questions](architecture/open-questions.md) — what is still undecided, and what each question has already been narrowed by: veto events, metadata migration, module upgrades |
| §8 | [Identity and access](architecture/identity-and-access.md) — tenancy, authentication, permissions, the front end |
| §9.2 | [Decisions](architecture/decisions.md) — the ones belonging to no single section: the test stack, classic-mode FrankenPHP, migration versions, what `schema:validate` still reports |

**Cite the section you mean, not the brief.** Pointing anybody — a person or an
agent — at the whole thing is now expensive enough to be a decision rather than a
courtesy.

### What belongs here, and what belongs in a docblock

This file and the ones beside it are for what a reader **cannot** learn by opening
the code:

* the constraints that are not negotiable — §2's lessons, records are not
  entities, money is decimal strings, derived values are the engine's;
* **decisions about things that were deliberately not built.** Nothing in the code
  says why there is no worker mode, because there is no worker mode;
* rules that span more than one class, where no single file is their home — §4.4's
  grant derivation, §5.9's ownership of derived values, the additive-migration
  window;
* the shape of the system, for somebody arriving cold.

Everything else belongs in the docblock of the thing it describes, where it sits
next to the code it is about and cannot drift from it. Half of `src/` and
`packages/` is already comments; a paragraph here that restates one of them is a
second copy of a decision, and only one of the two gets executed. **Where they
disagree, the code is right.**

## 9. Status

### 9.1 What is built

**Moved out of this document.** A list of what exists is a changelog, and keeping
one inside a design brief made it drift: it contradicted §9.3 twice before anybody
noticed, because nothing forces a prose list to stay true.

See **[CHANGELOG.md](../CHANGELOG.md)**, which is now the record of what was built
and when, and which also explains how the version number works — `17` is a
generation rather than a semver major, and the number moves on release rather than
on feature.

What stays is the part a changelog cannot carry: *why* each of those decisions was
taken, in the sections above, and what is still open, below.

**§9.2 is in [architecture/decisions.md](architecture/decisions.md)** and keeps its
number. It holds the decisions that belong to no single section: FrankenPHP in
classic mode with no worker, why a migration version is unique across both sets,
what `tenant:schema:validate` still reports and why. Forty-two places in the code
and the configuration cite it by number, so the number is its address.

### 9.3 Next

**The permission system is built** (§8.4, §7.5). What is left of it is small and
named at the end of that section.

**Templates** (§6.1), the other half of provisioning: which modules a
customer gets, with which presets. They need nothing new from the engine — a
template is a list of installations it already knows how to perform — but they do
need somewhere in the control plane to live.

*Corrected by §6.6 ([XIV-141]).* "They need nothing new from the engine" is true of
the first half and false of the second. Installing modules with presets is indeed a
list of operations the engine already performs; **"then add these fields" is not**,
because the metadata editor cannot set a choice field's `choices` or a reference's
`module`, and those are the two things a vertical is made of. So templates want one
thing from the engine after all, it is §5.4's own unfinished sentence — a type says
which of its options are the customer's to set — and it comes before the control
plane needs a table.

*And modules themselves are closed* ([XIV-141], §3.2). Nobody outside this
repository publishes one, Core is explicitly not a public API with a deprecation
policy, and the store may assume a curated catalogue.

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

# Xivi 17 — Architecture Brief

Metadata-driven CRM/ERP engine in Symfony, plus a working CRM built on top of
it. Ground-up rebuild of its predecessor, called **v1** throughout; the version
numbers are not a sequence, and no data migrates from v1. Solo project.

This brief is the authoritative context for design decisions. It is a summary
of the engine and its decisions, not the story of how each landed. The stories
live in the issue tracker and in git history; what is here is each decision,
its address, and the one reason that keeps it from being relitigated. When a
decision here conflicts with a convenient shortcut, follow the brief or raise
the conflict explicitly.

---

## 1. What this is

Two deliverables, built together, in this order:

1. **The engine**: metadata layer, storage, dynamic forms, validation, plugin
   surface.
2. **The CRM**: real modules built on the engine, which is what keeps the
   engine honest.

The engine is not allowed to grow features that no module actually needs. Earn
the abstraction: a second concrete use case before a generalization.

That rule gained its second half on 2026-08-20: **once a second module needs
it, it is the engine's.** A capability two modules carry copies of is an
abstraction already earned and not yet collected; only what is unique to one
module belongs in one. The first real test of this half will be recurrence,
because memberships and recurring invoices both want a thing done on a clock
inside a tenant, and neither module may own the clock. §4.5 is where the
clock's outside half already lives.

---

## 2. Lessons from v1 (hard constraints, not preferences)

v1 was ~90 repositories. The concept was sound; these failure modes must not
reappear:

- **No public static state.** Everything goes through DI; global reach means a
  service.
- **No stringly-typed hook registry.** Extension points are typed event classes
  and tagged services.
- **No implicit subclass contracts.** A required implementation is an
  interface, checked at compile time.
- **No EAV.** See §5.
- **Testable by construction.** A piece of the engine that is hard to test is a
  design defect, not a testing problem.

---

## 3. Code organization: monorepo

Single repository; modules are Symfony bundles under `packages/`, wired via
Composer path repositories. v1's multi-repo setup imposed a permanent
version-matrix tax.

**Boundary enforcement replaces repo boundaries**, with deptrac in CI:

- modules may depend on core, never on each other directly;
- core may never depend on any module;
- cross-module communication goes through events and interfaces only;
- the administration surface may depend on the application; the application may
  never depend on it (§3.1).

**And the check has to be checked** (XIV-60). Every layer collected nothing for
four months, and deptrac reported no violations for the same reason an empty
configuration would. **Plant a violation whenever a layer is added.** The
standing instruction and worked examples are in `deptrac.yaml`'s own header.

If external distribution is ever needed, use the `symfony/symfony` model, a
monorepo with read-only subtree splits. Do not pay for it now. Per-customer
module availability is a runtime concern, not a packaging concern.

**Modules may need each other at runtime** (XIV-23), so a module declares two
lists. Installing without a `requires` entry is refused, naming what to install
first. Installing without a `uses` entry succeeds, and the parts that depend on
it are simply not offered. Both hard would make "required" unreadable, and the
distinction is load-bearing all over §5 and §6.

### 3.1 The administration surface is a package; the registry is not (XIV-60)

**"Move the control plane out of the serving instance" is unsatisfiable.**
Every tenant request begins in the control-plane database, where
`TenantResolver` and `TenantConnectionParameters` turn a `Host` header into a
customer and a connection, so an instance without it cannot answer the first
question it is asked. What is separable is the **administration surface**. Two
halves of one database:

- **The registry** (`App\Registry`, in `src/`): the tenant row, domains,
  encrypted credential, status, module catalogue. It is read on every customer
  request and writes nothing.
- **The surface** (`Xivi\ControlPlane`, in `packages/control-plane`):
  provisioning, deprovisioning, tenant migrations, secret rotation, the
  catalogue's write side, operator identity and firewall, the tenant list,
  usage collection, the introspector. Nothing here sits on a customer's request
  path.

**The dependency rule runs opposite to the modules'.** The control plane may
depend on the application, because provisioning drives tenancy and the tenant
app's own services, and on core. The application may never depend on the
control plane; deptrac fails the build on it, and a serving instance can be
built without the surface compiled in, which §4.4 delivers. A module may depend
on neither, and neither on a module. `packages/xivi-mate` has the same
above-the-application shape.

Deliberately kept in the application: `app.control_plane_host` (a deployment's
fact, feeding `app.system_hosts` and the trusted-host pattern, §4.3 and §4.4),
the firewall seam (`security.firewalls` must be named by one source, so the
application splices the package's firewalls in behind a presence check, §4.4),
and `migrations/control/`, whose namespace is recorded in
`doctrine_migration_versions`. The operator screens extend the tenant app's
base template, which is the allowed direction; the surface is not renderable
alone. One PHPUnit suite covers both halves, because the firewall-ordering
invariant is a property of the assembled application.

### 3.2 Only we publish modules (XIV-141)

**Decided: closed.** Every module is one we wrote, ship in the image and
maintain. No third-party module, no plugin registry, no side-loading. Open is
five permanent obligations (distribution, review, versioning, revenue,
support), and a half-maintained third-party module is worse for its buyer than
none. Four consequences:

- **Core is not a public API and has no deprecation policy.** `Xivi\Core` may
  change in any release, because every caller is in this repository and fixed
  in the same commit, and `bin/ci` green across every module is a stricter
  check than a deprecation notice.
- **Which verticals we own is a list, not a reflex.** A vertical earns a module
  when it needs *behaviour* (a lifecycle, a deriver, a document, a number
  series), not different words on a contact. Most requests are refused, and
  refusing is the decision working.
- **The store is designed for a curated set and may assume it**: no unbounded
  catalogue, no search index it does not need.
- **The package boundaries stay, on their own merits.** They made the two-image
  split possible and keep five modules from becoming a mesh. Not an abandoned
  plugin plan, and now on record as not one.

What this does not close is whether a *vertical* can arrive without a deploy.
That is data, answered by §6.1's templates and examined in §6.6.

---

## The rest of the brief

§4 to §8 live in `docs/architecture/`, one file per area. XIV-149 split them
out on 2026-08-19, and XIV-159 **distilled them to their decisions on
2026-08-20**, deleting the issue-by-issue narratives in favour of the rules
they produced. Git history holds every earlier version, and the tracker holds
the stories.

**Section numbers never change.** §5.9 is §5.9 wherever it lives; code and
tickets cite sections by number, so the number is the address.

| | |
| --- | --- |
| §4 | [Deployment topology](architecture/deployment.md): single instance, database per tenant; the control plane, grants, the deploy, monitoring, the tenant lifecycle |
| §5 | [Data model](architecture/data-model.md): metadata-driven, not EAV; fields, collections, history, the query layer, the editor, money, documents, mail, the module features |
| §6 | [Extensibility](architecture/extensibility.md): presets, templates, module states, the store, prices, shape packs |
| §7 | [Open design questions](architecture/open-questions.md): what is still undecided, and what each question has been narrowed by |
| §8 | [Identity and access](architecture/identity-and-access.md): tenancy, authentication, permissions, the front end, operators, signup, support |
| §9.2 | [Decisions](architecture/decisions.md): the ones belonging to no single section: the test stack, classic-mode FrankenPHP, migration versions |

**Cite the section you mean, not the brief.**

### What belongs here, and what belongs in a docblock

This file and the ones beside it are for what a reader **cannot** learn by
opening the code:

- the constraints that are not negotiable: §2's lessons, records are not
  entities, money is decimal strings, derived values are the engine's;
- **decisions about things deliberately not built**; nothing in the code says
  why there is no worker mode, because there is no worker mode;
- rules that span more than one class, where no single file is their home;
- the shape of the system, for somebody arriving cold.

Everything else belongs in the docblock of the thing it describes. Half of
`src/` and `packages/` is already comments, and a paragraph here restating one
is a second copy of a decision, of which only one gets executed. **Where they
disagree, the code is right.**

## 9. Status

### 9.1 What is built

See **[CHANGELOG.md](../CHANGELOG.md)**. A list of what exists is a changelog,
and keeping one in a design brief made it drift. The changelog also explains
the version scheme: 17 is a generation, not a semver major, and the number
moves on release.

**§9.2 is in [architecture/decisions.md](architecture/decisions.md)** and keeps
its number, because code cites it by number.

### 9.3 Next

**The permission system is built** (§8.4, §7.5); what is left is small and
named there.

**Templates** (§6.1), the other half of provisioning: which modules a customer
gets, with which presets, then which fields. They need one thing from the
engine first, which is §5.4's own unfinished sentence, a type saying which of
its options are the customer's to set, applied to the two options a vertical is
made of (§6.6). After that they need somewhere in the control plane to live.
Modules themselves are closed (§3.2), and the store may assume a curated
catalogue.

The two §7.2 halves still open are a field changing type and purging a removed
field's values. Type change is decided on paper (§7.2; XIV-146 builds it).
Purge stays open, deliberately beside it.

Deliberately still missing, each needing a decision rather than an
implementation: column promotion, and the remaining halves of §7's questions.
Two things to keep honest while templates land: the metadata layer will want a
per-tenant cache, which is §7.4 in a new costume, and file storage is half
answered. Document templates live in the tenant database; attachments are
many, large and long-lived, and still want a real answer.

### 9.4 What this project is testing, and the order of the verticals (2026-08-20)

This repository is an architecture test with a company as its exit option, and
the two are deliberately not the same decision. Whether the software holds is
*evidence*; whether to take responsibility for paying customers is a judgement,
made when it is made and not by a gate. What this section fixes is the
evidence, so that the judgement is made looking at something.

**The claim under test.** One installation serves Swiss SMBs of five to fifty
people, from genuinely different verticals, with **no code per customer**. A
new vertical may cost a module, because modules are closed and only we write
them (§3.2, §6.6); an onboarded customer may never require a commit. Every time
a customer's need turns into a commit anyway, that is the test speaking, and it
is worth a written note naming what the engine could not say.

**The evidence to gather before the verdict, three pieces:**

1. **Tenant zero.** Our own operation on a production tenant, invoicing its
   real subscriptions, is what `17.1` means. The changelog reserves MINOR for a
   release worth naming, and this is the one it has been waiting for. Payment
   collection can sit on an external provider; what has to be ours is the
   tenant lifecycle, the invoices and the QR-bill (XIV-152), carrying
   production traffic for a first customer who forgives.
2. **One genuinely different vertical.** Contact through voucher is a single
   vertical: selling things. The claim needs a second shape, services-on-time
   or memberships first, expressed without the engine growing a feature per
   customer. **Health-adjacent verticals wait as a named later wave.** Physio
   and care homes put patient and resident data into tenants, which raises
   nFADP special categories, contract work and hosting questions that are a
   cost of their own, and not the wave to learn on. Gym, software companies and
   case management carry ordinary personal data and come first.
3. **The fleet rehearsal** (XIV-154). The claim is thousands of tenants and the
   fleet today is three. A thousand throwaway tenants, one real migration
   walked across them, one run killed mid-flight, and numbers instead of
   impressions.

**The business model, recorded so features can aim at it.** A signup fee by
vertical, subscription tiers that differ in support level, and some modules
carrying a one-time price on top; §6.5 built exactly that price and stopped,
deliberately, before recurring. What a lapsed subscription means is §4.6; what
support may see inside a tenant is §8.18.

**Hosting is decided in two steps, and jurisdiction is the second**, recorded
on XIV-61 as well. The first test deployment goes to Germany, because it is
cheap and nothing on it is real. **A Swiss datacenter is a hard precondition
for onboarding any paying customer**, and for the health wave it is not even a
discussion.

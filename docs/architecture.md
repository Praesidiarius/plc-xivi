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

#### Removing a live tenant means disconnecting it (XIV-94)

The argument above has a consequence nobody wrote down until it broke something.
If `suspended` is not a prerequisite, then the tenant most likely to be removed
is one that is still serving requests — and a tenant serving requests is, from
the cluster's side, a database with sessions open to it. Postgres refuses
`DROP DATABASE` while any session is attached, `IF EXISTS` and all:

```
SQLSTATE[55006]: Object in use: 7 ERROR:  database "tenant1_test_permissions"
is being accessed by other users
DETAIL:  There is 1 other session using the database.
```

It was reported from the test suite, where deprovisioning happens constantly and
a connection from a previous test had not closed yet, and it would have been easy
to file as a test-suite problem. It is not one. The statement that failed is the
statement a real deprovision runs, `deprovision()` clearing the tenant switcher
settles only *our own* end of it, and the operator's version of the same failure
is worse than the suite's — because the control-plane row was removed and flushed
**before** the drop, so the failure left a database and a role that nothing knows
about. Every tool in this project starts from the registry: `tenant:list`,
`tenant:inspect`, the control-plane pages, the deprovision command's own lookup.
An orphan out there is invisible to all of them, and the row that named it is the
thing that was just deleted.

**So the order was turned around: database, role, registry row.** The two
orderings do not fail equally. Dropping first and failing leaves a row pointing
at nothing, which every one of those tools can show and which running the same
command again repairs — both drops are `IF EXISTS`, so a second run steps over
whatever is already gone and finishes by removing the row. Removing the row first
and failing leaves wreckage that needs `psql` and somebody who happens to
remember the database name. That is the whole argument; it is not a preference
about which half is more likely to fail.

**Disconnecting people is written out as a step, not spelled `WITH (FORCE)`.**
Postgres 13 and later accept `DROP DATABASE … WITH (FORCE)`, which terminates the
sessions and drops in one word, and it would have been a one-character change.
What that word does is throw a customer's users out mid-request. That is the
correct behaviour here — it is the direct consequence of refusing to require
`suspended` — but it is a decision, and a decision that arrives as a keyword on
the end of an unrelated statement is one nobody reads. `pg_terminate_backend`
over `pg_stat_activity` is therefore its own named step with its own argument
attached, and the drop still carries `WITH (FORCE)` in the belt-and-braces
arrangement `bin/ci`'s test-database reclaim already uses (§9.2): the statement
handles every session that exists now, the keyword handles the client that
reconnects in between. Only one of the two is the reason the drop succeeds.

**`pg_terminate_backend` requests a termination; it does not perform one**
(XIV-142). It sends SIGTERM and returns true when the signal was delivered. The
backend acts on it at its next interrupt check, detaches from shared memory, and
only then leaves `pg_stat_activity` — so between the statement returning and the
session actually being gone there is a window, short and machine-dependent, in
which the cluster will still tell you somebody is connected. Measured here:
descheduled backends clear in under three milliseconds, and a backend held under
SIGSTOP never clears at all while it is held.

That window is not a problem for the removal, because the `WITH (FORCE)` on the
drop covers it — and covers it by *waiting*, which is the half of that keyword
nobody had written down. Postgres signals whatever is left and then polls for up
to five seconds for those backends to detach, raising the same
`55006 … is being accessed by other users` if they do not. Measured on this
cluster with one backend under SIGSTOP: the drop failed after 5005 ms, into the
`TenantRemovalFailed::databaseSurvived` path that already exists for it. So the
ordering above is unaffected and XIV-142 changed no behaviour — only
`TenantRemovalFailed::sessionsCameBack()`'s docblock, which used to say the only
thing left after a terminate was a client reconnecting, and now names the stuck
backend as the second thing an operator might be looking at. What the window
does break is anything that asks *the cluster* about sessions in the statement
immediately after the terminate, which is what
`TenantDeprovisionCommandTest::testTheProvisioningCredentialsMayEndATenantSession`
was doing and what made it fail about one run in ten under eight parallel
workers. That test now polls to the same five-second deadline the drop keeps, so
it fails exactly where a real deprovision would rather than wherever the
scheduler happened to land.

**Two guards keep it from terminating itself.** `pid <> pg_backend_pid()`
excludes the connection issuing the statement, which would matter if a
provisioning DSN were ever pointed at a tenant's own database; `datname = ?` is
the one that matters in practice, since the admin connection is opened against
the maintenance database and the control plane's is a third database again.
`RecordCounter` is the one thing that deliberately opens a *tenant* connection
just before a deprovision — it counts what is about to be destroyed, for the
confirmation — and its docblock has always worried about being the session that
blocks the drop. It closes on the way out, so it is not; and if it ever failed
to, the terminate would now close it for it. The two concerns agree rather than
fight.

**Whether the provisioning credentials may do this was measured, not assumed.**
Terminating another role's backend is not implied by `CREATE DATABASE` and
`CREATE ROLE`. Postgres allows it to a superuser, to a member of
`pg_signal_backend`, or to a role that *inherits* the privileges of the connected
role — and that last clause is a trap, because since Postgres 16 a `CREATEROLE`
role's grant on the roles it creates carries `ADMIN` without `INHERIT` or `SET`.
On this project's Postgres 18, a role with exactly `CREATEDB CREATEROLE` was
observed failing with `42501 permission denied to terminate process` against a
tenant role it had created itself, and succeeding once granted
`pg_signal_backend`. Development and test run as the cluster superuser and never
meet it, which is precisely why it had to be measured rather than inferred from a
green suite. The privilege error is caught by name and answered with the grant
that fixes it; `TenantRemovalFailed` carries the sentence.

The same experiment turned up two things about a narrowed provisioning role that
are **not** fixed here and are worth knowing before anyone tries one in
production: `CREATE DATABASE … OWNER <tenant role>` fails for it with "must be
able to SET ROLE", and `DROP DATABASE` fails with "must be owner of database",
both for the same Postgres 16 change to what `CREATEROLE` confers. A deployment
that wants provisioning credentials short of superuser needs a `GRANT … WITH SET
TRUE, INHERIT TRUE` on every tenant role as well, which is a design question for
the provisioning half rather than the removal half and is left open.

**And the operator is told a sentence.** The failure used to surface as a DBAL
driver exception with a SQLSTATE in it and no statement at all about what had
happened to the customer. `tenant:deprovision` now catches the four states a
removal can stop in, and prints what XIV-74 taught `tenant:reset` to print: what
went wrong in one sentence, what exists right now — database, role and row, each
named — and the line to type next, which is the same line, because the order was
chosen so that it always is. The driver's own words are kept, underneath, in the
same place the unreadable-database note goes.

**What the ticket deliberately did not touch:** what the command asks before it
acts. The confirmation, the interactive default of *no*, and the outright refusal
of an unattended run without `--force` are all settled above and were left alone.


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

### 4.2 What a deploy has to do, and where each part of it runs (XIV-61)

**This section is half of XIV-61 and says which half.** The ticket asks for two
things: a deploy *definition* — which tool, which host, an image registry, how a
rollback works — and the things a deploy has to *do*. Only the second is built,
because the first cannot be verified from here. There is no target host, and a
Deployer configuration that is green in CI and unproven where it matters is worse
than none: it reads as done. What follows is the part that is true whichever tool
eventually wins, and it is the part that was actually missing.

#### There were two sets of migrations and only one of them ever ran

`migrations/control` is the control plane's, one database, and
`frankenphp/docker-entrypoint.sh` has always applied it on container start.
`migrations/tenant` is every customer's, one database each, and it is applied by
`tenant:migrate` — which **nothing invoked anywhere**. Not the entrypoint, not a
script, not a cron, not a runbook. §4 has said since it was written that every
schema change lands for every tenant; what it did not say is who makes that
happen, and the answer was nobody.

The consequence is not subtle. Shipping an entity change meant new code serving
every customer against the old schema, indefinitely, and the first sign of it
would be a query failing in production for a customer who had done nothing
unusual. It is the single most important thing this ticket fixes.

#### The tenant migrations are a one-shot deploy step, not an entrypoint step

`bin/deploy` runs, in this order: the secret check below, the control-plane
migrations, the two checks that read the database the migration has just moved —
`deploy:check-grants` ([XIV-143], §4.4) and then `deploy:check-hosts` ([XIV-93],
§4.3) — and finally `tenant:migrate` across the whole registry. It is meant to be
run once per release, out of the image being released, before the serving
containers are replaced.

**The checks sit where they do because of what they read and what they cost.**
Both need the control-plane schema to be current — one asks about a table this
release may have added, the other reads the registry — so neither can run before
the migration above them; and both are before the containers are replaced, which
is what makes a non-zero exit cheap rather than an outage. Everything a deploy
can discover for free, it discovers there.

Putting the tenant loop in the entrypoint beside the control-plane migration is
the obvious alternative and was rejected for three reasons that compound.

1. **The entrypoint runs on every container start, which is not once per
   deploy.** An OOM restart, a health-check flap, a node draining, somebody
   typing `docker compose restart` — each would walk the whole registry. That is
   not merely wasteful. It makes a routine restart of one container into an
   operation across every customer's database, taken at whatever moment the
   restart happened to occur, which is precisely the sort of thing nobody has
   booked a maintenance window for.
2. **It would put work proportional to the customer count in the startup path.**
   The control-plane migration is one database and one transaction, and the
   container cannot serve a single request without it. The tenant loop is N
   connections, N metadata reads and N migration runs, with the container not
   serving for the duration. At fifty customers a restart stops being a restart.
3. **Concurrent starts would race.** Each tenant database tracks its own
   versions, so two containers starting together would compute the same plan for
   the same databases and both begin applying it. `all_or_nothing` protects a run
   from itself, not from another run. The topology is a single instance today
   (§4), which makes this the cheapest of the three to dismiss and the most
   expensive to have dismissed wrongly later.

A one-shot step has none of those properties. Its honest cost is that it has to
be *called*, and an entrypoint cannot be forgotten while a script can — that cost
is paid in the deploy definition this ticket still has open, rather than by
making every container start do something it has no business doing.

**The entrypoint keeps its control-plane migration**, and running it in both
places is deliberate rather than sloppy: it is idempotent, so the second run
costs a version query, and what it buys is that a container can never serve
against a control-plane schema older than itself.

**`bin/deploy` is a file in this repository rather than lines in a runbook** for
two properties a runbook does not have. It ships inside the image it deploys, so
the sequence being run is the sequence that release was written against — a
runbook lives elsewhere and is edited by somebody who is not looking at this
branch, which is how a deploy comes to run last month's steps against this
month's migrations. And it cannot be half-run: the ordering matters, because the
tenant loop reads the registry and a release that adds a control-plane column
that query needs must move the control plane first. Typed by a person, that
ordering is a convention; written down, it is a property.

#### The migration window: additive only, and the instance stays up

N tenant databases do not migrate atomically. While `tenant:migrate` walks them,
some customers are on the new schema and some are on the old one, and **the code
serving all of them is the same code**. That window is real, it is minutes long
at fifty customers, and it grows with every sale.

There were two honest answers and one dishonest one. The dishonest one is to
leave it unstated, which is what this project had been doing — §4 already asked
for expand/contract, but as a property of migrations rather than as a decision
about a deploy, so nothing said what it forbade or who checked.

**Rejected: taking the instance down for the duration.** It is the simpler
guarantee and it would let a migration do anything at all. It was not adopted
because the length of the outage is a function of how many customers exist:
every sale makes the downtime worse, which is the wrong direction for a cost to
move, and the pressure that creates is pressure to skip the window rather than to
plan it. It also buys less than it looks like it does — the instance would be
down for a *tenant* migration, which is most of them, so this would not be a rare
event.

**Decided: the instance stays up, and tenant migrations may only add.** Expand in
this release; contract in a later one, once every customer is past the first.
`bin/deploy` runs before the containers are replaced, which is what makes the
ordering safe in that direction: old code meets a schema that has only gained
things.

**What that forbids, in a tenant migration's `up()`:**

- `DROP TABLE` and `DROP COLUMN`. Code still running reads and writes those, and
  Doctrine names every column in its `INSERT`s rather than relying on defaults.
- `RENAME TO` and `RENAME COLUMN`, on a table or a column. A rename is a drop and
  an add in one statement and breaks old code exactly as a drop does. Add the new
  name, write both for a release, drop the old one later.
- `SET NOT NULL` on an existing column. Code still running does not know it has
  to write that column, so its inserts start failing the moment the migration
  lands. Backfill first, constrain in a later release.
- Narrowing a type or a length, and adding a `UNIQUE` constraint that code still
  running can violate. Both are destructive across the window and neither is
  mechanically checked — see below.

`down()` is not constrained by any of this. A rollback is a deliberate act by
somebody who has decided to go backwards, and forbidding a `down()` from removing
what its `up()` added would forbid reversibility itself.

**Something checks it**, because this rule had already been written down four
times — AGENTS.md, `config/migrations/tenant.php`, `TenantMigrator`, §4 — and
checked zero times, which is the exact shape of the two failures this project has
already had (`deptrac` green for four months because its layers were empty, and
`SERIAL` in eleven migrations because nothing but prose objected).
`tests/Unit/TenantMigrationsAreAdditiveTest.php` reads each tenant migration's
`up()` with PHP's own lexer, strips the comments — these files argue with
themselves, and a migration explaining why it is *not* dropping a column must not
fail on its own docblock — and refuses the four statements above. One migration
predates the rule and is exempt by name, `Version20260814084512`, the rename that
turned `module_definition` into `shape_definition` three weeks before this
installation had a customer to break.

**The check is deliberately blunt and deliberately incomplete.** A type narrowed
from `varchar(255)` to `varchar(64)`, a `UNIQUE` constraint old code can still
violate, a data migration that rewrites rows old code will read back: all
destructive across the window, none visible to a regular expression. The rule is
the author's to apply; the test catches the cases the rule is most often broken
by accident in, and saying so here is better than implying a guarantee it does
not give.

#### "Migrated 49 of 50" is not success

`tenant:migrate` catches per tenant and carries on, which is correct and is not
changed: one unreachable database must not cost the other forty-nine theirs,
because stopping at the first failure leaves everybody after it in the registry
serving new code against the old schema — the situation the command exists to
end.

What was wrong is what it told its caller afterwards. Measured before this
ticket, an empty registry exited 1, an unknown `--slug` exited 1, and a run in
which tenants failed exited 1. A deploy could not tell "there is nothing to do"
from "one of your customers is on the wrong schema and the new code is already
serving them", and the safest thing it could do with that was treat a healthy
installation with no customers as a failed deploy.

There are three codes now, and they are the command's published contract:

| code | meaning |
| --- | --- |
| 0 | every tenant asked about is at the latest version |
| 1 | the run could not happen: an empty registry, or a slug nothing answers to. Nothing changed |
| 3 | the run happened and at least one tenant is behind. The others were migrated and are fine |

Three rather than two, because Symfony's `Command::INVALID` is 2 and means "you
typed the command wrong" everywhere else; borrowing it for "a customer's database
refused a connection" would make the number lie to the first tool that read it
generically. A deploy stops on anything non-zero either way — what the
distinction buys is what it can *say* afterwards, and that a partial failure can
be retried with `--slug` for the tenants that failed rather than by re-running
the whole registry and hoping. The failure output names them and prints the line
to type.

#### A container refuses to start on a secret anybody can read

`.env` is committed and public, and it carries working values for everything the
application needs so that a fresh checkout starts with nothing configured. Two of
those values are secrets. The production image compiles `.env` into
`.env.local.php` during the build (`composer dump-env prod`), so a freshly built
image contains, verbatim:

```php
'APP_SECRET' => 'dev-only-not-a-real-secret',
```

A real environment variable still overrides it, so a deployment that supplies
`APP_SECRET` is fine. **A deployment that forgets is also fine, and that is the
problem**: there is no error, no warning and no degraded behaviour. Cookies are
signed, invitation links verify, the instance is healthy. It is simply signing
them with a value published on the internet, and the way that surfaces is not a
log line — it is somebody forging one.

`TENANT_SECRET_KEYS` is the same shape with worse consequences. Its dev keyring
is committed in `.env` a few lines further down and it encrypts every tenant's
database password and every tenant's outgoing-mail password at rest in the
control-plane database. §8.9's cipher is honest that it defends against a *copy*
of that database, which is exactly the defence a public key removes.

**The rule is not a list of bad strings.** `App\Deployment\PlaceholderSecretGuard`
reads `.env` at the moment of the check and refuses any secret whose live value
is byte-identical to the value committed there. A list of literals would need
editing every time `.env` changed, and the day it was not edited is the day the
check quietly stopped looking at one of them. What still has to be listed is
*which* variables are secrets, and that list is short and stable — and getting it
wrong in the safe direction, by forgetting to add a third one day, leaves the two
that matter checked, where a stale list of values leaves nothing checked at all.
An unreadable `.env` is a refusal rather than a pass: "cannot tell whether this
instance is running on a public secret" is not a question to resolve in favour of
starting.

**Where the check runs, and the three places it deliberately does not:**

- *Not a compiler pass, and not `Kernel::boot()`.* Both would refuse the **image
  build**, which runs `composer dump-env prod` and then `cache:clear` — booting
  the production kernel, in the production environment, on the placeholders, as a
  normal part of building an image. It has to: nobody supplies a customer's
  `APP_SECRET` to a build, and the same image is what every deployment runs. A
  check there would make the image unbuildable, which is a fine way to have the
  check deleted within a day.
- *Not a `kernel.request` listener.* That is a container that starts, reports
  healthy, binds its port and then answers everything with a 500. The failure
  being guarded against is a deployment that looks healthy; a different kind of
  looking-healthy is no answer.
- *Not only in `bin/deploy`.* A deploy can be skipped, replayed from an older
  revision, or walked past by restarting a container by hand, and the container
  that comes back from any of those is the one serving customers.

So it is `deploy:check-secrets`, run by the entrypoint before the database wait
and before any migration. `set -e` means a refusal never reaches `frankenphp
run`, and the failure presents as a container that will not come up — which is
loud — rather than as a service that is fine. `bin/deploy` runs it too, because
that is the earliest a deploy can find out, and failing there costs a one-shot
container rather than whatever the orchestrator does with a service that will not
start.

**It stands down entirely outside `APP_ENV=prod`.** `bin/ci`, the test suite and
`bin/compose up` all run on the placeholders on purpose — that is what lets a
fresh checkout start — so refusing in development would be refusing the ordinary
case. The environment decides rather than the debug flag, for the same reason
`NonProductionMailGuard` gives (§8.7): the environment is what the kernel allows,
while debug is something production can legitimately be run with while somebody
diagnoses a problem, and an instance being diagnosed is still an instance serving
customers.

The refusal names the variable, shows enough of the value to recognise it without
printing the whole thing, says what that secret protects, and prints the command
that generates a real one.

#### Still open on XIV-61

The deploy definition itself: which tool (Deployer was the candidate), an image
registry, rollback, and more than one target. None of it is here, and none of it
should be read into the above — `bin/deploy` is a step *for* a deploy, not a
deploy. Two things are worth writing down for whoever picks that up. A deploy
must call `bin/deploy` and must stop on a non-zero exit, because nothing else
runs the tenant migrations. And rollback is constrained by the window decision
above rather than free of it: the schema this release expanded is still expanded
after the code goes back, which is exactly why additive-only is what makes going
back possible at all.

### 4.3 Which hostnames this installation answers to (XIV-93)

`framework.trusted_hosts` was not configured at all and no trusted proxies were
set, so the `Host` header was taken exactly as sent. This section is what
replaced that, and it starts with what the gap actually was — because the honest
answer is narrower than "host header injection", and the part that is *not*
fixed is the part worth writing down.

#### Tenancy was already blocking most of it

`TenantRequestListener` resolves the host through the registry before routing and
throws `NotFoundHttpException` for a host no tenant claims. So an arbitrary
`Host: evil.example` never reached a tenant page; it reached a 404. That is a
real mitigation, it is why this had not bitten, and it is the reason this ticket
was reported from inside [XIV-57] rather than worked around at the time.

**The residue is the hosts that deliberately resolve no tenant.**
`app.system_hosts` bypasses tenant resolution by design, and since [XIV-57] the
control-plane hostname is one of its entries. So anybody who can set `Host:` to
the control-plane hostname reaches the control-plane sign-in page, from any
address that terminates the connection — not only from the name it was meant to
be served on.

**That is still true after this ticket, and it cannot be otherwise.** The
control-plane host is by definition one of the hostnames this installation
answers to, so it is *inside* the trusted-host pattern rather than outside it. A
pattern cannot distinguish "this request arrived at the right IP with the right
certificate" from "somebody wrote the right string in a header"; only the
network in front of the application can, and §4's topology does not have one.

So the sentence to hold on to is: **the control plane is not isolated by its
hostname.** What isolates it is the three layers §8.9 built — the route does not
exist on any other host, the credential is answerable only by the control plane's
own provider, and `access_control` demands `ROLE_OPERATOR` that no tenant
database can grant. Every one of those still applies to a request that arrived
with a forged `Host`. What the hostname buys is obscurity and a place to point
DNS at, and §8.9 now says so in its own words rather than leaving "no tenant
hostname can reach a control-plane route" to read like something stronger.

**And since [XIV-124] there is a fourth layer, which is the one this section
could not be.** `CONTROL_PLANE_ALLOWED_IPS` refuses a control-plane request from
an address the deployment has not listed, before anything else looks at it —
which is the thing a hostname cannot do, because a hostname is a string in a
header and an address is where the connection came from. It is optional, empty by
default and enforced on `Request::getClientIp()`, so it inherits this section's
`TRUSTED_PROXIES` decision rather than acquiring a second copy of it; §8.9 has
the argument, including why an allow-list built on a raw header would be worse
than none.

#### The half that is fixed: what goes into a generated link

Absolute URLs generated during a web request take their host from the request.
Invitations ([XIV-1]) go out as Symfony login links — absolute URLs in an email —
so a request arriving on a host this installation does not serve would put that
host into a link somebody is invited to click. `config/packages/routing.yaml`
already sets `default_uri`, and it does **not** cover this: a request context
wins over it, so `default_uri` is the console's answer and only the console's.

Tenancy's 404 kept that theoretical while every served host resolved a tenant.
It stopped being theoretical the moment something was served on a host that is
not tenant-resolved, which is now true of the control plane and of [XIV-64]'s
public signup endpoint. A trusted-host pattern is what closes it, for every host,
before routing.

#### Why this is a composed pattern and not a configuration line

`trusted_hosts` is a list of **regular expressions**, and this application's
hostnames are a wildcard by design: every customer gets their own (§4). So the
pattern has to admit `*.<deployment domain>` plus the control-plane host plus
whatever else a deployment serves.

The two ways of getting that wrong are not symmetrical:

- **Too wide is the same as not setting it.** An unanchored `xivi\.app` also
  matches `xivi.app.evil.example`. That is the status quo with extra steps.
- **Too narrow takes a paying customer's installation off the air**, and the
  symptom is an empty 400 — no page, no header named, nothing in the body. The
  person who finds out is the customer.

A hand-written regular expression puts that asymmetry in the hands of whoever is
editing an environment file at the time, and both failures are one keystroke
away. A forgotten backslash makes every dot a wildcard, which is the exact
mistake §8.9 already declined to make when it refused to host-scope the
control-plane firewall with Symfony's `host:` key and compared normalised strings
instead.

**So a deployment names domains and the application writes the expressions.**
`XIVI_TRUSTED_DOMAINS=xivi.app,1plc.ch` is a fact an operator knows;
`App\Deployment\TrustedHosts` turns each entry into a pattern anchored at both
ends that admits the domain and any name under it. It accepts `*.xivi.app`,
`.xivi.app` and `xivi.app.` as the same thing, because each is what somebody
writes when they are thinking about DNS, and refuses an entry that is not a
hostname at all — a URL, a port, a regular expression — rather than compiling it
into something that matches nothing.

**The system hosts are added rather than asked for.** Every entry of
`app.system_hosts` is admitted as an exact literal, so the control-plane host,
the signup host, the loopback and the container's internal name cannot be left
out by a deployment that only remembered its customer domain. This is the same
construction §8.9 uses to keep `CONTROL_PLANE_HOST` and `app.system_hosts` in
step — one fact, composed, rather than two things somebody has to keep equal —
and it matters most for the control plane, which §8.9 asks to be served on a name
that is not guessable from the customer-facing domain and which therefore often
is not *under* it either. An operator who set `XIVI_TRUSTED_DOMAINS` and locked
themselves out of their own console would be the first casualty of this feature.

#### A deployment that sets nothing is unchanged

`XIVI_TRUSTED_DOMAINS` is empty in `.env`, and empty means no patterns, which
means `Kernel::preBoot()` never calls `Request::setTrustedHosts()` and the `Host`
header is not checked at all. A fresh checkout, `bin/ci`, the suite and
`bin/compose up` behave exactly as they did before this existed — development
serves `*.localhost` and the suite invents a hostname per test, and a pattern
maintained for either of those would be a pattern maintained for the case that
does not matter.

The subtle half is that the system hosts are **not** turned into patterns on
their own when no domain is configured. A non-empty pattern list switches host
checking on for everybody, so a list holding only `localhost` and the control
plane would refuse every tenant this installation has. That is the one way this
feature could have taken an installation dark by being installed.

#### Trusted proxies are decided here, not deferred

They belong in the same decision because getting one right while leaving the
other wrong is worse than leaving both: a `X-Forwarded-Host` believed from a
proxy would hand the choice of host straight back to the caller, which is the
thing the paragraphs above are about.

**Trusted proxies stay empty by default**, which is both the safe answer and the
accurate one — §4's topology has FrankenPHP terminating TLS itself, so nothing is
in front of it and `X-Forwarded-*` arrives only from somebody who made it up. A
deployment that does put a load balancer in front sets `TRUSTED_PROXIES` to its
addresses, and CIDR ranges and Symfony's `REMOTE_ADDR` and `private_ranges`
shorthands all work.

**The header set is decided in the repository rather than by the deployment**,
and the omissions are the decision:

| Header | Trusted | Why |
| --- | --- | --- |
| `x-forwarded-for` | yes | The client's address. Without it, everything this application ever logs or rate-limits by is the balancer |
| `x-forwarded-proto` | yes | Not optional in front of a TLS-terminating balancer: without it every absolute URL generated during a request — the invitation link above all — comes out as `http://` |
| `x-forwarded-port` | yes | The other half of the same sentence |
| `x-forwarded-host` | **no** | Tenant routing *is* the `Host` header. Most proxies append rather than replace, so believing this would let a caller pick the tenant and pick the host in a mailed link. DNS already decided the host; there is no case here where a proxy legitimately renames it |
| `x-forwarded-prefix` | **no** | Nothing here is served under a path prefix, and trusting it would let a proxy rewrite the paths in those same links |

#### A too-narrow pattern has to be findable, not merely correct

The 400 stays a bare 400 — whoever is on the far end of a refused request is by
definition not somebody this installation serves, and telling them which domains
it does serve, and that the answer lives in an environment variable, is telling
the one audience that should not be told. So the diagnosis goes where the
operator is, in three places, in the order they occur:

1. **`tenant:provision` refuses a hostname the pattern would refuse.** The only
   one of the three that prevents the failure rather than reporting it: a
   customer is never created on an address every request to which is a 400.
   Beside [XIV-57]'s refusal to route a tenant at a system host, in the same
   loop, because both fail the same silent way — a row that exists, an address
   somebody was given, and nothing anywhere saying why it is dead. Self-service
   provisioning ([XIV-98]) inherits it, since it goes through the same method.
2. **`deploy:check-hosts` names every tenant the pattern would refuse**, from
   the registry, and `bin/deploy` runs it between the control-plane migration
   and the tenant migrations — the earliest moment the registry is readable and
   still before the serving containers are replaced. Exit 3 stops the deploy, on
   `tenant:migrate`'s published convention (§4.2) so that a deploy script does
   not have to learn a second one. A refused hostname belonging to a suspended or
   half-provisioned tenant is printed and stops nothing: nobody is served on it
   either way, and a release held up by a customer suspended in March is how a
   gate comes to be run with `|| true`.
3. **A refused request explains itself in the log.**
   `App\Deployment\EventListener\UntrustedHostListener` writes one `error` line naming the host
   as sent, the variable, what it currently admits, and the command that lists
   who is affected. It matches on the throwable chain and on whether the raw
   header is admitted, rather than on the framework's message text, so a reworded
   Symfony string cannot turn it off quietly.

**The container entrypoint runs `deploy:check-hosts` too and ignores its exit
code**, which looks like a check nobody enforces and is deliberately the opposite
of `deploy:check-secrets` next to it. The asymmetry is about blast radius. A
published secret is a property of the *instance*, so refusing to start denies
exactly the thing that must not run. A hostname outside the pattern is a property
of **one customer**, who is already dark — and refusing to start over it would
take every other customer dark to protect them, on every restart, for as long as
the mistake stood. So the entrypoint's copy is the diagnostic: it puts the
pattern, and the names it refuses, into `docker logs` on every start, which is
where somebody chasing an unexplained 400 is already looking.

#### What is deliberately not here

**Nothing is derived from the registry at runtime.** A pattern computed from the
tenants' own hostnames would be exactly right and would be recomputed only when
the kernel boots, so a customer provisioned after the last restart would be dark
until somebody restarted the containers — the failure this section is about,
caused by the mechanism meant to prevent it. The registry is consulted by a
command instead, which is a check rather than a source of truth.

**Caddy's `SERVER_NAME` is not this.** The compose stack already restricts which
hostnames the web server answers on, and a deployment that names its sites there
gets a first line of defence for free. It is the web server's, not the
application's: it does not survive a deployment that puts a catch-all in front,
it says nothing about what goes into a generated URL, and it is not what
`Request::getHost()` consults. The two are complementary and neither replaces the
other.

**There is no `deploy:check-hosts --fix` and no way to widen the pattern from
inside the application.** Which hostnames an installation answers to is a
deployment's statement about itself; a running instance that could edit it could
be made to admit anything.

### 4.4 Two images: what a customer's instance is built without (XIV-96)

This is the deployment half of [XIV-60], lifted out once the package had landed
and the real shape was visible. §3.1 answered "can the control plane be
separated" — no, and what is separable is the *administration surface*. This
answers the question that survived it: **can a customer-facing build omit that
surface**, and the answer is yes, in an image that does not contain it rather
than in an instance that does not route it.

#### The topology, and what is reachable from where

Two deployments, one repository, one lock file, one control-plane database.

| | The customer-facing instance | The internal instance |
| --- | --- | --- |
| Image | `frankenphp_public` | `frankenphp_prod` |
| Contains `packages/control-plane` | no | yes |
| Served on | every customer's hostname | `CONTROL_PLANE_HOST`, and `SIGNUP_HOST` if signup is on |
| Firewalls | `dev`, `main` | `dev`, `control_plane`, `signup`, `main` |
| Routes under `/control` | none exist | the operator console |
| Signup intake and landing page | absent | present, if `SIGNUP_HOST` is set |
| Tenant databases | reads and writes, per request | reads and writes, while provisioning |
| Control-plane database | **`SELECT` on the registry tables only** | full |
| Owns the schema | no. Refuses to start until somebody else has moved it | yes. `bin/deploy` and the entrypoint |

The registry is still read on every customer request and is still `App\Registry`
in `src/`, unmoved and unmovable (§3.1) — an instance that could not resolve a
hostname could not serve anybody. What the customer-facing image lacks is the
half nobody's own request touches.

#### Why two build targets rather than one image with the routes switched off

Three options were weighed and the middle one won.

**One image, two deployments, routes enabled by environment** is the cheapest:
one build, no drift, nothing new to keep in step. It was rejected on one
sentence. **"Not routed" and "not present" are different guarantees, and only
the second survives somebody's mistake** — a copied `.env`, a merge that
reinstates a listener, a compiler pass that stops being registered, a `host:`
that stops matching. [XIV-56] is the live precedent rather than a hypothetical:
`.env.dev` shipped inside the production image because an exclusion list needed a
line added and did not get one. It was inert on the day and it was still in
there for weeks.

**Two build targets from one repository** costs a second build in CI and gives
an image that genuinely does not contain the administration code. Adopted. The
`Dockerfile` already had multiple targets, and the second build is nearly free
because it starts from the first's finished builder stage: an autoload dump and
a cache warm-up, seconds against the internal image's minutes.

**A separate repository** would give real isolation and real drift, plus a
shared control-plane schema owned by two repositories with no single migration
history. Not worth it for one operator, and the thing it would isolate is
already isolated by a package boundary deptrac enforces.

#### The obstacle that mattered: the application's security configuration

Dropping the Composer requirement was **not** sufficient, and finding out why is
most of what this ticket was. `config/packages/security.yaml` named
`Xivi\ControlPlane\Security\ControlPlaneHost` as the control-plane firewall's
request matcher, `ActiveOperatorChecker` as its user checker,
`Xivi\ControlPlane\Entity\Operator` as its provider's class and
`Xivi\ControlPlane\Signup\SignupHost` as the signup firewall's matcher. So the
container did not compile without the package — the build failed before
anything was served, in the security configuration.

Three more of the same kind were behind it, and each would have failed the build
on its own: `doctrine.yaml` named the package's entity directory (DoctrineBundle
checks that a mapping's directory exists while the container is built, so this
one fails with a message about a path rather than a class); `routes.yaml` named a
route *type* only the package registers; and `config/services.yaml` registered
three of its classes under `when@dev` and `when@test`.

**Everything the package can declare, the package declares now**, contributed
from `XiviControlPlaneBundle::prependExtension()`: the `operators` provider, the
`Xivi\ControlPlane\Entity` mapping, and its own dev-and-test service
registrations. The application says nothing about any of it.

**Two things could not move, and both are Symfony's decision rather than ours.**

- `security.firewalls` is declared `disallowNewKeysInSubsequentConfigs()`, so
  every firewall in the installation has to be named by one configuration
  source. The application therefore names all four — in
  `config/packages/security_firewalls.php`, which is PHP precisely because it has
  a question to ask — and *splices* the administration surface's two between
  `dev` and `main` by requiring `packages/control-plane/config/firewalls.php`
  when the package is present. So the application carries the seam and the
  package carries the surface: a build without it has no operator firewall
  because the file describing one is not in the image either.
- `security.access_control` is `cannotBeOverwritten()`, which is the same
  restriction one notch stricter: a second configuration source contributing to
  it throws while the container is built. The two `^/control` rules therefore
  stay in the application's `security.yaml`. What is left behind is two path
  patterns and a role name — no class, no service, nothing that stops a build —
  and it is the harmless direction to be wrong in: a customer-facing image where
  `^/control` still demands `ROLE_OPERATOR` carries one refusal it will never
  need, on paths it has no routes for.

**Three seams remain and each asks whether the class is *in this build***, which
is a question about what was compiled rather than about what somebody configured
— and a classmap-authoritative autoloader cannot answer it "yes" for a file that
has been removed. They are `config/bundles.php`,
`config/packages/security_firewalls.php` and `config/routes/signup.php`. Two of
them ask it with a literal `class_exists()`; the bundle seam asks it from
`App\Kernel` instead, for the reason [XIV-111] found and the next subsection
gives. `tests/Unit/Deployment/ControlPlaneIsOptionalAtBuildTimeTest.php` holds
the list: any other application configuration file naming the namespace outside
a comment fails the build, and a seam that stops guarding fails it too. deptrac
has said the same thing about `src/` since [XIV-60] and cannot say it about
YAML, which is exactly where all four of the real obstacles were.

#### One of the three seams was in a generated file (XIV-111)

`config/bundles.php` carried the guard as an `if (class_exists(…))` appended
after the array. Then `composer update xivi/voucher` ([XIV-103]) ran a Flex
auto-recipe and the conditional was gone. It was caught in a diff and reverted,
and nothing was ever broken — but the near miss is the whole of this ticket,
because the failure it was one merge away from is not a broken test. It is
`--target frankenphp_public` ceasing to be a thing this repository can produce,
discovered at the next release.

**Flex is not misbehaving.** It regenerates `bundles.php` from its own template
rather than editing it in place, so a hand-written conditional there is
collateral by design. Adding a package is not an operation that promises to
leave `config/bundles.php` alone, and treating it as one is the mistake.

**The guard was also a general rule dressed as a special case.** *Do not
instantiate a bundle whose class is not in this image* has nothing to do with
the control plane, and nothing about it belongs in a generated file. So it moved:

- `config/bundles.php` is now a plain declarative array. It names
  `Xivi\ControlPlane\XiviControlPlaneBundle` unconditionally — **exactly the
  line Flex would write anyway**.
- `App\Kernel` does the skipping, from the explicit list in
  `config/optional_bundles.php`.

The property that makes this the right answer rather than a tidier one: **a Flex
rewrite of `config/bundles.php` stops being a hazard**, because the file it
produces is the file we want. That is strictly better than detecting the
rewrite, which was the other option and which would have needed somebody to
react to an alarm instead of needing nothing to happen at all. `src/Kernel.php`
is not regenerated when a package is added.

**Overriding `registerBundles()` without reimplementing it.**
`MicroKernelTrait::registerBundles()` is a generator that reads the bundle
definition, applies the per-environment filter and yields `new $class()`.
Wrapping it is useless — the instantiation happens *inside* the generator, so a
filter over what it yields runs after the fatal, and a generator that has thrown
cannot be resumed. Copying its four lines would mean owning Symfony's
environment-matching semantics for ever. The seam that avoids both is
`getBundlesDefinition()`, the private method the trait reads the array from,
which `MicroKernelTrait` already aliases to `doGetBundlesDefinition` for exactly
this kind of decoration; a method declared on the class takes precedence over
the one a trait imports. So the kernel removes entries from the array and
Symfony still does the reading, the `#[RequiredBundle]` resolution and the
environment matching. It filters the `.kernel.bundles_definition` container
parameter for free, which is built from the same method — and that matters,
because the `frankenphp_public` stage refuses to finish if anything under
`var/cache/` still names `Xivi\ControlPlane`.

**The list is explicit and short, because the risk this introduces is silence.**
A bundle skipped for being absent from the image looks exactly like a bundle
skipped because somebody's `composer install` did not finish. "Skip anything
missing" would turn a half-installed checkout into an application that boots,
serves and is quietly missing a module — and would pass every test here while
doing it. So anything *not* on the list that goes missing still fatals, loudly,
exactly as before, and
`tests/Unit/Deployment/OnlyOptionalBundlesAreSkippedTest.php` plants both halves
side by side.

**The list lives in `config/` rather than as a constant on the kernel**, and
that was decided by measurement rather than taste. `deptrac.yaml` says the
application may not depend on `Xivi\ControlPlane`; a
`XiviControlPlaneBundle::class` written into `src/Kernel.php` is collected as a
dependency and reports *"App\Kernel must not depend on
Xivi\ControlPlane\XiviControlPlaneBundle"*. Spelling it as a quoted string
would have slipped past the collector, and that is the reason not to — a
boundary evaded with a string is a boundary that has stopped being checked.
`config/` is where the application is already allowed to name the package, it is
the directory `ControlPlaneIsOptionalAtBuildTimeTest` reads, and it puts the
declaration beside the `bundles.php` whose reader is looking for it. **The
kernel holds the rule; the configuration holds the datum.**

**An absent optional bundle complains outside `prod`, which inverts [XIV-61].**
`PlaceholderSecretGuard` stands down outside production because the risk it
covers is production-only. This is the mirror image: the *legitimate* absence is
production-only, since `frankenphp_public` is the only build that removes a
package, so a `dev` or `test` checkout missing one is a broken install rather
than a deployment choice. It is an `E_USER_WARNING` naming the command that
fixes it, not an exception — the application genuinely works without the
administration surface, and `phpunit.dist.xml` sets `failOnWarning`, so in the
test environment it is effectively fatal anyway.

**What is still a Flex hazard, said plainly.** `importmap.php` is regenerated
the same way and is guarded by nothing. The stakes are much lower — a stylesheet
that comes back, not a deployment guarantee — so the answer is proportionate: no
mechanism, and the set of files that are *generated and hand-edited* is written
down in `AGENTS.md` where somebody adding a package will meet it, `importmap.php`
and `assets/controllers.json` included. What no longer depends on a recipe
behaving is the customer-facing image.

**[XIV-57]'s ordering invariant survives the move and is asserted the same way.**
`ControlPlaneFirewallTest` used to read the declared order out of
`security.yaml`; it reads the container's own `security.firewalls` parameter now,
which is the merged, compiled order and is a better question than the one it was
asking. The "host-scoped by a matcher, not by `host:`" assertions became
behavioural at the same time: a request to the hostname an unescaped regular
expression would also accept must land in `main`.

#### `app.control_plane_host` stays in the application, and so does `app.system_hosts`

[XIV-60] flagged this as the second obstacle and it turned out not to be one.
The parameter is read by a package class, which looks like the wrong direction
until you notice what else reads it: `app.system_hosts`, which is what makes a
control-plane request resolve no tenant (§8.9) **and**, since [XIV-93], what is
composed into the trusted-host pattern (§4.3). Both of those are the
application's. Moving the parameter into the package would have made a deployment
fact into a bundle default — answering a question about where the software is
installed — and would have split one fact across two files that must agree.

So the customer-facing image carries `CONTROL_PLANE_HOST` and `SIGNUP_HOST` and
uses neither for a firewall. A public deployment sets them empty; an empty entry
in `app.system_hosts` matches nothing, because no request has an empty `Host`,
which is the same property §8.12 already relies on for switched-off signup.

#### The templates are not renderable standalone, and that is still true

[XIV-60]'s third obstacle: the operator screens extend the tenant application's
`base.html.twig` and read their strings from its `messages` domain. Nothing here
changes that, and nothing needs to — the direction is the allowed one
(ControlPlane → App), and the internal image is the whole application plus the
surface rather than the surface plus a kernel. It is written down again because
it is the reason the split is by *image* rather than by deployable unit.

#### The strongest isolation is not network topology

Both instances talk to one control-plane database, so "which one is on which
network" is the weakest boundary available: both are on the network that
matters. The sharp one is **two database users with different grants**.

**Decided: the customer-facing instance's role holds `SELECT` on the registry
tables and nothing else.** No `INSERT`, `UPDATE`, `DELETE` or `TRUNCATE`
anywhere, no DDL, no sequences, and no access at all to `operator`,
`signup_request` or `tenant_usage`. An `INSERT INTO tenant` arriving from the
process facing the internet is not a thing that should be possible, whatever the
routing says and whatever a future bug in a controller does.

It costs nothing to arrange while an installation is being provisioned and is
genuinely awkward to retrofit once there are customers, which is the argument for
settling it here rather than leaving it as a note.

Nothing on a customer's request path writes to that database, and that was
checked rather than assumed: `App\Registry` reads, and the writers in `src/` are
`ModuleCatalog::moveTo()` and — since [XIV-101] — `ModuleCatalog::priceAt()`,
whose only callers are the `module:*` commands and the operator pricing screen;
`TenantSecretRotator` is driven from `tenant:rotate-secrets`; and — since
[XIV-120] — `Registry\Notice\NoticeBoard`, whose only caller is the operator's
notices screen (§8.16); and — since [XIV-123] — `Registry\Support\SupportDesk`,
whose only caller is the operator's support screen (§8.17), plus
`Support\SupportTicketCollector` in the package itself. Every one of those
callers is in the package and therefore absent from the image.

**That a writer is present in the image and unreachable is not the guarantee
being relied on**, and §6.5 says so at length where the split runs through one
feature. The grant is. A method that cannot be called today is one refactor from
being called; a role with no `UPDATE` is a refusal the database makes.

**And the grant has since decided a feature's data model rather than merely
guarding it** (§8.15, [XIV-102]). A customer asking to buy a module is a write
made by a customer's own request, so the sentence above leaves exactly one
database it can go in: theirs. The row lands in the tenant's
`module_purchase_intent`, an operator sees it because `tenant:purchase:collect`
copies it into the control plane, and the two shapes that would have avoided the
cron — widening this grant by one `INSERT`, or giving the public image a secret
and letting it POST to the internal one over HTTP — were both rejected on this
paragraph. The second is the one worth naming: re-obtaining over the network a
privilege the database refuses is a boundary made of care again, which is what
this whole section is about not doing.

**And it has since decided one the other way round** (§8.16, [XIV-120]). An
operator's notice to customers is written on this side and only *read* on
theirs, so it can live in the control-plane database and be read straight out of
it — no collector, no interval, no copy. What the grant decides there is the
**namespace**: the readable list is derived by asking the mapping for
`App\Registry\Entity` and nothing else, so a `Notice` filed with the
administration surface's entities would be withheld and every customer's
dashboard would meet a permission error. The sharper consequence is that the
recipients of an addressed notice are an *entity* rather than a `ManyToMany`,
because a join table is not a class, has no metadata, and is therefore invisible
to the generator — a grant that would have been produced, run, and been wrong.
Anything that is a table and not an entity is outside `readableTables()`;
`doctrine_migration_versions` is the only other member of that set and is named
explicitly for the same reason.

**And [XIV-123] has since decided one that goes both ways**, which is the case
neither of the two above covers. A support ticket is a customer's *write*, so
§8.15's sentence applies unchanged and the row goes into their own database with
a collector to bring it back. What the operator then does about it — a status, a
reply — is a write on *this* side that the **customer has to read**, which is
§8.16's direction. So the collected copy is an `App\Registry\Entity` class,
readable by a customer-facing instance, and the answer needs no second collector
pointing the other way: an operator writes it here and it is on the customer's
screen on their next request. That is why `support_request` is a registry table
while [XIV-102]'s `purchase_intent`, which nobody but an operator reads, is one of
the administration surface's. §8.17 has the argument, and
`tests/Functional/Deployment/SupportGrantsTest.php` proves it as the restricted
role.

**`bin/console deploy:registry-grants` prints the SQL**, and it prints rather
than executes for a reason: a running instance that could grant privileges to
itself could be made to grant itself others, so the application contributes the
list of tables and a database administrator contributes the decision. The list is
derived from the `control` entity manager's mapping rather than written out, so a
release that adds a registry entity cannot leave a hand-maintained script behind
— which is the failure that would otherwise present as a permission error on a
table nobody remembered, in production, at a moment nobody chose.

**It is proved against a real database rather than asserted about a string.**
`tests/Functional/Deployment/RegistryGrantsTest.php` creates the role, runs the
generated statements, opens a second connection *as that role*, and asks
PostgreSQL: `SELECT` on `tenant` and `tenant_domain` succeeds, `INSERT`, `UPDATE`
and `DELETE` on `tenant` are refused, and `operator` is not readable at all. The
string-matching version of that test would pass for a script that names the wrong
tables, one that forgets its `REVOKE`, or one that is never run.

#### Nothing checked that it had been run (XIV-143)

The paragraph above ends on "or one that is never run", and for two releases that
was exactly the hole. The list of tables is derived, so it grows on its own; the
**grant** grows when a database administrator runs the printed SQL, and nothing
anywhere asked whether they had. An installation upgraded without that step has a
role whose privileges match the *previous* release's entity list.

**It happened twice in two days.** [XIV-120] added `notice` and
`notice_recipient`, [XIV-123] added `support_request`. Both shipped a `CHANGELOG`
bullet saying the command has to be re-run, and a changelog bullet works exactly
as well as whoever reads changelogs. The cost of missing it is not subtle: the
notice widget is on the dashboard (§8.3.1), so a customer-facing instance one
release behind on grants answers **500 to every user of every tenant**, with
`SQLSTATE[42501]: permission denied for table notice`, and the support page does
the same.

That failure is loud rather than silent, which §8.3.1 prefers and which is better
than a page that quietly hides notices. But **loud at the customer is still the
customer finding out**, and this section's neighbour already owns the better
answer: `deploy:check-hosts` exists so that a deploy discovers a too-narrow
trusted-host pattern before a browser does. `deploy:check-grants` is the same
shape, one table over.

**It derives its expectations from `RegistryGrants`, not from a list.** The same
`readableTables()` that writes the `GRANT`s decides what is checked, and the same
`withheldTables()` decides what must be unreachable — so adding a registry entity
cannot make the check and the grant disagree, which is the only property that
makes a check like this worth having at all. Asserted from both ends:
`CheckRegistryGrantsTest` proves against a real cluster that what is reported
missing is exactly what the generator names, and
`RegistryPrivilegeExpectationsTest` invents an eighth entity in the mapping and
requires it to appear in the query and in the finding, which is what a hardcoded
list of today's seven tables would fail.

**It asks `has_table_privilege` rather than reading the ACL**, and the difference
decides real cases. An ACL comparison answers "was this statement run", which is
a question about history; `has_table_privilege(role, table, privilege)` answers
"can this role do it", which is the question a customer's request is about to
ask. A privilege reached through `GRANT`ed role membership, or held by `PUBLIC`,
is invisible in the first answer. A **superuser** is the sharp end of that: it
passes every privilege check there is, so a `DATABASE_URL` still carrying the
administrator's credentials undoes the whole of this section while every page
works — and it is reported on its own line rather than as one finding per table,
because it is not a grant that went wrong.

**Excess is a finding, not only absence.** A role missing `SELECT` is an outage
somebody will report within the hour; a role holding `INSERT` on a registry table
is this section's guarantee not holding while everything looks healthy, which is
worse. The same query answers both. [XIV-120] and [XIV-123] each asserted the
refusal for their own two tables against the *generated statements*; this asserts
it for every registry table against the **privileges the cluster is actually
holding**, at deploy time.

**It checks and does not repair**, decided rather than left to emerge. Re-running
`deploy:registry-grants` is idempotent, so the two are one line apart — but the
line is this section's own: an application that could grant privileges to itself
could be made to grant itself others. A repair also begins with `REVOKE ALL`, so
it would silently remove a privilege an administrator had added deliberately,
during a deploy, from a script. What it prints instead is the command to run, with
the role already in it.

**Where it runs: `bin/deploy`, immediately after the control-plane migration.**
That is the earliest moment the question can be asked — a table this release added
exists only once that migration has run — and it is before the serving containers
are replaced, which is what makes stopping cheap: the old containers keep serving,
the old code does not read the new table, and nobody is dark while somebody runs
one `GRANT`. Exit 3 and `set -e`, the same contract `deploy:check-hosts` and
`tenant:migrate` publish (§4.2). **Deliberately not in the container entrypoint**,
where `deploy:check-hosts` also appears as a diagnostic: the remedy here is a
statement only a database administrator can run, so a line in `docker logs` on
every restart would be advice nobody reading it is in a position to act on,
repeated for as long as the mistake stood. The deploy is where the decision gets
made, so the deploy is the only place it runs.

**A deployment says which role by setting `XIVI_PUBLIC_ROLE`, and empty is a real
answer** — the same shape `XIVI_TRUSTED_DOMAINS` and [XIV-126]'s ping list have.
An installation served entirely by the internal image has one database user and
nothing to compare, and a check that stopped those deploys would be a check
somebody appends `|| true` to within the week. The cost is that a split
deployment has to say so once; the alternative is guessing the role name, and a
check that silently audits a role nobody uses passes for ever while proving
nothing.

#### The schema has exactly one owner

A consequence of the grants, and the one operational change worth knowing.

The container entrypoint has always run the control-plane migrations on start, so
that a container can never serve against a schema older than itself (§4.2). The
customer-facing image cannot: its role has no DDL. So it **asks** instead —
`doctrine:migrations:up-to-date`, which is a `SELECT` on the one administration
table it is granted — and **refuses to start** if the answer is no.

Fatal rather than advisory, which puts it beside `deploy:check-secrets` rather
than beside `deploy:check-hosts`, and the asymmetry is the one those two already
draw: a schema behind the code is a property of the *instance*, so every customer
it would serve is served by code expecting columns that are not there. It is not
a race with the deploy, because `bin/deploy` moves the schema before the serving
containers are replaced; and it does not refuse a rollback, because
`--fail-on-unregistered` is deliberately not passed and a schema *ahead* of the
image is exactly what going backwards looks like under §4.2's additive-only rule.

`bin/deploy` itself refuses to run out of the customer-facing image, with a
message naming the internal one. It would fail anyway, on a permission error,
partway through — and "it cannot work" is not a good enough reason to let a
deploy start.

Each of these tests the *package's presence on disk* rather than an environment
variable, and that is the same choice the bundle seam makes — in `App\Kernel`
since [XIV-111], in `config/bundles.php` before it: a flag says what somebody
configured, a directory says what is in the image, and two builds cannot
disagree with a directory.

#### What the customer-facing image does still contain, said plainly

"Does not contain the administration surface" is a claim, and a claim is worth
bounding. The `frankenphp_public_builder` stage refuses to finish if
`Xivi\ControlPlane` survives in the sources, in the autoloader or in the compiled
container, so what follows is the complete remainder:

- **`migrations/control/`**, including the migrations that create `operator`,
  `signup_request` and — since [XIV-102] — `purchase_intent`. Those are the
  application's and must not move (§3.1): the namespace is recorded in
  `doctrine_migration_versions` and no table moved when the classes did. They are
  DDL rather than administration logic, the entrypoint does not run them here, and
  the database user could not.
- **Two `access_control` rules** mentioning `^/control`, for the reason above.
- **`composer.lock`**, which names `xivi/control-plane` because both images are
  built from one lock file. That is the property that stops the two builds from
  drifting and it is worth more than the tidiness of a second manifest.
- **`config/bundles.php` and `config/optional_bundles.php`**, which name the
  bundle class — the first unconditionally, the second to say it may be absent
  (§4.4, [XIV-111]). Two lines of PHP naming a class that is not there, read by a
  kernel that skips it. Nothing is loadable and nothing compiled from them
  mentions it: the `.kernel.bundles_definition` parameter is filtered by the same
  method that does the skipping, which is why this image's `var/cache/` still
  passes the grep that refuses the build.
- **`packages/xivi-mate/`**, whose source mentions the namespace in two files.
  That is a development dependency, is not installed into `vendor/` by
  `composer install --no-dev`, and is in the internal image for the same reason —
  the source tree is copied wholesale. It predates this ticket and is not made
  worse by it, and it is written down here rather than left for somebody to find
  with a grep.

Everything else — the operator entity and its firewall, provisioning,
deprovisioning, the tenant list, usage collection, secret rotation, the signup
intake and its landing page, every `control:*` and most `tenant:*` commands — is
absent from the filesystem, from the autoloader and from the compiled container.
The image's `security.firewalls` parameter is `["dev","main"]` and its router has
no route under `/control` and no signup route at all.

#### One suite, one stack, two builds

`bin/ci` runs a single PHPUnit suite over both halves, exactly as §3.1 said, and
the dev stack is still one `bin/compose up` against the complete image. What
changed is that `bin/ci` builds both production targets, in that order, because
the customer-facing one is assembled from the internal one's builder stage.

The reason the second build is not optional is that the failure it catches is
invisible to everything else here: one `user_checker:` added to the application's
`security.yaml` because that is where the other firewall's is, and the container
stops compiling without the package. The unit test above catches most of that in
a second by reading the configuration; only the build compiles a container with
the package genuinely gone.

### 4.5 Nothing noticed when a scheduled job stopped (XIV-126)

Three commands have to be on a schedule for this installation to behave the way
its screens claim: `signup:provision`, `tenant:purchase:collect` and
`tenant:usage:collect`. They are cron entries rather than workers because §8.7
settled that a long time ago and for the whole runtime — FrankenPHP in classic
mode with no worker block on purpose, so nothing in this deployment runs between
requests and a queue with nothing draining it is worse than a slow request.

**Every screen built on those jobs is honest about staleness, and that is not
monitoring.** A usage figure carries the moment it was taken; a customer nobody
has collected yet reads *not collected yet* rather than a zero (§8.11); [XIV-102]
refuses to draw a stale purchase request as current. All of that tells whoever
looks. **Nothing makes anybody look.**

[XIV-108] is the sharpest illustration and was filed as something else. A
customer who confirmed a signup and then heard nothing is *precisely* what a
stopped `signup:provision` produces — the intake row is fine, the confirmation
was recorded, the mail is not late, it is not coming. That was written up as a
messaging problem, and half of it is this.

It had already happened in the smallest possible way, too. `tenant:purchase:collect`
shipped with [XIV-102] and reached **no** list of cron entries: not the
documentation site's page, which said "the two cron entries an installation
needs", and therefore not anybody's crontab. A job nobody scheduled cannot be
observed to have stopped, because it never started, and there is no state
anywhere that differs from a healthy installation nobody has asked to buy
anything from.

#### Rejected: an internal checker, which is what v1 had

The previous generation of this product had a `BatchChecker`. Every job wrote a
`<name>_lastrun` setting, and a daily task read them all and mailed the
administrator about any that had not run in twenty-four hours. It is the obvious
design, it needs no third party, and **it is rejected here permanently** so that
it is not proposed again.

The flaw is the shape rather than the implementation, and no amount of care
inside it helps. **The checker is itself a scheduled job**, so the failure it
exists to catch — cron stopped, the container is not being restarted, the machine
is off, the deploy dropped the crontab — is the failure that stops *it*. A dead
man's switch that dies with the patient reports nothing, and reports it silently:
the mailbox that would have received the warning simply stays empty, which is
what it looks like when everything is fine.

Two lesser objections, worth recording because they survive even if somebody
thinks they can solve the first. It would need a place to write `lastrun`, and
the only database every job can reach is the control plane, so a monitoring
concern would acquire a schema. And it would mail from this installation's own
transport — the one whose failure is one of the things being monitored (§8.7).

#### The shape that works: the job pings, the service alerts

An external monitor inverts the dependency. The job makes an HTTP request when it
runs; the **service** raises an alarm when a request does not arrive. Nothing on
this machine has to survive for the alarm to go off, because *the alarm is the
absence of us* — which is the one signal a stopped cron, a full disk, a dead
container, a botched deploy and an unplugged server all produce identically.

That is the entire architectural argument, and it is why this is a small
integration rather than a subsystem.

#### The four candidates

| | Self-hostable | Licence | Protocol | Cost |
| --- | --- | --- | --- | --- |
| **Healthchecks** | **Yes** — Django, Postgres, official image | **BSD-3-Clause** | `GET <url>`, `<url>/start`, `<url>/fail`, **`<url>/<0–255>`** | Self-hosted free; hosted free to 20 checks, $20/mo to 100 |
| **Better Stack** | No | Proprietary | `GET <url>`, `<url>/start`, `<url>/fail`, `<url>/<exit code>` | 10 heartbeats free, then about $17/mo per 10 |
| **Oh Dear** | No | Proprietary | `GET <url>`, `<url>/started`, `<url>/failed`; the exit code is a POST field | From $17/mo, priced per *site* rather than per check |
| **Cronitor** | No | Proprietary | `GET …/p/<key>/<monitor>?state=run\|complete\|fail&status_code=N` | Free tier, then per monitor |

Read the protocol column rather than the price column. **Healthchecks and Better
Stack speak the same thing byte for byte**; Oh Dear spells the same three ideas
with different words and puts the exit code somewhere a `GET` cannot carry it;
Cronitor is a different shape entirely, query parameters rather than path
segments.

**Recommended and implemented against: Healthchecks**, for three reasons in this
order.

1. **It can be run by the person it is monitoring.** [XIV-115] refused to make a
   paid third-party service a requirement for storage, and the same instinct is
   stronger here, not weaker: an installation whose ability to know its own cron
   is alive depends on somebody else's billing relationship has swapped one
   silent failure for another. A container beside this one is a perfectly good
   deployment of it, and the hosted service remains available for whoever would
   rather not.
2. **BSD-3-Clause**, which is the licence class this project already accepts —
   `twig/twig` and `league/commonmark` are on the same terms — so self-hosting it
   raises no question this repository has not already answered.
3. **It records the exit status as a number.** `/ping/<uuid>/<0–255>` is read as
   success at 0 and failure otherwise, *and the number is kept and shown*. That
   is the one property that makes §4.2's three exit codes survive the trip, and
   the next part of this section is entirely about why that matters.

**What is implemented is the protocol, not the vendor.** Nothing in the code
names Healthchecks: `XIVI_MONITOR_PINGS` takes whatever URL a service issued, and
Better Stack's heartbeats work unchanged. Oh Dear and Cronitor will receive the
success ping and nothing else useful, and the honest thing is to say so here
rather than to build an abstraction over four dialects — a configurable suffix
vocabulary would be a knob with two correct settings, both undocumented, and the
project's taste is to decide instead.

#### The exit code is the payload

`tenant:migrate` publishes three codes on purpose (§4.2): **0** every tenant is
current, **1** the run could not happen at all, **3** the run happened and at
least one tenant is behind while the rest are fine. A monitor told only "it
failed" flattens 1 and 3 into each other — a deploy that did nothing, and a
deploy that left four customers on last week's schema while the new code serves
them. Those wake different people and require different actions.

So the outcome is reported as `<url>/<exit code>` rather than as `<url>/fail`.
The monitor treats 3 as a failure, which is right, **and shows *3***, which is
what somebody woken by it needs before they open a terminal. The collectors
publish 0 and 1 today and read identically.

And there is a fourth state, which is the whole point of the arrangement:
**nothing at all**. A job that was never scheduled, whose cron died, whose
container was replaced without its crontab, or whose machine is off sends
nothing, and the service raises that after its grace period. Silence is the
alert — the property the rejected checker could never have.

The start ping is sent as well, and buys two things the completion ping cannot.
It gives the run a *duration*, so "the collection now takes eleven minutes" is
visible before it becomes "the collection did not finish"; and it separates a job
that started and was killed — an OOM, a machine that went away — from one that
never started at all, because the first leaves a start with no end and the second
leaves nothing.

#### What a ping contains, and what it deliberately does not

The fact that it happened, and a number. A `GET`, no body, no query string.

**No tenant slug, no customer name, no email address, no counts, no hostname, no
version.** A ping URL goes to a third party, possibly a hosted one, and §8.11's
line — *counts, not contents* — is drawn a great deal further back here:
`tenant:usage:collect` holds every customer's slug and every one of their figures
by the time it terminates, and *"the job ran"* is the entire payload. The
`User-Agent` is the bare word `Xivi`, so that an operator reading the request log
of their **own** self-hosted instance can tell what pinged it; a version there
would turn every ping into a report of which release this installation is behind
on, sent to whoever runs the monitor.

What cannot be hidden is the source address, because a monitor by construction
receives a request from you. An installation for which that matters self-hosts,
which is the first reason Healthchecks is the recommendation.

The reverse direction is worth stating too: **a ping URL is a bearer token in URL
form.** Anybody holding one can report that the job succeeded, which is exactly
how somebody would silence this. So `deploy:crontab` prints *watched* and never
prints the address, because a crontab is world-readable on most machines.

#### Optional, and off by default

`XIVI_MONITOR_PINGS` is empty in the committed `.env`, and empty means **no pings
and no behaviour change of any kind** — the shape [XIV-93]'s trusted domains and
[XIV-61]'s secret guard already have. `App\Monitoring\JobMonitor` returns on an
array lookup before it constructs a URL, so an installation that configures
nothing never touches the HTTP client, never opens a socket and never pays a
timeout. That is asserted rather than claimed: `JobMonitorTest` checks that *no
request is made at all*.

A **failed ping never fails the job.** It is logged at warning and changes
nothing — not the exit code, not the output. Swallowing an error is usually wrong
in this codebase, and §8.7 is emphatic that a failed mail send is never
swallowed; the difference is that the consequence of a lost ping is *a monitor
reporting a missing ping*, so the failure announces itself at the far end. The
opposite policy is the harmful one: a monitoring feature that can turn a
five-second network problem at a third party into a failed deploy is a monitoring
feature somebody removes.

A **malformed entry is refused rather than skipped**, at the first console
command run after it is set. A skipped entry is a job nobody is watching on an
installation whose operator believes they configured watching, which is this
section's own failure mode wearing a defensive `continue`. Refused: no `=`, an
empty half, a scheme that is not HTTP, a duplicate command, and a URL with a
query string or a fragment — the last because the outcome is reported by
appending a path segment, and appending one to `…?key=abc` addresses something
nobody meant.

#### One place, and a list that is in the build

The pings are sent by **one console event listener**,
`App\Monitoring\EventListener\JobMonitorSubscriber`, on `ConsoleEvents::COMMAND`
and `ConsoleEvents::TERMINATE`. Three lines at the top and bottom of each command
was the obvious alternative and is wrong for the reason this whole ticket
exists: **the fourth scheduled command would not have them, and nothing would say
so.** Watching the ninth command is adding an entry to `XIVI_MONITOR_PINGS`;
nothing is edited and nothing is remembered.

`TERMINATE` rather than the value a command returns, because it fires for every
ending a command has — a returned code, an uncaught throwable, a command an
earlier listener disabled — and the one ending it does *not* fire for is the
process being killed outright, which is exactly the case that should produce a
start with no end. The listener reads `getExitCode()` and never writes it: §4.2's
codes are a published contract and a monitoring listener is not a party to it.

The map may name any command, not only the three. `tenant:migrate` is not a cron
entry — `bin/deploy` runs it once per release — and a deploy that quietly stopped
being run is the same class of silence, so restricting the map would have refused
that for a tidiness nobody asked for.

**What jobs exist is a property of the build, not of the deployment.**
`App\Monitoring\ScheduledJobs` is that list, in code, with each entry carrying its
suggested cadence and the sentence that says *what is wrong with this
installation while it is not running*. A deployment decides how often and whether
to watch; it does not get to decide that `signup:provision` is optional. Same
argument §4.2 makes for `bin/deploy` being a file in this repository: the sequence
a release needs ships with that release and can never be a version behind.

`deploy:crontab` prints it — the cron lines, and beside each one whether anything
is watching it. Everything on stdout is a crontab, comments included, so it can
be redirected into `/etc/cron.d/xivi` rather than retyped. It exits **0** when
every job this image has is watched *or* when none is (monitoring switched off is
a choice, and a check that fails on a fresh installation is a check that ends up
being run with `|| true`), and **3** for the inconsistent state — some watched and
some not, or a watched name that is not a command here. Three rather than two for
`tenant:migrate`'s reason. The customer-facing image prints an empty crontab and
says why, because all three of today's jobs are control-plane commands and §4.4
compiles them out; the list holds command *names* rather than class names
precisely so that it costs that image nothing.

#### Rejected: symfony/scheduler

Asked and answered by §8.7 rather than by a preference. `symfony/scheduler`
dispatches through Messenger and needs a consumer process — `messenger:consume` —
which is the thing this runtime does not have. It would move the schedule into
the repository, which is genuinely attractive, and pay for it by making *the
worker* the single point whose stopping nobody notices. That is the internal
checker again with more moving parts. When there is a supervised consumer for a
reason of its own, moving all of this onto it is a small change and this section
should be revisited.

#### Uptime checks and status pages are out of scope, and that is decided

Every service above also does uptime checks, and three of them do status pages.
It is one purchase, one integration and an obvious adjacency, which is exactly
why it is worth refusing deliberately rather than drifting into.

**Uptime checking needs nothing from this repository.** It is an HTTP GET against
a hostname, configured entirely at the monitoring service, and Xivi could not
make it better by knowing about it. An operator who wants it switches it on
there. Nothing here is required and nothing here is in the way.

**A status page is a different product decision and a much larger one.** Xivi has
no way to tell customers it is down — [XIV-120] is announcements, which are
authored in advance by an operator for a working installation, and an incident is
the opposite of that on both counts. Doing it properly asks who publishes, in
which language (§8.4.2 gives every user their own), whether one customer's
outage is every customer's business given that §4 is a database each, and where
the page is served from — because a status page hosted inside the thing it
reports on is the internal checker one more time. None of that is settled by
buying a monitoring subscription, and a monitoring ticket is not where it should
be settled by accident.

#### What this still does not do

Said plainly, because a monitoring section that overstates itself is worse than
none.

**A job can still stop without anybody finding out, in exactly one way: nobody
configured a check for it.** `XIVI_MONITOR_PINGS` empty is a supported state and
the shipped default, and an installation left that way is no better off than it
was — it is only easier to fix. What has changed is that the gap is now *visible*
rather than invisible: `deploy:crontab` says how many of the jobs are watched,
names the ones that are not, and exits 3 when the answer is "some" — which is the
state an operator who set this up once and later shipped a fourth job lands in,
and the state that otherwise looks exactly like being covered.

Nothing here schedules anything either. The crontab is still an operator's file
on an operator's machine, and this repository can print it, not install it.

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

**The stylesheet it brings, and why only one of four** (XIV-36, moved here by
XIV-143). Tom Select is what the autocomplete controller attaches to a select,
and it arrives with two JavaScript dependencies and a *choice* of four
stylesheets — default, Bootstrap 4, Bootstrap 5, and a bare one. This
application can use exactly one, and which one is settled by
`assets/controllers.json`; the other three would be downloaded into
`assets/vendor/` and served to nobody.

**That decision used to be a comment in `importmap.php`, which is the wrong file
to keep it in.** Flex regenerates that file when a package is added, and it has
already dropped the comment twice — during XIV-103 and again during XIV-126 —
each time caught by somebody reading a diff rather than by anything that would
notice reliably. An absence that looks like an oversight is one somebody
helpfully corrects, so the reasoning lives here, where nothing rewrites it. The
same argument XIV-111 makes about `config/bundles.php`, one file along.

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
constraint plus per-field constraints, including a custom unique-field constraint
— which since XIV-109 is the *readable* half of uniqueness rather than the
enforcing one, sitting in front of a real unique index on the column (§7.2).

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
type. A *unit* belongs to the article rather than to the line, and §5.20 is where
it now lives — for four tickets this paragraph said it was "deliberately absent"
and pointed at an article module that had no unit either, so a line read `2.5` of
nothing and the sentence described a place that did not exist (XIV-118).

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

#### Counting the rows before the form is built (XIV-90)

**A cap you can only enforce by first doing the thing it forbids is not a cap.**
`RecordWriter::save()` is the right place for it and remains the place: it is the
one door the form, the importer and everything future go through. But the form
path reaches that door *through the form* — the values arrive as a submission,
and a submission of four hundred and one rows had to be built as four hundred and
one row forms before anything could count them. The Live Component builds the
whole thing more than once per action besides: the real form, plus the throwaway
one `RecordForm::asSubmitted()` uses to turn view values back into model values
(XIV-32).

Measured on this branch by `testWhatAnOverLongSubmissionCosts()`, an order of
article lines posted to the component's `save` action, `APP_DEBUG=0`, per
request:

| submitted rows | peak MB | ms | status | |
| ---: | ---: | ---: | ---: | --- |
| 400 | 250.2 | 4 383 | 204 | saved |
| **401, before** | **273.3** | 6 282 | 200 | refused, *after* building it |
| 401, after | **1.9** | 31 | 200 | refused before building it |

**273 MB against the 256M a request is allowed** — so the refusal could not be
rendered, and what a hand-crafted over-long post actually produced was the
"Allowed memory size exhausted" out of the middle of Twig that the cap exists to
remove. The fix takes it to 1.9 MB and 31 ms.

**Two things in that table are worth reading beyond this ticket.** The first is
that 400 rows *submitted* costs 250 MB where 400 rows *drawn* costs 140 — the
submission pays for the form twice and the write once, and it sits at 98% of the
allowance rather than the 55% the render sits at. The headroom the cap was chosen
with is real for the page and thin for the post. The second is that the "before"
figure is 273 and not the 280 a doubling predicts, because the throwaway form and
the real one overlap rather than stack cleanly; the shape of the argument is
unchanged and the arithmetic in the ticket was close enough to act on.

**The 98% is accepted, deliberately, and this is the record of that** (decided
2026-08-18). Four hundred stays the cap and 256M stays the limit, knowing that a
save at exactly the supported size uses almost all of its allowance. The argument
is that the number is not the risk it looks like: a real order is well under a
hundred lines, so the case sitting at 98% is a document nobody writes, reached
deliberately by somebody adding four hundred rows. The failure if it is wrong is
also the mild one — a save refuses with a sentence, which is what XIV-90 built,
rather than the half-rendered exhaustion the cap was introduced to remove.

What is *not* accepted is being surprised by it later. Two things follow. **The
per-row constant on the post path is about 0.62 MB, not the 0.35 the render pays**
— roughly double, because the submission builds the form twice and writes once —
so anything that grows a row grows the post path twice as fast, and it is that
figure a future change has to be held against. And **the next move, if this ever
does bite, is the limit rather than the cap**: four hundred rows is a promise made
to customers, while `memory_limit` is a line in an ini file, and the two are not
equally expensive to change.

**Where the count comes from.** `Core\Record\SubmittedRows` reads the submitted
values while they are still the plain array the browser sent — no `FormView`
anywhere — and hands the number to the same `CollectionLimit` the writer asks. It
is a second caller, not a second rule: same number, same sentence, same
placeholders, so a reader cannot tell which layer caught them and does not need
to. **The writer's guard did not move and must not**, which is what keeps the
limit true for the importer, the demo generator and whatever calls it next; the
test proving the writer refuses on its own passes untouched.

**Both post shapes are counted, and they are not the same shape.** A Live
Component action replaces the whole model at once —
`updated: {"module_record": {"collections": {"lines": [...]}}}` — while an
ordinary form post sends one entry per control, keyed by the path its `name`
attribute makes, because the component puts `data-model` on the `<form>` element:
`updated: {"module_record.collections.lines.0.fields.text": "…"}`. On top of
either sits whatever the signed `props` already held, which is where the rows of a
record being edited come from. The library reconciles all three onto the
component's raw values before any form exists, so the count is taken there — one
reading rather than a second parser that would eventually disagree with the first.

**The refused page draws the record as it stands**, not the submission. There is
nothing else it honestly can draw: the submission is the thing that will not fit,
and truncating it to four hundred would be the document-that-lies this section
already refuses. The submitted values themselves are left exactly as they arrived
and go back out in the component's props untouched — emptying them would be the
far worse bug, because the next save would then write the shortened list over the
record.

**A submission that cannot be counted is refused as itself.** Nothing a browser
sends can put a string where a collection's rows belong, so what arrives that way
was written by hand and there is no honest number to name in a refusal. It gets a
sentence of its own — `record.submission_unreadable`, which names no limit and no
count — because a message saying "this holds at most 400 rows and this one has 0"
would be a number somebody invented.

**What is still open.** Reaching the cap through the interface takes four hundred
"add row" round trips, and the four hundred and first is not refused: the button
appends and the re-render then costs what a 401-row submission costs. Nobody
arrives there by accident and this ticket did not close it, because refusing an
add needs a sentence of its own — "nothing was saved" is the wrong thing to say
about a row that was never added — and that is a decision rather than a line of
code.

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

**Because it stores the values, it is also a time series** (XIV-121), and that
is worth stating here rather than being rediscovered. The diff holds `from` and
`to` and not merely the fact that something moved; the `created` entry records
every field the record was born with as a change from null; `RecordWriter` is the
only supported way to write a record, so there is no path that writes a value and
no entry; and nothing prunes the table. Put together, the chain of values for any
one field is unbroken from a record's creation to the moment it is read — so
"what was this article's price in March" is a question this table already
answers, and a second table holding a price series would be a copy of facts
already recorded, kept in step by hand, with two answers the first time it was
not. §8.3.1 is where that gets drawn as a line; `HistoryRepository` grew one
extra read for it, selecting only the entries with a `fields` branch and
returning them oldest first, because a trend is the opposite shape from a
timeline: the whole life of one value, with the *old* end carrying the
information.

Still to decide: retention and whether `occurred_at` wants range partitioning.
Cheap now, expensive at 60M rows. And field types will need a way to say "do not
record this value" before the first sensitive type ships.

**Retention has a second consumer now**, which changes what deciding it means.
While this table was only a timeline, dropping entries older than some horizon
would have cost a page nobody scrolls to the end of. Since XIV-121 it is also
where a chart comes from, and pruning the far past is precisely pruning the half
of a trend that carries the shape. Whatever retention turns out to be, it has to
be a decision made in front of that, not one made about a log.

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

Deliberately **never** built, which is a different word: a filter somebody writes
as an *expression* rather than as a condition (XIV-88, §5.8). It would have to be
evaluated in PHP over records that are already loaded, so it could not narrow the
page it is meant to narrow, and the count beside it least of all.

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
  because it cannot invalidate anything. **The unique half also names the shared
  values** (XIV-109), because a count on its own is not something anybody can act
  on: four records among six hundred, with nothing to search for. The values are
  the search terms. And the flag no longer only decides what a validator checks —
  ticking it builds a unique index on the column and unticking it drops one, in
  the same transaction as the definition row; see §7.2 for why that had to change
  and what it means for a save that loses a race.
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

That was a patch over the real shape, which is that a **type** says which of its
options are the customer's to set — the same way it already owns its validation,
its storage and its widget — so the editor can draw the right controls per type
instead of three fixed ones.

**That shape now exists** (XIV-36, then XIV-27). Autocomplete came first, as one
option behind one `instanceof`, and said in as many words that generalising an
interface from a single example would be guessing. Numbering was the second, and
two is the number at which the general form can be written from evidence: the
editor holds **one declared list of option to capability interface** —
`autocomplete` to `Autocompletes`, `sequence` to `Numbers` — and resolves it once
against the registry. A third option is a marker interface, a line in that list
and a control in the template, rather than another branch through the controller.

What stays per option, deliberately, is *drawing* it. A select of three fixed
answers and a numbering pattern with a live preview and a counter beside it have
nothing in common except the question "may this type have one"; generalising the
control as well would mean inventing a widget-description language to save two
`{% if %}`s, which is the speculative generalisation §1 warns about wearing a
different hat.

The rest of the rule is unchanged and is what makes the list safe: a control the
editor draws is **named on every save, cleared when blank**, and a type that does
not offer it is not named at all — so a `text` field's save says nothing about
autocomplete and could not clear one even if something had put it there. A
setting a form does not mention is one it can neither wipe nor invent.

#### The two the editor offered and could not configure (XIV-144)

The list above had three entries and the add-field select had every registered
type in it, and nothing compared the two. So a customer could add a **`choice`
field and never be offered its `choices`**, and a **`reference` field and never
be offered its target module** — both on this section's own "the form must not
touch these" list, neither excluded from the select.

Nothing failed. `ChoiceFieldType::constraints()` skips its `Assert\Choice` when
the option list is empty, on the argument that an empty list would otherwise
reject the empty value too and "misconfigured" is not what a record should be
told; so the field validated everything, offered nothing, and said so nowhere. A
reference with no target rendered `#41` where a name belongs. **A control that
appears to work and does nothing** is exactly what §8.3.1 exists to prevent, and
it had been sitting in the middle of the editor since the editor was built,
written down twice as an honest limit (§5.20, §5.22) and diagnosed neither time.

**The fix is the fourth and fifth entries in that list, and one new idea.** The
three that were there describe an option a field *may* have, and every one has a
good answer for a field that says nothing: decide the search box from the count,
do not number this field, follow the installation's country. These two have
none. So a capability may now say that it is **not optional** — `NeedsAnAnswer`,
which `Enumerates` and `PointsAtAModule` extend, and whose one method is the list
of options a field of that type is not finished without.

Two things read it, in different layers, and the split is the design:

- **the editor will not offer a type it cannot ask the question for.** The
  add-field select is built from the types whose every need this form draws a
  control for, rather than from the registry. Today that is every type and the
  two lists are the same list again; the day somebody writes a type needing
  something nobody has built a control for, it is absent from the select instead
  of being offered broken.
- **the engine will not write a definition that leaves one unanswered**, which
  holds for the importer, the console and the form posted around the page — the
  same division `assertNumbersSomething()` already made, for the same reason.

**A test over the registry is the part that stops this recurring**, and it is
deliberately not a test about `choice` and `reference`. The defect was never that
two types were forgotten; it was that nothing anywhere compared what a type needs
with what the editor can ask, so they drifted apart in silence and the twelfth
type would have drifted the same way. `EditorConfiguresEveryTypeTest` walks the
container's own registry and asserts the comparison, and — because an invariant
nobody has watched fail is an invariant nobody knows is connected to anything,
which is what deptrac taught this project in XIV-60 — it also plants the
violation: a type declaring a need no control exists for, refused by the same
function the select is built from.

##### Removing an option that records hold: refused, and [XIV-127] follows this

A list is the first of these settings that somebody **edits** rather than
answers, and editing has a direction the others do not: taking an entry away.

The decision is **refuse, with the values named and counted**, and it is the same
decision as making a field unique, reached the same way. Removing an option a
record holds destroys nothing — the value stays in the JSON and `display()` falls
back to printing it raw — but it leaves that record failing its own field's
validation, so the next person to open it and press Save is told their record is
invalid for a reason that has nothing to do with what they were doing. That is
the trap this section already refuses in general terms. Rewriting the affected
records to some other option is data loss on a click; leaving them broken is the
trap; so the answer is no, with enough in the sentence to make it a yes next
time. The options page prints the count beside every option, because a rule
somebody meets as a refusal is a rule they learn one failure at a time.

**Retiring an option — keeping it valid for the records that have it and taking
it out of the picker — is the genuinely better answer for the customer who has
stopped selling by the pallet and has four hundred old orders that were, and it
is deliberately not built.** It is a third state per option, every reader of
`choices` has to understand it, and it is the same question [XIV-127] has to
answer for a shared list. Building it here would be building a third of that
feature early and unbuilding it later, which is precisely the argument §5.20 used
to keep units out of a table of their own.

**[XIV-127] must follow this answer rather than inventing its own.** It proposes
lists a customer maintains once and several fields across several modules point
at — "our units", "our topics", "our payment terms" — and the removal question
there is this question with more records behind it. A shared list that quietly
lost an entry would break records in modules the person removing it was not
looking at. So: **a list somebody's records point into cannot lose an entry while
they point into it, whether the list lives in the field or beside it**, and if
[XIV-127] wants a friendlier answer than a refusal, the friendlier answer is
retirement and it has to arrive for both at once.

##### A module's own field's options may be added to and never taken from

This section's oldest rule, one level down. A module's own **fields** cannot be
removed because the module's code is written against them; a module's own
`choice` field's **options** are that same code's expectations written into the
definition — an order's `status` list is the states its lifecycle moves records
between, a contact's `kind` list is the variants the module ships forms for.
Either one losing an entry breaks the module rather than the record, and from a
table cell.

So on a module's own field: **add and rename, never remove.** The half that
matters is the first: the wholesaler who wants "pallet" beside the seven shipped
units (§5.20) and the workshop that wants "machine" beside the six shipped topics
(§5.22) are the two customers this whole change was written for, and both of them
are adding. Renaming is free by construction — see below.

**Adding to one has consequences worth naming**, and they are the engine working
as designed rather than a hole in this rule. The variants of a shape *are* its
variant field's options (§5.5) — "no second list to keep in step" — so a customer
who adds an option to Contact's `kind` has added a third kind of contact, which
appears in the picker, the filter bar and the record form with the module's own
two. A state added to an order's `status` is a state the lifecycle has no
transition into or out of, so records can be filtered by it and nothing can move
them there. Both are legible, neither breaks anything, and both are what the same
customer would have got by adding their own `choice` field — which is the test
this rule is really applying.

The refusal is blunt on purpose: *any* removal, not only of the options the
module happens to name. The definition records which **fields** came with the
module and does not record which **options** did, so there is no way to tell a
customer's own eighth unit from the seven the installer wrote. Refusing all of
them costs somebody a dead entry in a dropdown they added by mistake; allowing
all of them costs somebody their order lifecycle. Provenance per option is
[XIV-127]'s to model, and it is the right place for it.

##### The value is derived from the label, once

What every record holds is a **key**; what the page shows is a **label the
customer may rename**. That split already existed (§5.20) and had never been
exposed to anybody, because until now the only way to write an option was a
module's installer.

The editor asks for labels and derives the key from the first one it is given,
then never touches it again: "Pallet" becomes `pallet`, and renaming the option
to "Palette (EUR)" afterwards changes what the page says and moves no record.
Asking for the key as well would mean asking somebody who wants a seventh unit to
understand a distinction that only matters when it is too late to change it. The
derivation is `AsciiSlugger` pinned to `de`, with the argument [XIV-100] makes at
length about the self-service slug: the value is permanent and the language
somebody happened to have the page open in is not.

The trade is that a **typo in a label is permanent in the key**. It is the right
trade — nobody but an export column ever sees a key, the label is fixable in the
editor, and the alternative is a rename that silently orphans records.

##### A reference's target: refused once anything points through it

An id is only meaningful in the module it was chosen from. Repointing a populated
reference leaves every stored id addressing a row that is either somebody else's
record or nothing at all, and **nothing would report it**: the ids are valid
integers and every page would carry on naming records, the wrong ones. That is
the quietest failure in this section, so it is the one refused with a count
rather than warned about — the field may be repointed while it is empty, and not
once records point through it. A module's own reference is refused outright, on
the rule above: its target is what the module's own forms, documents and totals
expect.

Two smaller decisions go with it. The target must name a module **this customer
has installed**, checked on the write path rather than in the select, because a
target that is not installed is exactly as broken as no target. And moving a
target **clears the `variant`** beside it — a variant is a value of the old
module's variant field and narrows nothing in the new one, which would be an
empty picker arrived at from the other direction. The variant itself still has no
control; a reference that says nothing about it offers every record of its target
module, which makes it an optional setting of the ordinary kind.

##### Where the controls are, and what is still missing

The target is a `<select>` in the field table's row and in the add form, on the
same terms as the country and the search box. The **list is a page of its own**,
for numbering's reason: it is a row per option, a rename that must not move a
record and a removal that may be refused with a paragraph, and in a table cell
the change with the most consequences would look like the cheapest one on the
row. The add form asks for the list in a textarea, one label per line, because
the engine will not write a choice field without one — the question has to be
asked before the field exists.

Deliberately **not** in this:

- **retiring an option**, argued above, and with it any way to remove an option
  that history holds;
- **repairing an unfinished field** other than by pointing it somewhere or giving
  it options — a `choice` field with no list that predates this rule is *marked*
  in the editor and is otherwise left exactly as it was, because nothing new can
  reach that state;
- **the `variant` narrowing**, which still has no control and is still cleared
  rather than migrated when a target moves;
- **provenance per option**, which is what would let a module's own field give up
  an option the customer added to it;
- **changing a field's type**, which is §7.2's open half and is not made any
  easier by any of this.

#### Sections: a form of twenty-five you can read (XIV-119)

A field carried `position` and `width` and nothing else, so a module's form was
**one flat run of inputs**, however many there were. That is fine for Contact's
eight. It stops being fine the moment somebody does the thing this product exists
to let them do: a contact with billing details, delivery details, three custom
references and six fields of their own is twenty-five inputs in one column, and
nobody can find anything. The order module's own form got busier the day before
this was written (XIV-122), which is the same argument arriving without being
asked for.

A **section** is a heading and the fields under it — *Contact details*,
*Billing*, *Notes* — with the fields keeping their order and their width inside
it.

##### A section is not a collection, and that has to be said out loud

The two will look similar to whoever arrives next, and they are not similar at
all. §5.1's **collection** is a second way of grouping *records*: it has a table,
rows, field definitions of its own, a foreign key back to the record that owns
it, its own validation and its own history. A **section** has a word and a
number. A field in a section is the same field, in the same record, under the
same key of the same JSON payload; it is stored the same way, validated by the
same rules, found by the same filter, and named by the same document marker —
§5.7 addresses fields by key and has never heard of any of this. Only the form
and the record page draw it differently.

**Everything below follows from keeping that true**, and the moment any of it
stops being true a section has quietly become a second collection, which is a
feature this product already has and does not need twice.

That is why a section is **a value rather than a row**: it has no table, no id
and nothing that can point at it but a string on a field, so there is nothing for
a query to join to. Giving it a table would be handing somebody the join, and the
first join is the moment it stops being presentation. It is also why the grouping
never enters the **form tree**. Symfony's own way to group controls is
`inherit_data`, and it would work — but it moves the grouping into the form,
which is where the submitted array is shaped, where the `data-model` paths are
built, and where `RecordSubmission::mapViolations()` finds a field by key among
the direct children of `fields`. A presentation decision able to reach any of
those is no longer only a presentation decision. So the form tree stays flat and
the *template* draws the runs, which is Symfony's other answer to this and the
right one here.

##### Where a section lives: the membership on the field, the section on the module

The **membership is a property of the field** — `field_definition.section_key`,
null for none. A container holding a list of fields was rejected: a field already
carries its own order and its own width, so a container would be a second place
deciding the same thing, free to disagree with the first. Naming it from the
field also means an ungrouped field is simply one that names none, which is every
field in every tenant on the day this arrived — so the migration is two nullable
columns, no backfill, and every existing definition is untouched by construction
rather than by care.

The **section itself lives on the module** — `shape_definition.sections`, a JSON
map of key to label and position — because a section has to be able to exist
while it is still empty, and neither its name nor its order can be stored on a
field that is not in it yet. On the row rather than in a table of its own is
`followUpsEnabled`'s argument unchanged: "what this customer has, and how it is
set up" is one question with one answer, and the lifetime comes free — uninstalling
a module takes its headings with it.

**Not on `ShapeDefinition`**, and this is the one place the feature is narrower
than the editor. A collection's fields are drawn as a row inside the form and as
a row of a *table* on the record page, and a table row has nowhere to put a
heading. A section offered on a collection would be a control that did nothing on
half the pages it appeared on, which is precisely the defect XIV-144 is named
after — so the select is drawn on the module's own shape only, and the engine
refuses a section on anything else for the request that arrives without a page.

##### Ordering is a number the customer sets

Not the position of its first field, and the deciding case is the empty one: a
section is empty for exactly as long as it takes somebody to make one and then
move a field into it, and a heading that vanished between those two clicks would
be a control that appeared not to work. So a section carries a `position` exactly
as a field does, in tens, on the same numeric control — type 15 to put something
between. Inferring it would also mean that reordering a *field* silently
reordered a *section*, which is the same accident §5.4 already refused when a
record's name was guessed from field order.

**The ungrouped fields are drawn first**, before any section, under no heading.
That is the decision that costs an existing customer nothing: every field in
every tenant is ungrouped, so a shape with no sections yields one run holding
every field in its own order — the flat run that has always been drawn — and the
first section somebody creates appends a heading *below* what they already had
rather than pushing twenty-two fields down the page. A field naming a section
that no longer exists reads as ungrouped rather than disappearing; nothing here
can produce that state, but an import can, and a control that silently vanishes
takes its value with it on the next save. A section with no fields draws no
heading at all — it is kept in the editor, which is what lets somebody make one
before filling it.

##### The record page groups too, from the same method

**Showing a form in sections and a record as a flat list would be worse than not
grouping at all**, so the record page groups, and the grouping is decided exactly
once — `ModuleDefinition::getFieldGroupsFor()`. Both templates are handed the
answer rather than the ingredients, because two templates reading the same
definitions is the place grouping quietly diverges, and six months later somebody
is looking at a form in four sections beside a record page in one list. The test
asserts the two pages against *each other* rather than against two expectations
that could both be edited to match a bug.

The form keeps one thing the record page does not need: when there are no
sections it renders through `form_widget(form.fields)`, the same call it always
made, unconditionally. "An existing definition draws exactly as it does today" is
a promise, and the way to keep a promise like that is to run the same line of
code rather than a second one believed to produce the same bytes.

##### The controls, and the name is the customer's word

Making, naming, ordering and removing a section is a **page of its own**, for the
third time in this section and for numbering's reason: the field table is a
control per field, and a section is not a fact about a field. What *is* in the
table is one `<select>` per row saying which heading that field is under, which
is instantaneous and reversible — pick one, pick the blank again, nothing
happened — and therefore fits a cell.

The blank option is a real answer and the common one, which makes this the one
control on that row where nonsense is **refused rather than shrugged off**. A
width of 40 and a country that does not exist are read as "no opinion", because
there the honest response to a tampered form is to change nothing; here changing
nothing means saying no, since reading an unknown section key as blank would move
somebody's field and report success.

A section's **name is the customer's word, not a translation key**, and that is
not a new decision — a field's label and a shape's label are the same kind of
thing and are stored strings that go to the page as they are (§8.4.2). The key is
derived from the first name given and never touched again, which is XIV-144's
split for a choice field's options one level up: renaming a section changes what
the page says and moves no field, so renaming is free by construction. The trade
is the same one, and it is the right one: a typo is permanent in a key nobody
ever sees.

**Removing a section removes the heading and nothing else** — §5.4's oldest rule
one level up. The fields keep their values, their order, their widths and their
rules, and are drawn at the top of the form again; the confirmation says so, and
says how many, because a section *looks* like a container and "3 fields come back
to the top" is a different decision from "31 do". The fields are cleared in the
same transaction as the heading rather than left pointing at nothing: a
definition that is merely interpreted correctly is not the same as one that is
right.

Deliberately **not** in this:

- **collapsing a section**, which is a nice thing and a state to keep somewhere —
  per person, or per module, or in the browser — and every one of those answers is
  a decision this ticket did not need to make. Half-building it would mean a
  control that folds and forgets;
- **a module declaring its own sections** in its blueprint, with everything §7.2.1
  would then have to answer about offering one to a customer who has already
  arranged their own form;
- **tabs, wizards and conditional visibility.** The last is genuinely wanted and
  is a different feature: it is a rule about a *record* rather than a fact about a
  form, and XIV-88 has already written down why a rule a customer authors is not
  an expression language.

**Numbering is the one that does not fit in the table** (XIV-27). Its control is a
page of its own, because what a customer is deciding there is a pattern, the
number that pattern will produce and the counter it will come out of — and the
last two have to be shown *as it is typed*, before anything is saved. §5.10 has
the argument, including why the pattern syntax stayed a template rather than
becoming an expression language. Since XIV-91 that page also *starts* numbering,
which stayed off the table until the questions it asks about records had answers
— and is still not a control in this table, because everything else here is
instantaneous and reversible and that one writes numbers into records that
already exist.

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

#### A marker that draws a picture (XIV-89)

**`[tenant.logo]` is a change to this pipeline rather than a key in a list**,
which is why it was split out of XIV-49 and given a ticket of its own. Everything
above resolves to **text**: `DocumentMarkers::dataFor()` returns
`array<string, string>`, `DocumentGenerator` hands that to `anourvalar/office`,
and that library's `ZipDriver` opens the .docx and replaces strings inside the XML
parts. There is no image path in it at all — a driver for the spreadsheet side and
nothing equivalent for a Word drawing — so this is DrawingML written by hand, and
the marker's run is **replaced by an element instead of having its text
substituted**, which is the opposite of the one operation the whole feature is
built out of.

**Four things in the package have to agree, and they are per *part*.** The bytes
go in as `word/media/…`; that part is reachable only through a relationship in the
rels of whichever part draws it; `[Content_Types].xml` has to say what a `.png` in
this package is, or Word calls the file corrupt; and the drawing carries an extent
in EMU. A letterhead puts its mark in `header1.xml`, and a header keeps its own
relationships — so all of it happens once per part that mentions the marker, and
the media bytes are the only thing shared between them. `DocumentImages` is the
class; the seam it reads through is a second method on `DocumentContext`, so core
still never learns what a tenant or a logo is.

**The `rId` is taken from the part rather than counted.** Every `Id` in the rels
is collected, the highest `rIdN` decides where to start, and the candidate is
checked against the collected set anyway — because a relationship id is an xsd:ID
and nothing requires it to be `rId` plus a number, so a template that has been
through a converter or somebody's script may well carry `rIdImage1`. This matters
more than it looks: **a collision does not crash.** The package still opens and one
relationship answers for two uses, so the customer's own header image comes out as
the logo, or the logo comes out as their embedded font. A document that is wrong
and opens is worse than one that does not open.

**Where the drawing goes, and the split marker.** `<w:drawing>` is run content — a
sibling of `<w:t>` inside a `<w:r>` — so the substitution closes the text, emits
the drawing and opens a fresh one: `…</w:t><w:drawing/><w:t>…`. That is valid
because a run may hold text, then a drawing, then text again, and it is also
exactly right for the case that makes this hard. Word cuts a placeholder somebody
typed in one go across several runs, so the span being replaced routinely contains
`</w:t></w:r><w:r><w:t>` in the middle of it; consuming that span and emitting the
three fragments removes one `</w:r>` and one `<w:r>` together, so the markup stays
balanced and the two runs become one. The tail text inherits the first run's
formatting rather than its own, which is the one thing this loses and is a fair
trade against reconstructing run properties from a span that may have crossed
three of them. A span that crosses a *paragraph* is refused instead: a `[` at the
end of one paragraph finding a `]` at the start of the next is two brackets facing
each other, and welding the paragraphs together to draw between them is a worse
answer than leaving the words alone.

**The tolerant pattern moved to `TemplateTokens`**, beside the scan XIV-25 put
there. `RepeatingBlocks` had it privately and the library has its own copy inside
itself; a third would have earned the same paragraph XIV-25 wrote about three
scanners disagreeing about what a marker is. Two callers in this repository now
share one.

**How big: natural size at 96 dpi, scaled down to fit 40 × 20 mm, never scaled
up.** Three decisions rather than one. *Natural size* is the only starting point
that does not require guessing, and it is what makes a small mark come out small
instead of blown up. *The box* is what stops the common case being absurd — logos
are exported at two or three times their intended size as a matter of course, so a
1200-pixel-wide PNG is a 40 mm wordmark at 3× and not a request for a banner
317 mm across. A4 leaves about 160 mm between ordinary margins, so 40 mm is a
quarter of the text width: enough to read as the company's mark, small enough that
dropping it into a paragraph does not rearrange the page; the 20 mm ceiling is what
keeps a *square* logo from becoming a 40 mm block. *Never scaled up* because
enlarging a bitmap to fill a box is how a crisp mark acquires soft edges and the
customer has no way of knowing we did it — the same argument §8.6 makes for not
re-encoding the upload. The aspect ratio is preserved throughout, so the box is a
bound and not a shape. A PNG's own `pHYs` chunk is deliberately ignored: most
exports carry none and the ones that do carry whatever the design tool felt like.

**This does not want a second upload**, which the ticket asked about and §8.6 left
open. Fitting rather than stretching already gives a wide wordmark and a square
crest a sensible answer from one file, and §8.6's case for a second field was
about wanting a *different picture* in a different place rather than the same one
at a different size. If 40 × 20 turns out to be wrong for somebody, the next thing
to add is a size on the profile — one number, beside the picture they already
uploaded — and not a second picture, which would be a second thing to keep in step
for the sake of a measurement.

**The format is decided by decoding the bytes, and the list is PNG and JPEG.** The
seam hands over raw bytes and nothing else, so the question is asked of the thing
being embedded rather than answered by a label somebody chose — the same call
`LogoFormat` makes about an upload and the .docx check makes about a template. The
list is §8.6's and the reason it is that list is a licence rather than a
preference: the only credible SVG sanitizer in PHP is GPL-2.0-or-later and this
project is MIT. Both of these are formats Word embeds natively, so nothing is
converted and nothing is re-encoded on the way through. **No dependency was added
for any of this** — `ZipArchive`, `getimagesizefromstring` and `preg_*` are all
core PHP.

**An installation with no logo generates a document with nothing there**, and that
costs no code. The marker stays in the vocabulary whether or not anybody has
uploaded one — a marker that appeared and disappeared with the data behind it
would mean a template written this week naming something the review calls unknown
next week — so `dataFor()` still offers it as the empty string. The image pass
runs *before* the library and simply finds nothing to do, and the ordinary
blanking finishes the job. Blank beats brackets, unchanged. The same ordering is
why the image pass runs *after* `RepeatingBlocks` (§5.11): a mark inside a
repeating row is one marker before expansion and several afterwards, and each copy
needs a drawing id of its own.

**`TemplateReview` needed nothing.** It compares the tokens in the file against
`DocumentMarkers::keysFor()`, which is built from `general()`, which is where the
marker is declared — so the review stopped calling it unfillable by virtue of the
marker existing. That is the payoff for XIV-25's rule that the reference list, the
substitution and the report all read one vocabulary.

**The reference list says which marker draws a picture**, which the ticket raised
and is worth the one word it costs. `[tenant.logo]` beside `[tenant.name]`, under
one heading, in the same brackets, with a label that reads like the name of a file,
is a token that gets pasted into the middle of a sentence — and what comes back is
a picture wedged into a line of prose, which reads as the engine misbehaving. So a
marker carries a *kind* and the row carries a badge. A kind rather than a boolean
because the next one is plausibly a barcode. **The email templates page filters it
out entirely** rather than badging it: an email has no `<w:drawing>`, and what a
picture in one would be — a fetched URL or a CID attachment — is a design question
about email rather than a line missing (§5.13). Until that is answered the marker
comes out blank in an email, and advertising something that comes out blank is
what that page already refuses to do with collection markers.

**Documents are generated without a browser**, and this is the constraint that was
easiest to lose. XIV-49 added a public route serving these bytes and reaching for
it here would have worked in development and failed wherever the application
cannot address itself. `InstanceContext::images()` reads the column, and the test
that proves it generates a document with no request in flight at all.

**The PDF is proved, not assumed.** Gotenberg is what turns this into what the
recipient sees, and this feature has already been bitten once by Word and
LibreOffice agreeing that a file is valid and disagreeing about what to draw
(`showingPlcHdr`, above). So the suite converts both the body case and the
letterhead case and searches the PDF for an image XObject — and generates the same
document with no logo and asserts the PDF has none, because without that half the
test passes on a converter that puts an image in every PDF it makes.

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

#### A condition on a transition, and why it is not an expression language (XIV-88)

*Written while `LifecycleTransition` still carried no condition. It carries one
now — XIV-110 built it, in the shape this argument concluded with — and the
mechanism is the subsection below. This one is kept as it was, because the
reasoning about what a condition must **not** be is the part that stays true.*

`LifecycleTransition` carries no condition, and there is nowhere else to put one.
"Confirming an order needs at least one line" cannot be said anywhere today:
field validation is per field and unconditional, so it can only demand the line
of a draft as well, which is not the rule anybody means — the contact half of
that sentence is already a `required` field precisely because it *is* true of a
draft — and `RecordWriter` validates nothing, so the save a transition makes is
one that nothing inspects. The hole is real. This section records that it was
looked at deliberately rather than left lying, and that the answer proposed for
it was turned down.

The proposal was Symfony's ExpressionLanguage, raised while XIV-27 was rejecting
it for the numbering pattern. That rejection was narrow and the question
underneath is general: **is there anywhere in Xivi that wants a small, safe,
customer-authored expression?** The answer is **no, today**, and the argument is
written out here so that the next person to suggest it inherits it instead of
re-deriving it. The component is an *evaluator*: it parses PHP-like expressions
over variables that must be declared explicitly, and returns a value. Nothing is
ambiently in scope, and parsed expressions cache through PSR-6. It is MIT and
would be an acceptable dependency; it appears in `composer.lock` today only as an
optional peer of packages we already have, and is not installed.

**Two rules decide most of the candidates, and this project already learned
both.**

*Not where the answer has to become a `WHERE` clause.* §8.4 is explicit that
record-level permission is a query problem rather than a security-layer one — "a
check performed after loading, which is the wrong answer in a way that looks
right" — and §5.3 says the same of filters, where nothing from a user is
concatenated and the comparisons are a closed enum. An expression evaluates in
PHP over a record that has already been loaded, which is the wrong side of the
`LIMIT`: a list page is twenty-five records **and a separately counted total**,
and a predicate that can only run over the twenty-five prints a number of records
somebody may not see directly above the ones they may. **Permissions and filters
are therefore ruled out here, in as many words**, because §8.4's mistake would be
arriving through a new door and the sign was only on the old one.

*Not where the engine has to read the thing rather than run it.* That is XIV-27's
finding and it generalises. `NumberFormat::period()` decides which counter a
number comes out of by looking for `{year}` in the pattern **text**, and the
editor turns that into a promise kept before anything is saved. An evaluator can
only answer by evaluating, and `'ORD-' ~ (annual ? year : '')` has no static
answer at all. A useful third phrasing of the pair: it fits where the output is a
value, and badly where the output is text whose structure the engine inspects.

**The candidates, each with its verdict.**

- **Lifecycle guards — passes both rules, and still not this component's job.** A
  guard is a boolean over one record already in hand, and nothing needs to
  interrogate one without running it: the record page decides whether to draw a
  button by evaluating it against the record it is already showing. It is also
  the only candidate where there is currently *no* way to express the thing at
  all, which is the strongest argument any of them has. What decides it is asking
  who writes one. §7.1's narrowing — the paragraph above, about the engine
  refusing on a rule the module *declared* rather than a subscriber vetoing at
  runtime — is about a rule **a module** declared, and a module is code. Against
  code an expression string is strictly worse than a PHP predicate: PHPStan
  cannot see into it, neither can an IDE, renaming a field key breaks it
  silently, and it buys nothing a typed callable does not already give. This
  component earns its keep only where the author *cannot* ship PHP, which means a
  customer — and a customer cannot author a lifecycle at all. There is nowhere to
  keep one: options live on `FieldDefinition` and a lifecycle is not a field;
  this section says a lifecycle is part of what a module *is*, so changing one is
  a release rather than something a customer configures (§6.1); and XIV-27 set
  the standard for handing a customer a small language, which is a page that
  shows it working rather than a text box validated on submit. So: a guard is
  worth having, and when it arrives it should be a predicate declared beside the
  transition. Whether a customer may author one is a separate decision, and it is
  not one to make by accident inside a ticket about an evaluator.

- **Customer-authored derived values (§5.9) — declined on the transaction.** A
  deriver runs inside the save's transaction, so a customer's own slow or
  throwing expression fails somebody's save, and the failure would arrive at the
  point in the code least able to explain itself. The derivers anybody would want
  to imitate are the money ones, which would put a second implementation of
  §5.9's rounding rule in a text box — the exact duplication XIV-32 refused when
  the alternative was JavaScript. It inherits the storage and editor problem
  above unchanged: a deriver is a service, and services are code.

- **Conditional content in documents and email (§5.7, §5.13) — not blocked by
  either rule, blocked by a question that came first.** A boolean gate around a
  block would leave §5.13's escaping property intact, which is the thing to check
  before anything else: the block's body is still template text, and markers
  inside it are substituted into the Markdown **source** before CommonMark parses
  it, so record values stay escaped by construction. What stopped it was that
  §5.13 deliberately left repeating blocks out because Markdown has no unit to be
  one — a list item, a table row, a fenced block — and a conditional block asks
  the identical question with the same three candidate answers. **§5.13.1 has
  since answered the repeating half, and answered it by refusing to have a block
  at all**: `[lines]` is one marker that renders a table whose shape ships in
  code. That closes the question for collections and reopens nothing for
  conditions, because a condition has no equivalent trick — there is no "the
  whole thing, already laid out" for *maybe show this paragraph*, so a
  conditional still needs a unit to wrap and still has the same three candidates.
  What is settled is that a block arriving for conditions would be the first
  block syntax in an email body rather than the second, which makes it more of a
  decision than it looked like before, not less.

- **Validation rules (§5.4) — ruled out, by rule 1 arriving from a new
  direction.** A rule cannot be switched on if existing records would fail it:
  the editor counts them first and refuses with the number, and since XIV-109 the
  `unique` half names the shared values too. Counting how many records fail an
  arbitrary PHP expression means loading every record in the module and
  evaluating each one — a full read of a customer's table performed in order to
  draw a form, and one that gets slower for exactly the customers who have most
  to lose from the rule being wrong. The `unique` half is worse still: that flag
  now builds a unique expression index on the column, and there is no index for
  an arbitrary expression.

- **Conditional numbering — XIV-27, unchanged.** Rule 2, and nobody has asked.

**One thing that would be found and misread, so it is said here.**
symfony/workflow ships guards of its own, configured as expressions, and they are
the framework's own answer to this — which is normally the end of the argument
here (§5.7's rule). They are dispatched as `workflow.guard` events, and this
section builds its state machines with **no event dispatcher** on purpose,
because the component's events are a second place behaviour could hide. Adopting
its guards means adopting the seam this section refused. A condition, when there
is one, is evaluated by `RecordLifecycle` — which is where the refusal already
lives, and where `TransitionRefused` already carries a message somebody can act
on.

**What would change the answer.** A customer asking to state a rule of their own
about their own records and being told no — a guard, most likely, since that is
the one with no workaround. At that point three things are needed and none of
them exist: somewhere in the tenant's metadata for a per-transition option to
live, an editor page built to XIV-27's standard, and a written decision about
what an expression may see and what happens when one throws. Until somebody is
actually blocked, this is the abstraction §1 says has to be earned, and it has
one hypothetical use case rather than two real ones.

#### The condition itself: a transition that refuses (XIV-110)

The hole the subsection above found on the way is not closed by declining
anything, and this is what closes it. An order with no lines and a total of zero
confirmed cleanly — the button was drawn, the POST went through, and a document
with nothing on it became a confirmed sale. A lifecycle that can only refuse the
moves the **graph** forbids and never the moves the **record** forbids is doing
half of what this section claims it does, and §7.1's narrowing rests on the other
half: *"the engine refusing on a rule the module declared, not a subscriber
vetoing at runtime"*.

**`TransitionGuard`, declared beside the transition it is about.** One method,
`refusal()`, returning null when the move may be taken and otherwise a
translation key saying why not. It is not a service and is constructed inline in
the blueprint like `LineTotals` and `NumberFormat`, which is what keeps the
condition in the same place as the declaration it conditions — a tagged service
would have put the pairing somewhere other than the thing being paired. One guard
per transition rather than a list: "may this move be taken now" is one question,
and a module wanting two conditions writes one guard that asks both, which is
also the only way it gets to choose which of the two sentences comes back. A list
would need a rule for whose message wins, invented by the engine on behalf of a
module that knows better.

**The button and the enforcement are the same predicate asked twice, and both
have to exist.** A transition offered and then refused is worse than one not
offered, so the record page does not draw a button it knows would fail. But
hiding a button is not enforcement — a retyped POST is not a button — so
`RecordLifecycle::apply()` asks the same guard again, against the record as it is
at that moment, and *that* answer is the one that decides. Both come out of a
single method, `offeredFor()`, so there is no second evaluation to disagree with
the first; `enabledFor()` is a filter over its result rather than a second walk.
The order is deliberate: the state machine answers first and the guard second, so
a move the record's state already forbids costs nothing to not offer.

**A refused move is shown with its reason rather than silently dropped.** This
is the part that is not obvious. Hiding the button is right, but a refusal
nobody reads is a sentence written for a request only somebody retyping a URL
will ever make — so the page prints the module's reason where the button would
have been. That is the same three-state shape the send card beside it already
had for a recipient it cannot resolve (§5.14): a button, a reason instead of one,
or nothing at all.

**The message is the module's, in the module's own catalogue.** The guard hands
back a key and the engine puts it together with the module's key as the domain,
which is the same catalogue and the same domain the transition's *label* is read
from — so the button and the explanation for its absence are written in one file,
by one person, in one voice. "Cannot confirm" is something the engine could have
said on its own and is of no use to anybody; *"an order needs at least one line
before it can be confirmed"* is the message, and only the module can write it.
`TransitionRefused` gained a third constructor for it, and the third is a
different kind from the other two: "not a step this record has" and "not from
where it is" are facts about the lifecycle that the engine can phrase itself.

**What the predicate is handed, and what that costs.** A `GuardedRecord`: the
record, and a way to reach its rows. The rows are the reason the class exists —
"at least one line" is a question about a collection, and a collection is not in
the record's `data` — and handing a guard a bare record would mean every module's
guard knowing about the metadata and record repositories. They are **lazy**, so a
guard that reads only a header field makes no query at all, and **memoised**, so
a lifecycle with three guarded moves reads a collection once between them. One
object per ask, one query per collection asked about.

The cost question is the one §5.1 and XIV-54 both point at, and the answer is
established rather than assumed: **a list page never asks a lifecycle anything.**
Only the record page does, about the one record it is showing. So the whole bill
for a guard that reads rows is one query, on a page that is already loading those
same rows in order to draw them. The record page could therefore have handed the
rows over instead of paying for them again, and deliberately does not: a second
way in is a second thing to keep true, and a guard that behaves differently
depending on whether its caller remembered to prime it is a bug waiting for a
quiet afternoon. If a list page ever wants transition buttons per row, that is the
point at which this has to change — the rows would need priming for the page the
way `RecordPrimer` primes references, and a predicate evaluated inside a `LIMIT`
is on the wrong side of §8.4's line in any case.

**`RecordWriter` did not change, and that is the boundary.** It still validates
nothing, so the save a transition makes is still inspected by nobody. Whether the
engine may refuse a *save* on a module's say-so is XIV-73's question and a much
larger one — it reaches the form, the importer, the demo generator and every
caller holding the service — where refusing a *transition* touches one route with
one button on it. A guard is a condition on a move, not on a write, and the two
are not the same mechanism wearing different hats: a record may be saved in a
state a guard would refuse to move it out of, and that is correct, because saving
a half-finished draft is the ordinary thing to do with one.

**The one place that had to learn a new answer** is demo data. The generator
walks a record to its sampled destination one legal move at a time (§5.17), and
one demo order in seven is generated with no lines — which the order module's own
guard now refuses to confirm. It stops and leaves the record where it is, which
is the answer it already gave for a destination with no path to it. Writing the
state anyway would put records in a demo tenant that no person using the
application could have produced, which is exactly what XIV-73 spent a ticket
undoing.

**The rule that decides where a guard may go**, learned immediately and worth
stating: not on the only way out. The order's guard is on `confirm` and
emphatically not on `cancel`, because an empty order is precisely the kind
somebody wants to be rid of, and a guard that traps a record in a state it cannot
leave is worse than the bug it was fixing. It is also not on `deliver`, which is
unreachable without confirming first and would only be a second copy of the same
rule.

**And what the guard deliberately does not say.** The survey named "a total of
zero" alongside "no lines", and only one of them is a mistake. An order can
legitimately come to nothing — a goodwill replacement, a line discounted in full,
a sample priced at zero — and refusing those would be the engine having an opinion
about somebody else's pricing. An order with *no lines at all* cannot be any of
those things, because there is nothing on it to have been priced.

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

#### A price that already has the VAT in it (XIV-116)

The four decisions above all assume the price typed is a **net** price and the
tax goes on top. That is one of the two ways prices are quoted, and for a large
part of this product's market it is the wrong one. A shop in Zurich, Vienna or
Munich prices a lamp at 19.95 *including* 8.1%, because that is the number on
the shelf and, for anything sold to a consumer, the number the law says has to be
shown.

Until this ticket such a shop could not enter that number. They had to divide by
1.081 themselves, type 18.46, and hope the arithmetic came back — and at 19.95 it
does not: 18.46 plus 8.1% of 18.46 is **19.96**. A rappen above the shelf price,
on the customer's own document, and nobody in the building can explain it. That
rappen is not a rounding bug to be tightened away; it is what happens when a
number is derived from a derived number, and the only fix is to stop deriving the
one the customer typed.

**The mode is a value on the document, defaulted from the tenant.** Three shapes
were on the table and the argument is the one §5.16 already made about a date:

- **Per line** is wrong, and it is worth saying why rather than assuming it. Every
  *other* money decision here is per line, including the rate — a document mixing
  8.1% and 2.6% is an ordinary week. But a rate genuinely differs line by line,
  and how to read a price does not: a document with some lines quoted gross and
  some quoted net has a price column whose meaning changes halfway down it, and no
  recipient can check such a column at all.
- **Per tenant alone** is where the answer *comes from* — a shop is a shop, a
  consultancy is a consultancy, and it is what makes an article's `price` field
  unambiguous, since the catalogue is priced in whatever the installation says.
  What it cannot be is the thing the arithmetic reads. The day somebody changes
  the setting, every draft in the building would silently reprice, and every
  document ever saved would recompute differently the next time anybody touched
  it. That is the exact failure §5.9's first decision and §5.16's whole argument
  exist to prevent: **what was agreed is a fact about that document.**
- **Per document, materialised from the tenant's setting when the document is
  created** — which is both. The setting seeds the field once, on a blank form;
  the field is what `DerivesTotals` reads from then on; and a business that does
  both is covered by the one document that differs. The chain is the one
  [XIV-50], [XIV-67] and [XIV-83] already walk (`ProfileVatMode` implements
  `DefaultVatMode`, beside `ProfileCurrency` and `ProfilePaymentTerms`), and
  deliberately not a fourth variation of it.

**Null at the top, for the third time on that row.** An installation nobody has
asked writes nothing onto a new document, which is *not* the same as answering
"excluded" even though both produce a net-priced document: only the first leaves
an existing customer's records shaped exactly as they were. It is the same call
§8.6 makes about the currency and §5.16 about the payment term, and it lands in
the safe direction.

**An invoice takes the mode from the order it was seeded from**, not from the
settings page it happens to be saved on. §5.12's rule again: an invoice quotes
what was agreed on the day, and a price column that changed meaning because
somebody edited a setting afterwards would be the one figure on a sent document
that kept moving.

##### The arithmetic, and where the remainder lands

**Inclusive is not a second deriver.** It is `DerivesTotals` — still the only
thing computing any of this ([XIV-73]) — running the same loop the other way.
Everything before the last three lines is shared: a line total is quantity times
price rounded to two places, a comment line contributes nothing, a subtotal
restates the block above it, and the VAT table has one row per rate with a rate of
nothing getting no row. What the mode changes is which total the lines gave you:

| | exclusive | inclusive |
| --- | --- | --- |
| the lines sum to | the **net** total | the **gross** total |
| per rate | tax = `net × rate`, rounded once | net = `gross ÷ (1 + rate)`, rounded once |
| | | tax = **`gross − net`**, the remainder |
| the other total | gross = net + tax | net = gross − tax |

**The gross the customer typed is the gross that prints**, and the whole design
is in service of that one sentence. Deriving a net and then re-deriving a gross
from it is precisely the mistake that produces the rappen, so the gross is never
recomputed: it is the sum of a line-total column that was already rounded, and
the tax is whatever is left of it once the net has come out.

**So the remainder lands on the tax, and the rule generalises: the figure
somebody typed is exact, and the derived figure absorbs what is left over.**
[XIV-104] is deciding the same question for discounts and this is the answer to
agree with.

**There is no remainder to place *across* rates**, which is the other half of the
question and it turns out to have been answered in 2026 by the third decision
above. VAT is grouped per rate before it is rounded, so each rate's gross is split
into a net and a tax that add back to exactly that rate's gross; a document at
8.1% and 2.6% is two exact splits summed, and neither of them ever produces a
leftover rappen for the other to absorb. Nothing had to be decided about which
rate wins, because no rate ever loses.

`Amount` gained one operation for this, `withoutPercent()`, and it is the first
one on that class that rounds inside itself. That is not an inconsistency: every
other operation there is exact and can honestly defer the decision, and division
cannot — 19.95 ÷ 1.081 goes on forever. brick/math says the same thing in its
signature, since `dividedBy()` demands a scale and a rounding mode and throws
without them, so what happens here is the framework's own operation with §5.9's
rule applied to it rather than a division helper invented for the occasion.

##### What did not move

**No stored total in any existing record can read differently.** Totals are
stored rather than recomputed on read, so a record nobody saves is untouched by
construction; the migration adds one nullable column to `tenant_profile` and
writes into no document at all; and a record that *is* re-saved derives the same
figures, because an empty `vat_mode` reads as excluded and the tenant's setting is
never consulted while deriving. `VatMode::of()` is the single place that mapping
lives, and it maps *every* way of saying nothing — a null, an empty string, a key
that is not in the values at all, a value nobody could have meant — onto excluded
rather than throwing, because this runs inside a save's transaction.

**Existing tenants take the field deliberately**, through §7.2.1's offer, exactly
as [XIV-118] did with the unit. A customer who never takes it has a shape
identical to the one they had before this ticket and derives identically; a
customer who takes it and answers nothing is in the same position with a blank
field.

**Two values and not three.** "No VAT" was already representable and always has
been — it is a *rate* of nothing, which the third decision above settled. A third
mode here would be a second way to say something the rate already says, free to
disagree with it.

**What the recipient reads.** The mode is an ordinary header field, so it is an
ordinary document marker (§5.7) and appears in the reference list beside
`[gross_total]` with nothing added. Its shipped labels are therefore **whole
sentences** — "Prices include VAT", not "included" — because a template prints
`[vat_mode]` and gets the *option's* label with no field name beside it, and a
recipient reading one word next to a totals block is being asked to work out
included in what. Like every label it becomes the customer's on install (§6.1),
which is the point: "exkl. MWST" against "zzgl. MwSt." is house style as much as
translation. A template written before this ticket is unchanged and prints
nothing new, which is correct, because every document it can print is net-priced;
a shop that switches adds one marker.

---

### 5.10 Document numbers (XIV-15, XIV-27)

A field may be numbered from a sequence: `ORD-2026-0001`, `INV-2026-0001`. Two
things can go wrong with a document number and both are fatal — one that changes
after somebody has read it down the phone, and two documents carrying the same
one — so the mechanism is small and the decisions are written down.

**Declared as an option, not as a field type.** A number is a string; what is
special about it is *who fills it in*, which is a fact about the field rather
than about the kind of value. So `NumberFormat::from('ORD-{year}-{number:4}')`
spreads into any text field's options, the way inherited values do (§5.1), so it
is per customer and changeable without a deployment — **and, since XIV-27,
changeable by the customer**, on a page of their own in the metadata editor. For
two releases this section claimed that and it was false: the mechanism was
theirs, the control was missing, and every Xivi customer's orders were called
`ORD-` whether they sold orders or Aufträge.

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

#### The customer's own numbering (XIV-27)

**A page, not a cell in the field table, and it shows the number it will
produce.** `ORD-{year}-{number:4}` is a small language and every one of its
failure modes is quiet: a pattern with no `{number}` numbers nothing — the field
simply goes on being an ordinary text field — and a width too narrow stops
sorting correctly once the counter passes it, on a list somebody reads every day.
None of that can be explained by validating a text box on submit. What answers all
of it is rendering the next number *from the pattern as typed*, which turns a
syntax somebody has to learn into something they watch working. That is the whole
justification for a Live Component (§8.3) here rather than another column beside
the width and the search-box setting.

**The syntax stays a template, and that decision is now load-bearing.** Symfony's
ExpressionLanguage was proposed for it and rejected; the argument in full is on
XIV-27 and the short version is that `NumberFormat` reads the pattern **without
running it** — `{number}` decides whether the field is numbered at all, `{year}`
decides *which counter* the number comes from — and this page turns both into
promises kept before anything is saved. An evaluator can only answer by
evaluating, and `'ORD-' ~ (annual ? year : '')` has no static answer at all: an
expression language is precisely the tool that makes static derivation
impossible, and static derivation is what the numbering rests on. It would also
have inverted the ergonomics — the pattern is 95% literal text with two holes in
it — and answered none of the things this ticket was actually about.

**Refused rather than silently inert.** A pattern with no counter in it means one
thing to a blueprint and another to a form. To a blueprint it means "this field
is not numbered", which is right and should stay silent. To somebody who has just
typed it into the editor it would be silence in place of an answer, and they
would find out at their first blank invoice — so `MetadataEditor` refuses it,
**on the write path** rather than in the controller, which is where an import or
a console command meets the same rule.

**Which counter the next number comes from is said out loud, before saving.**
This is the part nobody guesses. Switching from `ORD-{number:4}` to
`ORD-{year}-{number:4}` does not reset anything: it starts drawing from a
different counter, one that has always existed and has never been used, so the
next order is `ORD-2026-0001` after `ORD-0087`. Defensible, surprising, and
therefore a sentence on the page rather than a footnote in a changelog. Nothing
is renumbered by any of this, and the page says that too — the numbers already
given out are on documents customers are holding, and the metadata editor cannot
reach them.

**The counter's next value is settable, and that is the one control here that can
produce a duplicate.** It earns its place because without it numbering can only
be adopted by a business on its first day of trading: somebody migrating from
another system arrives mid-sequence and their next invoice has to be 1043. So it
exists, and **it only moves forward**. The guard is one statement —
`ON CONFLICT DO UPDATE ... WHERE next_value <= :next` — for the same reason
allocation is: reading the counter in PHP and writing it back is the
read-then-write race this whole feature was designed around, and it would lose in
the way that matters, by consuming a number between the check and the write. No
rows come back when the condition fails, which is how the caller learns it was
refused. The page warns before the refusal happens; the refusal does not depend
on the page.

**Which types may be numbered is declared, not asked with `instanceof`.** A
`text` field can carry a document number and nothing else can: `ORD-2026-0001` is
a string in every part of itself, including the leading zeros that make it sort,
and an `integer` would store 1 and print 1. So `TextFieldType` implements
`Numbers`, XIV-36's `Autocompletes` stays as it is, and the editor holds **one
declared list of option to capability** — which is the shape §5.4 has been
describing since it was written, arrived at from two examples rather than
invented from one.

#### Making a field numbered, and stopping (XIV-91)

For two releases the numbering page appeared only on a field that was numbered
already, and the reason was never squeamishness about scope: turning numbering
*on* is a question about **records**, not about definitions, and it had three
answers a ticket about patterns could only have guessed at. Here they are,
answered.

**The rows that have no number: a backfill, in creation order, once.** This is
the decision, and it is §5.10's own rule rather than a preference.
`AssignsNumbers` fills an empty field on *any* save, which is what makes
"assigned once and never changes" work for a record that has one. Left alone,
switching a populated field to numbered would hand out numbers **in the order
somebody happens to open the records** — the oldest contact becomes 0001 by being
edited on a Tuesday, and a number that is supposed to record when a document was
made records when it was last touched. So the rows with nothing in them are
numbered on the spot, oldest first, in one transaction.

The alternative was numbering **only on creation**, and it loses twice. It leaves
every existing record permanently blank in a field the module may be using as the
record's title (§5.4) — a list ordered by the thing the record is called, with
three hundred blanks at the top of it — and it is not even a change to this
feature: "only on creation" means altering how `AssignsNumbers` behaves for every
already-numbered field in every tenant, to fix a case none of them are in. A
ticket about turning numbering on for one field is the wrong place to change what
happens to every field that already has it.

The backfill is irreversible and is therefore **stated before it happens**, on a
confirmation page in §4.1's tone: it names the pattern, how many records will be
written to, what the first and last of them will be called, and that it cannot be
undone; the tick arrives unticked and the controller requires it, because a
`required` attribute is a courtesy to somebody using the page and nothing at all
to a form posted around it. It writes the one column and deliberately does **not**
go through `RecordWriter`: one administrative act is not several hundred edits,
and putting it through the record writer would bump `updated_at` to today on the
whole table — stamping every document as changed today in the act of giving it a
number is precisely the confusion this section is trying to prevent. What
replaces the history entry is the confirmation, which says it once beforehand
rather than three hundred times afterwards.

**The values somebody already typed: the column is read, and the guard is not
touched.** A text field being made numbered may hold `RE-2026-0007` that a person
typed, and a counter starting at 1 knows nothing about it — the guard above reads
the counter and the collision is in the column. So `NumberFormat::render()` is run
**backwards**: a value is one of ours when the pattern's literals line up exactly
and the holes are digits, which makes recognition and production the same rule
read in two directions and unable to drift apart. The counter is then floored
above the highest recognised value, through a statement that takes `GREATEST` and
therefore has no failure mode at all.

Everything the pattern could not have rendered — `Referenz 12`, last year's
numbers under a `{year}` pattern — is left exactly where it is, and that is an
answer rather than an omission: a number this counter hands out can never come out
looking like `Referenz 12`, so it cannot be duplicated by it. The same check
guards the wind-forward control, *beside* XIV-27's in-statement refusal and never
in place of it: a column scan is a read and can be raced, `ON CONFLICT … WHERE`
cannot be, so the scan narrows and the statement guarantees. Dropping either
leaves a duplicate nobody catches.

**A numbered field becomes `derived`, and that is what closes it going forward.**
Otherwise a person could type a number the counter is about to give out, at any
moment, next to a counter with no way of hearing about it — the duplicate the
column scan just closed, reopened one save later and permanently. So the two move
together: numbering is not a setting that can be on while the field is still an
ordinary text box. The same rule decides what may be numbered — a `text` field on
a module's own shape that **nothing else already fills in**, because an order's
total and an invoice's due date belong to a deriver and two derivers with an
opinion about one column is a race decided by declaration order. System-ness is
deliberately *not* a bar: a module's own text field is still the customer's data
in the customer's copy of the module (§6.1), and §5.4's rule is about *removing* a
module's field, which orphans values, where this creates none.

**Turning it off is a page, not an emptied text box.** Un-numbering leaves every
record carrying a number nothing maintains, so it says so: the numbers stay,
because they are on documents customers are holding and nothing in the metadata
editor may reach them; the field becomes an ordinary text box anybody may type in;
and **the counter is kept**, which is the decision worth reading — deleting the
row would be tidier and would mean that switching numbering back on next month
started at 1 and walked straight back through numbers already printed. A counter
nobody draws from costs one row. An emptied pattern is still refused rather than
read as "off" (`MetadataEditor`), because blanking a text box is not that
conversation and reading it as one would make the most consequential change here
the one that takes the least typing.

**Where the control lives, and why not in the field table.** On the numbering page
XIV-27 built, reached from a link that now appears on a plain text field too. The
field table is a row per field and a control per column, and every one of those
controls is instantaneous and reversible: tick "listed", untick it, nothing
happened. This one writes numbers into records that already exist. A checkbox in
that row would make the most consequential change on the page look like the
cheapest one.

#### A numbered field is a unique field (XIV-109)

For one release this section ended with a window, written down rather than
papered over: the column scan runs inside the transaction that turns numbering
on, the field is not `derived` until that transaction commits, and a record saved
on another connection in those milliseconds could slip a hand-typed value in
beside the counter's freshly-applied floor. Administrator-only, small, and a
window. The honest fix named there was a unique index on the column, in §7.2's
territory rather than XIV-91's.

**§7.2 built it, and it lands here as a statement rather than as a lock.** This
section opens by saying that two documents carrying the same number is one of the
two fatal failures of this feature. Everything above keeps that promise with
arithmetic — a counter that moves in one statement, only forward, and a scan of
what somebody typed before it existed — and arithmetic is not a constraint: it is
complete about the numbers the counter gave out and blind to every other way a
string can reach that column. So the definition now says what the feature has
always meant. **Turning numbering on marks the field `unique` beside `derived`**,
which builds the index §7.2 describes, and the shipped order and invoice
blueprints declare it too.

Three things follow, and the third is the one that closes the window.

**The promise stops depending on the engine being the only writer.** `derived`
means nothing but the engine fills the field, and that was the whole guarantee; a
row arriving by any other route — an import that predates the flag, a restore, a
column somebody edited — could carry a number the counter would later hand out
again. Now it cannot be written at all.

**It can refuse, and that is right.** A column that already holds the same
reference on two records cannot be made to promise unique numbers, so turning
numbering on is refused there, with the values named (§7.2). The confirmation page
says so before anybody agrees to anything.

**The window is gone rather than narrowed.** `CREATE UNIQUE INDEX` takes a `SHARE`
lock on the table and holds it until the transaction commits, and that lock
conflicts with every insert and update. Marking the field unique is therefore the
*first* step of turning numbering on, and from that line onward no other
connection can write a row of the module at all: the scan that follows is not a
read that can be raced but a read of a table nobody may change, and the floor it
computes is true when it is applied. Neither XIV-91's floor nor XIV-27's
in-statement counter guard is weakened or removed — they answer different
questions and a lock that stops being taken for some future reason must not take a
counter with it.

**Turning numbering off leaves the field unique**, which is the decision worth
reading twice. Un-numbering makes the field an ordinary text box anybody may type
in, and that is exactly the moment the index earns its keep: the numbers on those
records are on documents customers are holding, and the first thing a text box
invites is somebody typing one of them a second time. Relaxing the promise as a
side effect of a change about something else would be the opposite of what the
rest of this section does. A customer who really means it unticks the box
themselves.

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

**An email does none of this, and §5.13.1 is where that is argued.** There a
collection is *one* marker — `[lines]` — rendering a whole table whose shape
ships in code, which is the opposite of everything above. The two are meant to
disagree: this section's whole argument is that a template exists to decide how
somebody's invoice looks and the engine must not take that away, and an email has
no layout worth designing (§5.13) — so in an email there is nothing to take. Read
side by side without that sentence, the difference looks like an oversight.

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

**Repeating blocks were deliberately out of scope**, and §5.13.1 is the ticket
that answered the question this one left open. `RepeatingBlocks` (§5.11) scans
`<w:tr>` elements out of Word's XML: the table row is the unit because it is the
unit Word gives a person. Markdown has no equivalent, and choosing one — a list
item? a table row? a fenced block? — was a design question rather than a port. A
collection marker written into an email came out blank, which is the same "blank
beats brackets" call any unfilled marker gets, and the page did not offer the
tokens.

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

#### 5.13.1 A collection in an email body (XIV-62)

The question §5.13 declined to answer, left open because it was a design
decision rather than a port. **`[lines]` is one marker that renders the whole
collection as a table, and the shape it renders into ships in code.**

**Why the document answer does not carry over.** §5.11 got its unit for free: a
`<w:tr>` is the unit because it is the unit Word gives a person, so the template
author builds the row they want and gets it that many times. Markdown gives no
unit at all, so there was nothing to port and every candidate cost something. A
Markdown table row is the closest thing to the docx model and is text held
together by punctuation, so a line description containing `|` breaks the
template rather than the line. A list item is natural Markdown and a poor fit,
because line items have columns and a list has one. Explicit `[lines]…[/lines]`
delimiters are unambiguous, multi-line and format-independent — and a template
language arriving by the side door, in a system whose markers are flat
substitutions and deliberately nothing else.

**All three exist to let a tenant hand-build the line table, and that is what
rules them out.** §5.13's argument for Markdown was that *an email has no layout
worth designing*. It is why there is no .docx here, no rich-text editor and no
per-tenant wrapper. Handing somebody a repeating construct so that they can lay
out their own table takes that argument back three paragraphs after making it.

**So this diverges from the document side on purpose, and the divergence is the
decision rather than an inconsistency to apologise for.** In Word the layout
*is* the deliverable — a template exists to decide how somebody's invoice looks,
and an engine that pre-formatted the cells would be taking that away, which is
exactly what §5.11 refused. In an email it is not, and there is a second reason
now: XIV-40 attaches the generated document, where the lines are already laid
out properly. What a message wants beside that attachment is a **summary** — a
few lines and a total — rather than a second full rendering of it. Anybody
reading the two sections side by side will see a repeating row on one and a
single marker on the other; this paragraph is why, because without it that reads
as an oversight.

**The grammar is the document's own, not a second one.** `collection[:kind].field`
is what §5.11 already writes; this reads the same production with the field part
allowed to be absent, or to be a list:

- `[lines]` — every row, in the columns below;
- `[lines:article]` — only the rows of that kind;
- `[lines.description,line_total]` — those columns, in that order;
- `[lines:article.description,line_total]` — both.

Overloading the colon to mean "columns" — `[lines:description,quantity]` — was
the other candidate and was rejected on exactly this: the colon already means
"of this kind" one screen away, and `[lines:article]` would then have had two
readings depending on whether the tenant happened to have a field called
`article`. Extending an existing production costs a reader nothing; giving a
separator a second meaning costs them the first one. The happy consequence is
that **every collection token from the document reference list means something
here** — `[lines.description]` pasted out of a .docx is a one-column table
rather than the blank it used to be.

**It expands to Markdown, before CommonMark parses it, and that is the
load-bearing part.** §5.13 made marker substitution happen on the *source*, with
`html_input: escape`, so that a record value containing a script tag becomes
text without anybody remembering to make it so. A `[lines]` that expanded to
**HTML** would arrive after that decision had been made, hand raw markup to the
sanitizer as its only defence, and — worse — have no sensible plain-text form,
so the text alternative §5.13 gets for free would quietly degrade to a table's
worth of nothing. A pipe table keeps both: values still enter as source and are
still escaped by the parser, and the text part is still the thing somebody would
read. `EmailCollectionKindsTest` and `EmailTemplateTest` both prove it with
markup in a record value.

The price is that a cell containing the table's own punctuation has to be
escaped, which is one small solvable problem instead of a class of them: the
backslash first and then the pipe, because escaping a delimiter with a character
that is itself special and not escaping *that* is the classic way to leave a
hole. A newline in a cell becomes a space — a pipe table's row *is* a line, and
the usual answer, a literal `<br>`, is the one thing this must not emit.

Two consequences of the same rule are worth naming. Tables are **not**
CommonMark — they are GitHub's extension to it — so the converter gained
`TableExtension`, named rather than taken as part of the GitHub-flavoured bundle
that would also bring autolinking, strikethrough and task lists nothing asked
for. And a table is a **block**, so it needs a blank line on each side; the
source is measured rather than padded blindly, because padding unconditionally
would leave a stray blank line in the plain-text half every time the marker
already stood alone, and that half is the one a person reads.

**A collection whose rows are not all the same thing goes into one table**, in
the collection's own order, with the union of the fields as columns and an empty
cell where a row's kind carries nothing. §5.11 left this to the template, which
is not an option once the shape ships in code, so the engine answers. The other
two candidates are worse for reasons the document side already found:

- **one table per kind** sorts the invoice by kind, and a comment line sits
  *between* two article lines (XIV-21) — this is precisely what §5.11 rejected
  when it made consecutive blocks a group;
- **the default kind alone, the rest named explicitly** sends an order
  confirmation listing four of six lines, which is the only one of the three
  that can be *wrong* rather than merely plain.

The union costs an empty cell where a comment line meets the money columns,
which is what a printed invoice looks like anyway. There is no layout here to
protect, and that absence is exactly the difference from Word that made §5.11
push kinds back to the template in the first place.

**Two fields are left out of the default columns, and neither is a guess about
what matters.** The field that says which kind a row is, because it is the
discriminator rather than a column — §5.1 has it travelling hidden for the same
reason, and "Comment, Article, Article" beside rows that already look different
is noise. And a field another field is copied *from* (XIV-18): an order line's
description is inherited from the article it names, so a table with both prints
the same words twice under two headings. Nothing else is capped. A cap would be
the engine guessing which of somebody's fields matter, and being wrong about it
drops the total off the end of the table without saying so; naming the columns is
one line, and the placeholder panel prints a worked example of the form built
from that collection's own fields.

**The panel offers the tokens and says what they do.** It has to say so: `[lines]`
sits in a list beside `[first_name]` looking exactly like it, and one of the two
expands to a whole table — which is the same mistake XIV-89's picture badge
exists to prevent one row further down the same list. It is deliberately *not*
the document page's section: there a token names a column, and printing those
here would be printing a vocabulary that means something else on the page it is
printed on.

**A collection marker in the subject line comes out blank**, because a table is
not a subject. It is blanked rather than left as brackets, which is the rule
every marker the engine knows and cannot fill already gets (§5.7).

**The substitution stopped being a `strtr()`**, and kept the property that was
`strtr()`'s whole justification. One left-to-right pass that never re-reads what
it has written is what stops a contact whose name contains `[today]` from having
it substituted; `preg_replace_callback` keeps that exactly — scanning resumes
after each replacement — and buys the thing `strtr()` could not do, which is to
decide per token what kind of marker it is. Collections are asked first, because
`dataFor()` blanks every `[lines.description]` for the document side's benefit
and consulting the flat map first would blank the very tokens this exists to
fill. A token nothing answers is still printed as it was typed, which is XIV-25's
rule and the thing a change of mechanism could most easily have dropped
unnoticed.

**The wrapper gained its one `<style>` block**, and it contradicts nothing §5.13
says. CommonMark emits a bare `<table>` — no borders, no padding, every cell
touching the next — and inline styles are not available for it: the markup comes
out of the parser and the sanitizer, and reaching in to add attributes afterwards
would mean editing HTML the application has just decided to trust. So a block,
scoped to the customer's own words so it cannot reach the frame, and the argument
against relying on one does not apply: a client that drops it shows a plain
table, which is legible, where a dropped *frame* would be collapse.

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

### 5.19 Vouchers, and a counter with a rule in it (XIV-103)

A tenant can create vouchers: a code they hand out, worth one of three things,
good between two dates and redeemable a bounded number of times. **Applying one
to an order is [XIV-104]** and is deliberately absent from this section — what is
built here is the voucher *existing*, being valid, and being redeemable. §5.24 is
the other half, and the seam between them turned out to be exactly the one method
call this section predicted.

> **The kinds below have since been reshaped** ([XIV-122], §5.25). There are now
> four rather than three, they say a *mode* as well as an arithmetic, and
> `free_article` is gone — dissolved into "a line voucher restricted to that
> article at 100%" rather than renamed. Everything this section argues about
> *why* the kind is a variant, why the module does not `require` articles, and
> how the counter works is unchanged and is why the reshape cost a blueprint
> edit. What it says about the article link being **required**, and about that
> being load-bearing twice, is the one claim §5.25 deliberately overturned.

Most of it is a blueprint like every module before it. One part of it is not, and
that part is the reason this section is long: **a usage limit is a counter, and a
counter that two requests can reach is the one thing a declaration cannot
express.**

#### The kind is a variant

Money off, a percentage off, or a free article. Three kinds, one shape (§5.5),
and the deciding fact is the one §5.5 already names — *the fields depend on the
answer*. An absolute voucher has an amount and no percentage; a free-article
voucher has neither and carries a link and a quantity instead. They use the field
types that already exist: `currency`, `decimal` and `reference`, with nothing
added to the engine for any of them.

Both alternatives lose, and how they lose is worth recording.

**Three modules** would put three entries in the navigation for one idea, and
would make "which voucher was used on this order" a *polymorphic* reference — an
id plus a type saying which table it points at. That is the shape §5.2 refused
once already, and [XIV-104] would have to carry it for ever.

**One shape with a nullable field per kind** would offer every customer an
amount, a percentage, an article and a quantity on every voucher, with nothing
anywhere saying that filling two of them in is nonsense. The rule that only one
applies would live in validation the engine cannot express, and the form would
ask four questions where one is meant.

§5.5's consequence follows for free and is a feature here rather than a cost:
adding a voucher **asks which kind first**, because the fields depend on the
answer and something has to settle it before the form is drawn.

#### It does not require the article module, and nothing had to be built for that

Only one of the three kinds needs an article to point at, and `requires` is per
module rather than per variant ([XIV-23]). Declaring it would mean a customer who
wants `GIVE-10` off a total cannot have vouchers at all unless they also keep a
catalogue — a whole module refused over a kind they were never going to use.

So it is **`uses`**, which is exactly the distinction [XIV-23] drew for the order
module's article lines: installing succeeds, and the part that depends on the
missing module is not offered.

**The question that mattered was whether hiding a kind already existed, and it
did.** `AvailableVariants` has hidden a variant whose *required* reference points
at an uninstalled module since [XIV-23]; what was untested until now is that it
does the same for a **module's own** variants rather than only for a collection's
row kinds — the same class asked about a different shape, which is what §5.5
meant by describing *shapes* rather than modules. Both the record form and the
"which kind" chooser already ask it. Nothing had to be built;
`VoucherWithoutArticlesTest` is the evidence, and it checks the URL as well as
the page, because a hidden kind reachable by typing is not hidden.

§7.6's other answer — a link into an uninstalled module matches nothing and reads
as `#id` — stays the right **fallback** and is the wrong primary mechanism. It is
what should happen to a voucher created while articles existed and read after
they were removed. Offering somebody a kind whose only meaningful field is a
picker with nothing in it is a different thing: broken rather than degraded.

#### The code: chosen or generated, and folded in one place

`GIVE-10` is the point. A code people can say out loud beats one that is merely
unguessable, so the customer may type their own — and a duplicate is refused with
a message on the field while the form is open.

**Case is decided by folding, not by comparing.** `give-10` and `GIVE-10` are the
same voucher to a person, and there are two ways to arrange that. The tempting
one is a case-insensitive comparison wherever a code is looked up. It loses for a
structural reason: since [XIV-109] a `unique` field is enforced by a **unique
expression index over `data ->> 'code'`**, which is case-sensitive, because that
is what Postgres does with text. A case-insensitive rule in PHP and a
case-sensitive index do not differ in style — they *disagree about what a
duplicate is*, and the database is the one that is actually true. `give-10` would
be accepted beside `GIVE-10` and then found by either spelling: two vouchers
answering to one name, with whichever one the till picked deciding the discount.

So the fold happens **on the way in**, in `VoucherCodeFieldType::toStorage()`.
That is not a convenient hook but the engine's own normalisation seam:
`RecordValidator` runs values through it before validating ("values are validated
in the shape they will be stored in"), `RecordRepository` before writing, and
`QueryCompiler` before comparing. One method covers the form, the import, the
validator, the index and every future lookup by code — including the one
[XIV-104] will make — and nothing downstream has to know case exists.

**A field type rather than an option on `text`.** The interface's own docblock
says a type owns storage, validation, the form control and the display, and that
a new one is "one class and no configuration". A `case: upper` option on
`TextFieldType` would have worked and would have put a voucher's rules in core,
where the next reader has no way of knowing why a text field grew a case setting.
The cost is stated rather than hidden: the registry is global, so "Voucher code"
appears in the metadata editor's type dropdown for every module in every tenant.
Hiding it would need the engine to learn which module may offer which type — a
concept it does not have and should not grow for one dropdown entry.

**Two alphabets, because two different things choose the characters.** What a
customer may type is wide: `A-Z`, `0-9` and single hyphens between groups.
`GIVE-10` contains `I`, `1` and `0`, so narrowing that set would refuse the one
code anybody would actually write. What the **generator** may pick is narrow, and
for a reason that does not apply to a chosen code: nobody chose those characters,
so nothing is lost by leaving out the ones that get read wrong. It is Crockford's
set — `0-9A-Z` less `0`, `1`, `I`, `L`, `O` and `U`. The first five are the pair
and the trio a person dictating and a person typing disagree about; `U` is there
for a different reason, which is that eight random letters occasionally spell
something a customer has to apologise for. A mitigation, not a guarantee. The
line is drawn at a published set rather than a longer bespoke one: `S`/`5`,
`2`/`Z` and `8`/`B` are also confusable in some fonts and are kept, because every
character removed costs entropy.

Eight characters in two groups of four — `HK4T-9PQM`, read out in two breaths —
from `random_int()` rather than `mt_rand()`. **Not a sequence**: document numbers
are sequential because gaps in the books are questions (§5.10), whereas anybody
holding `AB-0004` could guess `AB-0005`, and that is somebody else's money.

**Asking for a generated code is leaving the box empty.** A `ValueDeriver` fills
it, which is §5.10's rule with the field left editable — fill it if it is empty,
never touch it if it is not — so a code is assigned once and survives every later
save. It is deliberately not `SafeToPreview`: a generator run at typing speed
would hand back a different code on every keystroke.

A "generate" button was considered and costs more than it is worth here. It would
need a capability interface in core, a `LiveAction` on the application's record
form and a form theme block to render the control — three changes to shared
surfaces, none of them about vouchers, to replace a rule one sentence of help
text can state. **What is genuinely lost is that the code is not visible until
after the save**, which is the same trade §5.10 already makes; the code is the
record's title, so the next page is headed with it. If a second module ever wants
a generated value, the capability becomes worth building.

#### Once, N times, unlimited — and unlimited is not a number

One optional integer. "Once" is 1, "N times" is N, and **unlimited is nothing
stored at all**: `RecordRepository` drops nulls out of the payload, so an
unlimited voucher does not carry the key.

There is deliberately no sentinel — not 0, not -1, not a very large number. A
sentinel is a value arithmetic will happily compare against, so
`redeemed < 999999999` is true for reasons that have nothing to do with anybody
having asked for an unlimited voucher, and it stops being true on the day a
promotion outruns whoever picked the constant. Absence cannot be compared by
accident, and it forces the rule to be written out as `IS NULL` in the one
statement that matters.

A three-way choice field — once / limited / unlimited — plus a number was the
alternative and is worse. The shape's variant field is already the discount kind,
so a second choice could not hide the number the way variants hide fields, and
somebody could pick "unlimited" with 5 still in the box beside it: two controls
that can disagree, to say what one empty box says.

The floor is 1. Zero redemptions is not a voucher, it is a voucher somebody
switched off, and the dates are how that is said.

#### The counter, which is the engineering

**A redemption is an allocation.** Taking the last use of a voucher is the same
act as taking the next invoice number: a shared counter moves, exactly one caller
may have each value, and two callers arriving in the same millisecond must not
both be told yes. §5.10 solved that once and this is the same solution with a
ceiling on it.

The bug is the textbook one and needs no unusual conditions. Read the count,
compare it to the limit in PHP, write it back: under READ COMMITTED two checkouts
both read "4 of 5 used", both find room, and both write 5. A voucher good for five
orders has been used six times, and the sixth is money given away. Two people
checking out at once is the entire reproduction.

**Where the count lives: a table of its own, `voucher_redemption`, one row per
voucher, unique on `voucher_id`.** Not a field on the record, and this is the
decision worth reading. A record is written by `RecordWriter` as one unit of work
(§5.2) — the whole `data` document replaced by a single `UPDATE`, with a history
entry beside it — so two redemptions through that path are two whole-document
writes and the second overwrites the first's count with a number it read before
the first happened. The same race, wearing the engine's clothes, and with no
`WHERE` available to put the limit in because the statement is about a document
rather than about a counter.

Three more things follow, and all three are reasons rather than consequences:

- **It can carry the guard.** `ON CONFLICT … DO UPDATE … WHERE` needs a conflict
  target and a column to compare — a row that is *about the count*. A JSONB
  document has neither.
- **It is not the customer's field.** A redemption count is engine bookkeeping,
  like `position` on a collection row (§5.1) and like `number_sequence`. Nobody
  should be able to rename it, delete it in the metadata editor, type over it in
  a form or import a spreadsheet that zeroes it — and every one of those is
  possible for a field, because a field is theirs.
- **It does not stamp the voucher as edited.** Redeeming is not a change to the
  voucher. Through the record writer it would bump `updated_at` and write a
  history entry on every checkout, which is [XIV-91]'s argument about the
  numbering backfill on a hotter path.

Reusing `number_sequence` itself — shape `voucher`, field `redemptions`, period =
the record id — was considered and rejected. The table is already in every tenant
with the right index and the ergonomics are nearly free, but its column is called
`next_value` and its rows mean "what this counter will give out next". A row
there meaning "how many times this has been used" would be legible only to
whoever wrote it, and `period` would be holding a record id in a column
documented as a year. One table, one meaning.

The table is created by a **tenant migration**, so every customer has it whether
or not they install the module. An empty two-column table is a cheaper thing to
own than a new engine concept — "a module may declare a side table" — invented so
that one module can avoid it; `number_sequence` is in every tenant on the same
terms. There is no foreign key to `voucher` and there cannot be one: a module's
record table is created per customer by the installer, so at migration time the
table it would point at does not exist in most databases and may never exist in
some. A counter row can therefore outlive the voucher it counted, which is the
same thing soft deletion already does to everything else.

**The statement, in full:**

```sql
INSERT INTO voucher_redemption (voucher_id, redeemed_count)
SELECT CAST(:voucher AS INT), 1
WHERE CAST(:limit AS INT) IS NULL OR CAST(:limit AS INT) >= 1
ON CONFLICT (voucher_id)
DO UPDATE SET redeemed_count = voucher_redemption.redeemed_count + 1
WHERE CAST(:limit AS INT) IS NULL
   OR voucher_redemption.redeemed_count < CAST(:limit AS INT)
RETURNING redeemed_count
```

The first caller for a voucher inserts the row; every caller after that collides
with the unique index, is turned into an update, and waits on the lock Postgres
has already taken. There is no `SELECT FOR UPDATE`, no advisory lock, no retry
loop and — critically — no window between the check and the write. When the limit
is reached the `WHERE` fails, no row comes back, and that absence *is* the
refusal, exactly as it is for [XIV-27]'s counter wind-forward.

**`SELECT … WHERE` in place of `VALUES` is not decoration.** Written with
`VALUES` the insert branch is unguarded, so a voucher whose limit is zero would
be refused on every redemption *except the first* — the one with no row to
conflict with. One statement with one rule beats two rules that agree until they
meet the edge.

**The limit is passed into the statement rather than compared outside it**, and
that is not the race it looks like. What is raced is the count; the limit is a
property of the voucher's own definition, changed only by an administrative edit,
so reading it off the record and putting it in the `WHERE` is safe against every
concurrent *redemption*. Two people editing the limit at the same moment is
last-writer-wins on an administrative act, which is what it is everywhere else in
this system.

**Inside the caller's transaction**, like `NumberAllocator`: a checkout that
fails after redeeming gives the redemption back, because the lock and the
increment both belong to the transaction that failed. Nothing has to remember to
undo anything. The cost is a row lock held until that transaction ends, so two
orders redeeming one voucher take turns — for a row touched once per checkout,
the right way round.

#### Proving it, and what a single-process test cannot prove

`VoucherRedemptionRaceTest` is [XIV-109]'s `UniqueValueRaceTest` reused rather
than reinvented, because the two tickets are the same bug in two places. It
carries `#[SkipDatabaseRollback]`, provisions a customer of its own, commits what
it writes and takes the customer away at the end — a race cannot be tested inside
DAMA's transaction, because two connections that are the same connection cannot
conflict and two writes nobody commits cannot be seen. Every statement goes
through the production class on a connection of its own, so what is under test is
the application rather than a copy of its SQL.

The interleaving is performed, one statement at a time: both connections read the
count and **both are told there is room** (a race whose first half does not happen
is not the race); the first redeems and does not commit; the second redeems and
**blocks**, proved with `lock_timeout` rather than assumed; the first commits and
the second is refused. Both endings are checked — the winner commits and the loser
is refused, and the winner rolls back and the loser is let through, because a
checkout that never happened must not consume a use.

**What that cannot prove, said plainly.** Every one of those assertions would also
pass against a version that read the count in PHP, compared it and wrote it back,
because a single-threaded test cannot get between two statements another process
is running and that version's window is between two statements of its own. What
*can* be checked exactly is that there is no second statement for a window to be
between: DBAL's own logging middleware records what the driver executes, and
`testARedemptionIsExactlyOneStatement` asserts there is one, carrying both the
`ON CONFLICT` and the `WHERE`. It was verified the way round that matters — the
guard was temporarily rewritten as a read, a comparison and an update, and that
is the only test in the file that noticed.

#### Validity: expiry is a read

`valid_from` and `valid_until`, both optional, and **expired is not a stored
state**. This is [XIV-67]'s argument about overdue invoices applied without a word
changed: every state a record has is something a person performs, and *nothing
performs expiry — the calendar does*. A stored flag would need a job mutating
customers' records on a schedule with no human act behind it, and there is no
worker process here; it would also be wrong between midnight and whenever that job
next ran, which is exactly the window in which somebody redeems the voucher it
was meant to have closed.

An empty date is not a boundary, in both directions: no `valid_from` means it has
always been good, no `valid_until` means it never stops. Reading an absent date as
a passed one would expire every voucher created without filling the field in,
silently, at the till.

Both ends are inclusive — a voucher good until the 31st is good on the 31st — the
same rule §5.16 keeps about an invoice falling due today.

`VoucherValidity` expresses it twice from one declaration, as `Overdue` does: a
question about a record in hand, and query conditions for a list. **There is
deliberately no `validFilters()`.** "Currently valid" is
`(from IS NULL OR from <= today) AND (until IS NULL OR until >= today)` — a
conjunction of two disjunctions — and §7 question 3 records that `OR` between
conditions is one of the two things the query layer still cannot express. Expired
and not-yet-started are each a single condition and compile fine, which is why
those exist and the more useful third does not. Faking it by ANDing
`until >= today` alone would quietly drop every voucher with no end date, which is
most of them.

#### Deliberately not in this

**Applying a voucher to anything** ([XIV-104]). The seam between the two tickets
is one method call, and the shape of the discount is already declared: an amount,
a percentage, or an article and a quantity. *Answered by §5.24*, which took the
prediction literally: `Money\DocumentDiscounts` is one method, and the three kinds
collapse into one sentence — every one of them is a line. *And by §5.25*, which
found that sentence governs one of two modes and that the other reduces the line
it is applied to — through the same one method, which is the part of the
prediction that mattered.

**Module pricing** ([XIV-101], [XIV-102]) is a different feature that happens to
also involve money. A voucher against a module purchase is not this and has not
been designed into it: these vouchers are the customer's, in the customer's own
database, about the customer's own sales.

---

### 5.20 A unit belongs to the article (XIV-118)

An order line saying `2.5` is a line the customer has to ring up about. One
saying `2.5 hours` — or `0.75 kg` — is a line they can check, and for anything
sold by time or by weight that difference is what makes the price defensible.

§5.1 has said since XIV-22 that a unit belongs to the *article* rather than to
the line. That was right and it was half a decision: the article module declared
a title, a description, a price and a VAT rate and no unit at all, so the
sentence pointed at a place that did not exist. This is the other half.

**The unit is a field on the article, and the line takes a copy.** Ownership and
rendering are different questions and they get different answers: a desk is sold
by the piece on every order it will ever appear on, so the fact lives on the
article — and a line still has to *print* it, which it does through the
inheritance XIV-18 already built for the title and the price (§5.1). Not a second
path, and everything that mechanism already does comes with it: an order placed
in hours still says hours after the catalogue is re-priced by the day, a deleted
article does not empty the line, and the drift marker on the record page watches
the unit exactly as it watches the price.

On the **invoice** it arrives by the seed (§5.12) instead, and that is this
project following itself rather than disagreeing with itself: *nothing* on an
invoice line is read through the article, because an invoice quotes what was
agreed on the day. A unit read live from the catalogue would be the one field on
a sent document that kept moving.

#### Where the list comes from

Three shapes were on the table and §6.1 decides between them.

- **A `choice` field the customer fills in themselves** is the cheapest and gives
  a new customer nothing at all on their first day, and gives every installation
  its own spelling of *hour / hours / Std. / h*.
- **A managed list** — a small table of units, maintained on a screen of its own,
  referenced by articles — is consistent within a tenant, and it is a screen. For
  seven words that is a screen to find, learn and keep. Worse, it would be a
  second half-answer to a question [XIV-127] is asking properly: a list a customer
  maintains **once** and several fields across several modules point at, with
  colour, hierarchy and a merge. Units are one instance of that question rather
  than a special case of it, and a table built here would be a third of that
  feature, built early and then unbuilt.
- **A shipped set, seeded like everything else**, which is what §6.1 already
  describes a blueprint as doing. Seven values — hours, days, pieces, kg, m, m²,
  litres — written into the customer's own definitions when the module is
  installed, translated into their language on the way in like every other label,
  and theirs from that moment.

**The third**, because it is the only one that gives a new customer something
sensible on day one.

**They can add "pallet" now** ([XIV-144]). This was the honest limit here for as
long as this field has existed: the metadata editor drew no control for a choice
field's options, so the shipped seven were the seven. It was closed as the defect
it always was rather than as a feature — the editor *offered* the `choice` type
and could not configure it, which §5.4 has the argument for — and it was closed
without being closed unit-shaped, which was the condition this section put on
whoever got there. **Every variant field and every lifecycle's status field is a
`choice` field too**, and their options are load-bearing: so a module's own
field's options may be **added to and renamed, never removed**. Nobody deletes
`confirmed` from a table cell; a wholesaler adds "pallet" to the seven. Which
options a module itself named is not recorded anywhere, which is why the refusal
covers all of them and why per-option provenance is still [XIV-127]'s to model.

*Met again by §5.22 ([XIV-132]), and that is what closed it.* The knowledge
module's topics ran into this same wall from a second direction — a workshop that
wants "machine" could not have it — and two modules hitting one gap was the
argument for closing it once rather than a second time by hand. It was closed in
§5.4 rather than in [XIV-127]: what those two customers needed was a control on
the field they already had, and a shared list is still the right home for "our
units" when it arrives.

#### The values are keys; the labels are the customer's

The **value** is what every record holds and what an inherited copy is compared
against, so it is a stable ASCII key — `m2`, never `m²`. The **label** is what a
document prints and what the customer may rename.

That split is why the seven live in one place in core
(`Xivi\Core\Field\Units`) rather than being written out three times. The
article's field, the order line's and the invoice line's must agree on the
*values* or an inherited `hour` renders as the word "hour" on somebody's invoice
— the line's field is a `choice` of the same list precisely so that it can turn
the key back into the customer's word. Modules may not depend on each other (§3),
so core is the only place all three can share, and it is the same shape
`LineTotals`, `Seed` and `InheritedValue` already take: a declaration core owns
and modules spread into their own options. The *labels* stay per module, one
`unit:` block per catalogue, because a module that borrowed another's vocabulary
would be a module that cannot be installed on its own.

#### Plurals: no, and here is why that is a decision

"1 hour" and "2 hours" are different words, and so are "Stunde" and "Stunden".
The ICU catalogues in this project handle exactly that — **for sentences the
engine says**. A unit label is not one of those.

A choice field's labels stop being catalogue keys the moment the module is
installed: what is stored in the definition is text, in the tenant's language,
which the customer may rename to anything at all. There is no key left to look a
plural form up under. A customer's own "Palette" would have none either, so
pluralising the seven shipped ones would produce a document where some units
agreed with their number and some did not — which is worse than one where none
do.

So **a unit is a short, invariant label**, written in the form a line usually
needs: the plural where the word has one, because a quantity of exactly one is
the exception on an invoice and `2.5 hour` is a worse error than `1 hours`. Most
of the list settles the question by itself — `kg`, `m`, `m²` have no plural in
any language this ships in, and German's "Stück" and "Liter" have none either.

#### The two lines that have no article

**A custom line gets the same field and somebody types into it.** That is a
decision and not the default: a custom line is priced by hand with nothing to
inherit from, and it *also* carries a quantity — so leaving the unit off it would
recreate `2.5` of nothing on the one kind of line where every other value is
being typed anyway. It is offered the same seven, so a hand-written line and an
article line read alike on the document.

**Comment and subtotal lines are not offered one**, because they have no quantity
for a unit to qualify. That falls out of the variants (§5.5) rather than being
written anywhere.

#### An article that has no unit

Optional, and that is load-bearing rather than lenient. Every article that
existed before this field did has no unit, and a line for one has to read exactly
as it read the day before: a quantity, and nothing after it. A required unit
would have made the field a migration of somebody's catalogue instead of an
addition to it — and installing still retro-fits nobody (§6.1), so an existing
customer's articles gain the field only when they take it from the offer §7.2.1
makes.

That offer has one visible cost and it is the rule working rather than failing.
The blueprint made room for the unit by narrowing the line's description — the
form row is a twelfths grid (§5.1) and thirteen twelfths wrap — but an upgrade
only ever *adds*, and changing the width of a field somebody already has is
exactly the retro-fit §7.2.1 refuses to do. So a customer who takes the unit onto
their order lines gets it on a line of its own until they narrow the description
themselves, which is one number in a box in the field editor. The alternative was
an upgrade that quietly re-laid-out a form somebody had arranged, which is worse
than a form that wraps.

The case is also deliberately in the demo data: `null` sits among the samples, so
a generated tenant contains articles sold as themselves — a yearly maintenance
fee — beside ones sold by the hour.

#### Deliberately not in this

**Conversion.** Buying by the kilo and selling by the gram needs a factor, a
direction and a rounding rule per pair of units, and it changes what a price
*means*. It is a genuinely larger feature and nothing here implies it: a unit is
a label beside a number.

---

### 5.21 A field with formatting in it (XIV-131)

The longest thing a record could hold was a `textarea`, which is plain text. No
headings, no lists, no emphasis, no links. That is right for a note and wrong for
a procedure, for an article description that goes on a document, and for the
knowledge-base entry [XIV-132] is waiting on.

**The answer is Markdown, and the reason is that the dangerous half was already
built.** [XIV-38] and [XIV-62] put Markdown into email, and the valuable part of
that work is not the rendering — CommonMark is a library and turning text into
HTML is a line of code. The valuable part is a *safety property*: substitution
happens on the Markdown **source**, before anything is parsed, with
`html_input: escape`, so a record value containing a script tag becomes text
**without anybody remembering to make it so**. A sanitizer sits behind that as a
second layer, and link schemes are confined to http, https and mailto.

A rich-text editor storing HTML is the alternative and it loses on exactly that.
A value that is already markup arrives on the far side of the escaping decision,
leaving the sanitizer as the only thing between one customer's data and the
markup of a page — which is the trade §5.13.1 refused when it insisted a
collection expand to Markdown rather than to HTML. It also costs a dependency;
`league/commonmark` is installed, and nothing was added for this.

#### A new type, not an option on `textarea` — and [XIV-113] should follow this

The question is real and was close. An option means every existing textarea keeps
working and a customer ticks a box; a separate type means a reader knows what a
field holds from the type alone. It went to the separate type, `markdown`, for
three reasons.

**The precedent is one file away and went the same way.** `TextareaFieldType`
exists rather than being an option on `text`, and its own docblock says why:
*everything that follows from the length differs* — the widget, the default
maximum, the operators worth offering. Everything that follows from *formatting*
differs at least as much. The widget gains a preview. The record page draws a
block instead of a value on a line. A Word document is given something different
from what the page shows. A list cell is given something different again. That is
four divergences, which is not a flag on a type; it is a type.

**Whether a value is markup-bearing has to be readable from the type.**
`$type instanceof HoldsFormattedText` is a question the container answers once.
`$field->getOption('markdown') === true` is a question every caller answers
again, in the display path, the document path, the export path and the form path
— and two answers is how one of them ends up unescaped. This is the same argument
the section below makes about there being one converter, applied one level up:
the property being defended is that "text somebody typed" and "markup" stay
distinguishable *by construction*, and a boolean in a JSON options blob is not a
construction, it is a convention.

**A checkbox is retroactive and a type cannot be.** Ticking it reinterprets every
value already stored in that field, at once. A parts list typed with `*` bullets
and `_snake_case_` product codes changes meaning in every record, with no
migration, no history entry — §5.2 records *changes*, and nothing changed — and
nothing on any screen to say it happened. Choosing a type when the field is
created cannot do that, which is why "an existing `textarea` field is unaffected"
is a property of the design here rather than something a test had to defend.

The cost is accepted and is real: **there is no path from an existing `textarea`
to this.** A customer who wants their notes formatted has to add a field and move
the text. That is a conversion of stored data and belongs in §7.2's territory as
an explicit operation with a screen and a confirmation, not as a checkbox that
silently reinterprets what somebody already wrote.

**[XIV-113] weighs the identical question for references and should follow this
answer rather than reaching its own.** It is much larger and unbuilt, which makes
it the wrong place to decide a convention and the right place to inherit one; and
every reason above is *stronger* there, not weaker. A `multiple` option on
`reference` would change the **storage shape** of the value — an integer becomes
a list of integers — so the retroactivity argument stops being about how a string
reads and becomes about whether the stored value can be read at all. If a case
ever does justify an option where this justified a type, it will be a case where
the option changes neither what the value *is* nor how it must be escaped, and
the ticket that finds one should say so here.

#### One converter, configured in one place

`EmailRenderer` built its own `MarkdownConverter` in its constructor, which was
right while it was the only thing that had one. A second caller makes that
configuration a policy, so it moved whole into `Xivi\Core\Markdown\MarkdownRenderer`
and both callers are handed the same object.

**Two converters with two configurations is how one of them ends up unescaped**,
and the failure is quiet: somebody tightens what a link may point at, tightens it
in the one they were looking at, and the other stays open for a year with nothing
going red. There is now one `Environment`, one `MarkdownConverter` and one
sanitizer, so a change to what is permitted cannot apply to email and not to a
record page.

**The sanitizer policy was renamed rather than duplicated** — `email` became
`markdown` in `config/packages/html_sanitizer.yaml` — and it is deliberately the
*strictest* caller's rather than the union of what both would accept. Two of its
rules were written about email: relative links are dropped because a message has
no base URL, and `data:` media are dropped because a data URI is how an image
gets past a mail client's remote-content warning. Neither costs a record page
anything worth having. A relative link typed into a field would resolve against
whichever record it was read on, which is not something anybody means, and an
image in a field is [XIV-115]'s question. **A policy that relaxes for the newer
caller is two policies with one name**, which is the thing the extraction exists
to prevent.

#### The editor is a textarea and a preview

A toolbar means a JavaScript editor, and [XIV-33] settled the front end on Live
Components precisely so that the interactive parts of this system are
server-rendered — while the documentation promises a customer's browser makes no
CDN calls. So the control is the text, and the honesty is the preview underneath
it.

**The preview costs nothing, and that is not luck.** The record form already
carries `data-model` on the form element because [XIV-32]'s totals had to follow
somebody typing into a quantity box, so every keystroke already round-trips and
re-renders. A preview block inside the field's own widget therefore follows the
typing without a line of JavaScript being written for it. It is a form theme
block hung off the form type's prefix, which is the only way to give one kind of
field a different appearance when nothing renders fields one at a time —
`RecordForm` calls `form_widget(form.fields)` once and knows nothing about what
is in it, which is the §5 claim doing its job.

A toolbar is a later question if anybody asks. Nothing here forecloses it.

#### What a value is worth in each of the places it goes

A field's value goes to more places than a form, and leaving three of them to
emerge from whichever function happened to be nearest is how two screens end up
telling a reader different things about the same record. Each was decided.

- **The record page** gets the **rendered markup**. This is the only place in the
  application where a record's own value reaches a page as markup rather than as
  text, and it is safe for one specific reason rather than by habit: it was
  parsed with raw HTML escaped and then sanitized, by the same object an email
  goes through. It takes the whole row rather than a quarter of one, because a
  heading and a list drawn in a narrow column wrap to two words a line and the
  formatting that is the point of the field becomes unreadable.
- **A document** (§5.7) gets **the words with the marks taken off** —
  `Warning: do not…`, not `**Warning:** do not…`. A .docx is not HTML, so the
  formatting cannot survive the trip whatever is decided; given that, the only
  question left is whether the punctuation travels with it, and punctuation
  printed on a customer's invoice is punctuation nobody meant to send. *"The
  source, as typed"* is the defensible alternative and it was rejected on that
  one sentence.
- **A list column** gets the same, for the same reason arriving from a different
  direction: a cell has one line and no room for a block, so a cell reading
  `**bold**` is strictly worse than one reading `bold`. A collection's rows on
  the record page are a table too and get the same treatment.
- **An export** (§5.6) gets **the source, untouched**, and needed no decision in
  code because the exporter already works in storage form. It is still a decision
  and is written down here: an export has to be importable, so it carries what
  was stored rather than a rendering of it. That is also the one place a customer
  can get their formatting back out intact.
- **A filter and a search** match **the source**. Searching for `Warning` finds a
  record whose text says `**Warning:**` because `contains` runs on the stored
  string; searching for `**` finds every record with emphasis in it. Matching the
  rendered words instead would mean rendering every row on every query, or
  keeping a second derived copy of every value to search against, and neither
  buys anything worth having.

**The plain rendering asks the parser, not the string.** Stripping `*` and `#`
with a regular expression would be a second and worse implementation of a grammar
already in the room, and it would disagree with the rendered half the first time
somebody typed a literal asterisk. It is also **not** "the HTML with the tags
taken out": that would mean un-escaping entities afterwards to get readable text
back, and a pipeline that escapes and then un-escapes is one refactor away from
handing markup to a caller that trusted it. What it does instead is walk the
parsed document and read the literals off it, so the markup is never built at
all — which is asserted by giving the renderer a sanitizer that throws.

#### Deliberately not in this

**Images and file embeds**, which are [XIV-115]. The sanitizer policy already
refuses `data:` and relative sources, so nothing here quietly half-supports them.

**Tables beyond what `TableExtension` already gives**, and no other CommonMark
extension either. The grammar is `CommonMarkCoreExtension` plus tables, named
individually rather than taken as the GitHub-flavoured bundle — the smaller the
grammar somebody writes against, the fewer ways their text surprises them, and
every addition is a new shape of markup the sanitizer's policy would have to have
an opinion about.

**Collaborative editing**, of any kind.

**A module blueprint that declares one.** The engine has the type and the metadata
editor offers it; no shipped module changed its own fields to use it, because
installing does not retro-fit (§6.1) and a blueprint change would have meant new
tenants and existing ones disagreeing about what an article description is for no
gain this ticket needed.

*Answered by §5.22 ([XIV-132]).* One does now, and it is a new module rather than
a changed one — which is the same rule arriving from the other side. Nothing
retro-fitted, nobody's article description changed, and the first blueprint to
declare a `markdown` field is one whose customers are choosing it by installing
it.

---

### 5.22 An internal knowledge base, and how much of it was already here (XIV-132)

Every business runs on knowledge that lives in one person's head. *How do we
handle a refund past thirty days? Which supplier when the usual one is out? What
did we agree with this customer in 2023?* When that person is on holiday nobody
else can answer, because the answer has never had anywhere to live.

So: a module where experienced staff write entries and everybody else reads them.
**A very simple wiki, and the emphasis is on simple.**

This section is short on purpose, and its shortness is the finding.

#### The engine work this needed was none

An entry is a record with a title and a body. That makes this ticket a test of
the claim §1 has been making since the first module — *the engine describes
modules, it was not built around one* — and the test came back clean in a way the
earlier modules could not demonstrate. Contact proved a module could be
described. Article brought two field types. Order and invoice brought line
totals, seeding, numbering and payment terms. Voucher brought a field type and a
counter. **This one brought a blueprint, a translation file and a bundle**, and
`packages/knowledge` contains nothing else: no controller, no entity, no form
type, no template, no service, no migration, no field type.

Four things a knowledge base needs that were already there, and none of them was
asked for:

- **Who wrote it and who changed it** is §5.2's record history, on every record
  of every module, plus the `owner_id`, `created_at` and `updated_at` the
  installer puts on every module's table. The module therefore declares **no**
  `author` field and no `written_on` field, and their absence is the decision
  rather than an omission: a date field is a date somebody has to remember to
  set, and one they forgot is a record that is confidently wrong about itself.
- **Write versus read** is the per-module permission axis (§8.4), which already
  splits `add` and `edit` from `view` and `list`. No new permission concept, no
  "editor" role, nothing seeded at install.
- **Searching** is `Operator::Contains` over a field flagged `filterable`, which
  compiles to a case-insensitive `ILIKE` (§5.3). The whole feature is one boolean
  in the declaration. Its ceiling is real and is written down two headings below.
- **A formatted body** is [XIV-131]'s `markdown` type, merged the day before
  this one and naming a knowledge-base entry in its own docblock as the thing it
  was for. §5.21 closed by saying no shipped module declared one; this is the
  module that does, and it is a *new* module for exactly the reason that section
  gives — nothing about anybody's existing data changed.

One thing was added outside the package and it is a shared template rather than
the engine: the module list grew a **Changed** column, argued under *staleness*
below. That is the honest total.

#### Categorising: a plain `choice`, and a note for [XIV-127]

Six topics — process, policy, customer, supplier, product, other — as an ordinary
`choice` field, seeded into the customer's definitions at install like every
other label (§6.1). The stored value is `supplier` for ever; what is shown is a
row in their database from the moment they install it.

**[XIV-127] is the right answer and is unbuilt.** It proposes shared lists a
customer maintains once and uses across modules, which is where "our topics"
belongs — next to "our units" (§5.20) and "our payment terms" (§5.16). The choice
in front of this ticket was therefore between a plain choice field and building
half of [XIV-127] inside one module, and half of it is the worse option by some
distance: a half-shared list is a second mechanism [XIV-127] would have to
migrate customers off, and the customer would be the one who met the migration.

A choice field costs nothing to give up when it lands. The stored values are
strings, a shared list will also store strings, and the field's *type* changes
while the values do not — the cheapest thing §7.2 has to do. **This module is
recorded here as [XIV-127]'s first consumer**, so that whoever builds it has a
caller to design against rather than a hypothesis.

**The honest limit was §5.20's, word for word, arriving at a second module — and
finding it twice is what closed it** ([XIV-144]). A customer can add a seventh
topic: the field editor draws a control for a choice field's options now, on this
module's own `topic` field like any other. What it will not do is take one of the
six away, because this field came with the module and §5.4 refuses that for every
module's own field. `other` therefore stays useful for the reason it was put
there — it is what somebody files an entry under while they are deciding whether
they want a topic of their own — and it is no longer the difference between a gap
and a wall.

Deliberately **not required**. Somebody writing down what they know at half past
five should not be stopped by a dropdown they have no opinion about, and an entry
filed under nothing still answers the question it was written to answer.

#### Linking: no, and "no" is the whole decision

§7.6's references would do it, and a reference into a module the customer has not
installed matches nothing and reads harmlessly (§5.19) — so *"this entry is about
the invoice module"* or *"about this customer"* would have been safe to build.
It was still refused for the first slice.

The reason is that a link has to earn its way in from **both** ends, and only one
end was on offer. Pointing an entry at a contact costs a field and buys a filter.
Reading it back from the *contact's* page is the half people actually want —
"what do we know about this customer" — and that is §7.6's linked-records panel,
which would then put a knowledge card on every contact, article and invoice page
in the system. That is a much larger change than a `reference` field, and it is
one nobody asked for.

The consequence is worth having on its own: this module declares no `requires`,
no `uses` and no `reference`, which makes it **the first module that installs
into a completely empty tenant**. Somebody who signed up an hour ago can write
down what they know before they have a single contact.

If linking is ever wanted, an entry gaining a `reference` field is additive and
retro-fits nobody (§6.1, §7.2.1). Nothing here forecloses it.

#### Keeping it current: showing the age, not scheduling a review

**A knowledge base's failure mode is not being empty — it is being confidently
wrong.** An entry written in 2023 describing a process that changed in 2024,
which somebody reads and follows. Empty is obvious and harmless; stale looks
exactly like current.

A review date is the machinery this invites and it was refused. It is a field
somebody has to set, a second one somebody has to answer, and a notification
somewhere for when it passes — and an entry whose review date has lapsed is
still, on the page, indistinguishable from one that has not. What actually
defends against the failure is that **the age is on the screen next to the
entry**, and §5.2 has recorded it all along.

The record page already showed *Created* and *Changed* in its right-hand card.
That is the right place to find the answer and the wrong place to *notice* it: by
the time somebody is reading the page they have decided this is what they came
for. So the module list grew a **Changed** column, beside the *Owner* column that
has been there since the list existed.

**Both are system columns and that is the argument.** `owner_id` and `updated_at`
are written by the engine on every record of every module, neither is a field
anybody declared, and drawing the second next to the first is completing a pair
rather than introducing an idea. Neither sorts, for the same reason: a
`RecordQuery` orders on the customer's own definitions and these are not among
them. The date shows without the time, because a list is scanned rather than
read.

It lands on **every** module's list, which is deliberate rather than collateral.
"Which of these did somebody touch today" is asked of a list of orders as often
as of a list of entries, and a column the engine can fill for nothing on every
module is not a knowledge-base feature that leaked out — it is the generic thing
this ticket happened to be the first to need.

#### The search ceiling, stated rather than discovered

`contains` is `ILIKE '%word%'`. What that gives is case-insensitive substring
matching over the stored source (§5.21 decided that it matches the source rather
than the rendering). What it is **not**:

- **No stemming.** "Lieferanten" does not find "Lieferant"; the substring runs
  one way only.
- **No ranking.** Ten matches come back in whatever order the list is sorted by,
  not best first, so the most relevant entry is wherever the alphabet puts it.
- **No phrases or proximity** beyond the literal substring.
- **No index.** The query cannot use an ordinary btree, so the cost grows with
  the number of entries.

At a few dozen entries nobody can tell the difference. At a few thousand somebody
will want the difference badly, and giving it to them means `tsvector`, a GIN
index and a field type in the engine that knows about both — which is a ticket,
not a paragraph. **It is deliberately not a reason to hold this back**: a
knowledge base with substring search in it beats one that does not exist, and the
upgrade is invisible to the data because the stored value does not change.

`KnowledgeModuleTest` asserts the ceiling as well as the feature — the plural
failing to find the singular is a test rather than a sentence — so the day
somebody builds full text there is a red line pointing at exactly what changed.

#### Who may write, and what the default is

The permission axis covers this with nothing added. What was worth deciding is
the **default**, and there were two candidates: everybody who can read can write,
or writing is granted deliberately.

**Writing is granted deliberately.** For knowledge people will *act* on, an entry
somebody wrote in passing and got wrong is worse than an entry nobody wrote, and
"who may put something in here" is a question a business should have answered on
purpose. It can be relaxed by a grant on the day a customer decides otherwise;
the other direction — noticing afterwards that everybody has been editing the
refund policy — cannot be undone by a setting.

**And it needed nothing built, because §8.4's platform default is already deny.**
Nothing is granted at install, so a customer who does nothing gets exactly this.
`view` and `list` on Knowledge make a reader; `add` and `edit` make a writer.

#### What this must not become

**Not a wiki.** No page trees, no `[[cross-link]]` syntax, no namespaces, no
revisions-with-diffs beyond what §5.2 already records. Each of those is what
turns a wiki into a product somebody has to administer; this is a list of entries
with a search box.

**Not customer-facing**, and the declaration keeps that rather than anybody's
care. Nothing here is published, shared with a contact or attached to a document.
The module names no contact and declares no `mailRecipient`, so §5.14's *send
this record* path has nothing to resolve an address through and the button is not
drawn — an entry cannot be mailed to somebody by accident because there is
nowhere for the address to come from. If publishing a subset is ever wanted it is
a different feature with a different security argument, and it should arrive as
one.

---

### 5.23 A phone number is one number (XIV-114)

Phone numbers were typed into `text` fields, so `+41 79 123 45 67`, `0791234567`
and `079 123 45 67` were three different values for one number. A search found
one of them, a duplicate check found none of them, an export was whatever each
person had happened to type, and nothing downstream could ever have rung any of
it. `phone` is a field type now: whatever is typed is stored as **E.164**
(`+41791234567`), and what cannot be read is refused with a sentence rather than
kept as a string.

**`toStorage()` is the seam, which is §5.19's argument one step harder.** The
voucher code folds case there because `RecordValidator` normalises before it
validates, `RecordRepository` before it writes and `QueryCompiler` before it
compares — one method, and the form, the spreadsheet import, the unique index and
every future lookup agree without any of them being told. A phone number is the
same shape of rule about the same kind of value, so it lives in the same place.
The property worth stating as a property: **the form, the importer and the query
compiler cannot disagree about what a phone number is, because none of them has
an opinion.** `PhoneNumberTest` proves that by going in one door and out another
— a number typed `079 123 45 67` into the record form is found by a filter typed
`+41 79 123 45 67` in the URL of the list page — rather than by calling
`toStorage()` and asserting what comes back, which would test the method and say
nothing about the seam.

Three consequences follow, and each is taken rather than discovered:

- **`unique` starts working.** [XIV-109]'s index is over the stored string, so
  two people entering one number differently now collide as they always should
  have. Proved against the index rather than against a PHP comparison: the test
  writes both spellings through `RecordWriter`, which validates nothing, and what
  refuses the second is Postgres.
- **An import of existing data will refuse rows.** Ten years of hand-typed
  numbers contain some that are not numbers. That is correct and it is still a
  surprise, so the refusal names the value *and* the country it was read against
  — `"079 123 45" is not a phone number that can be dialled in Switzerland` sends
  somebody to the right place, where "row 2 is invalid" does not.
- **Google's metadata moves, so validity moves with it.** `isValidNumber()` is a
  question about a table in the package rather than about arithmetic, and
  countries open and retire ranges. A `composer update` can therefore change
  whether a number is acceptable — in both directions. Nothing revalidates on
  read, deliberately: a stored number is a fact about a customer (§5.9) and a
  library update is not a reason to stop showing somebody their own data. What it
  does mean is that the same spreadsheet can import cleanly on one release and be
  refused on the next, which is a thing to know before it happens rather than
  after.

**The country comes from the chain that already exists, and that is the decision
this ticket is mostly about.** `079 123 45 67` is only a number if you know where
it was dialled; the same digits are a valid Swiss mobile and a valid German
landline. §8.6 gives an installation a region, [XIV-50] built the chain that
reads it and [XIV-83] extended the same shape to the timezone — so a fourth
country setting was the thing to avoid, and none was added.
`Xivi\Core\Region\InstanceRegion` is the fourth instance of the seam
`InstanceCurrency`, `DefaultPaymentTerms` and `DefaultVatMode` keep, and
`ProfileRegion` answers it by **delegating to
`FormattingLocale::instanceRegion()`** rather than reading the profile a second
time: a fourth *reader* of one setting is the same mistake in cheaper clothes.

**The person is deliberately not in that chain, and display is where they come
back.** `FormattingLocale::of()` starts with the reader's own region; parsing
starts one link down, because how a number is *shown* is about who is looking and
how it is *stored* must not be. A French colleague at a Swiss company typing a
local number is typing a Swiss number, and a chain that asked who was looking
would store `+33…` for them and `+41…` for everybody else — the same digits
becoming two different customers depending on whose screen they were entered
from. Display then takes the opposite rule: national where the number is local to
the reader, international where it is not, read off `\Locale::getDefault()`,
which is [XIV-50]'s chain arriving where `DateFieldType` and `CurrencyFieldType`
already collect it. Core still does not know what a user is.

**A per-field override, because it is an option with a default rather than a
setting.** A customer whose `supplier_phone` only ever holds German numbers can
say so on that one field; every other phone field goes on following the profile
and nobody opens the metadata editor to get the common case. It is the **third**
entry in §5.4's declared option-to-capability list, and the first added since
that list stopped being a pair of `instanceof`s — which is the evidence that the
list was worth declaring. It cost one capability interface (`AssumesACountry`),
one line in `FieldController::PER_TYPE` and one `<select>` in the field table.
No branch was added anywhere. Changing it decides how the *next* value typed into
that field is read and rewrites nothing already stored, which is worth saying out
loud because the tempting reading is the other one.

**Extensions are refused, and the reason is arithmetic rather than taste.**
`+41 44 668 18 00 ext. 12` is a real thing people type, and there were three
options: keep it in the value, give it a second field, or refuse it. E.164 has no
room for an extension and `format(E164)` **drops it silently** — measured, and
asserted in `PhoneFieldTypeTest` so that the day the library changes its mind
something goes red. Keeping it would mean either storing something that is not
E.164, giving up the canonical form the whole type exists for, or filing a
switchboard and the twelve people behind it under one value: on a `unique` field
the twelfth colleague is then refused for a reason nothing on screen can explain.
A second field is the right answer and the customer already has it — the metadata
editor adds one without a deploy (§5.4) — so the refusal says exactly that.

**The dependency is the lite build, and the trade is measured.**
`giggsey/libphonenumber-for-php-lite` against `giggsey/libphonenumber-for-php`:
2.8 MB against 25 MB installed, and the 22 MB is geocoding, carrier lookup,
short-number data and number-to-timezone mapping, none of which this feature
touches. [XIV-96] took the customer image from 7.3 GB to 462 MB, so the full
build would have spent 5% of that image on which carrier owns a prefix. The
argument lives in `packages/core/src/Phone/PhoneNumbers.php` — the file that uses
it — so that whoever is later tempted to swap the requirement meets the reasoning
before the diff. **It is also the first Apache-2.0 dependency in a production
image**, which is compatible with this project's MIT and is not MIT: it carries a
notice requirement and an express patent grant, and `THIRD-PARTY-NOTICES.md` has
a section shaped for that now rather than a bullet in a list of exceptions.

**Contact's `phone` becomes one, and nobody's database moves.** The blueprint
declares the new type and marks the field filterable, because a filter over a
canonical value is a filter that finds things. A tenant that installed Contact
before this release keeps a text field and goes on keeping it — §6.1, and
`ModuleUpgrade` never offers a key the shape already has, whatever it now looks
like. Changing it for them would be a type change, which §5.4 refuses because
stored values may not survive one; §7.2's open half is still open and this does
not reopen it.

**Deliberately not in this:** nothing is *sent* to a phone number. No SMS, no
verification codes, no click-to-dial. This is a field type.

---

### 5.24 A voucher on an order (XIV-104)

§5.19 made a voucher exist, be valid, and be redeemable. This is the other half:
putting one on an order and changing what the customer owes. The seam between the
two turned out to be one method call in each direction — one to ask what a
voucher is worth, one to take a use of it — and almost everything below is about
where those two calls happen rather than about what they compute.

**Only where both modules are installed.** §6.1 says a customer's own module list
is the truth, and this has to be invisible to a tenant who has vouchers and no
orders, or the reverse. How that is arranged is the last part of this section and
is the one part that needed something new in the engine.

#### The rule that decided most of it

**A discount is a derived value, and derived values are the engine's** (§5.9).
`DerivesTotals` already works an order's totals out as a `ValueDeriver`, inside
the save's transaction, writing into ordinary derived fields. A voucher changes
the total, so it belongs in that path — not in a controller, not in a template,
and never written by hand. Writing derived values by hand is [XIV-73]'s bug: it
produces records that look plausible and are wrong.

Two decisions came with the ticket and are not re-argued here, only followed:

- **The voucher applies before VAT.** It reduces the net, and VAT is computed on
  the reduced net, rather than being deducted from a gross figure.
- **A discount is its own line.** Not a mutation of the lines it discounts, and
  not a field on the header — which is [XIV-16]'s own rule about discounts,
  arriving where it was always going to. **[XIV-122] later gave a voucher a
  *mode*, and this rule turns out to govern one of the two** — the mode where
  there is no line for the money to belong to. A voucher applied to a single line
  reduces that line instead, which is not a departure from this: see §5.25.

Together they unify the three kinds §5.19 declared, and that unification is the
whole reason the implementation is small: **every kind is a line.** An absolute
voucher is a `-10.00` line with nothing to distribute; a relative voucher is the
same line with the amount computed from what the lines came to; a free-article
voucher is a line at quantity N and a price of nothing. Nothing downstream — the
document, the invoice seeded from it, the VAT grouping — has to know which kind
it was.

It settles presentation too. The customer's document shows what they were quoted,
on the lines they were quoted, with the discount stated separately. Nothing
silently reads `1 × Widget @ 100.00 = 90.00`.

`DerivesTotals` needed no apportionment step to make the VAT work: it already
builds the table by grouping lines on `tax_rate`, summing each group's
`line_total` and applying the rate to the group once. A negative line joins that
grouping like any other.

#### Which rate a discount line carries

The sub-question those decisions leave, and the only genuinely open one.

A discount line must have a rate or it falls out of the grouping entirely — and a
discount outside the VAT table means tax computed on undiscounted nets, which
contradicts the first decision. On a single-rate order there is one answer and
one discount line. On a mixed-rate order no single line can carry the right rate,
so it becomes **one discount line per rate present**, each carrying that rate and
its share, pro rata on that rate's own net:

    Discount (8.1%)   −6.67
    Discount (2.6%)   −3.33

The distribution therefore comes back as *lines*, which is better than the
alternative it replaced: it is visible on the document and adds up in front of the
reader instead of inside a deriver.

**Where the remainder lands is decided and written down.** Rounded shares do not
have to add back — ten francs over three rates that sold equal amounts is 3.33
three times, which is 9.99, and a ten-franc voucher that took 9.99 off is a
voucher that lied by a rappen. So the shares before the last are computed and
subtracted, and **the last line takes the balance**. The lines are emitted sorted
by rate, the same order the VAT table is in, so "the last one" is the highest rate
on the document and a reader checking the column meets the odd rappen in the same
place they meet it everywhere else.

That agrees with [XIV-116], which settled the neighbouring question hours before
this one started: *the figure somebody stated is exact and the derived figure
absorbs what is left over.* There the stated figure is a gross price and the
derived one is the tax within a rate; here the stated figure is what the voucher
is worth and the derived ones are its per-rate shares. Neither remainder crosses
a rate boundary in a way that changes what a rate owes: each rate's discount line
joins that rate's own group and is taxed with it.

**Inclusive VAT needed no case of its own**, which is worth saying because it did
not exist when this ticket was written. The mode says how to read the price
column (§5.9, [XIV-116]), and a discount line is in that column like every other
line: on a shelf-priced order the discount comes off the gross, and the net and
the tax follow from it by the same division. A tenth off a gross is a tenth off
the net inside it.

**A voucher worth more than the order is capped by it.** The shares are computed
over the rates that sold something positive, and the discount stops at what they
came to. A negative total is money owed back to a customer, which nothing
downstream is built to hand over — §5.19 caps the percentage at 100 for the same
reason.

#### One deriver, and a seam rather than a second one

The arithmetic could not be a second `ValueDeriver`, and the reason is written
into `ValueDeriver` itself: **order between derivers is unspecified**, on purpose,
because two modules wanting the same field is not an argument the engine settles.
A discount deriver and a totals deriver are not two modules disagreeing, though —
they are two halves of one sum, and they have a strict order in both directions.
The discount lines must be in the grouping before the VAT table is computed from
it, and the *amount* of a relative discount is a fact about what the lines came
to, so it cannot be worked out before they are summed. A second deriver would
have been correct roughly half the time, and the half it was wrong in would store
an order's totals computed without its own discount.

So there stays exactly one deriver for a document's money, and what it does not
know it asks: `Money\DocumentDiscounts` is a one-method seam that core defines and
the voucher package implements. Core's half of the contract is deliberately
narrow — *how much comes off, and which lines to add* — and it contains no
voucher vocabulary at all. Where the money lands, which rate carries which share,
which line absorbs the rappen and whether the discount is capped are all
arithmetic about a document, and §5.9 has one place for that.

**The voucher package finds the order's field rather than being told it.** §3
forbids either package importing the other, and neither needs to: a link between
modules is a `reference` field carrying the *key* of the module it points at
([XIV-13]), and that key is in the customer's own definitions. So "does this
document name a voucher" is answered by reading the shape and looking for a
reference into `voucher` — the same reading the record page does in reverse when
it lists the orders naming a contact. It also means this works for any module a
customer points at vouchers, including one they built themselves in the metadata
editor.

**Three answers, and the third is the interesting one.** A source returns `null`
for *not mine*, an empty `Discount` for *mine and worth nothing today*, and a
discount otherwise. Collapsing the first two would break one case each way: an
invoice carrying discount lines copied down from its order (§5.12) would have them
taken off it by a module that has never heard of vouchers, or a voucher removed
from a draft would leave its discount on the order for ever.

#### The engine owns these lines, and a subtotal was not the precedent

A generated line must not be editable or deletable by hand, or it desynchronises
from the voucher that produced it. `SUBTOTAL_LINE` looked like the precedent, and
**establishing what the editor actually does with a subtotal row is what showed it
was not**: a subtotal's *figure* is derived and the row is the customer's — they
add it from a button, move it by typing a position, and delete it — and the whole
of its protection is that `line_total` is a `derived` field, which the form draws
disabled and the writer recomputes. That protects a *column*. A discount line
needs the *row* protected, because it is a fact about a voucher somebody redeemed
rather than a heading somebody wanted.

Three things do that, and only the first is enforcement:

- **The deriver writes them on every save.** Rows of the generated kind are taken
  out of the submitted set before the sums are computed, and whatever the voucher
  is worth now is written in their place — reusing the ids of the rows it
  replaced, so editing an order does not churn a row per save. A request that
  edits one, deletes one or invents one therefore changes nothing: the next
  derivation states the truth again. That is what `OrderVoucherTest` asserts, and
  it asserts it through the record form's own save action rather than by calling a
  guard.
- **The form draws them disabled.** `CollectionRowType` is told which kind the
  module generates and `RecordType` disables every field of such a row — the same
  mechanism a derived field has used since [XIV-20], one level up. A disabled
  field ignores what is submitted, so this is a second, independent refusal rather
  than decoration.
- **The kind is not offered.** `AvailableVariants` — the one class that answers
  "which kinds can be created here", and which both the form and the kind chooser
  already ask — leaves it out. The kind itself stays an ordinary option on the
  customer's variant field, because rows of it have to render and §5.5 is explicit
  that the variants *are* the field's options with no second list to disagree.

**Taking the discount lines off the form entirely was the alternative** and is
worse in a way that is easy to miss: a row that is not submitted has no id to
carry, so the writer would delete three rows and insert three identical ones on
every save — churning ids, filling the timeline with "line removed / line added"
and leaving a tombstone behind each time.

#### What is stored, and what is re-read

[XIV-67] settled this for payment terms and [XIV-16] for totals: **what was agreed
is a fact about the document.** The discount line's amount is stored like every
other line total, and the order's reference merely says which voucher it was.
Recomputing from the voucher on every *read* is the mistake §5.9 exists to
prevent, and nothing here does it — deleting the voucher afterwards changes
nothing on the order, which is asserted rather than asserted-about.

What the deriver does do is recompute on every *save*, from the voucher's current
values, exactly as a line total recomputes from its quantity and price. So editing
a voucher changes what an order that is still open will say the next time somebody
saves it. That is deliberate, and what keeps it away from a document somebody has
been given is §5.8: a **locked** record cannot be saved, and a record that cannot
be saved is never derived again.

**The window is wider than "a draft", and it is worth being exact about it.** The
order module locks `delivered` and `cancelled` and not `confirmed`, so a confirmed
order re-saved after the voucher was edited does restate its discount. That is not
a hole this feature opened: every derived figure on that order — the line totals,
the subtotals, the VAT table — has exactly the same window, and has since
[XIV-16]. Narrowing it for the discount alone would be a rule about vouchers
wearing the clothes of a rule about documents, and the place to narrow it, if it
is ever worth narrowing, is the lifecycle.

**A voucher that cannot be read leaves the lines alone.** Deleted, or its module
uninstalled — the deriver has nothing to say and changes nothing, rather than
reading the absence as "no discount" and quietly taking money off a document
somebody has already been given.

#### Redemption is a write, and it happens once

[XIV-103] built a guarded counter — its own tenant table and one
`ON CONFLICT … DO UPDATE … WHERE` statement — and said this ticket would be its
caller. It is, and everything interesting is *when*.

The caller is a subscriber on `RecordChanged`, which is dispatched **inside the
writer's transaction** (§5.2). That one fact buys all three properties the ticket
asked for:

- **A use is taken when the order commits**, not when somebody types a code into a
  form and wanders off. It has to be: the live form re-derives on every keystroke
  ([XIV-32]), so a redemption on that path would burn a voucher per character.
- **A save that fails takes nothing**, because the redemption is a statement in
  the transaction that rolled back. Nothing has to remember to undo anything,
  which is the property [XIV-103] chose its statement's shape for.
- **A refusal takes the save down with it.** Whether a use is left cannot be known
  any earlier than the statement that fails to increment the count, so the
  refusal has to be able to happen at the write.

**Removing a voucher from a draft gives the use back**, which was the open
question, and the invariant that decides it is worth stating in one line: **the
count is the number of documents that carry the voucher.** Naming one takes a
use, un-naming it gives that use back, swapping one for another does both, and
deleting the document gives it back. Anything else about the order — a line
edited, a status confirmed — does nothing at all, which is most of the traffic and
is why the subscriber reads the *field diff* rather than the record.

Leaving the count up instead would burn a single-use voucher on somebody's
mistake, with nobody in the building able to put it right: the counter is engine
bookkeeping and is deliberately not a field a customer can edit (§5.19). Giving it
back needs a second statement — `redeemed_count - 1` with a floor of zero — and
[XIV-103]'s guarded statement stays the only way a use is *taken*, which is the
part that had to stay true for [XIV-122]'s second caller.

**A cancelled order keeps its use**, and that is the one edge this invariant
leaves visibly imperfect. A cancelled order still carries the voucher, it is a
record of what happened (§5.8), and the lifecycle has locked it so nobody can take
the voucher off it either. Releasing on cancellation would be a fourth rule about
a fifth state; the honest answer for now is that the count says how many documents
carry it, and a cancelled order is one of them.

#### Refusing, and saying which

An expired, not-yet-started, exhausted or unreadable voucher is refused with a
sentence naming which — four sentences, not one, because a code that has been
used up, a promotion that starts next month and a voucher somebody deleted are
three different situations with three different things to do about them.

That needed something the engine did not have. §7.1's question was "may a
subscriber refuse a save", and it was half-answered: a subscriber has always been
able to take the transaction down by throwing, because the event is dispatched
inside it — what it could not do is *say what happened*, so a refusal was a stack
trace shown to somebody who typed a code that had already been used.
`Record\RecordRefused` is that missing half, and its shape is copied from
[XIV-109]'s `DuplicateValue`: it names the field it is about, the record form
catches it exactly as it catches a duplicate, and the sentence lands on that
control with everything the person typed still in the form. A reader cannot tell
the two apart and should not be able to.

**The deriver still cannot refuse**, and nothing here weakens that: `ValueDeriver`
has no return value, no stoppable event and no flag. What may refuse is a
subscriber, at the write, for a rule that can only be checked there. A rule that
could have been a field definition, a lifecycle transition or a validation
constraint belongs in one of those, where somebody meets it before pressing save.

**Validity is checked when the use is taken, once, and never again.** An order
agreed while a promotion was running keeps its discount after the promotion ends,
because expiry is the calendar rather than an act (§5.19) and re-checking on every
save would take the discount off a draft somebody merely opened the following
week.

**There is deliberately no transition guard** ([XIV-110]). "This order's voucher
has since expired" would refuse to confirm an order the shop has already agreed
to, on the grounds that the shop took too long to confirm it.

**A voucher that is already gone cannot be named through the form at all**, and
that is the engine's answer rather than this feature's: a `reference` control
offers the records that exist, so an id naming a deleted one does not survive the
submit — the field arrives empty and the order is saved without a voucher. The
refusal above is therefore a backstop for the callers that are not the form, the
importer and the demo generator among them, and it is tested at the writer because
that is the only place it can be reached.

#### The field exists only where both modules do

The negative half of the ticket, and the one part that needed a new rule in the
engine.

An order may name a voucher and vouchers are a module a customer may not have
bought — the link is `uses` rather than `requires` ([XIV-23]), because an order
book is a perfectly good thing to keep without ever running a promotion. What that
customer must not get is a **"Voucher" control with an empty picker behind it** on
every order they ever type.

[XIV-23]'s answer works for a row *kind*: the whole kind is hidden, so the link
inside it is never drawn. A field on the record itself has no kind to hide it
with. So it is hidden the only other way a field can be — **by not being
installed** — and that turns out to be the better answer rather than the
remaining one, because a definition that does not exist is invisible everywhere at
once: the form, the list, the record page, the import, the export, the document
templates and the history all read the customer's definitions, and not one of them
needs to learn a rule. `Module\AvailableFields` is that rule, in one place, asked
by the installer and by the upgrade offer.

Three consequences, each of which had to be arranged:

- **The upgrade offer asks the same question** (§7.2.1). Without it, an order-only
  customer would be *invited* to take a link into a module they have not got —
  nothing would refuse the invitation, and they would end up with exactly the
  empty picker the install skipped.
- **A customer who buys vouchers later is offered the field** by that same screen,
  which is what it is for. Installing is a seed and the definitions are the truth
  afterwards (§6.1), so nothing retro-fits and nobody is edited without being
  asked.
- **`ModuleInstallOrder` follows `uses` edges within the requested set**
  ([XIV-72]). Installing four modules from one command line must not depend on the
  order somebody typed them in, and an order installed before vouchers is an order
  with no voucher field on it. The edge is followed only when both modules are
  being installed anyway — nothing is pulled in and nothing is refused, which is
  the whole distinction between the two words.

**The rule is narrowed twice.** It applies only to a `reference` field, and only
to one that is not scoped to a variant — because a variant is
`AvailableVariants`' business and the two rules would fight: a voucher's own
`article` link belongs to the free-article kind, and taking the field away here
would make that kind look fillable and offer it with nothing in it, which is
precisely the failure [XIV-103] wrote a test against.

#### The invoice needed almost nothing

§5.12 seeds an invoice from an order by copying its lines, and a discount is a
line — so a bill for a discounted order comes out discounted with no new
machinery, which was the point of deciding it was a line in the first place.

The one thing the invoice module had to gain is the `discount` kind itself, and
**as an ordinary kind rather than a generated one**. The seed copies the kind
along with the figures, and a value the field had never heard of would fail the
choice constraint and refuse to bill a discounted order at all. But nothing
*generates* one on an invoice: an invoice names no voucher, so a discount line
there is a copy, and from that moment it is a line with a negative price and a
label saying what it is — which is what [XIV-16] has called a discount since
before vouchers existed. That also means it stays editable and deletable there,
which is right: what it says was decided on the order, and what to bill is decided
on the invoice.

#### Deliberately not in this

**A line voucher** ([XIV-122]), which reduces a single line rather than the
document. Two things were arranged so that it fits around this rather than being
retro-fitted: the redemption counter now has a release as well as a take and both
go through the one guarded statement, and `DerivesTotals` asks a *list* of
discount sources, so a document can carry an order voucher's own line and a
reduced line at once. *Answered by §5.25*, and one of the two hooks was used
differently than expected: the counter's release is what the set diff is built on
and was exactly right, but the list of sources stayed one entry long — a line
voucher turned out to be a second **answer** from the same source rather than a
second source, because both modes are decided from one record in one save and a
second source answering separately about the header and the lines would have had
nothing reconciling the two.

**Applying a voucher directly to an invoice.** A separate question with a separate
answer, and this ticket is orders only.

**A partial invoice takes the whole discount.** The seed's `outstanding`
arithmetic draws down on quantity, and a discount line has a quantity of one, so
the first invoice made from a discounted order carries the discount and a second
one does not. That is a defensible answer and it is not a decided one; if it
matters it wants its own ticket.

**The discount line does not appear until the first save.** The live form
recomputes the totals on every keystroke ([XIV-32]), so picking a voucher moves
the figures immediately — but the *line* it moved them by is a row the deriver
invented, and a row invented mid-typing has no index in the form it would have to
be drawn into. So the totals follow the voucher live and the line under them
appears when the order is saved. Showing it live means the preview inserting rows
into a form somebody is typing in, which is a bigger change to XIV-32 than the
thing it would show.

**A cancelled order keeps its use.** See above: it still carries the voucher, it
is locked, and nobody can take the voucher off it.

**Anything about who pays for the discount.** No accounting split, no cost centre,
no reporting on promotions. What is here is a document that says what the
customer owes.

---

### 5.25 Two ways to apply a voucher (XIV-122)

§5.24 put a voucher on an order and settled that **a discount is its own line**.
This is the other way of applying one, and the first thing to say is that the two
are not in tension — which they were read as being, and which is worth writing
down because the reading was reasonable.

**A voucher has a mode, and the mode decides both where it may be applied and
what it does.**

| mode | applied to | what it does |
| --- | --- | --- |
| **order** | the whole document | **adds its own line**, as §5.24 settled |
| **line** | one line, chosen when applied | **reduces that line** |

§5.24's rule governs the order mode, where there *is* no line for the money to
belong to, so it needs one of its own. The line mode has a line already and
reducing it is the natural reading — and adding a second line beside it would be
a document saying the same thing twice. Two modes, two answers, neither
overruling the other.

Everything below is the detail that follows.

#### The mode and the kind are one field, and that is what says which combinations exist

There are two independent questions — order or line, amount or percentage — and
both change what a voucher *is*. §5.5's rule is that the variants are the variant
field's options and the fields depend on the answer, so the shape asks **one
question with four answers** rather than two questions the engine could not
relate:

    order_amount        Amount off the order
    order_percentage    Percentage off the order
    line_amount         Amount off one line
    line_percentage     Percentage off one line

Which combinations exist is therefore a **list rather than a rule**, and the list
is all four. Each is a promotion somebody runs. What is decided by their absence
is the fifth thing a `mode` field beside a `kind` field would have allowed: **an
order voucher restricted to an article**, which is not "ten francs off" at all but
a rule about which orders qualify — a different and much larger feature. The
restriction is declared on the two line variants and on no others, so the engine
refuses it by not offering it, which is exactly the work §5.5 does and validation
would otherwise have to.

A `mode` choice beside a `kind` choice was the alternative, and it is [XIV-103]'s
own "one shape with a nullable field per kind" mistake one level up: nothing
anywhere could say that an order voucher restricted to an article is nonsense,
because a variant can hide a field and a plain choice field cannot.

#### The line is chosen when the voucher is applied, and that is what reaches a custom line

An earlier revision had the voucher name an **article** and the engine hunt for
the line selling it. That cannot reach a **custom line**, which has no article —
and a custom line is exactly where a negotiated discount lands. It would have
missed the case the feature exists for.

So the line is chosen by the voucher **being named on it**: the order's line
collection carries a `voucher` reference, and putting a voucher there is the whole
of applying one. That asks nothing of the line at all, which is the property the
article-hunting design could not have.

The article reference survives as an **optional restriction** rather than as the
targeting mechanism. Named, and the voucher may only go on a line carrying that
article; empty, and it may go on any line, custom included.

*Free article* then falls out of the general rule as **"line mode, restricted to
article X, 100%"** rather than being a kind of its own, and [XIV-103]'s
`free_article` is gone rather than renamed — it described neither half of the
shape any more. What it stops doing is *adding* the article as a line: the article
goes on the order the way every other article does, and the voucher takes its
price off. One more step for whoever types the order, and in exchange the free
article is a line somebody chose at a quantity somebody chose, priced from the
catalogue, rather than a row appearing underneath at a quantity the voucher
decided months earlier.

#### The consequence that removes a guard, recorded rather than noticed

[XIV-103] made the article reference `required: true` **specifically** so that
`AvailableVariants` would hide that kind from a tenant without the Article module;
its blueprint comment called this *"load-bearing twice"*. An optional reference is
not a reason to hide a kind, and `AvailableVariants` correctly says nothing about
one. **So that guard no longer fires, and all four kinds are offered to every
customer.**

That is the right outcome rather than a regression — "ten francs off one line" is
a perfectly good voucher for a tenant with no catalogue, and hiding it would
refuse them a feature that works, which is the opposite of what [XIV-23] hides a
kind for. But the **empty picker** [XIV-23] was really avoiding is still worth
avoiding, and it is, one class over.

`Module\AvailableFields` was narrowed by §5.24 to leave *variant-scoped* fields
alone, because a variant is `AvailableVariants`' business and the two rules would
fight. That narrowing was written when the only variant-scoped reference in the
codebase was required, so the two spellings agreed. They have come apart, and the
narrowing is **halved**:

> A **required** variant-scoped reference is `AvailableVariants`' to hide. An
> **optional** one is `AvailableFields`' to take away.

Between them every reference into a module is covered exactly once, and nothing
overlaps. The kind is offered to a customer with no catalogue; the restriction
simply is not a field they have.

The same class had to learn about **collections**, for the same reason and with no
new argument: an order *line* may name a voucher just as an order may, and §5.1's
claim is that a shape is a shape. Both places a definition is born now ask it —
the installer for a collection installed with its module, and the upgrade offer
(§7.2.1) for a field offered afterwards. Without it every order line in a tenant
that never bought vouchers would carry a picker with nothing behind it, which is
precisely what §5.24 spends its last section preventing on the header.

`VoucherWithoutArticlesTest` is where the loss is recorded rather than discovered:
the method that asserted two kinds of three now asserts four of four, and a second
one asserts that the restriction is not installed at all.

#### The reduction is a column, and the recipient can check it

A reduced line has to *say* it was reduced. §5.24 refused to let a document read
`1 × Widget @ 100.00 = 90.00` in the other mode and the same objection holds here:
that line asks its reader to take the arithmetic on trust. So the line gains a
derived **discount column**, and the line total under it is what is left:

    Consulting   3 × 66.65    199.95   −29.99   169.96

`LineTotals::$lineDiscount` is how a module names it, beside the `discountKind` it
already named for the other mode. A module may have one, the other, both or
neither, and an invoice is the interesting case: it has the **column and no
kind** — it can carry a reduction the order worked out without anything on it
granting one.

**What protects it is the derived flag, and here that is the right precedent
rather than the wrong one.** §5.24 found that a subtotal protects *a column, not a
row*, and needed three mechanisms because the engine owned the whole discount row.
A line voucher reduces a row the customer owns and edits freely — their own
article line — so a column is exactly what needs protecting, and the flag that has
done that since [XIV-20] does it. The deriver restates the column from the voucher
on every save, so a request forging a smaller figure into it has that figure
overwritten before anything is stored, and the form draws it disabled on top of
that.

#### Two passes, and no new arithmetic

`DerivesTotals` walked the rows once, because everything a discount did was
appended underneath them. A line reduction is not appended: what a line
contributes to the subtotal above it and to its rate's VAT base is not known until
the seam has answered, and the seam cannot answer until it has seen the lines.

So the loop is split at exactly that point — first pass works out what each row
*charges*, the seam is asked once in between, second pass takes the reductions off
and places the money. The rounding rule, the subtotal rule, the per-rate grouping
and the treatment of a row the engine wrote last time are the same statements in
the same order, one indentation level further down.

**Before VAT in both modes**, which is what makes both intelligible: a reduced line
joins its own rate's group carrying the reduced figure, so the tax is computed on
what was actually charged. And a line discount needs **no apportionment at all**,
which is the whole difference from §5.24's order voucher: it stays on its own line,
so it joins exactly one rate by being part of it. [XIV-116]'s rule about
remainders never crossing a rate boundary is satisfied by there being no share to
distribute.

**One seam, not two.** `Money\DocumentDiscounts` still has one method. A line
voucher turned out to be a second *answer* from the same source rather than a
second source, which is the better outcome: both modes are decided from one record
in one save, and a source answering separately about the header and the lines
would have had nothing anywhere reconciling the two. `Discount` carries `off` for
the document and `perLine` for the lines; `DiscountLine` is gone with the
free-article kind that was its only producer.

**When both are on one document the line reductions happen first**, and an order
percentage is a percentage of what is left. That is the only reading that is not
arbitrary — "a tenth off this order" is a tenth of what the order costs, and what
it costs already reflects the tenth somebody negotiated off one line. It also
keeps the two from ever adding up to more than the document charges.

#### The bound, decided rather than emergent

- **A percentage is capped at 100** by the field, unchanged from §5.19, and for the
  unchanged reason: a 120% voucher is a document that owes the customer money.
- **A fixed amount larger than the line is floored at the line**, not refused. A
  negative line is money owed back and nothing downstream hands any over; a
  refusal would be the engine declining an arithmetic it can perform, when the
  shop said "twenty off this", the line was worth fifteen, and fifteen off is
  plainly what was meant. It is §5.24's document-wide cap reached one level down,
  and a line that charges nothing or less than nothing takes nothing off.

#### One voucher on several lines is one use

The question this ticket left open, and the answer is [XIV-104]'s invariant
unchanged rather than a new one: **the count is the number of documents that carry
the voucher.** An order with `HALF-OFF` on three of its lines is one order carrying
`HALF-OFF`, so it takes one use.

"One use per line" loses on what a limit *means*. A customer told "this voucher may
be used five times" reads that as five customers, or five visits — a promotion
whose budget is five. Under per-line counting it is spent by the first shopper who
buys five things, and the five-customer promotion ends at the first customer. The
limit would be counting keystrokes rather than deals.

It also keeps **one counter**. Per-line counting needs a use to be a *(voucher,
line)* pair, which a counter keyed by `voucher_id` cannot express — so it would
have meant a second table with a second rule in it, and two counters that must
agree are two counters that will not.

**Which makes the diff a set.** [XIV-104] read one field's before-and-after because
a voucher could only be in one place; there are now many places, so what is
compared is the set of vouchers the document carries, before and after. Gaining
one takes a use, losing one gives it back, swapping does both, deleting the
document gives every one back — and **moving a voucher from one line to another
does nothing at all**, which is what the set buys and a per-field diff could not.
Dragging it down a row is two field changes and no change to the document; a naive
reading would release and re-take, and on a single-use voucher at its limit would
refuse a save that changed nothing about how many times the voucher had been used.

The "before" set is reconstructed from `RecordChanges` rather than read, because by
the time the subscriber runs the rows are already written: a row that was added is
taken out, a row that was removed is put back with the voucher its summary
remembers, and a row whose voucher changed gets the one it came in with. The
history entry and the subscriber read the same facts, which is what makes the
reconstruction trustworthy rather than clever.

One small thing had to be added for the delete path. `RecordChanged` is dispatched
*after* the delete inside the same transaction (§5.2), so the one moment at which
what a record carried matters most is also the one moment its rows are behind a
tombstone. `RecordRepository::findChildren()` gained the `includeDeleted` flag
`find()` already had, and every ordinary caller sees what it saw before. This was
found by a test rather than by reading: the first version released nothing on
deletion and said so.

#### Where the mode is enforced, and why it is there

A line voucher put on the order, or an order voucher put on a line, is **worth
nothing in the deriver and refused at the write** — with a sentence that names the
fix rather than the rule, because it is a mistake with an obvious one. A voucher
on a line that breaks its article restriction is refused the same way, by name, so
that whoever is holding it knows where it does work.

Both halves are needed. A refusal cannot happen in a deriver (§5.24), and the
deriver runs on every keystroke of a form somebody is still typing into — so the
rule would fire while they were halfway through choosing. And silently discounting
nothing would put a figure on the page that nobody could explain. What the deriver
must not do is *guess*: an order voucher dropped on a line is not "probably meant
for the order".

It could not have been a field definition or a validation constraint either, which
is §5.24's own test for anything landing at the write: whether this voucher may go
on this line depends on the **voucher's** kind and the **line's** article, and a
constraint on either record cannot see the other.

#### Deliberately not in this

**A voucher on two documents at once is still two uses**, and a cancelled order
still keeps its use. §5.24's edges are unchanged.

**Nothing shows a customer which of their lines a voucher would be worth most on.**
Choosing is a person's job here.

**The reduction has no reason on it.** "15% off, agreed with the customer on the
phone" is a note, and the line's description is where a note goes.

**A line still carries at most one voucher.** Two vouchers on one line is a stacking
rule — which comes first, whether the second is a percentage of the first's
result — and nothing here has an opinion about it. The field is a single reference,
so the question cannot be asked yet.

**And one cost is accepted rather than fixed.** The *Discount* column names no
module, so `AvailableFields` has no opinion about it and an installation with
orders and no vouchers gets a column nothing can ever fill in. Hiding it would
need a rule saying "this field is only writable while that other field exists",
which is one module's internals living in the engine — and §5.4 already gives the
better answer, since the field editor removes a field somebody does not want. It
is asserted in `OrderWithoutVouchersTest` so that it stays a decision rather than
becoming a discovery.

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
  time — and since §7.2.1 a collection the module gained *later* can be taken as
  well, which is the same rule arrived at from the other side: the installer is
  still the only thing that makes a table.

  Nothing records which preset was used. Storing it would only invite something to
  re-apply it later, and a preset is a seed with no further say.
- **Template** — how a customer is set up *across* modules: install these modules
  with these presets, then add these fields. "Dentist practice" is a template, not
  a preset; nothing about it belongs to any single module. It is data, in the
  control plane next to plan and enabled modules, because adding one means a new
  market rather than a code change — and needing a deploy to onboard a vertical is
  v1's compiled-in module list wearing a different hat.

  *Its second half is a shape pack, and §6.6 is what that may contain (XIV-141).*
  "Then add these fields" was one clause here and turns out to be the load-bearing
  one: a vertical is *"Contact with different fields"*, a preset can only ever mean
  *"Contact with fewer fields"*, and the gap between those two sentences is the
  whole of a trade. §6.6 draws the boundary — a pack may do nothing a customer
  could not do by hand in the editor (§5.4) — finds that the boundary encloses
  almost nothing until the editor can set a choice field's `choices`, and decides
  what the file may never contain whatever the editor grows.
- **Metadata rows** — anything one specific customer needs. The moment a preset is
  named after a customer, it has stopped being a preset.

A preset is a seed, not a type. Once installed, the tenant's definitions are the
truth and the preset has no further say — which is also why presets do not make
§7.2 worse: customers are *designed* to diverge from each other, so "we do not
retro-fit blueprint changes" is the stated model rather than a limitation.

**That rule is unchanged, and §7.2.1 is its one sanctioned exception (XIV-70).**
Nothing retro-fits: a blueprint that grows still reaches into no installation, a
release still rewrites nobody's definitions, and a deploy still changes no
customer's shape. What exists now is the other half of the sentence — an
*explicit* way to say yes. A customer is shown what their installed module is
missing relative to the blueprint they could have, including the fields a smaller
preset left out, and takes it item by item or dismisses it. It only ever adds, and
a key they already have is never offered — whatever they have since renamed or
narrowed it to — so the thing this rule exists to protect is protected by
construction rather than by care. The preset choice is therefore no longer a
one-way door in the direction that mattered: the smaller preset can be grown into
the larger, while nothing can take away what was installed. See §7.2.1 for the
whole of it, including what happens to a field somebody deleted on purpose.

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

*Updated by §6.5 ([XIV-101]).* The row carries a second decision now — what this
deployment charges — and it gates the store alongside the state rather than
through it: a module is offered when the platform says it is finished **and** this
deployment says it is for sale, and either saying no is enough. The two are
deliberately separate axes; "published and not for sale" is a real and useful
combination. The sentence above about uninstalling is the one that transfers word
for word, and §6.5 leans on it rather than restating it.

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

*Updated by §7.2.1 ([XIV-70]).* That upgrade exists now, so the sentence has
been corrected rather than kept: the fields a smaller preset leaves out can be
taken afterwards, one at a time, and a screen claiming otherwise would be telling
somebody they are making a decision they are not. The screen still says the part
that is still true and was always the load-bearing half — **nothing rewrites what
is installed here** — and the layout argument above is unchanged, because the
choice is still worth reading before it is made.

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

*Half-corrected by §6.5 ([XIV-101]).* "Every module is free in this iteration" was
true when it was written and is why the migration that added the price column
backfills every existing row to `free` — that is recording a fact rather than
inventing one. What has changed is that free is now a *decision* somebody made
rather than the absence of one, and a module nobody has priced is withheld from
the store instead of given away. Payment itself is still not in this: [XIV-102]
is what a customer sees and what they pay with.

*And finished by §8.15 ([XIV-102]).* What a customer sees is the price, where
there is one, and nothing at all where there is not — a free module's screens are
byte-identical to what this section describes, which is the property that made the
store's existing tests pass unchanged. What they pay with is **nothing**: there is
no gateway, a priced module cannot be installed from here at all, and the button
records a request an operator answers. "Uninstalling is not in this" is unchanged
and is load-bearing for that argument, which is why "install it and mark it
unpaid" was rejected rather than deferred.

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

One service, `Xivi\ControlPlane\Introspection\TenantInspector`, answers three
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

### 6.5 A module can have a price, and the operator sets it (XIV-101)

Modules were free, and §6.3 said so in as many words while leaving payment out of
the store on purpose. This is the first half of undoing that: **the price
existing, being set, and being readable.** Payment itself, and what a customer
sees in the store, are [XIV-102] and depend on this.

The governing sentence is that **what a module costs is not the code's business**.
The company deploying Xivi decides what its customers pay, and the whole of the
design below follows from taking that seriously rather than from anything about
money.

#### Where it lives, and why not on the blueprint

**On the control-plane `module` row, beside `state`.** Not on `ModuleBlueprint`,
and this is [XIV-7]'s argument reused rather than a new one (§6.2).

A blueprint is *code*. It ships identically to every deployment, so a price in
`packages/invoice/` would be a price every installation inherits and none of them
chose — and the deployments differ on exactly this point: one sells the invoice
module, the next bundles it into a contract it negotiates per customer, a third
runs this for one company and sells nothing at all. `App\Registry\Entity\Module`
already carries `key` and `state` for that reason, and `ModuleCatalog` is already
the seam where the build's half and the control plane's half meet.

**No `packages/*` blueprint names a price, and none may.** There is nothing to
enforce that with a test, because there is nothing in `ModuleBlueprint` to put one
in — the absence is the enforcement, and the day somebody adds a `price:`
parameter to that constructor is the day this section is what they should be made
to read.

#### Three decisions and one absence, and the absence is the load-bearing part

`ModulePricing` has four cases where the ticket asked for three, and the fourth
is what makes the three mean anything.

| | what it says | offered in the store |
| --- | --- | --- |
| `unpriced` | nobody has decided | no |
| `free` | it costs nothing | yes |
| `priced` | it costs this much | yes |
| `not_for_sale` | this deployment does not sell it | no |

**A null price is not free.** Collapsing "free" and "no price set yet" is how a
module ships at zero on the day somebody adds the column, and nothing anywhere
says it happened. So `unpriced` is a value in the column rather than an absence of
one — it has to be, because unlike `state`, the price cannot borrow "no row at
all" as its default: the row is frequently already there for the other decision.
A module somebody publishes and does not price is therefore explicitly undecided,
and is **withheld from the store** until somebody says which of the other three it
is.

That last part is a behaviour change and the visible cost of the ticket: before
this, publishing was sufficient. It is deliberate, it is said at every point where
somebody could be surprised by it — `module:list`, `module:state` at the moment of
publishing, and a banner at the top of the operator screen — and the alternative
is the failure this whole section exists to prevent.

**`not_for_sale` is not `development` in different words.** `development` is a
statement about the *module*: it is not finished, platform-wide, for everybody.
`not_for_sale` is a statement about *this company's price list* for a module that
is finished. A deployment that bundles the invoice module into a negotiated
contract, or has retired it, needs to say so without telling every reader that the
code is unfinished. The two axes are independent and either saying no is enough;
folding them into one enum would have produced a four-value list in which
"published and not for sale" had no spelling.

**A not-for-sale module is not listed at all**, rather than listed and unbuyable.
The open question the ticket left, decided: the store is a place to obtain
modules, so a row nobody can act on is an advertisement for something the
deployment has decided not to sell, and the reader's only available response to it
is to ask why. A deployment that genuinely wants to tease something is asking for
a "coming soon" list with a date on it, which is a different feature and would be
badly served by this one pretending to be it.

**Zero is refused as a price.** `priced 0.00` is `free` spelled in a way nothing
can distinguish from a form somebody submitted before finishing, so `ModulePrice`
throws on it — and rounds before it judges, so `0.004` is refused as the `0.00` it
was about to be stored as. Three states only stay distinguishable if the boundary
between two of them cannot be reached by typing a number.

#### One-off, not recurring — and what was rejected

**Decided: a one-off price.** One number, per module, and no period.

Recurring was the serious alternative and it changes the data model rather than a
field. `Tenant::$plan` exists and defaults to `standard`, which looks like
subscription thinking already in the air — and it was checked rather than trusted:
**nothing consumes it.** It is displayed by `tenant:list`, by the tenant list page
and by the introspector, it is written once at provisioning from a signup, and no
code anywhere reads it to decide anything. It is a label.

So "recurring" here would not be a `period` column added to a working billing
system. It would be a `period` column with **no** billing system behind it, and
the things it implies do not exist and are each their own ticket: a billing period
and where its boundaries fall, renewal, proration when somebody installs
mid-period, a grace period, dunning, what happens to an installed module when a
renewal fails — and that last one collides head-on with §6.2's rule that nothing
here uninstalls anything. Shipping the column and none of that is worse than
shipping neither, because it looks like the feature: a screen that offers "per
month" is a screen that promises somebody will be charged monthly.

**A one-off price is the smaller honest thing**, and it is not a dead end. When
recurring is genuinely wanted, `ModulePricing` grows a case the same way
`ModuleState` was designed to, and by then there will be a purchase record for a
period to hang off — which is where it belongs, since a period is a term of a
transaction rather than a property of a module. Rejected along with it: putting
`plan` to work as a pricing tier, which would have made a per-tenant label into a
billing input while nothing validated it and while no tenant's plan had ever been
chosen by anybody deciding about money.

#### The currency is the instance's, and `InstanceCurrency` does not fit

**A price list is an instance fact, not a tenant fact.** The deployment sells in
one currency; a tenant whose profile says something else does not change what the
deployment charges.

`Xivi\Core\Money\InstanceCurrency` is named for the instance and looks like the
answer. It is not, and reusing it fails twice in opposite directions — neither
failure being about the name:

- Its one implementation is `App\Tenant\Settings\ProfileCurrency`, which reads the
  **tenant profile** (§8.6) — the currency a customer writes their *own* invoices
  in. Rendering this deployment's price list through it would relabel francs as
  euros for a customer whose profile says EUR: the same digits, a different claim,
  agreed to by nobody at either end.
- A control-plane request resolves no tenant by construction (§8.9), so
  `ProfileCurrency` correctly returns null there, for ever. The one page on which
  somebody decides what a module costs would be the one page unable to say what it
  costs it *in*.

So this is **not a second currency concept**: same ISO 4217 code, same
`Money\Amount`, same two decimal places, and `App\Registry\Pricing\PriceCurrency`
deliberately does **not** implement `InstanceCurrency`, so that it cannot be
autowired into a field type by somebody who reads the interface name and stops
there. What differs is whose fact it is.

**It is a deployment parameter, `PRICE_CURRENCY` → `app.price_currency`**, and
that needs defending because the ticket is emphatic that a *price* must not live
in `.env`. It must not, and the argument does not transfer. A price changes, which
is the reason this ticket exists; a deployment's selling currency is picked once
at installation, and changing it does not adjust a price — it invalidates every
figure on the list at the same moment, since 49.00 CHF and 49.00 EUR are not the
same offer. That is a re-pricing exercise with a person in it, and making it need
a deploy is the correct amount of friction. It is also §4.4's shape for
`app.control_plane_host`: a fact about where and how this software is installed,
set in the environment, therefore identical in both images with nothing to keep in
step.

Rejected: a single editable row in the control plane — a table, a row, a column, a
screen and a migration for a value that changes roughly never. The note worth
leaving is what would change the answer: the day a deployment sells into two
markets in two currencies, this is wrong and a per-price-list currency is right.
That has exchange rates behind it, exactly as `CurrencyFieldType` says about
per-record currencies, and it is a feature rather than a field option.

**Empty is a real answer and is the default.** §8.6 refuses to guess a currency
for a customer because a guessed one is wrong quietly and surfaces on the first
priced thing they print; the same holds one level up. Unset, prices are bare
numbers and the operator screen names the variable.

#### The money is §5.9's money

A decimal string at two places, `NUMERIC(12, 2)` in the column, arithmetic through
`Money\Amount` on `brick/math`, scale taken from `Amount::SCALE` so this and an
invoice line round from one constant under one rule. Nothing on the path from the
request parameter to the column is ever a float. A system that got money right in
every customer's documents and then priced its own modules in `float` would be an
embarrassing exception, and the exception would be written by whoever found a
`float $price` easier to add than to read this paragraph.

#### Changing a price touches nothing anybody already has

**This is [XIV-67]'s argument about payment terms, and it transfers exactly.**
What was agreed is a fact about the transaction rather than a live lookup, so
raising a module's price must not retroactively change what an existing customer
is deemed to owe, and must not uninstall anything.

Structurally that is already true and stays true by construction: a customer's
modules live in *their own* database, are put there by `ModuleInstaller`, and are
read back out of their own metadata (§6.1, §6.3). Nothing on that path consults
the control plane's price column, and `ModuleCatalog::priceAt()` writes two columns
of one control-plane row and reaches nothing else. §6.2 already settled the same
point for state — a decision here says what may be obtained from now on, never
what is removed — and the price inherits it rather than restating it.

**Proved rather than asserted.** `tests/Functional/ControlPlane/ModulePriceTest.php`
provisions a real tenant, really installs a module into it, really writes a record
through `RecordWriter`, and photographs everything observable about the result —
the module definition, its table, every field with its label and requiredness, the
record count and the records' data. It then walks the price through free, priced,
a **rise**, and a withdrawal from sale altogether, and compares the photograph. A
test asserting "the price setter does not call the installer" would have passed for
any number of ways of doing the wrong thing indirectly.

The forward-looking half is a rule for [XIV-102] rather than code here: when a
purchase is recorded, the price goes onto that record as a **copy**, exactly as an
invoice stores its own due date (§5.16) and its own totals (§5.9). Nothing about a
sale is ever recomputed from this row afterwards. *That has landed and §8.15
records it*: the copy is on the customer's own purchase-request row, the collector
that shows it to an operator carries it across untouched and never consults this
class at all, and raising a price is proved not to move a figure somebody was
already quoted.

#### Reading and setting land on opposite sides of the [XIV-96] split

The tension is real and is resolved rather than noticed.

`App\Registry` stays in `src/` and is compiled into the customer-facing image,
because it is what a customer's own request needs in order to be served at all
(§3.1). That includes the two new columns, `ModulePrice`, `PriceCurrency` and
`ModuleCatalog` — **reading a price is a customer-facing concern**, since [XIV-102]
will draw it in that customer's store.

The operator screen that *sets* one is `Xivi\ControlPlane\Controller\ModulePricingController`,
in the package, and is therefore absent from that image: §4.4's builder stage
refuses to finish if the namespace survives anywhere under `/app`. Nothing in
`src/` or `config/` names it, which is what
`tests/Unit/Deployment/ControlPlaneIsOptionalAtBuildTimeTest.php` checks.

**And the guarantee underneath is the grant, not the routing.** §4.4 gives the
customer-facing instance's role `SELECT` on the registry tables and nothing else,
so `UPDATE module SET price_amount = …` from the process facing the internet is
refused by PostgreSQL whatever a controller there does. `ModuleCatalog::priceAt()`
therefore joins `moveTo()` on the short list of **writers that live in `src/` and
are only ever called from the package** — §4.4 names that list and it now has two
entries. Splitting the writer out into the package was weighed and rejected: it
would put half of one entity's invariants in `src/` and half in a bundle, so
`ModulePrice`'s rules would be enforced by whichever half a future caller happened
to go through.

The screen keeps [XIV-58]'s boundary as well: every value on it is a `module` row
crossed with the blueprints this build compiled in, so it opens no tenant
connection, and the test asserts that the same way `TenantListTest` does.

**It is also the second page on that surface**, which XIV-58's template said to
wait for before adding a nav. So the header moved into a partial both pages
include, rather than being copied.

#### Readable through one seam

`ModuleCatalog::price()` mirrors `state()`; `CatalogEntry` carries a
`ModulePrice`; `CatalogEntry::isOfferedInStore()` holds the whole rule — in the
build, published, and for sale — so the store, the operator screen and the
introspector ask one question rather than three each composing their own. There is
no second service reading the `module` table, and there must not be.

`module:price` exists beside the screen for the reason §6.3 gives about
`tenant:module:install`: a page is not a reason to take a command away, and a
headless deployment has no browser pointed at the control plane.

*Read by one more thing since §8.15 ([XIV-102]).* `ModuleStore` asks
`ModuleCatalog::price()` for every offer it draws, which is the fourth reader and
the first customer-facing one. It is worth noticing that this is exactly what the
[XIV-96] paragraph above predicted: reading a price is a customer-facing concern,
so the read side of this feature compiles into the public image and the operator
screen that writes it does not.

### 6.6 A vertical as data, and whether it can be uploaded (XIV-141)

§3.2 closed modules. The question it left open is the interesting half: a vertical
is mostly *shape* rather than behaviour, shape is data (§5), and data does not need
a deploy — so can somebody who is not us hand a customer a file that turns their
Contact module into a law firm's, or a care home's?

The answer is **not yet, and the obstacle is not the file format.** What follows is
the whole of it, because the finding that decides it is one nobody expects.

#### "Preset" is the wrong word, and §6.1 already has the right one

The middle position as it was proposed — *modules stay closed, presets are open* —
does not survive contact with what a preset is. A `ModulePreset` is a `key`, a
`label`, a `description` and **a list of field keys taken from the blueprint's own
fields**. It cannot add a field, rename one, reorder anything, or change a field's
options. It is a subset and nothing else.

So a shareable *Law Firm preset*, in the word's actual meaning, is **Contact with
fewer fields** — and there is no arrangement of the contact module's nine fields
that makes a law firm. The phrase the ticket reached for was *"Contact with
different fields"*, and *different* is precisely the thing a preset cannot express.
Worse, it would be redundant even where it works: since [XIV-70] a customer can
install the extended preset and decline what they do not want, item by item, on a
screen built for it (§7.2.1). Uploading a subset would add a file format to reach a
place two clicks already reach.

§6.1 already names the thing that was actually wanted, and has since this brief was
written: a **template** — *install these modules with these presets, then add these
fields*. "Dentist practice" is a template, not a preset. It is data, it lives in the
control plane, and the reason given there is exactly the reason XIV-141 was raised:
**needing a deploy to onboard a vertical is v1's compiled-in module list wearing a
different hat.** The middle way is not a new idea to be evaluated; it is an idea
this brief has been carrying, unbuilt, for a while. What XIV-141 adds is the part
§6.1 never spelled out — what "then add these fields" may actually contain, and
whether the file holding it may arrive from outside.

To keep the two apart, the second half of a template has a name here: a **shape
pack** is the list of edits applied to an installed module's definitions after the
modules are in.

#### The boundary, and it is the right one

> **A pack may do nothing a customer could not do by hand in the metadata editor.**

That is what makes a pack *data* rather than code, and every property worth having
falls out of it rather than being added on top. There is nothing to execute, so
there is no sandbox to get right. It grants no privilege — whoever applies it is
already an administrator who could sit down and make the same twenty edits one at a
time, which is §5.4's authority unchanged. Every outcome is reachable through a UI
somebody is allowed to use, so "what could a malicious pack do" has the same answer
as "what could a malicious administrator do", which is a question this system
already answers and does not have to answer twice. And it is reviewable by
*reading* it, which is the property a module never had.

It also gives an implementation with no new engine in it: a pack is a sequence of
`MetadataEditor::addField()`, `updateField()` and `renameShape()` calls, and it
inherits every refusal those already make — a bad key, a taken key, an unknown
type, a rule the existing records could not keep (§5.4). A pack cannot talk the
editor into anything the editor would refuse a person, because it *is* a person's
edits with the typing removed.

#### And today that boundary encloses almost nothing

This is the finding. **The metadata editor cannot configure a choice field's
choices, and it cannot tell a reference field which module it points at.**
`FieldController::optionsFrom()` draws exactly `max_length`, `min` and `max`, plus
the two per-type settings [XIV-36] and [XIV-27] introduced — `autocomplete` and
`sequence`. `choices` and `module` are on §5.4's own list of settings *the form
must not touch*, and they are on it because the form has no control for them and
saving the whole options array used to wipe them.

Meanwhile the add-field form's type select is `$this->fieldTypes->all()`. So a
customer can add a `choice` field today and get a select with nothing in it, or a
`reference` field with no target that renders every value as `#id`. Both types
degrade politely — `ChoiceFieldType::constraints()` skips its `Assert\Choice` when
the list is empty, which its own comment calls "a confusing way to say
misconfigured" — and neither is usable. That is a live gap in the editor,
independent of packs, and it is the reason this section ends where it does.

What survives the boundary today is: add text, textarea, integer, decimal, date,
email and currency fields, with labels, `required`, `unique`, `filterable`,
`listed`, `title`, a position, a width and a length or range; relabel and reorder
the module's own fields; rename the module. What does not survive is **a choice
field with choices** and **a link to another module** — which are the two things a
vertical is mostly made of. A law firm needs a matter type; a care home needs a
care level; both need to point at something.

So the boundary is right and the editor is too small. The prerequisite is not an
upload mechanism, it is the sentence §5.4 has been half-writing since [XIV-36]: **a
type says which of its options are the customer's to set.** `choices` wants a
capability interface and a control the same way `sequence` got one; a reference's
`module` and `variant` want the same, with the added question of what happens to
stored ids if a target is changed after records exist. That is a ticket of its own,
it is worth doing whether or not a pack ever ships, and until it lands a pack is a
file that can rename things.

#### The file, and what it cannot say

A law firm flavour of the contact module, written out in full so the trade-offs are
visible rather than described:

```yaml
# law-firm.pack.yaml
pack: law-firm
format: 1
label:  { en: Law firm,   de: Anwaltskanzlei }
description:
  en: Contacts as a practice keeps them — clients, opposing parties, courts.
  de: Kontakte, wie eine Kanzlei sie führt — Mandanten, Gegenparteien, Gerichte.

# What has to be installed already. A pack installs nothing itself: §6.3 refuses
# to chain-install for a reason that applies here word for word, since each
# module carries its own preset choice.
requires: [contact]

shapes:
  contact:
    rename: { en: Client, de: Mandant }          # renameShape()
    fields:
      # Existing fields, adjusted. The key must already be there; `type` and
      # `key` are not sayable, here or anywhere, because §5.4 has no answer for
      # either (§7.2).
      - key: company_name
        label:    { en: Firm or authority, de: Firma oder Behörde }
        position: 10
      - key: birthday
        listed: false

      # New fields. The key must NOT already be there — a key the shape has is
      # skipped and reported, which is §7.2.1's rule arrived at from this side.
      - key: matter_number
        add:      text
        label:    { en: Matter number, de: Aktenzeichen }
        unique:   true
        listed:   true
        position: 20
        options:  { max_length: 32 }

      - key: matter_type
        add:      choice
        label:    { en: Matter type, de: Rechtsgebiet }
        filterable: true
        position: 30
        options:
          choices:                                 # ← nothing can apply this today
            litigation: { en: Litigation, de: Prozess }
            advisory:   { en: Advisory,   de: Beratung }
            notarial:   { en: Notarial,   de: Notariat }

      - key: responsible_partner
        add:      reference
        label:    { en: Responsible partner, de: Verantwortlicher Partner }
        position: 40
        options:
          module:  contact                         # ← nor this
          variant: person
```

Two lines in that file — the two that carry the vertical — are instructions the
system has no way to perform. That is the section above, made concrete.

And here is what the format has nowhere to put, which matters more than what it
has:

- **A collection.** *Matters*, as rows belonging to a client, is the shape a law
  firm actually wants and is the first thing anybody would try to write here.
- **A variant.** "Client" and "opposing party" as *kinds* of contact is
  `ModuleBlueprint::$variantField` plus a field's `variants` list, and neither is
  editable by anybody — `updateField()` does not take `variants` at all.
- **A lifecycle** (§5.8), a **deriver** (§5.9), a **document template** (§5.7), a
  **number series** beyond what the editor's own numbering page does (§5.10), or
  any validation rule other than `required` and `unique`.
- **Translations.** A module ships a catalogue and the installer seeds labels
  through it (§6.1); a pack has no catalogue, so its labels are literals and it has
  to carry a language map or install in one language only. The map above is the
  honest version and it is also the version that goes stale silently.

Which leads to the naming, and it is a decision rather than pedantry: this can be
called a **field pack** and must not be called a *Law Firm*. Something named after
a trade promises the trade; a file that supplies four field names and a rename
would be sold as the first and delivered as the second, and the customer finds out
after they have configured around it. The apply screen shows the literal list of
changes, the way §7.2.1's confirmation does, and the marketing word stays with
[XIV-139]'s packages, where there is a module behind it.

#### Collections are out, and the reason is a table name

The hardest edge, and it does not fall the way the boundary rule alone suggests.
"Only the installer makes a table" is no longer the whole story: since [XIV-70],
`ModuleInstaller::adoptCollection()` creates one on an administrator's click
(§7.2.1). So "DDL on an admin's click" is already sanctioned, and refusing a pack
on that ground alone would be quoting a rule that has moved.

The real objection is narrower and worse. **A table name in an uploaded file is a
claim on an identifier in the customer's database, and it is permanent.**
`createRecordTable()` refuses to adopt a table it did not create — deliberately —
so a pack that creates `contact_matter` in a tenant has made it impossible for any
future *module* of ours declaring that table to ever install there. Not difficult:
impossible, for that customer, for good, with the failure arriving years later as
"this one tenant cannot install Matters" and no way back that does not involve
dropping a table with their data in it. Prefixing uploaded tables would contain it
and is exactly the kind of decision that cannot be un-made afterwards.

So: **fields only, never collections** — which is word for word the rule §6.1
already gives presets, arrived at independently and for an unrelated reason. There
it is because a collection cannot be added back; here it is because a collection
cannot be taken back. Two different arguments landing on the same line is the
strongest evidence available that the line is in the right place.

#### Applied once, and never a standing authority

A pack is a **seed**, on §6.1's terms and for §6.1's reason. It is applied, an
audit line records that it was, and from that instant the customer's definitions
are the truth and the file has no further say. Nothing stores it as the shape of
that tenant, nothing re-applies it, and there is no "update the pack" operation.

The alternative is the thing to name so it stays rejected: a stored, re-appliable
pack is a **second schema authority** sitting over a customer's definitions, and it
reopens every guarantee §6.1 makes — it would want to correct a label somebody
changed, restore a field somebody removed, and reconcile itself on a schedule. That
is not a bigger version of this feature. It is the retro-fit the whole brief
refuses, arriving as a file instead of as a deploy.

Applying once is also what disposes of the third-party-breakage problem [XIV-141]
worried about arriving through the back door. A pack names field keys and a
shipped module's blueprint moves; if the pack is applied once, at a moment somebody
is watching, then drift is a **report** — *this pack expected `company_name` and
this module has no such field; three of its eleven changes were skipped* — rather
than a break. Nothing is left holding a stale reference, because nothing is left
holding anything. A pack we ship in a package ([XIV-139]) is ours to keep in step;
a pack somebody else wrote goes stale, says so on the screen when it does, and
costs the customer a message rather than an outage.

#### Who may apply one, and into what

Two questions that look like one, and the answers are opposite.

**A tenant administrator, into their own installation: yes.** It is the same
authority the metadata editor already grants (§5.4, admin-only), doing the same
edits faster, previewed in full before anything is written. Whoever wrote the file
— a consultant, a partner, the customer's own IT — is irrelevant to the risk,
because the boundary makes the file's *origin* not matter. That is the whole point
of the boundary.

**An operator, into the store, listed for everyone: no.** That is not a bigger
version of the same thing; it re-opens the two questions §3.2 just closed. Whoever
lists it is vouching for it, and a pack that is wrong for a law firm is wrong for
every law firm that installs it — the fact that it cannot execute code limits the
*security* exposure and does nothing about the liability. So the rule is:
**anybody may author a pack; nobody but us may publish one.** Ours travel inside
[XIV-139]'s packages, where they are curated exactly as the module list is;
everybody else's travel as a file the customer chooses to apply, at their own risk,
with a preview.

That also settles the transport question honestly, and it is the least intuitive
conclusion here. §6.1 already puts templates in the control plane as data, which
already means **our** verticals need no deploy. Uploading buys exactly one thing on
top of that: a file that reaches a customer without an operator touching anything.
That is a real benefit and it is a narrow one, and it is not worth designing the
format around — so the format is designed for the control plane first, and uploading
is a second front door onto the same parser whenever somebody wants it.

#### The decision

**Not built, and not refused.** The order is:

1. **The editor learns `choices`, and a reference's `module` and `variant`** —
   §5.4's own unfinished sentence, worth doing on its own merits because the add
   form currently offers two types it cannot configure. Until this exists a pack
   cannot express a vertical, and a pack that cannot express a vertical is a
   feature with no reason to exist.
2. **Templates land** ([XIV-139] and §9.3), with the shape pack as their second
   half, in the control plane, ours. This is where the format is written and where
   it earns its first real use.
3. **Uploading is a separate, later decision**, onto the same format, and nothing
   in steps 1 and 2 forecloses it.

**Checked against [XIV-139] and [XIV-140]**, as XIV-141 required. Neither changes.
[XIV-139]'s "presets travel with it" is the acceptance criterion this section
answers: a vertical that is "Contact with different fields" is expressible as a
*shape pack* carried by the package, and it is **not** expressible as a preset, so
that criterion needs the pack rather than §6.1's presets and should say so.
[XIV-140] is unaffected and its lean is confirmed — packages are the grouping, the
catalogue stays curated (§3.2), and a store designed for a curated set is a store
that never has to answer "who else may put something here".

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
   *Asked from the other side and answered — XIV-88.* Whether the declarative half should
   become a **customer-authored expression** — Symfony's ExpressionLanguage, proposed after
   XIV-27 rejected it for the numbering pattern — was examined across every candidate in the
   system and declined. §5.8 carries the argument and the list; the short version is that a
   guard on a transition is the one place that passes both of the rules this project has
   learned, and that a module declaring one writes PHP, against which an expression string is
   strictly worse.
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

   *How `unique` is actually enforced, since it was asked here* (XIV-109). By a **unique
   expression index** on the customer's own record table, `data ->> 'key'`, partial:
   `WHERE deleted_at IS NULL AND (data ->> 'key') IS NOT NULL`. It is created when the flag
   goes on, dropped when it goes off, and removed with the field — one method,
   `UniqueIndex::follow()`, called by everything that writes the flag, in the same
   transaction as the definition row it belongs to, so a definition claiming a rule the
   database is not keeping cannot exist even for a moment.

   Until then it was a validator that queried the table and let the save proceed, which is a
   read followed by a write with nothing across the gap: under READ COMMITTED two saves
   arriving together both found nothing and both inserted, and two people pressing Save was
   the whole of the reproduction. The validator **stays**, first, and the split is the
   ordinary one — *it produces the readable message and the index is what is true*. Almost
   every duplicate is still caught while the form is open, on the field it belongs to, with
   nothing written; the index fires only on the sliver the validator structurally cannot see,
   and a `UniqueConstraintViolationException` is turned back into a message on that same
   field rather than into a 500 (`DuplicateValue`).

   Three decisions were needed and are recorded here rather than in a ticket.
   **Partial in both directions**: several records with nothing in a field are not colliding,
   and a plain `UNIQUE` would silently make "unique" mean "unique and mandatory"; a
   soft-deleted record keeps its value but must not reserve it, or deleting a contact would
   quietly retire their email address for ever. Both predicates are exactly the validator's
   own WHERE clause, so the two can never disagree about which rows count.
   **Existing duplicates are refused, not fixed**: applying the rule anyway would leave
   records nobody can save — the trap this very question already refuses in general terms —
   and picking a winner would be data loss on a tick box. What changed is that the refusal
   now names the shared values as well as counting the records, because a count on its own
   leaves somebody scrolling a list looking for rows they cannot describe.
   **Built inside the transaction rather than `CONCURRENTLY`**: a concurrent build cannot be
   in a transaction, so the flag and the index could not land as one act, and it *fails soft*
   — a duplicate leaves an `INVALID` index that exists and enforces nothing, which is the
   exact failure being closed. The cost is a `SHARE` lock on one shape's table for the length
   of the build, taken during a deliberate administrative act; §4.2 makes the same trade for
   the deploy-time half.

   Existing customers get theirs from a tenant migration that reads **their own definitions**
   — not the blueprints (§6.1) — and builds one index per unique field. A tenant whose column
   already holds a duplicate stops there, loudly, and is retried with `--slug` once the
   records are fixed; the alternative, skipping the field and logging a line, would leave the
   one customer most likely to be bitten as the one customer with nothing enforcing it.

   What this does **not** answer is `unique` on a collection's field, which the installer
   still refuses outright: unique across the whole table and unique within one parent are
   different rules, and the engine will not guess which was meant. The index would be one
   line either way; the question is not a technical one and is still open.
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
   *Exercised in earnest by §8.11 — XIV-59.* A command that walks every customer to
   count what is in their database is precisely "a process that serves several tenants
   in sequence", and it holds because `TenantSwitcher` drops the identity map, the
   metadata cache and the connection on every switch. What that ticket adds to this
   question is a second requirement nobody had written down: the switch must also
   *close* the connection, or a fan-out over fifty customers ends up attached to all
   fifty and blocks the `DROP DATABASE` an operator is running ([XIV-94]). Leaking
   state across tenants and holding resources across tenants turn out to be the same
   discipline. *XIV-94 changed the punishment, not the rule:* a removal now
   terminates whatever is attached, so a leaked connection no longer stops an
   operator — it gets killed under the collection instead, mid-count, which is a
   quieter failure and not a better one.
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

### 7.2.1 Taking what a module grew, without retro-fitting it (XIV-70)

The additive-only slice question 2 named, built. **§6.1 is unchanged and is the
thing this is built on top of**: installing does not retro-fit, a blueprint is a
seed, and from the moment a module is installed the customer's own definitions
are the truth. Nothing here reaches into anybody's database on a deploy.

What was missing was never the rule; it was that there was no *explicit* way to
say yes. Contact gained an `addresses` collection and a `payment_terms` field
([XIV-67]) after customers already had Contact, and those customers could have
neither — the collection because only the installer makes a table, the field
because nothing offered it. A tenant who installed the `basic` preset had no path
to the extended shape at all. So this is an **offer**: what your copy of a module
is missing relative to the blueprint, shown, chosen item by item, and taken by
somebody with the authority to change what a module is.

**Additive only, and the boundary is what makes it safe.** A field the blueprint
has and the shape has not is offered; a collection the blueprint has and the
module has not is offered, which means creating its table. That is the whole
list. Removing a field the blueprint dropped is *not* here — §5.4 decided that
removal keeps the values, and no module author gets to take a customer's field
away — and a field **changing type** is not here either, which is the half of
question 2 that stays open. Above all, nothing that already exists is touched:
every write is an insert.

**A key the shape already has is never offered**, whatever it now looks like.
That one test is the whole protection a customised field gets, and it is
deliberately cruder than comparing a definition against its blueprint: somebody
whose `phone` is now called "Mobile", four columns wide and no longer required
has made three decisions, and an upgrade clever enough to notice the difference
would be tempted to correct them. So a relabelled field, a changed width, a
relaxed rule and a reordered form all survive by construction rather than by a
rule somebody has to remember.

**The offer is diffed against the blueprint, never against a preset.** That falls
out of §6.1 rather than being a shortcut: nothing records which preset a module
was installed with, deliberately, because storing it would invite something to
re-apply it. It does not need to be recorded — every preset names a subset of the
blueprint's own fields, so "what this customer has, versus what the module
declares" already covers the extended preset's extras without anything
remembering the word *extended*.

#### What "missing" means, once somebody has edited things

The design question of the ticket, because two absences are indistinguishable. A
field the customer **deleted on purpose** looks exactly like one they **never
had**: §5.4's removal takes the definition and leaves nothing behind to tell them
apart with. Guessing is wrong in a way somebody notices, in both directions —
read every absence as "never had" and the offer nags them for ever about a
decision they already made; read it as "deleted" and a field a preset left out is
invisible to the customer who now wants it.

So **nothing is inferred afterwards; a decision is written down at the moment it
is made**, which is the only moment it is unambiguous. Two moments qualify, and
both write to the same place — a `declined_additions` map on the shape's own row:

- **Dismissing an addition** on the upgrade screen. It stops being offered.
- **Removing a field** in the metadata editor. Deleting something is as clear an
  answer to "do you want this" as declining it, and the write happens in
  `MetadataEditor::removeField()` while the intent is still legible.

Dismissals are kept **visible**, in a list of their own with a way back, because
a decision nobody can see is not a decision but a disappearance — the same
promise §5.4 makes by leaving the values behind when a field is removed.

Two consequences are stated rather than hidden. A removal records the key
whatever it is, including a field the blueprint never declared, so a future
module declaring `nickname` does not reopen a question this customer closed —
which is the right answer anyway. And an installation that predates this feature
has **nothing declined**, so the first time an administrator opens the screen
they are shown everything, including things deleted back when there was nowhere
to write the decision down. Asking once is a smaller imposition than nagging for
ever or deciding on somebody's behalf, and a migration cannot know what those
deletions meant.

**On the shape's row rather than in a table of its own**, for the reason
`ModuleDefinition::$followUpsEnabled` gives: "what this customer has, and how it
is set up" is already one question with one answer. It also gets the lifetime
right for free — uninstalling takes the declines with it, and a fresh install is
a fresh choice.

#### Per item, and who may do it

**Per addition rather than all or nothing.** Fifteen additions are fifteen
decisions and a customer is allowed to want four of them; all-or-nothing would be
simpler and would make declining one field cost somebody the other fourteen. It
also costs no state: there is no partial condition to describe, because a tenant
already holds an arbitrary subset of the blueprint — that is what §6.1 says a
tenant *is* — so taking four of fifteen leaves the installation in exactly the
kind of state it was already in. Nothing anywhere records that a module is
"upgraded", and nothing should.

**Administrators, on the metadata editor's authority rather than the store's**
(§5.4, §8.4.3). The store's install grant is about putting a module the customer
does not have into the installation; this changes the shape of every record in
one they already have, which is the sentence §5.4 uses to explain why field
editing is admin-only. It sits under `/m/{module}` beside the field editor and
names no module permission, for the same reason that editor does not.

**Never a side effect of a deploy, and not something an operator does to them.**
There is deliberately no console command. A headless second front door was the
obvious symmetry with `tenant:module:install` (§6.3) and it is the wrong one
here: installing is done *for* a customer at their request, whereas this is a
customer deciding what their own records are, and an operator's shell doing it to
them is precisely the retro-fit §6.1 refuses, wearing a different hat.

#### Two things the confirmation has to say

The middle screen is [XIV-91]'s shape — name what is about to happen, name how
much of it there is, default to no, and require the confirmation in the
controller rather than only as a `required` attribute. Nothing here destroys
anything and it is confirmed anyway, because "a table appears in your database
and every record in this module gains four fields" is a sentence somebody should
read before it is true rather than after.

- **A rule the records could not keep arrives switched off.** A blueprint field
  can be `required`, and every record that already exists is empty in a field
  that has just appeared — installing it required would leave a module nobody can
  save a record in, which is exactly what §5.4 refuses to do to somebody.
  Refusing the *addition* over it would be worse, since a tenant with data could
  then never take a required field at all. So it arrives optional, the page says
  which ones and why, and switching it on afterwards is the editor's existing
  conversation with its existing count and its existing refusal. It is a **count,
  not a policy**: `unique` normally survives, because two records with nothing in
  a field are not duplicates of each other — and it is checked rather than
  reasoned about, because §5.4 leaves values behind when a field is removed, so a
  key can carry duplicates without carrying a definition.
- **A derived field arrives empty.** Nothing here writes a value into a record.
  A field a `ValueDeriver` owns (§5.9) belongs to the engine, and this code
  inventing a plausible total, due date or document number would produce records
  that look right and are wrong (XIV-73). The definition arrives, the record is
  untouched, and the deriver fills it the next time that record is saved — which
  the page says in as many words rather than letting somebody discover it.

**What stays open** is what question 2 still has: a field changing type, purging
the values a removed field left behind, and removing a field the blueprint
dropped. None of the three is additive, and this slice was chosen precisely
because everything in it destroys nothing.


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

**A third thing, found when the layer was pointed at a page that is bound**
(XIV-105). The server Panther starts speaks plain HTTP and cannot be made to speak
anything else, so a route confined to `https` — which the whole signup surface is,
for reasons §8.12 argues at length — is unreachable from this layer as shipped.
Both halves of the fix are outside the application, a compose alias and a router
script handed to `php -S`, and the argument for doing it that way rather than
loosening the routes under `when@test` is in §8.13. The rule it leaves behind is
worth stating here, where somebody will next hit it: **when the browser layer
cannot reach something, ask what the harness is missing before asking what the
application could give up.**

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

#### Whose dashboard it is, and a seam a module can reach (XIV-66)

Everything above stays. What XIV-66 adds is three things that were deliberately
out of scope while there was one implementation to cut the seam around: **the
widget interface moved into core**, **a person arranges their own page**, and
**a panel is fetched separately from the page it is on**.

##### The seam is in `packages/core` now, and only the seam moved

`DashboardWidget` and `WidgetPanel` are `Xivi\Core\Dashboard\`. The obstruction
was structural rather than aesthetic: deptrac's `App` layer is every class under
`App\`, a module package may depend on `Core` and nothing else, so an interface in
the application is an interface `packages/invoice` is forbidden to implement — and
unpaid invoices is probably the most useful thing this product can put on a
landing page. Core declares the seam, the application collects and orders what it
finds, exactly as `ValueDeriver`, `Lifecycle` and `Seed` already work. Core learns
a tag name and nothing else; it still has no idea what a user, a tenant or a
module package is.

**A seam in core does not mean everything using it moves.** `ModuleTilesWidget`
reads the application's own navigation and `FollowUpWidget` reads a table in
`src/`; both are application concerns and both stayed exactly where they were,
implementing a core interface from up there. The temptation to move them with it
is the one worth naming, because "the interface is in core so the implementations
belong there" is a rule that would have dragged the permission resolver and the
tenant context down with them.

The module needed two more things to be genuinely self-sufficient, and both are
one line each rather than a mechanism. `WidgetPanel` carries a **translation
domain**, so a module names its card out of its own catalogue instead of adding a
key to the application's file. And `RecordPageUrl` is the sibling of
`RecordSearchUrl` (§7.6) — core asks where a record's page is and the application
answers with a route — because "12 unpaid invoices" being twelve *links* is the
whole difference between a statistic and a to-do list, and twelve links need
twelve URLs. Without it the module would spell `module_show` in its own Twig
template, which is the §3 boundary leaking out through the one file deptrac
cannot read.

##### A layout is the fourth instance of §8.4.2's chain, not a fourth variation

The person, then the installation (§8.6), then nothing — where nothing is every
widget that applies, in the order the tags declare, which is what every
installation had before the setting existed. `DashboardLayout` is deliberately
`FormattingLocale` and `DisplayTimezone` with a different value in it: same two
collaborators, same `of()` for the whole chain and `fallbackFor()` for the part a
settings page has to name out loud, same handling of a console command that has no
tenant. Both columns are `JSON`, nullable, unbackfilled, and the picker sits on the
two screens the other three already live on — your own on `/account`, the
installation's on the profile page.

**One thing genuinely differs, and it is why the columns are nullable rather than
defaulting to a list.** A language, a region and a zone have no empty value; a
layout does. Null is "has never chosen" and follows the layer below, and `[]` is a
dashboard somebody deliberately cleared. Folding those together would hand
somebody back the page they had just emptied and make the checkboxes look broken.
It is also why going back to the default is a button of its own rather than saving
with nothing ticked, and why the customise link is beside the page heading rather
than among the panels: a link that lived among the widgets would vanish with them,
and the escape §8.4.2's chain owes a person must not be an administrator.

**A default is not a permission.** A widget left out of the installation's layout
is still on offer in everybody's picker. What a person may *see* is §8.4's
question, answered per module against records as well as tiles, and a preference
somebody can edit is not a place to answer it.

**A saved layout is data referring to code, which is the sharp part.** A key can
name a module the customer has uninstalled, a widget a later deploy renamed, or a
class somebody deleted. `Dashboard` drops a key nothing answers to — the same
treatment and the same argument as a stale `reference` (§7.6): the missing thing is
a runtime fact about one customer, not a broken installation, and failing there
would mean a module somebody uninstalled taking the landing page down for everybody
who had ever ticked its box. The key lives on the *panel* rather than on the
interface, so a widget that returns null produces no key at all and "does not apply
to you" and "is not on offer to you" are one fact rather than two that can
disagree. §6.2's rule — a widget for an uninstalled module is not offered — is
therefore enforced nowhere: it falls out of the widget returning null, which is the
only place that fact is known.

##### Deferring, and what makes it worth anything

`loading="defer"` is already in `symfony/ux-live-component`, so the mechanism cost
no dependency. The part that needed designing is that **deferring the rendering
saves nothing on its own.** `panel()` is asked of every widget on every render — it
has to be, because the reader's layout is a list of keys and the keys come from the
panels — so a widget that counted rows in that method would have charged the page
for a card the reader had hidden, and a deferred one would have charged it twice.

So `panel()` is cheap by contract and the panel's data is a **promise** the
renderer resolves only for a panel it is actually drawing. That is XIV-84's line —
the dashboard decides whether a card exists, the card decides what is in it —
restated one level down. Measured on a tenant with the invoice card on it: the
landing page costs the same number of tenant queries with the card as without it,
and the two queries behind it happen on the request that fetches the card.

**The mount is the dashboard's rather than each widget's**, which is the other
half of the module story: `loading="defer"` is an attribute on a Live Component,
and `symfony/ux-live-component` is not a dependency of `packages/invoice`. One
generic `DashboardPanel` component takes a widget key, so a module ships a class
and a plain Twig template with no front-end dependency of any kind. A widget whose
body is *already* a component — the follow-up card — defers on its own mount
instead, because wrapping a deferred component in a deferred component buys a
second round trip for nothing.

**A widget declaring what it costs** stays a question rather than a requirement.
`defer` is a widget saying "this touches the database", which is as much as
anything currently acts on; a number would be a number nothing reads.

##### The "no charts yet" position, narrowed rather than reversed (XIV-121)

XIV-66 declined to add charting and gave the rule that would let something in
later: *"a dashboard that looks sad is fixed by useful numbers and actionable
lists, not by graphics; a chart earns its place where a **trend** is what is
being read, and nowhere else. Add the dependency for the one or two widgets that
need one. If that turns out to be zero, it was never needed."*

It did not turn out to be zero. **An article's price over time is a trend and
nothing else**, and it is the case the rule was written to admit: the same data
as a table — "on 3 March, 100.00 became 120.00" — is already on the record page
twice, in the history card and on the timeline page, and nobody reads it as a
series, because a column of numbers is not a shape. So the chart is not a second
way of showing what is shown; it is the one reading of that data a table cannot
give. `symfony/ux-chartjs` is in (MIT; Chart.js MIT, self-hosted through
AssetMapper like everything else — §8.3's no-CDN promise is unchanged).

**What is narrowed and what still stands.** The dependency is now paid for, so
the argument against the *next* chart can no longer be "it costs a dependency".
It has to be XIV-66's actual rule, which is unchanged: a chart is for a trend.
Dashboards of charts, revenue over time and anything aggregating across records
are still refused here — not because they cannot be drawn now, but because each
is a different design with a different subject and a different permission
question, and none of them has been through it.

**Where it went, and why not the dashboard.** A price trend is about *that*
article, so it is a card on the article's own page. A dashboard is what somebody
sees before they have picked anything, so a price chart there would need a
subject chosen for the reader — "which article?" is a question with no obvious
answer and is a feature of its own.

**It is not a chart of `price`, it is a chart of a numeric field.** One chart
wired to one field of one module would have been a special case with a
dependency attached. `Xivi\Core\History\FieldTrends` takes a module and a record
and answers for every field whose recorded values are numbers; the article
module contains not one line about charts. Which field is drawn is a picker on
the card, and its default is the field with the most changes, so the card is
useful before anybody touches it.

**Numeric is read off the values rather than declared**, which is §5's rule
about not growing a switch on field type, applied here. A field type added later
is plottable the day it stores numbers, and a *customer's own* field (§6.1) gets
a trend with no deploy. The one exclusion is a reference, whose value is another
record's id — a number that means nothing on an axis — and it is excluded by
asking the type whether it names a record (`LinksToRecord`, XIV-42) rather than
by knowing the word `reference`. A `choice` field somebody has filled with bare
numbers is deliberately *not* excluded: over-including costs one entry in a
picker, under-including costs a ticket.

**The card is the dashboard split, one level down.** §8.3.1's line — the page
decides whether a card exists, the card decides what is in it — is applied to a
record instead of a dashboard, and inverted in one respect worth naming: the
record page mounts this unconditionally and the *card* decides whether it
exists, because whether a module takes follow-ups is a flag on the definition
and whether a record has anything numeric to draw is not. The card answers it
from the definitions before it queries anything, so a contact page pays a
construction and no round trip.

**A control on a card is the card's, not the URL's** (XIV-84 again, and the same
sentence): which of two numbers somebody is looking at is not navigation, is not
worth a back-button entry and is not a link anybody sends a colleague.

**Permissions are asked twice and the second answer is silence.** A chart is a
number about records, so a reader must see nothing here about a record they may
not open (§8.4). The record page has voted `view` on the record; the component
asks again at its own endpoint, record-level rather than module-level, because
props are signed rather than secret. It **draws nothing** rather than refusing:
a controller answers a page and can answer 404, but there is no reading of "404"
a card inside somebody else's page can perform — thrown from a template it
becomes a 500, which is a worse outcome for exactly the same disclosure, namely
none.

**The degenerate cases are the common ones.** A catalogue is mostly articles
nobody has ever edited, so "one change, or none" is not an edge case. A record
whose price never changed draws a flat line from its creation to now and a
sentence saying since when — an empty box would be a question about whether the
feature is broken, where "100.00 since 3 March, unchanged" is a real answer to
"what was this in March". The line always runs to *now* rather than stopping at
the last change, because a line that stopped there leaves the reader to infer
from an absence that the last value is still in force.

**Chart.js is loaded lazily, and that is the difference between the cost above
and a real one.** `assets/controllers.json` marks the chart controller `fetch:
lazy` — the only controller in this application that is not eager — so the
library is imported when a page contains a canvas asking for it and on no other
page. The sign-in page, the dashboard, every list and every record of a module
with no numbers on it are byte-for-byte what they were. Eager would have put
200 KB of JavaScript on all of them for a card that appears on some article
pages.

**Which is why there is a browser test.** Lazy loading, the stepped
configuration and the small controller that formats the axis all fail the same
way — a blank box, a message in a console nobody is reading, and a green suite,
because every other test asserts what the *server* put in the page. The browser
test reads the canvas back and counts the pixels that are not transparent, which
is true only if the dynamic import resolved, the controller connected, the
configuration was accepted and a line was painted. §8.3's warning about this
layer stands and is why it is one assertion rather than a suite.

**What it cost, measured against the built image.** `symfony/ux-chartjs` v3.4.0
plus Chart.js 4.5.1 and `@kurkle/color`: **+586 KB inside `frankenphp_public`**
(235 KB of AssetMapper sources, 243 KB of the compiled copies under
`public/assets`, 27 KB of PHP, and the rest autoloader and warmed cache), which
is **+175 KB, or +0.17%, on the image's own reported size** of 103 MB. A browser
downloads about 208 KB of JavaScript, once, from this installation's own host.


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

**Not somewhere a customer-authored expression can go**, and that is now written
down rather than left to be worked out again (XIV-88, §5.8). The third seam above
is a `WHERE` clause; an expression evaluates in PHP over a record already loaded,
so a rule written as one would restrict the page and not the total beside it —
which is this section's opening sentence, arriving through a new door.

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

*A third verb since §8.15 ([XIV-102]).* `buy` — may ask for a module that costs
money — and the interesting part is that it is a case rather than a third axis:
the subject is still `@store`, the scope still does not apply, and the permission
screens draw it because they iterate the enum. Splitting it from `install` is the
decision, and §8.15 has the argument: one is authority over what this installation
consists of and the other is authority over the company's money, and in a small
company they belong to different people more often than not. It does **not** imply
`install` — a purchase request installs nothing at all — and the paragraph above
applies to it word for word, `grant-all` included.

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

**The document half landed as XIV-89** and is written up in §5.7, where the
reasoning belongs, because it turned out to be a change to the .docx pipeline
rather than an addition to the marker list. Two things decided there report back
here. The mark is drawn at its natural size at 96 dpi, capped to fit 40 × 20 mm
and never enlarged — and **that still does not want a second upload**: fitting
rather than stretching gives a wide wordmark and a square crest a sensible answer
from the one file, so the case for a second field remains what it was above,
about wanting a different *picture* rather than the same one at a different size.
And the engine reads these bytes out of `TenantProfile` directly: the route this
section adds is for a page, and a document is generated without a browser.

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

**That sentence needs one correction now XIV-64 has landed** (§8.12), because it
predicted the wrong ticket. Signup does not provision, so nothing here is invoked
by it: the first user is created when [XIV-98] turns a confirmed signup into a
tenant, and that is where the request-context problem is still waiting.

**[XIV-98] has landed and answered it** (§8.14): the router's request context is
pointed at the tenant's own hostname for the duration of the send and restored
afterwards, because that loop runs over many customers in one process and a
leaked context would sign the next person's link for the previous person's
domain. The port is left as `DEFAULT_URI` put it, on the argument that the host
is the part only the tenant can supply and the port is a property of the
installation. The same ticket found a second thing this paragraph did not
predict: off a cron there is no locale either, and the answer is the language the
visitor was reading the signup form in.

The signup's own confirmation mail is deliberately none of this mechanism — there is
no tenant, therefore no user to sign a link for — and it builds its absolute URL
from configuration rather than from a request for the same reason this paragraph
warns about.

### 8.9 An operator is not a tenant user (XIV-57)

Everything above this section is about people who belong to one customer. §8.1 puts
them in that customer's own database and binds the security provider to the tenant
entity manager, so *who is admin@example.com* is a question only one database can
answer; §8.2 stamps the session with the tenant it was minted for, because those
identifiers collide across customers. Neither of those is a precaution. They are
the reason a cross-tenant leak is structurally impossible here rather than
carefully avoided: a request resolves exactly one tenant and can only ever see that
one.

**An operator is the first identity that does not fit that shape**, and it does not
fit for a reason that cannot be engineered away: their subject matter is *the set
of tenants*. Somebody who has to look at the registry, provision a customer or read
why a migration failed is by definition not about one customer, so there is no
tenant database that is the right place to keep them.

So: **an operator is a row in the control-plane database** — its own entity
(`Xivi\ControlPlane\Entity\Operator`), its own provider, its own firewall, its own
host. Nothing about a tenant user changes.

#### Two alternatives, rejected

**A promoted user of a designated tenant.** Nominate one customer's installation as
the administrative one and give a `ROLE_OPERATOR` there platform-wide powers. It
needs no new table, no new firewall and no new host, which is the whole of its
appeal. It is rejected because it makes one customer's database the key to every
other customer's: whoever can write to that tenant's `app_user` table — a bug in a
user screen, a stray SQL grant, a compromised administrator of what might be the
smallest customer on the platform — is an operator. It also inverts the ownership
the rest of §8 is built on. The rows in a customer's database are the customer's,
and an identity that governs their competitors is not.

**No accounts at all.** Bind the control plane to loopback and reach it over an SSH
tunnel, or put an authenticating proxy in front of it. Honest for exactly one
operator on exactly one machine, and it is a real answer for that case — which is
why it is worth recording rather than dismissing. It is rejected because the second
operator turns it into a migration: at that point there is no way to say who did
what, no way to remove one person's access without rotating everybody's, and the
work of adding accounts has to be done anyway, on a system that has since acquired
screens built on the assumption that whoever reached them is trusted.

#### The invariant: the firewall's *order* is the security boundary

The `main` firewall has no `host:` restriction, so it matches every request, and
Symfony takes the first firewall whose matcher accepts. **The control-plane
firewall is therefore declared above it, and that ordering is the boundary.** Move
it below and a control-plane sign-in falls through to `main`, whose provider is
`tenant_users` — so an operator's password would be checked against
`app_user` in whichever customer's database the hostname resolved to. That is
precisely the leak §8.1 and §8.2 exist to prevent, arriving through a line moved in
a YAML file rather than through a design mistake.

A comment saying "do not reorder these" is read by everybody except the person who
reorders them, so `ControlPlaneFirewallTest` asks the **compiled firewall map**
which firewall takes a control-plane request and which provider it would
authenticate against, and `ControlPlaneSignInTest` gives the same email address a
different password on each side and proves the tenant's one is refused. The
ordering fails the build rather than shipping.

**The firewall is host-scoped by a request matcher rather than by `host:`.** That
key is a regular expression, and a hostname written into one is a pattern in which
every dot matches any character — `control.example.com` also accepts
`controlXexample.com`, a name somebody else can own.
`Xivi\ControlPlane\Security\ControlPlaneHost` compares normalised strings instead,
through `TenantResolver::normalize()`, which is the same function tenancy uses to
decide that a host is served without a tenant. One normalisation, so the firewall
matches exactly the host on which no tenant resolves.

#### Where it is served, and what makes it resolve no tenant

`CONTROL_PLANE_HOST` names it, and that parameter is written into
`app.system_hosts` in `config/services.yaml`. That is the whole mechanism for "a
control-plane request resolves no tenant" — §4's existing one, not a second: the
tenancy listener checks that list before it consults the registry, clears the
tenant connection and leaves it deliberately unusable. Reusing it rather than
inventing a parallel rule is what stops the two from ever disagreeing, and it means
the deployment step is one variable rather than two things to keep in step (see
*Running an installation → Hostnames* on the documentation site).

Provisioning refuses to route a tenant to any host on that list. Without the
refusal the mistake is silent in the worst way available: the row is created, the
tenancy listener never consults it, and that customer's users are shown the
platform's sign-in page instead of their own.

##### The hostname is not one of the boundaries (XIV-93)

Written down here because the paragraphs above read as though it were, and
because [XIV-93] was reported from inside this ticket rather than worked around.

**Anybody who can set `Host:` to the control-plane hostname reaches the
control-plane sign-in page**, from any address that terminates the connection,
not only from the name DNS points at. That was true before this application
configured `framework.trusted_hosts` and it is still true after: the
control-plane host is by definition one of the hostnames this installation
answers to, so it is *inside* the trusted-host pattern rather than outside it, and
a pattern cannot tell a request that arrived on the right address from one that
wrote the right string in a header. §4.3 has the full argument, including why
nothing about the topology in §4 can change it.

It is not a leak, and the reason is the three layers below rather than anything
about the name. The credential presented there is answerable only by the control
plane's own provider, no tenant resolves on that host, and `access_control` wants
a role no customer's database can grant — every one of which applies to a request
that arrived with a forged `Host` exactly as it applies to one that did not. What
the hostname buys is obscurity, which is why this section still asks for one that
is not guessable from the customer-facing domain.

**So "no tenant hostname can reach a control-plane route" is a narrower
guarantee than it reads.** What holds is that no *route* exists on another host
and no *tenant credential* is answered here. What does not hold, and never did,
is that only requests genuinely addressed to the control-plane name arrive at it.

Three layers keep a customer away from a control-plane route, and they are worth
distinguishing because only the first two are boundaries:

1. **The route does not exist on their hostname.** `ControlPlaneRequestListener`
   answers 404 for `/control/…` anywhere but the control-plane host, and for
   anything *but* `/control/…` on it. A 404 rather than a 403, because the path
   really is not there and because a 403 confirms there is something worth being
   refused from. It is a listener rather than a `host:` on the routes only because
   Symfony forbids environment placeholders in routing configuration, so a
   host-scoped route would have to carry a hostname compiled into the source.
2. **The credential is answered by the control plane.** The firewall above.
3. **`access_control` asks for `ROLE_OPERATOR`.** Third, and the weakest of the
   three by construction: a role is a string in a customer's own database, and
   nothing stops a customer's administrator writing `ROLE_OPERATOR` into their own
   row. The test suite creates exactly that person and proves they are still
   nobody here. Correspondingly, an operator holds **no `ROLE_USER`**, so the `^/`
   rule that guards the tenant application refuses them — which is why an operator
   who wanders towards a tenant screen is told no rather than collecting a 500
   from a connection that has no tenant behind it.

##### A fourth layer, and it is the only one in front (XIV-124)

The three above are all checks made **after the request has arrived**, by the
surface that can see every customer. That is not a criticism of them — they are
the layers that decide who gets in, and they hold against a forged `Host`
exactly as they hold against a real one. It is an observation about what was
missing, which is anything at all in *front*: until this ticket, the operator
console was a password prompt that the whole internet could attempt, and the
paragraph above says in as many words that no hostname setting changes that.

**`CONTROL_PLANE_ALLOWED_IPS` is a list of addresses and CIDR ranges, and a
request to the control-plane host from anywhere else is refused before anything
else looks at it.** `App\Deployment\ControlPlaneAllowList` holds the policy and
`Xivi\ControlPlane\EventListener\ControlPlaneAddressListener` applies it at
`kernel.request` priority 101 — ahead of `TenantRequestListener` (100) and of
`ControlPlaneRequestListener` (99), so an address that is not on the list cannot
make this installation consult its registry, resolve a route, touch a session or
build a firewall listener.

**It is the outermost of four and a replacement for none of them.** As the only
layer it would be bad design: an address is a claim about a network, and networks
are borrowed, shared behind one office NAT and spoofable on unfiltered paths. As
the fourth it is worth having, because it turns "anybody may attempt a password"
into "anybody on this list may attempt a password". Nothing about the firewall
ordering changed, `ControlPlaneFirewallTest` is untouched by this ticket, and
`ControlPlaneAllowListTest` asserts that an admitted address still lands on the
sign-in page rather than past it.

**Empty is the default and means no restriction**, which is `PlaceholderSecretGuard`'s
rule (§4.2) and `TrustedHosts`' (§4.3) for their reason: `bin/compose up`, the
suite and `bin/ci` all run on addresses no operator would ever write down, and
the listener returns before it reads anything at all when the variable is empty —
so "an installation that sets nothing behaves exactly as before" is a property of
the code rather than a claim about it.

###### The address comes from Symfony, and that is the whole of the design

`REMOTE_ADDR` is the *proxy's* address when there is a proxy, so the address this
is decided on is `Request::getClientIp()` — which consults `X-Forwarded-For` only
from an address in `TRUSTED_PROXIES` and only because §4.3 decided to believe
that header. **Nothing here reads a header.**

That is not a smaller version of the same thing. An allow-list built on a header
anybody can set is **worse than no allow-list at all**, because it admits
everybody who has read this repository while looking exactly like a restriction
to whoever configured it. Two tests hold it from both sides and only mean
anything together: with nothing trusted in front — the shipped topology — a
forged `X-Forwarded-For` naming an admitted address is ignored and the caller is
refused on the address their connection came from; with `TRUSTED_PROXIES` naming
that connection, the very same header *is* believed and the same caller is
admitted. The first alone would also pass for a listener that never looked at
forwarded headers, which would be wrong in the other direction: a deployment
behind a balancer would have to allow-list the balancer, which admits everybody
behind it.

Ranges are matched with `IpUtils::checkIp()` rather than compared as strings —
an office is a range and a VPN is a range, IPv4 and IPv6 both work, and `::1` and
`0:0:0:0:0:0:0:1` are the same address whatever form a network happens to present.

###### A refusal says nothing and the log says everything

An empty **403**, which is the line `UntrustedHostListener` already draws for
§4.3's 400 rather than a second convention. Whoever is refused is by definition
not somebody this installation admits, and a body naming the variable — or
admitting that an allow-list exists — would be telling the one audience that
should not be told.

A 403 rather than the 404 `ControlPlaneRequestListener` uses beside it, because
those are different sentences: that listener answers 404 because the path
genuinely is not there on that host, and here the path is there and the caller is
not welcome. The distinction is for the operator who has locked *themselves* out,
who otherwise sees exactly what they would see if the control plane had never
been deployed. It covers every path on that host, including the assets and
profiler paths `ControlPlaneRequestListener` deliberately stands aside for: those
are exempt from *which host serves them*, not from *who may connect*, and one
answer for every path is also one that draws no map.

The `error` line names the resolved address, the variable, what it admits, and
any entry that is not an address. **It also names `REMOTE_ADDR` beside the
resolved address**, which is the part that pays for itself: when the two are
equal on an installation the operator swears is behind a load balancer,
`TRUSTED_PROXIES` has not been set and every request in the installation is being
attributed to the balancer. That presents as "the allow-list refuses my office
and my office address is correct", and this line answers it in one glance.

###### Where it is enforced, and the one that was not built

**A listener, not the Caddyfile.** The web server is genuinely stronger — a
request refused there never reaches PHP — and the two are not exclusive, so
*Running an installation → The control plane* on the documentation site shows the
Caddy block for an operator who wants both. What shipped is the application's,
for three reasons about this codebase rather than about web servers. It **travels
with the code**, where a rule in a separately maintained Caddyfile can be a
release behind or absent with nothing here able to tell. It is **testable**,
which the assertion about a forged header above depends on entirely. And it
**inherits `TRUSTED_PROXIES`**, where Caddy would need to be told separately, in
its own syntax, which upstream may speak for a client — a second copy of §4.3's
decision and therefore a second place for it to be wrong.

###### Locking yourself out is the cost, and it is named rather than solved

**An operator who sets this wrong cannot sign in to fix it**, and unlike a
too-narrow trusted-host pattern there is no customer-facing symptom to notice
first: every customer keeps working, every dashboard stays green, and the only
sign is a 403 on a console one person visits — at whatever hour they next need
it, which by the nature of consoles is an hour when something is already wrong.

Three things reduce it and none of them removes it:

- **`deploy:check-control-plane` reports the list before anything depends on
  it**, in the shape `deploy:check-hosts` and `deploy:check-secrets` established
  and on their exit-code convention (0, and 3 for "would refuse"). It reads only
  the environment, so it answers from the same values the listener will see.
  `--address=…` asks about one address in particular; without it, the command
  offers the address `SSH_CONNECTION` says this shell came from, with the caveat
  attached — that is where the *shell* came from and it equals the browser's
  address only if both leave by the same route.
- **An entry that is not an address does not switch the restriction off.** It is
  dropped, remembered, printed by the command and named in the log, and the list
  stays in force. The two alternatives are worse: treating an all-invalid list as
  unconfigured is a restriction that silently stops restricting while its author
  believes in it, and *throwing* — which is what `TrustedHosts` does — would be a
  500 for every customer of the installation over one mistyped character in a
  variable about the operator's own console. §4.3's asymmetry, one step along.
- **The console is always the way back**, as it is for the last operator above:
  the variable is a deployment's own, and `deploy:check-control-plane` run on the
  box says what the running process actually has.

What is *not* claimed is that this is safe to set unattended. **An operator who
never runs the check can still lock themselves out**, and the honest mitigation
is that the door back in is a shell rather than a browser. That is the cost of
the layer, it is accepted rather than engineered away, and it is written down
here so that nobody has to rediscover it at two in the morning.

###### Deliberately not in this

**No per-operator addresses.** The list is the installation's, not an account's.
A column on `operator` would be a second place for the answer to live and would
be consulted only after the credential has been read, which is a layer in the
wrong place — the point of this one is that it decides before anything else does.

**No allow-list on the tenant application.** Customers are served on the
internet; that is what the product is.

**No `--fix`, and no way to widen the list from inside the application**, for
§4.3's reason: a running instance that could edit which addresses may reach its
own console could be made to admit anything.

#### Sessions

Separate firewalls have separate session contexts, so a token minted for an
operator is stored under a key `main` does not look for and vice versa. Symfony
would have given that for nothing, since a firewall's context defaults to its own
name — and both are written out in `security.yaml` anyway, because "an operator
session and a tenant session are not interchangeable" is a security property and
one holding only because nobody has changed a default is one line of somebody
else's release notes away from not holding. `TenantSessionGuard` covers the same
ground from the other side: a session stamped for a tenant, replayed on a host that
resolves none, is discarded.

#### An operator can be revoked, and it deactivates rather than deletes (XIV-92)

Everything above builds one account and never touches it again.
`control:operator:create` made an operator; nothing removed one, disabled one,
changed a password, or even said who existed. Every one of those was a statement
typed into `psql`.

§4.1 makes that argument about tenants and it lands harder here. **An operator is
the identity with the most reach in the installation, and it is the last one that
should need a database client to withdraw** — because revoking one is, by
construction, done in a hurry. Somebody has left, or a credential has leaked. That
is not the moment to be composing SQL against a table whose name is being checked
while it is typed.

So there are four commands, and they are one ticket rather than four because the
schema question decides all of their shapes:

| Command | What it does |
|---|---|
| `control:operator:list` | Who can sign in, revoked accounts included and marked |
| `control:operator:revoke <email>` | Withdraws access, keeps the account |
| `control:operator:restore <email>` | Puts it back, with the password it had |
| `control:operator:password <email>` | A new password, without asking for the old one |

##### Deactivate or delete: the argument is not inherited from §8.4.1

§8.4.1 settles this for a tenant user in one sentence — *deactivating locks the
person out, keeps every record attributable, and is reversible* — and **that
sentence does not carry over on its own.** Its load-bearing half is attribution:
records carry the id of whoever owns them and history carries the id of whoever
made each change, so deleting a tenant user leaves records belonging to nobody.
[XIV-57] looked at exactly that and concluded, correctly for the time, that
nothing in the control plane attributes anything to an operator, so revoking one
could be deleting the row.

That is still true today. Nothing in the control-plane schema references
`operator`. If attribution were the only argument, deletion would win, and the
`active` flag would be the promise-nothing-keeps that [XIV-57] refused to make.

Three other arguments carry it instead, and none of them is §8.4.1's.

**Deletion is the one lifecycle step nobody can undo, in the one situation where
people are moving fastest.** The address being revoked is often half-read off a
chat message during an incident. A wrong `revoke` is a sentence and a
`control:operator:restore`; a wrong `DELETE` is an account that has to be
recreated, with a new password, by somebody who now has two problems. That
asymmetry is the whole of why the reversible verb is the one that ships.

**The flag has to arrive before anything references an operator, not after.**
[XIV-98] provisions a tenant from a confirmed signup and [XIV-59]'s collection
surfaces sit next to it; the first column anybody will want on those rows is
*which operator did this*. The moment one exists, a deletable operator forces a
choice between `ON DELETE SET NULL` — an audit trail that erases itself exactly
when somebody is revoked in a hurry — and a foreign key that refuses the
revocation, which turns revoking back into a `psql` job. Both of those are
discovered with the schema already in production, and the migration that fixes
them is run at the worst possible time. Landing the column first costs one
`ALTER TABLE` today.

**And two answers to "somebody left" in one codebase is a cost by itself.** A
reader who knows how a tenant user is removed should not have to check whether an
operator works the other way round.

**What is given up** is that a row created by a typo is now permanent. The answer
is that `control:operator:list` makes it visible rather than invisible, that the
row holds a name, an address and a hash and nothing else, and that a second
irreversible command sitting next to the reversible one is the command somebody
types by accident. There is deliberately **no** `control:operator:delete`; §8.4.1
does not offer a delete button either, and shipping one here would make the flag
optional in the same week it was introduced.

##### What a revoked operator can still reach: nothing, and it took two mechanisms

**`Operator::active` is enforced twice, and neither mechanism covers the other's
case.** This is the same pair `User::active` needed (§8.4.1) and it is here for
the same framework reason rather than by imitation:

- **`ActiveOperatorChecker`**, wired as the `control_plane` firewall's
  `user_checker`, refuses the sign-in. It says the account was *revoked* rather
  than that the credentials were wrong, because a former operator otherwise goes
  looking for the password reset this surface does not have.
- **`RevokedOperatorListener`** ends a session that already exists.

The second is the part that had to be established rather than reasoned about.
"Symfony refreshes the user from the provider on every request" is true and
strongly suggests that a revoked account falls out at the next click. It does
not: `ContextListener::refreshUser()` compares the stored user against the
reloaded one on **identifier, password and roles**, and `active` is none of the
three. Run with the listener removed and the checker in place, a revoked
operator's next request returns 200 and renders the tenant list with their name
in the topbar — every customer's hostname, plan and usage, for as long as the
session lasts. That was watched happening; the listener was written against it.

**A password change needed no listener at all**, and the asymmetry is worth
knowing rather than rediscovering: the hash *is* one of the three things
`ContextListener` compares, so `control:operator:password` signs every live
session out on its own. Both behaviours are covered by
`ControlPlaneRevocationTest`, the second precisely because behaviour nobody wrote
is behaviour a framework release can take away quietly.

`EquatableInterface` on `Operator` would have done the revocation case in one
method and was not taken, for the reason §8.4.1 gives on the tenant side: it
replaces the framework's whole change comparison, so this package would silently
become responsible for the password case above too.

##### The last operator

**Revoking the last operator who can still sign in is refused, with no `--force`
past it.** The control plane has no sign-up, no invitation and no password reset,
so the web has no way back in at all.

The refusal counts **active operators, not rows**, and that distinction is the
whole of it. A guard written as "refuse when only one operator exists" is
defeated by revoking two accounts in turn: with two rows present, the first
revocation passes the count and so does the second, leaving an installation with
two operator accounts and nobody who can sign in. Counting what is still active
makes the *second* call the one that is refused — which is the call somebody
would actually be making. `OperatorManagerTest` states that arrangement outright,
against a repository nothing else can write to, because the control-plane
database is shared by every parallel test worker and a test of a count run
against a table other tests are writing to passes for reasons it did not intend.

The guard is absolute rather than overridable, which is a deliberate departure
from `tenant:deprovision`'s `--force` (§4.1). That command needs an escape hatch
because removing a customer unattended is a real operation; there is no
legitimate shape of "remove the last operator" that this refusal blocks. The
person leaving has a successor, who is created first; the installation being
decommissioned loses nothing by leaving a row in a database that is about to be
dropped. An escape hatch here would exist only to be typed by the person the
guard is for.

It is worth being honest about what the guard is *not*. It is not protection
against a catastrophe — whoever could type the revocation can type
`control:operator:create`, so the console is always a way back. It guards against
the accident, which is somebody revoking the wrong one of two addresses at speed.

Nothing guards self-revocation, because there is no *self* at a console: these
commands run with no session and no signed-in operator to compare against. §8.4.1
refuses an administrator deactivating their own account precisely because that
click comes from a session; the equivalent here would be a guess about who is
holding the keyboard.

##### `control:operator:create` on an existing address stays an error

The convenient reading is that creating over an existing address should just set
a new password — one verb, no second command to remember. It is refused, and
`control:operator:password` exists so that the refusal costs nothing.

Two reasons, and the second decides it. A **typo'd address becomes
indistinguishable from a rotation**: type `alice@exmaple.com` for
`alice@example.com` and an overloaded `create` reports success either way, in one
case having changed a password and in the other having minted a second identity
with the reach of the first, at an address nobody owns. And it would **silently
reinstate a revoked account** — writing a working password onto a row whose whole
point is that it no longer works, through a command that never mentions
revocation. So the two situations get different sentences, and each names the
command that does what the person was trying to do.

#### What is deliberately not built yet

**No permission model.** Every operator has `ROLE_OPERATOR` and nothing else. There
is one kind of operator so far, and a read-only or billing-only operator is a
distinction to draw when there is a second kind to draw it against — §8.4's
catalogue exists because modules and verbs were both real by then.

**~~No `active` flag and no way to revoke one from the console.~~ Built** by
[XIV-92], and the section above is what replaced it. The gap was named here on
the grounds that an operator who cannot remove an account from the console will
remove it in `psql`; what shipped is deactivation rather than the
`control:operator:delete` this paragraph anticipated, plus a password change, a
listing, and a refusal to revoke the last operator who can still sign in. There
is deliberately still no delete.

**No screen for any of it.** The four lifecycle commands are console-only, like
the create they join. An operator page is a small step from §8.10's tenant list
and is where these belong eventually, which is why every refusal lives in
`OperatorManager` rather than in a command — the page inherits them instead of
reimplementing them.

**No record of who revoked whom.** There is no actor to record: a console command
has no signed-in operator, and inventing one would mean either an `--as` flag
nobody can be held to or a guess. When there is a screen there is an actor, and
that is the moment to record one — see the argument above for why the `active`
column landing *before* anything attributes anything to an operator is what makes
that cheap to add.

**No invitations and no sign-up.** `control:operator:create` is the only way an
operator comes into existence. Invitations exist on the tenant side (§8.8) because
an administrator has colleagues to admit and no way to hand them a password; an
operator is created at a console by somebody who already has one, and a mailed link
admitting its holder to every customer's registry is not a convenience worth
inventing before anybody has asked for it. The password is **asked for rather than
generated**, which departs from §8.5 deliberately: a generated password is safe
there because `must_change_password` holds the account until its owner replaces it,
and the control plane has no account page to hold anybody on. **That argument now
has a command behind it rather than only an absence** ([XIV-92]): asking for the
password was the right call when there was no way to rotate one at all, and
`control:operator:password` is that way. It does not change the decision — a
generated credential is still one two people know — but it removes the corner the
original reasoning was standing in.

**No page.** Signing in lands on a placeholder that says what it is and what
replaces it, which is [XIV-58], the tenant list. That is the expected shape of this
ticket, not an unfinished edge of it — the same shape `DashboardController` had
before there were modules to show. **That placeholder is gone**; §8.10 is what
took its place.

### 8.10 The tenant list, and the boundary it keeps (XIV-58)

The page an operator lands on is the registry, drawn as a table: name and slug,
status, plan, primary domain, created and provisioned, enabled modules. Every
column of it is a field of the `tenant` row, which is the whole design and is
worth saying out loud rather than treating as a coincidence of what happened to
be easy.

`tenant:list` **still works and was not replaced.** A headless deployment has no
browser, and the command is what somebody has in an SSH session at three in the
morning. What the page adds is not the data.

#### One request, one database — and here that database is the control plane's

**This page opens no tenant connection at all.** §4 makes that sentence true of
every request in the application, and §8.9 makes it true of this host in the
strongest available way: a control-plane request resolves no tenant, so the
`tenant` connection is not merely unused but deliberately unusable, and anything
touching it gets `NoTenantResolvedException` rather than the previous customer's
database.

That property is not a side effect of the columns this page happens to show. It
is the reason [XIV-59] — how many users a customer has, when anybody last signed
in, how many records are in there — is a design problem rather than a `LEFT
JOIN`. Those figures live in the customers' own databases, one connection each,
and a page listing forty tenants that fetched them inline would open forty
connections to draw a column. There are several defensible answers to that — a
roll-up written back to the registry on a schedule, an on-demand figure for one
tenant, an explicit per-row fetch the reader asks for — and **none of them can be
chosen honestly while a join looks available.** The first person who wants "just
the user count" here will find it is one line away and that nothing in the file
physically stops them. What stops them is knowing why it is not there, which is
why the argument is in `TenantListController`'s docblock as well as in this
paragraph.

`TenantListTest` proves it rather than asserting it in prose. Three things
together: no tenant is resolved after the request; the tenant connection was left
unopened by a request that rendered every row; and touching that connection
afterwards throws — which is what stops the second from being a statement about
DBAL's laziness. The fixtures compound it. **The three tenants it lists have no
databases at all** — rows written straight into the registry, with DSNs naming a
host that does not resolve — so a page that connected would not be quietly wrong,
it would be red. Provisioning three real customers would have been the more
realistic fixture and a strictly weaker instrument.

#### The row also carries a credential, and the defence is a type

A `Tenant` holds `database_dsn` and `database_password`. Neither belongs on this
page or in its HTML, and neither ever arrives there on purpose: it arrives as a
`|json_encode` into a Stimulus data attribute, a `dump()` left in a template, a
serializer normalising an entity for a fragment, a profiler panel on a page
somebody pastes into a chat. Every one of those is a mistake that reads as
harmless while it is being made, so "be careful in the template" is not a
control.

**So the entity never reaches the template.** `Xivi\ControlPlane\View\TenantSummary`
is a readonly object of seven scalars and two arrays, with a private constructor
and one static factory — the single place in the codebase that reads a `Tenant`
for this page, and it does not read those two columns. Dump it, encode it, hand it
to a JavaScript component: there is no credential in it. That is a property of the
type rather than of whoever edits the template next, which is the only kind worth
having.

The test asserts it from the other side anyway, over the headers as well as the
body, and looks for the DSN's *parts* as well as the whole so that a "which server
is this customer on" column parsed out of the DSN still fails. `TenantLogoTest`
set exactly this shape in XIV-49, for a tenant settings row that holds an SMTP
password beside the one column that is deliberately public. Both halves are
wanted: the type makes the leak impossible, and the test notices when somebody
decides the entity would be more convenient after all.

#### Status is designed around, not printed

A registry sorted by name is one in which a tenant stuck in `provisioning` since
Tuesday sits on the third screen between two healthy customers, in a cell that
looks like every other cell. Provisioning is measured in seconds, so a tenant
found in that state by somebody loading a page is not mid-flight — it is what a
run that died halfway leaves behind (§4.1), and it is the single thing an operator
wants to see from the doorway.

Two things carry that, and both are needed:

1. **The table is ordered by `TenantStatus::attentionRank()` first and by name
   second.** The rank is a `match` on the enum rather than an `ORDER BY status`,
   because the stored strings sort alphabetically — `active`, `provisioning`,
   `suspended`, `trial` — which puts the healthy majority on top by accident of
   spelling. Provisioning outranks suspended: both stop a customer being served,
   but somebody *chose* the second. The rank is deliberately not derived from
   `servesRequests()`, which collapses those two; that predicate answers "may this
   hostname be served" and this one answers "who should read this row first", and
   a status added later can move in one without moving in the other.
2. **The page opens with a line saying how many customers are not being served,
   and naming them** — and is drawn only when that number is not zero. A banner
   permanently reading "0 customers are not being served" is furniture, and
   furniture is what the eye learns to skip. "Not being served" rather than
   "broken" because it is a fact rather than a judgement: a suspended customer
   belongs in the same count as a provisioning run that died.

**Rejected: computing "stuck" from a threshold** — `provisioning` with
`updated_at` older than a day, drawn as a warning. It is the obvious reading and
it is not built, because the threshold would be fiction. A tenant provisioning for
twenty-three hours is exactly as broken as one provisioning for twenty-five, and a
line drawn between them teaches the reader that everything under it is fine. What
the page says instead is weaker and true: this customer is not being served, the
row was created *then*, and it was provisioned — for a stuck tenant — never. That
is a date beside an em dash, and it reads as what it is. The reader supplies the
judgement, which is the half of "has it moved in a day" that no constant in this
repository could supply honestly.

**Rejected: a separate page, or a filter, for the unhealthy rows.** Both put the
thing worth seeing behind a click on a page whose entire job is that nobody has to
go looking. The cost of the ordering chosen instead is real and small: looking one
customer up by name now means finding them in the second group rather than in
strict alphabetical position. The registry is one row per customer, so this is a
list of tens; grouping a list of tens by state is a reading order, not an
obstacle. When it stops being tens the answer is a search box and paging, not a
different sort — and paging is the moment the ranking has to reach SQL, which is
when duplicating it as a `CASE` becomes a cost worth paying for a reason rather
than by default.

#### Two smaller decisions the ticket left open

**A tenant with no hostname is shown, not skipped.** `findAllWithDomains()` uses a
`leftJoin` where `findOneByHostname()` uses an inner one, because provisioning
writes the registry row before it routes a domain to it — so a run that died in
between leaves exactly a tenant with no domains, and an inner join would silently
omit the row this page is most needed for. It draws an em dash, which is the
honest rendering.

**The modules column is what the control plane believes, not what the customer
has.** §6.1 makes those two able to differ, and reconciling them means reading
each tenant's own metadata, which is a tenant connection this page does not open.
The column is `tenant.enabled_modules` and nothing else.

**[XIV-95] answered that without weakening it**, and the shape of the answer is
the point: the reconciliation happens where a tenant connection is already open —
in [XIV-59]'s collector, which was reading the customer's installed modules
already to know which shapes to count — and the page reads the result out of the
control plane like every other value here. The column now shows what the customer
*has*, names where that disagrees with what the registry says in both directions,
and carries the age of the collection it came from. See §8.11.

**No lifecycle actions.** Provision, suspend, migrate, rotate and deprovision all
have working commands, several of them with refusals and confirmations that a
button would have to reproduce (§4.1 is an essay about one of them). A page that
lists customers and a button that destroys one are different kinds of thing, and
the second gets its own ticket when somebody wants it.

### 8.11 What a tenant actually uses (XIV-59)

Three figures per customer: how many users they have, when anybody last signed
in, how many records are in there. Enough to tell somebody who is using this from
somebody who provisioned in March and never came back — which the registry alone
cannot say, because every column of it describes the *arrangement* with a
customer rather than what they do with it.

The data all exists. `User::$lastLoginAt` is written on every sign-in (§8.1), so
"last login" is `MAX(last_login_at)`; records are one table per module shape with
a soft delete, so a count is `COUNT(*) WHERE deleted_at IS NULL` per installed
shape. **None of it is in the control plane, and that is the whole ticket.**

#### The fan-out is the problem, and it is not mainly about speed

§4 is a database per customer, so there is no query that answers this for all of
them: fifty tenants with six modules each is fifty connections and three hundred
counts. On a page whose entire purpose is to be opened when somebody is already
worried, that is bad enough on its own.

The larger objection is what it would make true. **It would be the first thing in
the system that deliberately touches many tenants in one request.** §7.4's
guarantee — one request resolves one tenant, and the runtime keeps no state
between requests — is not a rule somebody follows; it is a *consequence* of how a
request works here, which is why the tenant connection on a control-plane host is
not merely unused but unusable (§8.9). A page that opened fifty tenant
connections would turn that consequence into a case-by-case argument, and the
second such page would not have to make the argument at all.

#### Decided: collect periodically, and let the page read the control plane

`tenant:usage:collect` walks the registry **one tenant at a time** through
`TenantSwitcher::runFor()`, writes what it finds into the control plane, and the
tenant list reads that table exactly as XIV-58 reads the registry. One request,
one database, still literally true — and XIV-58's proof that the page opens no
tenant connection passes unchanged, which is the test that would have gone red if
this had been built the obvious way.

**Periodically means a console command and the deployment's cron, not a queue.**
There is no worker process here and no consumer to supervise — the same
constraint that settled synchronous sending in [XIV-37]. A queue would add a
runtime component to a system that has none, for a job that takes seconds and
that nobody is waiting on.

**A run that fails for one tenant records that it failed for that tenant and
carries on.** One unreachable database must not cost the other forty-nine their
figures — but the run still exits non-zero, because under cron the exit status is
how anybody finds out at all.

**Each tenant's connection is closed before the next is opened.** `runFor` does
it unconditionally, including when the callback throws, and there is one tenant
connection object in the process. This is not tidiness: a collection run that sat
attached to every customer's database at once would be the reason an operator's
`tenant:deprovision` fails, because Postgres will not drop a database somebody is
connected to ([XIV-94]). The collector would have become the thing that blocks the
operator, which is the opposite of a tool for operators.

**The counting is shared with `tenant:deprovision`, not copied.** That command has
asked the same question since XIV-72 — it prints how much is in there before it
destroys it — so "switch to the tenant, read its own metadata, count each shape"
is now `Xivi\ControlPlane\Usage\RecordCounter` and both callers use it. Two copies
would have drifted at the first change to any of the three steps.

#### Where the figures live: their own table, not columns on `tenant`

`tenant_usage`, one row per customer, and the argument is that **a row there is a
collection rather than a customer**:

- Every figure means nothing without the moment it was taken beside it, so
  `collected_at` is not an extra column — it is the column the others are
  relative to. It is a fact about the run.
- So is the failure. A customer whose database did not answer has not changed;
  the collection failed. `tenant.failure` would read as a broken customer.
- **A customer nobody has collected yet has no row at all.** Five nullable
  columns on `tenant` could not have said that without a sixth meaning "the nulls
  above are real nulls". Absence says it exactly, and it is the state a customer
  provisioned ten minutes ago is genuinely in.

The association points one way only — `TenantUsage` knows its tenant and `Tenant`
knows nothing — because Doctrine cannot lazily load the inverse side of a nullable
one-to-one, so every `Tenant` hydrated anywhere would fetch a usage row nobody
asked for. The page fetches all the collections in one query and matches them by
slug: two queries against one database.

*This table has a sibling since §8.15 ([XIV-102]).* `purchase_intent` is filled by
`tenant:purchase:collect` on exactly this pattern, for exactly this reason — a
customer's request to buy a module is written into their own database because
§4.4's grant leaves nowhere else for a customer's write to go, so an operator sees
it the same way they see these figures. Every argument in this section transfers
and none of it is restated there; what §8.15 adds is why the alternative shapes,
which look cheaper, are not.

#### A stale figure presented as current is worse than no figure

The page shows the collection time beside the numbers, and it distinguishes three
states rather than two: *not collected yet*, *could not be read, tried at …*, and
the figures with their timestamp. **Zero and "we could not count" must not look
alike** — the same rule [XIV-39] drew for a mail that was not sent, one screen
along. A tenant whose collection failed shows as failed, with when it was tried,
and shows no numbers at all.

**A failed collection drops the previous figures rather than keeping them beside
the failure.** Keeping them would be more information and the wrong kind: the
numbers would then be as old as the last *success* while the timestamp beside them
says the last *attempt*, and a reader who takes in one and not the other has been
misled by the screen rather than by their own carelessness.

**The stored failure is the exception's class, never the driver's message.** A
connection error names the host, the port and the role — and §8.10's whole defence
is that a `Tenant`, and therefore a DSN, cannot reach an HTML page. Storing the
driver's words would smuggle those parts back in through a table whose rows are
rendered, waiting for somebody to print them "just for debugging". The class name
separates an unreachable database from a missing schema and names nothing; the
full message goes to the terminal of whoever ran the collection, who already has
the DSN.

#### Counts, not contents — and why the line is exactly there

An operator page exists to say **how much**, and the moment it says **what**, the
control plane has become a way to read a customer's data without their knowledge.
That is not a slippery-slope argument, it is a one-line argument: the code that
opens a tenant connection to count rows is a `SELECT *` away from selecting them,
and every seam here is shaped so that the tempting change is also an obvious one.
`RecordCounter` can only return integers. `UserRepository::countAndLastSignIn()`
is one aggregate row and loads no user — a `findAll()` and a `usort` would have
produced the same two numbers while pulling every customer's names, emails and
password hashes through the control plane's process to get them.

The one value here that is not a count is `MAX(last_login_at)`, which the ticket
asked for by name and which identifies nobody: it says somebody was here on
Tuesday, not who. That is the boundary, and anything past it — a name, a record
title, a "show me what they have" link — needs a different justification from
this one and does not have it. A customer's data belongs to the customer; a
platform that can read it whenever it likes has made *isolation* a claim about
intent rather than about architecture, which is the thing §4 exists to avoid.

#### What a tenant actually has installed, and where that disagrees (XIV-95)

§8.10 drew the modules column from `tenant.enabled_modules` and said out loud
that this is *what the control plane believes* rather than what the customer has.
§6.1 is why those are different sentences: once a module is installed the
customer's own definitions are the truth, installing does not retro-fit, and
`tenant:module:install` writes a tenant's metadata without touching the registry
row. So the column was honest and incomplete, and completing it meant reading
each customer's own metadata — a tenant connection that page does not open.

**The collector was already reading it.** `RecordCounter` walks
`MetadataRepository::all()` inside `TenantSwitcher::runFor()` to know which
shapes to count, so the real installed list was being read once per collection
and thrown away. It is now written to `tenant_usage.installed_modules` beside the
figures, under the same `collected_at`, and the page reads the control plane as
it always did. XIV-58's proof that the list opens no tenant connection passes
unchanged, which is the same test that would have gone red had this been built
the obvious way.

#### The disagreement is the useful part, and it is not stored

Three ways the two lists drift, all real: a module installed from a console that
provisioning never recorded; a module in `enabled_modules` whose tables a run
that died part-way never created (§4.1); and a module whose definitions the
customer has since diverged, which §6.1 makes their prerogative. An operator
looking at a customer that behaves oddly wants that answer without opening
`psql`.

**The comparison is made when the page is drawn, not when the collection runs.**
Storing the difference would have been one array instead of two and no work at
render time, and it would be a comparison between a database read last night and
a registry column anybody can change this morning: an operator who enables a
module at ten would go on being told at eleven that the registry does not know
about it, and one who disables a module would be told everything agrees. Half of
this comparison is genuinely current and half genuinely is not. So the row stores
only the half that was *observed*, and the page says how old it is.

The corollary is that a failed collection drops the installed list exactly as it
drops the figures. Keeping the last known list beside a failure would put an
observation from the previous run under a timestamp describing this one — and
this cell would then draw a module the tenant may no longer have as a
disagreement with the registry. **Drift invented by a stale row is the one thing
this cell must never report**, because a real one is meant to send somebody
looking.

For the same reason the installed list is read from the metadata rather than
taken from the keys of `records_by_module`, which happen to be the same strings
today. `array_keys()` would make *what a customer has installed* a by-product of
how counting is implemented: the first time the counter learns to skip a shape,
that module vanishes from the installed list and the page reports a difference
that does not exist. It costs nothing to ask separately — `MetadataCache` (XIV-53)
answers the second call from memory inside the same switch.

#### A difference is information, not a fault

**Nothing about drift is drawn as an error**, and that is a decision rather than a
styling choice. A module installed by hand is a legitimate state that somebody
chose; §6.1 says a customer's definitions win once installed. A page that told an
operator off for it would be a page they learn to stop reading — the same failure
§8.10 describes for a banner permanently saying "0 customers need attention".

So the cell names the two directions and stops. *not recorded* for a module the
tenant has that the registry does not list — the control plane is the thing that
failed to write it down, and the customer is fine. *not installed* for the other
way, named from the customer's side because that is whose experience it is: their
users see a module that is not there. There is no severity, no alarm colour and
nothing offering to fix it. **Reconciling the two lists is a different feature
with a much higher bar**: writing the registry from a tenant's metadata means
deciding which side is authoritative, and §6.1's answer to that question is "the
tenant, and the registry is an arrangement" — which is not obviously what an
operator pressing a button would expect.

#### Where the per-module counts went, and why the row still fits

XIV-59 stored `records_by_module` and showed it in a `title` tooltip. A tooltip is
invisible on a touch screen and to a screen reader, so the answer to *of what* was
reaching a mouse and nobody else — and this ticket was drawing per-module
information into the same table anyway. Deciding it twice would have produced two
answers. So the counts moved out of the tooltip and onto the module names they
belong to: one module per line, its count spelled out in words beside it, and the
disagreement in the same line when there is one.

That is much more text than a row of badges, which raises the question the ticket
asked. **Six modules is the most any customer in this repository can have today
and nothing stops there being more**, so the cell shows the first five and folds
the rest into a `<details>` — a control that a keyboard reaches and a screen
reader announces, which is exactly what the tooltip it replaces was not. The
ordering is what makes the folding safe: modules the two sources disagree about
sort first, alphabetically within that, so what folds away is only ever something
both sides already agree on. Same argument as §8.10's row ordering, one cell down.

**Rejected: truncating with an ellipsis.** It hides the end of whichever line is
longest, and the end of the line is where the disagreement is named — replacing a
hover with a different thing you cannot read.

#### The line is unchanged

Names and counts, never contents. Which modules a customer has and how many
records are in each is *how much*; what is in them is *what*. Reading a module's
definitions to learn its key is on the permitted side of that line — a
`ModuleDefinition` in hand is the whole shape, fields and collections and their
fields, and exactly one string of it leaves the collector. A field label would not
be, and a record certainly is not.

### 8.12 A public surface that provisions nothing (XIV-64)

Self-service signup is the first thing in this system reachable by somebody who is
nobody: no tenant, no account, no session, no invitation. Everything above this
section is about people who already belong somewhere — a customer's user (§8.1), an
operator (§8.9) — and the machinery that identifies them assumes it. None of it
transfers, and pretending it does is how this feature gets built wrong.

#### The naive shape, and why it is not a matter of being careful

A signup form calls something that creates a customer. The thing that creates a
customer here is `TenantProvisioner::provision()`, and it connects with
`TENANT_ADMIN_DSN` — the credential its own docblock describes as *"allowed to
CREATE DATABASE and CREATE ROLE; provisioning only"*. Wiring a public form to it
puts the most privileged operation in the system **one anonymous HTTP request away
from the open internet**, where the only things between the two are the parsing,
the authentication and the slug rules in front of it. Every one of those is code
somebody will change.

**So the endpoint records a signup and does nothing else.** One `INSERT` into one
table, one email, and no elevated credential anywhere in its reach. Turning a
confirmed row into a customer is [XIV-98], and it runs where an operator can see
it. That separation is not sequencing — it is what the ticket is for.

**And the claim is deliberately narrower than it sounds.** What is delivered here
is a **code** boundary: a separate service, its own table, its own controllers,
and no provisioner reachable from any of them. `SignupEndpointTest` walks the
constructor graph behind both controllers and asserts that neither
`TenantProvisioner` nor `TENANT_ADMIN_DSN` appears. It is **not** yet a privilege
boundary. There is one instance and one set of environment variables, so the
process that answers this request holds `TENANT_ADMIN_DSN` whether or not anything
in it reads the variable. Making the public surface a process that does not have
the credential at all is [XIV-96]. Saying "the endpoint cannot create a database"
without that sentence attached would be claiming a guarantee that does not hold
yet.

#### Confirmation is a pre-tenant identity, and none of §8.8 transfers

An address typed into a form proves nothing: anybody can type anybody's. So the
signup is confirmed by email before it holds anything, and this is the gate rather
than a nicety — without it the endpoint records names on behalf of people who never
asked.

[XIV-1]'s invitation is the nearest thing already built and it is unusable here,
for a reason that is structural rather than inconvenient: a login link is an HMAC
over a `UserInterface` **loaded from a provider**, and the provider for tenant
users is bound to a *tenant's* database (§8.1). There is no tenant. There is no
`app_user` row. Inventing one so the framework's helper could be used would mean
creating an account for somebody who may never confirm — which is precisely the
thing confirmation exists to avoid.

So the token is the control plane's own, and it is a **stored digest**:

- **32 bytes from `random_bytes`**, base64url in the URL. 256 bits of entropy, so
  brute force is out of reach without help from the rate limiter — which matters,
  because the rate limiter is about volume and a token that depended on it would
  stop being safe the day somebody widened a limit.
- **SHA-256 of it in the row, never the token.** §8.8's objection to a token table
  was that *"a token table stores something replayable and a signature stores
  nothing at all"*. That objection is answered by hashing rather than by not
  storing: a dump of the control-plane database carries nothing anybody can
  present. A plain digest rather than a password hasher, deliberately — the input
  is full-entropy random, so there is no dictionary for a slow KDF to defend
  against, and a slow hash would be paid on every click of every link.
- **Twenty-four hours**, the same window an invitation gets, and for the mirror
  image of §8.8's argument. There the window was short and the mitigation was that
  an administrator could send another; here the person can reissue it themselves by
  submitting the form again, so the same window costs less. What it buys is that an
  unanswered signup stops occupying its address within a day.

`UriSigner` was the third candidate and loses to the requirement below: a signature
over an id and an expiry cannot be invalidated when a second submission supersedes
the first.

**A second submission from an unconfirmed address is a resend, not a conflict.**
The row is rewritten in place — new company name, new slug, new plan, new token,
new twenty-four hours — and the previous link stops working with the same write,
because the digest it is checked against has been overwritten. This is §8.8's
invitation rule reached from the same argument: *"I asked for another one"* has to
be the way to fix a mail that went to spam, and it is not if the first link is
still live in whatever mailbox it reached. Treating it as a conflict instead would
mean the only way out of a confirmation that never arrived is to own a second email
address.

**A second submission from a *confirmed* address is refused.** At that point the
address is holding a name and the second request is asking for a second
installation, which is a real request and not this endpoint's to grant quietly. One
confirmed address, one unprovisioned signup — see the abuse argument below for what
that buys.

**Following the link twice changes nothing, and that is the design rather than a
tolerance.** Confirmation is idempotent: the second call finds the row already
confirmed, keeps the moment of the *first* click, re-reserves nothing and sends
nothing. A single-use token would have been the reflex, and it is wrong here for a
reason that has nothing to do with attackers — people click twice, mail gets
forwarded, and any company with a mail gateway has a link scanner that fetches
every URL in a message **before its recipient sees it**. A single-use link is burnt
by the scanner and the human is told it is invalid. What actually makes a replay
worthless is that there is nothing to replay, and the token still expires and is
still superseded.

**The confirmation mail comes from the instance identity, not from a tenant's
SMTP.** §8.8 refused to carve an exception to §8.7 for the invitation, with a good
argument: one place decides who a message is from. This is not an exception to that
rule, it is a message the rule cannot be applied to — `TenantMailer` asks the
current tenant's profile whether they have their own server, and there is no tenant
to ask.

§8.7's fallback transplants exactly, though, and it is worth following because the
first version of this feature got it wrong. There, an empty `MAILER_SENDER` sends
from `no-reply@` at the *tenant's own primary domain*, and the argument for why
that is honest rather than a guess is that the hostname **is** this installation as
far as that customer is concerned. Replace the tenant with the signup host and the
sentence still holds: `SIGNUP_HOST` is the name the prospective customer's site
posted to and the name their confirmation link points at. So an empty
`MAILER_SENDER` means `no-reply@` there, and signup adds no deployment step at
all. The rejected alternative — requiring `MAILER_SENDER` whenever signup is on —
would have made switching signup on quietly rewrite the `From` of every *tenant's*
mail as well, since the two are one variable.

**It is written in the visitor's language**, which the calling site forwards with
the submission because there is nowhere else to get it: this person has no account
on this installation, so no stored preference, and the `Accept-Language` of a
server-to-server POST belongs to the calling server. A language this build does not
have falls back to the installation's default rather than being refused — the same
choice the translation catalogue makes one level down (§8.4.2), and the same check
that keeps a caller from handing an arbitrary string to the translator and to a
sixteen-character column.

#### Two slug rules, on purpose

`TenantProvisioner::SLUG_PATTERN` is `/^[a-z][a-z0-9_]{1,55}$/`. It permits
**underscores** and forbids **hyphens**, which is exactly backwards for a string
that becomes a DNS label: `my_company.xivi.app` is not a valid hostname.

**It is not changed, and this paragraph exists so that nobody unifies the two.** It
is right for what it guards — a provisioning slug is also a PostgreSQL database and
role name, where an underscore is the ordinary separator and a hyphen would force
every identifier to be quoted. Every tenant that exists is named that way and so is
the whole test suite (`test_picker_candidates` and two dozen like it). And
`provision()` never derives a hostname from a slug at all: hostnames are an explicit
parameter, so an operator is free to route `acme.example.com` at a tenant called
`acme_ag` and nothing is inconsistent.

Self-service is the case where **nobody types the hostname**. The slug *is* the
subdomain, so it gets a second, stricter rule:

    ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$

One DNS label as RFC 1123 allows it: lowercase alphanumerics and hyphens, no
leading or trailing hyphen, at most 63 characters. The two rules overlap on names
made only of lowercase letters and digits and disagree everywhere else, in both
directions. `SelfServiceSlugTest` asserts that disagreement from both sides, so
replacing either pattern with the other fails the build.

**A consequence to hand to [XIV-98]**: because the two rules disagree, a
hyphenated self-service slug can never equal an existing tenant's slug today, and
the intake's check against the registry only bites for names both rules accept.
Whatever mapping [XIV-98] chooses from a signup slug to a provisioning slug has to
be checked here as well, or two customers can be promised names that collide once
translated.

**That has landed and §8.14 records it.** The mapping is hyphen to underscore,
the intake now asks the registry about the translated name *and* about the
hostname it would take, and the names that have no translation at all — one
character, a leading digit, anything past fifty-six — are refused here with
`invalid_slug` rather than accepted and failed in a cron run.

**The name is derived from the company name, shown before submission, and
editable** — and the derivation is part of the contract rather than the form's
business. Two implementations of a transliteration rule disagree on the first
umlaut somebody types, so the endpoint derives it, hands back what it derived, and
§8.13's form shows that.

**The rule takes nothing from the request, which is [XIV-100]'s fix.** It was
locale-aware — `Bäckerei` is `baeckerei` to a German reader and `backerei` to the
default rules — and that was wrong for a reason that is easy to miss, because
there was only ever *one* derivation and both endpoints already called it. What
differed was the argument. `locale` is an **optional** field on both requests, its
documented job is to choose the language of the confirmation mail, and nothing
obliged a caller to send the same value to the availability check and to the
submission. So the preview said `muller-bau-ag`, the submission created
`mueller-bau-ag`, and the `available: true` had been computed about a name nobody
would ever be given.

Passing the locale more carefully does not fix that: two requests are two
requests, and any rule that reads an optional field can be made to disagree with
itself by a caller that forgets it once. So the request stops deciding. The mail's
language is a property of the *reader* and rightly varies; the slug is a hostname,
it is permanent, and it belongs to the *company* — which writes itself `Mueller`
whenever ASCII is required, on Monday in German and on Tuesday in English.
`SelfServiceSlug::TRANSLITERATION_LOCALE` is `de`, chosen deliberately for the
market this is sold into, and every other language keeps what it had: the locale
maps only add expansions on top of the generic ASCII rules, so `é` is still `e`
under `de` exactly as under `en`. What it costs is the handful of languages with
an expansion of their own — a Swedish `Å` is `a` here rather than `aa` — and a
deployment selling somewhere that trade is wrong changes one constant, which is a
decision about the installation rather than about a request.

**Reserved names are two lists.** The conventional one — `www`, `admin`, `api`,
`mail`, `app`, `control`, `status`, `support` — exists because those are names a
platform will want later and cannot take back. The second is computed from
`app.system_hosts` and the control-plane host, and it is a boundary rather than a
convention: [XIV-57] made `tenant:provision` refuse to route a tenant onto a system
host, and that refusal fires when [XIV-98] runs — long after somebody has confirmed
an address and been told the name is theirs. What is reserved is the **first label**
of each such host, because that is what collides: a control plane at
`control.xivi.app` is collided with by a signup for `control` under the same domain,
not by one for the whole string.

#### Abuse: confirmation and volume are different problems

**Squatting** is answered by the two rules above, and the mechanism is worth stating
plainly: **a name is held only by a confirmed address**. An unconfirmed signup
reserves nothing, so a script that posts ten thousand company names has produced ten
thousand rows and blocked nobody. Holding a name costs a working mailbox and a
clicked link — *per name*, because a confirmed address may hold only one
unprovisioned signup at a time. Without that second half the cost is paid once and
reused for as many names as you like.

The price of that is a race the design cannot remove and does not try to: two people
ask for `acme`, both are told it is free because nothing is held, and the second to
click their link is told it has gone. That is the anti-squatting rule costing
somebody something, and it is the right side to take — the alternative is holding
names for addresses that have proven nothing.

**Volume** is a separate harm and needs a separate answer: a script posting a
thousand addresses a minute has used this installation to send a thousand people
mail they did not ask for. `symfony/rate-limiter` (MIT, first-party, checked and
recorded in `THIRD-PARTY-NOTICES.md`) with three sliding windows: a small one per
email address, which bounds how much mail a stranger can aim at one *person*; a
loose one per client address, loose because [XIV-65]'s recommended integration is a
server-side post and an office behind one NAT is one address; and a much larger one
for availability checks, which write nothing and are made as somebody types.

Two things about it are worth knowing rather than discovering. **The secret is
checked before the limiter is touched** — otherwise anybody at all could exhaust a
chosen victim's bucket without holding the credential, turning a defence against
abuse into an instrument of it. And **there is no global cap**: with a server-side
integration every request arrives from one transport address and the client address
is supplied by the caller, so a compromised caller can spread itself across as many
buckets as it likes. The thing that answers a compromised caller is rotating the
secret, and a single ceiling on the endpoint would also be a single number that one
busy afternoon turns into an outage for everybody.

#### The contract is a public API, and its host is its own

The intake is an interface somebody else compiles against rather than a form's
private detail — that was the assumption when it was written, and §8.13 kept it
even though the landing page ended up in this repository: the page holds the
secret and posts to this contract like any other caller, so a deployment that
builds its own front end is on the same footing as the one shipped here. That
fixes four things:

- **A documented request and response shape**, on
  `Xivi\ControlPlane\Controller\SignupApiController`, next to the code rather than
  only here.
- **A version in the path** (`/api/signup/v1/`). Within v1: fields and error codes
  may be *added*, and added fields must be optional; nothing may be removed,
  renamed or made required. Anything else is v2, served beside v1.
- **A stable error vocabulary** — `invalid_request`, `unauthorized`,
  `invalid_email`, `invalid_slug`, `slug_taken`, `address_already_registered`,
  `unknown_plan`, `rate_limited`, `mail_failed` — with its HTTP statuses decided in
  one table rather than at each `return`. The message beside the code is one
  **fixed** English sentence per code, for a developer's log; [XIV-65] owns the
  words a visitor reads, in their language. Fixed rather than descriptive is a
  security property rather than laziness — the *internal* refusal message names
  which of three reasons made a slug unavailable, and the first version of this
  endpoint returned it, undoing the paragraph below from inside the response that
  paragraph was written for.
- **A shared secret**, in `X-Xivi-Signup-Key`, compared in constant time and
  **refusing everybody when unset**. A deployment that set a host and forgot the
  secret has published an anonymous endpoint; failing closed makes that a feature
  that does not work, which is noticed in minutes, rather than one that works for
  everybody, which is not noticed at all.

**A server-side post is the recommended integration**, and the difference is where
the credential lives: in a browser-side design it is in the page's source, which is
to say in everybody's hands, and the endpoint additionally has to appear on a public
CORS origin list. There is deliberately **no CORS configuration anywhere in this
feature**, and that is not a gap to be filled in later — adding it is the change
that makes the browser-side design possible.

**`slug_taken` is one word for three situations**: a customer has the name, a
confirmed signup is holding it, and the platform keeps it. Distinguishing them would
be more informative and is deliberately not done, because whatever the endpoint
distinguishes, a caller can enumerate — and the useful action is the same in all
three cases. **The honest limit**, because it is a limit rather than a fix: "not
available" is still one bit, so the set of unavailable names is discoverable by
anybody entitled to call this. What keeps that from being an enumeration of the
customer list is the shared secret and the rate limiter, not the vocabulary. A
deployment that proxies the availability check straight through to anonymous
visitors has made that bit anonymous too, and should say so to itself.

**It is served on a hostname of its own**, not under `/control/`. §8.9 asks for the
control-plane host to be hard to guess, and a hostname configured into a third
party's marketing site is the opposite kind of secret — it ends up in somebody
else's deployment, somebody else's chat and eventually somebody else's repository.
Serving an anonymous endpoint there would also aim the internet's traffic at the
host that answers to the people who can see every customer. `SIGNUP_HOST` goes into
`app.system_hosts` exactly as `CONTROL_PLANE_HOST` does, so a signup request
resolves no tenant by the same mechanism rather than a second one, and the
application refuses to build a routing table when the two hosts are equal.

The firewall there is `security: false`, which is a decision rather than an
omission: `main` matches every host, so without a block of its own a request here
would sit inside the firewall whose provider looks people up in a customer's
database. Nothing would come of it in practice — no session, no credential the
provider is asked about — and "nothing would come of it in practice" is the wrong
standard for a boundary. It is declared *below* the control-plane firewall so that
a deployment which somehow got both hostnames equal ends up with an operator console
that still demands a password rather than one with `security: false` in front of it.

#### Off means the route does not exist

The three states are page-and-endpoint, endpoint-only, and neither; the endpoint
switch is this section's and the page's is §8.13's. Here, **off means
no route is registered** — not a route that answers 404. A registered route is a
controller the router can reach: it is in the compiled matcher, it is in
`debug:router`, and it is one misplaced access rule away from running. A route that
was never loaded is absent as a property of the routing table rather than of a check
somebody has to keep correct.

Symfony cannot say that in routing configuration, because **environment placeholders
are forbidden there** — the same constraint that made `ControlPlaneRequestListener` a
listener rather than a `host:` on the operator routes (§8.9). A route loader is the
framework's own answer: it runs at route-load time, it can read what a service can
read, and what it returns *is* the routing table. It also stamps the configured
hostname onto every route it returns, which is why no signup controller carries a
`host:` of its own, and it forces `https`, because the request carries a shared
secret and the confirmation link is how somebody proves control of a mailbox.

One variable does both jobs — empty `SIGNUP_HOST` is off, a hostname is on and says
where — rather than a flag beside a hostname, which is two facts that can disagree.
`SignupRouteLoaderTest` asserts the empty collection directly rather than by booting
a second kernel: the claim is about the route collection, the loader is what
produces it, and two kernels in one environment share a compiled matcher, so the
kernel version of that test would pass or fail on test order.

**That was not enough, and §8.13 found out why.** The loader was right about its
own collection and the routing table held a second, host-less copy of every signup
route registered by the framework's own `routing.controllers` — present even with
`SIGNUP_HOST` empty. The fix and the assertion that catches it are in §8.13; the
lesson to carry back here is that a claim about "the routing table" has to be
asserted against the router, because a loader can only ever be asked about what it
returns.

#### What is deliberately not built

**The landing page and the form.** §8.13's — which, in the event, is served from
this repository and on this hostname rather than from a site of its own; the
argument for that is there. What is provided from *here* is the derivation rule
and the availability check as part of the contract. The one page that was here
first — where a confirmation link lands — is the plainest in the repository on
purpose: it can only live on this side, because the token is a row in this
database, and it remains deliberately unlike the landing page rather than an
early draft of it.

**Provisioning.** [XIV-98]'s, and with it the removal of the row: this table holds
*live* signups only. That is why `SignupStatus` has two cases and not three — a
`provisioned` state here would be a second copy of a fact the registry already holds
in `tenant.slug`, free to disagree with it, and the disagreement would be silent.

**Any notion of which caller presented the secret.** There is one secret because
there is one caller. When there are two — a partner, a reseller — that is the moment
for a table of keys with a name against each.

### 8.13 A landing page, and the scope is the decision (XIV-65)

§8.12 built an intake and deliberately built no way in to it. This is the way in:
one page, one form, on the signup host. A visitor types their company name,
watches the address they will be given appear, edits it if they want it
different, and submits.

**It is a landing page and not a marketing site**, and that was weighed before it
was built rather than discovered while building it. The two have nothing in common
except this form: different authors, different release cadence, different risk
appetite, different reviewers. A marketing site in this repository would put a
copy edit through a suite that provisions PostgreSQL databases, and would put an
ERP release behind somebody's rewrite of a features page. So the scope is a
landing page, no pricing, no feature grid and no content model — and **the day a
real marketing site is wanted, this section reopens**. The answer then is not to
grow this page: it is a site of its own posting to the published contract, which
is exactly what §8.12 made the contract public *for* and what the "endpoint only"
state below exists to serve.

#### Three states, two switches, and one `and`

The page and the endpoint are wanted independently:

- **page and endpoint** — the default when signup is on, and what the company
  selling this runs.
- **endpoint only** — somebody has built their own front end. The built-in page
  would be a second front door onto the same intake, worse than theirs and
  confusing to find.
- **neither** — a single company self-hosting, for whom an open endpoint that
  records signups is a liability rather than a feature. **This is the shipped
  default**: `.env` leaves `SIGNUP_HOST` empty.

`SIGNUP_HOST` is §8.12's and says whether there is an intake and where.
`SIGNUP_PAGE` is this one and says whether we also draw the form. They are
combined in one place, `SignupPage::isEnabled()`, and the combination is an `and`
— so the fourth state, a page with no intake behind it, is **not expressible**
rather than refused by a check. That is worth being deliberate about because it is
the combination that would fail worst: a form that renders, accepts a company
name and then cannot post anywhere looks like it works to everybody except the
person filling it in.

A boolean here where §8.12 refused one for the endpoint, and the asymmetry is not
an inconsistency. That variable had a second job — it has to say *where* — so a
flag beside it would have been two facts that can disagree, and the disagreement
everybody eventually has is "enabled, but nobody said where". This one has no
second job, because where the page is served is already decided.

#### The page shares the endpoint's hostname

§8.12 argues at length that the *endpoint* must not be served on the control-plane
host, because a hostname configured into a third party's site ends up in somebody
else's repository and the operator console's should not. That argument is about
secrecy and the page has none to lose: it is anonymous, public and meant to be
linked to.

What decides it is the confirmation link. It lands on `SIGNUP_HOST/signup/confirm/…`
because only this side can answer it — the token is a row in the control-plane
database — and a visitor who filled in a form at one name and is asked to confirm
at another has been handed the exact shape of a phishing mail. One name, from the
form to the mailbox and back. A second hostname would also be a second variable, a
second DNS record and a second certificate for a page whose entire job is to post
to the first one.

#### It goes through the front door, and the front door is what the test proves

The page could call `SignupIntake` directly; it is in the same process. It does
not, and there are two reasons that outlive the convenience.

**The secret is the design.** §8.12 recommends a server-side post carrying
`X-Xivi-Signup-Key` because the alternative puts the credential in the page's
source and forces a CORS origin list onto an anonymous endpoint. This page is the
*reference* implementation of that integration; one that reached past the contract
would be recommending one thing and doing another, and the first person to copy it
would copy the wrong half.

**The contract has to be exercised by something the company itself runs**, or its
shape, its header name, its status codes and its error vocabulary are proven only
by a test. Going through the front door means we are broken by the same change
that breaks a customer's integration, in our own staging, first.

**The request is real; the socket is not.** `SignupClient` builds a genuine
`Request` — POST, `application/json`, the documented body, the secret in the
documented header — and hands it to the kernel as a sub-request. It is routed by
the router to the real controller, parsed by the real `SignupSubmission`, checked
against the real secret, charged to the real rate limiter and written to the real
database, and the response is parsed back out of JSON exactly as a third party
would parse it. What that proves is the whole published contract. What it does not
prove is DNS, TLS and whatever proxy sits in front, and saying so is part of the
claim rather than a caveat on it.

A real socket was the alternative and lost on two grounds. FrankenPHP runs in
classic mode (§9.2), so a request occupies a worker: a page that opens a
connection back to its own server holds one worker while waiting for a second, and
with *n* workers, *n* simultaneous submissions deadlock the instance on precisely
the busiest day. And the container would have to resolve and trust its own public
name, which behind a terminating load balancer or split-horizon DNS it frequently
cannot — a landing page that works everywhere except production.

#### What the page gives away, said out loud

A live name check **is** an availability oracle offered to anonymous visitors.
§8.12 names this and asks a deployment that proxies the check to say so to itself;
this is that deployment saying so. `available: false` is one bit and a script can
walk it. What is left in front of that bit is the per-visitor `signup_slug_check`
bucket, which bounds a walker rather than preventing one, and the fact that
"unavailable" is one word for three situations — so a walker cannot tell a
customer from a reserved word. That is the price of showing somebody their address
before they commit to it, which is the whole point of the ticket. A deployment
unwilling to pay it switches the page off and keeps the endpoint.

The visitor's own address is forwarded to the intake so the limiter counts per
visitor rather than per installation; without it the client bucket would be a
single counter for the internet, either large enough to bound nothing or small
enough to be an outage.

**No CSRF token**, which is a decision. CSRF protection stops a third-party page
spending a credential the browser holds, and there is none here: the signup host
has `security: false`, nothing on the page is authenticated, and a forged
cross-site post achieves exactly what the forger could achieve by posting from
their own server. The one thing a forgery buys is that the victim's address lands
in the client bucket instead of the attacker's — a rate-limiting nuisance, not a
boundary — and paying for it would mean starting a session for every anonymous
visitor to the one host in this system that has none.

#### Not a Live Component, and the reason is structural

XIV-33 adopted Symfony UX Live Components and this page is exactly their shape, so
the departure needs an argument. A live component answers at
`/_components/{name}/{action}`, a route the bundle registers once for every host
this installation serves, and the component is resolved from that route's
parameter rather than from any route of its own. A `SignupForm` component would
therefore keep answering after `SIGNUP_PAGE` had switched the page off, and on
every tenant's hostname besides — a page that is "off" while its actions still
run, which is the hidden-page failure §8.12 wrote a route loader to avoid. Nothing
in the bundle's configuration can say otherwise, because the route that reaches it
is not this feature's to bind.

So the page is a plain controller whose routes the loader owns, and the live half
is sixty lines of Stimulus posting to one of them. Server-rendered stays true: the
script sets text into three elements and toggles two classes, and there is
deliberately no transliteration in it — a copy of the derivation rule in the
browser is XIV-100 again, one layer further out and worse, because the customer
would be reading our answer while the server recorded its own.

#### The defect this found in §8.12, which was live

`SignupRouteLoader` keeps its promise about the collection it returns and
`SignupRouteLoaderTest` proves it about that collection. It was not true of the
routing table.

Symfony autoconfigures **every class carrying a `#[Route]` attribute** with
`routing.controller`, and `config/routes.yaml`'s `resource: routing.controllers`
loads all of them. The signup controllers carry `#[Route]` attributes, so they
were loaded twice: once by this feature's loader with the host and `https` stamped
on, and once by the framework's with neither. Route names are unique in a
collection, so the survivor was whichever loaded last — which happened to be the
loader's, purely because `signup:` sat below `controllers:` in a YAML file.

With `SIGNUP_HOST` empty — the shipped default, the state a self-hosting company
relies on — `debug:router` still listed every signup route, on every hostname,
over plain HTTP. Only `SignupApiKey` failing closed on an unset secret kept that
from being an open intake, which is a defence in depth doing the entire job alone.
And moving two keys in `config/routes.yaml` silently unbound the host of the whole
feature; that is how it was found.

`SignupRoutesComeOnlyFromTheLoaderPass` takes those classes out of the framework's
loader, so the loader is the only thing in the process that can register a signup
route. The assertion that would have caught it is in `SignupPageTest` and is made
against the **router** rather than the loader: every route named `signup_*` in the
compiled table carries the configured host and `https`, and the set of them is
exactly what the loader returns.

#### How a content-only change gets through the changelog gate

`bin/ci` requires every branch to add a `CHANGELOG.md` entry, which is right for
anything a reader has to act on and absurd for a comma moved on a signup form. The
entry would say nothing, which is how a changelog becomes noise; and the
alternative is `--no-changelog`, typed routinely, until it is typed on the branch
that did need one. **A gate people skip out of habit stops being a gate, and it
stops being one quietly.**

So the rule is stated mechanically instead. The landing page's copy lives in a
catalogue of its own, `translations/landing.*.yaml`, rather than as keys in
`messages` — and `bin/ci` exempts a branch whose **entire** diff is that catalogue
and the page template. It is narrow in two deliberate ways: the exemption is per
branch rather than per file, so one line of PHP anywhere and the gate applies
exactly as before; and what counts as content is a short explicit list rather than
a rule like "anything under `translations/`", because the application's own
catalogue is product text — renaming *Invoice* is a change a customer sees.

Giving the page its own translation domain was worth doing for that reason alone.
It is also the right shape independently: the person who edits marketing copy
should not be editing the file that names the engine's fields.

#### The sixty lines of script, and how a browser was made to reach them (XIV-105)

The section above ends by saying the live half is sixty lines of Stimulus. `bin/ci`
never ran a single one of them. Every route the page owns is driven by
`SignupPageTest` with a real client, and every one of those assertions would go on
passing with the script deleted — which is the shape [XIV-84] already made
expensive once. There, a `data-action` typo made every lens button on the
dashboard inert and the suite stayed green, because the server-side tests called
the action directly and no button was involved. This page has three `target`
attributes, two action descriptors and one value name, and it is the one page in
this repository strangers reach.

**So it is tested in a browser, and the interesting part was that it could not be.**
Panther serves the application with `php -S` — plain HTTP, an arbitrary port — and
the signup routes carry the signup host and `https` because the surface behind them
mints mailbox-proving links and carries a shared secret in a header. That is not an
oversight in the test: it is a genuine incompatibility between how the page is
bound and how the browser suite reaches an application, and **the binding is the
half that is right**. Relaxing it under `when@test` was rejected outright — it is
the exact property [XIV-65] fixed a live defect to establish, and
`SignupPageTest::testEverySignupRouteInTheRouterCameFromTheLoader` exists to fail
when it stops holding.

What made a browser affordable is that **both obstacles turned out to be the test
harness's rather than the application's**, and both are answered outside it:

  * **The hostname.** The web server binds one address and the browser reaches it
    by whatever name resolves there, so a second compose network alias on the
    application container is enough — the `Host` header is then simply what the
    browser asked for. It could not be `signup.localhost`, which is what the suite
    used to configure: Chromium implements draft-west-let-localhost-be-localhost
    and answers every `*.localhost` name from its own loopback before DNS is
    consulted, so no amount of compose wiring reaches one. `.env.test` names
    `signup.e2e` instead. It keeps a dot because `SignupFirewallTest` proves the
    signup firewall is scoped by a matcher rather than by `security.yaml`'s `host:`
    — a regular expression in which a dot matches anything — and it proves it by
    asking for the hostname with its dots substituted. A single-label host would
    have left that test asserting nothing.
  * **The scheme.** `tests/panther-router.php` is handed to `php -S` and stands in
    for the TLS terminator production has, telling the front controller that a
    request to *that hostname and no other* arrived securely. The condition matters:
    the web server is started once for the whole browser suite, `cookie_secure` is
    `auto`, and a session cookie marked `Secure` is one a browser on `http://` will
    not store — so lying to the other six classes would have broken every test
    that signs somebody in.

**Nothing in `src/`, `packages/` or `config/` changed**, which is a stronger claim
than a `when@test` seam and is why it was worth the search. `SignupNameTest` states
it as an assertion rather than as a paragraph: the routes it has just reached over
plain HTTP are still `https`-only and host-bound in the compiled router of the
process making the claim.

**Two tests, and both are chosen so they cannot pass by accident.** A free name
asserts the box holds `mueller-soehne-ag` — computed by calling the server's own
deriver — because that expansion is what §5's argument about [XIV-100] is *for* and
what any browser-side slugifier would get wrong; it would catch a copy of the rule
growing in the script, which is the failure this page is most exposed to. A
reserved name asserts the other half of `report()`, the red class and the absence
of the green one, and needs no fixture at all: `admin` is reserved by the code
rather than by a row somebody committed, so the answer does not depend on which
browser class ran first.

The net was proved by breaking the page rather than by argument, the way [XIV-84]'s
own regression test was. Writing `data-action="input->signup-name#company|prevent"`
— [XIV-84]'s literal bug, one screen over — turns both tests red, and so does
renaming the controller's value from `url` to `endpoint`, which is the *silent*
version: nothing appears in the console, the page renders perfectly, and the box
simply never fills in.

**What it costs is two Selenium sessions and no tenant.** The landing page resolves
no customer, so this is the only browser class that provisions nothing, and it is
the cheapest one there is: about three to five seconds against a suite that varies
by more than that between runs. The router script is on the path of every browser
request now and measures inside the same noise.

**What is still not covered, said plainly.** The debounce and the
newest-answer-wins sequencing are real logic and no test here touches them; a
regression in either shows up as a form that feels wrong rather than one that is
broken, and it would ship. The same is true of the submit path, which is a plain
form post and needs no script. What is closed is the wiring — the attributes, the
route the script calls, and the two places the answer is written — which is the
part that has shipped broken before.

### 8.14 Turning a confirmed signup into a customer (XIV-98)

§8.12 built a public surface that provisions nothing and said, in as many words,
that acting on what it records is a separate ticket which *"runs where an
operator can see it"*. This is that ticket. It is the privileged half — the one
that legitimately holds `TENANT_ADMIN_DSN` — and everything below follows from
that being true of one console command rather than of an HTTP endpoint.

#### A command and cron, and the constraint is the runtime rather than the feature

The obvious shape is a message dispatched when somebody clicks their confirmation
link and consumed by a worker. **There is no worker.** This deployment is
FrankenPHP in classic mode with no worker block on purpose (§9.2), so nothing
runs between requests and a queue with nothing draining it is strictly worse than
no queue: the customer's installation is simply never made, and the failure is
silence.

That is the third feature to reach the same answer from the same place — [XIV-37]
made sending mail synchronous, [XIV-59] made usage collection a cron entry — and
the reason to write it down a third time is that it is **not three decisions**.
The constraint is a property of the runtime, so it produces this answer for
anything that would otherwise want a consumer, and it stops producing it the day
somebody introduces one for a reason of its own. On that day, moving this onto it
is a small change. Inventing one for a job that takes seconds is not.

The cost is latency, and here it is customer-facing rather than housekeeping:
somebody who confirms at ten past two waits for the next run. So the recommended
cadence differs from [XIV-59]'s — every few minutes rather than nightly — and
nothing in the command assumes either.

**One failure must not cost the others**, which is [XIV-59]'s rule and is
inherited rather than restated: the provisioner returns an outcome instead of
throwing, the failure is written onto that signup's row, the loop moves to the
next person, and the run exits non-zero so that cron mails somebody. Two things
about it are deliberately *un*like `tenant:usage:collect`. An empty queue is a
**success** here — no confirmed signups is the ordinary state of a healthy
installation on most nights, and a cron entry that mails somebody nightly for
being idle is one whose mail nobody reads within a fortnight. And nothing is ever
given up on: there is no attempt limit and no dead-letter state, because every
failure a retry could fix is one an operator fixes *elsewhere* — a full disk, a
mail server, a grant on the provisioning role — and a run that had disarmed
itself in the meantime would make the repair a two-step job whose second step
nobody remembers.

#### The hard part: which steps are idempotent, established rather than assumed

Provisioning is a registry row, a Postgres role, a database, a schema and then an
administrator, and it can stop at any of them. `TenantStatus::Provisioning`
exists for exactly that state. What a retry may do was read out of the code
rather than hoped for, and the answer is uncomfortable enough to be worth stating
plainly:

**`provision()` is not re-runnable, at either end.** Called again for a slug the
registry already holds it throws `slugTaken` before it does anything at all, and
with that row removed by hand it would throw `databaseExists`; PostgreSQL has no
`CREATE ROLE IF NOT EXISTS`, so the role would raise `42710` on its own. The
generated role password is fresh on every call and stored encrypted on the row,
so a hypothetical resume would also have to `ALTER ROLE … PASSWORD` to make the
stored DSN true again. Exactly **one** step inside it repeats safely: the
migration, because Doctrine records executed versions in the tenant's own
`doctrine_migration_versions` and steps over them.

**So a half-made tenant is cleaned up rather than finished**, and the cleanup is
`deprovision()` — which §4.1's [XIV-94] subsection made re-runnable in precisely
the way this needs. Both drops are `IF EXISTS`, sessions are terminated before
the drop, and the registry row is removed **last**, so a cleanup that itself
fails leaves a row pointing at whatever survived rather than an orphan nothing
knows about; the same run repeats over what has already gone and finishes it.

Destroying rather than resuming costs nothing, and that is an argument rather
than a shrug. A tenant still in `provisioning` has never served a request —
`TenantStatus::servesRequests()` says so and `TenantRequestListener` enforces it
— and its first user is created *after* `provision()` returns. There is no
session, no record and nobody holding a credential. It is an empty database with
a company's name on it.

**The three steps after it are idempotent as they stand**, which is why a tenant
that is already serving is finished rather than torn down. Creating the first
user is guarded by a lookup on the address and `UserManager::add()` refuses a
duplicate anyway. Sending an invitation twice is §8.8's own documented behaviour
rather than something tolerated here — the seed rotates, the previous link dies,
and there is never more than one live invitation per person. Removing the signup
row is a `DELETE` of a row that has already gone.

#### Telling our own wreckage from somebody else's customer

The resume path is the dangerous one, because *"a tenant with this slug exists"*
is not the sentence *"a previous run of mine made it"*. An operator's own
`acme_ag`, provisioned by hand a year ago, matches on the slug — and walking into
it to create an administrator and mail a stranger a link into somebody else's
installation is the worst thing this feature could do.

**So identity is the hostname, not the slug.** A tenant made here is routed at
the address below and holds it as its primary domain, written in the same flush
as the registry row, so even the earliest wreckage carries it. A tenant that does
not hold that hostname is somebody else's, whatever it is called, and is neither
resumed nor torn down: the signup fails at `preflight` and a person decides which
of the two names has to move. That refusal repeats in every run for ever, which
is the right amount of pressure for something only a person can settle.

#### A stuck signup is visible where somebody already looks

[XIV-58] sorts a non-serving tenant to the top of the tenant list and names it in
the banner, and `provisioning` is the state it ranks first. That page is the
answer to "where does a half-made customer show up", and it needed nothing added
to it — what this ticket had to make sure of is that a failure *reaches* a state
it can draw, rather than sitting only in the intake table where nobody looks.

It does, because `provision()` persists its registry row before it touches the
cluster. Every failure from that point on leaves a row in `provisioning`. The
failures that leave **nothing** are the pre-flight ones — a name or a hostname
that is no longer available — and those are precisely what the intake checks
below exist to make unreachable. What survives them is the genuine race, and it
is reported in the run's output and counted on the signup row rather than drawn
anywhere. That is the honest limit of this criterion: a customer whose name an
operator took by hand between confirming and the next cron run is visible to
whoever reads the cron mail and to nobody else.

**What is recorded on the signup row is a stage, not a message.** [XIV-59]
settled the same question one table along: a driver exception names hosts, ports
and roles, which is right in front of somebody who already holds the DSN and
wrong on a row something might later draw on a page. `TenantUsage` stores "could
not be read" and prints the driver's words; this stores `preflight`, `tenant`,
`first_user`, `invitation` or `cleanup` and prints the driver's words. A stage
also answers the only question the stored value has to: whether trying again,
unaided, could ever work.

There is still **no third `SignupStatus`**, for §8.12's reason unchanged — a
status here would be a second copy of a fact `tenant.slug` already holds, free to
disagree with it. A failed signup is the same confirmed row it was a minute
earlier, still holding its name, with a counter and a stage beside it.

#### The slug trap, and how the collision is prevented rather than made unlikely

§8.12 kept two slug rules apart on purpose and handed the consequence here:

    TenantProvisioner::SLUG_PATTERN  /^[a-z][a-z0-9_]{1,55}$/     an identifier
    SelfServiceSlug::PATTERN         /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/   a label

**The translation is hyphen to underscore, and nothing else.** It was chosen over
dropping the separator or appending a hash because it is the only rule a human
can perform in their head: an operator reading `acme-bau.xivi.app` in a support
ticket types `psql tenant_acme_bau` without looking anything up.

**It cannot make two customers collide, and that is a proof rather than a hope.**
A self-service slug is drawn from `[a-z0-9-]` and contains no underscore, so the
map is the identity on `[a-z0-9]` and sends the one remaining character to one
that never occurred in the input. It is a bijection onto its image. Two distinct
signup slugs therefore never translate to one provisioning slug, and the intake's
existing rule — one confirmed signup per reserved name — carries over intact with
no second uniqueness check.

**What does not carry over is the check against the registry, and that is the
sharp edge.** `tenant.slug` holds *provisioning* slugs. No self-service slug can
ever equal `acme_bau`, so the intake's `findOneBySlug()` looks it up, finds
nothing, and says `acme-bau` is free — and provisioning then refuses, days later,
after somebody has confirmed an address and been told the name is theirs. So the
intake now asks the registry about the **translated** name as well, at the moment
the name is asked for, and about the **hostname** it would take for the same
reason one noun along: `provision()` derives no hostname from a slug, so an
operator may perfectly well have routed `acme.xivi.app` at a tenant called
something else. Both refusals are `slug_taken`, which is §8.12's rule about one
word covering several situations, applied unchanged.

**The map is also partial**, and the gaps are where the two rules disagree about
something other than separators. A DNS label may be one character and an
identifier may not; a label may start with a digit and an identifier may not; a
label may run to 63 characters and an identifier to 56. Those names are refused
at the intake with `invalid_slug` rather than accepted and failed later.

The two halves of that are treated differently on purpose. A **derived** name is
a suggestion this system made, so `SelfServiceSlug::derive()` now cuts to 56
rather than 63 — a suggestion it cannot honour is its own mistake to fix. A name
somebody **typed** is refused, because silently shortening what a customer asked
to be called is worse than telling them it is too long. The residual cost is
real and is stated rather than engineered around: a company whose name begins
with a digit cannot have that name as a slug, and is asked for another. Every
scheme that would have rescued it — prefixing a letter, appending a digit — makes
the translation non-injective, and losing the collision proof to save `3m` is the
wrong trade.

#### What hostname a self-service tenant gets

**The signup slug as a label under the signup host's parent domain.** A
deployment serving signup at `signup.xivi.app` puts its customers at
`acme.xivi.app`; a single-label host — `localhost`, a container name — has no
parent to take and keeps itself, so a fresh checkout gets `acme.localhost`.

This was already the convention §8.13's form displayed beside the name box, where
`SignupPage::tenantDomain()` called itself *"a display hint"* and said the real
answer was this ticket's. It is now a fact, in
`Provisioning\SelfServiceTenantHostname`, and **the page delegates to it**. That
direction is the load-bearing part: two implementations of "the domain a customer
sits under" is two implementations of a promise, and the way anybody would
discover they had drifted is a customer typing the address they were shown and
reaching nothing.

It also explains, retrospectively, why §8.12 reserves the **first label** of every
system host rather than the whole string. A control plane at `control.xivi.app`
is collided with by a signup for `control` precisely because that signup would be
routed at `control.xivi.app`. That paragraph was written against this convention;
it becomes correct rather than merely well-aimed now the convention is code.

#### The first user gets a link, and no password exists anywhere

`tenant:provision --admin-email` prints a generated password because an operator
is watching a terminal. **Nobody is watching this**, and §8.5's own note said the
printing goes away once there was a mailer. So the first administrator is created
with `createWithoutPassword()` — the hash stays empty, which nothing can
authenticate against from either direction — and §8.8's signed login link is what
admits them. A generated password nobody ever reads is a live credential sitting
on the account for as long as the account does.

Note this is a **different** token from §8.12's confirmation token, and the two
are not interchangeable: that one proved an address existed before there was a
tenant, and this one signs somebody into a tenant that now does. §8.12 explains
at length why the framework's login link could not be used for the first job.

§8.8 predicted two problems with sending one off a cron and left them here, and
both are real:

**There is no request, so there is no hostname to be absolute against.**
`DEFAULT_URI` is `http://localhost` in this repository, and a link generated
against it is a link to nowhere. The router's request context is therefore
pointed at the tenant's own hostname for the duration of the send, over `https`,
exactly as §8.12's confirmation link is built from configuration rather than from
a header — and restored in a `finally`, because the run is a loop in one process
and a leaked context would sign the next person's link for the previous person's
domain. That is a link that *works*, and admits somebody to the wrong
installation. The **port** is deliberately left as configuration put it: the host
is the part only the tenant can supply, while the port is a property of the
installation, which is what `DEFAULT_URI` states.

**There is no request, so there is no locale either.** An invitation is
ordinarily written in the language of whoever pressed the button; nobody pressed
anything. The best answer available is the language the visitor was reading the
signup form in, which the row has carried since §8.12 recorded it for the
confirmation mail — so it is switched to for the send, and it is also what the
new account's own locale is set to.

The mail itself needed no exception carved for it. §8.8 refused one and was
right: a freshly provisioned tenant has configured no SMTP server, so §8.7's
instance fallback applies of its own accord and the message goes out under
`no-reply@` at the customer's own new hostname. "Works on day one" and "the first
user of a self-service signup" meet exactly where §8.8 said they would.

#### The customer is not told when it fails, and that is the honest gap

The run's non-zero exit, the addresses in the cron mail, the attempt counter on
the row and the banner on the tenant list are all addressed to the **operator**,
who is the only party who can act. Nothing is mailed to the person waiting.

That is deliberate for the ordinary case — nearly every failure here is
transient, the next run fixes it, and "we could not set up your installation"
followed twenty minutes later by "here is your login" is worse than twenty
minutes of quiet — and it is a genuine gap for the case that never resolves. A
signup stuck at `preflight` waits for ever while a person is expected to notice.
The counter is what makes that legible: one attempt is a bad afternoon, two
hundred is a name somebody has to give back. A mail after N attempts is the
obvious next move and is not built here, because it needs a decision about what
it may honestly say, and that decision is worth more than the twenty lines it
would take.

#### Privilege

This half holds `CREATE DATABASE` and `CREATE ROLE` and must run only on the
non-public side. Today that is a console command in one deployment, which is a
code boundary and not yet a privilege boundary — §8.12's own honest limit,
unchanged. When [XIV-96] separates the deployments, `signup:provision` and
`Provisioning\SignupProvisioner` belong in the internal image and
`TENANT_ADMIN_DSN` belongs only in its environment. Note also §4.1's finding that
the provisioning role needs more than `CREATEDB CREATEROLE` on Postgres 16 and
later; a deployment narrowing it has that work to do first.

### 8.15 A price a customer can see, and an ask that installs nothing (XIV-102)

The customer-facing half of §6.5. That section gave a module a price, the ability
to set one, and a single seam to read it through; this one puts the figure on the
screen a customer is standing in front of and answers the question the figure
raises — *so how do I get it?*

**There is no payment gateway, and this ticket exists so that there does not have
to be one yet.** A gateway is a decision with PCI scope, a merchant agreement, a
refund policy and a webhook endpoint behind it, and none of those are things a
pricing feature should have to wait for. So the answer is a placeholder, and the
whole ticket is about what the placeholder *is*.

#### The question that decides it, and the two answers that were rejected

**Install anyway, and record that it is unpaid.** Rejected, and written down here
so that nobody re-proposes it as "just for now".

It is the smallest change and it makes the price decorative. A module installed
on the strength of an unpaid flag is a module the customer has: their definitions
are in their own database, their records are in their own tables, and §6.2's rule
that nothing here uninstalls anything means there is no mechanism to take it back
— by design, and rightly. So the flag is a note in a table that no code enforces,
and the first customer who notices gets every priced module for nothing. Worse
than the loss is what it teaches: a price that can be ignored by pressing the
ordinary button is not a price, and every screen that displays one afterwards is
making a claim the system does not stand behind. "Just for now" is exactly how
that becomes permanent — the flag ships, nothing breaks, and the thing that was
supposed to replace it never has a forcing function.

**Refuse, and say to get in touch.** Honest, and it is the fallback rather than
the design. It answers the customer's question with a dead end and hands them a
task — find the address, write the mail, describe which module — while the system
that knows all three sits there not doing it. A self-service product whose
self-service stops at the word "price" has a hole where the interesting half is.

**Record a purchase intent that an operator fulfils.** Adopted, and it is the
shape this codebase already reaches for rather than a new one. §8.12 answers the
same question one layer up: a public surface records an intent and does nothing
privileged, and a non-public process acts on it, because *"anyone may ask" and
"the thing happens" are deliberately not the same event*. Substitute a customer
for a stranger and installing a module for provisioning a tenant and the sentence
survives intact.

The forward-looking half is why it is worth more than the other two: **the day a
gateway lands it slots in where the operator currently stands.** A payment
confirmation is a thing that answers an outstanding request, which is exactly what
an operator installing the module is today. Neither of the rejected shapes leaves
anything for it to slot into — one has already installed the module and the other
has recorded nothing at all.

#### Where the intent is stored, and why there was only ever one answer

**In the customer's own database**, one row per module, in
`module_purchase_intent`.

§4.4 decides this rather than a preference. The customer-facing instance's
database role holds `SELECT` on the registry tables and **nothing else** — no
`INSERT`, `UPDATE` or `DELETE` anywhere in the control-plane database, on any
table, present or future, which is precisely the guarantee [XIV-96] was for and
which `RegistryGrantsTest` proves against a real role on a real connection. A
feature whose first requirement is a write made by a customer's own request
therefore has exactly one database available to it.

That constraint turns out to point at the right place anyway, which is worth
saying so that nobody reads this as a workaround:

- **It is the customer's fact.** They asked; they are the party entitled to see
  that they asked, on their own screen, when they come back on Thursday wondering
  why nothing has happened.
- **It sits beside the thing it is about.** A module is installed into their
  database; the record of wanting one belongs in the same place, next to §6.1's
  definitions rather than one boundary away from them.
- **It cannot leak between tenants**, for the same structural reason nothing else
  can: one request resolves one tenant and the connection is theirs (§7.4).

**How an operator sees it: `tenant:purchase:collect`, which is [XIV-59]'s
collector reused rather than reinvented.** The command walks the registry one
tenant at a time through `TenantSwitcher::runFor()`, reads each customer's
requests, and writes copies into `purchase_intent` in the control plane; the
operator's screen reads that table and opens no tenant connection at all. Every
sentence of §8.11's argument transfers: the fan-out belongs in a process nobody is
waiting on, the page stays one request against one database, and §7.4's guarantee
stays a *consequence* of how requests work rather than a rule with an exception in
it.

**The honest cost, stated rather than buried:** an operator learns about a request
within one collection interval rather than the instant it is made. That is small
against what happens next — a person deciding about money and then installing a
module by hand — and the screen prints the collection time beside every row so
nobody has to guess how fresh the list is. A deployment that minds runs the
command more often; unlike the usage collector, this one is about somebody
waiting, and the command's docblock says so where the crontab line gets written.

#### The shape that was rejected, and it is the tempting one

**Have the store POST to a control-plane HTTP endpoint**, exactly as §8.13's
landing page posts to §8.12's signup intake. It is genuinely the same pattern,
it removes the collector and the interval, and it is wrong here.

It would hand the customer-facing image **a credential that lets it write the
control plane** — a shared secret and a reachable internal host — and thereby
re-obtain over the network precisely the privilege the database refuses it. §4.4's
entire argument is that the sharp boundary is the grant rather than the topology,
because *"not routed" and "not present" are different guarantees and only the
second survives somebody's mistake*. A secret in the public image's environment is
a boundary made of care again, and it is the first thing a copied `.env` undoes.

The pattern is not being misapplied by declining it, either: §8.12's contract is
an HTTP API **because the caller is a third party** — somebody else's website,
compiled against a published shape. Here the caller and the callee are two images
built from one repository by one company against one database. Inventing a network
boundary between them, in the one direction the database has been deliberately
closed, would be reaching for the mechanism and dropping the reason.

Also rejected, more briefly: **widening the grant** so the public role could
`INSERT` into one control-plane table. It is one line of SQL and it costs the
sentence "the role holds no write privilege anywhere", which is the sentence that
makes the guarantee checkable — a role with one exception has a second one coming.

#### The copy, which §6.5 asked for by name

**The price goes onto the request as a copy**, amount and currency, frozen at the
moment somebody pressed the button. §6.5 left this as an instruction rather than a
suggestion, and it is [XIV-67]'s rule about payment terms and §5.9's about invoice
totals arriving at the same place: what was agreed is a fact about the
transaction, never a live lookup. An operator who raises a module's price the next
morning has changed what the *next* customer will be quoted and has changed nothing
about this one — which is the only reading under which the figure the customer saw
means anything.

The collector carries the copy across untouched and **never consults
`ModuleCatalog`**, so the operator's screen cannot drift back to the live figure
by somebody being helpful.

**Asking again rewrites the row rather than adding one**, which is §8.12's
`reissue()` for its reason: somebody pressing the button twice is asking again,
most likely because nobody replied, and an operator's queue full of duplicates is
a queue that stops being read. The copied price is refreshed with it, because a
second press is somebody reading today's figure. `created_at` is not, because how
long this has been outstanding is the number that says how badly it went.

#### There is no status column, on either side

Fulfilment is **observed**, not tracked. The customer either has the module or
they do not, their own metadata is the truth about that (§6.1), the collector is
already inside their database, and nothing here uninstalls anything (§6.2) — so
`installed` on the collected row is read at collection time and a status column
would be a second copy of a fact the customer's database already holds, free to
disagree with it. That is §8.14's argument for refusing a `provisioned` status on
a signup, and it lands the same way.

The visible consequence is that **the operator's screen has no button on it.** An
operator answers a request by installing the module — `tenant:module:install`,
which §6.3 kept precisely so that a page is never the only way to do something —
and the next collection sees it. A "mark as fulfilled" control would be a way to
make this screen disagree with reality, on the one screen somebody opens to find
out whether they still owe anybody anything.

#### Who asked does not cross, and the gap that leaves is named

The tenant-side row records the person's id and the name they had at the time —
`follow_up`'s two-column pattern, so somebody leaving does not take the record of
a purchase request with them — and **neither value ever leaves that database.**
§8.11 drew the line at *how much* rather than *what*, and a customer's own people
are on the far side of it: an operator page that listed names would have made the
control plane a way to read a customer's staff without their knowing.

So an operator knows **which company wants which module** and does not know whom
to write to. They reach the customer the way they already reach them, which is the
arrangement the registry describes. That is a real limitation and it is the right
side of the line; a contact column here would be a second copy of somebody's
personal data in a database they cannot see, kept for a conversation that happens
elsewhere anyway.

#### Buying is its own permission, and that is a decision rather than an omission

**`StoreAction::Buy`, a third case on [XIV-6]'s axis** rather than a reuse of
`install`.

The two are close enough that folding them together is the obvious move, and the
reason not to has nothing to do with software. `install` is *"may decide what this
installation consists of"* — §8.4.3's own words for it — which is authority over
the shape of the system, and in a twelve-person company it belongs to whoever set
the thing up. `buy` is may **commit this company to a payment**, which is authority
over its money, and in the same company it very possibly belongs to somebody who
would not know what a preset was.

The direction of the mistake decides it. Granting an office manager `install` so
they can add follow-ups, and thereby granting them the ability to order something
the owner has to pay for, is a surprise nobody consented to. The reverse — a
second grant that mostly gets handed to the same person — costs one more switch on
a screen that already draws every other one.

**It costs nothing today and cannot break anything today**: every module in this
repository is free, so nothing is buyable, and no existing grant changes meaning.
The day a deployment prices something is the day somebody has to decide who may
spend, which is exactly when that decision should be asked for rather than
assumed. And `buy` does **not** imply `install`, which is the property that makes
it safe to hand out: a purchase request installs nothing at all.

A third *axis* was not needed and was not added — the subject is still `@store`,
the scope still does not apply, and the permission screens draw the new verb
because they iterate the enum. The counter-argument, which is not stupid: one
grant is simpler, and a deployment that wants one authority grants both to one
group in one screen. That is why this is a case on an existing axis rather than
something larger.

**The operator's side has no permission at all**, and the asymmetry is not an
inconsistency. A tenant has many users with different authority over the company's
money; this installation has operators, all of whom are the company running Xivi.
Inventing a "may see purchase requests" grant before there is a second kind of
operator would be modelling a guess (§8.9) — the same sentence §6.5's pricing
screen carries.

#### What the placeholder must not be, and each absence is a decision

**It must not look like a payment page.** A form that looks like checkout and
quietly does nothing is worse than a sentence saying what is actually going on,
because it teaches people to type card numbers into pages that do not take them —
a habit worth not creating in software somebody uses at work every day.

So, item by item, and each of these is asserted rather than intended:

- **No card fields.** None, of any kind, disabled or otherwise. The page's only
  input is the CSRF token, and `ModulePurchaseTest` counts them — bluntly, because
  that assertion is what goes red when a later ticket makes the page friendlier.
- **No total, no line items, no VAT row.** The price appears once, as what the
  module costs. A total is the visual grammar of a page about to charge you.
- **No "processing", no spinner, no confirmation number.** The button posts a row
  and redirects; there is no transaction to have a state.
- **No promise of when.** "Somebody will get in touch" is what is true. An
  installation that said "within 24 hours" would be making a commitment on behalf
  of a company this code knows nothing about.
- **No congratulation.** The flash afterwards says *asked for X, nothing has been
  charged, somebody will get in touch* — not "thank you for your purchase", which
  is the exact lie the whole ticket refuses to tell.

What it does say is what it costs, that pressing the button is a request rather
than a payment, that nothing is charged, and that a person will reply.

#### A free module says nothing, and that is what makes this ticket invisible

**Absence of a price is the ordinary case in this store and it looks ordinary.**
Not a "Free" badge on every tile, not a zero. Almost everything here is free and
always will be for a deployment that sells nothing, so a badge everywhere is noise
everywhere — and worse, a page that says "Free" on every card has taught its
reader to skip that line, which is the line that matters on the one card that is
not.

The acceptance criterion that guards it is that **the existing store tests pass
unchanged**, and they do: `ModuleStoreTest` is untouched by this ticket, because
`publish()` there already prices every module `free` (§6.5 made that necessary)
and every screen and every check behaves for a free module exactly as it did
before.

The other two pricing states never reach the store at all — `unpriced` and
`not_for_sale` are withheld by `CatalogEntry::isOfferedInStore()` (§6.5) — so the
presentation only ever has two cases to draw.

**A module priced after installation keeps working**, and the store says nothing
about it: the customer sees "you already have this", no button appears, and
nothing anywhere treats "priced and installed" as an anomaly to correct. §6.5
proves that rule against the control plane with a photograph; `ModulePurchaseTest`
proves the customer's side of it, including that their fields are exactly as they
were.

#### Money on the screen, and a currency that may be unset

**Drawn as it is stored** — a decimal string at two places with the ISO 4217 code
beside it — and deliberately not through a locale-aware currency formatter. Three
reasons, in increasing order of weight: `NumberFormatter::formatCurrency()` takes
a float and §5.9 is that nothing on a money path is ever a float; the currency may
be absent, in which case there is nothing to format *with*; and this figure is
copied verbatim onto the purchase request, so the value shown and the value stored
have to be the same string rather than the same number rounded twice. On a
customer's own invoice a formatted amount is right; on a price they are about to
commit to, the stored value is.

**An unset currency shows a bare number, and the customer is told nothing about
why.** §8.6 refuses to guess a currency for a customer because a guessed one is
wrong quietly, and §6.5 refuses to guess one for a price list; the same refusal
here means `49.00` stands alone when `PRICE_CURRENCY` is empty, which is the state
this repository ships in and the state the test suite runs in. The *operator's*
screens name the variable in that situation because an operator can go and set it;
the customer's screen does not, because they cannot, and a sentence about somebody
else's environment file is a deployment detail offered in place of an answer.

#### VAT, named and moved on from

§6.5 settled on a **one-off** price, so tax on that sale is a real question and it
is not this ticket's. Nothing here computes, displays or stores a tax amount, and
the figure a customer sees is the figure §6.5 stores with no claim attached about
whether it includes anything. When a gateway lands, the deployment's own VAT
position — where it is registered, whether the customer is a business in another
member state, whether reverse charge applies — arrives with it, and it is a
feature with a tax adviser in it rather than a column. Recording that it was
noticed is the whole of what this paragraph is for.

#### What is deliberately not built

No gateway, no invoice to the customer for the purchase, no recurring billing, no
refunds, no tax handling — all out of scope by the ticket. Beyond those, three
smaller absences worth naming rather than leaving to be discovered:

- **Nothing withdraws a request.** A customer who changes their mind tells
  somebody, exactly as they would about the request itself. The collector already
  removes a collected row whose request has gone, so the machinery is there when a
  screen for it is wanted.
- **Nobody is notified.** No mail to the operator when a request arrives, and none
  to the customer when it is fulfilled — the second being visible anyway, since the
  module appears. This is §8.14's honest gap in a smaller form, and the same
  argument applies: a notification needs a decision about what it may honestly say.
- **The operator cannot decline one on the screen.** They get in touch, which is
  what the page tells the customer will happen; a declined request is a
  conversation rather than a state.

---

### 8.16 An operator can say something, and it lands where the work is (XIV-120)

The previous iteration had `LicenseClientNotification` — a title, a summary, a
body, a date, the client it was for and a status. This one had nothing, which
meant **whoever runs an installation could see every customer and tell them
nothing**. Three sentences an operator knows and a customer needs:

* *"This installation will be unavailable on Sunday morning while we upgrade."*
* *"The invoice module gained payment terms; your existing invoices are
  unchanged."*
* *"Your trial ends in a week."*

All three were an email somebody sent by hand from their own client, if they
remembered.

#### Where it appears, and why not by mail

**On the customer's own dashboard**, as a widget (§8.3.1). Mail is a second
channel with its own deliverability problems, and §8.7 is a whole section about
how much has to be true before a customer's installation sends a message
reliably; none of that should stand between an operator and *"we are upgrading on
Sunday"*. This is information that belongs where the work is.

It is a widget rather than a banner welded into the layout because [XIV-66] had
just built the seam that makes a card a class and a template, and inventing a
second mechanism for the same job a week later is exactly what that seam exists
to prevent. `NoticeWidget` sits above the follow-ups, which is the only widget
with a real claim to the top of the page: a maintenance window on Sunday is
information about whether the work below it can be done at all.

#### Which database it lives in, and why this is [XIV-102] in the easy direction

**In the control plane, in the registry half, read directly by every instance
that has to show it.** No collector, no interval, no copy.

§8.15 met this boundary from the other side and had no such luxury. A purchase
request is a **write** made by a customer's own request, §4.4 gives the
customer-facing instance's role `SELECT` on the registry tables and no write
privilege anywhere in that database, so that row had exactly one home — the
customer's own — and an operator sees it only because `tenant:purchase:collect`
copies it back. A notice is written by an operator on the instance that owns the
schema and is only **read** by a customer, and reading the registry is precisely
what the grant already permits. **The constraint that made that ticket expensive
makes this one cheap**, and neither is a workaround.

That was confirmed against the grant rather than assumed, and the confirmation
turned out to have teeth.

**The namespace is the grant.** `App\Deployment\RegistryGrants` derives the
readable list by walking the `control` entity manager's mapping and taking the
table name of every class under `App\Registry\Entity\`, and nothing else. So a
`Notice` declared in `Xivi\ControlPlane\Entity` — which is where an operator's
feature would naturally be filed — would land on the *withheld* list beside
`operator` and `signup_request`, and the first customer dashboard to render would
meet a permission error. `Notice` and `NoticeRecipient` are therefore
`App\Registry\Entity` classes in `src/`, exactly as §3.1 requires of anything a
tenant's own request reads.

**And the recipients are an entity rather than a `ManyToMany`, which is the
finding worth keeping.** A many-to-many's join table is not a class, has no
metadata, and is therefore *invisible to the grant generator*. The grant would
have been generated, run, and been wrong — and the failure would have appeared
only on the deployment that matters, only for notices addressed to named
customers, and never in a suite that runs as an account holding everything. The
general form: **anything that is a table but not an entity is outside
`readableTables()`**, and the only other member of that set today is
`doctrine_migration_versions`, which is named explicitly for that reason. Nothing
enforces this in general — a test would have to know what tables a future feature
means to read — so it is written down here and asserted for these two tables by
name.

**The proof is a role, not an argument.**
`tests/Functional/Deployment/NoticeGrantsTest.php` creates the role, runs
`RegistryGrants`'s own statements, opens a connection **as that role**, builds an
entity manager on it and runs `LiveNotices` — the same class the dashboard runs —
through it. That is why `LiveNotices` takes its entity manager as a constructor
argument instead of being a `ServiceEntityRepository`: a repository resolves its
own connection out of the `ManagerRegistry`, so the test could only ever have
exercised it as the suite's own privileged account, which is a test that proves
nothing while passing. The same class asserts the role still cannot `INSERT`,
`DELETE` or address anything.

**The cost of that arrangement lands on a deploy, and it is worth stating.** A
release that adds a registry table means `deploy:registry-grants` has to be run
again — that is why the command derives its list from the mapping rather than
maintaining a script (§4.4) — and an installation that skips it gets a
customer-facing instance whose role cannot read `notice`. The widget asks on
every dashboard render, so the failure is immediate, loud and total for that
instance rather than latent: the landing page 500s, which §8.3.1 already decided
is the right behaviour for a widget that throws. That is the honest reading of
the trade this feature makes. A `deploy:check-grants` beside
`deploy:check-hosts` and `deploy:check-secrets` — asking the database whether the
role can read what this build intends to read — is the obvious next move and is
not built here; it is a bigger thing than a notice board and would deserve
deciding on its own.

**The author is a copy, and the reason is stronger than the usual one.**
`notice.author_label` is the operator's name as it was when they published, not a
foreign key to `operator` — because the reader the column exists for is a
customer, and §4.4 gives their instance no access to that table at all. A join
would be unreadable by the only party that needs the value. The ordinary reason
holds too: an operator later revoked or renamed must not rewrite the authorship
of something already published.

#### Everybody, or named customers

Both, as the ticket asked. `notice.every_tenant` is the switch and
`notice_recipient` is the list.

**They are not folded into one.** "No recipient rows" and "everybody" would look
identical on the screen and mean different things in fact: recipient rows cascade
away with a deprovisioned customer, so an announcement addressed to three
companies would silently become an announcement to the *entire installation* on
the day the last of them left. A boolean says which of the two somebody meant and
no cascade can change it.

**A third case — "every customer who has module X" — was considered and is not
here.** It is a different kind of question. The registry knows which modules are
*enabled* for a tenant; what a customer has actually installed is their own
metadata (§6.1), one boundary and one database away, and answering it for every
customer is a collector's job. That is a feature, not a case in an enum.

**Addressing is all-or-nothing.** A notice naming a customer who is not there is
refused entirely, with the name in the message, rather than published to the ones
that resolved — because reaching three of four companies while reporting success
is this feature's characteristic failure wearing a different hat. So is a notice
addressed to named customers and naming none, which is refused for the same
reason: *the operator would believe they had told somebody.*

#### Who inside a tenant sees it, decided per notice

`NoticeAudience` is `Everyone` or `Administrators`, on the notice.

A maintenance window is for everybody who might sit down to work on Sunday; a
trial ending is for whoever pays, and putting it on the screen of a colleague who
cannot act on it is either noise or an awkward conversation somebody did not
choose to start. A global rule would have to pick one and be wrong about the
other every time, which is why the ticket asked for this and why the answer is a
column rather than a setting.

**The second case is coarse and says so.** A tenant's own authority model is
§8.4's grants — per person, per module, per verb — and none of it describes "the
person who pays", because nothing in the product has ever needed to. `ROLE_ADMIN`
is the nearest true thing an installation knows. That is honest for a trial
ending and would be dishonest dressed up as anything finer.

**A permission was considered and refused.** A `@notices` area on §8.4.3's second
axis would let a customer decide who reads announcements — and therefore let a
customer switch off a channel the operator is relied upon to have. The addressing
belongs to the sender here, which is the one place in this product where that is
true, and it is true because the sender is the party running the installation.

#### Dismissing, and where that write goes

**A customer can dismiss a notice, per person, and the row lands in their own
database** — `notice_dismissal`, in `src/Tenant/Entity`.

This is §8.15's shape reused rather than a new one, and again §4.4 decides it
rather than a preference: the customer-facing instance may read the control plane
and may write nothing there, so a dismissal has exactly one database available to
it. **The feature reads across the boundary and writes on this side of it**,
which is the arrangement the grant was built to force.

**Per person, not per tenant.** Dismissing is *"I have read this"*. A
tenant-wide dismissal would let whoever opened the dashboard first take a
maintenance window off everybody else's screen, which is the silence the whole
ticket is against.

**`notice_id` is an integer with nothing to point at**, since the row it names is
in another database. That makes it the same kind of value as a saved dashboard
layout's widget key (§8.3.1) or a stale `reference` (§7.6): data referring to
something outside this database, resolved where it is read and dropped when it
resolves to nothing. A dismissal of a deleted notice hides a notice that does not
exist, which is correct and needs no repair — and there is deliberately no
process hunting orphans, because a cross-database garbage collector is a much
worse thing to own than a few bytes.

#### Stopping one, and what an operator can see

**A notice is live between `published_at` and `expires_at`, and withdrawing is
the second of those being set to now.** One concept rather than two: a
`withdrawn` boolean beside an expiry would be two ways of saying *stop showing
this*, free to disagree, with every reader having to remember both. The cost is
that an operator cannot afterwards tell whether something ran out or was pulled,
which is a fact about the past nobody has asked for.

**Withdrawing is not deleting.** The row stays on the operator's screen, marked
ended, because *"what did we tell them in March"* is a question somebody asks and
a list that answered it by having no row would answer it wrongly. That is the
purchase screen's argument for keeping fulfilled requests, reused.

**The operator's screen leads with what is live and states who each notice went
to.** Those are the two facts an operator's belief rests on, and both are the
kind that fail silently — a count that included ended notices, or a row that
printed another notice's customers, would leave the page looking exactly as it
does when it is right. So the count is asserted against a page holding both live
and ended notices, and the addressing is asserted with two notices addressed to
*different* customers, which is the only shape in which a query that ignores its
scope can be caught.

#### The widget costs a query in `panel()`, which is a departure

`DashboardWidget::panel()` is documented as cheap by contract (§8.3.1): it is
asked of every widget on every render, before the reader's layout is applied, so
a widget that counts rows there charges the page for a card somebody may have
hidden. `NoticeWidget` asks the registry whether anything is live for this
customer, which is a database read. **That is deliberate, and the alternative is
worse.**

"Does this apply to you" is answerable for the follow-ups from a per-request
metadata cache and for the module tiles from the navigation. For a notice it *is*
the question the database holds. A widget that returned a panel unconditionally
would put a permanent, usually-empty "Notices" card on every dashboard in every
installation — furniture, which §8.10 and the purchase screen both refuse — and
would make the one week it says something the week nobody notices it.

The cost is bounded rather than hand-waved: one indexed `SELECT` on the control
connection, which is already open because resolving the tenant needed it, and a
second query against the customer's own database **only when the first found
something**. An installation that announces nothing — most of them, most weeks —
pays one read. `defer` is false for the same reason: by the time the panel
exists, its contents are already in memory, so deferring would buy a round trip
to render text we have.

#### [XIV-108] revisited, and the answer is no

The ticket asked whether this is the mechanism [XIV-108] was waiting for — a
signup that never provisions leaves somebody waiting in silence, and §8.14's own
honest gap is that nothing is mailed to the person waiting. **It is not, and the
reason is structural rather than a matter of effort.**

A notice appears in an installation, addressed to a tenant, on the dashboard of a
user. A stranded signup has **none of those three**: provisioning is precisely
what has not happened, so there is no tenant row to address, no database to hold
a dismissal, and no user account to sign in and read anything. The person is
waiting *outside* the product. Reaching them needs a channel that works before
they are a customer, which is the mail §8.12 already sends them their
confirmation on, and the thing genuinely blocking it is what §8.14 said: a
decision about what such a mail may honestly say.

So [XIV-108] is unblocked by nothing here and keeps its own ticket. What this
does give it is one thing: the moment a stranded signup *does* become a customer,
an operator has a way to tell them what happened.

#### What is deliberately not built

**No read receipts.** An operator can see that a notice is live and what it was
addressed to; they cannot see that anybody read it. Knowing that would mean
collecting a fact out of every customer's database — [XIV-102]'s collector
pointed the other way — and it is a feature rather than a column: a walk over
every tenant, a table of collected counts, and a page that says how old the
figures are. **This is the honest gap in this ticket**, it is the half of *"not
silent"* that is not answered, and the operator's screen says so on the screen
rather than leaving somebody to assume the number is somewhere. Reusing
dismissals as receipts was considered and refused: a dismissal is a button
somebody pressed, and reporting it as "read" would over-claim on exactly the
screen an operator uses to decide whether they have communicated.

**No scheduling.** Publishing is immediate. A notice dated for Friday is a real
thing to want and needs a third state on the operator's screen — live, ended, and
*pending* — which is more page than the feature has earned. The column is
compared against `now` rather than assumed, so the day it lands is a form field.

**No severity, no colour, no icon per notice.** Every notice is drawn the same
way, so nothing competes for attention by claiming to be urgent. The day a
genuine emergency channel is wanted it should look different from this one rather
than being this one with a flag set.

**No links, no markdown, no HTML, no image.** The body is plain text rendered as
plain text. The moment a notice can carry a link it is a channel somebody can be
phished through, on the one screen in the product a customer has no reason to
distrust — and an operator writing to every customer of an ERP they depend on is
a serious act that should not look like a campaign.

**No translation.** A notice is written once, in the language the operator wrote
it in, and shown to everybody exactly as written — including a customer reading
the rest of the interface in German (§8.4.2). Every other string in this product
comes out of a catalogue, and this one cannot: it is somebody's sentence, not a
key. The alternative is a form with one box per language, which is a real answer
for a deployment that needs it and is not this one.

**No summary.** The previous iteration had a title, a summary and a body; a
summary is a second thing to write that can disagree with the first. A title and
a body is what an announcement is.

---

### 8.17 A customer can reach whoever runs this installation (XIV-123)

The previous iteration had a `Support` module — tickets, replies, an FAQ. This
one had **no channel from a customer to the operator at all**: not a ticket, not
a contact form, not an address. A customer whose invoice module was behaving
oddly, or who wanted a module they could not see in the store, had whatever email
address they happened to be given when they signed up.

#### The pair this completes

§8.16 gave the operator a way to talk **to** customers. This is the return path,
and the two are one feature seen from both ends:

* an announcement is one-to-many, scheduled, and about the installation;
* a ticket is one-to-one, unscheduled, and about a problem.

Neither substitutes for the other, and building only the first would have been
odd — an operator who can broadcast and cannot be replied to.

#### Where it lives, and the constraint that decides it

A ticket is **written by a customer**, which is [XIV-102]'s direction and
therefore [XIV-102]'s constraint: §4.4 gives the customer-facing instance
`SELECT` on the registry tables and no write privilege anywhere in the
control-plane database. So the ticket cannot be written there, and it goes where
every write a customer's request makes goes — into their own database, as
`support_ticket` — with `tenant:support:collect` bringing it back for an operator
to read. That is `tenant:purchase:collect`'s shape with different columns, reused
rather than re-derived.

The alternative that removes the collector is the same one §8.15 rejected and it
is rejected here for the same sentence: **an HTTP call to the control plane**
would hand the public image a credential that writes that database, re-obtaining
over the network exactly the privilege PostgreSQL refuses it. §4.4's whole
argument is that the sharp boundary is the grant rather than the topology.

#### But the answer comes back the other way, and that is the design

Here is where this stops being [XIV-102] and starts being both tickets at once.

**The status and the reply live on the collected copy, in the control plane, and
the customer reads them directly.** Reading the registry is precisely what §4.4's
grant has always permitted (§8.16 is a whole section about how cheap that
direction is), so an operator who answers at 14:03 has answered on the customer's
screen at 14:03. There is no second collector pointing into every customer's
database, no push, and nothing that can be stale.

That decides the one thing about this feature that could have been got quietly
wrong: **`support_request` is an `App\Registry\Entity` class**, not one of
`Xivi\ControlPlane\Entity`'s. `RegistryGrants` derives the readable list from that
namespace and no other, so the namespace *is* the grant — and filed beside
[XIV-102]'s `purchase_intent`, which is the obvious place for it, every
customer's support page would have met SQLSTATE 42501. The difference between the
two tables is exactly the difference between the two features: a purchase request
is collected **for an operator to read**, and a support ticket is collected so
that an operator can **answer**.

**The cost of that lands on a deploy, and it is stated rather than discovered.**
A release that adds a registry table means `deploy:registry-grants` has to be run
again — that is why the command derives its list from the mapping rather than
maintaining a script (§4.4) — and an installation that skips it gets a
customer-facing instance whose role cannot read `support_request`. The failure is
immediate, loud and total for that instance rather than latent. [XIV-120] made
the same trade for `notice` and `CHANGELOG.md` names it as an action bullet the
same way.

#### The delay, decided rather than inherited

Collection means a delay, in one direction only, and it is worth being exact
about which:

| | Who is waiting | How long |
| --- | --- | --- |
| Customer → operator | nobody is watching a screen; the operator is not sitting in the product | one collection interval |
| Operator → customer | the person who asked, who came back to look | none at all |

**The leg that waits is the leg where nobody is watching.** That asymmetry is
what makes the interval acceptable, and it is why the alternative — a secret in
the public image — buys so little for what it costs.

Three things follow, and all three are built:

1. **`App\Monitoring\ScheduledJobs` carries `tenant:support:collect` at five
   minutes**, which is `signup:provision`'s cadence rather than
   `tenant:purchase:collect`'s ten, and for `signup:provision`'s reason: somebody
   is waiting rather than something is being counted. §4.5 exists because
   `tenant:purchase:collect` shipped into *no* list of cron entries at all; the
   list is now what `deploy:crontab` prints, so this job reaches a crontab
   without anybody remembering it.
2. **The customer's own screen says so.** A ticket nobody has collected reads
   *not received yet* rather than borrowing a status it has not got — §8.11's
   *absence says it exactly*, pointed at the person the delay happens to. That is
   the honest rendering, and it is what stops a quiet product looking like a
   broken one. The flash after raising one says the same thing in words, and
   deliberately does not thank anybody or promise a time (§8.15's rule).
3. **The operator's screen prints the collection time on every row**, and its
   empty state names the command. This matters more here than on the purchase
   screen: an empty support queue and a cron entry nobody ever wrote look
   identical, for ever.

**No interval is printed anywhere in the product.** How often tickets are
collected is a line in the crontab of whoever runs the installation, and a figure
on a customer's screen would be this repository guessing at somebody else's file
— §8.15's refusal to promise "within 24 hours", one screen over.

#### Replies are in scope, and the shape is one column

The ticket asked whether replies were a first slice or a later one. They are in,
because **the mechanism is already paid for**: a status the customer can see
requires a control-plane row the customer can read, and once that exists an
operator's answer is one `TEXT` column on it arriving by the same route. Building
the whole read path and then telling the customer to check their email would have
been the odd outcome.

What is *not* built is a thread. There is one reply per ticket; sending another
rewrites it, and `replied_at` moves with it, because a second version of an
answer is the answer. A customer cannot answer back in place — they raise another
ticket, and the operator sees both.

The reason is a boundary rather than effort. **A customer's message crosses the
collector and an operator's does not**, so a two-sided thread is not one feature
symmetrically applied: it is a message table on each side, an interleaving that
has to survive a collection interval, and an operator's screen that has to make
sense while half the conversation is in flight. That is a conversation product,
and it is a much bigger thing than this ticket. What is here is the honest first
slice: **a customer can ask, an operator can answer, and both can see where it
has got to.**

**Replying does not move the status**, which is a decision rather than an
oversight. An operator who answers and considers it finished closes it; one who
answers and expects to hear more leaves it in progress. A hidden state change on
a screen that also has a visible state control is how the two stop agreeing.

#### The status, and the states that are not there

`SupportStatus` is `Open`, `InProgress`, `Closed`.

§8.15 refused a status column outright on a purchase request, and the argument
was good: fulfilment there is *observable* — the customer either has the module or
they do not, and a column would have been a second copy of a fact free to
disagree with it. **None of that transfers.** Whether somebody has picked up a
question is not observable from anywhere; it exists in an operator's head until
they say so, and a customer staring at silence is the entire problem this ticket
is about.

So each case has to earn its place by saying something nothing else says:

* **`InProgress` is the one that earns the enum.** *"Somebody is looking at
  this"* is what a waiting customer most wants and what no other column can
  express; without it the only way to signal progress is to close a ticket that
  is not finished.
* **There is no `Answered`.** A reply is visible on the row — the customer is
  reading it — so a state naming its existence would be §8.15's second copy
  arriving by the back door.
* **No priority, no category, no SLA.** An ERP support queue is not a helpdesk
  product and those three are how it becomes one. Each is also a promise: a
  priority promises an ordering and an SLA promises a time, and this installation
  knows nothing about the arrangement between the two companies that would let it
  keep either.

Any state may follow any other. A lifecycle (§5.8) would be modelling a process
nobody has described, and an operator reopening something they closed by mistake
is an ordinary Tuesday.

#### Who may raise one: everybody signed in

Not administrators only, and not a per-installation setting.

**Raising a ticket commits nothing** — no money, no install, no change to the
installation — which is the whole of the difference from [XIV-102]'s `buy`, where
the argument for a separate grant was that pressing a button obliges the company
to pay somebody. And **the person who met the problem is the person who can
describe it**: routing a bug report through an administrator means the
description travels through somebody who did not see it happen, or does not
travel at all.

**A per-installation setting was refused**, and not only on the grounds that it
is more than this needs. It is a switch whose only possible effect is to stop
somebody with a problem reaching the people who can fix it — §8.16's argument for
refusing a `@notices` permission, pointed the other way: the channel between a
customer and their operator should not be something either end can quietly close.

The firewall is the whole of the access control, and
`SupportTicketTest::testSomebodyWhoIsNotSignedInCannotReachIt` proves it through
a real request, on the POST as well as the GET, because a form that is not drawn
is not a check.

**The tickets are the company's, not the reader's.** A colleague who asked the
same question on Tuesday should find the answer rather than ask it again, which
is most of what a screen buys over an email. The name of whoever raised each one
is on the row, so nothing is anonymous — it is simply not private between
colleagues, and the page says so where somebody deciding what to type will meet
it.

#### What a ticket carries, and who does not cross

A subject, a body, a date, who raised it, and a status. The ticket asked for
exactly that and nothing was added.

**Who raised it does not cross.** The tenant-side row keeps the person's id and
the name they had at the time — `follow_up`'s two-column pattern, so somebody
leaving does not take the record of a question with them — and **neither value
ever reaches the control plane**. §8.11 drew the line at *how much* rather than
*what*, [XIV-102] held it for purchase requests, and it is held here where
crossing it is most tempting: an operator would obviously like to know whom to
write back to.

**They do not need to, and that is what makes this line free rather than merely
principled.** The answer is delivered inside the product — it lands on the
collected row and the customer reads it on the screen they raised the ticket on —
so an operator answers the company without ever learning which of its staff typed
the question.

#### The reference, and a rebuilt database

The collected copy is matched on a random 128-bit `reference` generated in the
customer's database, on the pair `(tenant, reference)`.

The primary key would have been the obvious choice and is wrong. **Ids are a
sequence per database**, so a customer whose database is rebuilt — `tenant:reset`
does exactly that, and §4.1's rebuild is a supported operation — starts again at
1, and the next collection would find "ticket 1" and overwrite the row holding an
operator's answer to a different question. And the tenant is half of the key
because a reference is a value produced inside a *customer's* database: matching
on it alone would let one customer name another's row by producing the same
string.

#### The collector removes nothing, which is where it differs from [XIV-102]'s

`PurchaseIntentCollector` deletes a collected row whose request has gone from the
customer's database, and the reason is good: a queue half full of requests that
no longer exist is a queue somebody stops trusting.

**The support collector deliberately does not**, because the operator's half of
the row — the status, the reply, who wrote it and when — exists **only here**.
Deleting it would destroy the answer rather than tidy up after it, and *"we
answered them in March and then their database was rebuilt"* is a question
somebody asks. Nothing in this system deletes a support ticket.

For the same reason the collector writes the customer's three columns and touches
nothing else. A collection that rewrote the whole row — the obvious
implementation, and the one an upsert produces — would discard an answer whenever
a run overlapped with somebody typing one, on a job that runs every five minutes,
and the visible symptom would be a customer shown their own question back with
the answer gone. `SupportRequestTest::testACollectionDoesNotUndoAnOperatorsAnswer`
is what goes red.

#### The FAQ is out of scope, and its home is named

The previous iteration's `Support` module bundled tickets, replies **and an FAQ**,
and the third of those is a different feature wearing a similar name. An FAQ is
**documentation**, and this project has a documentation site in its own
repository — <https://praesidiarius.github.io/plc-xivi-docs/>. If a customer's
question has a written answer, that is where the answer belongs: written once,
versioned with the product, readable by somebody who has not signed in yet, and
editable without a deploy. Reproducing it inside the application would mean a
second place for the same sentences to be wrong in.

**And no link to it from the support page either**, which is the smaller decision
inside the larger one. The docs site's address is a fact about *this* deployment
— a company that forked Xivi has its own — so putting it on a customer's screen
means a new environment variable and a new deployment fact, for a link. `README.md`
names it for the people who can act on it. That is where it stays until somebody
asks.

#### What is deliberately not built

**No mail, in either direction.** Not to the operator when a ticket arrives and
not to the customer when it is answered. §8.7 is a section about how much has to
be true before an installation sends a message reliably, and none of it should
stand between somebody with a problem and the people who can fix it — §8.16 made
the same call for notices. The customer sees the answer where they asked; the
operator sees the queue where they work.

**No attachments and no screenshots.** A screenshot is the single most useful
thing a support ticket could carry and it is not here, because it is a file
upload crossing a tenant boundary — stored in the customer's database, copied or
referenced by a collector, and served to an operator on a different host. That is a feature, not a column, and it is the first thing to
build when this one has been used in anger.

**No read receipts and no "the operator has seen it".** `InProgress` is somebody
*saying* they have picked it up, which is a claim a person makes rather than a
fact a system observes — and §8.16's refusal to report a dismissal as "read"
applies here word for word.

**No search, no paging, no filtering.** One company's tickets are a list of tens
over the life of an installation, and the operator's page is every company's list
sorted by who has waited longest. When it stops being tens the answer is the same
one §8.10 gives for the tenant list: a search box, and the ordering reaching SQL.

**Nothing withdraws a ticket.** A customer who solves it themselves says so, in
the ticket, which is what a person does anyway.

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
the directory — and `DEVELOPING.md` says to `docker image rm` it. An accepted cost
that is written down beats an automatic one that surprises people.

**And a hundred buckets is not many once worktrees are the normal case**
(XIV-86). XIV-51 gave each checkout its own ports by adding an offset to five
bands, the offset being `cksum` of the project name modulo one hundred, plus one.
`cksum` was chosen for being in every POSIX box rather than for spread, and the
modulus for the bands each owning exactly a hundred numbers — neither decision
was about how many checkouts there would be. This is the birthday problem, and
the numbers are unkind: seven checkouts collide about one time in five, twelve
better than even. Parallel agents in worktrees turned "two branches at once" from
the exotic case into the ordinary one, and seven live worktrees on this machine
did in fact put two on offset 52.

**What a collision looks like is the reason it is worth code.** Docker refuses
the bind on whichever published port `up` reaches first and reports that port and
nothing else — not which other checkout holds it, not that all five addresses
belong to that checkout, and not that the number came from a checksum of a
directory name. But the loud half is the harmless half. `DATABASE_PORT` is the
address `xivi_stack_summary` prints for PhpStorm's database panel and for `psql`,
and on a collision that address *answers*: it is the neighbouring checkout's
Postgres, healthy, holding a full set of that checkout's tenant databases, and
nothing about the connection suggests otherwise. AGENTS.md's standing warning is
that a bare `docker compose` in a worktree runs the suite against the main
checkout's tenants; this is the same hazard through a different door, reached by
somebody who did the right thing and used `bin/compose`.

**Detect and refuse, rather than detect and step.** Four shapes were weighed.
Stepping to the next free offset is the convenient one and quietly gives up what
the offset was for: a checkout's URL would depend on what else happened to be
running the morning it started, so the address stops being a thing you can
bookmark — which is the only property the scheme was ever buying. Widening the
space makes a collision rarer and never impossible, at the cost of restructuring
bands whose hundred-wide arithmetic is load-bearing, and it is a probabilistic
answer to something a check can settle. Doing nothing was legitimate while
worktrees were rare. So: `xivi_assert_ports_free` in `bin/lib/stack-env.sh` takes
one `docker ps`, matches this checkout's derived ports against what other compose
projects are publishing, and refuses with the offset, the holding project, its
directory, and the six exports that move this checkout somewhere free — the
suggestion computed from the same snapshot, so it costs nothing extra and the
stepping still happens in a human's shell rather than behind their back.

Two constraints shaped it more than the detection did. It must not tax the common
case, so it is a function the callers invoke rather than something the fragment
does while being sourced: `bin/compose` runs it only for the subcommands that
create containers, which means `exec`, `logs`, `ps` and `down` pay nothing, and
finding that subcommand means walking past Compose's value-taking global options
rather than reading `$1`. And an explicit export must win *past* the check as well
as past the derivation — the `${VAR:-…}` form at the top of that file promises
that, and a guard that refused an exported port would be retracting the promise at
exactly the moment somebody was using it to resolve a collision by hand. So the
fragment records which ports it chose itself, and only those are ever questioned.

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

**That paragraph was right about the disk and wrong about the cost** (XIV-106).
Being small and on disk is not the interesting property of those databases. The
interesting one is that nothing ever drops them, so they outlive the branch that
migrated them.

That is fine until a branch adds a control migration and then renames or amends
it, which is what iterating on a migration looks like. `doctrine_migration_versions`
is then a record of a tree that no longer exists, in one of two shapes: a row
naming a class that is gone, which Doctrine reports as executed-but-unavailable
and carries on past; or no row for the new name, in which case Doctrine runs a
`CREATE TABLE` against a table the old name already created. The second one
throws a driver exception — `relation "…" already exists`, or `column "active" of
relation "operator" already exists` — out of the PHPUnit bootstrap, before a
single test has run, on a branch whose diff is fine. It names a table and never
mentions a migration, and it cost one run two manual `DROP DATABASE`s before
anybody worked out what they were looking at.

So `bin/ci` reclaims these too, in a step of its own, and both halves of that
sentence matter.

**Reclaimed by emptying rather than by dropping, and unconditionally.** Emptying
first: `DROP DATABASE` forces an immediate checkpoint, and on this on-disk server
that is about 1.7 seconds each — nine of them measured at 15.4s, which is a
sixth of the suite's running time added to every run for ever. `DROP SCHEMA
public CASCADE` is the same outcome, because `public` is where every table these
migrations create lives, `doctrine_migration_versions` included, and
`create --if-not-exists` is a no-op on the database left standing. Nine of those
measured at 1.7s through nine `docker compose exec`s and **0.5s through one
`psql` session** walking the list with `\connect`. The bootstrap then pays 613 ms
instead of 468 ms to migrate an empty database rather than find a ready one.

Unconditionally, because the cheap alternative is not enough. Comparing the
recorded versions against the files would catch a *renamed* migration — a
recorded version with no file — and would miss an *amended* one, which is the
other half of iterating: same version, different SQL, version still recorded,
migration a silent no-op, suite running against the schema the old SQL built.
That failure is quieter than the one this ticket is about, and a reclaim that has
to be reasoned about before it is trusted is not worth having.

**A step of its own, because the safety argument is not the same one.** The
tenant reclaim can *ask* the server whether it is disposable — `SHOW fsync` is
`off` only on `database-test`, and `compose.override.yaml` sets it there
precisely because everything on it is throwaway. On `database` that question has
no useful answer: the dev tenants and the dev control plane are on it, and it is
supposed to keep its promises. The safety has to come from names instead, and a
name is exactly what cannot be trusted here — `app_test` is a test database
today and would be a perfectly ordinary *dev* control plane in a checkout whose
`POSTGRES_DB` is `app_test`, since `compose.yaml` interpolates that straight into
`DATABASE_URL`. Both have the same seven tables, which was checked rather than
assumed.

What separates them is the running configuration, so that is what is asked: the
dev control plane is whatever `DATABASE_URL` in the *php container* names — not
`${POSTGRES_DB:-app}` read out here, for the reason the reclaim already asks that
container who its superuser is — and the pattern is that name plus the suffix
Doctrine appends under `when@test`. Deriving it rather than writing `app_test`
down makes the dev database structurally unable to match: `app` is not
`app_test<digits>`, and in the awkward checkout `app_test` is not
`app_test_test<digits>` either. That was verified by running it rather than by
reading it: the stack was brought up with `POSTGRES_DB=app_test`, the suite
created `app_test_test`, both databases had the same seven tables, and the
reclaim derived `^app_test_test[0-9]*$` and emptied only the second — dev
`app_test` still 7 tables, test `app_test_test` down to 0. The dev name is *also*
excluded by hand in every statement, which is belt for the derivation and braces
for whoever one day replaces it with a literal.

Three things it deliberately does not cover, all of them written down beside it
in `bin/ci`. Another checkout's databases — unlike the tenant names these carry
no `TEST_RUN`, because Doctrine's suffix is the worker and nothing else, so this
is safe only for as long as each checkout has its own `database` container, which
is the same caveat `compose.override.yaml` already records for `TEST_RUN`. Dev
tenants on that server, which nothing here matches. And a control plane left
under a base name the configuration has since changed away from, which nothing
knows about any more.

Moving these databases onto `database-test` is *still* not answered here, and it
is now less pressing rather than more: emptying costs half a second, so the move
would buy nothing but tidiness — while the thing it would actually change is the
safety argument above, replacing a derived name with `SHOW fsync`. Worth doing on
the day something else makes it necessary; not worth doing for this.

**And the failure is made legible for what the reclaim cannot anticipate.** A
bare `composer test`, or `bin/phpunit` from an editor, does not go through
`bin/ci` and can still meet a half-applied database. `tests/bootstrap.php` now
answers with the database name, the server it is on and why it is on that one,
the driver exception, the sentence *this is usually not a defect in your branch*,
and `bin/ci --reclaim` — which exists as a flag for no other reason than to give
that message something to name. A message that says what to type is worth the
flag; the alternative was a `psql` incantation nobody types correctly out of a
stack trace.

**One numbering space for migrations, and it is a decision rather than a
constraint** (XIV-107). On 2026-08-18 [XIV-92] and [XIV-95] both chose
`Version20260818140000` and it was caught by hand at merge. The cause is not
carelessness: migrations here are hand-written, so the version is a timestamp
somebody types, and people type `…140000` far more often than `…143327` — two
authors on one afternoon are choosing from a handful of round numbers rather than
from 86,400.

The control-plane and tenant sets are separate Doctrine configurations against
separate connections and separate databases, and Doctrine stores the version
fully qualified: `doctrine_migration_versions` holds
`DoctrineMigrations\ControlPlane\Version20260818140000`, namespace and all. So
the same digits in both sets is **not** a technical conflict and nothing would
break. That is precisely why it had to be decided — an answer that is not forced
is one two people answer differently.

The answer is that **a version is unique across the whole repository**, both
directories together, and it rests on three things. A version is quoted by its
digits and never by its namespace in every place a person actually meets one — in
conversation, in a branch name, at a `psql` prompt reading a half-applied
`doctrine_migration_versions` — so ambiguity costs most in exactly the situation
above and saves nothing anywhere. It costs nothing to obey, because timestamps to
the second are not scarce. And "unique" is a sentence, while "unique within its
own directory, and a duplicate across directories is fine because the namespace
disambiguates it" is a paragraph — a rule nobody can state from memory gets
applied by guess.

`tests/Unit/MigrationVersionsAreUniqueTest.php` enforces it, along with the class
name matching its file, which is Doctrine's own requirement arriving in
milliseconds instead of out of a bootstrap and is the obvious way to get a
renumbering half right. It is the check that fails in `bin/ci` rather than in
somebody's head at merge time; the same-path collision is a merge conflict git
surfaces eventually, and the cross-directory one is not.

`bin/new-migration <set> [description]` is the half that means nobody has to be
caught by it: it takes the version from the clock, to the second, checks it
against both sets, and writes the file. Not
`doctrine:migrations:generate` — Symfony's own answer, and the right one if there
were one set of migrations. There are two, it would have to be handed
`--configuration=config/migrations/tenant.php` for the other, and in neither case
would it look at the set it was not pointed at, which is the whole check the
decision above needs. It also wants the stack up and a booted kernel to write an
empty file, where this is a `date` and a heredoc and works with every container
stopped.

One detail in it is a consequence of the habit it ends. Typed timestamps are
rounded *up*: this repository's migrations are stamped 14:00 and 15:00 on days
whose work happened in the morning. Ask the clock at 06:14 and the version sorts
*before* migrations that already exist, and Doctrine — which runs everything
unexecuted in version order — then runs the new one first, against a schema that
does not have what the earlier-numbered migration added. So the version is the
later of the clock and one second past the newest version in the tree. On a
repository whose versions are honest that line does nothing; here it holds the
ordering true while the typed ones age out.

**What none of this catches**, and it is worth being explicit because it is the
quieter of the two shapes: two branches adding a column to the same table with
*different* timestamps. Those merge cleanly and both run. There is no honest
static check for it — the question is what the SQL means, not what the files are
called — and the outcome is caught downstream by
`SchemaMatchesTheMappingTest` and by `tenant:schema:validate`, which compare the
schema that resulted against the mapping.

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
- **Gravatar**, which is nearly free and was refused. The documentation promises a
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

**Migrations write identity columns, never `SERIAL`** (XIV-97). The first
migration in this repository was Doctrine-generated and used `GENERATED BY
DEFAULT AS IDENTITY`. Every hand-written one after it reached for `SERIAL`,
because that is what a person types from memory — eleven tables across the two
databases, three of them on a single day. Nothing broke: the two behave
near-identically and no query here can tell them apart.

What it cost was an instrument. `doctrine:schema:validate --em=control` had
reported the schema out of sync every day for months, and the entire difference
was those columns — so the one check that would have said *somebody added a
property to an entity and forgot the migration* had become a line nobody read.
Same shape as the argument §5.3 makes about a picker that truncates silently and
§8.11 about a failed collection drawn as a zero: **a signal that is always on
carries no information.** The repair is therefore an instrument repair and
changes no behaviour, and the part that stops it recurring — a source-level check
over new migrations, and a run of `schema:validate` in the suite — is worth more
than the columns.

*The dangerous part is the sequence.* `ALTER TABLE … ADD GENERATED BY DEFAULT AS
IDENTITY` builds a *new* sequence starting at 1, so the obvious conversion hands
out `id = 1` on the next insert into a table that has rows. The migrations carry
the position across as `GREATEST(what the old sequence would issue next, max(id)
+ 1)` — the sequence being authoritative because deletes and rolled-back inserts
leave it ahead, `max(id)` being the guard against rows loaded from a dump with
their ids supplied. They also drop the old sequence *between* removing the
default and adding the identity, because `DROP DEFAULT` leaves it `OWNED BY` the
column: skip that and the database keeps two sequences for one column, the new
one is named `<table>_id_seq1`, and `pg_get_serial_sequence()` answers with the
orphan.

**A tenant schema can be validated at all, and it is not the same question**
(XIV-97). `doctrine:schema:validate --em=tenant` cannot work — the DSN comes from
a resolved tenant and a console command has none (§7.4) — so until this ticket
nobody had ever asked whether a *customer's* database matched the mapping.
`tenant:schema:validate` asks it, entering each customer through
`TenantSwitcher::runFor()` and running Doctrine's own validator inside.

Two things came out of building it. The first is that **a tenant database can
never be "in sync" in the unrestricted sense, and that is the design rather than
a defect**: records are not entities (§5), so a customer's record tables, their
history and their collections are built by `ModuleInstaller` from that customer's
own metadata and Doctrine proposes dropping every one of them. The comparison is
narrowed to the mapped tables with DBAL's `setSchemaAssetsFilter`, scoped to the
command — as connection-wide configuration it would tell `ModuleInstaller` that a
table it is about to create already does not exist.

The second is that the tenant side carried more than `SERIAL`. With that half
converted, a freshly provisioned customer still differs from the mapping in
thirteen ways: index names the entities never declared, partial unique indexes
the mapping cannot express at all, two column defaults left by backfills, and
`shape_definition.parent_id`, where `CollectionDefinition` declares
`JoinColumn(nullable: false)` while the column is nullable because a
`ModuleDefinition` row has no parent — a mapping that `doctrine:schema:create`
would turn into a database nothing could be inserted into. Each of those wants a
decision rather than a conversion, so they are left as a ticket of their own and
the suite asserts the property that *is* true out there — that no id column
anywhere draws from a `nextval()` default — rather than a green nobody has
earned.

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

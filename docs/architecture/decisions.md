### 9.2 Decided since this brief was written

Decisions that belong to no single section. Each entry is the rule and the one
reason that keeps it from being relitigated. The full stories are on the
tickets.

**The runtime is classic PHP, not a worker.** A long-lived kernel serving every
tenant would keep state across request boundaries, which is §7.4's hazard at
full strength. Booting per request removes it by construction and costs a few
milliseconds. Worker mode is a performance flag to revisit once tenant-scoped
caching is a discipline, not before. It is also why the system has no queue and
no message consumer.

**Per-tenant database roles.** Each tenant database has its own Postgres role
and revokes all rights from `PUBLIC`. The database enforces §4's isolation
instead of the application being careful. A wrong DSN fails to connect; it does
not read another customer's data.

**Tenant credentials are encrypted at rest**, separately from the DSN. Each
stored value names the key that encrypted it, so a rotation can stop halfway
and resume. This protects dumps and replicas of the control plane. A
compromised application process is the per-tenant roles' problem, not this
mechanism's.

**A module and a child collection are the same kind of thing** (§5.1). More
than one module wants children, and an address is not a module in disguise.
Nothing can reach it except the contact that owns it.

**Build order was deliberate.** Collections came before the query layer, so
the compiler's central abstraction, what counts as a filterable thing, met a
to-many relation while it was still soft. Then history, then the query layer.
Events arrived without §7.1 being answered, and rightly: history only observes,
so the passive half of §6 was never blocked by the veto question.

**Permissions are grants, and record-level access is a WHERE clause** (§8.4). A
voter is handed one subject. A list is a page plus a total counted by a second
query, and a voter cannot reach either.

**A checkout is the unit of isolation for the test stack** (XIV-51, XIV-55,
XIV-71, XIV-86). `bin/lib/stack-env.sh` derives the compose project, the
published ports, the bind mount, the tenant-database prefix and the dev image
name from the directory. `bin/ci` and the `bin/compose` wrapper both source it,
so the two readers cannot disagree. A git worktree is a first-class checkout
with its own stack, databases and image. The rules that keep this true:

- **Always go through `bin/compose`.** A bare `docker compose` in a worktree
  guesses the project name under different sanitising rules and gets none of
  the derived environment. It lands on the main checkout's stack, or on a third
  one belonging to nobody.
- **An explicit export always wins**, past the derivation and past every guard.
  The fragment only ever questions ports it chose itself.
- **Port collisions are refused, never stepped past.** `xivi_assert_ports_free`
  names the checkout holding the port and prints the exports that move this one
  somewhere free. Stepping to the next free port would make a checkout's URL
  depend on what else ran that morning, and a bookmarkable address is the one
  thing the derived offset buys. The refusal also covers the quiet failure,
  which is worse than the loud one: a collided `DATABASE_PORT` *answers*, with
  the neighbouring checkout's healthy Postgres behind it.
- **One image per checkout costs ~29 kB**, measured, because unchanged layers
  are shared. Cleanup is manual on purpose. `bin/compose` with no arguments
  prints the image name; remove the image before the worktree, because after
  `git worktree remove` nothing can derive the name any more.

**`bin/ci` reconciles its inputs instead of assuming them** (XIV-63,
`bin/reconcile`, also run by the container entrypoint). A warm stack otherwise
believes things about a tree it has not read. Stale vendor/ is the quiet case,
and `composer install` is the answer precisely because it removes. A compiled
container from an older `security.yaml` needs a boot, not a clear. PHPStan's
result cache tracks the container XML but not the installed packages, so a hash
written beside the run tells it when they changed. Reconciling fixes rather
than refuses, and a warm correct run costs about a second. `bin/ci` prints that
second every run, so the claim stays checkable.

**`bin/ci` reclaims the test databases before the suite starts** (XIV-78,
XIV-106). Two mechanisms, because the safety arguments differ:

- **Tenant test databases** (the tmpfs server). Leftovers saturate at classes ×
  workers, eight runs' worth, so `bin/ci` drops every database and role
  matching this checkout's test prefix at the start of a run. Start rather than
  end, because that also covers a killed run that had no teardown. It
  terminates sessions first and drops `WITH (FORCE)`; a stray Panther server
  holding one connection otherwise fails every reclaim with `55006`. Before
  dropping anything it asserts `SHOW fsync` is `off`, identifying the
  throwaway server by its configuration rather than trusting a name. One `df`
  after reclaiming refuses above 80%, because a full tmpfs presents as a
  hundred connection failures, not as a disk.
- **Control-plane test databases** (`app_test<worker>`, on the persistent
  `database` server). Reclaimed by **emptying the schema, not dropping**, since
  `DROP DATABASE` forces a checkpoint at ~1.7 s each while emptying nine costs
  0.5 s in one `psql` session. And **unconditionally**, because comparing
  recorded migration versions against files catches a renamed migration and
  misses an amended one. The name pattern derives from the php container's own
  `DATABASE_URL` plus Doctrine's `when@test` suffix, so the dev control plane
  cannot match by construction; the dev name is excluded by hand as well. Not
  covered, and recorded beside the code: another checkout's databases (safe
  only while each checkout has its own `database` container), dev tenants, and
  a control plane left under a renamed base.

`bin/ci --reclaim` exists so the bootstrap's failure message has something to
name. A bare `bin/phpunit` that meets a half-applied database is told the
database, the server, that this is usually not a defect in your branch, and
what to type.

**A migration version is unique across the whole repository**, both sets
together (XIV-107). Doctrine stores versions fully qualified, so a duplicate
across `migrations/control` and `migrations/tenant` breaks nothing. That is
exactly why it had to be decided. People quote versions by their digits alone,
at a `psql` prompt or in a branch name, and a rule nobody can state from memory
gets applied by guess. `MigrationVersionsAreUniqueTest` enforces it, along with
the class name matching the file name. **Use
`bin/new-migration <set> [description]`** rather than typing a timestamp. It
takes the version from the clock, checks both sets, and clamps to one second
past the newest version in the tree; typed timestamps get rounded up to future
times, and an honest clock would otherwise sort a new migration before existing
ones. What none of this catches is two branches touching the same table under
different timestamps. `SchemaMatchesTheMappingTest` and
`tenant:schema:validate` catch that downstream.

**A mail catcher is visibility, and only that** (XIV-41). Mailpit accepts
everything and delivers nothing. Its UI sits on the loopback with a
per-checkout port; SMTP is unpublished. It is **not** the guarantee that
nothing escapes, because a DSN naming a real server is believed; the guarantee
is XIV-37's transport guard. The suite never reads from the catcher. Eight
workers against one inbox would be one mutable shared thing again, so tests
assert through Symfony's message logger with `null://null`.

**Tests are isolated by a transaction, one tenant database per test class.**
Each test rolls back; provisioning stays outside the transaction because
`CREATE DATABASE` cannot run inside one, so the class's database is made once
and the next run reclaims it. The part specific to database-per-tenant: DAMA
keys its static connection per *configured* connection, and one configured
connection serves every tenant here, so every test would have shared whichever
tenant's connection opened first. A test-only middleware between DAMA and
`TenantDriver` keys by the resolved database name instead. The cross-tenant
tests in `tests/Functional/Engine` are the canary. Remove the middleware and
they fail, rather than quietly agreeing. Tests that provision and drop their
own tenants carry `#[SkipDatabaseRollback]`.

**An avatar is generated here, never fetched** (XIV-77). Initials in a circle,
on a hue derived from the email address. Refusing Gravatar was a privacy
decision, not a styling one: it would send every signed-in user's email hash to
a third party on every page load, against the same no-CDN promise
`assets/app.js` makes about scripts. Wanting it means opting in and arguing it
here first. An uploaded picture waits on the attachments question (§9.3); the
seam is `App\Twig\Avatar`.

**Migrations write identity columns, never `SERIAL`** (XIV-97). Not because the
two behave differently. The drift had silenced an instrument:
`doctrine:schema:validate` reported out-of-sync for months, the entire
difference was those columns, and a signal that is always on carries no
information. When converting an existing column, carry the sequence position
across as `GREATEST(next value, max(id) + 1)`, and drop the old sequence
*between* removing the default and adding the identity. Skip that and the
database keeps an orphan sequence, and `pg_get_serial_sequence()` answers with
it.

**`tenant:schema:validate` exists, and a tenant database can never be fully "in
sync" by design** (XIV-97). Records are not entities, so a customer's record,
history and collection tables come from their own metadata, and Doctrine
proposes dropping every one of them. The command narrows the comparison to the
mapped tables with a schema-assets filter scoped to itself; applied
connection-wide, the filter would tell `ModuleInstaller` that a table it is
about to create does not exist. The mapped differences that remain each want a
decision and keep their own ticket: undeclared index names, partial unique
indexes the mapping cannot express, backfill defaults, and the nullable
`parent_id` that `CollectionDefinition` declares non-null. The suite asserts
the property that is true out there instead. No id column anywhere draws from a
`nextval()` default.

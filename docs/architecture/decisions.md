### 9.2 Decided since this brief was written

Decisions that belong to no single section. Each entry is the rule and the one
reason that keeps it from being relitigated; the full stories are on the tickets.

**The runtime is classic PHP, not a worker.** One long-lived kernel serving every
tenant is the §7.4 hazard in its most dangerous form: state that survives a
request boundary. Booting per request removes it structurally, for a few
milliseconds a request. Worker mode is a performance flag to revisit once
tenant-scoped caching is a discipline, not before. This is also why there is no
queue and no message consumer anywhere in the system.

**Per-tenant database roles.** Each tenant database has its own Postgres role and
revokes all rights from `PUBLIC`. §4's isolation is enforced by the database
rather than by the application being careful: a wrong DSN fails to connect
instead of reading another customer's data.

**Tenant credentials are encrypted at rest**, separately from the DSN, under keys
that stored values name individually so rotation is resumable. This protects
dumps and replicas of the control plane; a compromised application process is the
per-tenant roles' problem.

**A module and a child collection are the same kind of thing** (§5.1). More than
one module wants children, and an address is not a module in disguise: nothing
can reach it except the contact that owns it.

**Build order was deliberate**: collections before the query layer (so the
compiler's central abstraction, "what counts as a filterable thing", met a
to-many relation while still soft), then history, then the query layer. Events
arrived without §7.1 being answered, because history only observes; the passive
half of §6 was never blocked by the veto question.

**Permissions are grants, and record-level access is a WHERE clause** (§8.4). A
voter is handed one subject; a list is a page plus a total counted by a second
query.

**A checkout is the unit of isolation for the test stack** (XIV-51, XIV-55,
XIV-71, XIV-86). The compose project, published ports, bind mount, tenant-database
prefix and the dev image name are all derived from the directory, in
`bin/lib/stack-env.sh`, sourced by both `bin/ci` and the `bin/compose` wrapper so
the two readers cannot disagree. A git worktree is a first-class checkout: its
own stack, its own databases, its own image. The rules that keep it true:

- **Always go through `bin/compose`.** A bare `docker compose` in a worktree
  guesses the project name with different sanitising rules and none of the
  derived environment, and lands on the main checkout's stack or a third one
  belonging to nobody.
- **An explicit export always wins**, past the derivation and past every guard;
  the fragment only questions ports it chose itself.
- **Port collisions are detected and refused, never stepped past**
  (`xivi_assert_ports_free`): stepping would make a checkout's URL depend on
  what else was running that morning, which is the one property the derived
  offset buys. The refusal names the holding checkout and prints the exports
  that move this one somewhere free. The quiet hazard it prevents: a collided
  `DATABASE_PORT` *answers*, with the neighbouring checkout's healthy Postgres.
- **One image per checkout costs ~29 kB**, measured: unchanged layers are
  shared. Cleanup is deliberately manual (`bin/compose` with no arguments prints
  the image name; remove it before removing the worktree), because after
  `git worktree remove` nothing can derive the name any more.

**`bin/ci` reconciles its inputs instead of assuming them** (XIV-63,
`bin/reconcile`, also run by the container entrypoint). A warm stack otherwise
believes things about a tree it has not read: stale vendor/ (removal is the quiet
direction; `composer install` is the answer because it removes), a compiled
container from an older `security.yaml` (needs a boot, not a clear), and
PHPStan's result cache, which tracks the container XML but not the installed
packages and is told via a hash written beside the run. Reconciling fixes rather
than refuses; a warm correct run costs about a second, printed every run so the
claim stays checkable.

**`bin/ci` reclaims the test databases before the suite starts** (XIV-78,
XIV-106). Two mechanisms, because the safety arguments differ:

- **Tenant test databases** (tmpfs server): leftovers saturate at classes ×
  workers, eight runs' worth, so every database and role matching this
  checkout's test prefix is dropped at the start of a run, which also covers a
  killed run that had no teardown. Sessions are terminated first and the drop is
  `WITH (FORCE)`, because a stray Panther server holding a connection otherwise
  fails every reclaim with `55006`. The reclaim asserts `SHOW fsync` is `off`
  before dropping anything: the throwaway server is identified by its
  configuration, not by its name. One `df` after reclaiming refuses above 80%,
  because a full tmpfs presents as a hundred connection failures, not as a disk.
- **Control-plane test databases** (`app_test<worker>`, on the persistent
  `database` server): reclaimed by **emptying the schema, not dropping**
  (`DROP DATABASE` forces a checkpoint, ~1.7 s each; emptying nine costs 0.5 s
  through one `psql` session), and **unconditionally**, because comparing
  recorded migration versions against files catches a renamed migration and
  misses an amended one. The pattern is derived from the php container's own
  `DATABASE_URL` plus Doctrine's `when@test` suffix, so the dev control plane is
  structurally unable to match; the dev name is excluded by hand as well.
  Not covered, recorded beside the code: another checkout's databases (safe only
  while each checkout has its own `database` container), dev tenants, and a
  control plane left under a renamed base.

`bin/ci --reclaim` exists so the bootstrap's failure message has something to
name: a bare `bin/phpunit` meeting a half-applied database is told the database,
the server, *this is usually not a defect in your branch*, and what to type.

**A migration version is unique across the whole repository**, both sets
together (XIV-107). Doctrine stores versions fully qualified, so a duplicate
across `migrations/control` and `migrations/tenant` breaks nothing, which is
exactly why it had to be decided: people quote versions by digits alone, at a
`psql` prompt or in a branch name, and a rule nobody can state from memory gets
applied by guess. `MigrationVersionsAreUniqueTest` enforces it (plus class name
matching file name). **Use `bin/new-migration <set> [description]`** rather than
typing a timestamp: it takes the version from the clock, checks both sets, and
clamps to one second past the newest version in the tree, because a version typed
as a round future time otherwise makes an honest clock sort a new migration
before existing ones. What none of this catches: two branches touching the same
table under different timestamps; that lands downstream with
`SchemaMatchesTheMappingTest` and `tenant:schema:validate`.

**A mail catcher is visibility, and only that** (XIV-41). Mailpit accepts
everything and delivers nothing; its UI is on the loopback with a per-checkout
port, SMTP unpublished. It is **not** the guarantee that nothing escapes: a DSN
naming a real server is believed. The guarantee is XIV-37's transport guard. The
suite never reads from the catcher (eight workers, one inbox, one mutable shared
thing): tests assert through Symfony's message logger with `null://null`.

**Tests are isolated by a transaction, one tenant database per test class.**
Rolled back after each test; provisioning stays outside the transaction because
`CREATE DATABASE` cannot run inside one, so the class's database is made once
and reclaimed by the next run. The part specific to database-per-tenant: DAMA
keys its static connection per *configured* connection, and one configured
connection serves every tenant here, so a test-only middleware between DAMA and
`TenantDriver` keys by the resolved database name instead. The cross-tenant
tests in `tests/Functional/Engine` are the canary: remove the middleware and
they fail rather than quietly agreeing. Tests that provision and drop their own
tenants carry `#[SkipDatabaseRollback]`.

**An avatar is generated here, never fetched** (XIV-77). Initials in a circle on
a hue derived from the email address. Gravatar was refused as a privacy decision:
it would send every signed-in user's email hash to a third party on every page
load, against the same promise `assets/app.js` makes about scripts; wanting it
means opting in and arguing it here first. An uploaded picture waits on the
attachments question (§9.3); the seam is `App\Twig\Avatar`.

**Migrations write identity columns, never `SERIAL`** (XIV-97). Not because they
behave differently, but because the drift had silenced an instrument:
`doctrine:schema:validate` had reported out-of-sync for months and the entire
difference was those columns, and a signal that is always on carries no
information. When converting an existing column: carry the sequence position
across as `GREATEST(next value, max(id) + 1)`, and drop the old sequence
*between* removing the default and adding the identity, or the database keeps an
orphan sequence that `pg_get_serial_sequence()` still answers with.

**`tenant:schema:validate` exists, and a tenant database can never be fully "in
sync" by design** (XIV-97). Records are not entities, so a customer's record,
history and collection tables are built from their own metadata and Doctrine
would propose dropping every one; the comparison is narrowed to the mapped
tables with a schema-assets filter **scoped to the command** (connection-wide it
would tell `ModuleInstaller` a table it is about to create does not exist). The
residual mapped differences (undeclared index names, partial unique indexes the
mapping cannot express, backfill defaults, the nullable `parent_id` that
`CollectionDefinition` declares non-null) each want a decision and are left as
their own ticket; the suite asserts the property that is true instead: no id
column anywhere draws from a `nextval()` default.

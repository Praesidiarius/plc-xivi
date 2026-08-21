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

**A tenant with a DAMA-cached connection may not be deprovisioned, and the
suite now refuses rather than survives it** (XIV-148). Deprovisioning
terminates every session on the database (§4.1), and a terminated connection
in DAMA's cache does not fail where it was caused: DAMA rolls back and reopens
its transactions across every cached connection around every test, so one dead
connection surfaces as "terminating connection due to administrator command"
in whichever tests run next: as runner warnings a green run hides, or as a
cascade of errors. That is what a serial run met: the "provisioned once per
process" set behind `SharesATenant`'s reuse guard was a static *on the trait*,
PHP copies trait statics into every using class, and the six browser classes
sharing the `e2e` slug each got an empty copy and deprovisioned the live
tenant in turn. Three things came out of it. The bookkeeping is a class
(`ProvisionedSlugs`), because a class static is the process-wide slot the
sentence needed. The leftover-cleanup path checks a ledger of databases DAMA
has cached (kept by the connection-key middleware, which sees exactly what
DAMA sees) and throws in the offending test instead of poisoning the ones
after it. And `failOnPhpunitWarning` is on: the warnings this defect surfaced
as are invisible to `failOnWarning`, and a suite whose isolation has silently
stopped must be red. No extra serial leg was added to the gate, because the
serial path is already exercised on every CI run: the coverage leg runs
PHPUnit serially, paratest being unable to do coverage under PHPUnit 13. A
local serial leg would re-check what CI already checks at the cost of the
difference between the parallel and serial runs (measured 67 s against
9 min 16 s on a workstation carrying several stacks; CI's own gap is ~10 s
against ~48 s), and the `SharedSlugReuse*Test` pair fails the serial run
deterministically if the guard regresses.

**Coverage is measured with PCOV, and Xdebug is switched off while it is**
(XIV-170). The dev image carried one coverage driver and the coverage run used
it, which is inheritance rather than a choice. With both installed the same
1955 tests take 328 s against 687 s, and the number the floor gates on does not
move: 87.92% against 87.91%, the whole disagreement being two `match` default
arms. Getting there meant separating two things that look like one.
php-code-coverage takes PCOV for line coverage by itself and only falls back to
Xdebug for branch and path granularity, so the driver was never the hard half;
an Xdebug still in `develop` or `coverage` mode goes on doing its own work
underneath whoever is collecting, and switching it off is what buys the time
(28 s, 40 s and 44 s for the same directory at `off`, `develop` and
`coverage`). What is given up is branch coverage, which PCOV cannot do and
`bin/coverage-gate` never asked for. Xdebug stays in the image: it is the only
thing a debugger can attach to, and the two coexist.

**Languages are covered by field type, not by test count** (XIV-45). XIV-44 was
a Critical bug that four hundred and eighty tests walked past, the browser layer
included, because the whole suite spoke English, and in English a number's
displayed form and its stored form are the same string. The bug class is narrow
and nameable: a value crossing between what the model stores and what the reader
sees. A field type owns its storage, its form type and its display, so that
crossing is a property of fourteen classes rather than of four hundred tests.
Making the suite German would have moved the blind spot rather than closed it,
since English has failure modes German does not. Two additions, and no third:

- **A round trip per field type per formatting locale**
  (`FieldTypeRoundTripTest`). Write a value down, build the form, take what a
  browser would post back, submit exactly that, and compare what would be
  stored. Over `FieldTypeRegistry::all()`, so the next type is covered without
  an edit, and it fails by name if that type asks a question the harness cannot
  answer. A module's own type is in the registry too, so `voucher_code` is
  covered by the same sweep without core knowing the module exists. The run also
  counts the fields whose displayed form really differed from their stored one
  and refuses to be green without them, because a round trip through the
  identity function survives every time. A planted violation kept in the file
  makes XIV-44's mistake on purpose, and is green in English and red in the
  first locale writing a decimal comma, which is this ticket's whole argument in
  two assertions.
- **One browser test in every enabled language**
  (`FiguresInEveryLanguageTest`), one test rather than the browser suite run
  four times. The browser is the only layer exercising the typing, the
  re-render and the server's arithmetic at once, and it is the layer XIV-44
  walked through. §8.3's "deliberately few" is kept by making the addition one
  page load per language, in a class of its own because that file is about the
  three things only a browser can see and none of them is language.

**The locale set is derived, never listed.** The languages are
`enabled_locales`, which is already the promise that a language in the picker is
served whole, and each is expanded to one locale per distinct way of writing a
number, an amount of money and a short date across every country ICU knows
(`FormattingLocales`). Two locales agreeing on those three cannot round-trip
differently, so thirty locales cover all 249 countries. That is how `fr_CH`
gets tested writing plain numbers with a comma and money with a point, which
four bare languages would have missed, and how `en_IN` grouping in lakhs gets
tested without anybody having thought of it. **A fifth language costs its own
five or six formatting locales and one browser page load**, not a second copy of
anything.

**Rejected: the whole suite twice**, which scales by multiplication and makes
the third language a decision somebody regrets; **and a locale dimension on the
tests that touch formatted values**, which needs exactly the knowledge that
failed the first time.

**What none of it covers, recorded rather than discovered later**: anything
locale-dependent that is not a field type. A figure formatted in a Twig template
or a PDF, a sort order where `ae` and `a` collate differently, an ICU plural
whose harder forms English cannot exercise, and a type that stops localizing
altogether, which round-trips perfectly because a value shown as it is stored
comes back as it was shown. `SwissFiguresTest` pins the separators themselves.

**A browser test gives its session back** (XIV-45,
`App\Tests\Support\ReleasesTheBrowser`). Panther consults its client cache only
for `chrome` and `firefox`, so a suite talking to a grid gets a new session per
test method, and nothing closes it: the extension resets its own list,
`tearDownAfterClass()` is disabled while that extension is loaded, and the one
client quit at the end is the last one created. Every browser test therefore
held one of the node's four slots until the run ended, and from the fifth test
onwards every test waited on the 300-second idle reaper, which is what the
browser leg's running time mostly was and why a test occasionally gave up at the
180-second session-request timeout instead. Writing the seventeenth test is what
found it. Quitting in `tearDown()` took the leg from about five minutes to
forty seconds and made four slots generous again.

**The prevention is a rule in §8.3, not a new class.** XIV-44's root cause was
code reading view values where model values were meant, and the seam where that
happened has one caller, so extracting a named accessor for it would be naming a
thing to make it look guarded. The rule is written where the Live Components
decisions are, and the suite is what holds anybody to it.

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

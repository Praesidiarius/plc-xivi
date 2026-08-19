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

## 4. Deployment topology: single instance, database per tenant

One deployed codebase serving all customers. Tenancy resolves per request from
the `Host` header (`customer1.1plc.ch`).

**Explicitly rejected: per-domain `.env` files.** That reintroduces v1's
configuration drift, N config files on disk that nobody audits.

**Instead: a control-plane database.** One row per tenant holds domains,
database DSN, enabled modules, plan and status, and provisioning metadata.
Provisioning is a console command, not a filesystem ritual. The control plane
also keeps decisions that are the same for everybody, such as module states
(§6.2); one row per tenant is what it started as, not what it is.

**Database per tenant, not shared tables with `tenant_id`:**

1. **Isolation is physical.** A forgotten `WHERE tenant_id = ?` becomes a bug,
   not a cross-customer data leak. Relevant for CH/EU customers.
2. **Backup, restore, and export-on-churn** are per-customer operations for
   free.
3. **Column promotion is inherently per-tenant** (§5): natural with a database
   each, incoherent in a shared table.

**Accepted costs, designed around rather than ignored**: blast radius (one bad
deploy affects everyone), noisy neighbours, no per-tenant version pinning, and
migrations that must expand and contract rather than destroy in one step
(§4.2).

**Escape hatch, free by construction**: a customer demanding a dedicated
instance gets the same codebase pointed at a registry with one row. A config
choice, never a fork.

### 4.1 Removing a tenant, and why `suspended` is not a prerequisite

`tenant:deprovision` exists and **ships** in the production image, because an
operator who cannot remove a customer from the console does it from `psql`
instead, getting wrong exactly the details the method exists to get right. The
sharpest of those: it resolves database and role **out of the stored DSN**,
never from the slug. The command is hard to run *by accident*, not hard to
run. The confirmation names the database, role, hostnames and record count and
defaults to no, and an unattended run is refused outright without `--force`;
`--no-interaction` alone is not consent, because Symfony answers an unanswered
question with its default.

**Rejected: requiring `TenantStatus::Suspended` first.** A speed bump the same
hand removes buys no second opinion. It would block the case the command is
most needed for, since a tenant stuck in `provisioning` cannot be suspended.
And its busiest caller, `tenant:reset`, would satisfy it mechanically until it
meant nothing. What is kept is the information, delivered where the decision is
made: the confirmation says when the tenant still serves requests. Suspending
first is good practice for a real customer removal, and the command says so
rather than pretending to enforce it.

**The order is database, role, registry row** (XIV-94). The two orderings do
not fail equally. Dropping first and failing leaves a row pointing at nothing,
which every tool can show and a re-run repairs, both drops being `IF EXISTS`.
Removing the row first leaves an orphan database that only `psql` and somebody's
memory can find.

**A live tenant is disconnected as a named step**, `pg_terminate_backend` over
`pg_stat_activity`, with the drop still carrying `WITH (FORCE)`. The statement
handles the sessions that exist; the keyword handles the client that reconnects
in between and *waits* out the window in which a terminated backend is still
listed (XIV-142). Throwing a customer's users out mid-request is the correct
and deliberate consequence of not requiring `suspended`.

**Provisioning credentials short of superuser need more than
`CREATEDB CREATEROLE`**, measured on Postgres 18. Terminating another role's
backend needs `pg_signal_backend`, and since Postgres 16,
`CREATE DATABASE … OWNER` and `DROP DATABASE` additionally need
`GRANT … WITH SET TRUE, INHERIT TRUE` on the tenant roles. Dev and test run as
the cluster superuser and never meet this; a narrowed production role is an
open design question for the provisioning half.

**`tenant:reset`** (deprovision, provision, install, demo records) is
registered only in `dev` and `test`. Not because it is destructive, but because
it is *meaningless* where records are real; the demo commands are excluded for
the opposite reason, since generating fiction into a customer's database is
dangerous. It resolves module order from the blueprints' own `requires` and
makes every refusal it can **before** touching the existing tenant.

**Rejected: building the replacement under a temporary slug and swapping.** A
tenant's identity is five things (slug, hostnames, database, role, encrypted
DSN), so a swap is five non-transactional operations *after* the destruction.
It narrows the die-mid-run window without closing it, and what it leaves behind
is strictly harder to clear than "the tenant is gone, run it again". So the
destruction stays first and the command owes precision instead: what is gone,
what stands (read back from the control plane, not inferred), and the command
line that starts over (`ResetProgress`).

**One process, three accumulators.** Folding six commands into one reset left
Doctrine's profiler query log and Monolog's debug processor filling for the
whole run. The reset empties both at every seam and after every batch, so the
cost follows batch size rather than `--records`. The profiler's stopwatch is
deliberately left growing; `ResetTenantCommand::forgetQueries()` carries the
measurements and the why.

### 4.2 What a deploy has to do, and where each part of it runs (XIV-61)

The deploy *definition* (tool, host, registry, rollback) is still open on
XIV-61. What is built is the part that is true whichever tool wins.

**`bin/deploy` is the one-shot step, run once per release, out of the image
being released, before the serving containers are replaced.** In order: the
secret check, the control-plane migrations, `deploy:check-grants` (§4.4),
`deploy:check-hosts` (§4.3), and finally `tenant:migrate` across the whole
registry. The checks sit after the migration because they read the schema it
just moved, and before the container swap because that is what makes a non-zero
exit cheap rather than an outage.

- **Not in the entrypoint**, which runs on every container start. The tenant
  loop is work proportional to the customer count, in the startup path, and two
  containers starting together would both begin applying the same plan. The
  entrypoint keeps its control-plane migration, one idempotent database, so a
  container can never serve against a control-plane schema older than itself.
- **A file in the repository, not a runbook.** It ships inside the image it
  deploys, so the sequence is the one this release was written against, and
  the ordering (control plane before tenant loop) is a property rather than a
  convention.

**The migration window is additive only, and the instance stays up.** N tenant
databases do not migrate atomically, and the same code serves customers on
both schemas for the duration. Downtime was rejected because its length is a
function of the customer count, so every sale makes it worse, and that
pressure ends in skipped windows. Forbidden in a tenant migration's `up()`:
`DROP TABLE`, `DROP COLUMN`, `RENAME TO` and `RENAME COLUMN`, and
`SET NOT NULL` on an existing column. Expand in this release, contract in a
later one. `down()` is exempt, because going backwards is deliberate.
`tests/Unit/TenantMigrationsAreAdditiveTest.php` enforces it and is
deliberately blunt and incomplete. A narrowed type, a new `UNIQUE`, or a data
migration are destructive across the window and invisible to it; the rule is
the author's to apply.

**XIV-151 squashed the migrations to one baseline each on 2026-08-19, and that
door is shut.** The whole justification: nothing had been deployed anywhere, so
the walk-an-existing-installation job had never been done. From the first real
deployment onwards, squashing means `doctrine:migrations:version --add`
against live customer databases with no check that the schema matches, and it
is not worth what it saves. A migration written after that commit is history
and is kept. The baselines were written against a `pg_dump` of a fully
migrated database, because a mapping-generated baseline would have silently
dropped `btree_gist`, XIV-136's SQL functions, and the `EXCLUDE USING gist`
constraint. They were verified by dump diff, and they carry the per-column
reasoning and the one seeded row, `tenant_profile`'s singleton, which a schema
diff structurally cannot see; the suite is the real gate. Provisioning went
from ~592 ms to ~481 ms, and XIV-150 measures against that.

**"Migrated 49 of 50" is not success.** `tenant:migrate` catches per tenant
and carries on, because one unreachable database must not cost the other
forty-nine theirs, and it publishes three exit codes, the contract every other
deploy check borrows. **0** means all current. **1** means the run could not
happen and nothing changed. **3** means the run happened and at least one
tenant is behind; the failure names them and prints the `--slug` retry. Not 2,
which is Symfony's "you typed it wrong".

**A container refuses to start on a secret anybody can read.** `.env` is
committed with working values, and the production image compiles it into
`.env.local.php`, so a deployment that forgets to supply `APP_SECRET` or
`TENANT_SECRET_KEYS` runs healthily on published values.
`App\Deployment\PlaceholderSecretGuard` (`deploy:check-secrets`, run by the
entrypoint before anything else and by `bin/deploy`) refuses any secret whose
live value is byte-identical to the committed one. That is a rule, not a list
of bad strings, so editing `.env` moves the check with it. It stands down
outside `APP_ENV=prod`, because the placeholders are what lets a fresh
checkout start, and the environment decides rather than the debug flag. It is
deliberately not a compiler pass or a kernel boot check; those would refuse
the image *build*, which boots the prod kernel on the placeholders as a normal
step.

**For whoever writes the deploy definition**: it must call `bin/deploy` and
stop on non-zero, because nothing else runs the tenant migrations. And
rollback is constrained by the window decision. The schema this release
expanded stays expanded after the code goes back, which is exactly why
additive-only makes going back possible at all.

### 4.3 Which hostnames this installation answers to (XIV-93)

Tenancy already blocked most host-header mischief. `TenantRequestListener`
resolves the host through the registry before routing and 404s anything no
tenant claims. The residue is twofold.

**The control plane is not isolated by its hostname, and cannot be.** Its host
is by definition one this installation answers to, so it is inside any
trusted-host pattern, and a pattern cannot tell a request that arrived at the
right address from one that wrote the right string in a header. What isolates
it is §8.9's layers: route existence, the provider, the role, and since
XIV-124 an IP allow-list, which is the one thing a hostname cannot do. The
hostname buys obscurity and a DNS target, nothing more.

**The fixed half is what goes into generated links.** Absolute URLs, invitation
mails above all, take their host from the request. `default_uri` covers only
the console.

The rules:

- **A deployment names domains; the application writes the expressions.**
  `XIVI_TRUSTED_DOMAINS=xivi.app,1plc.ch` is a fact an operator knows.
  `App\Deployment\TrustedHosts` compiles each entry into an anchored pattern
  admitting the domain and everything under it, and refuses a non-hostname
  entry rather than compiling something that matches nothing. Hand-written
  regexes were rejected because both failure directions are one keystroke
  away, and the too-narrow one takes a paying customer dark with an empty 400.
- **The system hosts are added as exact literals, never asked for**, so an
  operator who remembers only the customer domain cannot lock themselves out
  of their own console.
- **Empty means no checking at all**, which keeps a fresh checkout, the suite
  and dev unchanged. System hosts alone are deliberately not turned into
  patterns; a non-empty list switches checking on and would refuse every
  tenant.
- **Trusted proxies stay empty by default.** FrankenPHP terminates TLS itself,
  so an `X-Forwarded-*` header arrives only from somebody who made it up. A
  deployment with a balancer sets `TRUSTED_PROXIES`. The header set is decided
  in the repository: `x-forwarded-for`, `-proto` and `-port` are trusted, and
  **`x-forwarded-host` and `-prefix` are not**, because tenant routing *is*
  the `Host` header and believing a forwarded one lets a caller pick the
  tenant.
- **A too-narrow pattern must be findable, not merely correct.** The 400 stays
  bare, since the refused party is by definition not somebody we serve. The
  diagnosis goes to the operator, in three places. `tenant:provision` refuses
  a hostname the pattern would refuse, which is the preventive one.
  `deploy:check-hosts` names every affected tenant; `bin/deploy` runs it and
  exit 3 stops the deploy, though a suspended tenant's hostname is printed and
  stops nothing, or the gate ends up run with `|| true`. And
  `UntrustedHostListener` writes one log line naming the host, the variable
  and the command, matching on facts rather than framework message text. The
  entrypoint also runs `deploy:check-hosts` and **ignores** its exit, because
  a bad pattern is one customer's outage, and refusing to start would take
  every other customer dark to protect them.
- **Nothing is derived from the registry at runtime.** A boot-time pattern
  would darken customers provisioned since the last restart. Caddy's
  `SERVER_NAME` is complementary and is the web server's concern. There is no
  `--fix`, because a running instance that could widen its own pattern could
  be made to admit anything.

### 4.4 Two images: what a customer's instance is built without (XIV-96)

§3.1 established that the control plane cannot be separated, since every
request starts in it, and that the *administration surface* can. This is the
deployment half: a customer-facing build that does not **contain** the
surface, rather than an instance that does not route it.

| | customer-facing | internal |
| --- | --- | --- |
| Image | `frankenphp_public` | `frankenphp_prod` |
| Contains `packages/control-plane` | no | yes |
| Served on | every customer hostname | `CONTROL_PLANE_HOST`, `SIGNUP_HOST` |
| Firewalls | `dev`, `main` | plus `control_plane`, `signup` |
| Control-plane database | **`SELECT` on registry tables only** | full |
| Owns the schema | no; refuses to start until it is current | yes |

**Two build targets, not one image with routes switched off.** "Not routed"
and "not present" are different guarantees, and only the second survives
somebody's mistake. XIV-56 is the precedent: `.env.dev` shipped in the image
for weeks because an exclusion list needed a line it did not get. A separate
repository was rejected: real drift, a schema owned by two histories, and
deptrac already enforces the boundary.

**The security configuration was the real obstacle.** Everything the package
can declare, the package declares, prepended from `XiviControlPlaneBundle`.
Two things cannot move, by Symfony's rules. `security.firewalls` is
`disallowNewKeysInSubsequentConfigs()`, so the application names all firewalls
in `config/packages/security_firewalls.php` and splices the package's two in
behind a presence check. `access_control` cannot be overwritten, so the two
`^/control` rules stay in `security.yaml`, two path patterns and a role name,
harmless in the image that has no such routes. Three seams ask "is the class
in this build": `config/bundles.php`, `security_firewalls.php`, and
`config/routes/signup.php`. `ControlPlaneIsOptionalAtBuildTimeTest` holds the
list, and any other configuration file naming the namespace outside a comment
fails the build.

**The bundle seam lives in `App\Kernel`, not in `config/bundles.php`**
(XIV-111). Flex regenerates `bundles.php` from its own template, and an
auto-recipe silently removed a hand-written conditional there. `bundles.php`
is now exactly the file Flex would write, and `App\Kernel` skips bundles from
the explicit list in `config/optional_bundles.php`. Explicit, because a bundle
absent from the image looks exactly like an unfinished `composer install`, so
anything not on the list still fatals. A Flex rewrite thereby stopped being a
hazard instead of becoming an alarm. Still a Flex hazard, accepted and
recorded in AGENTS.md: `importmap.php` and `assets/controllers.json` are
generated and hand-edited.

**`app.control_plane_host` stays in the application.** It feeds
`app.system_hosts`, which is what makes a control-plane request resolve no
tenant (§8.9), and the trusted-host pattern (§4.3), both the application's. A
public deployment sets it empty, and an empty entry matches nothing.

**The strongest isolation is the grant, not the network.** Both instances
share one control-plane database, so the boundary is **two database users**.
The customer-facing role holds `SELECT` on the registry tables and nothing
else. No writer sits on a customer's request path, and the guarantee relied on
is the role, not unreachability: a method that cannot be called today is one
refactor from being called, and a role with no `UPDATE` is a refusal the
database makes. The grant has since decided data models. A customer's write
goes in the customer's own database (purchase requests, support tickets,
collected by cron), an operator's write that customers read is a registry
entity (notices, support replies), and re-obtaining a refused privilege over
HTTP was rejected by name.

- `deploy:registry-grants` **prints** the SQL rather than executing it,
  because an instance that could grant itself privileges could be made to
  grant itself others. The table list derives from the `control` entity
  manager's mapping, so a new entity cannot leave the script stale.
  `RegistryGrantsTest` proves it against a real role, not by string matching.
- **`deploy:check-grants` verifies the SQL was actually run** (XIV-143; it was
  missed twice in two days, each time a 500 for every user of every tenant).
  It runs in `bin/deploy` right after the control-plane migration, and exit 3
  stops the deploy while the old containers keep serving. It checks and does
  not repair, because a repair begins with `REVOKE ALL` and would silently
  remove a deliberate extra grant. It asks `has_table_privilege` rather than
  reading the ACL, reports a superuser connection as a finding, and treats
  **excess as a finding**, not only absence. `XIVI_PUBLIC_ROLE` names the
  role; empty means a single-image deployment, and the check stands down.

**The schema has exactly one owner.** The customer-facing image has no DDL, so
its entrypoint *asks* (`doctrine:migrations:up-to-date`) and refuses to start
on a stale schema, fatal like the secret guard, because a schema behind the
code is a property of the instance. `bin/deploy` refuses to run out of the
customer-facing image at all. Both refusals test the package's presence on
disk, not an environment variable: a flag says what somebody configured, a
directory says what is in the image.

**What the public image still contains, bounded**: `migrations/control/` (DDL
whose namespace is recorded in `doctrine_migration_versions`; the entrypoint
does not run them here and the role could not), the two `^/control` access
rules, one shared `composer.lock` (which is what stops the builds drifting),
the two bundle-list files naming a class that is not there, and
`packages/xivi-mate` sources (require-dev, never installed). The builder stage
refuses to finish if `Xivi\ControlPlane` survives in sources, autoloader or
compiled container. `bin/ci` builds both targets, because only the build
proves the container compiles with the package genuinely gone.

### 4.5 Nothing noticed when a scheduled job stopped (XIV-126)

The commands that must run on a schedule are `App\Monitoring\ScheduledJobs`,
in code, each entry carrying its cadence and the sentence saying what is wrong
while it is not running. Cron entries, not workers (§9.2: classic mode,
nothing runs between requests). Every screen built on those jobs is honest
about staleness, and none of that is monitoring, because nothing makes anybody
look. A stopped `signup:provision` is a customer who confirmed and hears
nothing (XIV-108).

**Rejected permanently: an internal checker**, v1's `BatchChecker`. The
checker is itself a scheduled job, so the failure it exists to catch is the
failure that stops it, and the empty mailbox it produces looks exactly like
health. It would also give a monitoring concern a schema and send mail through
the transport whose failure it monitors.

**The shape that works: the job pings, the service alerts.** The job makes an
HTTP request when it runs, and the external service alarms when one does not
arrive. The alarm is the absence of us, the one signal a dead cron, a full
disk, a dead container and an unplugged server all produce identically.

- **Implemented against the protocol, not a vendor.** `XIVI_MONITOR_PINGS`
  maps command names to URLs. Healthchecks (self-hostable, BSD-3-Clause, keeps
  the exit code as a number) is the recommendation, and Better Stack speaks
  the same bytes. No abstraction over four vendor dialects.
- **The exit code is the payload**, sent as `<url>/<code>`, because §4.2's
  three codes mean different actions. A start ping gives runs a duration and
  separates killed from never-started.
- **A ping carries the fact and a number, nothing else.** No slug, no counts,
  no hostname; the URL goes to a third party, so §8.11's counts-not-contents
  is drawn further back. A ping URL is a bearer token, so `deploy:crontab`
  prints *watched* and never the address.
- **Optional and off by default.** Empty means no HTTP client, no socket, no
  behaviour change. A failed ping never fails the job, because a monitoring
  feature that turns a third-party blip into a failed deploy gets removed. A
  malformed entry is refused rather than skipped (`PingTargets`).
- **One console event listener sends them** (`JobMonitorSubscriber`), so
  watching the next scheduled command means adding a map entry, not
  remembering three lines. `deploy:crontab` prints the crontab, redirectable
  into `/etc/cron.d/xivi`, with per-job watch status: exit 0 when all or none
  are watched, 3 for the inconsistent state.
- **Rejected: `symfony/scheduler`.** It needs a Messenger consumer, the
  process this runtime does not have; revisit if a supervised consumer ever
  exists for its own reasons. **Out of scope, decided**: uptime checks (they
  need nothing from this repository) and status pages (a different product,
  with §8.4.2's language question and the internal-checker trap of hosting it
  inside the thing it reports on).
- **Honest limit**: a job nobody configured a check for can still stop
  silently. The gap is visible rather than closed; `deploy:crontab` exits 3 on
  "some watched". Nothing here schedules anything: the repository prints the
  crontab, and an operator installs it.

### 4.6 A lapsed customer reads, exports, and pays nothing (2026-08-20)

Nothing bills anybody yet, since §6.5 built a one-off module price and
deliberately stopped before recurring, but the lifecycle a subscription
implies is decided now. Tenant states are cheap to write down and expensive to
retrofit.

**Lapse is a grace period, then read-only, and the export never closes.** A
lapsed customer keeps signing in, reading, and exporting; what stops, after
the grace period, is writing. Read-only is a registry fact on the same axis as
`suspended`. The grace period's length is a contract term, not this section's.

**Nothing is deleted by lapsing.** Removal stays §4.1's separate deliberate
act, and no billing state may ever trigger it on its own. A billing system
that can destroy a database is §4's blast radius handed to a webhook.

**The export artifact is the per-module CSV**, decided against also offering a
raw `pg_dump`. A dump is complete and unreadable anywhere else, and a second
export path is a second thing to keep true. "You can always leave" has to name
a format the customer can open.

### 4.7 Files are the second thing to back up (XIV-115)

A record's bytes are on a filesystem rather than in the tenant database (§5.30),
which changes three deployment facts and adds a check.

- **`XIVI_ATTACHMENTS_DIR` must be a volume, mounted at the same path in both
  images** (§4.4). The customer-facing build serves the downloads, because a
  design needing the administration surface to hand a customer their own file
  would be the wrong design; it needs nothing the `SELECT`-only registry role
  does not already give it.
- **A backup is now two operations**, `pg_dump` plus the directory, and a
  restore that takes only the first produces records pointing at nothing. The
  documentation site's *Running an installation* says so in those words.
- **`tenant:deprovision` removes the directory in the same command that drops the
  database**, between the role and the registry row: after the drops because a
  removal that destroyed a live customer's files and then failed would have
  destroyed data while they were still served, and before the row because the
  directory name is derived from the DSN in it. Its confirmation names the file
  count and total size beside the record count.
- **`tenant:files:check` reports records with missing files and files no record
  claims**, per tenant or across the registry, with §4.2's exit codes and no
  repair. **Run on demand rather than by `bin/deploy`**: the deploy checks are
  cheap properties of the deployment whose failure is an outage, and this is a
  full directory walk per customer whose expected steady state is a handful of
  orphans. A release blocked by somebody's abandoned upload is a check that stops
  being read.

### 4.8 The deploy itself: Deployer as an SSH task runner (XIV-61)

`deploy.php` is the definition and carries the long-form reasoning. What is
settled here:

- **Deployer, driving `docker compose` on the target, and its release layout is
  deliberately unused.** No `releases/N`, no `current` symlink, no `shared/`,
  because this ships as a container image and the target has no PHP to link a
  release against. `recipe/common.php` is not required at all, so `dep list`
  cannot offer `rollback`, `deploy:symlink` or `provision:mysql` as though they
  meant something here. A GitHub Actions job was the alternative and lost on one
  point: XIV-60 wants the control instance not publicly reachable, and a hosted
  runner cannot reach a box an operator on the VPN can.
- **The image is built where the deploy is driven, pushed to ghcr.io, and pulled
  by digest.** Not built on the target: that puts the toolchain on the box and
  makes builds compete for the RAM the sizing was done against. The digest rather
  than the tag, so a container that restarts weeks later comes back as the same
  code.
- **Order: push, pull, `bin/deploy`, then replace the containers.** Migrating
  before the swap is what lets the instance stay up, and it works only because
  the window rule (§4.2) makes tenant migrations additive.
- **Rollback is `dep deploy:to --tag=<previous digest>`, and it does not touch
  the databases.** Code steps back, schema does not, and the additive rule is
  what makes that safe rather than lucky. A migration that must remove something
  is two releases.
- **A failed deploy has nothing to unwind.** Nothing is replaced until after the
  migration, so a failure leaves the previous image serving.

**What the target needs: Docker, Compose, and nothing else.** No PHP, no
Postgres client, no rsync. That last one is a constraint the deploy honours
rather than a fact about Debian: `deploy.php` sends its configuration files
base64 through the SSH session instead of calling Deployer's `upload()`, which
shells out to rsync on both ends. Log rotation is worth setting on the daemon
(`10m` x 3 in `/etc/docker/daemon.json`); the default is unbounded and the disk
is the smallest thing on the box.

**The cron entries are installed by the deploy, generated out of the image being
deployed** (§4.5). `deploy:crontab` prints them from `App\Monitoring\ScheduledJobs`
in code, so what lands in `/etc/cron.d/xivi` is what *this release* needs rather
than what a runbook remembers, and it cannot be a version behind its own
commands. **A committed cron table was considered and rejected**: it would be a
second list, and the way anybody would find out it had drifted is a job that
quietly stopped being scheduled, which is the same silence a job that never ran
produces.

Two things about that file are load-bearing and both fail silently when wrong.
The entries run through `docker compose` rather than `bin/console`, because the
host has no PHP; `--wrapper` is what makes that so. And the task refuses to write
anything that is not a crontab, after the first version captured the container
entrypoint's own output, which prints the Symfony banner, the cache clear and the
database wait to stdout, straight into `/etc/cron.d` above the real entries.
`--entrypoint php` is what stops that at the source.

**Mail needs a port the host can reach, and that is worth measuring rather than
assuming** (§8.7). Hosting providers block outbound SMTP to keep their address
ranges off blocklists, and the block is a timeout, so it looks like mail hanging
rather than mail refused. On the first target, 25, 465 and 2525 were blocked and
587 was open, which rules out `smtps://` and leaves `smtp://` on 587 with
STARTTLS. Credentials in the DSN have to be percent-encoded: a username that is
an email address carries an `@` inside the userinfo, and a `[` in a password
makes a URL parser read the rest as an IPv6 literal and refuse the whole string.

**Outbound TLS needs `openssl.cafile` set, and finding that out cost a
deployment.** The runtime image is `debian:13-slim` with no `ca-certificates`
package; the bundle is copied from the builder and pointed at with
`SSL_CERT_FILE`. The CLI honours that and FrankenPHP-served requests did not, so
signup confirmations failed with `certificate verify failed` while
`bin/console mailer:test` sent successfully on the same DSN in the same
container. Two SAPIs disagreeing about the trust store is not worth leaving to
environment inheritance, so `frankenphp/conf.d/10-app.ini` names the file. It
covers SMTP and the §4.5 monitoring pings, which go to an HTTPS endpoint and are
documented to fail silently.

**Secrets are installed once, deliberately, and never by a deploy.**
`dep secrets:install <alias>` writes `/opt/xivi/.env.deploy` at mode 600 from a
gitignored local file. A deploy that shipped them every time would put them in
the shell history of every machine that ever deployed and would silently
overwrite a rotated value.

**On-demand TLS with an ask endpoint, which is not optional.** Tenancy resolves
by hostname, so there is no list of names to certify and Caddy must be allowed to
answer names nobody configured. Without the ask, anybody pointing DNS at the
address spends the registered domain's certificate budget, which is every
customer's. `App\Controller\TlsAskController` answers from the registry plus
`app.system_hosts`, returns 204 or 404 and no body, and **refuses any request
that did not arrive from the loopback**, which is what stops it being a way to
ask whether a customer exists. It reads `REMOTE_ADDR` rather than
`Request::getClientIp()` on purpose: the latter believes `X-Forwarded-For` wherever
trusted proxies are configured. `/_tls/` is `PUBLIC_ACCESS` in `security.yaml`
because Caddy asks before the handshake finishes and has no credential to send.
Behind the firewall it answered 302 and Caddy reads anything non-2xx as no, so
the failure mode was an instance serving no customer over HTTPS at all.

**Postgres is tuned by the deployment, not left on the compose defaults.**
`max_connections` and `shared_buffers` are variables in `.env.deploy` because the
right values are a property of the box. Connections scale with tenants times
concurrency here, since there is a database per tenant, no pooler, and classic
mode. **A pooler is decided against for now**: it would need a pool per database
rather than one pool, so it is design rather than a package, and XIV-154 measured
tuning as worth about 12% on a migration walk.

**The hosts are not committed.** `.hosts.yaml` is gitignored with
`.hosts.yaml.dist` as the template, because the German test box is a rehearsal
for the move to a Swiss one and a move that means editing a committed file is a
move somebody does wrong under pressure.

**Two things a first deployment gets wrong, both found by deploying rather than
by reading.** `.env` ships `TENANT_ADMIN_DSN` carrying the committed placeholder
password, and `PlaceholderSecretGuard` deliberately does not guard it or
`DATABASE_URL` on the argument that a wrong database password fails loudly in
seconds. It does fail loudly, at `tenant:provision`, with `password
authentication failed for user "app"` and no hint about which variable is at
fault, so `compose.prod.yaml` now builds `TENANT_ADMIN_DSN` and
`TENANT_DSN_TEMPLATE` from `POSTGRES_PASSWORD` and the deployment sets one value.
Separately, the env file is what Compose interpolates this file *with*; it is not
the container's environment, so anything the running application reads has to be
named in `environment:` as well.

**Anything structural taken from the environment has to be recomputed at
container start.** `%env()%` is resolved when a parameter is read, so most
configuration survives one image being deployed to many instances. Routing does
not: `SignupRouteLoader` decides whether the signup routes exist from
`SIGNUP_HOST` (§8.13), and that is written into the compiled URL matcher at
warmup, which happened at image build against the committed `.env`. The
entrypoint runs `cache:clear` for that reason, and `cache:clear` rather than
`cache:warmup` because warmup leaves an existing matcher alone, which was
measured rather than assumed. The failure this fixes is worth remembering: the
landing page was served by the dashboard route, so it answered 500 on a host that
resolves no tenant, and `debug:router` reported the signup routes as present
throughout, because it re-reads the loader instead of the matcher.

**An installation with no customers has to say it means it.** `bin/deploy` exits
1 on an empty registry (§4.2), because one that has lost its registry is
indistinguishable from one that never had a customer and the first should stop a
release. The case that was not allowed for is an instance waiting for its first
self-service signup (§8.14), which is legitimately empty and, while it was,
could not be deployed at all: the step fails before the serving containers are
replaced, so removing the last tenant from an installation made it undeployable.
`XIVI_ALLOW_EMPTY_REGISTRY=1` is that stated deliberately, and it is a variable
rather than a new default because only the deployment can tell the two apart. It
does not cover `--slug`: a slug nothing answers to is a typo or a tenant that has
gone missing, never an installation that is empty on purpose.

**The machine that deploys is the dev container.** There is no PHP on the host,
so `compose.override.yaml` mounts `~/.ssh` read-only at `/ssh` and the dev image
carries an ssh client. `ssh_control_path` is moved to `/tmp` because Deployer's
default puts the multiplexing socket under a home directory the container does
not have.

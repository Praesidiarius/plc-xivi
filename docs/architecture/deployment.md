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
method exists to get right, the sharpest being that it resolves the database and
the role **out of the stored DSN** rather than assuming they follow the slug. A
DSN that disagrees with the slug is not exotic; it is what the `--dsn` option on
`tenant:provision` is for.

So there is a `tenant:deprovision`, and it **ships** — it is not excluded from the
production image the way the development-only commands are. That is the decision,
and it is not the comfortable one. The argument is that an operator who cannot
remove a customer from the console will remove them from `psql` instead, and the
`psql` version is the failure this replaces rather than a fallback it can afford
to push people towards. What ships is therefore made hard to do *by accident*,
not hard to do: the confirmation names the database, the role, the hostnames and
how many records are in there before it asks, the interactive default is *no*,
and an unattended run is **refused outright** unless `--force` was typed.
`--no-interaction` on its own is specifically not enough, because Symfony answers
an unanswered question with its default and a default is not consent.

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
the cluster's side, a database with sessions open to it, which Postgres refuses
to drop, `IF EXISTS` and all. It was reported from the test suite, where
deprovisioning happens constantly, and it would have been easy to file as a
test-suite problem. It is not one: the statement that failed is the statement a
real deprovision runs, and the operator's version of the same failure was worse
than the suite's, because the control-plane row was removed and flushed
**before** the drop.

**So the order was turned around: database, role, registry row.** The two
orderings do not fail equally. Dropping first and failing leaves a row pointing
at nothing, which every tool in this project can show — `tenant:list`,
`tenant:inspect`, the control-plane pages and the deprovision command's own
lookup all start from the registry — and which running the same command again
repairs, both drops being `IF EXISTS`. Removing the row first and failing leaves
a database and a role that nothing knows about, invisible to all of those and
clearable only with `psql` and somebody who happens to remember the database
name. That is the whole argument; it is not a preference about which half is more
likely to fail, and it is written out where it is performed, on
`TenantProvisioner::deprovision()`.

**Disconnecting people is written out as a step, not spelled `WITH (FORCE)`.**
Postgres 13 and later would do it in one keyword, and what that keyword does is
throw a customer's users out mid-request. That is the correct behaviour here — it
is the direct consequence of refusing to require `suspended` — but it is a
decision, and a decision that arrives as a keyword on the end of an unrelated
statement is one nobody reads. So `pg_terminate_backend` over `pg_stat_activity`
is its own named step with its own argument attached, and the drop still carries
`WITH (FORCE)` in the belt-and-braces arrangement `bin/ci`'s test-database
reclaim already uses (§9.2): the statement handles every session that exists now,
the keyword handles the client that reconnects in between. Only one of the two is
the reason the drop succeeds.

**`pg_terminate_backend` requests a termination; it does not perform one**
(XIV-142). Between the statement returning and the session actually being gone
there is a window, short and machine-dependent, in which the cluster will still
say somebody is connected. That window changed no behaviour here, because
`WITH (FORCE)` covers it by *waiting* — the half of that keyword nobody had
written down. What it changed instead was
`TenantRemovalFailed::sessionsCameBack()`'s docblock, which now names a stuck
backend as the second thing an operator might be looking at rather than only a
client reconnecting, and one test, which polls to the same five-second deadline
the drop keeps rather than asking the cluster in the statement immediately after
the terminate. Both carry the measurements.

**Whether the provisioning credentials may do this at all was measured, not
assumed**, and this is the part a deployment has to know before it narrows them.
Terminating another role's backend is not implied by `CREATE DATABASE` and
`CREATE ROLE`: Postgres allows it to a superuser, to a member of
`pg_signal_backend`, or to a role that *inherits* the privileges of the connected
role — and that last clause is a trap, because since Postgres 16 a `CREATEROLE`
role's grant on the roles it creates carries `ADMIN` without `INHERIT` or `SET`.
On this project's Postgres 18, a role with exactly `CREATEDB CREATEROLE` was
observed failing with `42501 permission denied to terminate process` against a
tenant role it had created itself, and succeeding once granted
`pg_signal_backend`. Development and test run as the cluster superuser and never
meet it, which is precisely why it had to be measured rather than inferred from a
green suite. The privilege error is caught by name and answered with the grant
that fixes it.

The same experiment turned up two things about a narrowed provisioning role that
are **not** fixed here and are worth knowing before anyone tries one in
production: `CREATE DATABASE … OWNER <tenant role>` fails for it with "must be
able to SET ROLE", and `DROP DATABASE` fails with "must be owner of database",
both for the same Postgres 16 change to what `CREATEROLE` confers. So a
deployment that wants provisioning credentials short of superuser needs
`pg_signal_backend` **and** a `GRANT … WITH SET TRUE, INHERIT TRUE` on every
tenant role — which is a design question for the provisioning half rather than
the removal half, and is left open.

**What the ticket deliberately did not touch:** what the command asks before it
acts. The confirmation, the interactive default of *no*, and the outright refusal
of an unattended run without `--force` are all settled above and were left alone.


`tenant:reset` — deprovision, provision, install modules, generate demo records,
print the admin password — is the development counterpart and is **not registered
outside `dev` and `test`**. Note that the two exclusions in that neighbourhood
are not the same argument: the demo commands are excluded because generating
fiction into a customer's database is dangerous, while `tenant:reset` is excluded
because it is *meaningless* where the records are real. Neither is "it is
destructive" — the destructive one of the pair is the one that ships. It resolves
module install order from each blueprint's own `requires` (§6,
`Xivi\Core\Module\ModuleInstallOrder`) rather than from the order somebody
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
can disagree — and the command line that starts over. The confirmation says the
same thing before the drop, so nobody learns it from the wreckage.
`ResetProgress` is that report and carries the rest, including why the exception
itself is re-thrown rather than turned into a tidy message.

#### The memory itself: one process, three accumulators

The failure was not the generator. `tenant:demo:generate` had never hit it at
5,000 records because each module was a process of its own; folding six commands
into one leaves every debug collector in the process filling for the whole run.
Two of them do it expensively — Doctrine's profiler query log and Monolog's debug
processor, each keeping a record per statement — and emptying only the first
merely moved the wall. Both are emptied at every seam of the reset and after
every generated batch, which makes their cost a function of the batch size rather
than of `--records`; the third accumulator, the profiler's stopwatch, is
deliberately left growing. `ResetTenantCommand::forgetQueries()` has the
measurements, the reason there is no supported way to turn any of this off from
inside a running command, and why resetting the stopwatch would trade slow growth
for a reliable explosion after the work had succeeded.

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
happen, and the answer was nobody. Shipping an entity change therefore meant new
code serving every customer against the old schema, indefinitely, and the first
sign of it would be a query failing in production for a customer who had done
nothing unusual. It is the single most important thing this ticket fixes.

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
the obvious alternative and was rejected for three reasons that compound: the
entrypoint runs on every container start, which is not once per deploy; the loop
is work proportional to the customer count, in the startup path, with the
container not serving for its duration; and two containers starting together
would compute the same plan for the same databases and both begin applying it.
`bin/deploy` makes that argument in full and `frankenphp/docker-entrypoint.sh`
makes it from the other end — read both before moving the step. A one-shot step
has none of those properties. Its honest cost is that it has to be *called*, and
an entrypoint cannot be forgotten while a script can — that cost is paid in the
deploy definition this ticket still has open, rather than by making every
container start do something it has no business doing.

**The entrypoint keeps its control-plane migration**, and running it in both
places is deliberate rather than sloppy: it is idempotent, so the second run
costs a version query, and what it buys is that a container can never serve
against a control-plane schema older than itself.

**`bin/deploy` is a file in this repository rather than lines in a runbook** for
two properties a runbook does not have. It ships inside the image it deploys, so
the sequence being run is the sequence that release was written against — a
runbook lives elsewhere and is edited by somebody who is not looking at this
branch, which is how a deploy comes to run last month's steps against this
month's migrations. And it cannot be half-run: the ordering above matters,
because the tenant loop reads the registry and a release that adds a
control-plane column that query needs must move the control plane first. Typed by
a person, that ordering is a convention; written down, it is a property.

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

**What that forbids, in a tenant migration's `up()`:** `DROP TABLE`,
`DROP COLUMN`, `RENAME TO` and `RENAME COLUMN`, and `SET NOT NULL` on an existing
column. Add the new name, write both for a release, drop the old one later;
backfill first, constrain in a later release.
`tests/Unit/TenantMigrationsAreAdditiveTest.php` refuses all four and carries, as
its failure message, what code still running does when it meets each one.

`down()` is not constrained by any of this. A rollback is a deliberate act by
somebody who has decided to go backwards, and forbidding a `down()` from removing
what its `up()` added would forbid reversibility itself.

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
ticket, an empty registry, an unknown `--slug` and a run in which tenants failed
all exited 1, so a deploy could not tell "there is nothing to do" from "one of
your customers is on the wrong schema and the new code is already serving them",
and the safest thing it could do with that was treat a healthy installation with
no customers as a failed deploy.

There are three codes now, and they are the command's published contract — the
one every other check in a deploy has since borrowed (§4.3, §4.4, §4.5):

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
the whole registry and hoping.

#### A container refuses to start on a secret anybody can read

`.env` is committed and public, and it carries working values for everything the
application needs so that a fresh checkout starts with nothing configured. Two of
those values are secrets — `APP_SECRET` and `TENANT_SECRET_KEYS` — and the
production image compiles `.env` into `.env.local.php` during the build
(`composer dump-env prod`), so a freshly built image contains working copies of
both, verbatim.

A real environment variable still overrides them, so a deployment that supplies
`APP_SECRET` is fine. **A deployment that forgets is also fine, and that is the
problem**: there is no error, no warning and no degraded behaviour. Cookies are
signed, invitation links verify, the instance is healthy. It is simply signing
them with a value published on the internet, and the way that surfaces is not a
log line — it is somebody forging one. `TENANT_SECRET_KEYS` is the same shape
with worse consequences: it encrypts every tenant's database password and every
tenant's outgoing-mail password at rest in the control-plane database, and §8.9's
cipher is honest that it defends against a *copy* of that database, which is
exactly the defence a public key removes.

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

**So it is `deploy:check-secrets`, run by the container entrypoint** before the
database wait and before any migration. `set -e` means a refusal never reaches
`frankenphp run`, and the failure presents as a container that will not come up —
which is loud — rather than as a service that is fine. `bin/deploy` runs it too,
because that is the earliest a deploy can find out, and failing there costs a
one-shot container rather than whatever the orchestrator does with a service that
will not start. The three earlier homes it deliberately does not take — a
compiler pass, `Kernel::boot()`, and a `kernel.request` listener — are argued on
the command itself: the first two would refuse the **image build**, which boots
the production kernel in the production environment on the placeholders as a
normal part of building an image, and a check that makes the image unbuildable is
a check that gets deleted within a day.

**It stands down entirely outside `APP_ENV=prod`.** `bin/ci`, the test suite and
`bin/compose up` all run on the placeholders on purpose — that is what lets a
fresh checkout start — so refusing in development would be refusing the ordinary
case. The environment decides rather than the debug flag, for the same reason
`NonProductionMailGuard` gives (§8.7): the environment is what the kernel allows,
while debug is something production can legitimately be run with while somebody
diagnoses a problem, and an instance being diagnosed is still an instance serving
customers.

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

Absolute URLs generated during a web request take their host from the request, so
a request arriving on a host this installation does not serve would put that host
into a link somebody is invited to click — invitations ([XIV-1]) go out as
Symfony login links, which are absolute URLs in an email.
`config/packages/routing.yaml` already sets `default_uri`, and it does **not**
cover this: a request context wins over it, so `default_uri` is the console's
answer and only the console's.

#### Why this is a composed pattern and not a configuration line

`trusted_hosts` is a list of **regular expressions**, and this application's
hostnames are a wildcard by design: every customer gets their own (§4). The two
ways of getting that wrong are not symmetrical. Too wide is the same as not
setting it — an unanchored `xivi\.app` also matches `xivi.app.evil.example`.
**Too narrow takes a paying customer's installation off the air**, and the
symptom is an empty 400: no page, no header named, nothing in the body, and the
person who finds out is the customer. A hand-written regular expression puts that
asymmetry in the hands of whoever is editing an environment file at the time, and
both failures are one keystroke away — a forgotten backslash makes every dot a
wildcard, which is the exact mistake §8.9 already declined to make when it refused
to host-scope the control-plane firewall with Symfony's `host:` key.

**So a deployment names domains and the application writes the expressions.**
`XIVI_TRUSTED_DOMAINS=xivi.app,1plc.ch` is a fact an operator knows;
`App\Deployment\TrustedHosts` turns each entry into a pattern anchored at both
ends that admits the domain and any name under it, accepts the several spellings
somebody thinking about DNS writes, and refuses an entry that is not a hostname
at all rather than compiling it into something that matches nothing.

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
and the omissions are the decision. `x-forwarded-for`, `x-forwarded-proto` and
`x-forwarded-port` are trusted — the second is not optional in front of a
TLS-terminating balancer, or every absolute URL generated during a request, the
invitation link above all, comes out as `http://`. `x-forwarded-host` and
`x-forwarded-prefix` are **not**: tenant routing *is* the `Host` header, most
proxies append rather than replace, and believing a forwarded host would let a
caller pick the tenant and pick the host in a mailed link. DNS already decided
the host, and nothing here is served under a path prefix. The list and the
argument sit on the setting itself, in `config/packages/framework.yaml`.

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
   `App\Deployment\EventListener\UntrustedHostListener` writes one `error` line
   naming the host as sent, the variable, what it currently admits, and the
   command that lists who is affected. It matches on facts — the throwable chain,
   and whether the raw header is admitted — rather than on the framework's
   message text, so a reworded Symfony string cannot turn it off quietly.

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

**Two build targets from one repository** costs a second build in CI — cheap,
because the stage starts from the first's finished builder — and gives an image
that genuinely does not contain the administration code. Adopted.

**A separate repository** would give real isolation and real drift, plus a
shared control-plane schema owned by two repositories with no single migration
history. Not worth it for one operator, and the thing it would isolate is
already isolated by a package boundary deptrac enforces.

#### The obstacle that mattered: the application's security configuration

Dropping the Composer requirement was **not** sufficient, and finding out why is
most of what this ticket was. Four of the application's own configuration files
named control-plane classes or directories, and each would have failed the build
on its own, before anything was served: `security.yaml` (the control-plane
firewall's request matcher, its user checker, its provider's class, and the
signup firewall's matcher), `doctrine.yaml` (the package's entity directory —
DoctrineBundle checks that a mapping's directory exists while the container is
built, so this one fails with a message about a path rather than a class),
`routes.yaml` (a route *type* only the package registers) and
`config/services.yaml` (three of its classes under `when@dev` and `when@test`).

**Everything the package can declare, the package declares now**, contributed
from `XiviControlPlaneBundle`, whose docblock carries the argument for each and
for why prepending rather than loading is what keeps the merge order safe. The
application says nothing about any of it.

**Two things could not move, and both are Symfony's decision rather than ours.**
`security.firewalls` is declared `disallowNewKeysInSubsequentConfigs()`, so every
firewall in the installation has to be named by one configuration source: the
application names all four — in `config/packages/security_firewalls.php`, which
is PHP precisely because it has a question to ask — and *splices* the
administration surface's two in from the package when the package is present. So
the application carries the seam and the package carries the surface: a build
without it has no operator firewall because the file describing one is not in the
image either. `security.access_control` is `cannotBeOverwritten()`, the same
restriction one notch stricter, so the two `^/control` rules stay in the
application's `security.yaml`. What is left behind there is two path patterns and
a role name — no class, no service, nothing that stops a build — and it is the
harmless direction to be wrong in: a customer-facing image where `^/control`
still demands `ROLE_OPERATOR` carries one refusal it will never need, on paths it
has no routes for.

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
`config/bundles.php` is now a plain declarative array naming
`Xivi\ControlPlane\XiviControlPlaneBundle` unconditionally — **exactly the line
Flex would write anyway** — and `App\Kernel` does the skipping, from the explicit
list in `config/optional_bundles.php`. The property that makes this the right
answer rather than a tidier one: **a Flex rewrite of `config/bundles.php` stops
being a hazard**, because the file it produces is the file we want. That is
strictly better than detecting the rewrite, which was the other option and which
would have needed somebody to react to an alarm instead of needing nothing to
happen at all.

The rest of the decision lives on the code that makes it, and every part of it is
an argument rather than a mechanism. `App\Kernel` has why the list is explicit
rather than "skip anything missing" — a bundle absent from the image looks
exactly like a `composer install` that did not finish, so anything *not* on the
list that goes missing still fatals; why the list is read from `config/` rather
than written as a constant there, which was decided by measurement (`deptrac`
collects a `::class` in `src/Kernel.php` as a dependency on the package, and
spelling it as a quoted string would evade the check rather than satisfy it) and
which leaves **the kernel holding the rule and the configuration holding the
datum**; how `getBundlesDefinition()` is decorated without reimplementing
Symfony's environment matching, and why that also keeps the class name out of the
compiled container the `frankenphp_public` build greps; and why an absent
optional bundle warns outside `prod`, which is the mirror image of [XIV-61]'s
guard standing down there. `config/optional_bundles.php` has why the control
plane is on the list at all, and
`tests/Unit/Deployment/OnlyOptionalBundlesAreSkippedTest.php` plants both halves
side by side.

**What is still a Flex hazard, said plainly.** `importmap.php` is regenerated
the same way and is guarded by nothing. The stakes are much lower — a stylesheet
that comes back, not a deployment guarantee — so the answer is proportionate: no
mechanism, and the set of files that are *generated and hand-edited* is written
down in `AGENTS.md` where somebody adding a package will meet it, `importmap.php`
and `assets/controllers.json` included. What no longer depends on a recipe
behaving is the customer-facing image.

**[XIV-57]'s ordering invariant survives the move and is asserted the same way.**
`ControlPlaneFirewallTest` reads the container's own `security.firewalls`
parameter rather than the declared order in `security.yaml`, which is the merged,
compiled order and a better question than the one it was asking.

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
checked rather than assumed: `App\Registry` reads, and every writer of it in
`src/` is driven from a `module:*` or `tenant:*` command or from an operator
screen — that is, from a caller that lives in the package and is therefore absent
from the customer-facing image.

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
generated statements, opens a second connection *as that role* and asks
PostgreSQL. The string-matching version of that test would pass for a script that
names the wrong tables, one that forgets its `REVOKE`, or one that is never run.

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

`App\Deployment\RegistryPrivileges` carries the rest of it and is worth reading
before changing any of it: why its expectations are derived from the same
`readableTables()` and `withheldTables()` that write the `GRANT`s rather than
from a list, which is the only property that makes a check like this worth having
at all; why it asks `has_table_privilege` — "can this role do it" — rather than
reading the ACL, which answers only "was this statement run" and is blind to a
privilege reached through role membership or held by `PUBLIC`; why a **superuser**
is therefore reported on its own line, a `DATABASE_URL` still carrying the
administrator's credentials being a one-line mistake that undoes the whole of
§4.4 while every page works; and why **excess is a finding, not only absence**, a
role holding `INSERT` on a registry table being this section's guarantee not
holding while everything looks healthy.

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
table it is granted — and **refuses to start** if the answer is no. Fatal rather
than advisory, which puts it beside `deploy:check-secrets` rather than beside
`deploy:check-hosts`, and the asymmetry is the one those two already draw: a
schema behind the code is a property of the *instance*, so every customer it
would serve is served by code expecting columns that are not there.

`bin/deploy` itself refuses to run out of the customer-facing image, with a
message naming the internal one. It would fail anyway, on a permission error,
partway through — and "it cannot work" is not a good enough reason to let a
deploy start. Both refusals are argued where they are performed, in
`frankenphp/docker-entrypoint.sh` and in `bin/deploy`, including why neither of
them refuses a rollback.

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
changed is that `bin/ci` builds **both** production targets, in that order,
because the customer-facing one is assembled from the internal one's builder
stage. The second build is not optional and `bin/ci` says why at that step: the
unit test above catches most of what it catches in a second by reading the
configuration, but only the build compiles a container with the package genuinely
gone.

### 4.5 Nothing noticed when a scheduled job stopped (XIV-126)

The commands that have to be on a schedule for this installation to behave the
way its screens claim are `App\Monitoring\ScheduledJobs` — four of them today,
each carrying the sentence that says what is wrong while it is not running. They
are cron entries rather than workers because §8.7 settled that a long time ago
and for the whole runtime — FrankenPHP in classic mode with no worker block on
purpose, so nothing in this deployment runs between requests and a queue with
nothing draining it is worse than a slow request.

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

`tenant:migrate` publishes three codes on purpose (§4.2), and a monitor told only
"it failed" flattens them: a deploy that did nothing, and a deploy that left four
customers on last week's schema while the new code serves them, wake different
people and require different actions. So the outcome is reported as
`<url>/<exit code>` rather than as `<url>/fail`, and that is why the protocol
column above rather than the price column decides the recommendation.

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

The fact that it happened, and a number. A `GET`, no body, no query string, and
**no tenant slug, no customer name, no email address, no counts, no hostname, no
version** — §8.11's *counts, not contents* drawn a great deal further back,
because a ping URL goes to a third party and possibly a hosted one.
`App\Monitoring\JobMonitor` carries the reasoning omission by omission,
including why the `User-Agent` is the bare word `Xivi`.

What cannot be hidden is the source address, because a monitor by construction
receives a request from you. An installation for which that matters self-hosts,
which is the first reason Healthchecks is the recommendation.

The reverse direction is worth stating too: **a ping URL is a bearer token in URL
form.** Anybody holding one can report that the job succeeded, which is exactly
how somebody would silence this. So `deploy:crontab` prints *watched* and never
prints the address, because a crontab is world-readable on most machines.

#### Optional, and off by default

`XIVI_MONITOR_PINGS` is empty in the committed `.env`, and empty means **no pings
and no behaviour change of any kind** — no HTTP client, no socket, no timeout.
That is the shape [XIV-93]'s trusted domains and [XIV-61]'s secret guard already
have, and it is asserted rather than claimed.

A **failed ping never fails the job**, and a **malformed entry is refused rather
than skipped**. Both are decided against this section's own failure mode: a
monitoring feature that can turn a five-second network problem at a third party
into a failed deploy is a monitoring feature somebody removes, and an entry
silently skipped is a job nobody is watching on an installation whose operator
believes they configured watching. `App\Monitoring\JobMonitor` argues the first —
including why swallowing is right here and wrong for a mail (§8.7) — and
`App\Monitoring\PingTargets` argues the second and lists what is refused.

#### One place, and a list that is in the build

The pings are sent by **one console event listener**,
`App\Monitoring\EventListener\JobMonitorSubscriber`, rather than by three lines at
the top and bottom of each command — and that is the reason this whole ticket
exists, one level down: **the next scheduled command would not have them, and
nothing would say so.** Watching one more command is adding an entry to
`XIVI_MONITOR_PINGS`; nothing is edited and nothing is remembered. The map may
name any command, not only the scheduled ones, because a `bin/deploy` that
quietly stopped being run is the same class of silence.

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
`tenant:migrate`'s reason. Every one of today's jobs is a control-plane command,
so the customer-facing image prints an empty crontab and says why; the list holds
command *names* rather than class names precisely so that it costs that image
nothing.

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


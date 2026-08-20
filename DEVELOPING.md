# Developing Xivi

**For somebody changing this code.** How to run the stack, how to run the
suite, what `bin/ci` covers, where the packages sit, and the tooling this
project ships for itself. It lives in the repository rather than on the
documentation site because it has to travel with the commit that changes the
behaviour it describes.

Three other documents, and the line between them:

| | |
| --- | --- |
| [`docs/architecture.md`](docs/architecture.md) | The design brief's entry point: the non-negotiable constraints, the code layout, and a map of the rest. |
| [`docs/architecture/`](docs/architecture) | §4 to §8, one file per area: deployment, data model, extensibility, open questions, identity and access. Cited by section number throughout the issue tracker, and distilled to the decisions since [XIV-159]. **Read the sections you need, never the whole brief.** |
| [`AGENTS.md`](AGENTS.md) | The conventions, and the two or three things that mislead somebody who has only read the code. Written for a coding agent and worth a human's two minutes. |
| [The documentation site](https://praesidiarius.github.io/plc-xivi-docs/) | For whoever deploys or uses an installation: configuration, hostnames, deploying, the command reference. |

## Requirements

Docker and Docker Compose. Nothing else: there is **no host PHP and no host
Composer step**.

## Use `bin/compose`, not `docker compose`

It is a thin wrapper that forwards every argument through, `bin/compose up -d
--wait`, `bin/compose logs -f php`, `bin/compose down`, after pointing Compose
at *this* checkout's stack.

That matters because a checkout is the unit of isolation here: the compose
project, the published ports, the bind mount, the test tenant prefix and the
dev image all derive from the directory, so a git worktree is a first-class
second stack (XIV-51, XIV-71). A bare `docker compose` knows none of it, and
in a worktree it collides on port 443, runs the suite against the main
checkout's tenant databases, and rebuilds the dev image every *other* checkout
is running, the last two quietly. The wrapper also runs the container as you
rather than as root, so files it creates belong to you; there is nothing to
put in `.env.local`.

`bin/compose` with no arguments answers "which stack is this, and where":

```bash
bin/compose
# checkout   plc-xivi (the main one)
# project    plc-xivi
# image      xivi-php-dev
# app        https://localhost:443
# ...
```

The derivation lives in `bin/lib/stack-env.sh`, and `bin/ci` reads the same
file, so the suite and your shell cannot end up on different stacks (XIV-55).

Starting the stack, provisioning a first tenant and reaching it in a browser
is the [Quickstart](README.md#quickstart) in the README.

## Throwing a test tenant away, and getting a fresh one

```bash
bin/compose exec php bin/console tenant:reset bulk \
    --modules=contact,article,order,invoice --records=300 --seed=24
# ... Password: <generated, shown once>
```

One command for what used to be six: the existing tenant is deprovisioned, a
new one is provisioned and migrated, the modules are installed, each is filled
with demo records, and an admin user is created and their password printed,
which is the whole point, since a fresh tenant nobody can sign in to is not
much use.

Worth knowing:

- **Module order is worked out for you.** An invoice needs an order and an
  order needs a contact; list them in any order you like. A module that is
  missing a requirement, or that this build does not carry, is refused
  *before* the existing tenant is destroyed.
- `--modules` defaults to every module in the build, `--records` to 50, and it
  is **one number applied to each module**: 300 contacts *and* 300 articles
  *and* 300 orders. Different sizes per module are what
  `tenant:demo:generate` is for.
- `--seed` makes the records identical every run, which is what makes "it
  broke on record 4,312" something somebody else can see too.
- Hostnames default to `<slug>.localhost`; pass your own as extra arguments.
- **It destroys before it builds, and no flag changes that.** The slug, the
  hostnames, the database and the Postgres role all belong to the tenant being
  replaced, so the drop genuinely is the first act. If something fails after
  it, the command prints what is gone, what is standing and the line to run
  again; §4.1 argues why that is the answer rather than a temporary slug and a
  swap.
- **No `--no-debug` needed at any size.** Turning `--records` up used to
  exhaust the memory limit in Symfony's profiler collectors, since the whole
  rebuild is one process; the command empties them as it goes now.
- **Development only.** It is excluded from the production image in
  `packages/control-plane/config/services.php`, which is where the exclusion
  moved with the package (XIV-96).

To remove a tenant without building it again, including on a production
installation, where `tenant:reset` does not exist:

```bash
bin/compose exec php bin/console tenant:deprovision bulk
```

It names the database, the role, the hostnames and how many records are in
there, then asks; pressing return is *no*. An unattended run needs `--force`;
`-n` on its own is refused rather than answered with a default. It drops the
database and the role and deletes the row, and there is no undo: take the dump
first.

**The development tenants are throwaway.** Reset them, drop them, rebuild
them. Nothing in them is worth preserving, and no plan here has to protect
their data. The one reason to make your own instead is other people, not
safety: several checkouts and several agents share one Postgres cluster, so
working in a tenant somebody else is using costs them a confusing failure. The
suite provisions and drops its own for exactly that reason.

## Looking at a tenant's database

```bash
bin/compose --profile tools up -d adminer   # http://127.0.0.1:8080
```

Sign in with server `database`, user `app`, password `!ChangeMe!` (or whatever
`POSTGRES_PASSWORD` says), and pick the database: each tenant is its own,
named `tenant_<slug>`, so `tenant_acme`. That is §4's isolation seen from the
outside.

Note what that login is. `app` owns the cluster and can read every tenant,
which is the operator's view and not the application's: the app connects as
the tenant's own Postgres role, which can reach exactly one database. Those
role passwords are encrypted in the control plane and nothing prints them, so
Adminer cannot be used to check that isolation, only to look at data.

It sits behind a profile, so it is opt-in and never starts as part of the
stack or of CI. It binds to the loopback, because it is an unauthenticated
door to a database server.

## Looking at mail that was sent

Mail sent in development goes to [Mailpit](https://mailpit.axllent.org/),
which starts with the stack. Open it at <http://127.0.0.1:8025>. It shows the
rendered HTML, the plain-text alternative and the raw source of every message,
which a log line cannot.

The dev `MAILER_DSN` names it (`smtp://mailpit:1025`, in `.env.dev`), so
nothing you send by accident leaves this machine. Its inbox is kept in memory
only, so a restart empties it.

**It is visibility, not a guarantee, and the guarantee exists beside it**
(XIV-37). A catcher sees only what is pointed at it, so a DSN aimed at a real
server would once have reached real people with this container none the wiser.
`App\Mail\NonProductionMailGuard` is registered ahead of every transport
factory symfony/mailer ships and refuses, outside production, to *build*
anything that could deliver, this catcher and the loopback excepted in dev and
nothing at all excepted in test. That covers a tenant's own SMTP credentials
too, since those become a DSN through the same factory. See brief §8.7.

Where a customer's mail actually *comes from* on a real installation is their
own setting rather than a variable here; that is
[Configuration](https://praesidiarius.github.io/plc-xivi-docs/running/configuration/)
on the documentation site.

If two checkouts are running, the second's UI is not on 8025. `bin/ci` derives
the port from the directory the same way it derives the compose project and
the database port, so the stacks do not collide. Set `MAILPIT_PORT` to pin it.

Only the web UI is published, on the loopback, for Adminer's reason: it is an
unauthenticated reader of every message this machine has sent. SMTP is
reachable on the compose network alone.

The test suite does not use it and should not be made to. The note beside
`MAILER_DSN` in `.env.test` says why eight parallel workers and one shared
inbox is a race rather than an assertion.

## Symfony AI Mate

> **Development only.** Mate is a local MCP server that hands a coding agent
> this application's Monolog logs and Symfony profiler. Those contain tenant
> hostnames, executed SQL and request payloads, which is real customer data on
> any deployment that has any. Never run it against production, and never
> expose it beyond the machine you are developing on. Upstream says the same.

It is required as a **dev dependency**, so `composer install --no-dev` leaves
it out and it is absent from the production image. It registers no bundle:
`mate serve` is its own process, speaking MCP over stdin and stdout, and it
opens no port.

It earns its place here because `var/` is an anonymous volume in the dev
container and so is invisible from the host; without it, logs and profiles
cannot be read from outside the container at all.

Nothing it generates is committed (see `.gitignore`), because the command
depends on how you run PHP and the agent files depend on which agent you use.
Set it up yourself, **both commands, every fresh checkout**:

```bash
bin/compose exec php vendor/bin/mate init
bin/compose exec php vendor/bin/mate discover
```

Two things to know, both of which cost an afternoon once:

- `mate init` writes `"command": "php"` into `mcp.json`. There is no host PHP
  here, so change it to
  `bin/compose exec -T php vendor/bin/mate serve --force-keep-alive`. Your MCP
  client runs that from whatever it considers the working directory. Give it
  an absolute path if that is not this checkout, or it will attach to another
  one.
- `mate discover` is a **manual step**. Its Composer plugin is deliberately
  left out of `allow-plugins` so that nothing of Mate's runs during `bin/ci`;
  the price is that without `discover`, the extensions stay unregistered,
  including this project's own, and the tool list silently stays short.

`mate init` also writes an `AGENTS.md` telling agents to prefer its tools over
the CLI. Take that as a suggestion; for most of what this project needs, a
shell in the container is the better instrument.

### This project's own tools (XIV-76)

`packages/xivi-mate` is a committed Mate extension, wired in as a path
repository like the modules and required as a **dev** dependency, so it is
absent from a production build entirely. `mate discover` enables it; check
with:

```bash
bin/compose exec php vendor/bin/mate mcp:tools:list
```

| Tool | What it answers |
| --- | --- |
| `xivi-tenants` | Which tenants exist, their status and hostnames, whether each schema is current, what each has installed |
| `xivi-tenant-shapes` | What one tenant's modules **actually** look like: every field, type, options, variants, collections. Not the blueprint; see §6.1 |
| `xivi-modules` | The module catalogue and each module's state, which is what decides whether the store offers it |
| `xivi-tenant-reset` | **Destructive.** Rebuilds a dev tenant end to end; the result names what was destroyed |
| `xivi-tenant-deprovision` | **Destructive and irreversible.** Needs `force=true`; the command refuses an unattended run without it |

Every one of them has a console twin, so a dropped MCP server costs
convenience and nothing else:
`bin/console tenant:inspect [slug] [module] [--modules] [--json]`,
`module:list`, `tenant:reset`, `tenant:deprovision`. Nothing here is
tool-only, and §6.4 of the brief argues why that is a rule rather than a
nicety.

## Pointing a command at one tenant

The full command reference, provisioning, operators and the deploy checks, is
[Commands](https://praesidiarius.github.io/plc-xivi-docs/running/commands/) on
the documentation site. Two things there are only for people working on the
code.

`tenant:inspect [slug] [module]` is the development view of the registry:
tenants with their schema state and installed modules; with a slug, that
tenant's *real* field definitions. `--modules` for the catalogue, `--json` for
exactly what the MCP tools return.

And any console command can be pointed at one tenant's database with the
`TENANT` environment variable, because a command has no Host header to resolve
one from:

```bash
TENANT=acme bin/compose exec php bin/console doctrine:schema:validate --em=tenant
```

That is also how a tenant migration is generated, since the diff needs a
database to compare against:

```bash
bin/compose exec -e TENANT=acme php bin/console doctrine:migrations:diff \
    --em=tenant --configuration=config/migrations/tenant.php
```

Migrations are split: `migrations/control` runs once per deploy,
`migrations/tenant` runs once per tenant, and `bin/deploy` is what runs both.
See [Deploying](https://praesidiarius.github.io/plc-xivi-docs/running/deploying/).

**Each set is one baseline plus whatever has been written since.** The
fifty-one migrations written before 2026-08-19 were squashed into two, which
was possible only because no instance had been deployed and is therefore not
something that happens again (XIV-151, §4.2). If you are looking for why a
column exists and the baseline does not say, the original migration is in git:
`git log --diff-filter=D --name-only -- migrations/tenant`.

A dev tenant provisioned before that squash cannot be migrated forward, since
its `doctrine_migration_versions` names classes that are gone, so
`tenant:reset` it.

Every schema change lands for every customer, and a deploy walks their
databases one at a time with the instance still serving, so tenant migrations
are **additive only**: expand in this release, contract in a later one. `up()`
may not drop a table or a column, rename either, or add `NOT NULL` to an
existing column. `tests/Unit/TenantMigrationsAreAdditiveTest.php` refuses the
ones that do. The window this protects, and what the test cannot see, is
[§4.2](docs/architecture/deployment.md#42-what-a-deploy-has-to-do-and-where-each-part-of-it-runs-xiv-61).

## Tests and CI

```bash
bin/ci                # everything CI runs, in the same containers
bin/ci --no-build     # skip the two production image builds, the slow step
bin/ci --coverage     # measure coverage and hold it above the floor
```

GitHub Actions runs that same script rather than its own copy of the checks,
so nothing can drift between local and CI, and a green run locally means a
green run there.

**Two branches at once:** `git worktree add ../xivi-XIV-99 -b XIV-99/thing`,
then run `bin/ci` there. That directory gets its own compose project, ports,
tenant databases and dev image, so both suites run at the same time without
meeting (XIV-51, XIV-71); `bin/ci` refuses a second run in the *same* checkout
rather than letting the two interleave. Reach that worktree's stack by hand
with its own `bin/compose`.

**If two checkouts want the same ports, you are told which one has them**
(XIV-86). The offset is a checksum of the directory name into one of a hundred
buckets, so two directories can land on the same one; at seven worktrees that
happens about one time in five. `bin/compose up` and `bin/ci` check before
starting anything and refuse, naming the offset, the checkout holding it and
its directory, and printing the six exports that move this checkout somewhere
free:

```bash
export HTTP_PORT=8053 HTTPS_PORT=8453 HTTP3_PORT=8453 \
       DATABASE_PORT=5553 ADMINER_PORT=8153 MAILPIT_PORT=8253
```

Export them in the shell you run `bin/ci` from and they win over the derived
values, this check included. Without the check the loud half would be a failed
bind on port 443's stand-in; the quiet half is that `DATABASE_PORT` would
answer as the *other* checkout's Postgres, so anything you pointed at the
address `bin/compose` prints, PhpStorm's database panel or `psql`, would be
working on that checkout's tenants (brief §9.2).

The dev image is `<checkout>-xivi-php-dev`, so `xivi-xiv-99-xivi-php-dev` for
the worktree above, and a branch that changes the `Dockerfile` or the
entrypoint cannot alter what another checkout is running. It costs almost
nothing: every layer your build has in common with the main checkout's is
shared, so a worktree that changed nothing about the build is about 29 kB.

**When you remove a worktree, remove its image.** Nothing does it for you: the
name derives from the directory, so once the directory is gone there is
nothing left to work it out from. Read it off the summary first.

```bash
bin/compose down                     # in the worktree
docker image rm xivi-xiv-99-xivi-php-dev xivi-xiv-99-xivi-prod-check
git worktree remove ../xivi-XIV-99
```

`docker image ls 'xivi-*'` finds any you have already lost track of; they
share their layers with the main checkout's image, so the disk they return is
small.

`bin/ci` covers: `composer validate --strict`, a dependency vulnerability
audit, coding standards (`php-cs-fixer`, Symfony's ruleset plus the licence
header), deptrac module boundaries, PHPStan level 8, PHPUnit, and a build of
the **production** image. The last one is there because the dev image installs
dev dependencies and so proves nothing about what ships.

Before any of that it runs `bin/reconcile` inside the container, which makes
what the stack has cached match the tree being checked: vendor/ against
`composer.lock`, and the compiled service container against the configuration
it was built from (XIV-63). Starting a stack that is already running installs
and compiles nothing, so without this a merge that added or dropped a
dependency turned up as PHPStan errors about code. It costs about a second on
a warm stack, runs from the container entrypoint too, and can be run by hand,
`bin/compose exec php bin/reconcile`, after any merge that changes either.

`composer cs-fix` writes the formatting fixes. It cannot write the `@author`
annotation every class carries, because no fixer adds one, so a new class
needs it by hand; `.php-cs-fixer.dist.php` names the three Symfony rules
deliberately turned off and why.

Coverage is measured over `src/` and `packages/`, written to `coverage/`, and
gated by a floor in `bin/ci`, because a number nothing enforces drifts down
one uncovered branch at a time. Open `coverage/html/index.html` to see what is
not covered. Xdebug costs this suite about seven percent, because it spends
its time provisioning databases rather than executing PHP.

Individual pieces, if you want them on their own:

```bash
bin/compose exec php composer test      # PHPUnit
bin/compose exec php composer phpstan   # level 8
```

The functional tests provision real tenants: real databases and real
PostgreSQL roles. They cover the parts that would fail silently: two hosts
reaching two databases within one process, one tenant's credentials being
refused by another tenant's database, a session from one tenant being refused
by another, records and uniqueness not crossing tenants, and a full
encryption-key rotation.

A tenant is provisioned once per test **class**, and each test rolls back
afterwards rather than truncating (`dama/doctrine-test-bundle`). Speed is not
the point, since a truncate was already fast; a rollback also undoes field
*definitions*, which is what lets the metadata-editor tests share a database
with everything else. Tenant databases are left behind between runs on
purpose, and reclaimed the next time.

## Layout

```
src/                     the application: tenancy, the tenant registry, security, controllers
packages/core            the engine: metadata, field types, record storage
packages/contact         a module built on it (also article, order, invoice, voucher, knowledge)
packages/control-plane   the administration surface: provisioning, operators, the tenant list
```

Modules are Symfony bundles wired as Composer path repositories. A module may
depend on core, never on another module, and core may depend on neither the
modules nor the application, enforced by `deptrac.yaml` in CI rather than by
separate repositories (§3).

The control plane is the same kind of package pointed the other way: it may
depend on the application, and the application may never depend on it. The
half of the control-plane database a *customer's* request needs, which tenant
owns this hostname and the credential to reach their database, is
`App\Registry` and stays in `src/`, because an instance serving customers
cannot boot without it (§3.1).

That rule covers `config/` too, and deptrac cannot see it; `AGENTS.md` has the
detail, and `tests/Unit/Deployment/ControlPlaneIsOptionalAtBuildTimeTest.php`
is what fails when it is broken (XIV-96, §4.4).

## Conventions

Branch naming, the changelog entry every branch owes, the licence header, how
a migration is started and the rest are in [`AGENTS.md`](AGENTS.md). It is
short and it is the same list for a person as for an agent.

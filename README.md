# Xivi 17

[![CI](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml/badge.svg)](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/Praesidiarius/plc-xivi/branch/main/graph/badge.svg)](https://codecov.io/gh/Praesidiarius/plc-xivi)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.dist.neon)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A metadata-driven CRM/ERP engine in Symfony, plus a CRM built on top of it to keep
the engine honest.

> **Status: early, but no longer a skeleton.** Multi-tenancy, sign-in and the
> metadata engine are built and tested. A customer can list, filter, create, edit,
> delete, export and import records, change their own fields, and read what
> happened to a record — every page of it built from definitions in their own
> database. An administrator can manage the people who sign in and decide, per
> module and per action, what each of them may do. What is missing is templates
> deciding which modules a customer is given, and a second module.

The design is written down first and the code follows it. Read
**[docs/architecture.md](docs/architecture.md)** before anything else; it explains
the decisions this repository is an implementation of, and the code comments cite
its section numbers. What has actually been built, and when, is in
**[CHANGELOG.md](CHANGELOG.md)** — which holds what has not shipped yet, and
indexes the released versions under [docs/changelog/](docs/changelog/).

The version is `17.0.5`, shown in the footer of every page. The leading **17 is a
generation, not a semver major** — it says which Xivi this is and changes only when
there is a new one, so breaking changes inside a generation do not touch it. The
rest moves on *release*, not on feature: work accumulates under *Unreleased* and
cutting a version is something a person does deliberately.

## What exists today

**Tenant resolution.** One deployed codebase serves every customer. The tenant is
resolved per request from the `Host` header against a control-plane database, and
each tenant's data lives in **its own PostgreSQL database** — isolation is physical,
not a `WHERE tenant_id = ?` you can forget (§4).

**A control plane, not config files.** Domains, database DSN, status, plan and
enabled modules are rows, so onboarding a customer is a command rather than a
deploy. There are deliberately no per-customer `.env` files.

**Per-tenant database credentials.** Every tenant gets its own PostgreSQL role, and
its database revokes all rights from `PUBLIC`. A bug that hands Doctrine the wrong
DSN fails to connect instead of quietly reading another customer's data. Passwords
are generated with `random_bytes`, stored encrypted (libsodium), and never printed.

**Sign-in, per tenant.** Users live in each customer's own database, so the same
email address is a different person at a different customer. Sessions are stamped
with the tenant that created them and refused anywhere else, because Symfony
restores a session by user *identifier* and those identifiers collide across
tenants (§8.2).

**A metadata-driven engine.** A module declares its fields; installing it for a
customer writes those definitions into that customer's database and creates its
table. From then on the definitions drive validation and storage, and the customer
can add fields of their own. `packages/contact` is the whole first module — a
declaration and nothing else, no entity, no repository, no controller and no form
class (§5). One generic controller and one generic form serve every module,
building each page from that customer's definitions.

**Presets, so installing is a choice.** A module ships named subsets of its own
fields — Contact has `basic` and `extended` — picked at install time with
`--preset` (§6.1). A preset names fields the declaration already contains rather
than carrying its own, so a field key means one type across the whole module no
matter which preset a customer got.

**Child collections.** A contact's addresses are the same kind of thing as the
module itself, not a special case: their own table with a real foreign key, their
own definitions, edited inline with the parent and soft-deleted along with it
(§5.1). Contact declares them and still contains no code.

**One module, more than one kind of record.** A contact is a person *or* a
company, each variant with its own fields, in a single module — because "pick a
contact" has to stay a plain foreign key, and two modules would make every link to
one polymorphic (§5.5). A person links to their company by id; the company's
people are a query, not a second copy of the answer.

**History per module, not one table for everything.** Every write goes through a
single writer, which records one entry per action in that module's own history
table (§5.2). Per module because a shared polymorphic table cannot carry a foreign
key, which is exactly what made the last one rot at 60 million rows; per action
because a timeline nobody can read is a feature nobody uses. A record's timeline
is on its page.

**Filtering, sorting and paging.** Compiled from the customer's definitions rather
than written per module, so a field they added this morning is filterable this
afternoon (§5.3). A filter bar, sortable columns, and a filtered list that is a
URL you can send to a colleague. Filtering by a collection compiles to an `EXISTS`
semi-join, and each field type owns which operators apply to it.

**An editor for the metadata.** Admins add, relabel and remove fields on any
shape, collections included (§5.4). Changes that would strand data are refused
with a count rather than performed — turning on required or unique when existing
records would fail it — and removing a field leaves its values untouched, so
re-adding the key brings them back.

**Export, and import back.** A module's records as a spreadsheet, one sheet per
shape, carrying whatever the list was filtered to — and the same file back in
(§5.6). Every row is validated by the same rules the form uses and the file is
applied in one transaction or refused whole, because half an import is a state
nobody can reason about. **A check is the import, rolled back** rather than a
second code path, so it catches what only a write can: two rows of one file
claiming the same unique email collide on the second, because by then the first
one is really there.

**Users, managed from the application.** An administrator adds colleagues,
makes them administrators, deactivates the ones who leave and resets a lost
password; everybody can change their own password on their account page (§8.4.1).
A generated password has to be replaced before the account is usable — until it
is, every page leads back to the account page, because a password an administrator
read off a screen and passed on is one two people know.
Nobody is ever deleted — records carry the id of whoever owns them and history the
id of whoever changed them, so deactivating keeps all of it attributable and is
reversible. Every refusal here is about lock-out: you cannot deactivate yourself,
demote yourself, or leave the installation with no administrator.

**English and German** (XIV-8). Each person picks their language on their account
page; the login page follows the browser, since there is nobody to ask yet. A
module's own labels are seeded from its catalogue at install time —
`tenant:module:install acme contact --locale=de` gives that customer *Kontakte*
and *Vorname* — and from then on they are the customer's data, renameable and no
longer following the catalogue: resolving a label on every render would overrule
that rename every page load. Which means labels are one language per *tenant*,
while everything else follows each reader. A key with no German translation fails
the build rather than falling back quietly.

**Money that adds up** (§5.9). Lines carry a quantity and a price; the order's
net, VAT and gross are derived from them on save and *stored*, because a price
list that changes must not restate an invoice already sent. The arithmetic is the
server's, and the same derivers run behind the figures that update while you
type.

**Documents from a Word template** (§5.7). A tenant uploads a .docx with
placeholders, and a record fills it in — as Word or as a PDF. A row of a table
containing a collection's marker repeats once per line, so an invoice's lines
come out as an invoice's lines.

**Numbers, lifecycles and links** (§5.10, §5.8). Documents are numbered from
the customer's own counters; a record moves through declared states rather than
having one written into it; and a reference is a link to the record it names,
offered only where the reader may open it.

**Mail, as the customer** (§8.7, §5.13–§5.15). Email templates are written in the
application in Markdown, sent from a record with the recipient taken from the
module's own declaration, and a generated document can go out attached. Outside
production nothing can reach a real mail server at all.

**A store, so a tenant installs modules themselves** (§6.3). What this build
offers, what each module is, and a wizard that picks a preset — with no shell and
no operator in the loop.

**Colleagues are invited, not handed a password** (§8.8). Adding a user sends a
link that works once and for twenty-four hours; no password is generated at all.

**Permissions, on two axes** (§8.4, §8.4.3). What can be done *to a module* is a
closed list — view, list, add, edit, delete, export, import, templates, document,
email templates, send email, transition — so that half of the set is the list
crossed with the modules a customer has installed, with nothing to seed and
nothing to keep in step. The store is the second axis and deliberately not part of
that crossing: browsing is about no module, and installing is about one you have
not got. Grants are given to a group and inherited by
its members, or to one person on top of that; they only ever add, so resolving
somebody is a maximum rather than a precedence table nobody can hold in their
head. Mutating and reading alike can be narrowed to **only the records that
person owns**, which is a WHERE clause rather than a check after loading — a page
filtered after fetching shows four rows under a total that says twenty-five.
Administrators bypass all of it, because a permission that can be taken from the
last administrator is a locked-out installation. Everybody else starts with
nothing.

**Classic PHP execution, on purpose.** FrankenPHP runs without worker mode, so no
PHP state survives a request boundary and cross-tenant leakage (§7.4) is
structurally impossible for web requests. It costs a few milliseconds per request
and is worth it. See the comment in `frankenphp/Caddyfile`.

**Server-rendered, no build step.** Twig, Bootstrap's CSS and Bootstrap Icons,
self-hosted through AssetMapper — no Node, no bundler, and no CDN calls from a
customer's browser. The forms work without JavaScript.

## Requirements

Docker and Docker Compose. Nothing else — there is no host PHP or Composer step.

**Working here with a coding agent?** [`AGENTS.md`](AGENTS.md) is the orientation
it should read first — the conventions and the two or three things that mislead
somebody who has only read the code. It is short, and it is worth a human's two
minutes as well.

**Use `bin/compose`, not `docker compose`.** It is a thin wrapper that forwards
every argument through — `bin/compose up -d --wait`, `bin/compose logs -f php`,
`bin/compose down` — after pointing Compose at *this* checkout's stack.

That matters because a checkout is the unit of isolation here: the compose
project, the published ports, the bind mount, the test tenant prefix and the dev
image are all derived from the directory, so a git worktree is a first-class
second stack (XIV-51, XIV-71). A bare `docker compose` knows none of it, and in a
worktree it collides on port 443, runs the suite against the main checkout's
tenant databases, and rebuilds the dev image every *other* checkout is running —
the last two quietly. The wrapper also runs the container as you rather than as
root, so files it creates belong to you; there is nothing to put in `.env.local`.

`bin/compose` with no arguments answers "which stack is this, and where":

```bash
bin/compose
# checkout   plc-xivi (the main one)
# project    plc-xivi
# image      xivi-php-dev
# app        https://localhost:443
# ...
```

The derivation lives in `bin/lib/stack-env.sh` and `bin/ci` reads the same file,
so the suite and your shell cannot end up on different stacks (XIV-55).

## Quickstart

```bash
bin/compose up -d --wait
```

That builds the image, starts PostgreSQL, installs dependencies and applies the
control-plane migrations. The app is then on <https://localhost> with a self-signed
certificate, so expect a browser warning (or use `curl -k`).

Provision a tenant with an admin user, then sign in at `https://acme.localhost`:

```bash
bin/compose exec php bin/console tenant:provision acme acme.localhost \
    --name='Acme AG' --admin-email=you@example.com
# ... Password: <generated, shown once>
```

To check the plumbing without a browser:

```bash
curl -k -H 'Host: acme.localhost' https://localhost/_tenancy/whoami
# {"tenant":"acme","status":"active","database":"tenant_acme"}
```

`*.localhost` resolves to `127.0.0.1` on most systems; if yours disagrees, add the
hostname to `/etc/hosts`. Caddy matches a single wildcard label, so dev hostnames
are one level deep — `acme.localhost`, not `www.acme.localhost`.

`/_tenancy/whoami` is a diagnostic that reports the resolved tenant and asks
PostgreSQL which database the connection actually reached. It is served only when
debug is on.

## Throwing a test tenant away, and getting a fresh one

```bash
bin/compose exec php bin/console tenant:reset bulk \
    --modules=contact,article,order,invoice --records=300 --seed=24
# ... Password: <generated, shown once>
```

One command for what used to be six: the existing tenant is deprovisioned, a new
one is provisioned and migrated, the modules are installed, each is filled with
demo records, and an admin user is created and their password printed — which is
the whole point, since a fresh tenant nobody can sign in to is not much use.

Worth knowing:

- **Module order is worked out for you.** An invoice needs an order and an order
  needs a contact; list them in any order you like. A module that is missing a
  requirement, or that this build does not carry, is refused *before* the existing
  tenant is destroyed.
- `--modules` defaults to every module in the build, `--records` to 50, and it is
  **one number applied to each module** — 300 contacts *and* 300 articles *and*
  300 orders. Different sizes per module are what `tenant:demo:generate` is for.
- `--seed` makes the records identical every run, which is what makes "it broke
  on record 4,312" something somebody else can see too.
- Hostnames default to `<slug>.localhost`; pass your own as extra arguments.
- **It destroys before it builds, and no flag changes that.** The slug, the
  hostnames, the database and the Postgres role all belong to the tenant being
  replaced, so the drop genuinely is the first act. If something fails after it,
  the command prints what is gone, what is standing and the line to run again —
  §4.1 argues why that is the answer rather than a temporary slug and a swap.
- **No `--no-debug` needed at any size.** Turning `--records` up used to exhaust
  the memory limit in Symfony's profiler collectors, since the whole rebuild is
  one process; they are emptied as it goes now.
- **Development only.** It is excluded from the production image in
  `config/services.yaml`, beside the demo commands.

To remove a tenant without building it again — including on a production
installation, where `tenant:reset` does not exist:

```bash
bin/compose exec php bin/console tenant:deprovision bulk
```

It names the database, the role, the hostnames and how many records are in there,
then asks; pressing return is *no*. An unattended run needs `--force` — `-n` on
its own is refused rather than answered with a default. It drops the database and
the role and deletes the row, and there is no undo: take the dump first.

## Looking at a tenant's database

```bash
bin/compose --profile tools up -d adminer   # http://127.0.0.1:8080
```

Sign in with server `database`, user `app`, password `!ChangeMe!` (or whatever
`POSTGRES_PASSWORD` says), and pick the database: each tenant is its own, named
`tenant_<slug>` — `tenant_acme`. That is §4's isolation seen from the outside.

Note what that login is. `app` owns the cluster and can read every tenant, which
is the operator's view and not the application's: the app connects as the tenant's
own Postgres role, which can reach exactly one database. Those role passwords are
encrypted in the control plane and nothing prints them, so Adminer cannot be used
to check that isolation — only to look at data.

Behind a profile, so it is opt-in and never starts as part of the stack or of CI.
Bound to the loopback, because it is an unauthenticated door to a database server.

## Looking at mail that was sent

Mail sent in development goes to [Mailpit](https://mailpit.axllent.org/), which
starts with the stack. Open it at <http://127.0.0.1:8025> — it shows the rendered
HTML, the plain-text alternative and the raw source of every message, which a log
line cannot.

The dev `MAILER_DSN` names it (`smtp://mailpit:1025`, in `.env.dev`), so nothing
you send by accident leaves this machine. Its inbox is kept in memory only, so a
restart empties it.

**It is visibility, not a guarantee** — but the guarantee now exists beside it
(XIV-37). A catcher sees only what is pointed at it, so pointing a DSN at a real
server would once have reached real people with this container none the wiser.
`App\Mail\NonProductionMailGuard` is registered ahead of every transport factory
symfony/mailer ships and refuses, outside production, to *build* anything that
could deliver — this catcher and the loopback excepted in dev, and nothing at all
excepted in test. That covers a tenant's own SMTP credentials too, since those
become a DSN through the same factory. See brief §8.7.

Where a customer's mail comes from is their own setting, on the company profile:
a sender address, and optionally their SMTP server, in which case the mail is
genuinely from them. Without a server it goes out through this installation, with
their name on it and their address to reply to. `MAILER_SENDER` is the address
this installation sends as; leaving it empty uses `no-reply@` at the hostname
that customer reaches you on.

If two checkouts are running, the second's UI is not on 8025 — `bin/ci` derives
the port from the directory the same way it derives the compose project and the
database port, so the stacks do not collide. Set `MAILPIT_PORT` to pin it.

Only the web UI is published, on the loopback, for adminer's reason: it is an
unauthenticated reader of every message this machine has sent. SMTP is reachable
on the compose network alone.

The test suite does not use it and should not be made to — see the note beside
`MAILER_DSN` in `.env.test` for why eight parallel workers and one shared inbox
is a race rather than an assertion.

## Symfony AI Mate

> **Development only.** Mate is a local MCP server that hands a coding agent this
> application's Monolog logs and Symfony profiler. Those contain tenant hostnames,
> executed SQL and request payloads — real customer data on any deployment that has
> any. Never run it against production, and never expose it beyond the machine you
> are developing on. Upstream says the same.

It is required as a **dev dependency**, so `composer install --no-dev` leaves it out
and it is absent from the production image. It registers no bundle: `mate serve` is
its own process, speaking MCP over stdin and stdout, and it opens no port.

It earns its place here because `var/` is an anonymous volume in the dev container
and so is invisible from the host — without it, logs and profiles cannot be read
from outside the container at all.

Nothing it generates is committed (see `.gitignore`), because the command depends
on how you run PHP and the agent files depend on which agent you use. Set it up
yourself, **both commands, every fresh checkout**:

```bash
bin/compose exec php vendor/bin/mate init
bin/compose exec php vendor/bin/mate discover
```

Two things to know, both of which cost an afternoon once:

- `mate init` writes `"command": "php"` into `mcp.json`. There is no host PHP here,
  so change it to `bin/compose exec -T php vendor/bin/mate serve --force-keep-alive`.
  Your MCP client runs that from whatever it considers the working directory — give
  it an absolute path if that is not this checkout, or it will attach to another one.
- `mate discover` is a **manual step**. Its Composer plugin is deliberately left out
  of `allow-plugins` so that nothing of Mate's runs during `bin/ci`; the price is
  that without `discover`, the extensions stay unregistered — including this
  project's own — and the tool list silently stays short.

`mate init` also writes an `AGENTS.md` telling agents to prefer its tools over the
CLI. Take that as a suggestion — for most of what this project needs, a shell in
the container is the better instrument.

### This project's own tools (XIV-76)

`packages/xivi-mate` is a committed Mate extension, wired in as a path
repository like the modules and required as a **dev** dependency, so it is absent
from a production build entirely. `mate discover` enables it; check with:

```bash
bin/compose exec php vendor/bin/mate mcp:tools:list
```

| Tool | What it answers |
| --- | --- |
| `xivi-tenants` | Which tenants exist, their status and hostnames, whether each schema is current, what each has installed |
| `xivi-tenant-shapes` | What one tenant's modules **actually** look like: every field, type, options, variants, collections. Not the blueprint — see §6.1 |
| `xivi-modules` | The module catalogue and each module's state, which is what decides whether the store offers it |
| `xivi-tenant-reset` | **Destructive.** Rebuilds a dev tenant end to end; the result names what was destroyed |
| `xivi-tenant-deprovision` | **Destructive and irreversible.** Needs `force=true`; the command refuses an unattended run without it |

Every one of them has a console twin, so a dropped MCP server costs convenience
and nothing else: `bin/console tenant:inspect [slug] [module] [--modules] [--json]`,
`module:list`, `tenant:reset`, `tenant:deprovision`. Nothing here is tool-only,
and §6.4 of the brief argues why that is a rule rather than a nicety.

## Commands

| Command | What it does |
| --- | --- |
| `tenant:provision <slug> <hostname...>` | Creates the row, the role, the database and its schema; `--admin-email` adds the first user |
| `tenant:deprovision <slug>` | Removes the row, the database and the role. Asks first, defaults to *no*, and needs `--force` to run unattended |
| `tenant:reset <slug>` | **Dev only.** Deprovision, provision, install `--modules`, generate `--records`, print the admin password |
| `tenant:user:create <slug> <email>` | Adds a user to one tenant; `--admin` grants ROLE_ADMIN |
| `tenant:module:install <slug> <module>` | Installs a module for one tenant: its table and field definitions; `--preset` picks which fields, `--locale` which language its labels are seeded in |
| `tenant:list` | Shows the registry |
| `tenant:inspect [slug] [module]` | **Dev only.** Tenants with their schema state and installed modules; with a slug, that tenant's real field definitions. `--modules` for the catalogue, `--json` for what the MCP tools return |
| `tenant:migrate [--slug=]` | Applies tenant migrations to every tenant. Exits **0** when they are all at the latest version, **1** when it could not run at all (empty registry, unknown slug) and **3** when a tenant failed while the others succeeded. `bin/deploy` runs it |
| `deploy:check-secrets` | Refuses in production on a secret still set to the placeholder committed in `.env`. The container entrypoint runs it on every start; it does nothing outside `APP_ENV=prod` |
| `deploy:check-hosts` | Prints the hostnames this installation answers to, and names every tenant the pattern would answer with a 400. `bin/deploy` runs it and stops on exit 3; the entrypoint runs it and only prints |
| `tenant:usage:collect [--slug=]` | Counts each tenant's users, last sign-in and records into the control plane, one tenant at a time; put it in cron — see below |
| `signup:provision [--email=]` | Turns confirmed self-service signups into tenants, one at a time, and invites each first user; put it in cron — see below |
| `tenant:permissions:grant-all <slug>` | Grants every action on every installed module to one tenant's non-admin users; the upgrade path for an installation that predates permissions, and the way back into a locked-out one |
| `tenant:rotate-secrets` | Re-encrypts stored passwords with the active key |
| `control:operator:create <email>` | Creates somebody who can sign in to the control plane; asks for the password, or takes `--password` for a scripted run. Refuses an address that already has an operator |
| `control:operator:list` | Who can sign in to the control plane, revoked accounts included and marked |
| `control:operator:revoke <email>` | Withdraws an operator's access, keeping the account. Refuses to revoke the last one who can still sign in |
| `control:operator:restore <email>` | Gives a revoked operator their access back, with the password they had |
| `control:operator:password <email>` | Sets a new password, signing out every session that account had; asks for it, or takes `--password` |
| `doctrine:migrations:migrate --em=control` | Control-plane schema only |

Any console command can be pointed at one tenant's database with the `TENANT`
environment variable — a command has no Host header to resolve one from:

```bash
TENANT=acme bin/compose exec php bin/console doctrine:schema:validate --em=tenant
```

That is also how a tenant migration is generated, since the diff needs a database
to compare against:

```bash
bin/compose exec -e TENANT=acme php bin/console doctrine:migrations:diff \
    --em=tenant --configuration=config/migrations/tenant.php
```

Migrations are split: `migrations/control` runs once per deploy, `migrations/tenant`
runs once per tenant. **`bin/deploy` is what runs both**, in that order, once per
release — see [Deploying](#deploying) below.

Every schema change lands for every customer, and a deploy walks their databases
one at a time with the instance still serving, so tenant migrations are
**additive only**: expand in this release, contract in a later one. `up()` may not
drop a table or a column, rename either, or add `NOT NULL` to an existing column.
`tests/Unit/TenantMigrationsAreAdditiveTest.php` refuses the ones that do. The
window this protects, and what it cannot see, is
[§4.2](docs/architecture.md#42-what-a-deploy-has-to-do-and-where-each-part-of-it-runs-xiv-61).

## Configuration

Set in `.env` for development; override with real environment variables or the
Symfony secrets vault in production.

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | Control-plane database |
| `TENANT_DSN_TEMPLATE` | Template for new tenant DSNs; `{database}` and `{user}` are substituted |
| `TENANT_ADMIN_DSN` | Used **only** by provisioning, for `CREATE DATABASE` / `CREATE ROLE` |
| `TENANT_SECRET_KEYS` | `{"id": "base64 32 bytes"}` — keys that encrypt tenant passwords |
| `TENANT_SECRET_KEY_ID` | Which of those keys new values are written with |
| `XIVI_TRUSTED_DOMAINS` | The domains this installation answers to, comma separated. **Empty means the `Host` header is not checked at all** — see below |
| `TRUSTED_PROXIES` | Addresses of a reverse proxy in front of this application. Empty means `X-Forwarded-*` is ignored — see below |
| `CONTROL_PLANE_HOST` | The hostname the control plane is served on — see below |
| `SIGNUP_HOST` | The hostname the public signup endpoint is served on. **Empty means no signup route exists at all** — see below |
| `XIVI_SIGNUP_SECRET` | The shared secret the calling site presents in `X-Xivi-Signup-Key` |
| `XIVI_SIGNUP_PLANS` | Which plans self-service may ask for, comma separated, most ordinary first |
| `PRICE_CURRENCY` | The ISO 4217 code this deployment's module price list is in. **Empty means prices render as bare numbers** — see below |

### Which hostnames this installation answers to

**Empty is the default and it means the `Host` header is not checked**, which is
what a fresh checkout and the test suite need and is how this application behaved
before XIV-93. On a real deployment, set it:

```dotenv
XIVI_TRUSTED_DOMAINS=xivi.app,1plc.ch
```

Each entry admits the domain **and every hostname under it**, so `xivi.app`
covers `xivi.app`, `acme.xivi.app` and `acme.eu.xivi.app`. Names, not patterns —
this is deliberately not a regular expression, because every dot in a
hand-written one matches any character and `control.example.com` would then also
accept `controlXexample.com`, a name somebody else can own. The application
writes the expressions and anchors them.

**You do not list the control plane, the signup host, the loopback or the
container name.** Every entry of `app.system_hosts` is added for you, so setting
this variable cannot lock an operator out of their own console. What you have to
get right is the domain your **customers** are on — including, if self-service
signup is switched on, the parent domain of `SIGNUP_HOST`, since that is where a
provisioned signup is routed (`signup.xivi.app` puts customers at
`acme.xivi.app`, so `xivi.app` is the entry you want).

**What happens if it is wrong.** A hostname outside the list is answered with an
empty **400 Bad Request** by the framework, before any of this application runs:
no page, no header named, nothing in the body. That is the correct response to
send a stranger and a terrible one to debug, so three things say it out loud
instead:

- `tenant:provision` **refuses** to create a customer on a hostname this
  installation would refuse, naming the variable. So the mistake normally fails
  at a console rather than at a customer's browser.
- `bin/console deploy:check-hosts` prints what the installation answers to and
  names every tenant that would get a 400. `bin/deploy` runs it before the
  serving containers are replaced and stops the deploy (exit 3) if a tenant that
  is serving today would be refused; the container entrypoint runs it on every
  start and only prints, because one customer's hostname must not stop every
  container from coming up.
- A refused request writes one `error` line naming the host that was sent, what
  the pattern admits and what to change.

The reasoning, and why the pattern is composed rather than configured, is
[§4.3](docs/architecture.md#43-which-hostnames-this-installation-answers-to-xiv-93)
of the brief.

### If there is a load balancer in front

`TRUSTED_PROXIES` is empty by default and that is correct for the shipped
topology: FrankenPHP terminates TLS itself, so nothing is in front of it and
`X-Forwarded-*` headers are ignored — which is the safe default, and also the
reason a deployment that *does* have a proxy sees the proxy's address everywhere
instead of the client's.

```dotenv
TRUSTED_PROXIES=10.0.0.0/8          # or private_ranges, or REMOTE_ADDR
```

Which forwarded headers are then believed is decided in
`config/packages/framework.yaml` and is not a deployment setting:
`X-Forwarded-For`, `-Proto` and `-Port` are trusted, `X-Forwarded-Host` and
`X-Forwarded-Prefix` deliberately are not. Tenant routing *is* the `Host` header,
and most proxies append forwarded headers rather than replacing them, so
believing `X-Forwarded-Host` would let a caller pick which customer they are and
which host appears in a mailed invitation link.

**Set this if you have a proxy.** Without it, absolute URLs generated while
serving — the invitation links in §8.8's mails above all — come out as `http://`
behind a TLS-terminating balancer.

### The control plane needs a hostname of its own

**This is a deployment step, and skipping it means the control plane is served on
`control.localhost`,** which is the development default and is not a hostname
anybody will reach in production.

Set `CONTROL_PLANE_HOST` to a name this installation answers on and that **no
customer is served on**. That one variable is all there is to it: the value is
written into `app.system_hosts` in `config/services.yaml`, which is the list of
hosts served *without* a tenant — so the host that serves the control plane is by
construction one that resolves no customer, and there is no second place to keep in
step with the first. Point DNS and the certificate at it like any other host; there
is no separate deployment, since it is the same application.

Two consequences worth knowing before you pick a name:

- **Nothing else is served there.** A request to that host for anything but
  `/control/…` answers 404, and `/control/…` answers 404 on every other host.
- **`tenant:provision` refuses to route a customer to it**, along with every other
  entry in `app.system_hosts`. Given what is behind it, prefer a name that is not
  guessable from the customer-facing domain.
- **The hostname is not a boundary.** Anybody who can set `Host:` to it reaches
  the sign-in page from any address that terminates the connection, and
  `XIVI_TRUSTED_DOMAINS` does not change that — the control-plane host is one of
  the names this installation answers to by construction. What keeps a customer
  out is the firewall, the provider and `ROLE_OPERATOR`, all of which apply to a
  forged `Host` exactly as they apply to a real one
  ([§4.3](docs/architecture.md#43-which-hostnames-this-installation-answers-to-xiv-93)).

Then create the first operator — there is no sign-up, and the control plane refuses
everybody until this has been run:

```bash
bin/compose exec php bin/console control:operator:create you@example.com
```

Withdrawing one is `control:operator:revoke`, and it deactivates rather than
deletes — the account stays in `control:operator:list`, marked, and
`control:operator:restore` puts it back. **The last operator who can still sign
in cannot be revoked**, because there is no sign-up, no invitation and no
password reset here to get back in through; create the successor first. A
password is rotated with `control:operator:password`, which signs out every
session that account had.

The reasoning, and why an operator is not a promoted user of some tenant, is in
§8.9 of the brief.

### Self-service signup is off unless you switch it on

**Leaving `SIGNUP_HOST` empty means there is no signup endpoint** — not a route
that refuses, but a routing table with nothing in it. That is the default, and it
is the right one for an installation whose customers are provisioned by hand.

To switch it on, two variables:

```dotenv
SIGNUP_HOST=signup.example.com
XIVI_SIGNUP_SECRET=…   # php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

- **It must not be `CONTROL_PLANE_HOST`.** That host answers to people who can see
  every customer; this one is configured into your marketing site. The application
  refuses to build a routing table when the two are equal.
- **The secret is not optional.** An empty one refuses everybody, and the
  application will not start with `SIGNUP_HOST` set and no secret.
- **`MAILER_SENDER` is optional here as everywhere else.** An empty one sends the
  confirmation from `no-reply@` at `SIGNUP_HOST`, which is §8.7's fallback with
  the tenant's hostname replaced by this one — honest for the same reason, since
  that is the name the visitor's site just posted to.

What the endpoint does is deliberately small: it records a signup and mails the
address a confirmation link. It creates **no tenant, no database and no role** —
that is XIV-98, and it runs where an operator can see it. The reasoning, and why
a public surface never provisions directly, is §8.12 of the brief; the request and
response shapes are documented on `Xivi\ControlPlane\Controller\SignupApiController`.

The landing page that posts to it is XIV-65 and is not in this repository. Prefer
a server-side post from that site over a browser posting directly: the credential
then lives on a server rather than in a page's source, and this endpoint stays off
any public CORS origin list — there is deliberately no CORS configuration for it.

### Keeping the usage figures fresh

The tenant list shows what each customer uses — users, last sign-in, records — and
**nothing collects those figures for you**. Until the command has run, every row
reads *not collected yet*, which is honest and is not useful:

```cron
17 3 * * *  cd /srv/xivi && bin/console tenant:usage:collect
```

There is no worker process here and no message consumer, so this is a cron entry
rather than a queue — the same constraint that makes mail synchronous (§8.7). The
cadence is yours: the page states how old what it shows is, so hourly and weekly
both tell the truth about themselves. A tenant whose database cannot be reached is
recorded as *could not be read* and the run carries on with the rest, then exits
non-zero so that cron mails somebody. The reasoning, and why the page does not
fetch this itself, is §8.11 of the brief.

### Turning self-service signups into customers

Signup records a request and **provisions nothing** — the endpoint is anonymous
and the thing that creates a customer holds `TENANT_ADMIN_DSN`, so the two are
deliberately kept apart (§8.12). Nothing happens to a confirmed signup until this
runs:

```cron
*/5 * * * *  cd /srv/xivi && bin/console signup:provision
```

Only needed on a deployment that has set `SIGNUP_HOST`. The cadence is yours and
is a customer-facing latency rather than a housekeeping one: somebody who has
just confirmed their address is waiting for the mail this sends, so every five
minutes is a better default here than the nightly one above. It is a cron entry
rather than a queue for the same reason everything else here is — there is no
worker process and no message consumer in this deployment (§8.7).

Each run creates a role, a database, a schema and a first administrator, then
mails that person an invitation link; **no password is generated or printed**
(§8.8). A signup that fails is recorded against its own row, the run carries on
with the rest, and it exits non-zero so that cron mails somebody. Running it
again is safe: a tenant left half-made by a run that died is cleared and rebuilt,
and one that is already standing is finished rather than duplicated. A
half-provisioned customer also appears at the top of the tenant list, named in
its banner, so the failure is visible to somebody who never reads a cron mail
(§8.10). The whole design is §8.14 of the brief.

**It is the privileged half of the feature.** When the public and internal
deployments are separated it belongs on the internal one; today it needs
`TENANT_ADMIN_DSN` in whatever environment the cron runs in.

### What this deployment charges for a module

The prices themselves are **not** environment variables. They live on the
control-plane `module` row and an operator sets them at `/control/modules`, or
with `module:price` — because a price somebody has to edit in a file is a price
nobody can change without a deploy (§6.5).

`PRICE_CURRENCY` is the one part that is a deployment fact, and it is **not** the
currency on a customer's profile: that one is about the invoices *they* write
(§8.6), while this is what the company running Xivi charges. A deployment picks it
once, and changing it invalidates every figure on the list at the same moment —
49.00 CHF and 49.00 EUR are not the same offer — so it is a re-pricing exercise
rather than an edit between two customers.

```dotenv
PRICE_CURRENCY=CHF
```

Left empty, prices are shown as plain numbers and the operator screen says which
variable to set. Nothing is guessed: a currency guessed for somebody is wrong
quietly.

### Before deploying anywhere real

The values committed in `.env` are placeholders and are public. Replace at minimum
`APP_SECRET`, `TENANT_SECRET_KEYS`, `CONTROL_PLANE_HOST` and the PostgreSQL
password, and set `XIVI_TRUSTED_DOMAINS` — until you do, this installation answers
to any hostname that reaches it, and any of them can end up in an invitation link.

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # a TENANT_SECRET_KEYS value
```

`TENANT_ADMIN_DSN` should name a role with `CREATEDB` and `CREATEROLE` rather than a
superuser, and ideally should not be present in the web processes' environment at
all.

**Two of those are enforced rather than advised.** An instance starting with
`APP_ENV=prod` on the `APP_SECRET` or the `TENANT_SECRET_KEYS` committed in `.env`
**refuses to start**, naming the variable and printing the command that generates a
real one. The check is `deploy:check-secrets`, the entrypoint runs it before
anything touches a database, and it does nothing at all in development or in the
test suite — both of which run on those placeholders on purpose.

The failure it exists for is quiet: the image build compiles `.env` into
`.env.local.php`, a real environment variable overrides it, and a deployment that
supplies none runs on a published secret while looking perfectly healthy.

### Deploying

There is no deploy tool here yet — which host, which registry and how a rollback
works are still open on XIV-61. What *is* here is the step a deploy has to run,
whichever tool eventually does it:

```bash
# in a one-shot container, from the image being released,
# with the deployment's environment, before the serving containers are replaced
bin/deploy
```

It checks the secrets, migrates the control-plane database, checks that every
customer is on a hostname this installation answers to, then migrates every
tenant database, and stops on the first failure. **Nothing else runs the tenant
migrations** — the entrypoint deliberately does not, because it runs on every
container start rather than once per deploy, and at fifty customers that turns a
restart into an outage. The full argument, and the additive-only migration window
the ordering depends on, is
[§4.2](docs/architecture.md#42-what-a-deploy-has-to-do-and-where-each-part-of-it-runs-xiv-61).

A non-zero exit means do not switch traffic. **3** in particular means some tenants
migrated and some did not; the output names them and prints the `--slug` line to
retry each one. `deploy:check-hosts` uses the same 3 for the same reason — some
customers are on hostnames this installation would answer with a 400 — and names
them too.

### Two images, and which one goes where

`docker build` has two production targets, and they differ in one thing: whether
the administration surface is in the image.

```bash
docker build --target frankenphp_prod   -t xivi-internal .   # everything
docker build --target frankenphp_public -t xivi-public   .   # no admin surface
```

`frankenphp_public` is what a **customer's hostname** is served from. It contains
no operator console, no signup intake, no provisioning and no `control:*`
commands — not switched off, *absent*: from the filesystem, from the autoloader
and from the compiled container. Its `security.firewalls` is `["dev","main"]` and
its router has no route under `/control`. The build refuses to finish if any of
that is untrue, so you do not have to take this paragraph's word for it:

```bash
docker run --rm --user root --entrypoint sh xivi-public -c 'ls /app/packages'
```

`frankenphp_prod` is what the **control-plane hostname** is served from, what
`bin/deploy` runs out of, and what `bin/compose` builds for development. Nothing
about it changed.

Both talk to the same control-plane database, so the boundary worth having is not
the network — it is the database user. Give the public instance one of its own:

```bash
bin/console deploy:registry-grants xivi_public   # prints the SQL; run it as a DBA
```

It ends up with `SELECT` on the tenant registry and nothing else — no writes, no
DDL, and no access at all to the `operator` table. **The customer-facing image
therefore does not run the control-plane migrations**: it checks that somebody
else has and refuses to start if not, so `bin/deploy` has to run before the public
containers are replaced. That was already the right order; it is now enforced.

A single-instance deployment — one image, one database user — keeps working
exactly as it did. All of the above is opt-in by building the second target. The
argument for it, and the complete list of what the public image *does* still
contain, is
[§4.4](docs/architecture.md#44-two-images-what-a-customers-instance-is-built-without-xiv-96).

### Rotating the encryption key

Stored secrets record which key wrote them, so both keys are valid during the
changeover and the job is resumable:

1. Add a new key to `TENANT_SECRET_KEYS`, point `TENANT_SECRET_KEY_ID` at it.
2. Run `tenant:rotate-secrets` until it reports nothing stale.
3. Remove the old key.

## Tests and CI

```bash
bin/ci                # everything CI runs, in the same containers
bin/ci --no-build     # skip the two production image builds, the slow step
bin/ci --coverage     # measure coverage and hold it above the floor
```

GitHub Actions runs that same script rather than its own copy of the checks, so
there is nothing that can drift between local and CI, and a green run locally means
a green run there.

**Two branches at once:** `git worktree add ../xivi-XIV-99 -b XIV-99/thing`, then
run `bin/ci` there. That directory gets its own compose project, ports, tenant
databases and dev image, so both suites run at the same time without meeting
(XIV-51, XIV-71); `bin/ci` refuses a second run in the *same* checkout rather than
letting the two interleave. Reach that worktree's stack by hand with its own
`bin/compose`.

**If two checkouts want the same ports, you are told which one has them**
(XIV-86). The offset is a checksum of the directory name into one of a hundred
buckets, so two directories can land on the same one — at seven worktrees that
happens about one time in five. `bin/compose up` and `bin/ci` check before
starting anything and refuse, naming the offset, the checkout holding it and its
directory, and printing the six exports that move this checkout somewhere free:

```bash
export HTTP_PORT=8053 HTTPS_PORT=8453 HTTP3_PORT=8453 \
       DATABASE_PORT=5553 ADMINER_PORT=8153 MAILPIT_PORT=8253
```

Export them in the shell you run `bin/ci` from and they win over the derived
values, this check included. Without the check the loud half would be a failed
bind on port 443's stand-in; the quiet half is that `DATABASE_PORT` would answer
as the *other* checkout's Postgres, so anything you pointed at the address
`bin/compose` prints — PhpStorm's database panel, `psql` — would be working on
that checkout's tenants (brief §9.2).

The dev image is `<checkout>-xivi-php-dev` — `xivi-xiv-99-xivi-php-dev` for the
worktree above — so a branch that changes the `Dockerfile` or the entrypoint
cannot alter what another checkout is running. It costs almost nothing: every
layer your build has in common with the main checkout's is shared, so a worktree
that changed nothing about the build is about 29 kB.

**When you remove a worktree, remove its image.** Nothing does it for you: the
name is derived from the directory, so once the directory is gone there is
nothing left to work it out from. Read it off the summary first.

```bash
bin/compose down                     # in the worktree
docker image rm xivi-xiv-99-xivi-php-dev xivi-xiv-99-xivi-prod-check
git worktree remove ../xivi-XIV-99
```

`docker image ls 'xivi-*'` finds any you have already lost track of; they share
their layers with the main checkout's image, so the disk they return is small.

It covers: `composer validate --strict`, a dependency vulnerability audit, coding
standards (`php-cs-fixer`, Symfony's ruleset plus the licence header), deptrac
module boundaries, PHPStan level 8, PHPUnit, and a build of the **production**
image — the last one because the dev image installs dev dependencies and so proves
nothing about what ships.

Before any of that it runs `bin/reconcile` inside the container, which makes what
the stack has cached match the tree being checked: vendor/ against
`composer.lock`, and the compiled service container against the configuration it
was built from (XIV-63). Starting a stack that is already running installs and
compiles nothing, so without this a merge that added or dropped a dependency
turned up as PHPStan errors about code. It costs about a second on a warm stack,
runs from the container entrypoint too, and can be run by hand —
`bin/compose exec php bin/reconcile` — after any merge that changes either.

`composer cs-fix` writes the formatting fixes. It cannot write the `@author`
annotation every class carries — no fixer adds one — so a new class needs it by
hand; `.php-cs-fixer.dist.php` names the three Symfony rules deliberately turned
off and why.

Coverage is measured over `src/` and `packages/`, written to `coverage/`, and
gated by a floor in `bin/ci` — a number nothing enforces drifts down one
uncovered branch at a time. Open `coverage/html/index.html` to see what is not
covered. Xdebug costs this suite about seven percent, because it spends its time
provisioning databases rather than executing PHP.

## Layout

```
src/                     the application: tenancy, the tenant registry, security, controllers
packages/core            the engine — metadata, field types, record storage
packages/contact         the first module built on it
packages/control-plane   the administration surface: provisioning, operators, the tenant list
```

Modules are Symfony bundles wired as Composer path repositories. A module may
depend on core, never on another module, and core may depend on neither the
modules nor the application — enforced by `deptrac.yaml` in CI rather than by
separate repositories (§3).

The control plane is the same kind of package pointed the other way: it may depend
on the application, and the application may never depend on it. The half of the
control-plane database a *customer's* request needs — which tenant owns this
hostname, and the credential to reach their database — is `App\Registry` and stays
in `src/`, because an instance serving customers cannot boot without it (§3.1).

Individual pieces, if you want them on their own:

```bash
bin/compose exec php composer test      # PHPUnit
bin/compose exec php composer phpstan   # level 8
```

The functional tests provision real tenants — real databases and real PostgreSQL
roles. They cover the parts that would fail silently: two hosts reaching two
databases within one process, one tenant's credentials being refused by another
tenant's database, a session from one tenant being refused by another, records and
uniqueness not crossing tenants, and a full encryption-key rotation.

A tenant is provisioned once per test **class**, and each test is rolled back
afterwards rather than truncated (`dama/doctrine-test-bundle`). Speed is not the
point — a truncate was already fast — a rollback also undoes field *definitions*,
which is what lets the metadata-editor tests share a database with everything
else. Tenant databases are left behind between runs on purpose, and reclaimed by
slug the next time.

## Licence

MIT — see [LICENSE](LICENSE). Third-party notices are in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md); the Docker setup is adapted from
[dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

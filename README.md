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
> database, and an administrator can manage the people who sign in. What is
> missing is templates deciding which modules a customer is given, a second
> module, and an authorization model finer than a single admin role.

The design is written down first and the code follows it. Read
**[docs/architecture.md](docs/architecture.md)** before anything else; it explains
the decisions this repository is an implementation of, and the code comments cite
its section numbers.

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

**Classic PHP execution, on purpose.** FrankenPHP runs without worker mode, so no
PHP state survives a request boundary and cross-tenant leakage (§7.4) is
structurally impossible for web requests. It costs a few milliseconds per request
and is worth it. See the comment in `frankenphp/Caddyfile`.

**Server-rendered, no build step.** Twig, Bootstrap's CSS and Bootstrap Icons,
self-hosted through AssetMapper — no Node, no bundler, and no CDN calls from a
customer's browser. The forms work without JavaScript.

## Requirements

Docker and Docker Compose. Nothing else — there is no host PHP or Composer step.

The dev container runs as you, not as root, so that files it creates — migrations,
recipe config, vendored assets — belong to you. `bin/ci` works this out for
itself. If you start the stack with `docker compose` directly and your uid is not
1000, set `APP_UID` and `APP_GID` in `.env.local`:

```bash
printf 'APP_UID=%s\nAPP_GID=%s\n' "$(id -u)" "$(id -g)" >> .env.local
```

## Quickstart

```bash
docker compose up -d --wait
```

That builds the image, starts PostgreSQL, installs dependencies and applies the
control-plane migrations. The app is then on <https://localhost> with a self-signed
certificate, so expect a browser warning (or use `curl -k`).

Provision a tenant with an admin user, then sign in at `https://acme.localhost`:

```bash
docker compose exec php bin/console tenant:provision acme acme.localhost \
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

## Looking at a tenant's database

```bash
docker compose --profile tools up -d adminer   # http://127.0.0.1:8080
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
yourself:

```bash
docker compose exec php vendor/bin/mate init
docker compose exec php vendor/bin/mate discover
```

Two things to know, both of which cost an afternoon once:

- `mate init` writes `"command": "php"` into `mcp.json`. There is no host PHP here,
  so change it to `docker compose exec -T php vendor/bin/mate serve --force-keep-alive`.
- `mate discover` is a **manual step**. Its Composer plugin is deliberately left out
  of `allow-plugins` so that nothing of Mate's runs during `bin/ci`; the price is
  that without `discover`, the extensions stay unregistered and the tool list
  silently stays at one.

`mate init` also writes an `AGENTS.md` telling agents to prefer its tools over the
CLI. Take that as a suggestion — for most of what this project needs, a shell in
the container is the better instrument.

## Commands

| Command | What it does |
| --- | --- |
| `tenant:provision <slug> <hostname...>` | Creates the row, the role, the database and its schema; `--admin-email` adds the first user |
| `tenant:user:create <slug> <email>` | Adds a user to one tenant; `--admin` grants ROLE_ADMIN |
| `tenant:module:install <slug> <module>` | Installs a module for one tenant: its table and field definitions; `--preset` picks which fields |
| `tenant:list` | Shows the registry |
| `tenant:migrate [--slug=]` | Applies tenant migrations to every tenant; run it on every deploy |
| `tenant:rotate-secrets` | Re-encrypts stored passwords with the active key |
| `doctrine:migrations:migrate --em=control` | Control-plane schema only |

Any console command can be pointed at one tenant's database with the `TENANT`
environment variable — a command has no Host header to resolve one from:

```bash
TENANT=acme docker compose exec php bin/console doctrine:schema:validate --em=tenant
```

That is also how a tenant migration is generated, since the diff needs a database
to compare against:

```bash
docker compose exec -e TENANT=acme php bin/console doctrine:migrations:diff \
    --em=tenant --configuration=config/migrations/tenant.php
```

Migrations are split: `migrations/control` runs once per deploy, `migrations/tenant`
runs once per tenant. Every schema change lands for every customer, so tenant
migrations must be expand/contract — never destructive in a single step (§4).

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

### Before deploying anywhere real

The values committed in `.env` are placeholders and are public. Replace at minimum
`APP_SECRET`, `TENANT_SECRET_KEYS` and the PostgreSQL password.

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # a TENANT_SECRET_KEYS value
```

`TENANT_ADMIN_DSN` should name a role with `CREATEDB` and `CREATEROLE` rather than a
superuser, and ideally should not be present in the web processes' environment at
all.

### Rotating the encryption key

Stored secrets record which key wrote them, so both keys are valid during the
changeover and the job is resumable:

1. Add a new key to `TENANT_SECRET_KEYS`, point `TENANT_SECRET_KEY_ID` at it.
2. Run `tenant:rotate-secrets` until it reports nothing stale.
3. Remove the old key.

## Tests and CI

```bash
bin/ci                # everything CI runs, in the same containers
bin/ci --no-build     # skip the production image build, the slow step
bin/ci --coverage     # measure coverage and hold it above the floor
```

GitHub Actions runs that same script rather than its own copy of the checks, so
there is nothing that can drift between local and CI, and a green run locally means
a green run there.

It covers: `composer validate --strict`, a dependency vulnerability audit, coding
standards (`php-cs-fixer`, Symfony's ruleset plus the licence header), deptrac
module boundaries, PHPStan level 8, PHPUnit, and a build of the **production**
image — the last one because the dev image installs dev dependencies and so proves
nothing about what ships.

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
src/               the application: tenancy, control plane, security, controllers
packages/core      the engine — metadata, field types, record storage
packages/contact   the first module built on it
```

Modules are Symfony bundles wired as Composer path repositories. A module may
depend on core, never on another module, and core may depend on neither the
modules nor the application — enforced by `deptrac.yaml` in CI rather than by
separate repositories (§3).

Individual pieces, if you want them on their own:

```bash
docker compose exec php composer test      # PHPUnit
docker compose exec php composer phpstan   # level 8
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

# Xivi 17

A metadata-driven CRM/ERP engine in Symfony, plus a CRM built on top of it to keep
the engine honest.

> **Status: early.** The multi-tenancy foundation is built and tested. The engine
> itself — metadata layer, dynamic forms, field-type registry, modules, UI — is not.
> There is no usable CRM here yet.

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

**Classic PHP execution, on purpose.** FrankenPHP runs without worker mode, so no
PHP state survives a request boundary and cross-tenant leakage (§7.4) is
structurally impossible for web requests. It costs a few milliseconds per request
and is worth it. See the comment in `frankenphp/Caddyfile`.

## Requirements

Docker and Docker Compose. Nothing else — there is no host PHP or Composer step.

## Quickstart

```bash
docker compose up -d --wait
```

That builds the image, starts PostgreSQL, installs dependencies and applies the
control-plane migrations. The app is then on <https://localhost> with a self-signed
certificate, so expect a browser warning (or use `curl -k`).

Provision a tenant and visit it:

```bash
docker compose exec php bin/console tenant:provision acme acme.localhost --name='Acme AG'
curl -k -H 'Host: acme.localhost' https://localhost/_tenancy/whoami
# {"tenant":"acme","status":"active","database":"tenant_acme"}
```

`*.localhost` resolves to `127.0.0.1` on most systems; if yours disagrees, add the
hostname to `/etc/hosts`. Caddy matches a single wildcard label, so dev hostnames
are one level deep — `acme.localhost`, not `www.acme.localhost`.

`/_tenancy/whoami` is a diagnostic that reports the resolved tenant and asks
PostgreSQL which database the connection actually reached. It is served only when
debug is on.

## Commands

| Command | What it does |
| --- | --- |
| `tenant:provision <slug> <hostname...>` | Creates the row, the role, the database and its schema |
| `tenant:list` | Shows the registry |
| `tenant:migrate [--slug=]` | Applies tenant migrations to every tenant; run it on every deploy |
| `tenant:rotate-secrets` | Re-encrypts stored passwords with the active key |
| `doctrine:migrations:migrate --em=control` | Control-plane schema only |

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
```

GitHub Actions runs that same script rather than its own copy of the checks, so
there is nothing that can drift between local and CI, and a green run locally means
a green run there.

It covers: `composer validate --strict`, a dependency vulnerability audit, PHPStan
level 8, PHPUnit, and a build of the **production** image — the last one because the
dev image installs dev dependencies and so proves nothing about what ships.

Individual pieces, if you want them on their own:

```bash
docker compose exec php composer test      # PHPUnit
docker compose exec php composer phpstan   # level 8
```

The functional tests provision real tenants — real databases and roles — and drop
them again. They cover the parts that would fail silently: two hosts reaching two
databases within one process, one tenant's credentials being refused by another
tenant's database, and a full encryption-key rotation.

## Licence

MIT — see [LICENSE](LICENSE). Third-party notices are in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md); the Docker setup is adapted from
[dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

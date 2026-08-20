# Xivi 17

[![CI](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml/badge.svg)](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/Praesidiarius/plc-xivi/branch/main/graph/badge.svg)](https://codecov.io/gh/Praesidiarius/plc-xivi)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.dist.neon)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A metadata-driven CRM/ERP engine in Symfony, plus a CRM built on top of it to
keep the engine honest. **One installation serves many customers**, each with
their own PostgreSQL database and their own hostname, and each deciding for
themselves what a contact or an invoice looks like.

> **Status: early, but no longer a skeleton.** The engine is built and tested:
> multi-tenancy, sign-in, the metadata layer and per-action permissions. Six
> modules run on it, from contacts through articles, orders, invoices and
> vouchers to a knowledge base, every page of them built from definitions in
> the customer's own database, and a store installs them without a shell. What
> is still assembled by hand is the customer themselves: templates deciding
> which modules and fields a signup receives are the missing half of
> provisioning.
>
> The full inventory is
> [What exists today](https://praesidiarius.github.io/plc-xivi-docs/what-exists/).

## Where to go

| Where                                                                  | What's in it                                                                                                                                                                                                                                                                   |
|------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **📖 [Documentation](https://praesidiarius.github.io/plc-xivi-docs/)** | Installing Xivi, running an installation, and what a record, a module and a tenant actually are. **Start here if you want to *use* this rather than change it.** Its source is [plc-xivi-docs](https://github.com/Praesidiarius/plc-xivi-docs).                                  |
| **[DEVELOPING.md](DEVELOPING.md)**                                     | Working on the code: the stack, the test suite, `bin/ci`, the package layout, the tooling this project ships for itself.                                                                                                                                                         |
| **[docs/architecture.md](docs/architecture.md)**                       | The design brief: the decisions this repository is an implementation of, distilled to the rules. It opens with a map of the rest, one file per area in [`docs/architecture/`](docs/architecture). Code comments cite section numbers; **read the sections they name, not the whole brief.** |
| **[AGENTS.md](AGENTS.md)**                                             | The conventions, and the handful of things that mislead somebody who has only read the code. Written for a coding agent, worth a human's two minutes.                                                                                                                            |
| **[CHANGELOG.md](CHANGELOG.md)**                                       | What has been built and when. It holds what has not shipped yet and indexes the released versions under [docs/changelog/](docs/changelog/).                                                                                                                                      |

The design is written down first and the code follows it, which is why the
brief is the second row rather than an appendix.

**It stays here rather than on the documentation site on purpose.** It is the
record of *why* each decision was made, the issue tracker cites it by section
number, and it has to travel with the commit that changes the behaviour it
describes. The site is the other half: what an installation *is*, for somebody
deploying or evaluating one.

The version is in [`src/Version.php`](src/Version.php) and is shown in the
footer of every page. The leading **17 is a generation, not a semver major**;
how the rest of the number moves is
[in the changelog](CHANGELOG.md#how-the-version-works).

## Quickstart

Docker and Docker Compose, and nothing else: there is no host PHP or Composer
step. Use **`bin/compose`, not `docker compose`**. It points Compose at *this*
checkout's stack, and [DEVELOPING.md](DEVELOPING.md) explains why that matters.

```bash
git clone git@github.com:Praesidiarius/plc-xivi.git
cd plc-xivi
bin/compose up -d --wait
```

That builds the image, starts PostgreSQL, installs dependencies and applies the
control-plane migrations. The app is then on <https://localhost> with a
self-signed certificate, so expect a browser warning (or use `curl -k`).

Provision a customer with an admin user, then sign in at
`https://acme.localhost`:

```bash
bin/compose exec php bin/console tenant:provision acme acme.localhost \
    --name='Acme AG' --admin-email=you@example.com
# ... Password: <generated, shown once>
```

`*.localhost` resolves to `127.0.0.1` on most systems; if yours disagrees, add
the hostname to `/etc/hosts`.

**Next:** [Getting Started](https://praesidiarius.github.io/plc-xivi-docs/getting-started/)
takes the same road at walking pace and carries on into the first operator, the
control plane and the first customer.

## Deploying

The design decisions are in
[docs/architecture/deployment.md §4.8](docs/architecture/deployment.md); the
long-form reasoning is in [`deploy.php`](deploy.php) itself. This is the runbook.

**What a target needs: Debian 13, Docker, Compose, and nothing else.** No PHP, no
Postgres client, no rsync. Set Docker's log rotation, because the default is
unbounded:

```jsonc
// /etc/docker/daemon.json
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "3" } }
```

The provisioning role needs `CREATEDB`, `CREATEROLE` and **`pg_signal_backend`**
(XIV-94). A non-superuser without the last one cannot `DROP DATABASE` a tenant
that somebody is still connected to, and the first deployment finds out at
provisioning time.

**Once per installation:**

```console
$ cp .hosts.yaml.dist .hosts.yaml            # hostnames, users, which image
$ cp .env.deploy.dist .env.deploy.<alias>    # secrets, hostnames, Postgres sizing
$ bin/compose exec php vendor/bin/dep secrets:install <alias>
```

Both copies are gitignored. Generate `APP_SECRET` and `TENANT_SECRET_KEYS` with
`openssl rand -hex 32`; an instance that starts on the committed placeholders
refuses to boot rather than encrypting customer data with a key that is in this
repository.

**A fresh installation has no customers, and `bin/deploy` stops on an empty
registry.** That is deliberate: a registry that has lost its tenants looks
identical from the inside to one that never had any, and the first is worth
stopping a release over. Only the deployment knows which it is, so say so:

```
XIVI_ALLOW_EMPTY_REGISTRY=1
```

in the env file, for an installation that is meant to be empty, such as one
waiting for its first self-service signup. It changes nothing else: a tenant that
fails to migrate still fails the deploy. Take it out once you have customers.

Without it, the first pass migrates the control plane and stops, so provisioning
and then deploying again also works.

**Every release:**

```console
$ export GHCR_USER=<your github login>
$ export GHCR_TOKEN_FILE=~/somewhere/outside/this/checkout
$ bin/release <alias>
```

`bin/release` builds the image, pushes it to ghcr.io, and hands the resulting
**digest** to Deployer, which pulls it on the target, runs `bin/deploy` out of
the new image to migrate the control plane and every tenant, and only then
replaces the serving containers. A tenant that fails to migrate fails the deploy.

The build is out here rather than inside the dev container because the container
has no Docker, and mounting the host's Docker socket into it to get one is a
root-equivalent permission for a small convenience. `bin/ci` already builds the
production image on the host, so this is the same split.

The deploy also writes `/etc/cron.d/xivi` from the job list in this build, so the
scheduled commands are installed rather than remembered. `bin/console
deploy:crontab` shows what they are and which of them a monitoring service
watches; **none, by default**, which is the state where a stopped job is
invisible. `XIVI_MONITOR_PINGS` is how you change that.

**Rollback is the same command with the previous digest**, which builds nothing:

```console
$ bin/release <alias> --tag=sha256:<previous>
```

**It does not roll the databases back**, and does not need to: tenant migrations
are additive only, so old code meets a newer schema and ignores what it does not
know. A change that genuinely removes something is two releases.

### Before public DNS exists

No certificate authority can issue for a hostname that does not resolve publicly,
because validation is an inbound request from the CA. A hosts file on your own
machine changes nothing, and Let's Encrypt's staging endpoint validates the same
way. To rehearse anyway, put `CADDY_TLS_ISSUER=issuer internal` in the env file:
Caddy signs locally, nothing trusts the certificate, and **the ask endpoint is
still consulted**, which is the half worth rehearsing. Remove the line once DNS
resolves.

```console
$ curl -k --resolve demo.example.com:443:<ip> https://demo.example.com/
```

## Licence

MIT; see [LICENSE](LICENSE). Third-party notices are in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md), and the Docker setup is
adapted from
[dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

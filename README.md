# Xivi 17

[![CI](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml/badge.svg)](https://github.com/Praesidiarius/plc-xivi/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/Praesidiarius/plc-xivi/branch/main/graph/badge.svg)](https://codecov.io/gh/Praesidiarius/plc-xivi)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.dist.neon)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A metadata-driven CRM/ERP engine in Symfony, plus a CRM built on top of it to keep
the engine honest. **One installation serves many customers**, each with their own
PostgreSQL database and their own hostname, and each deciding for themselves what
a contact or an invoice looks like.

> **Status: early, but no longer a skeleton.** Multi-tenancy, sign-in and the
> metadata engine are built and tested. A customer can list, filter, create, edit,
> delete, export and import records, change their own fields, and read what
> happened to a record — every page of it built from definitions in their own
> database. An administrator can manage the people who sign in and decide, per
> module and per action, what each of them may do. What is missing is templates
> deciding which modules a customer is given, and a second module.
>
> The full inventory is
> [What exists today](https://praesidiarius.github.io/plc-xivi-docs/what-exists/).

## Where to go

| | |
| --- | --- |
| **📖 [Documentation](https://praesidiarius.github.io/plc-xivi-docs/)** | Installing Xivi, running an installation, and what a record, a module and a tenant actually are. **Start here if you want to *use* this rather than change it.** Its source is [plc-xivi-docs](https://github.com/Praesidiarius/plc-xivi-docs). |
| **[DEVELOPING.md](DEVELOPING.md)** | Working on the code: the stack, the test suite, `bin/ci`, the package layout, the tooling this project ships for itself. |
| **[docs/architecture.md](docs/architecture.md)** | The design brief — the decisions this repository is an implementation of. Read it before designing anything; the code comments cite its section numbers. |
| **[AGENTS.md](AGENTS.md)** | The conventions, and the handful of things that mislead somebody who has only read the code. Written for a coding agent, worth a human's two minutes. |
| **[CHANGELOG.md](CHANGELOG.md)** | What has been built and when. It holds what has not shipped yet and indexes the released versions under [docs/changelog/](docs/changelog/). |

The design is written down first and the code follows it, which is why the brief
is the second row rather than an appendix.

**It stays here rather than on the documentation site on purpose.** It is
the record of *why* each decision was made, it is cited by section number
throughout the issue tracker, and it has to travel with the commit that changes
the behaviour it describes. The site is the other half: what an installation *is*,
for somebody deploying or evaluating one.

The version is in [`src/Version.php`](src/Version.php) and is shown in the footer
of every page. The leading **17 is a generation, not a semver major** — how the
rest of the number moves is [in the changelog](CHANGELOG.md#how-the-version-works).

## Quickstart

Docker and Docker Compose, and nothing else — there is no host PHP or Composer
step. Use **`bin/compose`, not `docker compose`**: it points Compose at *this*
checkout's stack, and [DEVELOPING.md](DEVELOPING.md) explains why that matters.

```bash
git clone git@github.com:Praesidiarius/plc-xivi.git
cd plc-xivi
bin/compose up -d --wait
```

That builds the image, starts PostgreSQL, installs dependencies and applies the
control-plane migrations. The app is then on <https://localhost> with a self-signed
certificate, so expect a browser warning (or use `curl -k`).

Provision a customer with an admin user, then sign in at `https://acme.localhost`:

```bash
bin/compose exec php bin/console tenant:provision acme acme.localhost \
    --name='Acme AG' --admin-email=you@example.com
# ... Password: <generated, shown once>
```

`*.localhost` resolves to `127.0.0.1` on most systems; if yours disagrees, add the
hostname to `/etc/hosts`.

**Next:** [Getting Started](https://praesidiarius.github.io/plc-xivi-docs/getting-started/)
takes the same road at walking pace and carries on into the first operator, the
control plane and the first customer.

## Licence

MIT — see [LICENSE](LICENSE). Third-party notices are in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md); the Docker setup is adapted from
[dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

# Working on Xivi

Orientation for a coding agent. Short on purpose — this is what gets got wrong
without being told, not documentation. The long version of *why* anything here is
the way it is lives in [`docs/architecture.md`](docs/architecture.md).

## Run everything through `bin/compose`, never `docker compose`

There is **no host PHP and no host Composer**. Every command runs in the
container:

```bash
bin/compose exec php composer test
bin/compose exec php bin/console tenant:list
bin/compose                     # prints which stack this checkout owns
```

`bin/compose` forwards to `docker compose` after deriving the compose project,
the published ports, the bind mount, the image name and the test-database prefix
**from the checkout directory** (XIV-51, XIV-55, XIV-71). A bare `docker compose`
does none of that. In the main checkout it happens to be right; in a git worktree
it collides on port 443 and, less visibly, runs the test suite against the main
checkout's tenant databases.

`--ignore-platform-req` is not the answer to anything here.

## `bin/ci` is the gate

```bash
bin/ci                # everything, including the production image build
bin/ci --no-build     # skip the slow image build, the inner loop
```

GitHub Actions runs this same script, so there is no second list of checks that
can drift. It must be green before you commit. It reconciles the container's
cached artifacts with the tree first (XIV-63) — `vendor/` against
`composer.lock`, and the compiled container against the config that produced it —
so a stale stack is no longer something you have to think about.

**Two branches at once:** make a git worktree. It gets its own stack, ports,
image and tenant databases, so two suites run at the same time without meeting.

## The brief is authoritative

`docs/architecture.md` holds the design reasoning and is where decisions live.
**Read the relevant sections before designing anything** — most questions that
look open have been answered there, with the argument attached.

`CHANGELOG.md` records *what changed*, one bullet per ticket. It is not where a
decision goes. If a decision is worth keeping and is not in the brief yet, put it
in the brief first.

## Conventions that are enforced socially, not mechanically

- **Branches are `XIV-<n>/short-name`.**
- **Every branch adds a real `CHANGELOG.md` entry** under `## [Unreleased]`, with
  the `[XIV-n]:` link at the bottom. `bin/ci` only checks that the file changed —
  writing something a reader can actually use is a human obligation. Anything
  somebody has to *act* on gets its own bullet.
- **Every class carries the licence header and an `@author` docblock.**
  `composer cs-fix` fixes formatting but cannot add the `@author`.
- **Comments explain *why*, in prose, at length.** This codebase argues with
  itself in its own comments. Match that; a terse one-liner in a file full of
  reasoning reads as unfinished.
- **Reach for Symfony's own component before hand-rolling one**, and say so out
  loud when you deliberately do not.
- **Licence-check every new dependency** and record the result. Permissive only —
  LGPL has been rejected here before.

## Tools, if you want them

This project ships its own MCP tools — tenants, the module catalogue, a tenant's
*real* field definitions, and tenant lifecycle. Once per checkout:

```bash
bin/compose exec php vendor/bin/mate init
bin/compose exec php vendor/bin/mate discover
```

`discover` is what registers this project's own `xivi/mate` extension; nothing
runs it for you, because Mate's Composer plugin is deliberately outside
`allow-plugins`.

**None of it is required.** `bin/console tenant:inspect` answers the same
questions from the command line, and `--json` prints exactly what the tools
return. Nothing here is tool-only, and a server that is not running is not a
reason to be stuck.

## Tenants

The application is multi-tenant with a database per customer, so most things need
to know which tenant they are about.

```bash
bin/compose exec php bin/console tenant:list      # who exists
bin/compose exec php bin/console list tenant      # everything you can do to one
bin/compose exec php bin/console tenant:migrate   # after any merge that adds a migration
bin/compose exec php bin/console tenant:reset acme --modules=contact,article --records=200
```

`tenant:reset` throws a development tenant away and rebuilds it end to end,
resolving module install order from the modules' own requirements. Pass
`--no-debug` for large record counts (XIV-74).

**Do not seed, edit or delete records in a tenant you did not create.** The dev
tenants are somebody's working state. The test suite provisions and drops its
own.

## Two things that will mislead you

**A module's blueprint in code is not what a tenant has.** Installing does not
retro-fit: once a module is installed the customer's own definitions are the
truth (§6.1), so a tenant may lack a field the blueprint declares, or carry one
it renamed. Read the tenant's metadata rather than the module class when the
question is about a tenant.

**Derived values belong to the engine.** Fields marked `derived` — document
numbers, money totals, due dates — are filled on save by derivers. Writing them
by hand suppresses the engine and produces records that look plausible and are
wrong (XIV-73).

## Layout

```
src/               the application: tenancy, control plane, security, controllers
packages/core      the engine — metadata, field types, record storage
packages/contact   a module built on it (also article, order, invoice)
```

A module may depend on core, never on another module; core may depend on neither
the modules nor the application. `deptrac` enforces this in CI.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate Summary:
- Role: MCP-powered, project-aware coding guidance and tools.
- Required action: Read and follow `mate/AGENT_INSTRUCTIONS.md` before taking any action in this project, and prefer MCP tools over raw CLI commands whenever possible.
- Installed extensions: symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

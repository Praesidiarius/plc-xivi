# Changelog

What has changed in Xivi, and when. The design *reasoning* lives in
[docs/architecture.md](docs/architecture.md) and stays there; this file is the
record of what was built.

Released versions are archived one file per release under
[docs/changelog/](docs/changelog/) — see [the index](#releases). This file holds
only what has not shipped yet, so it stays a page rather than a history.

## How the version works

The format is `17.MINOR.PATCH`, and it is **not** semantic versioning.

- **17** is a *generation*, not a semver major. It says which Xivi this is, and
  changes only when there is a new one — a business decision rather than a
  technical one. Breaking changes inside a generation do not touch it.
- **PATCH** is the release counter. It moves every time a release is cut, which
  at this project's current pace is roughly daily — so a year of it reads
  17.0.351, not like semver. Features move it; so do fixes.
- **MINOR** is for a release big enough to be worth naming. It has not moved yet.

Deliberately *not* "patches are fixes, minors are features". That rule was here
first and was false the day 17.0.1 shipped two large features under it. A version
scheme nobody follows is worse than an unusual one everybody does.

**The version moves on release, not on feature.** Work lands under *Unreleased*
and moves nothing; cutting a release is the deliberate act of renaming that
heading and dating it. Nothing else can advance the number, which is what stops
it creeping while the project is moving quickly.

The number lives in [`src/Version.php`](src/Version.php), is shown at the foot of
every page — inside the card on the sign-in page, which has no footer (XIV-79) —
and is not yet tied to git tags.

### Writing an entry

**One bullet per ticket, one to three lines.** Say what changed for somebody
using Xivi, and point at the brief section for why. The long version of the
reasoning belongs in `docs/architecture.md`, which is the rule the top of this
file has always stated — entries that restate it make this file grow at the rate
work happens, which is the rate nobody can read.

Anything a reader has to **act** on — a changed status code, a dropped guarantee,
a manual step on upgrade — is called out as its own bullet even when it is small.
That is what somebody opens a changelog for.

If a decision is worth keeping and is not in the brief yet, **put it in the brief
first**. An entry is not the place a design decision lives.

### Cutting a release

1. Move the whole `Unreleased` block into `docs/changelog/<version>.md`, under a
   `# Xivi <version> — <date>` heading, and take its `[XIV-N]:` link definitions
   with it. Sections drop one level: `###` becomes `##`.
2. Add a line to the [release index](#releases) below.
3. Bump [`src/Version.php`](src/Version.php).
4. Update the version line near the top of [`README.md`](README.md) — the one
   reading ``The version is `17.0.3` ``. It is the first thing anybody reads and
   it drifts silently; 17.0.3 shipped saying 17.0.2.
5. Tag the merge commit `v<version>` and push the tag. That is what publishes:
   `.github/workflows/release.yml` posts the file from step 1 as the GitHub
   release, and fails if the file is missing or the tag disagrees with steps 3
   or 4.

`bin/ci` gates on this file having changed, which keeps working: new work always
lands in `Unreleased` here.

## [Unreleased]

### Changed

- **A tenant removal now empties the cluster before it touches the registry**,
  where it used to delete the control-plane row first. A failure part-way
  therefore leaves a row pointing at nothing — visible to `tenant:list`, and
  repaired by running the same command again — rather than a database and a role
  that nothing in the system knows about ([XIV-94], §4.1).
- **A removal that stops part-way prints what is standing**: the database, the
  role and the control-plane row, each said to be gone or still there, with the
  line to type next, instead of a DBAL driver exception ([XIV-94]).

### Fixed

- **Two checkouts landing on the same port offset now say so** ([XIV-86]). The
  offset comes from a checksum of the directory name modulo one hundred, so at
  seven parallel worktrees a collision is about one in five and at twelve it is
  better than even — and it had already happened. `bin/compose up` and `bin/ci`
  check whether another compose project is publishing this checkout's ports
  before starting anything, and refuse with the offset, the checkout holding it,
  its directory and the six exports that move this one somewhere free. **The
  reason this is worth refusing over is `DATABASE_PORT`:** it is not one of the
  ports Docker announces by failing to bind it, it is the address `bin/compose`
  prints for PhpStorm and `psql`, and on a collision it answers — as the *other*
  checkout's Postgres, with a full set of that checkout's tenant databases in it
  (§9.2).
- A checkout that does not collide is unchanged: the same offset, the same
  addresses, the same bookmarks. An explicitly exported port is still honoured
  and is not subject to the new check — somebody exporting these has already
  resolved a collision by hand and must not be refused for it. The check runs on
  the subcommands that create containers and nowhere else, so `bin/compose exec`
  and friends cost what they always did.
- **`tenant:deprovision` works on a tenant that is still in use.** Postgres
  refuses `DROP DATABASE` while anything is connected, so removing a customer who
  was still being served failed with `SQLSTATE[55006]`. The removal now
  disconnects the sessions on that database first, as a deliberate step rather
  than a flag: §4.1 refuses to make `suspended` a prerequisite, and a live tenant
  is by definition one with sessions open ([XIV-94]).

### Measured

- **What it takes to end another role's Postgres session**, on this project's
  Postgres 18 rather than off a manual page: a role with exactly
  `CREATEDB CREATEROLE` is refused with `42501` against a tenant role it created
  itself, because a `CREATEROLE` grant has carried `ADMIN` without `INHERIT` since
  Postgres 16, and succeeds once granted `pg_signal_backend`. The same experiment
  found two further obstacles for a non-superuser provisioning role, neither
  addressed here — see §4.1 ([XIV-94]).

### Upgrade notes

- **Provisioning credentials short of superuser now need `pg_signal_backend`.**
  Deployments using the default superuser `TENANT_ADMIN_DSN` are unaffected. A
  narrowed one needs `GRANT pg_signal_backend TO <provisioning role>`, which
  `tenant:deprovision` now names in the error when it hits the wall — nothing is
  destroyed in that case ([XIV-94]).

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

[XIV-86]: https://xivi.youtrack.cloud/issue/XIV-86
[XIV-94]: https://xivi.youtrack.cloud/issue/XIV-94

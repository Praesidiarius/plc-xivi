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
### Added

- **The tenant list shows the modules a customer actually has installed, and
  names where that disagrees with the registry** ([XIV-95]). The list is read
  from the customer's own metadata by `tenant:usage:collect` — the page still
  opens no tenant connection — so it is as old as the last collection and says
  so, in the same three states as the figures beside it: not collected yet, could
  not be read, or installed as of a time. A difference is drawn in both
  directions (*not recorded* for a module the customer has that
  `enabled_modules` does not list, *not installed* for the other way) and
  deliberately **not** as a fault: §6.1 makes a module installed from a console a
  legitimate state, and nothing here offers to reconcile the two
  ([§8.11](docs/architecture.md#what-a-tenant-actually-has-installed-and-where-that-disagrees-xiv-95)).
- **Per-module record counts are now readable text instead of a tooltip.** They
  were a `title` on the usage cell, which a touch screen and a screen reader both
  simply do not have; they are drawn beside the module names they belong to. A
  customer with more than five modules folds the tail into a disclosure, and the
  modules the two sources disagree about always sort ahead of it.
- **`tenant_usage` gains an `installed_modules` column, and nothing is backfilled**
  — a row collected before this change has genuinely never had its modules read,
  and the page draws that as *not collected yet* until the next run. Filling it in
  from `enabled_modules` would have manufactured perfect agreement for every
  existing customer, which is the assumption the column exists to stop.
- **An operator can be revoked, restored, listed and given a new password, all
  from the console** ([XIV-92], §8.9). `control:operator:create` made one and
  nothing else touched it, so withdrawing the identity with the most reach in the
  installation meant `psql`. Four commands now: `control:operator:list`,
  `control:operator:revoke`, `control:operator:restore` and
  `control:operator:password`.
- **Revoking deactivates rather than deletes**, so the account stays in the list,
  marked, and comes back with `control:operator:restore`. **This adds
  `operator.active`** — run `bin/console doctrine:migrations:migrate --em=control`
  on deploy. Everybody who exists today stays able to sign in.
- **A revoked operator cannot sign in, and a session they already had ends on
  their next request.** The second needed its own listener: Symfony compares
  identifier, password and roles when it restores a session, and never `active`
  (§8.9).
- **The last operator who can still sign in cannot be revoked.** There is no
  sign-up, no invitation and no password reset on the control plane, so create
  the successor first. The refusal counts active operators rather than rows, so
  it cannot be walked past by revoking two accounts in turn.
- **Changing an operator's password signs out every session that account had**,
  which Symfony does on its own; it is now tested for, because it was inherited
  rather than written.
- **`control:operator:create` on an address that already has an operator is still
  an error** and now says which command to use instead — a different sentence for
  a live account and for a revoked one. Making create double as a password change
  would make a typo'd address indistinguishable from a rotation, and would undo a
  revocation without mentioning one (§8.9).

- **`[tenant.logo]` in a document template draws the customer's logo**
  ([XIV-89]). Put it anywhere in the .docx — including the header, which is where
  a letterhead wants it — and the generated document carries the picture, in the
  Word file and in the PDF alike. It works when Word has split the marker across
  several runs, as every other marker does, and the relationship it adds to the
  package cannot collide with one the template already uses (§5.7).
- **The mark is drawn at its natural size at 96 dpi, capped to fit 40 × 20 mm and
  never enlarged.** A logo exported at 3× comes out the right size rather than
  filling the page, and a small one is not blown up into a blur. Still one
  upload — the same picture that appears in the bar — and if that box turns out
  to be wrong the next thing added is a size on the profile, not a second file
  (§5.7, §8.6).
- **An installation that has uploaded no logo generates a document with nothing
  there** — not the brackets, not an empty picture. The same rule every unfilled
  marker already followed.
- **The placeholder list says which marker draws a picture**, so `[tenant.logo]`
  is not pasted into the middle of a sentence by somebody who read it as text.
  The email templates page does not offer it at all: an email has no answer yet
  for what a picture in one would be, and offering something that comes out blank
  is what that page already declines to do (§5.7, §5.13).

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
- **A record form now counts its rows before it builds them** ([XIV-90]). The
  400-row cap on a collection was enforced after the submission had been built
  into one form per row — twice over, since the live form builds a throwaway copy
  beside the real one — so a hand-crafted 401-row post needed 273 MB of the 256 MB
  a request is allowed and answered a 500 instead of the refusal. The rows are now
  counted from the submitted values, before any form exists: 1.9 MB and 31 ms
  against 273 MB and 6.3 s. Same limit, same sentence, same numbers as before —
  `RecordWriter` still refuses independently, which is what keeps the cap true for
  the importer and everything else. A submission whose values cannot be counted at
  all is refused with a message of its own rather than one naming a made-up count.
  See [§5.1](docs/architecture.md#counting-the-rows-before-the-form-is-built-xiv-90),
  which also has the figures and one thing left open.

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
[XIV-90]: https://xivi.youtrack.cloud/issue/XIV-90
[XIV-94]: https://xivi.youtrack.cloud/issue/XIV-94
[XIV-89]: https://xivi.youtrack.cloud/issue/XIV-89
[XIV-92]: https://xivi.youtrack.cloud/issue/XIV-92
[XIV-95]: https://xivi.youtrack.cloud/issue/XIV-95

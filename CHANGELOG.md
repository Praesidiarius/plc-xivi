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

- **The sign-in page stopped wearing the application's furniture** ([XIV-79]). It
  no longer inherits the footer every signed-in page carries — a bar in the
  bottom-left corner of a page that is otherwise a single centred card — and the
  *Login* heading over an email field, a password field and a Sign in button is
  gone. The version it used to show is still there and now sits inside the card,
  under the button, because "which version is this" is the first question about
  any bug report and this is the page somebody reporting "I cannot sign in" is
  looking at. The hostname is now the page's heading, which is the one line on it
  that says **which installation you are signing into**.

### Fixed

- **No environment file the production image cannot use is inside it any more**
  ([XIV-56]). `.dockerignore` listed `.env.test` and not `.env.dev`, so the dev
  file shipped — naming a mail catcher that does not exist there. It is now a
  pattern rather than a list, so a new env file is excluded by default instead of
  by somebody remembering.
- **`tenant:reset` survives a real `--records`** ([XIV-74]) — turning the one knob
  a developer reaches for used to exhaust the memory limit, because the whole
  rebuild happens in one process and Symfony's profiler keeps every statement it
  sees, with a backtrace each. `2000` records in four modules now takes about 36
  seconds instead of dying at contact number 1,290, and no `--no-debug` is needed
  at any size. `tenant:demo:generate` gets the same treatment.
- **A reset that fails part-way says what it left behind** ([XIV-74]) — it has to
  destroy the tenant before it can rebuild it, so a failure after that point costs
  the tenant. It now prints what is gone, what the control plane holds right now,
  which modules were installed and filled, and the command line to start over;
  §4.1 of the brief argues why that rather than a temporary slug and a swap. Two
  common failures (anything Doctrine throws, an empty `--admin-email`) previously
  slipped past the handler and printed a stack trace that never mentioned the
  dropped database.

- **Cutting a release can no longer forget the README.** Its version line said
  `17.0.2` for the whole of 17.0.3, because nothing checked it — the release
  workflow verified `src/Version.php` and the changelog file and stopped there.
  It now refuses a tag whose version the README disagrees with, and the
  procedure names the step.
- **The test database volume no longer fills** ([XIV-78]). `bin/ci` drops the
  test databases earlier runs left before it starts, so the steady state is one
  run's worth — 48 databases, 440 MB — rather than growing towards classes ×
  workers, which is what had the tmpfs enlarged three times. It stays at 3g,
  now about seven times what a run needs. See §9.2.
- **A connection nobody closed can no longer fail a run** ([XIV-78]). A Panther
  web server left behind by an earlier browser suite held a tenant database
  open, and every class that reclaimed that tenant failed with `database … is
  being accessed by other users`. The reclaim terminates those sessions first.
- **A full test volume says so** ([XIV-78]), in one line, instead of arriving as
  a hundred tests reporting "no connection to the server" — which is what a
  Postgres that has aborted its checkpointer and restarted looks like.
- **Act on upgrade: `bin/ci` deletes leftover test databases.** Its first step
  drops every database and role on `database-test` whose name carries this
  checkout's test prefix. Dev tenants are on the other server and are untouched,
  and the step refuses if the server it is pointed at is not the disposable one
  — but a test database you were keeping to look at needs reading, or dumping,
  before the next run rather than after.

[XIV-79]: https://xivi.youtrack.cloud/issue/XIV-79
[XIV-78]: https://xivi.youtrack.cloud/issue/XIV-78
[XIV-56]: https://xivi.youtrack.cloud/issue/XIV-56

[XIV-74]: https://xivi.youtrack.cloud/issue/XIV-74

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

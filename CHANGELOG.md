# Changelog

What has changed in Xivi, and when. The design *reasoning* lives in
[docs/architecture.md](docs/architecture.md) and stays there; this file is the
record of what was built.

Released versions are archived one file per release under
[docs/changelog/](docs/changelog/); see [the index](#releases). This file holds
only what has not shipped yet, so it stays a page rather than a history.

## How the version works

The format is `17.MINOR.PATCH`, and it is **not** semantic versioning.

- **17** is a *generation*, not a semver major. It says which Xivi this is, and
  it changes only when there is a new one, which is a business decision rather
  than a technical one. Breaking changes inside a generation do not touch it.
- **PATCH** is the release counter. It moves every time a release is cut, which
  at this project's current pace is roughly daily, so a year of it reads
  17.0.351 rather than like semver. Features move it; so do fixes.
- **MINOR** is for a release big enough to be worth naming. It has not moved
  yet.

Deliberately *not* "patches are fixes, minors are features". That rule was here
first and was false the day 17.0.1 shipped two large features under it. A
version scheme nobody follows is worse than an unusual one everybody does.

**The version moves on release, not on feature.** Work lands under *Unreleased*
and moves nothing; cutting a release is the deliberate act of renaming that
heading and dating it. Nothing else can advance the number, which is what stops
it creeping while the project is moving quickly.

The number lives in [`src/Version.php`](src/Version.php) and is shown at the
foot of every page, inside the card on the sign-in page, which has no footer
(XIV-79). It is not yet tied to git tags.

### Writing an entry

**One bullet per ticket, one to three lines.** Say what changed for somebody
using Xivi, and point at the brief section for why. The long version of the
reasoning belongs in `docs/architecture.md`, which is the rule the top of this
file has always stated; entries that restate it make this file grow at the rate
work happens, which is the rate nobody can read.

Anything a reader has to **act** on is called out as its own bullet even when
it is small: a changed status code, a dropped guarantee, a manual step on
upgrade. That is what somebody opens a changelog for.

If a decision is worth keeping and is not in the brief yet, **put it in the
brief first**. An entry is not the place a design decision lives.

### Cutting a release

1. Move the whole `Unreleased` block into `docs/changelog/<version>.md`, under
   a `# Xivi <version> (<date>)` heading, and take its `[XIV-N]:` link
   definitions with it. Sections drop one level: `###` becomes `##`.
2. Add a line to the [release index](#releases) below.
3. Bump [`src/Version.php`](src/Version.php).
4. Tag the merge commit `v<version>` and push the tag. That is what publishes:
   `.github/workflows/release.yml` posts the file from step 1 as the GitHub
   release, and fails if the file is missing or the tag disagrees with step 3.

There used to be a fifth step, updating a hand-written version line near the
top of `README.md`, and a gate in the release workflow to catch the times it
was forgotten, because 17.0.3 shipped saying 17.0.2. **The line is gone
instead** (XIV-112). `Version::CURRENT` was always the number the application
actually serves; a second copy of it in prose could only ever agree or be
wrong, and the cheapest way to stop it being wrong was to stop writing it
twice. The README links here rather than restating anything.

`bin/ci` gates on this file having changed, which keeps working: new work
always lands in `Unreleased` here.

## [Unreleased]

- **An installation learns who is actually talking to it over IPv6**
  ([XIV-61], §4.8). Docker's bridge is IPv4-only unless told otherwise, and a
  published port still answers IPv6 through the userland proxy, which opens its
  own connection and hides the client behind the bridge gateway. The control
  plane's IP allow list decides from the socket peer, so it would have refused
  every IPv6 visitor including the operator, failing closed and saying nothing.
  The deployment's network enables IPv6 now, and AAAA records are safe to add
  once it does.


## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.8](docs/changelog/17.0.8.md) | 2026-08-20 | A deploy definition proven against a real target: Deployer over SSH, on-demand TLS that asks before it issues, cron generated from the job list, and the nine things that only fail once there is somewhere to deploy to |
| [17.0.7](docs/changelog/17.0.7.md) | 2026-08-19 | Vouchers on orders and lines, VAT-inclusive prices, periods that cannot overlap, shared lists, form sections, and a brief that went from 13,539 lines to 355 |
| [17.0.6](docs/changelog/17.0.6.md) | 2026-08-18 | Two images, a price list, vouchers, dashboards you arrange, and a day of guarantees made checkable |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations, and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

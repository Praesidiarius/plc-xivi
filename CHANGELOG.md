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
4. Tag the merge commit `v<version>` and push the tag. That is what publishes:
   `.github/workflows/release.yml` posts the file from step 1 as the GitHub
   release, and fails if the file is missing or the tag disagrees with step 3.

There used to be a fifth step — updating a hand-written version line near the top
of `README.md` — and a gate in the release workflow to catch the times it was
forgotten, because 17.0.3 shipped saying 17.0.2. **The line is gone instead**
(XIV-112). `Version::CURRENT` was always the number the application actually
serves; a second copy of it in prose could only ever agree or be wrong, and the
cheapest way to stop it being wrong was to stop writing it twice. The README
links here rather than restating anything.

`bin/ci` gates on this file having changed, which keeps working: new work always
lands in `Unreleased` here.

## [Unreleased]

### Added

- **Xivi has documentation, and it is published**
  ([XIV-112](https://xivi.youtrack.cloud/issue/XIV-112)) —
  <https://praesidiarius.github.io/plc-xivi-docs/>. Installing Xivi, running an
  installation, and what a record, a module and a tenant actually are, for the two
  people who meet Xivi from outside the code: whoever deploys it and whoever uses
  it. Source in [plc-xivi-docs](https://github.com/Praesidiarius/plc-xivi-docs),
  built on every push and gated on having no broken links.
- **The control plane can be restricted to a list of addresses**
  ([XIV-124]) — `CONTROL_PLANE_ALLOWED_IPS` takes addresses and CIDR ranges, IPv4
  and IPv6, and a request to the control-plane host from anywhere else is refused
  with an empty 403 before anything else looks at it. **Empty is the default and
  means no restriction**, so an installation that sets nothing is unchanged, and
  customers are never affected either way. It is a fourth layer in front of the
  sign-in, the operator-only provider and `ROLE_OPERATOR` rather than a
  replacement for any of them — worth having because the control-plane *hostname*
  has never been a boundary (§4.3). The address is resolved through
  `TRUSTED_PROXIES`, never from a raw header, so **set `TRUSTED_PROXIES` too if
  there is a load balancer in front** or every request will look like it came
  from the balancer. Reasoning in `docs/architecture.md` §8.9.
- **`deploy:check-control-plane` reports what that list admits**
  ([XIV-124]) — run it **before** you depend on the variable. It names what is
  admitted, refuses to be quiet about an entry that is not an address, answers
  `--address=198.51.100.7` directly, and offers the address your SSH session came
  from. Exit 3 if the list is unusable or would refuse an address you asked
  about. **Getting this wrong locks the operator out with no customer-facing
  symptom**, which is the whole reason the command exists.

### Changed

- **`README.md` is 84 lines instead of 962**
  ([XIV-112](https://xivi.youtrack.cloud/issue/XIV-112)) — what Xivi is, a
  Quickstart that fits on a screen, and links. Nothing was rewritten and nothing
  was dropped: the deployment half is now *Running an installation* on the
  [documentation site](https://praesidiarius.github.io/plc-xivi-docs/running/) —
  configuration, hostnames, deploying, the two cron entries, self-service signup
  and the command reference, in the order somebody deploying meets them — and the
  feature inventory is
  [What exists today](https://praesidiarius.github.io/plc-xivi-docs/what-exists/).
- **`DEVELOPING.md` is new**, and is where the developer half went: `bin/compose`
  and why it exists, dev tenants, Adminer, Mailpit, Symfony AI Mate, `bin/ci`,
  worktree stacks and the package layout. It stays in the repository because it
  has to travel with the commit that changes it.
- **The README no longer carries a hand-written version, and the release
  checklist lost the step that maintained it.** `Version::CURRENT` is the number,
  the release workflow still gates on *that*, and the third gate over the README
  line is gone with the line. **Action on your next release:** *Cutting a
  release* is four steps now, not five.
- **`deploy:check-hosts`, `deploy:check-secrets` and a refused provisioning now
  point at the documentation site** instead of at a README section that has
  moved.
- **`docs/architecture.md` stays here**, and the README now says why: it is the
  record of *why* each decision was made, it is cited by section number
  throughout the issue tracker, and it has to travel with the commit that changes
  the behaviour it describes. The site is the other half — what an installation
  *is*, rather than why it was built that way.

[XIV-124]: https://xivi.youtrack.cloud/issue/XIV-124

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.6](docs/changelog/17.0.6.md) | 2026-08-18 | Two images, a price list, vouchers, dashboards you arrange — and a day of guarantees made checkable |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |


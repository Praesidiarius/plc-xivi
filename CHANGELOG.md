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

- **A field can hold formatted text** ([XIV-131]) — a new field type, *Formatted
  text*, written as Markdown: headings, bold, italic, lists, links and tables. Add
  one in the field editor like any other. The form is a plain textarea with a
  live preview under it — no toolbar, no editor to download, and nothing fetched
  from anywhere. The record page draws the formatting; a list column, a
  spreadsheet export cell and a generated Word document get the words with the
  marks taken off, so `**Warning:**` prints as `Warning:` rather than as
  punctuation you never meant to send. Filters and search match what you typed,
  marks included. §5.21 has the argument for each of those, including why this is
  a new type rather than a checkbox on *Long text*.
- **Nothing changes for a *Long text* field** ([XIV-131]) — existing textarea
  fields keep their type, their widget and their behaviour, in every tenant.
  There is deliberately **no way to convert one into a formatted field**: it would
  silently reinterpret every value already stored — a parts list typed with `*`
  bullets would change meaning in every record with nothing to see. Add a
  formatted field and move the text across if you want one.
- **An article is sold in a unit, and a line says so** ([XIV-118]) — hours, days,
  pieces, kg, m, m² or litres, shipped as a starting set and seeded into your own
  definitions at install like every other label (§6.1). An order line and an
  invoice line show it beside the quantity, on the record page and in a generated
  document, so `2.5` reads `2.5 hours`. The unit is the *article's*: an order line
  takes a copy of it the way it already takes the title and the price ([XIV-18]),
  which means an order placed in hours goes on saying hours after the catalogue
  changes, and the page marks the line as drifted when the two disagree. A custom
  line has no article and gets the same list to pick from by hand; a comment or
  subtotal line has no quantity and is not offered one. **Nothing changes for an
  article that has no unit** — its lines read exactly as they did — and the field
  is optional for that reason. §5.20 has the whole argument, including why units
  are not pluralised and what still has to happen before you can add "pallet".
- **On upgrade:** an existing installation does not gain the field until somebody
  takes it. It appears in *what your module has grown* on each of Articles, Orders
  and Invoices (§7.2.1) and is added by choosing it there — three separate offers,
  because they are three modules. Taking it on Articles alone gets you a unit
  nothing prints. Nothing already stored changes, and no document you have sent
  reads differently.
- **Action after taking it on Orders or Invoices:** the line's form row is a
  twelfths grid and the blueprint made room by narrowing the description, which an
  upgrade deliberately does not do to a field you already have (§7.2.1 only ever
  adds). So the row adds up to thirteen and the unit wraps to a line of its own
  below the number. Narrow *Description* by one in the field editor — 4 to 3 on an
  order line, 3 to 2 on an invoice line — and it sits beside the quantity. A new
  installation is already laid out that way.
- **A record's numbers, as a line** ([XIV-121]) — an article's page now carries a
  small chart of what its price has been over time, and a sentence saying how
  many times it has changed and between which two figures. It is **not a chart of
  `price`**: any numeric field is offered — a VAT rate, a quantity, a stock level
  you added yourself — and a picker on the card chooses which one is drawn, so a
  field you invented gets a trend with no new version of Xivi. A reference is
  never offered, because plotting one would be plotting record ids.
- **Nothing new is stored, and nothing has to be switched on.** The chart is read
  out of the record history that has been kept since the beginning: it records
  the values, not just the fact that something changed, so every price your
  catalogue has ever had is already there and every article you have — including
  the ones created years ago — has a chart today. An article nobody has ever
  edited draws a flat line and says "unchanged since" the day it was made, which
  is a real answer rather than an empty box.
- **A reader sees nothing about a record they may not open**, chart included; the
  card is checked against the record itself and not merely the module, so
  somebody restricted to their own records cannot read a colleague's prices off
  an axis.
- **The first chart in Xivi, and the only one for now** ([XIV-121]) — charting
  was deliberately left out in [XIV-66] on the argument that a chart earns its
  place where a *trend* is what is being read and nowhere else. That argument is
  unchanged; a price over time is the case it admits. Dashboards of charts and
  anything totalling across records are still not built. The library is Chart.js,
  MIT, served from your own installation like every other asset — **no request
  leaves a customer's browser to a third party**, which is unchanged and remains
  the promise. It costs about 590 KB inside the customer-facing image, roughly a
  sixth of one percent of it. Reasoning in `docs/architecture.md` §8.3.1, and why
  history could answer this without a new table in §5.2.

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
- **Scheduled jobs can tell an external monitor that they ran** ([XIV-126]) —
  optional, off by default, and an installation that sets nothing behaves exactly
  as before. Set `XIVI_MONITOR_PINGS` to `command=<ping url>` pairs and each
  watched command pings `<url>/start` when it begins and `<url>/<exit code>` when
  it ends, so the *service* raises the alarm when a job stops running. The ping
  carries the fact and the exit code and nothing else — no tenant, no customer,
  no counts, no version. Healthchecks is the recommendation because you can
  self-host it (BSD-3-Clause); Better Stack's heartbeat URLs work unchanged. The
  reasoning, the comparison of four services, and why an in-house checker is
  rejected for good are `docs/architecture.md` §4.5.
- **`bin/console deploy:crontab`** ([XIV-126]) — prints the cron entries this
  build needs, with what goes stale without each one and whether anything is
  watching it. Output is a crontab, comments and all, so it can be redirected
  rather than retyped. **Action:** run it on your installation. It exits 3 if
  some jobs are watched and others are not, which is the state that looks like
  being covered and is not.
- **`tenant:purchase:collect` was missing from every list of cron entries**
  ([XIV-126]) — it shipped with [XIV-102] and reached no documentation page and
  no crontab, so a customer's request to buy a module never reached the operator
  screen on an installation that followed the instructions. **Action:** if you
  set your crontab up before today, add it.

### Fixed

- **`importmap.php` is a generated file again**
  ([XIV-111](https://xivi.youtrack.cloud/issue/XIV-111)). It carried a comment
  explaining why only one of Tom Select's four stylesheets is pulled in — real
  reasoning, in a file Symfony Flex regenerates from a template whenever a
  package is added. It was dropped twice in two days and caught both times only
  because somebody read the diff. The reasoning moved to `docs/architecture.md`
  §5.4, and a test now holds the fact, so a recipe that adds the other three
  fails `bin/ci` instead of shipping three stylesheets served to nobody.
- **A deprovisioning test no longer fails about one run in ten** ([XIV-142]) —
  `pg_terminate_backend` sends SIGTERM and returns as soon as the signal is away,
  so the session it ended is still in `pg_stat_activity` for a millisecond or
  three afterwards; the test asked in the very next statement and, under eight
  parallel workers, sometimes got its answer before the backend had gone. It now
  polls to the same five-second deadline `DROP DATABASE … WITH (FORCE)` keeps,
  and still demands that the session really went. **Nothing in Xivi changed**:
  the drop waits for those backends itself, which the brief now records (§4.1).

### Changed

- **Markdown is rendered in one place now** ([XIV-131]) — the converter, the
  raw-HTML escaping and the sanitizer policy moved out of the email renderer into
  a single core service that email and formatted fields share. **No behaviour
  changed for email**; what changed is that what an email is allowed to contain
  and what a record page is allowed to contain can no longer drift apart. If you
  have overridden the `email` HTML-sanitizer policy in your own configuration, it
  is now called `markdown`.
- **`README.md` is 84 lines instead of 962**
  ([XIV-112](https://xivi.youtrack.cloud/issue/XIV-112)) — what Xivi is, a
  Quickstart that fits on a screen, and links. Nothing was rewritten and nothing
  was dropped: the deployment half is now *Running an installation* on the
  [documentation site](https://praesidiarius.github.io/plc-xivi-docs/running/) —
  configuration, hostnames, deploying, the cron entries, monitoring, self-service
  signup and the command reference, in the order somebody deploying meets them —
  and the
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

[XIV-18]: https://xivi.youtrack.cloud/issue/XIV-18
[XIV-66]: https://xivi.youtrack.cloud/issue/XIV-66
[XIV-102]: https://xivi.youtrack.cloud/issue/XIV-102
[XIV-118]: https://xivi.youtrack.cloud/issue/XIV-118
[XIV-121]: https://xivi.youtrack.cloud/issue/XIV-121
[XIV-124]: https://xivi.youtrack.cloud/issue/XIV-124
[XIV-126]: https://xivi.youtrack.cloud/issue/XIV-126
[XIV-131]: https://xivi.youtrack.cloud/issue/XIV-131
[XIV-142]: https://xivi.youtrack.cloud/issue/XIV-142

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


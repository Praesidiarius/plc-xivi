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

- **The field editor asks the type first, and is three pages instead of one**
  ([XIV-163], §5.4). Clicking **Fields** on a module now offers a choice per
  shape: add a field, edit one, or arrange the form. Adding asks which kind of
  field before anything else and then shows only that kind's settings, so a
  choice field's options and a reference's target are asked on the form rather
  than refused after it. Editing lists the shape's fields and gives each one a
  form of its own. Order, width, section and "on list" are decided together on
  the arrange page, which is also where sections are managed from.
- **You will have to relearn where two things are** ([XIV-163]). The single
  table with every field and every control on it is **gone**, and no URL still
  renders it: a link or bookmark to `/m/<module>/fields` now lands on the three
  doors. Whether a field is a column in the list has moved off the add form to
  the arrange page, so a field added today arrives off the list and joins it in
  a second, deliberate step. Every refusal is unchanged and still enforced by
  the engine rather than by a page.
- **A maximum length, a smallest and a largest are now drawn where they mean
  something** ([XIV-163], §5.4): they used to be settings the editor kept in a
  list of its own and offered for every field there is. They are declared by the
  field type like every other setting now, so `max_length` appears on text
  fields and `min`/`max` on number fields, and on nothing else.

- **A signup from a throwaway address provider is refused** ([XIV-125], §8.12):
  a confirmed signup becomes a real database and a real Postgres role, so the
  intake now turns away addresses at the services whose product is a mailbox
  nobody keeps. The list is short, shipped in `DisposableEmailDomains` and kept
  by hand; **free-mail providers are deliberately not on it**, since a great
  many small businesses sign up from Gmail, GMX or Bluewin. The refusal happens
  before anything is written or sent, and answers `invalid_email` rather than a
  code of its own, so the list cannot be read back one address at a time.
- **Refusals are counted for the operator**: the tenant list gained a section
  naming each provider that has been turned away, how often and when it last
  happened. **Act on it if a domain you recognise appears there**: that is a
  real customer being refused and never told why, and the fix is a line out of
  the list.
- **Unconfirmed signups are now discarded** by `signup:prune`, thirty days after
  their confirmation window closes. **Add it to your crontab**:
  `bin/console deploy:crontab` prints the line, nightly. It removes rows and
  never a tenant.
- **Abandoned tenants are reported and never removed**: `tenant:usage:collect`
  now ends by naming the customers nobody has ever signed in to, with the date
  each was provisioned, and points at `tenant:deprovision`. Nothing on a
  schedule in this repository deletes a customer's database (§4.1, §4.6).
- **The architecture brief is a summary again** ([XIV-159]): §4 to §8 and the
  entry file were distilled from ~12,500 lines to ~2,700 and unslopped. Every
  section number and every rule survives at its old address; the issue-by-issue
  narratives now live only in the tracker and in git history. The same pass
  swept the README and this file, and release headings are now
  `# Xivi <version> (<date>)`.
- **The documentation site was caught up to 17.0.7** ([XIV-160]), in its own
  repository: the inventory, the fourth scheduled job, the grant check, the
  built-in signup page, and the store's refusal of requirement chains are now
  described as they behave.

- **The thousand-tenant fleet is rehearsed, not extrapolated** ([XIV-154]):
  `bin/rehearse-fleet` provisions a throwaway fleet of 1,000 tenants on this
  checkout's own stack, walks a generated additive migration across it, breaks
  tenants behind the registry, kills the walk mid-flight, checks the §4.5
  monitoring pings, and cleans up after itself with a verified count. It only
  touches `rehearsal_*` tenants and refuses foreign fleets and non-dev stacks.
  The measured numbers are on XIV-154 and XIV-61.

- **Invoice PDFs carry a Swiss QR-bill payment part** ([XIV-152], §5.28): the
  slip is appended as the PDF's last page, with the tenant's own IBAN and
  address from the company profile (new fields there) and the invoice number as
  an ISO 11649 creditor reference by default. CHF and EUR only; anything else,
  or missing profile data, produces the invoice without a payment part and a
  message saying exactly why. Library: `sprain/swiss-qr-bill` (MIT, tree
  checked in THIRD-PARTY-NOTICES.md); the PHP image gained `bcmath` and `gd`,
  so **rebuild your containers** after pulling this.

- **`bin/ci --coverage` no longer renders the HTML report** ([XIV-161]): the
  renderer outgrew the 512M limit at ~89k lines and was killing CI, and nothing
  in CI reads it. The floor gate and Codecov keep their Clover file; the
  browsable report is now `composer coverage-html` (run with
  `XDEBUG_MODE=coverage`), and the PHPUnit memory limit is 1G for its sake.

- **A serial test run can no longer be poisoned by a shared tenant's
  deprovision** ([XIV-148]): `SharesATenant`'s "already provisioned in this
  process" bookkeeping lived in a trait static, which PHP copies per class, so
  the six browser classes sharing the `e2e` slug each tore the tenant down
  under DAMA's live connection and every later test inherited a dead one. The
  bookkeeping is now process-wide (`ProvisionedSlugs`), a guard refuses any
  deprovision that would reach a DAMA-cached connection, the
  `SharedSlugReuse*Test` pair proves the reuse serially, and
  `failOnPhpunitWarning` turns the previously invisible subscriber warnings
  into a red run. Reasoning in the brief, decisions §9.2.

- **French and Italian** ([XIV-153]): every catalogue (the application's, the
  engine's and each module's) now exists in fr and it, both are in
  `enabled_locales`, and the picker offers them. French says vous where German
  says du; Italian says tu. `TranslationCatalogueTest` now reads
  `enabled_locales` itself, so enabling a locale with an incomplete catalogue
  fails the build, and `SwissFiguresTest` pins what ICU does under fr_CH and
  it_CH: Swiss French numbers group with narrow spaces and take a decimal
  comma, except in money (§8.4.2).

- **A fleet walk no longer grows by a tenant's worth of debug log per tenant**
  ([XIV-162], §7 item 4): `TenantSwitcher` now empties Doctrine's query log and
  Monolog's debug processor whenever a tenant is left, so `tenant:migrate`,
  `tenant:inspect`, `tenant:schema:validate` and the three nightly collectors
  cost one tenant rather than the fleet. Over 300 rehearsal tenants the
  migration walk went from 88 MB climbing to 120 MB, to 74 MB flat.
- **Nothing to act on for deployments, and the ticket's alarm was wider than
  the bug**: both logs exist only in a debug build, and `bin/deploy` runs with
  debug off, where the walk was already flat at 24 MB across the same 300
  tenants. What was running out of memory was the rehearsal and a developer's
  own fleet commands.

[XIV-125]: https://xivi.youtrack.cloud/issue/XIV-125
[XIV-148]: https://xivi.youtrack.cloud/issue/XIV-148
[XIV-152]: https://xivi.youtrack.cloud/issue/XIV-152
[XIV-153]: https://xivi.youtrack.cloud/issue/XIV-153
[XIV-154]: https://xivi.youtrack.cloud/issue/XIV-154
[XIV-159]: https://xivi.youtrack.cloud/issue/XIV-159
[XIV-160]: https://xivi.youtrack.cloud/issue/XIV-160
[XIV-161]: https://xivi.youtrack.cloud/issue/XIV-161
[XIV-162]: https://xivi.youtrack.cloud/issue/XIV-162
[XIV-163]: https://xivi.youtrack.cloud/issue/XIV-163


## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.7](docs/changelog/17.0.7.md) | 2026-08-19 | Vouchers on orders and lines, VAT-inclusive prices, periods that cannot overlap, shared lists, form sections, and a brief that went from 13,539 lines to 355 |
| [17.0.6](docs/changelog/17.0.6.md) | 2026-08-18 | Two images, a price list, vouchers, dashboards you arrange, and a day of guarantees made checkable |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations, and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

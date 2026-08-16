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

The number lives in [`src/Version.php`](src/Version.php), is shown in the footer
of every page, and is not yet tied to git tags.

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

`bin/ci` gates on this file having changed, which keeps working: new work always
lands in `Unreleased` here.

## [Unreleased]

### Added

- **Totals update while you type** ([XIV-32], [XIV-44]). A line's total and the
  order's net, VAT and gross follow the quantity and the price as they are
  entered, before anything is saved, and read in the reader's own number format.
  The arithmetic is the server's — the same derivers the save runs, so there is
  no second copy of the rounding rule to disagree with the first. See §5.9.
- **Fields have a width, and forms stop being one column** ([XIV-43]). A field is
  drawn in twelfths of a row, so a first name and a last name sit side by side.
  The default comes from the *field type* — a text is half a row, a textarea the
  whole one, a count three twelfths — and a tenant can override it per field in
  the metadata editor. Collection rows lay out the same way, since a row's fields
  are the same thing one level down — and an order or invoice line now declares
  its own widths, so a whole line sits on one row instead of six. Existing forms change appearance on upgrade, which is the
  point; nothing is migrated and no value is written. See §5.
- **The installation can show a logo** ([XIV-48]), in the top bar and on the
  login page. It is supplied by the deployment rather than committed: name a
  file in `APP_LOGO` and put it in `assets/brand/`, which is gitignored. It is the
  favicon too. Unset — the default, and what a fresh clone has — falls back to
  the name in text and the mark drawn as `17`.
- **Language and region are separate settings** ([XIV-50]). Choosing German used
  to mean German-from-Germany, so a Swiss reader saw `1.234.500,00` where their
  country writes `1’234’500.00`. Pick a country on your account, or set one for
  the whole installation on its profile; a region needs no new translation.
- **Dates are written the way the reader's country writes them**, rather than as
  ISO for everybody. What is *stored* is still ISO, which is what makes a date
  sort and filter.
- **Totals on a form group their thousands** ([XIV-47]), in the reader's own
  locale — so a gross total reads `1.234.500,00` in German rather than running
  together. Only the figures nobody types into: what you edit is untouched, and
  `integer` is left alone because the engine cannot tell a count from a year.
- **Money is formatted even before a currency is chosen** ([XIV-47]). An
  installation that has not filled in its profile — which every installation is
  on its first day — was showing amounts through `number_format` with a dot and
  no separators, in nobody's language. It is now grouped and localized, with the
  currency still the only thing missing.
- **The sign-in card is centred**, with a larger logo. What somebody types into
  is not: text that moves as it is typed is worse on the one field on that page
  anybody has to be careful with.
- **The record page is two columns again.** Each card used to be its own grid
  column, so once a record had more than one thing pointing at it the sidebar
  settled beside the last of them with a gap above it.
- **What points at a record is folded away until asked for.** A contact's orders
  and invoices show their heading and how many there are; the list opens on a
  click. Native `<details>`, so it works without JavaScript and with the
  keyboard — the same choice the timeline made.
- **A reference is a link to the record it names** ([XIV-42]), on the record
  page, in a list column and in a collection row. The name is shown to anybody
  who can see the record holding it; the *link* is offered only where the reader
  may actually open the target, and a stale reference stays plain `#id` text.
  See §7.6.
- **Releases are published on GitHub**, from the changelog file the release
  procedure already writes. Pushing a `v*` tag posts
  `docs/changelog/<version>.md` as the release notes — and refuses if that file
  does not exist, or if the tag disagrees with `src/Version.php`. Releases can
  also be published by hand for a tag that predates the changelog file, which is
  how 17.0.0 and 17.0.1 got theirs.

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

[XIV-32]: https://xivi.youtrack.cloud/issue/XIV-32
[XIV-42]: https://xivi.youtrack.cloud/issue/XIV-42
[XIV-43]: https://xivi.youtrack.cloud/issue/XIV-43
[XIV-47]: https://xivi.youtrack.cloud/issue/XIV-47
[XIV-48]: https://xivi.youtrack.cloud/issue/XIV-48
[XIV-50]: https://xivi.youtrack.cloud/issue/XIV-50
[XIV-44]: https://xivi.youtrack.cloud/issue/XIV-44

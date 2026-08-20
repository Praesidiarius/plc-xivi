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

- **An edit form on a field naming several records now shows every one of them,
  even where two are called the same thing** ([XIV-167], §5.29). Two links
  sharing a title used to collapse into one option, and saving dropped whichever
  had lost: the picker's own guard against that ran only when it read a *page*
  of candidates, and an edit form reads its links one id at a time. Only the
  autocompleting picker was affected, so it took a catalogue past twenty records
  to see it.
- **Two records that share a title now both carry their id, in the picker and in
  the form alike** ([XIV-167], §5.29). Where one of the pair used to keep its
  plain name, both are now written `Aktenregal Basis (#47)`. The plain name is
  back the moment there is nothing beside it to confuse it with, so nothing
  changes on a form where no two names clash.
- **A reference picker no longer refuses a set it suggested itself**
  ([XIV-167]). The choice list answered under the wrong keys, so a submission
  holding one id the reader may not have, or one written by the page as a list
  that does not start at zero, came back as "The selected choice is invalid"
  about records that were perfectly valid. An id the reader may not have still
  drops out of the submission, silently, exactly as before.
- **An operator's notice can now go on every page instead of only the dashboard,
  and can say what kind of thing it is** ([XIV-166], §8.16). The publish form
  gained **Where it appears**, dashboard or every page, and **Kind**, one of four
  colours. The dashboard is the default and its cards are unchanged: the colour
  is drawn only on the every-page band, which sits under the top bar of whatever
  page the customer is on.
- **An every-page notice cannot be dismissed by the people reading it, so the
  form now requires an end date for one** ([XIV-166], §8.16). Publishing one
  without an end is refused with a sentence. Dashboard notices are unaffected:
  they still have an optional end and can still be put away per person.
- **Adds a control migration**, so `bin/console doctrine:migrations:migrate` on
  the control-plane database is needed on upgrade ([XIV-166]). Notices published
  before it keep working untouched: they become dashboard notices at the one
  weight they were already drawn in.
- **Fields on the arrange page are now moved rather than renumbered**
  ([XIV-165], §5.1, §8.3). Drag a field by the grip beside it and drop it where
  it belongs. The order box is gone from the page: the number it held is still
  what gets stored, in tens, and the page now works it out from where the rows
  ended up. Nothing is written until Save, exactly as before, so a whole form is
  still rearranged in one go.
- **Dropping a field under a heading puts it in that section** ([XIV-165],
  §5.4). The table draws the headings in the order the form does, including
  sections nothing is in yet, and the section box in each row follows the drag
  both ways. Where the *headings* sit is still set on the sections page.
- **Dragging is never the only way** ([XIV-165]). Every row has an up and a down
  button beside the grip, reachable with the keyboard and announced with the
  field's name, and a move made with them is read out. What to do is written on
  the page.
- **Adds one front-end dependency, SortableJS** ([XIV-165]). MIT, no
  dependencies of its own, self-hosted through AssetMapper like Tom Select and
  Chart.js; nothing is fetched from a CDN. See `THIRD-PARTY-NOTICES.md` for the
  licence and for why the browser's own drag-and-drop API was not enough.
- **A signup that will never provision now shows up on the tenant list, with the
  person's address and a button that writes to them** ([XIV-108], §8.14). It sits
  above the customer table, is drawn only when there is somebody in it, and names
  where provisioning stopped, how many runs have tried and how long the person
  has been waiting. Which signups qualify is the existing stage enum's answer, so
  a failure the next run may fix is deliberately not listed.
- **Nothing is sent automatically, and that is the decision rather than a gap**
  ([XIV-108]). No attempt count, stage or elapsed time triggers a mail. The
  operator reads the exact message on the page first, presses send, and the mail
  goes out under the instance's own identity in the language the signup recorded.
  It names no cause, because only one stage has one the system established, and
  it carries no link.
- **One message per person, recorded on the signup row** ([XIV-108]). Once it has
  gone, the row shows who sent it and when instead of a button, and a second
  operator posting a stale page sends nothing. **Adds a control migration**, so
  `bin/console doctrine:migrations:migrate` on the control-plane database is
  needed on upgrade; existing signups acquire two empty columns and nothing else
  changes.
- **A record can carry a file** ([XIV-115], §5.30). A new kind of field, **File**:
  the signed contract on a contact, the delivery note on an order, the datasheet
  on an article. Upload it on the record form, replace it or tick it off, and
  download it from the record page. Up to **10 MB**, PDFs and everything else
  alike; nothing paid is involved.
- **The metadata is in your database and the bytes are on a filesystem**
  ([XIV-115], §5.30), one directory per customer, behind `league/flysystem` so
  that object storage is later a configuration change rather than a rewrite.
  **Two things a deployment must do**: mount a **volume** for
  `XIVI_ATTACHMENTS_DIR` in *both* images, and back it up. `pg_dump` is no longer
  the whole backup, and the documentation site's *Running an installation* now
  says so in two steps.
- **A download is a permission, not a link** ([XIV-115], §8.4). It goes through
  the application and is checked against the same rules as the record it hangs
  off, so somebody who may not open the record cannot open the file even holding
  the address. A record they may not see answers 404, as everywhere else.
- **`tenant:deprovision` now takes the files with the database** ([XIV-115],
  §4.1), and its confirmation names the file count and their total size beside
  the record count. **`tenant:files:check` is new**: it reports records pointing
  at missing files and files no record claims, per tenant or across the registry,
  and it reports rather than repairs. Run on demand, deliberately not in
  `bin/deploy`.
- **The upload limits are aligned and stated** ([XIV-115]). The application
  refuses at 10 MB and says so with the real number; PHP's `upload_max_filesize`
  and `post_max_size` and Caddy's request body limit sit above it in that order,
  and a unit test fails if that order is ever broken. **The container's PHP
  configuration changed**, so an installation that pins its own ini has to move
  with it.
- **One file per field, not several** ([XIV-115], §5.29's rule). If several is
  ever wanted it will be a field type of its own, for the reason a `multiple`
  option was refused for links. **Not built, deliberately**: virus scanning,
  thumbnails, previews, versioning a replaced file, and de-duplication. A
  collection row cannot hold a file, because a row has no address to download
  from.
- **A field can now name several records at once** ([XIV-113], §5.29). A new
  kind of field, **Links to several records**, beside the existing single link:
  the tags on a contact, the people on a project, the categories an article is
  in. It uses the same picker, which lets you pick more than one, and the record
  page, documents and emails print the names separated by commas.
- **Filtering one offers "includes" and "does not include", and deliberately not
  "any of these"** ([XIV-113], §5.29, §7.3). Two *includes* filters mean *and*,
  like every other pair of filters. Asking for any of several is the `OR` the
  query layer still has not got, and is left unbuilt rather than faked.
- **Two things such a field will not do, and says so** ([XIV-113]). It cannot be
  marked **unique**, because "unique" has no single meaning for a set of values,
  so the box is not drawn; and a list cannot be put in the order of one, so its
  column heading is not a sort link. Both are refusals with a sentence rather
  than silence.
- **Exports and imports carry several ids in one cell**, separated by a comma,
  and round-trip unchanged ([XIV-113], §5.6). An entry in such a cell that is
  not a record id refuses the whole file and names the cell and the item, rather
  than being quietly dropped.

- **An administrator can now change a field's type on a tenant that already has
  records** ([XIV-146], §7.2, §5.4). The field's own page has a **Change the
  type** link. Picking a kind shows a report first: how many values convert and
  what two of them become, how many the new kind cannot read and what those say,
  whether the change can be undone, and whether a `unique` field would end up
  with two records holding one value. Nothing is written until that report has
  been agreed to.
- **A conversion the data refuses is refused whole, and emptying is a second,
  separate tick** ([XIV-146]). One unreadable value stops the change and names
  itself; emptying those rows is an extra box that starts unticked, and is
  refused outright on a required field. Every value a run rewrites or empties
  goes into the record's own history first, under a new **Type converted** entry,
  so the old spelling stays readable even when nothing can put it back.
- **A tenant whose `phone` is still a text field can reach the shape a new
  installation has** ([XIV-146], §5.23). That is the worked example: the three
  ways people write one Swiss mobile become one stored number, and the rows that
  say "ask reception" are named rather than silently dropped. Nothing converts
  anybody automatically. A shipped module may not request a conversion on
  upgrade, which is now written down rather than merely absent.

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
- **The payment part is now a tick, ticked** ([XIV-164], §5.28). The document
  window and the send window both carry **Add the QR-bill payment part**,
  already ticked, so a copy for the file, a proforma or an invoice that is
  already paid can go out without a slip. The choice belongs to that one
  document and is stored nowhere on the record. The box is absent, without
  comment, where it could not mean anything: on modules that add nothing to a
  PDF, on an installation whose payment settings could not produce a slip, and
  when the Word format is chosen. **A download link kept from before this
  release now produces an invoice without a payment part**, since a request that
  chose nothing is not a request for one; download it from the window again.
  The timeline entry for a sent invoice says whether the slip went with it.

- **`bin/ci --coverage` no longer renders the HTML report** ([XIV-161]): the
  renderer outgrew the 512M limit at ~89k lines and was killing CI, and nothing
  in CI reads it. The floor gate and Codecov keep their Clover file; the
  browsable report is now `composer coverage-html` (run with
  `XDEBUG_MODE=coverage`), and the PHPUnit memory limit is 1G for its sake.

- **A language can no longer hide a bug** ([XIV-45], decisions §9.2). The suite
  spoke English, and in English a number's displayed form and its stored form
  are the same string, which is how XIV-44 shipped past four hundred and eighty
  tests. Two things now: every field type is round-tripped through a form in
  thirty formatting locales derived from `enabled_locales`, so a value that
  stops surviving being shown and read back fails by name and by language; and
  one browser test types a price and reads the total back in every enabled
  language. A fifth language costs its own handful of formatting locales and one
  browser page load, and no edit to either file. Measured: 2.6 s for the round
  trips, 7 s for the browser test.
- **The browser tests give their sessions back, and the leg is seven times
  faster** ([XIV-45]). Panther never closes a Selenium session, so every browser
  test held one of the grid's four slots until the whole run ended and, from the
  fifth test onwards, every test waited on the node's five-minute idle reaper.
  That was most of the leg's running time and the cause of its occasional
  `POST /session` timeouts. Seventeen browser tests now run in 41 s where
  sixteen took about five minutes. **A new browser test class must
  `use App\Tests\Support\ReleasesTheBrowser`**, which is the whole of the fix.

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

[XIV-45]: https://xivi.youtrack.cloud/issue/XIV-45
[XIV-108]: https://xivi.youtrack.cloud/issue/XIV-108
[XIV-113]: https://xivi.youtrack.cloud/issue/XIV-113
[XIV-115]: https://xivi.youtrack.cloud/issue/XIV-115
[XIV-125]: https://xivi.youtrack.cloud/issue/XIV-125
[XIV-146]: https://xivi.youtrack.cloud/issue/XIV-146
[XIV-148]: https://xivi.youtrack.cloud/issue/XIV-148
[XIV-152]: https://xivi.youtrack.cloud/issue/XIV-152
[XIV-153]: https://xivi.youtrack.cloud/issue/XIV-153
[XIV-154]: https://xivi.youtrack.cloud/issue/XIV-154
[XIV-159]: https://xivi.youtrack.cloud/issue/XIV-159
[XIV-160]: https://xivi.youtrack.cloud/issue/XIV-160
[XIV-161]: https://xivi.youtrack.cloud/issue/XIV-161
[XIV-162]: https://xivi.youtrack.cloud/issue/XIV-162
[XIV-163]: https://xivi.youtrack.cloud/issue/XIV-163
[XIV-164]: https://xivi.youtrack.cloud/issue/XIV-164
[XIV-165]: https://xivi.youtrack.cloud/issue/XIV-165
[XIV-166]: https://xivi.youtrack.cloud/issue/XIV-166
[XIV-167]: https://xivi.youtrack.cloud/issue/XIV-167


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

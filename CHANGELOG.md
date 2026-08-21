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

- **The Knowledge index is now a card per topic instead of a page of rows**
  ([XIV-168], §5.22). Each card holds its entries' titles as links to the entry,
  with the day it last changed beside each one, and the search, the filter bar
  and every button above them work exactly as before. Entries with no topic get
  a card of their own at the end, because the topic was never required. A topic
  nobody has written under is not drawn, and a card stops at ten entries, says
  how many it is holding back and links to the full list for that topic.
- **Edit and delete are no longer on the index for Knowledge** ([XIV-168]).
  Both are on the entry's own page, which the title links to, so deleting an
  entry is one click further than it was.
- **Any module can ask for a grouped index now, and no other module has
  changed** ([XIV-168], §5.3). A module names one of its own choice fields and
  the engine does the rest, so the cards are built from that field's *current*
  options: a topic a customer adds in the field editor gets a card without
  anybody writing code. Every other module's list is the table it was, with its
  sortable headers, its pager and its row actions.
- **An installation learns who is actually talking to it over IPv6**
  ([XIV-61], §4.8). Docker's bridge is IPv4-only unless told otherwise, and a
  published port still answers IPv6 through the userland proxy, which opens its
  own connection and hides the client behind the bridge gateway. The control
  plane's IP allow list decides from the socket peer, so it would have refused
  every IPv6 visitor including the operator, failing closed and saying nothing.
  The deployment's network enables IPv6 now, and AAAA records are safe to add
  once it does.
- **A field can hold several options, not just one** ([XIV-169], §5.31). New
  `multi_choice` type beside `multi_reference`: the languages somebody speaks,
  the channels a customer agreed to, the certifications a supplier holds, from
  the field's own options or from a shared list. Stored as a JSON array in the
  field's own option order, so rearranging the options rewrites no record;
  filtered by "holds this one", not sortable, never unique; a chip per value on
  the record page and the first three plus a count in a list column. `choice` is
  untouched.
- **A shared list now sees inside a field holding several of its entries**
  ([XIV-169], §5.31). Removing an entry, counting how many records hold it and
  merging two of them all asked `data ->> 'key' = …`, which for a set is the
  array's own text and matches nothing. On a `multi_choice` field that would
  have let an entry be removed from under the records holding it and made a
  merge report rewriting nothing while leaving those records saying the old
  thing. The comparison is asked of the field's type now. **Nothing to act on:**
  the type is new in this release, so no installation can have such a field yet.
- **The engine has a clock, and modules declare work on it rather than owning
  one** ([XIV-155], §6.7). A module says what recurs and what to do for one
  period; the engine asks every tenant what is outstanding, does it, and
  remembers that it did. No module ships a cron entry, a command or a loop. The
  same period cannot be done twice, however often the clock turns: the record of
  an occurrence is written in the same transaction as the work, so two runs
  meeting produce one result and an attempt that failed is not a run. Catch-up
  after an outage is declared per work kind, every missed period or only the
  latest, and the schedule is read in the customer's own timezone rather than the
  server's. Nothing uses it yet: recurring invoices ([XIV-156]) and memberships
  ([XIV-157]) are the two consumers it was built for.
- **There is a sixth scheduled job: `tenant:work:run`, hourly** ([XIV-155],
  §4.5). A deploy writes `/etc/cron.d/xivi` from the job list in the image, so a
  deployed installation picks it up with no action. **Act on this only if your
  crontab was installed by hand**: re-run `bin/console deploy:crontab` and
  install what it prints, or nothing a customer sets up on a schedule ever
  happens. It walks every customer that is serving requests, skipping the
  suspended and the half-provisioned, and exits with §4.2's codes: 0 walked, 1
  could not run, 3 some customers had work fail and the rest are fine.
- **Act on this: a tenant migration adds a table** ([XIV-155]).
  `bin/console tenant:migrate` after this lands, as after any release that adds
  one. `due_work` is engine bookkeeping, one row per occurrence the clock has
  done, and nothing reads it but the engine.

[XIV-61]: https://xivi.youtrack.cloud/issue/XIV-61
[XIV-155]: https://xivi.youtrack.cloud/issue/XIV-155
[XIV-156]: https://xivi.youtrack.cloud/issue/XIV-156
[XIV-157]: https://xivi.youtrack.cloud/issue/XIV-157
[XIV-169]: https://xivi.youtrack.cloud/issue/XIV-169

- **Coverage is measured with PCOV, and the longest step in CI takes half as
  long** ([XIV-170]). The dev image carried one coverage driver, so every run
  used it without anybody having compared it with anything. PCOV now sits beside
  Xdebug and `bin/ci` names the driver it wants instead of inheriting one: the
  full suite with coverage went from 687s to 328s, over the same 1955 tests, and
  the figure the floor gates on did not move. A stack older than this has no
  PCOV in its image and Compose will not rebuild one it already has, so
  `bin/ci --coverage` refuses in its first seconds and prints the two commands.
- **Run `composer coverage` and `composer coverage-html` by hand with
  `XDEBUG_MODE=off`** ([XIV-170]). Without it the dev stack's Xdebug stays
  active and goes on doing its own work underneath PCOV, which costs about half
  as much again for nothing. Xdebug is untouched otherwise and is still what a
  debugger attaches to; PCOV does line coverage only, so ask for
  `XDEBUG_MODE=coverage` if you want a branch report.

[XIV-170]: https://xivi.youtrack.cloud/issue/XIV-170

- **A partial invoice takes its share of the order's discount, not all of it**
  ([XIV-147], §5.12). Billing a discounted order in parts put the whole voucher
  on the first invoice and none on any later one, and a voucher applied to a
  single line was copied onto every bill whole, so two half invoices came to
  twice what was granted. Each bill now carries the share that matches what it
  bills, and the one that finishes the order takes the balance, so the bills add
  up to the order's discount exactly even when the split does not divide.

- **A discount line on an invoice is the engine's now**, like the one on the
  order ([XIV-147], §5.24). It is worked out on every save from the order's own
  discount, so the form draws it disabled, `Discount` is no longer offered as a
  line somebody can add to a bill, and a figure typed over it is restated.
  Invoices already written are untouched until they are saved again, and a sent
  one is locked and never will be.

[XIV-147]: https://xivi.youtrack.cloud/issue/XIV-147

[XIV-168]: https://xivi.youtrack.cloud/issue/XIV-168

- **An order's voucher pickers offer only the vouchers that can go where they
  are** ([XIV-172], §5.25). A voucher applies either to the document or to one
  line, and both pickers listed all four kinds, so putting one in the wrong
  place was only ever discovered by the save refusing it. Each now lists its own
  family, on the plain select and on the search box alike, and an id from the
  other family is not a choice even when it is typed straight into the request.
- **Act on this if you write orders through anything but the record form.** The
  save still refuses a misplaced voucher, with the same sentence, and that is
  now the *only* place the sentence appears: through the form the value is no
  longer a valid choice, so it arrives as nothing and the order saves without
  it, the way every unofferable link on that form has always behaved. Imports,
  copies and anything else writing through the engine are refused as before.
- A reference field's `variant` option may now name several kinds, as a list,
  and the search endpoint takes them as a repeated `variant[]` parameter. Fields
  naming one kind are unchanged, and so is every stored definition.

[XIV-172]: https://xivi.youtrack.cloud/issue/XIV-172
- **The trend chart's x axis reads as dates again** ([XIV-174], §8.4.2). It was
  labelled `1'787'200'000'000` for anybody whose account or installation names a
  country. The page's `lang` carried Symfony's `de_CH` where HTML wants
  `de-CH`, and `Intl.DateTimeFormat` throws on the underscore rather than
  ignoring it, so the browser abandoned the dates, the tooltip heading and the
  line's colour in one go and Chart.js printed the raw millisecond count. The
  `lang` attribute is a real language tag now, which is worth knowing about
  because everything else reading the document's language was being handed the
  same broken value.
- **And no two labels on it say the same day** ([XIV-174]). Ticks land on
  midnight rather than on round millisecond counts, so a record made yesterday
  is labelled with the day it crossed instead of with the same date twice. The
  labels also survive the field picker redrawing the card, which they did not.

[XIV-174]: https://xivi.youtrack.cloud/issue/XIV-174
- **A field can put its values at the top of the record page** ([XIV-173],
  §5.26). Tick "at the top" on the arrange page and a `choice` or `multi_choice`
  field's values are drawn as chips beside the module name, the state and the
  overdue badge, in the list entry's own colour and icon. Make one list called
  Tags, point fields at it from Contacts and Orders, and each module decides for
  itself whether they show up there. Three chips are drawn and the rest are
  counted; the field is still on the form and still in the record below, so
  nothing moves out of the section it was put in. A record with nothing in a
  promoted field shows nothing at all.
- **The option is on the field, not on the list** ([XIV-173], §5.26). A list is
  shared across modules on purpose, so an option on the list would decide for
  every module at once. The box is offered only for a field whose values are a
  set you keep; on anything else the engine refuses it and the arrange page
  draws a dash. Changing such a field to a type with no value set clears the
  flag rather than refusing the change.
- **Run `bin/console tenant:migrate` after merging** ([XIV-173]). One additive
  column, `field_definition.is_promoted`, defaulting to false, so every existing
  field stays where it is and no record page changes until somebody ticks the
  box.

[XIV-173]: https://xivi.youtrack.cloud/issue/XIV-173
- **And only the vouchers that can be used today** ([XIV-175], §5.25). The
  pickers still offered one that expired last month, or that starts next year,
  and the save was again the first thing to say so. Both are out of both
  pickers now, on the select and on the search box, and an expired id is not a
  choice when it is typed straight into the request either.
- **An order keeps the voucher it was agreed with after that voucher expires**
  ([XIV-175], §5.25). What the calendar narrows is what may be newly chosen: a
  document that already names a voucher goes on naming it, and re-saving it
  takes no use, gives none back and is refused nothing, exactly as before. A
  picker that dropped the stored value would have taken the discount off a
  document the shop had already agreed to.
- **Vouchers that are used up are still offered, deliberately** ([XIV-175]).
  Whether a use is left is a count read at a moment rather than a property of
  the voucher, and it is decided inside the statement that takes the use so
  that two checkouts cannot both be told yes. A list that hid an exhausted
  voucher would be promising the ones it showed, which it cannot; the save says
  so instead, with the sentence it always had. A line voucher restricted to an
  article is likewise still offered, because whether that holds is a fact about
  the line rather than about the voucher.
- **Act on this if you write orders through anything but the record form.** As
  with [XIV-172], the refusals at the write are untouched and are now the only
  place the `expired` and `not yet valid` sentences appear: through the form the
  value is no longer a valid choice, so it arrives as nothing and the order
  saves without it. Imports and copies are refused as before.

[XIV-175]: https://xivi.youtrack.cloud/issue/XIV-175


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

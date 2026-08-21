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

- **A module's own field options now reach a tenant that installed it earlier**
  ([XIV-176], §7.2.2). Which voucher kinds an order's two pickers admit is the
  module's answer, not the customer's: no control draws it and only the
  installer ever wrote it. Options like that are read from the blueprint every
  time they are read, so XIV-172's narrowing reaches an existing shop with
  **nothing written** into its definitions: no screen, no command, no consent
  and no migration. The customer's own label, width, position and `required`
  are untouched, and §7.2.1's upgrade offer is unchanged.
- **A narrowing a tenant's shapes cannot express is dropped rather than
  applied** ([XIV-176]). A picker whose target module has no kinds would
  otherwise list nothing at all instead of listing everything, which is how a
  module improving its own blueprint could empty a form across an instance.
- **A voucher a document already names stays on it** ([XIV-176], §7.2.2), the
  way XIV-175 already kept one that expired after the order was agreed. The
  picker shows it and a save that changes nothing keeps it; a document taking
  such a voucher afresh still meets the refusal naming the field.
- **`tenant:inspect` prints what the engine reads** ([XIV-176]). Where the
  stored row and the effective options differ it shows both, and it names how
  many records hold a link the narrowing no longer offers. Development only,
  and it counts rather than fixing anything.

- **A module can draw its own records on its index now, and the engine does not
  learn what it drew** ([XIV-178], §5.3). One seam: a module hands back a
  template name and its data, and §5.3's page includes that template where it
  would otherwise draw the table. Everything above stays the engine's on every
  module: the heading, Export, Import, Templates, Email templates, Fields, New,
  the filter form and both empty states. A module never gets the page, a
  controller or a route. A module whose customer has deleted or converted the
  field its layout needs gets the table back rather than an error.
- **The card index belongs to the Knowledge module, not to the engine**
  ([XIV-177], §5.22). It was built the other way round first, as a general
  grouping capability a module declared with one line, and it had exactly one
  declaration; the brief's first rule is that an abstraction is earned by a
  second concrete use case. The cards, their order, their ceiling, the unfiled
  card and the card markup are all in `packages/knowledge` now, behind the seam
  above. **Nothing on screen changed and no release ever had the capability**,
  so there is nothing to act on unless you are working from `main`, where
  `GroupedList`, `ModuleBlueprint::$groupedList`, `RecordGrouper` and
  `RecordGroup` are gone and `Xivi\Core\Record\IndexBodyProvider` is what a
  module implements instead.
- **`Enumerates::optionsOf()` is off the interface again** ([XIV-177]). It was
  promoted onto it for the card index and had no other interface-typed caller;
  `ChoiceFieldType` keeps the method and every caller reaches it there,
  including `MultiChoiceFieldType`, which asks the single-choice type it wraps.
  That promotion is what left `multi_choice` abstract by omission when two
  branches merged on `main`, and a field type implementing `Enumerates` no
  longer has to answer it.
- **Templates shipped by core or by a module are checked for the boundary they
  cannot break in code** ([XIV-178], §3). `deptrac` reads PHP and cannot open a
  Twig file, so a `path()` call in a module template would be the module
  learning the application's routing table with nothing to say so. A unit test
  refuses route helpers there, and refuses a `trans` that names no domain.
  Naming one is allowed whichever catalogue it is, because saying so is the
  decision.

[XIV-150]: https://xivi.youtrack.cloud/issue/XIV-150
[XIV-171]: https://xivi.youtrack.cloud/issue/XIV-171
[XIV-176]: https://xivi.youtrack.cloud/issue/XIV-176
[XIV-177]: https://xivi.youtrack.cloud/issue/XIV-177
[XIV-178]: https://xivi.youtrack.cloud/issue/XIV-178

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
- **The cards are built from the topic field's *current* options, and no other
  module has changed** ([XIV-168], §5.3). A topic a customer adds in the field
  editor gets a card without anybody writing code, and a customer who removes
  the field gets the ordinary list back rather than an error. Every other
  module's list is the table it was, with its sortable headers, its pager and
  its row actions.
- **Installing a module asks the database once which tables are taken, not once
  per table** ([XIV-171]). Postgres answers "does this table exist" by listing
  every table in the database, and the installer asked it per table: three
  listings to install an order module, one of which is its history. The refusal
  happens before any DDL now and names every clashing table rather than the
  first, so a clash on a collection no longer leaves the module's own tables
  standing.
- **A test cannot see another test class's records, and that is a test now**
  ([XIV-171]). It was true by construction, and nothing would have gone red if a
  change to `SharesATenant` had made two classes share a tenant, which is the
  first thing anybody trying to make provisioning cheaper reaches for. The new
  pair commits a contact into one class's tenant and proves the next class
  cannot read it, corroborated by the same reader finding it one tenant along.
- **Neither ticket's actual proposal was built, and the measurement is why**
  ([XIV-171], [XIV-150], §9.2). Serially, provisioning test tenants is 23 s of a
  620 s run, so cloning a template database cannot repay its staleness and its
  cluster-wide role problems, and sharing tenants between classes buys the same
  seconds by giving up the one property the suite exists to prove. What did
  change is smaller and elsewhere: the test container no longer wraps every form
  type in the profiler's data collector, filling in a panel nothing opens.
  `tests/Functional/Engine` goes from 591 s to 529 s and the whole serial suite
  from 686 s to 619 s.

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

- **The store is two shelves now, not one wall of tiles** ([XIV-140], §6.3.1).
  *Modules you have* comes first, compact, in the customer's own words for each
  one; *modules you can add* follows and no longer repeats what they already
  have. A module the store has stopped offering stays on the first shelf,
  because leaving the store never uninstalls anything.
- **Tiles say what a module needs, and the offer is in alphabetical order**
  ([XIV-140]). Every requirement is named, satisfied ones included, with the
  missing ones marked; the order is the reader's alphabet in their own language
  rather than the module keys, which on a German screen was no order at all.
  Install and *Ask to buy* are on the tile, under the same two grants as on the
  module's own page.
- **A search box over the store** ([XIV-140], §3.2). A `q` parameter matched
  against module names and the lists they create, so a narrowed store is a URL
  you can reload or send. Nothing is indexed and nothing is ranked.
- **No categories, and that is the decision** ([XIV-140], §6.3.1). A module
  belongs to as many trades as sell it, so grouping by trade is [XIV-139]'s
  packages rather than a second taxonomy on the module.
- **The store page no longer reads the database once per module** ([XIV-140]).
  It asked the control plane twice and the tenant once for every tile, which at
  six modules was invisible. One read each now, whatever the catalogue holds.

[XIV-139]: https://xivi.youtrack.cloud/issue/XIV-139
[XIV-140]: https://xivi.youtrack.cloud/issue/XIV-140
- **An article can be sold in more than one variant, each with its own price**
  ([XIV-133], §5.32). A T-shirt in S, M and L is one article and three sizes
  instead of three unrelated articles: an article is now *plain*, *sold in
  variants* or *a variant*, and adding one asks which, the way adding a contact
  already asks whether it is a person or a company. A variant brings its own
  title and its own price and shares the base's description, so an edit to the
  description is one edit. Its unit and VAT rate arrive filled in from the base
  and are the variant's own afterwards.
- **An article sold in variants cannot be put on an order line** ([XIV-133]).
  It is not a thing anybody can pick and pack, so the pickers on an order line,
  on an invoice line and on a voucher's article restriction offer the plain
  articles and the variants and leave the base out. A line naming a variant
  records which variant was sold, and an invoice made from that order carries
  it, because a variant is an ordinary article record and nothing about the link
  changed.
- **Nothing about an article without variants changed**, including every order
  already written ([XIV-133], §5.32). Turning an existing article into one sold
  in variants is a change to one dropdown; its price stops being asked for and
  is kept, and every order line that already named it still says what it said,
  because a line holds its own copy of the title and the price.
- **Act on this if you have a tenant from before today.** Variants reach new
  installations only, which is the standing rule that a customer's definitions
  are theirs (§6.1): the module upgrade offers the two new *fields* and cannot
  make the module have kinds, so taking them there would give you a dropdown
  that decides nothing. Rebuild a development tenant with `tenant:reset`
  instead. There is no migration in this change.
- **A record can now take a value from a record it points at, not only a line**
  ([XIV-133], §5.32). Inheritance is what an order line has always used to copy
  an article's title and price; it works on a record's own fields now, with the
  same "copied once, never over something typed" rule and the same marker when
  the copy and the source have since diverged. Nothing changes for a field that
  does not declare it, which today is every field outside Articles and Orders.
- **Not in this**: stock and inventory, an attribute matrix that turns size ×
  colour into nine variants, and barcodes beyond a field you add yourself
  ([XIV-133], §5.32). Demo data is still all plain articles, for the reason
  generated vouchers restrict nothing.

[XIV-133]: https://xivi.youtrack.cloud/issue/XIV-133


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

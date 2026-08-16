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

- **An agent can ask this installation what it actually is** ([XIV-76]). A
  committed MCP extension, `packages/xivi-mate`, exposes five tools through AI
  Mate: which tenants exist and whether each one's schema is current, what one
  tenant's installed modules *really* look like — every field with its type,
  options, variants and its derived/system flags — the module catalogue and each
  module's state, and the two destructive commands `tenant:reset` and
  `tenant:deprovision`. The destructive ones call the commands, so their
  guardrails apply unchanged, and both name what they destroyed in the result.
  See §6.4 for why this is not answerable from the repository at all.
- **`tenant:inspect` answers the same three questions from the console**
  ([XIV-76]). Nothing the tools expose is tool-only — Mate's server is a process
  that can drop, and a shell has to stay sufficient. `--json` prints exactly what
  the tools return. Development and test only, beside the demo commands.
- **Act on upgrade: a fresh checkout needs one extra step to get the tools**
  ([XIV-76]). After `composer install`, run `vendor/bin/mate init` and then
  `vendor/bin/mate discover` — the latter is what writes `xivi/mate` into the
  gitignored `mate/extensions.php`, and Mate's Composer plugin is deliberately
  outside `allow-plugins` so nothing of Mate's runs during `bin/ci`. Without
  `discover` the tool list silently stays at ten. See the README.
- **A tenant can install a module themselves, from a store** ([XIV-6]). Three
  screens — what this build offers, what each module is, and a wizard that
  chooses a preset and says plainly that the choice cannot be changed later
  (§6.1; making it changeable is [XIV-70]). A module whose requirements are
  missing names them with a link instead of failing on submit, and nothing is
  chain-installed. Installing writes only your own database, and
  `tenant:module:install` is unchanged. See §8.4.3 and §6.3.
- **Act on upgrade: browsing and installing are a new permission axis**
  ([XIV-6]). They are not `ModuleAction` cases — browsing is about no module and
  installing is about one you have not got — so **no existing tenant has them**,
  including the groups `tenant:permissions:grant-all` created. Administrators
  still reach the store through the `ROLE_ADMIN` bypass; anybody else needs the
  two new grants, on the new **The store** section of the group and user screens.
  Nothing was migrated and nothing needs to be: the grant table already held a
  subject, a verb and a scope. See §8.4.3.
- **A tenant can be removed from a command, and rebuilt with one** ([XIV-72]).
  `tenant:deprovision <slug>` drops the control-plane row, the database and the
  role through the same code provisioning uses — it names what it is about to
  destroy and how many records are in there, defaults to *no*, and **refuses an
  unattended run unless `--force` was typed**, because `--no-interaction` alone
  is a default rather than consent. It ships: removing a customer is a real
  operation, and an operator who cannot do it from the console will do it in
  `psql`. See §4.1, which also argues why a status of `suspended` was *not* made
  a prerequisite.
- **`tenant:reset <slug>` rebuilds a test tenant in one step** ([XIV-72]) —
  deprovision, provision, install modules, generate demo records, print the admin
  password. Module install order comes from each blueprint's own requirements, so
  `--modules=invoice,order,contact` works in any order somebody types it, and an
  unknown module or an unsatisfiable set is refused **before** anything is
  destroyed. Development only: it is excluded from the production image beside
  the demo commands.
- **An invoice knows when it falls due, and says when it is late** ([XIV-67]).
  The company profile sets how long customers get to pay and a contact may be
  given its own terms; the date is written onto the invoice as it is sent and
  never restated afterwards, so changing a customer's terms leaves invoices
  they already have alone. Overdue is worked out on read — sent and past the
  date — rather than being a state anything has to move records into. See §5.16.
- **A new colleague can be invited by email instead of handed a password**
  ([XIV-1]). Adding a user now asks how they get in the first time; the invitation
  is the default and **creates no password at all**, sending a link that works once
  and for 24 hours. Inviting somebody again retires the earlier link. See §8.8.
- **An installation can send mail, as itself** ([XIV-37]). The company profile
  now holds a sender address and, optionally, your own SMTP server: with one,
  mail is genuinely from you; without one it goes out through this installation
  with your name on it and your address to reply to. The SMTP password is stored
  encrypted and moves with `tenant:rotate-secrets`, and outside production
  nothing can reach a real mail server at all. See §8.7.
- **Emails are written in Xivi, not uploaded** ([XIV-38]). A module can now have
  email templates — a name, a subject and a message in Markdown, typed into a form
  and edited in place, with the same placeholders documents already offer. The
  frame around the message ships with Xivi so nobody can break it, and writing
  templates is its own permission, separate from keeping the .docx ones. See §5.13.
- **An email can be sent from a record** ([XIV-39]). A **Send email** button
  beside Documents opens a chooser: pick a template, check the subject, check the
  recipient, then send or preview first. The address comes from a module's own
  declaration — a contact's own, or the contact an order names — and it is
  editable for that one message without altering the record. Every send is on the
  record's timeline, successes and failures alike, and sending is a permission of
  its own. See §5.14.
- **A document can go out with the email** ([XIV-40]). The send chooser can
  attach one of the module's documents, in PDF or Word, generated from the record
  as it is sent — so "send the invoice" is one button. Attaching needs the
  document permission as well as the send one, the timeline records the send and
  its attachment as one entry, a document that cannot be made sends nothing at
  all, and anything over 7 MB (`XIVI_MAX_ATTACHMENT_BYTES`) is refused on the
  screen rather than bounced later. See §5.15.
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
- **A reference picker says when it is showing only the first few** ([XIV-35]).
  It has always stopped at 200 and never mentioned it, so a company that could
  not be linked to looked exactly like one that did not exist. The total is
  counted under the reader's own permissions, so it says nothing about records
  they may not see.
- **Pages read a module's definitions once instead of once per question**
  ([XIV-53]). A record list naming twenty-five different contacts went from 83
  queries to 33. Nothing is cached beyond the tenant it belongs to: the cache is
  emptied whenever the tenant context moves, and whenever a definition changes.
- **Mail sent in development can be read** ([XIV-41]). A Mailpit catcher starts
  with the dev stack and the dev `MAILER_DSN` points at it, so messages are
  rendered and readable at <http://127.0.0.1:8025> instead of leaving the
  machine. It is visibility, not a guarantee: a DSN naming a real server still
  reaches it.
- **Two checkouts can run the suite at the same time** ([XIV-51]). A git worktree
  gets its own compose project, ports and tenant databases, all derived from the
  directory name; the main checkout keeps the names and ports it had. Two runs in
  one checkout are refused with a message rather than left to interleave.
- **`bin/compose` reaches the stack your checkout owns** ([XIV-55]). **Use it
  instead of `docker compose`** — it forwards every argument through after
  deriving the project, ports and bind mount that [XIV-51] made per-checkout, and
  with no arguments it prints which stack this is and where it answers. A bare
  `docker compose` in a worktree collides on port 443 and, less visibly, runs the
  suite against the main checkout's tenant databases. The derivation now lives in
  `bin/lib/stack-env.sh`, which `bin/ci` reads too, so the two cannot drift.
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

- **A field can say what its demo data should look like** ([XIV-24]). Generated
  records used to be valid and implausible — an article priced at 63.90% VAT,
  titled "Kuhn GmbH" — because the generator knows a field's bounds and not what
  it means. A field now declares a `samples` list it is filled from; an article's
  tax rate draws real rates, some with no VAT at all, and its title is something
  somebody would sell. A field that declares nothing generates exactly as before,
  and the seed still decides which record gets what. See §5.17.

### Fixed

- **Demo data no longer invents what the engine works out** ([XIV-73]). Generated
  orders and invoices were numbered `Distinctio voluptatem dolorum` and fell due
  in 1996, because the generator filled in every field — including the ones the
  engine derives — before the engine saw the record, and a number or a due date
  is only ever written into an *empty* field. Generated records now carry the
  engine's own numbers, totals and due dates, and demo records reach their status
  by being moved through the lifecycle, so a cancelled order was cancelled and
  says so on its timeline. Orders and invoices also draw sample VAT rates and
  payment terms somebody can read a document off, instead of 63.9% over 287 days.
  See §5.17.
- **Generating demo data no longer spends a tenant's document numbering**
  ([XIV-73]). It used to hand out a real number to the handful of records whose
  invented one came out empty: three hundred generated orders left the counter at
  29, so the next genuine order was `ORD-2026-0030` with twenty-nine numberless
  records in front of it — and clearing the demo records did not give those
  numbers back. Generating *n* records now leaves the counter at exactly *n*.
- **Regenerate any demo data you have, and check the counter.** Nothing is
  migrated: records already generated keep their invented numbers, totals and due
  dates. `tenant:demo:clear` removes them, and on a tenant that had demo data
  generated into it you may want to reset `number_sequence` afterwards — the
  numbering it consumed is not returned by clearing.
- **The pager no longer draws every page** ([XIV-69]). A list of a thousand
  records offered forty numbered links, ten thousand offered four hundred, and
  the page you were on was lost somewhere among them. It is now First, Previous,
  five pages around where you are, Next and Last — the same control on the record
  list and on a record's history, which were two copies of it before.
- **A record's "linked records" card counted what fitted on it, not what exists**
  ([XIV-52]). A contact with 207 orders had a card headed "Orders 25" and no sign
  the other 182 were there. The badge is now the real count, taken under the same
  permissions the records are read with, and a card that cannot show everything
  says "Showing 10 of 207" and links to the module's list filtered to that record.
  A card now shows ten rather than twenty-five, since the rest are one click away.
- **`bin/ci` reconciles the stack it runs on instead of trusting it** ([XIV-63]).
  vendor/ against `composer.lock`, and the compiled service container against the
  configuration that produced it, before anything is checked. A merge that added
  or dropped a dependency, or changed a service, used to arrive as PHPStan errors
  about code. About a second on a warm run, and the number is printed.
- **Rebuild the dev image once** — `bin/compose up -d --build` — to get the other
  half: the container entrypoint reconciles on start now, rather than installing
  only when `vendor/` is empty.
- **A worktree builds its own dev image instead of everybody's** ([XIV-71]). The
  image name was the one per-checkout name XIV-51 missed, so a branch that
  touched the `Dockerfile` or the entrypoint rebuilt the image every other
  checkout was running — once as a crash-looping container, and silently the rest
  of the time. `bin/lib/stack-env.sh` now derives `IMAGES_PREFIX` too, and
  `bin/compose` prints the image name. The main checkout's image is still
  `xivi-php-dev`; a worktree that changes nothing about the build shares every
  layer and costs 29 kB. See §9.2.
- **Act on upgrade: an existing worktree builds its image on the next `up`, and a
  removed one leaves its image behind** ([XIV-71]). The first `bin/compose up` in
  a worktree after this lands builds `<checkout>-xivi-php-dev`; it is cached
  against the main checkout's layers, so it takes seconds unless your branch
  changed the build. Nothing removes it afterwards — `git worktree remove` takes
  the directory and the derivation with it, so read the name off `bin/compose`
  *before* removing the worktree and `docker image rm` it.

[XIV-76]: https://xivi.youtrack.cloud/issue/XIV-76
[XIV-52]: https://xivi.youtrack.cloud/issue/XIV-52
[XIV-69]: https://xivi.youtrack.cloud/issue/XIV-69
[XIV-63]: https://xivi.youtrack.cloud/issue/XIV-63
[XIV-73]: https://xivi.youtrack.cloud/issue/XIV-73
[XIV-71]: https://xivi.youtrack.cloud/issue/XIV-71

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

[XIV-32]: https://xivi.youtrack.cloud/issue/XIV-32
[XIV-41]: https://xivi.youtrack.cloud/issue/XIV-41
[XIV-42]: https://xivi.youtrack.cloud/issue/XIV-42
[XIV-35]: https://xivi.youtrack.cloud/issue/XIV-35
[XIV-43]: https://xivi.youtrack.cloud/issue/XIV-43
[XIV-47]: https://xivi.youtrack.cloud/issue/XIV-47
[XIV-48]: https://xivi.youtrack.cloud/issue/XIV-48
[XIV-50]: https://xivi.youtrack.cloud/issue/XIV-50
[XIV-51]: https://xivi.youtrack.cloud/issue/XIV-51
[XIV-53]: https://xivi.youtrack.cloud/issue/XIV-53
[XIV-55]: https://xivi.youtrack.cloud/issue/XIV-55
[XIV-44]: https://xivi.youtrack.cloud/issue/XIV-44
[XIV-37]: https://xivi.youtrack.cloud/issue/XIV-37
[XIV-38]: https://xivi.youtrack.cloud/issue/XIV-38
[XIV-39]: https://xivi.youtrack.cloud/issue/XIV-39
[XIV-40]: https://xivi.youtrack.cloud/issue/XIV-40
[XIV-1]: https://xivi.youtrack.cloud/issue/XIV-1
[XIV-67]: https://xivi.youtrack.cloud/issue/XIV-67
[XIV-24]: https://xivi.youtrack.cloud/issue/XIV-24
[XIV-6]: https://xivi.youtrack.cloud/issue/XIV-6
[XIV-70]: https://xivi.youtrack.cloud/issue/XIV-70
[XIV-72]: https://xivi.youtrack.cloud/issue/XIV-72

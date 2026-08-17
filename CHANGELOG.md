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

- **The administration surface is a package of its own** ([XIV-60]).
  `src/ControlPlane` is now `packages/control-plane`, wired as a path repository
  like the modules, and the rule about it is enforced rather than assumed: it may
  depend on the application, and the application may never depend on it. Nothing
  a customer sees changes, no table moved and no migration is needed (§3.1).
- **`src/ControlPlane` did not move whole, and the half that stayed is the point**
  ([XIV-60]). Every tenant request reads the control-plane database before it
  knows whose request it is, so the tenant row, its credential and the module
  catalogue stayed in the application as `App\Registry`. What moved is what an
  *operator* touches: provisioning, migrations, secret rotation, operator identity
  and its firewall, the tenant list and usage collection.
- **If you import from the control plane in your own code, the namespace has
  changed** ([XIV-60]) — `App\ControlPlane\…` is now either `App\Registry\…` or
  `Xivi\ControlPlane\…` depending on which half it was. Every command, route,
  service id and template still answers to the name it had.

### Fixed

- **`composer deptrac` had never checked anything** ([XIV-60]). Every layer in
  `deptrac.yaml` was collected by a path pattern anchored `^src/`, which deptrac
  matches against a file's absolute path — so no file was in any layer and the
  check reported no violations for the same reason an empty configuration would.
  The collectors match on namespace now. Turning it on for the first time found
  **zero** real violations, so no code changed; the module boundaries the brief
  has claimed since §3 were true all along, they were simply not being verified.

### Added

- **A public signup endpoint, off by default** ([XIV-64]). A site of yours can post
  a signup to this installation and it is recorded — company, name, plan, email —
  and nothing else. It creates **no tenant, no database and no role**: turning a
  confirmed signup into a customer is [XIV-98] and runs where an operator can see
  it. The reasoning, and why a public surface never provisions directly, is §8.12.
- **Switching it on is a deployment step, and leaving it off leaves no route**
  ([XIV-64]). `SIGNUP_HOST` empty — the default — means the routing table has no
  signup route in it at all, rather than a route that refuses. Set it to a hostname
  of its own (**not** `CONTROL_PLANE_HOST`) and set `XIVI_SIGNUP_SECRET`; the
  application refuses to start with a signup host and no secret. Nothing else:
  `MAILER_SENDER` stays optional, and an empty one sends the confirmation from
  `no-reply@` at the signup host, which is §8.7's existing fallback one noun
  along. See *Configuration* in the README.
- **Run the control-plane migration on deploy** ([XIV-64]) —
  `doctrine:migrations:migrate --em=control` — which adds the `signup_request`
  table. No tenant migration and no backfill.
- **A signup is confirmed by email before it holds a name** ([XIV-64]). The
  confirmation link carries a control-plane token with a 24-hour expiry; asking
  again from the same address resends and kills the previous link; following one
  twice changes nothing rather than failing. Until an address answers, the name it
  asked for is still free to everybody — which is what makes squatting cost a
  working mailbox per name. A confirmed address may hold one unprovisioned signup
  at a time.
- **Self-service names are hostname-safe, and that is a second rule rather than a
  change to the first** ([XIV-64]). `TenantProvisioner::SLUG_PATTERN` is unchanged
  and still permits underscores, because a provisioning slug is also a Postgres
  identifier; a self-service slug becomes a subdomain, so it takes a stricter DNS
  rule. §8.12 says why they differ, and a test fails if anybody unifies them.
  Reserved names include every entry in `app.system_hosts` and the control-plane
  host's own label.
- **The endpoint is rate limited** ([XIV-64]), by email address and by the visitor's
  address, using `symfony/rate-limiter` — MIT, first-party, and it brings nothing
  new with it (`THIRD-PARTY-NOTICES.md`). The limits are in
  `config/packages/rate_limiter.yaml`; **a deployment running more than one
  instance needs a shared cache pool there**, or each instance enforces its own
  copy of every limit.
- **What each customer is using, on the tenant list** ([XIV-59]). Every row now
  shows how many users that customer has, when anybody last signed in and how
  many records are in there — enough to tell somebody who is using Xivi from
  somebody who provisioned in March and never came back. The figures say when
  they were collected, and a customer whose database could not be read says
  *that* rather than showing zeroes (§8.11).
- **Schedule `tenant:usage:collect` in your deployment's cron** ([XIV-59]).
  Nothing collects those figures for you: until the command has run, every row
  reads *not collected yet*, which is honest and is not useful. Nightly is a
  sensible cadence — the page states the age of what it shows, so any cadence
  tells the truth. The command walks tenants one at a time, records a failure
  against the one customer it could not reach, carries on with the rest, and
  exits non-zero so cron mails you about it.
- **Run the control-plane migration on deploy** ([XIV-59]) —
  `doctrine:migrations:migrate --em=control` — which adds the `tenant_usage`
  table. No tenant migration and no backfill: an empty table is exactly "nobody
  has been collected yet".
- **Every tenant on one page** ([XIV-58]). Signing in to the control plane now
  lands on the registry itself — name and slug, status, plan, primary domain,
  when the row was created and when it was provisioned, which modules are enabled
  — instead of XIV-57's placeholder, which is gone. **Ordered by status rather
  than by name**, so a tenant stuck in *provisioning* is at the top instead of on
  the third screen, and the page opens with a line naming the customers that are
  not being served (§8.10).
- **The tenant list reads only the control-plane database** ([XIV-58]). It opens
  no connection to any customer's database, and a test proves it — which is what
  keeps usage figures ([XIV-59]) a design decision rather than a join somebody
  adds in passing. `tenant:list` is unchanged and still works; a headless
  deployment needs it.
- **Somebody can sign in to the control plane** ([XIV-57]). Operators are rows in
  the control-plane database with their own entity, provider and firewall — never
  promoted users of a designated tenant, which would make one customer's database
  the key to every other customer's (§8.9). Signing in lands on the tenant list
  above.
- **The control plane is served on a host of its own, and only there** ([XIV-57]).
  A control-plane URL answers 404 on every customer's hostname, and the tenant
  application answers 404 on the control plane's. A request there resolves no
  tenant at all, and operator sessions and customer sessions are not
  interchangeable.
- **Set `CONTROL_PLANE_HOST` before deploying, and create the first operator**
  ([XIV-57]). It defaults to `control.localhost`, which is a development value.
  The variable is written into `app.system_hosts` for you, so there is one thing
  to set rather than two; then run `control:operator:create <email>` — there is no
  sign-up, and the control plane refuses everybody until it has been run. See the
  README under *Configuration*.
- **`tenant:provision` now refuses a hostname served without a tenant** ([XIV-57]),
  the control plane's above all. Such a row would never have been reached, and
  that customer's users would have been shown the platform's sign-in page instead
  of their own.
- **A customer's own logo, on their pages and their sign-in page** ([XIV-49]).
  Uploaded on the company profile beside the company name, kept in the tenant's
  own database like a document template is, and drawn in the top bar and on the
  login page of their own hostname — so an installation reads as theirs from the
  first screen. PNG or JPEG up to 512 KB; **SVG is refused**, because sanitizing
  it needs a GPL dependency this project will not take (§8.6). A system host still
  shows Xivi's own mark.
- **The logo is served without signing in**, and is the only piece of a tenant's
  data that is ([XIV-49]). It has to be — it is on the sign-in page — so the route
  is scoped by hostname and not by permission, and nothing else on the profile
  comes out of it. Changing the logo needs the same *edit* grant as changing the
  company name. See §8.6 for the argument and the cache design.
- **Your order and invoice numbers are yours to shape** ([XIV-27]). A numbered
  field now has a *Numbering* page in the module's field editor, where the
  pattern — `ORD-{year}-{number:4}` — can be changed to your own prefix, your own
  width and your own answer to whether it restarts each year. **The page shows
  the next number as you type it**, so a width too narrow to sort correctly is
  visible before it is saved rather than at the hundredth document, and a pattern
  with no `{number}` in it is refused with a reason instead of quietly numbering
  nothing. Nothing already given out is renumbered, ever (§5.10).
- **The counter can be set to where your old system left off** ([XIV-27]). If
  your next invoice has to be 1043, type 1043. **A value at or below a number
  already given out is refused** — a duplicate number on a document is not
  something an apology fixes — and the refusal is in the database statement that
  moves the counter, so it holds for anything that ever writes one.
- **Adding or removing `{year}` moves to a different counter, and the page says
  so before you save** ([XIV-27]). That counter has its own starting point, so
  the next document can be `ORD-2026-0001` after `ORD-0087`. Defensible,
  surprising, and now impossible to meet by accident.
- **Turning numbering on for a field that has none is not part of this**
  ([XIV-27]) — the page appears on fields that are numbered already. What should
  happen to records that already exist, and to values somebody typed by hand, is
  a decision about your data rather than about a form; §5.10 gives the reasoning
  and [XIV-91] holds the question.

- **Type to find a record instead of scrolling for one** ([XIV-36]). A reference
  picker with more than about twenty candidates becomes a search box that queries
  as you type and pages through everything you may see, instead of a dropdown
  capped at two hundred — so linking to the nine thousandth contact is possible
  for the first time. A `choice` field with a long list gets the same box, filtered
  in the browser with no request at all. It is decided from the candidate count by
  default; the metadata editor has a per-field *Search box* setting of
  *Automatic* / *Always* / *Never* beside the width for anybody who wants to say
  so. Nothing about the value changes — same storage, same validation, same
  filtering, same export — which is why this is an option and not a field type
  (§5).
- **A search endpoint for a module's records**, `GET /m/{module}/search`
  ([XIV-36]). Generic over module and variant, sorted and paged by the same title
  fields the picker used, and **scoped exactly as the picker is**: it needs *view*
  on the module and applies the same record-level predicate a list does, so
  somebody restricted to their own records cannot find a colleague's by typing its
  name (§8.4).

- **Your due follow-ups, on the dashboard** ([XIV-81]). A widget listing what is
  assigned to you and not yet done, soonest first, narrowable to *due today*,
  *due this week* or *all*. The lenses are ceilings with no floor, so something
  you missed stays in every one of them — including *today* — rather than
  disappearing the moment its time passes. Each entry links to the record it is
  about; one whose record you may no longer view keeps its text and loses the
  title and the link, and one whose record was deleted is gone. Days and weeks are
  worked out on your own clock, and the week starts on the day your region starts
  it on rather than always Monday (§5.18).
- **The dashboard is made of widgets now** ([XIV-81]). The module tiles are one
  and the follow-ups are another, rather than follow-ups being wired into the
  page. Nothing to act on today; §8.3.1 is where the next thing to go on that
  page should look first.
- **A timezone, on your account and on the company profile** ([XIV-83]). Times on
  screen are shown on the clock you are looking at rather than on UTC. Most
  installations need do nothing: where the region already chosen has exactly one
  timezone — Switzerland, Austria, France, the UK — it is derived from that, and
  §8.4.4 argues why an ambiguous country is asked rather than guessed at.
- **Follow-ups on a record's page** ([XIV-82]). What is outstanding on a contact,
  an order or an invoice now sits at the top of its page: priority as a coloured
  left border, the due moment on your own clock, who it is for, and the notes
  written on it as a thread. You can open one, write on it, mark it done and
  reopen it from there — and the ones already done are behind a counter rather
  than down the page. Nothing appears for a module whose follow-ups are switched
  off, and nothing appears on record *lists*; §5.18 of the brief argues both.
- **Follow-ups: the storage, the permissions and the per-module switch**
  ([XIV-80]). A record can carry something somebody decided to do about it — a
  priority, a due date, an optional assignee and a thread of notes — with done as
  a stamp that can be taken off again. This is the engine half only: there is **no
  user interface yet**, and the record page ([XIV-82]) and the dashboard widget
  ([XIV-81]) are separate. One shared pair of tables for every module rather than
  one per module as history has, argued in §5.18 of the brief.
- **Two new permissions per module, `follow_up_create` and
  `follow_up_complete`** ([XIV-80]). They appear on the group and user permission
  screens for every installed module, and — like every permission here —
  **nobody holds them until somebody grants them**. Creating covers writing notes;
  completing covers reopening, because those are one edit pointing two ways.
  Reading follow-ups follows the module's existing *view*.
- **A note can only be edited or deleted by whoever wrote it**, and there is no
  administrator override ([XIV-80]). The only place in Xivi where `ROLE_ADMIN` is
  not a bypass, on purpose: editing somebody's note under their name is putting
  words in their mouth.
- **A follow-up can only be assigned to somebody who may view its record**
  ([XIV-80]). Refused on the write path, not only in a form, so it holds for
  imports and console commands too. Taking that grant away afterwards leaves
  existing assignments standing — deliberately, so that editing permissions never
  silently unassigns somebody's outstanding work.
- **Follow-ups are per module, on by default, and can be switched off**
  ([XIV-80]). The store's install wizard has a checkbox for it and
  `tenant:module:install` has `--no-follow-ups`. Unlike the preset, this one is
  **not permanent**: it can be turned on or off at any time afterwards, and
  switching it off deletes nothing.
- **A document template says which of its placeholders nothing will fill in**
  ([XIV-25]). Uploading one now names every `[token]` in it that no marker
  answers — and so does the templates page for the ones already there, so a
  template that has gone stale because a field was renamed shows up without
  anybody re-uploading it. The template is still accepted either way: brackets
  in a letter are legal, and the wording says what will happen to the text
  ("`[contacŧ]` will be printed just as it is") rather than calling it an error.
  See §5.7 for why unknown markers print themselves and why unused ones are
  deliberately not reported.

### Changed

- **A collection now holds at most 400 rows, and says so** ([XIV-68]). An order
  with more lines than that, a contact with more addresses, is refused when it is
  saved rather than half-drawn or answered with a 500 — on the record form, on an
  import and through the engine alike, with a message naming the limit and how
  many rows arrived. 400 is the supported size of a document here; §5.1 has the
  measurement it rests on, and why paginating the edit form was declined rather
  than forgotten.
- **A PHP request is now allowed 256M** ([XIV-68]), set in
  `frankenphp/conf.d/10-app.ini`, where nothing set it before and it ran on PHP's
  stock 128M. **Act on this if you host Xivi yourself and pin container memory**:
  the edit form of a 400-line order needs 140 MB and would have answered 500 on
  the old default. Nothing else in the request path changed.
- **The picker's truncation notice now appears only where a picker can still
  truncate** ([XIV-36]). It is unchanged and still says "showing the first 200 of
  9 421" — but under the new default a picker that large is a search box with no
  ceiling, so the sentence has nothing to say. A field set to *Never* keeps both
  the dropdown and the notice.

- **Narrowing the dashboard's follow-up list no longer reloads the page**
  ([XIV-84]). *Today*, *this week* and *all* are buttons on the card rather than
  three links carrying `?follow_ups=…`, so the lens stops occupying the address
  bar and stops leaving a history entry behind every time you change your mind.
- **A follow-up's priority reads as a 4px bar down its leading edge**
  ([XIV-84]), on the record page and on the dashboard alike. The record page used
  to ring the whole card in the priority colour, which at three priorities on one
  page read as three boxes rather than one list.
- **Information-priority follow-ups are blue on the dashboard**, not grey
  ([XIV-84]) — the widget shipped with a stopgap colour of its own and now draws
  from the same mapping the record page does.
- **A record page no longer asks the database once per row for the names its
  rows point at** ([XIV-54]). A page of a record with a collection now reads
  what its rows name in one query per target module, so an order with 500 lines
  costs 16 queries instead of 2014, and 5 lines cost the same 16. The document
  path benefits identically — 500 lines expanded from a .docx template went from
  503 queries to 4 — and a 25-row list from 32 to 8. See §5.3.
- **The names a request resolves are now dropped when it ends** ([XIV-54]). They
  always were meant to be (§7.4) and in a classic request they effectively were;
  they now say so through `ResetInterface` rather than relying on the process
  ending, which matters for anything long-running.
- **Record timelines group by your own days** ([XIV-83]). "Today", "this week" and
  "this month" were worked out on UTC midnights, so an entry made just after
  midnight could sit under yesterday on a page you had just made (§5.2).
- **A follow-up's priority is drawn the same colour everywhere** ([XIV-82]). The
  dashboard widget ([XIV-81]) landed first with its own copy of the colour table,
  which drew *for information* grey where the record page draws it blue. Both
  screens go through one mapping now, and the dashboard is the one that moved
  (§5.18).
- **The two follow-up permissions now do something** ([XIV-82]). They shipped in
  [XIV-80] with nothing on any screen calling them, so granting one changed
  nothing anybody could see; the record page is what they are for. Nobody holds
  them until somebody grants them, so **check who should have them** — a colleague
  with only the module's *view* reads follow-ups and can change none of them.
- **`permission_grant.action` is now 31 characters wide** ([XIV-80]) — the new
  verbs are eighteen, and the column held sixteen. Nothing to act on: the tenant
  migration widens it, and the permission catalogue itself still needs no
  migration when a verb is added.

### Fixed

- **An archived follow-up can no longer be written to** ([XIV-85]). Its notes
  took new entries, edits and deletions, and an edit even bumped the follow-up's
  timestamp — so something settled last month reported activity today. `done_at`
  now means history: the only thing offered on an archived follow-up is reopening
  it, and the write path refuses the rest whatever the page happens to be showing.

- **A long edit form stops asking the database for the same picker list once per
  row** ([XIV-87]). Opening a 500-line order for editing made 973 queries and now
  makes 13, the same 13 a 100-line one makes, and the page renders about a third
  faster. It does **not** move the limit below — see the note under *Measured*,
  because the reason is worth knowing.

### Measured

- **How long a collection can get before its record page breaks, measured**
  ([XIV-68]). `tests/Measurement/CollectionCeilingTest.php` builds an order of a
  given number of lines and records what the read view and the edit form cost —
  time, bytes, queries and memory. Nothing in the product changed; §5.1 of the
  brief now carries the table and what it says. Run it with
  `bin/compose exec -e APP_DEBUG=0 php vendor/bin/phpunit tests/Measurement/CollectionCeilingTest.php`;
  it is in no test suite, so `bin/ci` does not pay for it.
- **Act on this if you have long documents.** The edit form of an order or an
  invoice needs more memory than a PHP request is allowed somewhere around
  **250 lines**, and above that it answers 500 rather than a page. The read view
  goes about forty times further. **Both halves of that are now answered** — see
  the cap and the memory limit under *Changed* — and the read view is deliberately
  left unbounded, because with writes capped it is never near its own ceiling.
- **That ceiling survived the picker fix, which is the useful part of the
  finding** ([XIV-87]). Batching the candidate reads took a 500-line edit form
  from 973 queries to 13 and about a third off its render time, and moved its
  memory from 221 MB to 212 MB — because every row still *renders* two hundred
  `<option>` elements whether or not they were read once or five hundred times.
  The bytes are identical to the byte. So the edit form's limit is a rendering
  cost, not a query cost, and the thing that would actually move it is a control
  that does not emit the options at all.

- **And a control that does not emit the options arrived** ([XIV-36]). Not what
  it was built for: the same 500-line order form, the same 250-article catalogue,
  measured on one machine back to back — **5 829 901 bytes to 2 173 433 (−63%),
  268.9 MB to 233.6 MB (−13%), 4 186 ms to 3 032 ms**, and 13 queries to 15. The
  ceiling moves up and does not go away, because §5.1's other finding still holds:
  most of the memory is one Symfony form per row, and no widget changes that.

- **And the supported size was measured again against what it now costs**
  ([XIV-68]). A 400-line order's edit form: **140.3 MB per request, 15 queries,
  1.74 MB of HTML, answering 200** — 55% of the 256M a request is now allowed, and
  above the 128M it used to have. The measurement tool's default sizes stop at the
  cap now, because the cap refuses to build a longer fixture and the question it
  answers has changed from "where does this break" to "does the supported size
  still draw".

### Upgrade notes

- **Run `bin/console tenant:migrate` after merging** ([XIV-80], [XIV-83]).
  Between them they add `follow_up` and `follow_up_note`, widen the grant column,
  turn follow-ups on for every module every tenant already has, and add a column
  to `app_user` and one to `tenant_profile`. Nothing is backfilled and no stored
  moment moves — everything was already absolute UTC, and the timezone is a
  display setting.
- **`bin/console tenant:migrate` also covers the tenant logo** ([XIV-49]), which
  adds three nullable columns to `tenant_profile`. Nothing is backfilled: every
  existing installation keeps showing whatever it showed before until somebody
  uploads a mark.
- **`[tenant.logo]` on a .docx is not in this** ([XIV-49]) — it is [XIV-89].
  Every marker the engine has resolves to text, and one resolving to an image is a
  change to the document pipeline rather than another key in the list; §5.7 says
  what it would take.
- **Nobody can create a follow-up until you grant one of the new permissions**
  ([XIV-80]), administrators excepted. `tenant:permissions:grant-all` includes
  them, as it does every verb.
- **A country with more than one timezone shows UTC until somebody chooses**
  ([XIV-83]) — Germany, Spain, China, the United States, Canada, Australia,
  Brazil and Russia among them. The company profile names which zone is in force
  beside the empty option, so the page says what it is doing.

[XIV-25]: https://xivi.youtrack.cloud/issue/XIV-25
[XIV-27]: https://xivi.youtrack.cloud/issue/XIV-27
[XIV-36]: https://xivi.youtrack.cloud/issue/XIV-36
[XIV-49]: https://xivi.youtrack.cloud/issue/XIV-49
[XIV-54]: https://xivi.youtrack.cloud/issue/XIV-54
[XIV-57]: https://xivi.youtrack.cloud/issue/XIV-57
[XIV-58]: https://xivi.youtrack.cloud/issue/XIV-58
[XIV-59]: https://xivi.youtrack.cloud/issue/XIV-59
[XIV-60]: https://xivi.youtrack.cloud/issue/XIV-60
[XIV-64]: https://xivi.youtrack.cloud/issue/XIV-64
[XIV-68]: https://xivi.youtrack.cloud/issue/XIV-68
[XIV-80]: https://xivi.youtrack.cloud/issue/XIV-80
[XIV-81]: https://xivi.youtrack.cloud/issue/XIV-81
[XIV-82]: https://xivi.youtrack.cloud/issue/XIV-82
[XIV-83]: https://xivi.youtrack.cloud/issue/XIV-83
[XIV-84]: https://xivi.youtrack.cloud/issue/XIV-84
[XIV-85]: https://xivi.youtrack.cloud/issue/XIV-85
[XIV-87]: https://xivi.youtrack.cloud/issue/XIV-87
[XIV-89]: https://xivi.youtrack.cloud/issue/XIV-89
[XIV-91]: https://xivi.youtrack.cloud/issue/XIV-91
[XIV-98]: https://xivi.youtrack.cloud/issue/XIV-98

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

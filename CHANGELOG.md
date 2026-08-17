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

### Added

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

### Changed

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

### Upgrade notes

- **Run `bin/console tenant:migrate` after merging** ([XIV-80], [XIV-83]).
  Between them they add `follow_up` and `follow_up_note`, widen the grant column,
  turn follow-ups on for every module every tenant already has, and add a column
  to `app_user` and one to `tenant_profile`. Nothing is backfilled and no stored
  moment moves — everything was already absolute UTC, and the timezone is a
  display setting.
- **Nobody can create a follow-up until you grant one of the new permissions**
  ([XIV-80]), administrators excepted. `tenant:permissions:grant-all` includes
  them, as it does every verb.
- **A country with more than one timezone shows UTC until somebody chooses**
  ([XIV-83]) — Germany, Spain, China, the United States, Canada, Australia,
  Brazil and Russia among them. The company profile names which zone is in force
  beside the empty option, so the page says what it is doing.

[XIV-80]: https://xivi.youtrack.cloud/issue/XIV-80
[XIV-81]: https://xivi.youtrack.cloud/issue/XIV-81
[XIV-82]: https://xivi.youtrack.cloud/issue/XIV-82
[XIV-83]: https://xivi.youtrack.cloud/issue/XIV-83
[XIV-84]: https://xivi.youtrack.cloud/issue/XIV-84

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

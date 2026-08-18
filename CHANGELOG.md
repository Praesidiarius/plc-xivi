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

- **A tenant removal now empties the cluster before it touches the registry**,
  where it used to delete the control-plane row first. A failure part-way
  therefore leaves a row pointing at nothing — visible to `tenant:list`, and
  repaired by running the same command again — rather than a database and a role
  that nothing in the system knows about ([XIV-94], §4.1).
- **A removal that stops part-way prints what is standing**: the database, the
  role and the control-plane row, each said to be gone or still there, with the
  line to type next, instead of a DBAL driver exception ([XIV-94]).
- **`tenant:migrate` exits 3 when a tenant failed**, where it used to exit 1 —
  which is the same 1 it exits with for an empty registry and an unknown
  `--slug`, so a deploy could not tell "nothing to do" from "one of your
  customers is on the wrong schema" ([XIV-61], §4.2). **Anything reading that
  exit code needs updating**: 0 is all tenants current, 1 is the run could not
  happen, 3 is some failed and the rest are fine. The failure now names the
  tenants and prints the `--slug` line to retry each one.

### Added

- **A dashboard each person picks and arranges, over a default the installation
  sets** ([XIV-66],
  [§8.3.1](docs/architecture.md#whose-dashboard-it-is-and-a-seam-a-module-can-reach-xiv-66)).
  The picker is on `/account`, under the language and timezone settings it shares
  a shape with; whoever administers the installation sets the default everybody
  who has not chosen sees, on the profile page. A "Customise" link sits beside the
  dashboard heading so somebody who has hidden every card can still get back, and
  "Go back to the default" needs no administrator.
- **A module can ship a dashboard widget**, which the widget seam moving into
  `packages/core` is what makes possible. **Invoices ship the first one**:
  everything sent and not yet paid, most overdue first, as links to the invoices
  rather than as a count — with an "Overdue" badge worked out from the due date at
  the moment you look (§5.16).
- **The dashboard no longer waits for its cards.** The page arrives with its
  headings and navigation and each card that reads the database is fetched
  separately, so a slow widget costs its own tile instead of the landing page.
- **Upgrading:** the tenant migration adds `dashboard_layout` to `app_user` and
  `tenant_profile`. Both are nullable and nothing is backfilled — every existing
  user and installation keeps exactly the dashboard they have until somebody
  chooses otherwise. A saved layout naming a widget that later disappears (an
  uninstalled module, a renamed widget) quietly loses that card rather than
  erroring.
- **A customer-facing image that does not contain the administration surface**
  ([XIV-96], [§4.4](docs/architecture.md#44-two-images-what-a-customers-instance-is-built-without-xiv-96)).
  `docker build --target frankenphp_public` produces the image a customer's
  hostname is served from: no operator console, no signup intake, no
  provisioning, no `control:*` commands — absent from the filesystem, the
  autoloader and the compiled container rather than switched off. The internal
  image is unchanged and is still `--target frankenphp_prod`. The build itself
  refuses to finish if any of the three still names the package.
- **`deploy:registry-grants` prints the SQL** that leaves the customer-facing
  instance able to read the tenant registry and unable to write it — derived from
  the mapping, so a release that adds a registry table cannot leave a
  hand-written grant script behind. It prints rather than runs: the application
  knows which tables, a database administrator decides whether.
- **A tenant can take what their module grew after they installed it**
  ([XIV-70], [§7.2.1](docs/architecture.md#721-taking-what-a-module-grew-without-retro-fitting-it-xiv-70)).
  Installing still does not retro-fit — §6.1 is unchanged and no deploy touches
  anybody's definitions — but the metadata editor now shows what a module's
  blueprint has that this copy has not, and an administrator takes it item by
  item. Fields, and whole collections, which needed the installer because they
  need a table.
- **A preset chosen once is no longer a one-way door.** The fields `basic` left
  out are offered later like any other addition, so a customer who chose the
  smaller shape can grow into the larger one. Nothing records which preset was
  used and nothing needs to: the offer is diffed against the blueprint.
- **Nothing that already exists is touched.** A key the shape already has is
  never offered, whatever it has since been renamed, narrowed or reordered to,
  and every write is an insert. A blueprint rule the records could not keep —
  `required` on a module that already has records — arrives switched off, and
  the confirmation says which ones and why.
- **A field somebody deleted is not offered back for ever.** After §5.4's
  removal a deliberately deleted field and one nobody ever had are
  indistinguishable, so the decision is recorded when it is made rather than
  guessed at later: removing a field writes it down, as does dismissing an
  offer, and both are visible in a "dismissed" list with a way back.
  **On upgrade, every installation starts with nothing declined**, so the first
  visit to the screen offers everything — including anything deleted back when
  there was nowhere to record the decision. Dismissing settles it.
- **A newly added derived field arrives empty**, on every record that already
  exists, and is filled by its deriver the next time each one is saved. Nothing
  here writes a plausible value into a record: derived values are the engine's
  (§5.9).
- **Run `tenant:migrate` after merging this.** One nullable column
  (`shape_definition.declined_additions`) holds what a customer has declined.
  The entity maps it, so a tenant that has not been migrated cannot read any
  module's definitions at all.
- **The store's install wizard no longer says the field set cannot be changed
  later**, because it now can be grown. What it still says, and what is still
  true, is that nothing rewrites what is installed.
- **An installation can declare which hostnames it answers to**, with
  `XIVI_TRUSTED_DOMAINS` — a comma-separated list of domains, not regular
  expressions; the application writes the anchored patterns and adds
  `CONTROL_PLANE_HOST`, `SIGNUP_HOST` and the loopback and container names for
  you, so setting it cannot lock an operator out of their own console
  ([XIV-93], [§4.3](docs/architecture.md#43-which-hostnames-this-installation-answers-to-xiv-93)).
  **Empty is the default and means the `Host` header is not checked at all**, so
  development and the test suite are unchanged.
- **A too-narrow list is findable rather than only correct.** A hostname outside
  it gets a bare 400 from the framework, so three things say so instead:
  `tenant:provision` refuses to create a customer on such a hostname,
  `deploy:check-hosts` names every tenant that would get one, and a refused
  request writes an `error` line naming the host, the pattern and the variable.
- **`bin/deploy` runs `deploy:check-hosts`** between the control-plane and tenant
  migrations, and stops the deploy (exit 3) when a tenant that is serving today
  is on a hostname the pattern refuses. The container entrypoint runs it too and
  **only prints** — one customer's hostname must not stop every container from
  starting.
- **Trusted proxies are configurable, with `TRUSTED_PROXIES`.** Empty by default,
  which is what the shipped topology wants. **Set it if you have a load
  balancer**: without it, absolute URLs generated while serving — invitation
  links above all — come out as `http://` behind a TLS terminator.
  `X-Forwarded-Host` and `X-Forwarded-Prefix` are deliberately never trusted,
  because tenant routing *is* the `Host` header.
- **The brief now says the control plane is not isolated by its hostname**
  ([§8.9](docs/architecture.md#89-an-operator-is-not-a-tenant-user-xiv-57)).
  Anybody who can set `Host:` to it reaches the sign-in page, and no trusted-host
  pattern can change that; what keeps a customer out is the firewall, the
  provider and `ROLE_OPERATOR`. Nothing about the behaviour changed — the
  guarantee was being read as stronger than it was.
- **A text field that is not numbered can be given a numbering pattern, from the
  metadata editor** ([XIV-91], [§5.10](docs/architecture.md#making-a-field-numbered-and-stopping-xiv-91)).
  [XIV-27] made a numbered field's pattern the customer's and stopped there,
  because turning numbering *on* is a question about records rather than about
  definitions. The link is now on any of a module's own text fields that nothing
  else fills in.
- **Records that already exist are given numbers, oldest first, once.** The
  alternative — numbering only new records — leaves permanent blanks in a field
  the module may use as the record's title, and letting `AssignsNumbers` catch up
  on its own would number the oldest record 0001 for the accident of somebody
  opening it first.
- **The backfill is confirmed before it runs, and names its scale.** A page in
  front of it says the pattern, how many records will be written to, what the
  first and last of them will be called, and that it cannot be undone; the tick
  arrives unticked and the server requires it. Nothing here writes a history
  entry per record and nothing bumps `updated_at` — a number records when a
  document was made, and stamping every document as changed today while giving it
  one would say the opposite.
- **A number somebody typed in by hand can no longer be handed out by the
  counter.** The column is read for values the pattern could have produced and
  the counter starts above the highest of them — including when the counter is
  wound forward by hand, which is now refused against the records as well as
  against the counter. [XIV-27]'s in-statement guard is unchanged and still the
  one that makes the promise; this narrows what reaches it. Values the pattern
  could never produce (`Referenz 12`) cannot collide and are left alone.
- **A numbered field becomes derived**, so the engine fills it and nobody types
  into it. That is what keeps the duplicate closed after the change rather than
  only at the moment of it. A field something else already derives — an order's
  total, an invoice's due date — is not offered numbering.
- **Numbering can be turned off**, from a page that says what that means: the
  numbers on records stay, the field becomes an ordinary text box anybody may
  type in, and the counter is kept where it is so switching numbering back on
  carries on rather than walking back over numbers already given out. An emptied
  pattern is still refused rather than read as "off".
- **The tenant list shows the modules a customer actually has installed, and
  names where that disagrees with the registry** ([XIV-95]). The list is read
  from the customer's own metadata by `tenant:usage:collect` — the page still
  opens no tenant connection — so it is as old as the last collection and says
  so, in the same three states as the figures beside it: not collected yet, could
  not be read, or installed as of a time. A difference is drawn in both
  directions (*not recorded* for a module the customer has that
  `enabled_modules` does not list, *not installed* for the other way) and
  deliberately **not** as a fault: §6.1 makes a module installed from a console a
  legitimate state, and nothing here offers to reconcile the two
  ([§8.11](docs/architecture.md#what-a-tenant-actually-has-installed-and-where-that-disagrees-xiv-95)).
- **Per-module record counts are now readable text instead of a tooltip.** They
  were a `title` on the usage cell, which a touch screen and a screen reader both
  simply do not have; they are drawn beside the module names they belong to. A
  customer with more than five modules folds the tail into a disclosure, and the
  modules the two sources disagree about always sort ahead of it.
- **`tenant_usage` gains an `installed_modules` column, and nothing is backfilled**
  — a row collected before this change has genuinely never had its modules read,
  and the page draws that as *not collected yet* until the next run. Filling it in
  from `enabled_modules` would have manufactured perfect agreement for every
  existing customer, which is the assumption the column exists to stop.
- **An operator can be revoked, restored, listed and given a new password, all
  from the console** ([XIV-92], §8.9). `control:operator:create` made one and
  nothing else touched it, so withdrawing the identity with the most reach in the
  installation meant `psql`. Four commands now: `control:operator:list`,
  `control:operator:revoke`, `control:operator:restore` and
  `control:operator:password`.
- **Revoking deactivates rather than deletes**, so the account stays in the list,
  marked, and comes back with `control:operator:restore`. **This adds
  `operator.active`** — run `bin/console doctrine:migrations:migrate --em=control`
  on deploy. Everybody who exists today stays able to sign in.
- **A revoked operator cannot sign in, and a session they already had ends on
  their next request.** The second needed its own listener: Symfony compares
  identifier, password and roles when it restores a session, and never `active`
  (§8.9).
- **The last operator who can still sign in cannot be revoked.** There is no
  sign-up, no invitation and no password reset on the control plane, so create
  the successor first. The refusal counts active operators rather than rows, so
  it cannot be walked past by revoking two accounts in turn.
- **Changing an operator's password signs out every session that account had**,
  which Symfony does on its own; it is now tested for, because it was inherited
  rather than written.
- **`control:operator:create` on an address that already has an operator is still
  an error** and now says which command to use instead — a different sentence for
  a live account and for a revoked one. Making create double as a password change
  would make a typo'd address indistinguishable from a rotation, and would undo a
  revocation without mentioning one (§8.9).

- **`signup:provision` turns confirmed self-service signups into customers**
  ([XIV-98]). One console command on the deployment's cron, reading the rows
  [XIV-64]'s endpoint records and deliberately does nothing with: it creates the
  tenant, its first administrator and an invitation link, then removes the signup
  row. It is the privileged half of the feature — the half that holds
  `TENANT_ADMIN_DSN` — and it runs nowhere a request can reach (§8.14).
- **The first user of a self-service tenant is invited, never given a password.**
  [XIV-1]'s signed login link, mailed to the address that confirmed, in the
  language they read the signup form in. Nothing generates a password, because
  nobody is watching a terminal to read one off (§8.5, §8.8).
- **A self-service customer is served at `<name>.<signup host's parent domain>`** —
  `acme.xivi.app` for a deployment serving signup at `signup.xivi.app`. That was
  the address [XIV-65]'s form already showed beside the name box as a hint; the
  form and provisioning now compute it with one function, so the promise and the
  fact cannot drift apart (§8.14).
- **Running the command again after a partial failure is safe.** A tenant left
  half-made by a run that died is cleared and rebuilt — `provision()` cannot be
  resumed, and [XIV-94]'s removal order makes clearing it repeatable — while one
  that is already standing is finished rather than duplicated. A tenant with that
  name that this feature did not create is never touched in either direction
  (§8.14).
- **One failing signup does not stop the others.** The failure is recorded
  against its own row with the step it stopped at and how many attempts have been
  made; the run carries on and exits non-zero so that cron mails somebody. A
  half-provisioned customer also sorts to the top of the tenant list and is named
  in its banner ([XIV-58], §8.10).
- **`[tenant.logo]` in a document template draws the customer's logo**
  ([XIV-89]). Put it anywhere in the .docx — including the header, which is where
  a letterhead wants it — and the generated document carries the picture, in the
  Word file and in the PDF alike. It works when Word has split the marker across
  several runs, as every other marker does, and the relationship it adds to the
  package cannot collide with one the template already uses (§5.7).
- **The mark is drawn at its natural size at 96 dpi, capped to fit 40 × 20 mm and
  never enlarged.** A logo exported at 3× comes out the right size rather than
  filling the page, and a small one is not blown up into a blur. Still one
  upload — the same picture that appears in the bar — and if that box turns out
  to be wrong the next thing added is a size on the profile, not a second file
  (§5.7, §8.6).
- **An installation that has uploaded no logo generates a document with nothing
  there** — not the brackets, not an empty picture. The same rule every unfilled
  marker already followed.
- **The placeholder list says which marker draws a picture**, so `[tenant.logo]`
  is not pasted into the middle of a sentence by somebody who read it as text.
  The email templates page does not offer it at all: an email has no answer yet
  for what a picture in one would be, and offering something that comes out blank
  is what that page already declines to do (§5.7, §5.13).
- **`bin/new-migration control|tenant 'what it does'` starts a migration on a
  version nobody else has** ([XIV-107]). It takes the version from the clock to
  the second, checks it against both sets, and writes the file — no container
  needed. Not `doctrine:migrations:generate`, which knows about one of the two
  sets and would never look at the other (§9.2).
- **Two migrations can no longer answer to the same version**, caught in `bin/ci`
  rather than by hand at merge — [XIV-92] and [XIV-95] both chose
  `Version20260818140000` on 2026-08-18. **A version is unique across
  `migrations/control` and `migrations/tenant` alike**, which is a decision
  rather than a constraint: Doctrine records the namespace too, so duplicates
  across the two would not actually collide. The argument for the stricter rule,
  and the check, are in `tests/Unit/MigrationVersionsAreUniqueTest.php` (§9.2).
- **`bin/ci --reclaim` reclaims the test databases and stops**, running nothing
  else ([XIV-106]). It exists so the suite's own error messages have a command to
  name.

- **`bin/deploy` migrates the tenant databases, which nothing did before**
  ([XIV-61], §4.2). One command a deploy runs once per release, out of the image
  being released: it checks the secrets, migrates the control plane, then
  migrates every customer, and stops on the first failure. Until now
  `tenant:migrate` existed and **nothing called it anywhere**, so shipping an
  entity change left every customer on the old schema with the new code serving
  them.
- **The container entrypoint deliberately does not migrate tenants**, and says
  why: it runs on every container start rather than once per deploy, and at fifty
  customers that turns a restart into an operation across every customer's
  database. It still migrates the control plane, which is one database and cheap.
- **Tenant migrations are additive only, and that is now checked.** `up()` may
  not drop a table or a column, rename either, or add `NOT NULL` to an existing
  column, because a deploy walks the customer databases one at a time with the
  instance still serving. `tests/Unit/TenantMigrationsAreAdditiveTest.php`
  refuses the ones that do; `down()` is untouched, and the window this protects
  is §4.2.
- **An instance starting in production on the `APP_SECRET` or
  `TENANT_SECRET_KEYS` committed in `.env` refuses to start** ([XIV-61], §4.2).
  Those values are compiled into the production image by `composer dump-env
  prod`, so a deployment that supplies neither ran on a published secret while
  looking perfectly healthy. The refusal names the variable and prints the
  command that makes a real one. **Development, the test suite and `bin/ci` are
  unaffected** — the check does nothing outside `APP_ENV=prod`, because those
  three run on the placeholders on purpose.
- **A module can have a price, and the operator sets it** ([XIV-101],
  [§6.5](docs/architecture.md#65-a-module-can-have-a-price-and-the-operator-sets-it-xiv-101)).
  It is a control-plane row beside the module's state, never a field on a
  blueprint: what a module costs is a fact about the company deploying Xivi, and a
  price in `packages/invoice/` would be one every deployment inherited and none of
  them chose. Set on a new operator screen at `/control/modules`, or with
  `module:price`; the control plane now has a second page and a nav between them.
- **Free, priced and not-for-sale are three answers, and "nobody has said" is a
  fourth.** A module with no price is **not** a free module and is not offered in
  the store — collapsing the two is how a module ships at zero on the day somebody
  adds a column. `module:list` names any module in that state, `module:state` says
  it at the moment somebody publishes one, and the screen says it at the top.
- **The store now needs both halves to say yes**: published *and* for sale.
  Withdrawing a module from the price list takes it out of the store and
  **uninstalls nothing** — §6.2's rule about state, inherited rather than
  restated, and proved against a real tenant in
  `tests/Functional/ControlPlane/ModulePriceTest.php`.
- **One-off, not recurring**, and deliberately: recurring implies renewals,
  billing periods and dunning, none of which exist here. `Tenant::$plan` is not
  involved and still nothing reads it (§6.5 says what was rejected and why).
- **Payment is not in this**, and neither is what a customer sees in the store.
  That is [XIV-102], which this exists for.
- **The store shows what a module costs, and asking to buy one installs nothing**
  ([XIV-102],
  [§8.15](docs/architecture.md#815-a-price-a-customer-can-see-and-an-ask-that-installs-nothing-xiv-102)).
  A priced module shows its price on its tile and on its page; a free one says
  nothing about money at all, so a deployment that has priced nothing sees exactly
  the store it saw before. **There is no payment gateway and this page is not one**
  — it takes no card details, shows no total and claims no charge. Pressing the
  button records a request, and an operator answers it by installing the module.
- **Asking to buy needs its own permission**, `buy`, beside `browse` and
  `install` on the store's permission axis. It is a separate grant on purpose:
  deciding what this installation consists of and committing the company to a
  payment are different authorities, and `buy` installs nothing on its own.
  **Nobody has it after upgrading** — it is handed out on the permission screens,
  and administrators reach it through the `ROLE_ADMIN` bypass as before.
- **`tenant:purchase:collect` is new, and an operator sees nothing without it.**
  A purchase request is written into the customer's own database — the
  customer-facing instance holds no write privilege on the control-plane database
  and must not (§4.4) — so this command walks the tenants one at a time and copies
  what it finds into `/control/purchases`, which is the control plane's third
  page. **Put it in the deployment's crontab**, more often than
  `tenant:usage:collect`: a usage figure is a background fact and a purchase
  request is somebody waiting.
- **The price on a request is a copy**, frozen when the customer pressed the
  button. Raising a module's price changes what the next customer is quoted and
  changes nothing about a request already made (§5.9, §5.16, §6.5).

### Fixed

- **An order with no lines can no longer be confirmed** ([XIV-110],
  [§5.8](docs/architecture.md#58-lifecycles-xiv-14)). A lifecycle could only
  refuse the moves its *graph* forbade, so an empty order with a total of zero
  confirmed cleanly — nothing else in the engine was going to catch it, because
  field validation is per field and would have demanded the line of a draft too.
  A module can now declare a condition on a transition, in code, and the engine
  checks it: the button is not drawn when it would fail, **and a retyped POST is
  refused as well** — the first is the courtesy, the second is the enforcement.
- **A step that cannot be taken says why**, in the module's own words and its own
  catalogue, where the button would have been: "An order needs at least one line
  before it can be confirmed." A missing button on its own explains nothing.
- **A generated demo order with no lines now stays a draft** instead of being
  walked to `confirmed`. One in seven is generated empty, and the guard refuses
  them the same way it refuses a person — which is the point of walking the
  lifecycle rather than assigning states (§5.17).
- **Saving is unchanged.** A guard is a condition on a *move*, not on a write:
  `RecordWriter` still validates nothing, and a half-finished draft still saves.
  Refusing a save is [XIV-73]'s question and is still open.

- **The production images no longer contain the working copies of every agent
  that has ever run against this checkout** ([XIV-96]). `.claude/` was not in
  `.dockerignore`, so `COPY … . ./` pulled in thirty-three complete checkouts of
  the repository — including thirty-three copies of the administration surface,
  inside the image built specifically not to contain it. **7.3 GB down to
  462 MB.** `.gitignore` is not `.dockerignore`, for the second time; the
  customer-facing build now also refuses to finish if a copy of the package is
  anywhere under `/app`, rather than only at the three paths it used to check.

- **A field marked unique is now enforced by a unique index rather than by a
  query, so two saves arriving together can no longer both write the same value**
  ([XIV-109], [§7.2](docs/architecture.md#7-open-design-questions)). It was a
  validator that read the table and then let the save proceed — a read and a
  write with no lock between them, which two people pressing Save at the same
  moment walked straight through. The index is created when the flag goes on,
  dropped when it goes off or the field is removed, and covers live records with
  something in the field: several records with nothing in it are still not
  duplicates, and a deleted record no longer reserves its value.
- **A save that loses that race comes back as a message on the field**, not as a
  500. The validator stays and still catches every ordinary duplicate while the
  form is open; the index only fires on the moment between its read and the
  write, and that refusal is turned back into a form error naming the field.
- **Marking a field unique now names the values that are in the way** when
  records already share one, instead of only counting them — the count was true
  and left somebody scrolling a list looking for rows they could not describe.
  The change is still refused, which is the decision: applying it anyway leaves
  records nobody can save.
- **A numbered field is now a unique field.** Turning numbering on marks the
  field unique beside `derived` and builds its index, which closes the window
  [XIV-91] wrote down in §5.10: the scan that floors the counter now runs against
  a table nothing else can write to. Turning numbering *off* leaves the field
  unique — the numbers are on documents customers are holding, and the field
  becoming typeable again is exactly when that matters.
  **Order and invoice numbers are affected on existing installations**: the
  tenant migration below marks them unique.
- **On upgrade, `bin/deploy` builds every index a customer's current definitions
  imply**, reading their own field definitions rather than the module blueprints.
  **A tenant whose column already holds the same value twice will fail to
  migrate**, by design: `tenant:migrate` reports it, the other tenants are
  unaffected, and it is retried with `--slug` once those records are fixed. The
  Postgres error names the index and the duplicated value.
- **A self-service name that would collide once translated is now refused when it
  is asked for** ([XIV-98]). `tenant.slug` holds provisioning slugs — underscores
  legal, hyphens not — and a signup slug is the mirror image, so an operator's
  `acme_bau` was invisible to the check made about `acme-bau`. Two customers
  could therefore be promised names that become one. The intake now asks the
  registry about the translated name and about the hostname it would take, both
  answered `slug_taken` (§8.12, §8.14).
- **A name that could never become a database name is refused too**, with
  `invalid_slug`: a single character, a leading digit, or anything past 56. All
  three are legal hostname labels and none can be an unquoted PostgreSQL
  identifier, so they used to be accepted, confirmed and then refused for ever in
  a run nobody was watching. A name *derived* from a company name is shortened to
  fit rather than refused (§8.14).
- **Two checkouts landing on the same port offset now say so** ([XIV-86]). The
  offset comes from a checksum of the directory name modulo one hundred, so at
  seven parallel worktrees a collision is about one in five and at twelve it is
  better than even — and it had already happened. `bin/compose up` and `bin/ci`
  check whether another compose project is publishing this checkout's ports
  before starting anything, and refuse with the offset, the checkout holding it,
  its directory and the six exports that move this one somewhere free. **The
  reason this is worth refusing over is `DATABASE_PORT`:** it is not one of the
  ports Docker announces by failing to bind it, it is the address `bin/compose`
  prints for PhpStorm and `psql`, and on a collision it answers — as the *other*
  checkout's Postgres, with a full set of that checkout's tenant databases in it
  (§9.2).
- A checkout that does not collide is unchanged: the same offset, the same
  addresses, the same bookmarks. An explicitly exported port is still honoured
  and is not subject to the new check — somebody exporting these has already
  resolved a collision by hand and must not be refused for it. The check runs on
  the subcommands that create containers and nowhere else, so `bin/compose exec`
  and friends cost what they always did.
- **`tenant:deprovision` works on a tenant that is still in use.** Postgres
  refuses `DROP DATABASE` while anything is connected, so removing a customer who
  was still being served failed with `SQLSTATE[55006]`. The removal now
  disconnects the sessions on that database first, as a deliberate step rather
  than a flag: §4.1 refuses to make `suspended` a prerequisite, and a live tenant
  is by definition one with sessions open ([XIV-94]).
- **A record form now counts its rows before it builds them** ([XIV-90]). The
  400-row cap on a collection was enforced after the submission had been built
  into one form per row — twice over, since the live form builds a throwaway copy
  beside the real one — so a hand-crafted 401-row post needed 273 MB of the 256 MB
  a request is allowed and answered a 500 instead of the refusal. The rows are now
  counted from the submitted values, before any form exists: 1.9 MB and 31 ms
  against 273 MB and 6.3 s. Same limit, same sentence, same numbers as before —
  `RecordWriter` still refuses independently, which is what keeps the cap true for
  the importer and everything else. A submission whose values cannot be counted at
  all is refused with a message of its own rather than one naming a made-up count.
  See [§5.1](docs/architecture.md#counting-the-rows-before-the-form-is-built-xiv-90),
  which also has the figures and one thing left open.
- **A branch that adds a control migration no longer poisons its own test
  databases** ([XIV-106]). The suite's control-plane databases (`app_test`,
  `app_test1…8`) are on the persistent `database` server rather than the tmpfs
  one, and nothing ever dropped them — so they outlived the branch that migrated
  them, and renaming or amending a migration while iterating on it left
  `doctrine_migration_versions` describing a tree that no longer exists. Every
  later run then died in the PHPUnit bootstrap, before a single test, with a
  driver exception naming a table and never mentioning a migration. `bin/ci`
  empties them at the start of every run now, in a step of its own — 0.5s for
  nine, against 15.4s to drop and recreate them, and the bootstrap migrates from
  scratch either way (§9.2).
- **That reclaim cannot take a dev control plane with it.** The pattern is
  derived from whatever `DATABASE_URL` in the php container names, not from the
  literal `app_test` — which is a test database here and would be an ordinary dev
  one in a checkout whose `POSTGRES_DB` is `app_test`. Verified by running that
  configuration: the suite's `app_test_test` was emptied and the dev `app_test`
  kept every table. What it deliberately does not cover is written down beside it
  in `bin/ci`.
- **A leftover database that gets past it now says so.** A bare `composer test`
  does not go through `bin/ci` and can still meet one; the bootstrap answers with
  the database, the server it is on and why, the cause, and `bin/ci --reclaim`,
  instead of the driver exception alone ([XIV-106]).

### Measured

- **What it takes to end another role's Postgres session**, on this project's
  Postgres 18 rather than off a manual page: a role with exactly
  `CREATEDB CREATEROLE` is refused with `42501` against a tenant role it created
  itself, because a `CREATEROLE` grant has carried `ADMIN` without `INHERIT` since
  Postgres 16, and succeeds once granted `pg_signal_backend`. The same experiment
  found two further obstacles for a non-superuser provisioning role, neither
  addressed here — see §4.1 ([XIV-94]).

### Decided

- **Symfony's ExpressionLanguage is not adopted**, and the brief now says where
  it would and would not fit so the question is not re-derived ([XIV-88],
  [§5.8](docs/architecture.md#58-lifecycles-xiv-14)). Nothing changes for anybody
  using Xivi; what changes is that **record-level permissions (§8.4) and filters
  (§5.3) are explicitly closed to it** — a rule evaluated in PHP over a loaded
  record cannot be the `WHERE` clause both of those are made of — and that a
  condition on a lifecycle transition, which is the one place that does fit, is
  recorded as wanting a typed predicate rather than an expression, because a
  lifecycle is declared by a module in code.

### Upgrade notes

- **`tenant:purchase:collect` belongs in the crontab** ([XIV-102], §8.15). Two
  migrations, both additive and neither backfilling anything: one tenant migration
  creating `module_purchase_intent` in every customer's database, one control
  migration creating `purchase_intent`. Nothing appears on `/control/purchases`
  until the command has run, and nothing can appear at all until some module has a
  price — which no module in this build has. **`deploy:registry-grants` needs
  re-running only if you have changed the registry**; `purchase_intent` belongs to
  the administration surface and is deliberately *not* granted to the
  customer-facing role, which the command now says out loud in its withheld list.
- **Price the modules you have published, or nobody is offered them**
  ([XIV-101], §6.5). One control-plane migration adds `pricing` and
  `price_amount` to `module`. **Rows that already exist backfill to `free`**,
  which is what every module in this repository is today (§6.3), so an
  installation with customers browsing the store sees no change. Rows written
  *after* the migration default to `unpriced` instead, and an unpriced module is
  not offered — so the next module somebody publishes needs `module:price` (or the
  screen) before anybody can install it. `module:list` and `module:state` both say
  so when it applies.
- **`PRICE_CURRENCY` is new and empty by default.** It is the ISO 4217 code this
  deployment's price list is in — one answer for the whole installation, not the
  currency on a tenant's profile (§8.6), which is about that customer's own
  documents. Left empty, prices render as bare numbers and the operator screen
  names the variable. It is an environment variable and the prices are emphatically
  not: a deployment picks its selling currency once, and changing it invalidates
  every figure on the list at the same moment.
- **The customer-facing deployment needs its own database user, and it is easier
  to arrange now than later** ([XIV-96], §4.4). Run
  `bin/console deploy:registry-grants <role>` and apply the SQL it prints: the
  public instance gets `SELECT` on the registry tables and nothing else, so an
  `INSERT INTO tenant` from the process facing the internet is not possible
  whatever the routing says. A deployment that keeps one database user for both
  instances still works and loses that guarantee.
- **The customer-facing image does not run the control-plane migrations.** It
  checks that somebody else has and **refuses to start** if not, so `bin/deploy`
  — out of the internal image — has to run before the public containers are
  replaced. That was already the documented order (§4.2); it is now enforced.
  `bin/deploy` refuses to run out of the public image at all.
- **Nothing to do for a single-instance deployment.** One image, one database
  user and one set of containers behaves exactly as before; all of the above is
  opt-in by building the second target.
- **Self-service signup needs a cron entry, and does nothing without one**
  ([XIV-98]). A deployment with `SIGNUP_HOST` set must add
  `*/5 * * * * cd /srv/xivi && bin/console signup:provision`; until it does, a
  confirmed signup sits in the intake table and the person who made it waits for
  a mail that is never sent. Deployments with signup switched off need nothing.
  The command needs `TENANT_ADMIN_DSN` in its environment — it is the privileged
  half of the feature (README, §8.14).
- **One control-plane migration**, adding three columns to `signup_request` that
  record what happened the last time a signup failed to provision. Existing rows
  backfill to "never tried", which is what they are ([XIV-98]).
- **Provisioning credentials short of superuser now need `pg_signal_backend`.**
  Deployments using the default superuser `TENANT_ADMIN_DSN` are unaffected. A
  narrowed one needs `GRANT pg_signal_backend TO <provisioning role>`, which
  `tenant:deprovision` now names in the error when it hits the wall — nothing is
  destroyed in that case ([XIV-94]).

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

[XIV-86]: https://xivi.youtrack.cloud/issue/XIV-86
[XIV-90]: https://xivi.youtrack.cloud/issue/XIV-90
[XIV-94]: https://xivi.youtrack.cloud/issue/XIV-94
[XIV-89]: https://xivi.youtrack.cloud/issue/XIV-89
[XIV-92]: https://xivi.youtrack.cloud/issue/XIV-92
[XIV-95]: https://xivi.youtrack.cloud/issue/XIV-95
[XIV-98]: https://xivi.youtrack.cloud/issue/XIV-98
[XIV-61]: https://xivi.youtrack.cloud/issue/XIV-61
[XIV-91]: https://xivi.youtrack.cloud/issue/XIV-91
[XIV-70]: https://xivi.youtrack.cloud/issue/XIV-70
[XIV-27]: https://xivi.youtrack.cloud/issue/XIV-27
[XIV-1]: https://xivi.youtrack.cloud/issue/XIV-1
[XIV-58]: https://xivi.youtrack.cloud/issue/XIV-58
[XIV-64]: https://xivi.youtrack.cloud/issue/XIV-64
[XIV-65]: https://xivi.youtrack.cloud/issue/XIV-65
[XIV-106]: https://xivi.youtrack.cloud/issue/XIV-106
[XIV-107]: https://xivi.youtrack.cloud/issue/XIV-107
[XIV-109]: https://xivi.youtrack.cloud/issue/XIV-109
[XIV-93]: https://xivi.youtrack.cloud/issue/XIV-93
[XIV-101]: https://xivi.youtrack.cloud/issue/XIV-101
[XIV-102]: https://xivi.youtrack.cloud/issue/XIV-102
[XIV-88]: https://xivi.youtrack.cloud/issue/XIV-88
[XIV-110]: https://xivi.youtrack.cloud/issue/XIV-110
[XIV-73]: https://xivi.youtrack.cloud/issue/XIV-73
[XIV-66]: https://xivi.youtrack.cloud/issue/XIV-66
[XIV-96]: https://xivi.youtrack.cloud/issue/XIV-96

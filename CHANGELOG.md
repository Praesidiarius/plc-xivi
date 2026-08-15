# Changelog

What has changed in Xivi, and when. The design *reasoning* lives in
[docs/architecture.md](docs/architecture.md) and stays there; this file is the
record of what was built.

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

## [Unreleased]

### Added

- **Documents from Word templates** ([XIV-4]). A module's records can be
  downloaded as a filled-in .docx or PDF, from templates the customer writes in
  Word and uploads themselves. Both formats every time: the PDF is what gets
  sent, and the .docx is what somebody edits when a letter needs a sentence the
  template has not got.
- **The placeholder list comes from the customer's own definitions.** A field
  they added this morning is `[a_marker]` this afternoon, and one they removed
  stops being offered — the same claim the form and the list already make, so
  there is no documentation to go stale. Values are written the way the field
  type shows them, so a date reads as a date and a price as "CHF 19.90".
- **A template may name a variant** (§5.5) — a letter to a person is a different
  document from a letter to a company, and one naming no variant is offered on
  every record of the module.
- **Uploading templates and generating documents are two permissions**
  (`templates` and `document`), which is what the enum crossed with the modules
  gives for free. Whoever designs the invoice is not whoever sends one, and a
  template decides what every future document of that kind looks like.
- **Three libraries, all MIT** (§5.7): `anourvalar/office` fills the .docx, where
  PHPWord — the obvious choice — is LGPL-3.0; and `sensiolabs/gotenberg-bundle`
  talks to a Gotenberg container that converts it with LibreOffice, where every
  pure-PHP PDF library is LGPL or GPL and none of them can read a .docx at all.
  DomPDF would have meant docx → HTML → PDF, which throws away the header, the
  footer and the fonts the template was made in Word for.
- **The first files this system keeps live in the tenant's own database**, in a
  bytea column. Templates are small and few, so the isolation §4 already provides
  costs nothing extra here — no volume, no bucket, no paths to get wrong.
  Deliberately a bounded decision and not the general file-storage design, which
  attachments will still need.

- **An article module** ([XIV-11]) — a title, a description and a price. The
  second module on the engine, and the first one nobody had to change the engine
  for: a declaration and two field types, with no controller, no entity, no form
  class and no template of its own. Contact showed the engine could describe a
  module; this one is the check that it was not quietly built around that
  particular module.
- **A `textarea` field type.** Its own type rather than an option on `text`,
  because everything that follows from the length differs — a box instead of a
  line, a default maximum in the thousands, and no "starts with" filter, which is
  not a question anybody asks of a description.
- **A `currency` field type**, built on Symfony's `MoneyType`: the Bootstrap
  theme's own `money_widget` draws the input group, with the instance's currency
  ([XIV-12] supplies it) beside the amount on whichever side the reader's locale
  puts it, and no currency at all until one is chosen. Stored as a decimal string
  and never a float — 19.90 has no exact binary representation, and the place a
  lost hundredth of a cent turns up is an invoice, so the form is asked for
  `input: 'string'` and hands one back. The currency is not stored beside the
  amount: one per installation means a column of prices adds up, where per record
  it would need exchange rates behind it to mean anything.
- **A field type may now ask the application something it cannot know** (§5): core
  declares `InstanceCurrency` and the application answers it from the tenant
  profile, the same shape as the entity manager and the connection being bound in
  `config/services.yaml`. Deliberately not a field type reaching into a customer's
  settings table on its own, which would be §3's boundary quietly gone.

- **A tenant profile** ([XIV-12]), at `/settings/profile`: what this customer
  calls themselves, and the currency their instance works in. It lives in the
  customer's own database next to their users and definitions (§8.6) — the
  control plane's `tenant.name` stays the operator's label in the registry, and
  the chrome shows the company name once there is one.
- **The currency is an ISO 4217 code**, never a symbol or a formatted string. The
  list comes from symfony/intl, named in whatever language is being read, and
  null until somebody chooses: a currency guessed for a customer would only
  surface on the first priced thing they printed.
- **Permission areas** — things worth granting that no module owns (§8.4). The
  catalogue is now the action enum crossed with the customer's modules *and* a
  closed set of areas, still worked out at runtime with nothing seeded and
  nothing migrated. An area is stored in the grant table's existing `module_key`,
  which was never a join; area keys begin with `@`, which module keys cannot, so
  they can never collide. Reading the profile and changing it are separate
  grants, and neither of them is "administrator".

- **Modules have a state** ([XIV-7]), platform-wide: `development` or `published`,
  a closed set that grows by adding a case. Preparation for the store ([XIV-6]),
  which is the only thing that will read it — so that a module can exist in a
  build without being offered to anybody.
- **A module with no decision recorded is in development**, which is how a new
  module gets the right default without a sync step whose only job is to write it
  down. The state lives in the control plane rather than in the blueprint,
  because publishing is a decision about whether customers may have a module, not
  a change to the module — the same rule that puts presets in code and templates
  in data (§6.1, §6.2).
- **`module:list` and `module:state`**, the process for seeing and changing it.
  Neither takes a tenant, because the answer is the same for all of them. A state
  row naming a module the build no longer ships is listed and flagged rather than
  hidden, and never offered — the store cannot install what the deploy does not
  carry.
- Installing names the state and does not enforce it: a module is developed by
  installing it somewhere, so refusing the case the state exists to describe
  would be backwards.

### Changed

- **A record's history is compact, and no longer the whole thing** ([XIV-3]). An
  entry is one line — when, what, who, and how many things it touched — with the
  changes themselves behind a native `<details>`. Native rather than a Bootstrap
  collapse: no JavaScript, and a timeline is exactly the sort of thing somebody
  reads with the keyboard.
- **The record page shows the latest five and says how many there are**, linking
  to a history page of its own that pages twenty-five at a time. The card is now
  the same height on a record edited once and one edited daily for a year; before
  this it rendered up to fifty entries with every change expanded under each, and
  said nothing at all about the fifty-first.
- **Older entries fold away**: the timeline is grouped into today, this week,
  this month, this year and earlier, and anything older than a month opens
  closed. The first section on a page is always open, so a page deep enough to
  hold only old entries is not a screen of shut boxes.
- **The timeline is ordered by when things happened**, with the id breaking ties,
  where it used to be by id alone. The same answer as long as rows are only
  appended as things happen, and a different one as soon as anything backfills an
  entry with an older timestamp — which would put a section boundary in the
  middle of a day.
- **The export's content type comes from `symfony/mime`** ([XIV-5]) instead of a
  constant copied into `ModuleController`. The package is now a direct
  dependency, and the MIME type is looked up from the `.xlsx` extension the
  export is already named for — a table worth having somebody else maintain.
  Looked up rather than sniffed: the response would otherwise ask libmagic what
  the bytes are, which is a different answer per image for a file we wrote.

## [17.0.1] — 2026-08-15

Two features that had been open questions since the brief was written — who may
do what, and in which language — plus the test suite going from 165s to 10s along
the way.

### Added

- **A fine-grained permission system** ([XIV-2]), settling §7.5 and replacing
  "authenticated, plus `ROLE_ADMIN`". What can be done is a closed `ModuleAction`
  enum — view, list, add, edit, delete, export, import — so the catalogue of
  permissions is that enum crossed with the modules a customer has installed,
  worked out at runtime. There is nothing to seed when a module is installed and
  nothing to migrate when an action ships.
- **Groups and grants.** A grant says "this holder may do this to this module,
  this far", held by either a group or one user, with the database enforcing that
  exactly one of them is set. Resolution is a union with no denies, so it is a
  maximum rather than a precedence table, and a user's own grants only ever add
  to what their groups gave them.
- **Scope: all records, or only your own.** It applies to every action that names
  a record which already exists — view, list, export, edit, delete — and not to
  adding or importing, which name none.
- **Record access is a WHERE clause**, not a check after loading. A voter is
  handed one subject and cannot answer "which twenty-five am I looking at"; the
  page and its total are separate queries, so a restriction reaching one and not
  the other would print the number of records somebody may not see directly
  underneath the ones they may. The predicate sits beside the soft-delete one in
  `QueryCompiler`, and the export carries it too.
- **Two voters** for the point checks: one for "may you do this to any of this
  module's records", annotated on the routes, and one for a single record. A
  record you may not view answers 404 rather than 403, so guessing ids cannot be
  used to find out which ones exist; one you may view but not change answers 403,
  because that is the true answer.
- **Importing is its own permission per module**, no longer `ROLE_ADMIN` — the
  answer §5.6 said §7.5 would give it.
- **Grants can also be made to one person**, on their own page, alongside their
  group membership — the exception the group model cannot express without
  inventing a group of one that nobody can read the purpose of. What their groups
  already give is shown beside each cell and never merged into it: merged,
  somebody grants a thing twice and then cannot work out why removing it changed
  nothing. Each cell shows what the person can actually do — groups and personal
  grant folded together — and cannot be set below what the groups give, since
  grants only ever add. Only the part wider than the groups is stored, so saving
  the form back unchanged writes nothing and a grant a group has since covered is
  tidied away.
- **A screen for granting permissions**, at `/users/groups`. Until it existed the
  only way to grant anything was a console command against the customer's
  database, which is not a thing a customer has — the same argument §8.4.1 made
  for building the user manager before the permissions themselves. A group is
  named, given a matrix of modules against actions with each cell reading no /
  own records / all records, and given members. Creating one goes straight to the
  matrix, because a group with no grants does nothing.
- The matrix offers scope only where scope means something: `add` and `import`
  read no / yes, since "add, but only the ones you own" describes nothing. The
  form asks the enum rather than knowing.
- Saving replaces the group's grants rather than merging them, so setting a cell
  back to "no" is how a permission is taken away. Deleting a group says how many
  people are in it first, and deletes none of them.
- **The navigation shows only the modules you may open.** A module you cannot
  list is not in the topbar and not on the dashboard: navigation that advertises
  doors and then refuses them is worse than navigation that is honest about the
  building. The empty dashboard now tells its two states apart — "nothing is
  installed", which an administrator can fix with a command, and "nothing is
  yours", which they cannot.
- **Buttons follow the same rule**, through a `can()` Twig function, and the
  per-row ones are asked about the record rather than the module, since with a
  scope of "own" the answer differs from one line to the next. This hides
  controls and protects nothing — every route still decides for itself.
- **The build fails when a module route names no permission.** The surface is
  defined by the URL — any route whose path contains `{module}` — rather than by a
  list of controllers, so a new controller is covered the day it is written and
  there is no list to drift. It also catches a mistyped attribute string, which
  would otherwise 403 for everybody including administrators and read as a
  permissions problem rather than a spelling one; a permission with no subject,
  which fails closed and silently; and an action added to the enum that nothing
  uses, which would appear in the admin screen as a control that does nothing.
  `#[NoModulePermission('why')]` is the deliberate opt-out, and it demands a
  reason.
- **`tenant:permissions:grant-all`**, the deliberate upgrade path for an
  installation that predates this, and the way back into one that has locked
  itself out. The migration is structural and writes no grants: it lands for every
  tenant at once, and deciding what a customer's people may do is not something to
  do to them in passing.
- **Demo data generation** (`tenant:demo:generate`), for finding out what the
  list, the query layer and the paging do at a size nobody types by hand. It
  walks a module's own definitions and asks each field *type* for a plausible
  value, so a field added in the editor is filled in without the generator
  knowing it exists, and a new field type gets demo data by implementing one
  method. `--seed` makes a run repeatable.
- `FieldType::sample()`, which is where that value comes from. A `choice` returns
  one of its own options — so generating contacts produces both people and
  companies without the generator having heard either word — and a `reference`
  returns the id of a record that really exists.
- **`tenant:demo:clear`**, which removes exactly what a generator made and
  nothing else. Generated ids are written to a `demo_record` ledger, so a record
  somebody typed into the same module survives the cleanup.
- Both commands are registered in dev and test only, and are absent from a
  production image entirely.

- **Localization** ([XIV-8]), in progress. `symfony/translation` does the work and
  `symfony/intl` names the languages, so the picker offers "Deutsch" rather than
  "German" — somebody looking for their own language is not reading the one they
  cannot. English is the source and the fallback; German is informal (*du*),
  decided deliberately because changing the register later means rewriting every
  string.
- **The language is per person, not per customer**, on `app_user.locale`, chosen
  on the account page. One office is not one language. Null means "follow the
  application default", which is a different promise from choosing English: one
  keeps following the default if it moves.
- Resolved per request from the signed-in user and **never parked in the
  session** — that would be state outliving the request that made it, which this
  runtime otherwise does not have (§7.4, §9.2). The login page has nobody to ask,
  so it asks the browser.
- **A missing translation fails the build.** The catalogues are compared key for
  key in both directions: a key with no German is the quietest bug here, since the
  fallback keeps the page working and merely serves one paragraph of it in the
  wrong language on somebody else's screen.
- **A module's labels are seeded from its own catalogue**, read once at install
  time: `tenant:module:install --locale=de` gives that customer "Kontakte" and
  "Vorname" rather than the blueprint's English. Once written they are the
  customer's data (§5) and stop following the catalogue — resolving a label on
  every render would overrule a rename every page load, which would make the
  screen offering that rename a lie.
- **A module can be renamed**, which it could not be before. Field labels were
  already the customer's to change; the shape holding them was not, so a module
  installed in the wrong language could not be corrected at all.
- Labels therefore stay one language *per tenant*, not per reader: two colleagues
  share one row, and a label that changed with who was looking would have stopped
  being data.
- **Every string in the interface is now a catalogue entry**, across all nineteen
  templates. Counted sentences use ICU plurals rather than a ternary, because
  "one" and "other" are not the only two answers every language has and building
  the sentence in Twig bakes English grammar into the template.
- The engine ships **its own catalogue** from `packages/core/translations`, so
  core can name a filter operator or a permission action without reaching into
  the application's file. A module package would do the same.
- **Flash messages and refusals are translated too.** A refusal carries a key
  beside its message rather than instead of it: the exception's own text stays
  English and goes to the log, where the reader is a developer, and the customer
  gets the same fact in their own language. Two audiences, two sentences, neither
  a compromise for the other.
- Import problems carry a key and their parameters, with the sheet and row
  wrapped around them as a nested translatable rather than concatenated — so a
  translator can reorder the parts, which German wants often enough to matter.
- **Tooltips on the icon-only buttons** in the record list, the user list and the
  group list. Bootstrap's JavaScript is now shipped for them — it was deliberately
  absent, and the rule it was protecting still holds: the forms work without
  scripting, and a `title` stays on the element either way, so a failed asset load
  costs a hint rather than a feature.

### Changed

- **The test suite runs against a database kept in RAM** ([XIV-10]) — 85s to
  **10s**, and 165s to 10s counting from before any of this. A second Postgres
  service on `tmpfs` with `fsync=off`, because provisioning is what the suite
  spends its time on and provisioning is disk-bound: `CREATE DATABASE` copies a
  template and eleven migrations commit repeatedly. A *second* service, not a
  setting on the first, so the dev tenants keep their disk and their crash safety.
- It removed the reason for two other planned changes. The critical path was
  `LoginTest` at 27s and `TenantIsolationTest` at 26s, both reprovisioning tenants
  per test method; they now take 4s and 3s, so making them share a tenant — which
  would have traded away the independence they exist to prove — buys nothing.
  `paratest --functional` was measured too: 8s against 10s, with one consistent
  failure and an assertion count that varied between runs. Two seconds is not
  worth a suite that disagrees with itself.
- **The test suite runs in parallel** ([XIV-9]) — 165s to about 85s. Each worker
  namespaces the tenant databases and roles it creates, which it has to: those are
  *cluster* objects while the registry naming them is one database, so two workers
  with a registry each would both claim `tenant_test_locale` and the second would
  connect with a password only the first's registry knows. That was exactly the
  failure, 245 of them, before the prefix existed.
- The isolation tests that asserted a literal database name now read the
  configured prefix instead. They still prove what they proved — which database a
  tenant reaches, and that its credentials cannot open another's — they simply
  stopped asserting the test runner's own bookkeeping.
- Eight workers, pinned rather than one per core: past about four there is no
  gain, because the wall clock is bounded by the slowest single class and by
  Postgres serialising `CREATE DATABASE`. Pinning also means each run reclaims the
  databases the last one left, which a varying count would strand.

- **The session no longer carries a user's groups and grants.** `User` is
  serialized into the security token, and a Doctrine collection in there comes
  back detached — so touching it throws rather than loading lazily, and a person
  in several groups would have written their whole permission model into the
  session on every request only for it to be refreshed from the database anyway
  (§8.2). The excluded properties are listed one by one rather than derived, so a
  column silently missing from the session is not a thing that can happen quietly.
- **`bin/ci` refuses a branch that adds no changelog entry**, before it starts the
  stack, so forgetting costs seconds rather than the whole suite. It also notes
  when a branch name carries no issue number. `--no-changelog` is the deliberate
  escape hatch for a branch that genuinely changed nothing anybody would be told
  about — a check with no way out is one that gets deleted rather than answered.

## [17.0.0] — 2026-08-14

The first version to carry a number. Everything below was built before versioning
began and is recorded here as one entry rather than invented as a history.

### Tenancy and deployment

- One deployed codebase serves every customer. The tenant is resolved per request
  from the `Host` header against a control-plane database (§4).
- **A database per tenant.** Isolation is physical, not a `WHERE tenant_id = ?`
  that can be forgotten.
- **A control plane, not config files.** Domains, DSN, status, plan and enabled
  modules are rows, so onboarding is a command rather than a deploy.
- **Per-tenant PostgreSQL roles**, with all rights revoked from `PUBLIC`. A wrong
  DSN fails to connect instead of reading another customer's data. Passwords are
  generated with `random_bytes` and stored encrypted (libsodium), under keys that
  stored values name individually so rotation is resumable.
- Migrations split into `migrations/control` (once per deploy) and
  `migrations/tenant` (once per tenant), applied by `tenant:migrate`.
- Docker throughout: FrankenPHP and PostgreSQL 18, with `bin/ci` running the same
  checks locally and in GitHub Actions.
- **FrankenPHP without worker mode, on purpose** — no PHP state survives a request
  boundary, so cross-tenant leakage (§7.4) is structurally impossible for web
  requests.

### The engine

- **Metadata-driven records (§5).** A module declares its fields; installing it
  writes those definitions into the customer's database and creates its table.
  From then on the definitions drive validation, storage, the form, the list and
  the record page. `packages/contact` is a declaration and nothing else — no
  entity, repository, controller or form class.
- **Child collections (§5.1).** A collection is the same kind of thing as a
  module: its own table with a real foreign key, its own definitions, edited
  inline with its parent and soft-deleted with it.
- **History (§5.2).** One history table per module, never a shared polymorphic
  one, and one entry per action rather than per row touched. Every write goes
  through `RecordWriter`, which owns the transaction and the entry.
- **The query layer (§5.3).** Filtering, sorting and paging compiled from the
  customer's own definitions: a filter bar, sortable columns, and a filtered list
  that is a URL. Collection filters compile to `EXISTS` semi-joins.
- **The metadata editor (§5.4).** Adding, relabelling and removing fields on any
  shape, administrators only. Changes that would strand data are refused with a
  count rather than performed; removing a field leaves its values in place.
- **Variants (§5.5).** A contact is a person *or* a company in one module, each
  with its own fields, so a link to a contact stays a plain foreign key.
- **References (§7.6, in part).** A person links to their company by id; the
  company's people are a query rather than a second copy of the answer.
- Field types: text, email, integer, date, choice and reference, in a closed
  registry — each owning its validation, storage, form type, display and the
  operators it can be filtered by.
- Presets (§6.1): a module ships named subsets of its own fields, chosen at
  install time with `tenant:module:install --preset`.
- A title flag saying which fields name a record, and a listed flag saying which
  fields the list shows a column for.

### Getting data in and out (§5.6)

- **Export**: a module's records as a spreadsheet, one sheet per shape, headers
  as field keys, values in storage form, carrying whatever the list was filtered
  to.
- **Import**: the same file back. Every row is validated by the rules the form
  uses and applied in one transaction or refused whole. A check is the import
  rolled back rather than a separate code path, so it catches what only a write
  can. An export imported back unedited changes nothing.

### Identity and access (§8)

- Users live in each customer's own database, so the same email is a different
  person at a different customer. Sessions are stamped with the tenant that
  created them and refused anywhere else.
- **User management (§8.4.1)**: adding colleagues, making them administrators,
  deactivating leavers, resetting a lost password, and an account page for
  changing your own name and password.
- Nobody is deleted — records carry an owner id and history a user id, so
  deactivating keeps every record attributable and is reversible.
- Deactivation is enforced at sign-in *and* against sessions that already exist.
- **A generated password must be replaced before the account is usable**: until
  it is, every page leads back to the account page.
- Every lock-out route is refused: your own account, your own administrator role,
  and the last active administrator.

### The interface

- Server-rendered Twig with Bootstrap's CSS and Bootstrap Icons, self-hosted
  through AssetMapper. No Node, no bundler, no CDN calls, and the forms work
  without JavaScript.
- One generic controller and one generic form serve every module.

### Checks

- `bin/ci` runs the whole suite in the same containers as CI: `composer validate
  --strict`, a dependency audit, coding standards, deptrac module boundaries,
  PHPStan level 8 over `src/`, `tests/` and `packages/`, PHPUnit, and a build of
  the production image.
- 220 tests, with functional tests that provision real tenants — real databases
  and real PostgreSQL roles.

[XIV-2]: https://xivi.youtrack.cloud/issue/XIV-2
[XIV-3]: https://xivi.youtrack.cloud/issue/XIV-3
[XIV-4]: https://xivi.youtrack.cloud/issue/XIV-4
[XIV-5]: https://xivi.youtrack.cloud/issue/XIV-5
[XIV-6]: https://xivi.youtrack.cloud/issue/XIV-6
[XIV-7]: https://xivi.youtrack.cloud/issue/XIV-7
[XIV-11]: https://xivi.youtrack.cloud/issue/XIV-11
[XIV-12]: https://xivi.youtrack.cloud/issue/XIV-12
[XIV-8]: https://xivi.youtrack.cloud/issue/XIV-8
[XIV-9]: https://xivi.youtrack.cloud/issue/XIV-9
[XIV-10]: https://xivi.youtrack.cloud/issue/XIV-10

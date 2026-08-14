# Changelog

What has changed in Xivi, and when. The design *reasoning* lives in
[docs/architecture.md](docs/architecture.md) and stays there; this file is the
record of what was built.

## How the version works

The format is `17.MINOR.PATCH`, and it is **not** semantic versioning.

- **17** is a *generation*, not a semver major. It says which Xivi this is, and
  changes only when there is a new one — a business decision rather than a
  technical one. Breaking changes inside a generation do not touch it.
- **MINOR** moves when a release is cut that somebody would be told about.
- **PATCH** moves for fixes to a version already released.

**The version moves on release, not on feature.** Work lands under *Unreleased*
and moves nothing; cutting a release is the deliberate act of renaming that
heading and dating it. Nothing else can advance the number, which is what stops
it creeping while the project is moving quickly.

The number lives in [`src/Version.php`](src/Version.php), is shown in the footer
of every page, and is not yet tied to git tags.

## [Unreleased]

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
  nothing. Choices the groups already cover are disabled rather than offered and
  ignored, since grants only ever add — never the current one, because clearing a
  grant that has become redundant is a real change.
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

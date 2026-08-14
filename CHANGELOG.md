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

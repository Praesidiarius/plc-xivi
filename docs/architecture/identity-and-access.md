## 8. Identity and access

### 8.1 Users live in the tenant database

Not in the control plane: pooling them centrally would put every customer's
names, emails and password hashes into one shared database while claiming
physical isolation for everything else (§4), and would stop export-on-churn
being one `pg_dump`. One person at two customers is two rows, which for a B2B
CRM is the honest representation. The security provider is bound to the tenant
entity manager: "who is this email" is only ever answered by the database of
the customer being served.

### 8.2 Identifiers are only unique within a tenant

The sharp edge of §8.1: a session minted for one customer and replayed against
another where the same email exists would authenticate as the other customer's
user, and emails collide in practice (`admin@…`). Sessions carry the tenant they
were created for, and a mismatch invalidates the session. Anything that outlives
a request and names a user has the same obligation.

### 8.3 The UI is server-rendered, in this repository

Form login, session cookie, Twig. Not a separate SPA: v1's separate front-end
build meant a per-customer `yarn build` and the module list compiled into each
customer's bundle, the opposite of §3's runtime module availability.

- **It assumes JavaScript** (XIV-28): "server-rendered" and "works with
  JavaScript off" were separate claims and only the second was given up, when
  collection forms needed rows that change shape as somebody picks.
- **Symfony UX Live Components, after htmx** (XIV-33). htmx lost on morphing (a
  form that redraws under a moving caret), on having no model for state that is
  not in the markup, and on one-vendor (§5.7's reach-for-the-framework rule).
  The accepted cost: the write path is a function of the UI library, and a
  refused save answers **200, not 422**; only the body says no.
- **What a submitted record form means is not the controller's** (XIV-30):
  whatever renders the form (controller, component, import) asks the same
  service, and none gets its own idea of what a valid record is.
- **One browser test layer, only over what only a browser can see** (XIV-31):
  deliberately tiny, because a flaky end-to-end layer gets skipped and a skipped
  safety net is worse than none. The browser is another process, so the
  transaction rollback is invisible to it (its tenant is committed and
  reclaimed), and it needs a hostname that resolves from both containers. When
  the browser layer cannot reach something, ask what the harness is missing
  before asking what the application could give up (XIV-105).
- **Components stay generic over module, record and shape**, mounted on a module
  key and an id. One component renders any record form; an `OrderForm` beside it
  would be the module-specific code §1 exists to avoid.

### 8.3.1 The dashboard is its widgets (XIV-81)

**A widget is a service that decides whether it has anything to say, and if so
names a template and hands it data.** Discovery and ordering are Symfony's
tagged iterator; nothing keeps a list, so nothing can disagree with the classes
that exist. Returning null is "does not apply to you"; a widget that throws
takes the page down rather than being quietly omitted, because a dashboard that
silently drops a panel cannot be trusted to be complete.

- **A widget's controls are its own state, not the URL's** (XIV-84): narrowing a
  summary is not navigation. The dashboard decides whether a card exists; the
  card decides what is in it. The URL keeps saying which page you are on.
- **The seam is in `packages/core`** (XIV-66), because a module package may
  depend on core and nothing else, and unpaid invoices belongs on a landing
  page. Core declares the seam and learns a tag name; the application collects.
  Implementations stay where their dependencies are. A module also gets a
  translation domain on the panel and `RecordPageUrl`, so "12 unpaid invoices"
  is twelve links without the module spelling a route name.
- **A person arranges their own page**, on §8.4.2's chain: person, then
  installation (§8.6), then every widget that applies. The columns are nullable
  because a layout, unlike a language, has an empty value: null is "never
  chose", `[]` is "deliberately cleared", and folding them together hands
  somebody back the page they just emptied. A saved layout is data referring to
  code: a key nothing answers to is dropped, like a stale reference (§7.6). A
  default is not a permission; what somebody may see is §8.4's question.
- **Panels are deferred**, and `panel()` is cheap by contract: it is asked of
  every widget on every render, so data is a promise resolved only for a panel
  actually drawn. The mount is the dashboard's one generic `DashboardPanel`
  component, so a module ships a class and a plain template with no front-end
  dependency.
- **Charts: a chart is for a trend, and nothing else** (XIV-121).
  `symfony/ux-chartjs` is in (MIT, self-hosted, no CDN) for the one case the
  rule admits: a numeric field's value over time, read off the values rather
  than a declared type, so a customer's own field gets a trend with no deploy.
  Dashboards of charts and cross-record aggregations stay refused; the argument
  is no longer "it costs a dependency", it is the rule. Permissions are asked
  again at the component's own endpoint (props are signed, not secret) and it
  draws nothing rather than refusing, because a card inside somebody's page has
  no useful way to say 404. The chart controller is the one lazy controller in
  `assets/controllers.json`, and a browser test counts non-transparent canvas
  pixels because every failure mode here is a blank box under a green suite.

### 8.4 Authorization: grants, resolved per person

- **What can be done is a closed enum**: view, list, add, edit, delete, export,
  import, per module. The catalogue is the enum crossed with the customer's
  installed modules, worked out at runtime: nothing seeded, nothing migrated,
  nothing to drift (§5's registry argument applied again).
- **Not everything grantable is a module** (XIV-12): a closed set of *areas*
  joins the modules (the tenant profile is `@profile`). Area keys begin with
  `@`, which a module key cannot, so they never collide. The verbs stay
  `ModuleAction`'s; scope does not apply to areas.
- **Only grants are stored**: one holder (group or person, one table, a check
  constraint enforcing exactly one), one action, one module, one scope.
  Resolution is a union and a maximum: **nothing can deny**, so resolution is
  order-independent and "why can this person still see that" stays a simple
  question. "Everything except one thing" is expressed as a smaller grant.
- **Scope is all records or only your own**, applying to every action that names
  an existing record; add and import name none, and the enum says so.
- **`ROLE_ADMIN` stays a bypass, not a group**: a group somebody can be removed
  from reintroduces the lock-out §8.4.1 refuses. It still gates the metadata
  editor, user management and the permission screens themselves (gating those
  with a module permission would be circular).
- **Three enforcement seams**: `#[IsGranted]` on routes; a voter for one record;
  **a WHERE clause for lists**, beside the soft-delete predicate in the
  compiler, because by the time a voter runs the page is fetched and the total
  counted separately. The export carries it too. The seams must agree: a record
  kept out of a list but reachable by typing its id is not protected. A refused
  record answers **404**, so guessing ids reveals nothing; a record you may view
  but not change answers 403, because that is true.
- **Default deny**, and the upgrade path is a command
  (`tenant:permissions:grant-all`), not a migration: deciding what a customer's
  people may do is not something to do to them in passing, and the command is
  also the way back in for a locked-out installation.
- **The build fails when a route names no permission.** The surface is defined
  by URL, so a new controller is covered the day it is written.
- **Pickers and the search endpoint are scoped** like lists, through
  `RecordAccessProvider` (core's seam, since a link cannot know which module it
  lands in). The cost: somebody scoped to their own records sees a picker that
  omits the answer, with no message; that is the safer half of the trade and the
  half a grant can widen. A search endpoint enumerates harder than a dropdown
  leaks, so it carries `view` on the target module and the same predicate, with
  a test that a scoped reader cannot find a colleague's record by name.
- **Not somewhere a customer-authored expression can go** (XIV-88): an
  expression evaluates in PHP over a loaded record, so it would restrict the
  page and not the total. Still open: what a grant means for an uninstalled
  module (inert today, deliberately not deleted).

### 8.4.1 Managing users, before managing permissions

The user manager came first because permissions need something to be granted to,
and "run this command against your customer's database" is not an answer.

- **Deactivate, never delete**: records and history carry user ids, so deleting
  leaves records belonging to nobody. Deactivating is reversible and keeps
  attribution.
- **`active` takes two mechanisms**, and neither covers the other: a user
  checker refuses sign-in, and a request listener ends an existing session,
  because the checker is not consulted when a session is restored.
- **Every refusal is about lock-out**: no self-deactivation, no removing your
  own admin, never zero active administrators. There is no support desk behind
  this.

### 8.4.2 Language

- **Language and region are two settings** (XIV-50): which words somebody reads
  and which country's conventions they write by are independent (`de` + `CH`
  makes `de_CH`; an English speaker at a Swiss company wants English words with
  Swiss figures). The chain: person, then installation (§8.6), then the bare
  language.
- **Dates are shown locally and stored as ISO**, two formats with two names;
  reaching for the storage constant to localize a display is the XIV-47 mistake
  (one method formatting and normalizing made every save refuse its own totals).
- Language is per user, on their row, resolved per request and never parked in
  the session (§7.4's hazard). The login page follows the browser.
- **A customer's own words are not translated**: labels are their data. What a
  blueprint ships is code, so its labels are keys resolved **once at install
  time** from the module's own catalogue and then written down; a label looked
  up on every render would overrule the customer's rename each page load. That
  is also why renaming a shape exists. Engine and modules ship their own
  catalogues.
- **A missing translation fails the build**: the fallback serves one paragraph
  in the wrong language on somebody else's screen, the quietest bug available.

### 8.4.3 A second permission axis, for the store (XIV-6)

Every `ModuleAction` is something done to a module's records; neither store verb
fits. **Browse** is about no module; **install** is about a module the customer
does not have, so a per-module grant has nothing to attach to. The authority is
one sentence about the business: *may decide what this installation consists
of*.

- What is second is the **vocabulary, not the machinery**: `StoreAction` is a
  second enum; `PermissionVerb` is the tiny shared interface. One grant table,
  one resolver, no migration (the table was already "a subject, a verb, a
  scope"). The column became a string; `PermissionCoverageTest` fails the build
  if the two vocabularies ever share a word, because a collision would silently
  resolve to whichever enum is tried first.
- **Two voters, one per axis.** A verb from the wrong axis is dropped by the
  manager, not stored.
- **`grant-all` does not hand these out**: its contract is every action on every
  installed module, and `install` decides what the installation consists of,
  permanently. Administrators reach the store through the `ROLE_ADMIN` bypass.
- **`buy` is a third case** (§8.15), not a third axis: `install` is authority
  over the system's shape, `buy` is authority over the company's money, and in a
  small company those are different people. It does not imply `install`.

### 8.4.4 A timezone to read moments in (XIV-83)

Storage needed no change: `timestamptz` normalises to UTC, the engine always
wrote `DATETIMETZ_IMMUTABLE`, the process runs UTC. The rule going forward: **no
new column is a zoneless `timestamp`**. The gap was display, and it bit the
moment anything grouped by day: a UTC midnight *moves* an entry across "today".

- Third setting of §8.4.2's chain, with one extra link: person, installation,
  **whatever the effective region implies**, UTC. Derive only where the country
  has exactly one zone, never the first of several (a head-of-list rule files
  Madrid in North Africa); a wrong zone is worse than an unanswered question
  because nothing on screen reveals it. Both pickers name the zone currently in
  force beside their empty option. tz-database *links* (Calcutta/Kolkata) are
  recognised as one zone; genuinely agreeing zones are not collapsed, because
  "close enough" is only true until one changes its rules.
- **Rendering is one request-scoped setting on Twig** (`twig.date.timezone`),
  not a filter per template. `date_default_timezone_set()` is rejected: it would
  change what gets *written*, quietly. Grouping happens in PHP, so core takes a
  `\DateTimeZone` and applies it to `now`; the engine still does not know what a
  user is.
- A console command has no user and may have no tenant; the chain runs out and
  lands on UTC, and neither is an error.

### 8.5 The first user comes from provisioning

`tenant:provision --admin-email` creates an admin and prints a generated
password once (`random_bytes`, never clock-derived: v1's `date +%s | sha256sum`
reduced the search space to which second the account was created in).

**A generated password must be replaced before the account is usable**: at least
two people know it. `must_change_password` is set whenever the system generates
one, holds every page at `/account` until the owner picks their own (signing out
stays allowed), and applies only to passwords *this system* generated. The
screens share the same path: an administrator never types a colleague's
password. Changing one's own needs the current one, because an unattended
session should not be enough to take an account over.

### 8.6 The instance's own settings (XIV-12)

One profile per customer: what they call themselves, and the currency they work
in. **In the tenant's database, not the control plane**: it is the customer's
data. The registry's `tenant.name` stays the operator's label; the chrome shows
the profile name when it exists. One row, enforced by a constant primary key,
inserted empty by the migration. **The currency is an ISO code, null rather
than guessed**: a guessed currency is wrong quietly and surfaces on the first
priced thing printed. Read and change are separate grants; the page shows
disabled fields rather than refusing.

#### The customer's own mark (XIV-49)

- **In the tenant database, `bytea`, like a document template** (§5.7): one
  small file, unmistakably theirs, covered by per-customer backup and export.
  The general attachments design is still not being started here.
- **PNG and JPEG; SVG is refused.** SVG is an XML document with scripts, and
  the one credible PHP sanitizer is GPL; this project is MIT and turned PHPWord
  down over LGPL. A customer exports a PNG. Size and pixel ceilings are decided
  by reading the header, never the claimed type; the first bounds the row every
  request carries, the second is about not handing the browser a decompression
  bomb.
- **Nothing is re-encoded**: bytes out are bytes in, because re-encoding is how
  a crisp wordmark acquires artefacts; the accepted list must therefore be safe
  to serve untouched.
- **The sign-in page carries it** (tenancy resolves before authentication); a
  system host falls back to Xivi's mark. The serving route is tenancy-scoped
  and not permission-gated, stated rather than incidental: a logo is a public
  mark, and nothing else on that row (SMTP credentials live beside it) comes out
  of the same door; a test compares the body for byte equality.
- **Cache by fingerprint in the path**: a different logo is a different address,
  `immutable`; a stale address answers current bytes with `no-store`.
- `alt` is empty in the top bar (the name is printed beside it) and the company
  name on the sign-in page (nothing else names it there). One upload, not two;
  documents fit rather than stretch it (§5.7), which is why one file still
  suffices.

### 8.7 Who a tenant's mail comes from (XIV-37)

The question is who owns deliverability: SPF, DKIM, and whose reputation is
spent. **Decided: through the customer's own SMTP server when they have named
one; through this instance's transport until then, with their address as
`Reply-To`, never `From`** (our domain is not entitled to claim theirs, and
sending as their domain from our infrastructure fails silently into spam
folders). Works on day one, becomes correct with one form field.

- The name on the mail is `InstanceName` (§8.6); no separate sender-name
  setting to disagree with it.
- `MAILER_SENDER` may be empty; the fallback is `no-reply@` at the tenant's own
  primary domain, which is literally true.
- **The SMTP credential is encrypted like tenant database passwords**
  (`TenantSecretCipher`, values naming their key, rotation resumable), and it
  lives in the *customer's own database*, so `tenant:rotate-secrets` walks
  tenant databases as well as the registry, tenant first (the control-plane row
  is the key to the tenant database).
- **Sending is synchronous**: classic mode has no consumer, and a queue nothing
  drains means the mail never goes. Revisit only when a process exists for a
  bigger reason.
- **A failed send is never swallowed** (`MailSendFailed` is thrown on): an email
  is outbound and irreversible, and "nothing happened" must not look like "it
  went out".
- **Dev and test cannot build a deliverable transport at all**: a guard ahead of
  every transport factory refuses outside prod, for `MAILER_DSN` and for tenant
  credentials alike. One place turns a DSN into a transport, so one factory in
  front of it is a guarantee, not a default. The catcher (§9.2) is visibility
  only.

### 8.8 An invitation instead of a password read off a screen (XIV-1)

The invitation path **generates no password at all**: the hash stays empty,
which nothing can authenticate against from either direction. **The link is
Symfony's signed login link**, not a token table: a token table stores something
replayable, a signature stores nothing, so a database dump carries no usable
invitation. What is ours on top:

- A login link only opens the door; `must_change_password` holds the invitee at
  `/account`, and the signed link stands in for the current-password proof, a
  path refused outright for accounts that already have one.
- **`invitation_seed`** makes a stateless link revocable: one signature input,
  rewritten on acceptance and on reissue, so superseding and consuming are one
  `UPDATE`. Symfony's `max_uses` was rejected because it enforces via a cache
  pool, and an eviction would quietly restore a consumed invitation.
- Inviting twice retires the first link and restarts the 24 hours; there is
  never more than one live invitation per person ("I sent a new one" must fix a
  leak).
- The seed is spent **after** the user checker (on `LoginSuccessEvent`), so a
  deactivated person's click is refused without consuming their link.
- Not offered for an account with a password (it would be a quiet password
  reset); a generated password stays available for an account awaiting an
  invitation, which is the way out when mail is not working.
- The mail goes through `TenantMailer` with no exception carved out: one place
  decides who a message is from, and a fresh tenant hits the instance fallback
  anyway. The content is a system message in the translation catalogue, not an
  editable email template (a tenant who edited the link out would lock somebody
  out).
- Sending off a cron (XIV-98): the router's request context is pointed at the
  tenant's own hostname for the send and restored (a leaked context signs the
  next person's link for the previous person's domain); the locale is the
  language the visitor was reading the signup form in.

### 8.9 An operator is not a tenant user (XIV-57)

An operator's subject matter is the set of tenants, so no tenant database is the
right place to keep them: **an operator is a row in the control-plane database**,
own entity, own provider, own firewall, own host. Rejected: a promoted user of a
designated tenant (makes one customer's database the key to every other's) and
no accounts behind an SSH tunnel (honest for one operator, a migration the day
there are two).

- **The firewall's order is the boundary**: `main` matches every request, so the
  control-plane firewall is declared above it, host-scoped by a request matcher
  rather than `host:` (a hostname in a regex has dots that match anything).
  `ControlPlaneFirewallTest` asks the compiled firewall map;
  `ControlPlaneSignInTest` proves the same email with different passwords on
  each side refuses the tenant's one.
- `CONTROL_PLANE_HOST` is written into `app.system_hosts`, the one mechanism for
  "resolves no tenant"; provisioning refuses to route a tenant onto any system
  host.
- **The hostname is not a boundary** (XIV-93): anybody who can set `Host:`
  reaches the sign-in page. What keeps a customer out is layered: (1) the route
  does not exist on their hostname (`ControlPlaneRequestListener`, 404 in both
  directions); (2) the credential is answerable only by the control plane's
  provider; (3) `access_control` wants `ROLE_OPERATOR`, which no tenant database
  can grant (the suite creates a customer admin with that string and proves they
  are nobody here), and an operator holds no `ROLE_USER`, so tenant screens
  refuse them.
- **A fourth layer, the only one in front** (XIV-124): `CONTROL_PLANE_ALLOWED_IPS`
  refuses a control-plane request from an unlisted address at priority 101,
  before registry, route or firewall. Empty means no restriction (the shipped
  default). It is decided on `Request::getClientIp()`, inheriting §4.3's
  `TRUSTED_PROXIES` decision; **an allow-list built on a raw header would be
  worse than none**, and two tests hold both directions (forged header ignored
  when nothing is trusted; believed when the proxy is). The refusal is an empty
  403 (the path is there, the caller is not welcome; 404 would be the other
  listener's sentence), covering every path on the host. The log line names
  `REMOTE_ADDR` beside the resolved address, which diagnoses the
  forgot-`TRUSTED_PROXIES` case in one glance. Enforced in a listener rather
  than the Caddyfile: travels with the code, testable, inherits the proxy
  decision (an operator may add the Caddy block as well). **Locking yourself out
  is the accepted cost**: the console is the way back, `deploy:check-control-plane`
  reports the list, and a malformed entry is dropped-and-logged rather than
  switching the restriction off or 500ing every customer. No per-operator
  addresses, no allow-list on the tenant application, no `--fix`.
- **Sessions**: separate firewalls have separate session contexts, written out
  explicitly rather than left to defaults; `TenantSessionGuard` discards a
  tenant-stamped session replayed on a host that resolves none.

#### An operator can be revoked, and it deactivates rather than deletes (XIV-92)

Four commands (`control:operator:list|revoke|restore|password`), because
revoking is done in a hurry and `psql` is the wrong tool then. Deactivation wins
over deletion on three arguments (not §8.4.1's attribution one, which does not
apply yet): a wrong revoke is one command to undo where a wrong delete is two
problems; the `active` flag must exist *before* anything references an operator,
or the first audit column forces `ON DELETE SET NULL` (a trail that erases
itself) versus a refusing FK (revocation back to `psql`); and one codebase
should not have two answers to "somebody left". There is deliberately no delete.

- **Enforced twice**, checker plus request listener, §8.4.1's pair for the same
  framework reason: a session is not re-checked by the provider refresh, and a
  revoked operator otherwise keeps browsing every customer's registry until
  their session expires (watched happening; the listener was written against
  it). The duplication across the boundary is deliberate: one class reading both
  a tenant `User` and an `Operator` would put one rule across a boundary this
  section exists to keep clean.
- **Revoking the last active operator is refused, no `--force`**: the web side
  has no sign-up, no invitation, no reset. It counts active operators, not rows.
  Nothing guards self-revocation, because a console has no *self*.
- `create` on an existing address stays an error (`password` exists, so the
  refusal costs nothing); an overloaded create would silently reinstate a
  revoked account.
- Not built, deliberately: no permission model (one kind of operator so far), no
  screen (refusals live in `OperatorManager` so a future page inherits them), no
  record of who revoked whom (a console has no actor), no invitations (an
  operator is created at a console by somebody who already has one; the
  password is asked for, not generated, because there is no account page to
  hold anybody on).

### 8.10 The tenant list, and the boundary it keeps (XIV-58)

The operator landing page draws the registry: every column is a field of the
`tenant` row, and that is the design. `tenant:list` still works; a headless
deployment has no browser.

- **The page opens no tenant connection at all.** Per-tenant figures (§8.11) are
  a design problem precisely because a `LEFT JOIN` is not available, and none of
  the honest designs can be chosen while a join looks available.
  `TenantListTest` proves it with fixtures that have no databases at all: a page
  that connected would be red, not quietly wrong.
- **The entity never reaches the template.** A `Tenant` carries a DSN and an
  encrypted password, and every accidental leak path (a `|json_encode`, a
  `dump()`, a serializer) reads as harmless while being made. A readonly view
  object that never reads those columns is the single reader; the test also
  scans headers and body for the DSN's *parts*.
- **Status is designed around**: ordered by how much a row wants explaining,
  with an opening line naming customers not being served, drawn only when the
  count is nonzero (a permanent "0 customers" banner is furniture the eye learns
  to skip). Rejected: a staleness threshold (a tenant provisioning for 23 hours
  is exactly as broken as one at 25, and a line teaches the reader everything
  under it is fine) and a separate page or filter (the job is that nobody has to
  go looking).
- A tenant with no hostname is shown, not skipped (`leftJoin`): a run that died
  between row and domain is exactly the row the page is for. No lifecycle
  buttons: the commands have refusals a button would have to reproduce.

### 8.11 What a tenant actually uses (XIV-59)

Three figures per customer: users, last sign-in, records. None of it is in the
control plane, and the fan-out is the problem: a page opening fifty tenant
connections would be the first thing that deliberately touches many tenants in
one request, turning §7.4's structural guarantee into a case-by-case argument.

**Decided: collect periodically (`tenant:usage:collect`, cron, no queue), write
to the control plane, let the page read that.** One tenant at a time through
`TenantSwitcher::runFor()`, each connection closed before the next opens (or the
collector blocks an operator's `DROP DATABASE`, XIV-94); a failing tenant is
recorded and the run carries on but exits non-zero. Counting is shared with
`tenant:deprovision`, not copied.

- **Own table (`tenant_usage`), not columns on `tenant`**: a row is a
  *collection*, `collected_at` is what the others are relative to, and a
  customer nobody collected has no row, which nullable columns could not say.
  The association points one way (Doctrine cannot lazily load the inverse side
  of a nullable one-to-one). `purchase_intent` (§8.15) is its sibling on the
  same pattern.
- **A stale figure presented as current is worse than none**: three states (not
  collected, could not be read plus attempt time, figures plus timestamp); zero
  and "could not count" must not look alike. A failed collection drops the
  previous figures (numbers as old as the last success under a timestamp naming
  the last attempt mislead the reader), and the stored failure is the
  exception's class, never the driver's message, which would smuggle DSN parts
  into a rendered table.
- **Counts, not contents.** The counter returns integers; user figures come back
  as one aggregate row. `MAX(last_login_at)` says somebody was here Tuesday, not
  who. Anything past that line (a name, a record title) needs a justification
  this page does not have; a platform that can read customer data whenever it
  likes has made isolation a claim about intent.
- **Installed modules** (XIV-95): the collector was already reading each
  customer's metadata to know what to count, so the real installed list is
  written beside the figures. The *comparison* with `enabled_modules` happens at
  render time, never stored (half of it is current and half is not), and a
  failed collection drops the list too: drift invented by a stale row is the one
  thing the cell must never report. The list is read from metadata, not derived
  from count keys (the first skipped shape would invent a difference). Drift is
  drawn as information, never as an error: *not recorded* / *not installed*.
  Reconciling the lists is a different feature with a higher bar. Per-module
  counts sit on the module names in a `<details>` (a tooltip reaches a mouse and
  nobody else), with disagreements sorted first so what folds away is agreed.

### 8.12 A public surface that provisions nothing (XIV-64)

Self-service signup is the first thing reachable by somebody who is nobody, and
none of the identity machinery above transfers. **The endpoint records a signup
and does nothing else**: one `INSERT`, one email, no elevated credential in
reach (`SignupEndpointTest` walks the constructor graph for `TenantProvisioner`
and `TENANT_ADMIN_DSN`). Acting on it is XIV-98. Until XIV-96 separates the
deployments this is a code boundary, not a privilege boundary, and saying so is
part of the claim.

- **Confirmation is the gate**: an address typed proves nothing. §8.8's login
  link is structurally unusable (no tenant, no user row), so the token is the
  control plane's own **stored digest**: full entropy in the link, SHA-256 in
  the row, 24 hours. Resubmitting unconfirmed is a resend (row rewritten, old
  link dead in the same write); resubmitting confirmed is refused (one confirmed
  address holds one unprovisioned signup). **Following the link twice changes
  nothing**: mail scanners fetch every URL before the recipient sees it, so a
  single-use token burns on the scanner; idempotence is the design.
- The confirmation mail comes from the instance identity (there is no tenant to
  ask); empty `MAILER_SENDER` falls back to `no-reply@` at `SIGNUP_HOST`. It is
  written in the visitor's language, forwarded with the submission; an unknown
  language falls back rather than being refused.
- **Two slug rules, on purpose, never to be unified**: a provisioning slug is a
  Postgres identifier (underscores, no hyphens); a self-service slug is a DNS
  label (RFC 1123). `SelfServiceSlugTest` asserts the disagreement from both
  sides. The name is derived from the company name server-side, shown, editable;
  **the derivation takes nothing from the request** (XIV-100: a locale-dependent
  rule let the availability check and the submission disagree about the same
  name). `TRANSLITERATION_LOCALE` is a constant, `de`, for the market this sells
  into.
- **Reserved names are two lists**: the conventional platform list, and the
  first label of every system host, which is a boundary (that signup would be
  routed onto the control plane's own name).
- **Squatting vs volume are different problems**: a name is held only by a
  confirmed address, one unprovisioned signup per address (the accepted race:
  two people told `acme` is free, second click loses). Volume gets
  `symfony/rate-limiter`, three sliding windows (per target email, per client
  address, larger for availability checks); the secret is checked *before* the
  limiter (or anybody could exhaust a victim's bucket), and there is no global
  cap (one busy afternoon must not be an outage; a compromised caller is
  answered by rotating the secret).
- **The contract is a public API**: versioned path (`/api/signup/v1/`, additive
  within a version), stable error vocabulary with one status table, shared
  secret compared in constant time, **refusing everybody when unset** (failing
  closed is noticed in minutes; an open endpoint is not noticed at all). Error
  messages are fixed English sentences for logs; the words a visitor reads are
  §8.13's. `slug_taken` is one word for three situations, deliberately: whatever
  the endpoint distinguishes, a caller can enumerate. The honest limit: "not
  available" is still one bit, guarded by the secret and the limiter, not the
  vocabulary. Server-side post recommended; there is deliberately no CORS
  configuration anywhere in the feature.
- **Served on its own hostname** (`SIGNUP_HOST`), never under `/control`: a
  hostname configured into third parties' sites ends up in their repositories,
  and the control-plane host should be unguessable. Its firewall is
  `security: false` (a decision: without a block it would sit inside `main`,
  whose provider reads customers' databases), declared below the control-plane
  firewall so equal hostnames fail toward the password prompt.
- **Off means the route does not exist**, not a 404: a route loader returns the
  table, stamps host and `https`, one variable does both jobs (empty is off).
  Environment placeholders are forbidden in routing config, which is the same
  constraint that made §8.9 a listener. The defect §8.13 found: the framework's
  attribute autoloading registered a second, host-less copy of every signup
  route; a compiler pass removes them, and **the assertion is made against the
  compiled router, not the loader**, because a loader can only be asked about
  what it returns.
- `SignupStatus` has two cases, not three: a `provisioned` state would be a
  second copy of a fact `tenant.slug` holds, free to disagree silently. One
  secret, one caller; a table of named keys is the moment there are two.

### 8.13 A landing page, and the scope is the decision (XIV-65)

One page, one form, on the signup host: type a company name, watch the derived
address appear, edit it, submit. **A landing page, not a marketing site**
(different authors, cadence, risk; a marketing site is its own site posting to
the published contract, which is what the contract is public for).

- **Three states, two switches, one `and`**: `SIGNUP_HOST` (intake, and where)
  times `SIGNUP_PAGE` (draw the form). The fourth state, a form with no intake,
  is *not expressible*, which matters because it is the one that fails worst.
- **The page shares the endpoint's hostname**, decided by the confirmation
  link: a visitor who typed at one name and confirms at another has been handed
  the shape of a phishing mail.
- **It goes through the front door**, posting to the contract with the secret,
  as the reference implementation of the recommended integration; the request is
  a real `Request` handed to the kernel as a sub-request (a real socket would
  deadlock classic-mode workers on the busiest day and needs the container to
  resolve its own public name). What it proves is the contract; DNS and TLS are
  stated as not proven.
- **The availability check is an oracle, said out loud**: one bit, bounded per
  visitor by the limiter, three situations behind one word. A deployment
  unwilling to pay that switches the page off and keeps the endpoint. The
  visitor's address is forwarded so the bucket is per visitor. **No CSRF
  token**: nothing is authenticated here, a forged post achieves what posting
  from your own server achieves, and the fix would be a session for every
  anonymous visitor on the one host that has none.
- **Not a Live Component, structurally**: component actions answer on a
  bundle-registered route on every host, so a `SignupForm` would keep answering
  after the page was switched off. A plain controller whose routes the loader
  owns, plus sixty lines of Stimulus, and deliberately no transliteration in the
  browser (a copy of the rule is XIV-100 one layer out).
- **Content-only changes bypass the changelog gate mechanically**: the page's
  copy lives in `translations/landing.*`, and `bin/ci` exempts a branch whose
  entire diff is that catalogue plus the template. Narrow on purpose: per
  branch, explicit file list ("anything under translations/" would exempt
  product text).
- **The script is browser-tested** (XIV-105): every server-side assertion passes
  with the script deleted, and this is the one page strangers reach. The
  obstacles were the harness's, answered outside the application (a compose
  network alias with a dot in it, since Chromium answers `*.localhost` from
  loopback and the firewall-matcher test needs dots to substitute; a router
  script for `php -S` standing in for the TLS terminator, lying for that one
  hostname only, because a `Secure` cookie will not store over `http://`).
  Nothing in `src/`, `packages/` or `config/` changed, asserted by a test that
  reaches the routes over HTTP and then checks the compiled router still says
  `https`-only and host-bound. Not covered, said plainly: the debounce,
  newest-answer-wins, and the plain form post.

### 8.14 Turning a confirmed signup into a customer (XIV-98)

The privileged half, legitimately holding `TENANT_ADMIN_DSN`: one console
command (`signup:provision`) on cron, every few minutes rather than nightly,
because a person is waiting. **The third feature to reach command-plus-cron from
the same constraint** (no worker), and it is one decision, not three; it stops
holding the day a consumer exists for its own reasons.

- An empty queue is a success (the ordinary state); nothing is ever given up on
  (no attempt limit, no dead-letter): every failure a retry could fix is fixed
  *elsewhere*, and a run that disarmed itself makes the repair a two-step job
  nobody remembers.
- **`provision()` is not re-runnable; the three steps after it are.** So a
  half-made tenant is cleaned up (`deprovision()`, made re-runnable by XIV-94)
  rather than finished: a tenant in `provisioning` has never served a request,
  holds no session and no credential; it is an empty database with a company's
  name on it. A tenant already serving is finished rather than torn down.
- **Identity is the hostname, not the slug**: a tenant made here holds its
  derived hostname from the first flush, so an operator's own `acme_ag` from
  last year is neither resumed nor torn down; the signup fails at `preflight`
  and repeats forever, which is the right pressure for something only a person
  can settle.
- A failure reaches the tenant list (the row is persisted before the cluster is
  touched, so wreckage lands in `provisioning`, which §8.10 ranks first). The
  signup row records a **stage, not a message** (§8.11's rule), plus an attempt
  counter; still no third `SignupStatus`.
- **The slug translation is hyphen to underscore, and nothing else**: the one
  rule a human performs in their head, and a bijection onto its image, so two
  customers cannot collide (proof, not hope). The intake asks the registry about
  the translated name *and* the hostname it would take, at ask time; names with
  no translation (leading digit, too long) are refused at the intake, except
  that a *derived* suggestion is cut to fit (the system's own mistake to fix)
  while a *typed* one is refused.
- **The hostname**: the signup slug as a label under the signup host's parent
  domain (`signup.xivi.app` puts customers at `acme.xivi.app`); a single-label
  host keeps itself. `SelfServiceTenantHostname` owns it and §8.13's form
  delegates, because two implementations of a promise drift and the discovery is
  a customer reaching nothing.
- **The first administrator has no password anywhere**:
  `createWithoutPassword()` plus §8.8's login link (a generated password nobody
  reads is a live credential for the account's lifetime). Request context and
  locale handling are §8.8's closing rules.
- **The customer is not told when it fails**, and that is the honest gap: every
  signal (exit code, cron mail, counter, banner) is addressed to the operator,
  who can act. A mail after N attempts needs a decision about what it may
  honestly say, and that decision is worth more than the twenty lines.

### 8.15 A price a customer can see, and an ask that installs nothing (XIV-102)

The customer-facing half of §6.5. **There is no payment gateway, and this ticket
exists so there does not have to be one yet.**

- **Rejected: install anyway, marked unpaid** (written down so nobody
  re-proposes it "just for now"): nothing here uninstalls, so the flag is a note
  no code enforces, the first customer who notices gets every module free, and a
  price that can be ignored teaches that prices are decorative. **Rejected:
  refuse and say get in touch**: a dead end plus homework the system could do.
  **Adopted: record a purchase intent an operator fulfils**, §8.12's shape one
  layer down (anyone may ask; the thing happening is a separate, non-public
  act), and the day a gateway lands it slots in where the operator stands.
- **The intent lives in the customer's own database** (`module_purchase_intent`),
  because §4.4's grant leaves a customer's write exactly one home, and that home
  is right anyway (their fact, beside what it is about, structurally unable to
  leak). `tenant:purchase:collect` copies it to the control plane; the operator
  screen opens no tenant connection. The honest cost is one collection interval,
  printed beside every row.
- **Rejected, the tempting shape: the store POSTing to a control-plane
  endpoint.** It would hand the public image a credential that re-obtains over
  the network the privilege the database refuses; §8.12's API exists because its
  caller is a third party, and inventing a network boundary between two images of
  one repository drops the reason while keeping the mechanism. Also rejected:
  widening the grant by one `INSERT`, which costs the sentence "no write
  privilege anywhere", the sentence that makes the guarantee checkable.
- **The price is copied onto the request**, amount and currency, frozen at the
  button press (§6.5's instruction; XIV-67's payment terms and §5.9's totals
  arriving at the same place). The collector carries it untouched and never
  consults `ModuleCatalog`. Asking again rewrites the row (a duplicate-filled
  queue stops being read), refreshes the copied price, and keeps `created_at`,
  because how long this has been outstanding is the number that says how badly
  it went.
- **No status column, on either side**: fulfilment is observed (the customer has
  the module or not, their metadata is the truth), so the operator screen has no
  button; an operator answers by installing (`tenant:module:install`), and the
  next collection sees it.
- **Who asked does not cross**: the tenant row records the person (two-column
  pattern), and neither value leaves the database; the operator knows which
  company wants which module and reaches them the way they already do. Free
  rather than merely principled, because the answer is delivered inside the
  product.
- **`buy` is its own permission** (§8.4.3): authority over the company's money,
  not over the installation's shape. The operator side has no permission at all
  (one kind of operator; a grant before a second kind exists would model a
  guess).
- **The placeholder must not look like a payment page**: no card fields (the
  test counts inputs), no total or VAT row, no spinner or confirmation number,
  no promised timeframe, no thank-you. It says what it costs, that this is a
  request, that nothing is charged, and that a person will reply.
- **A free module says nothing**: no badge, no zero; almost everything is free,
  and a reader taught to skip the price line skips it on the one card that
  matters. `unpriced` and `not_for_sale` never reach the store (§6.5). A module
  priced after installation just keeps working.
- **Money is drawn as stored**: decimal string plus ISO code, deliberately not a
  locale formatter (floats, an absent currency, and the shown value must be the
  stored value verbatim). An unset currency shows a bare number; only operator
  screens name the variable, because only operators can set it. VAT is named and
  moved on from: a gateway feature with a tax adviser in it.
- Not built: withdrawal of a request (the collector already removes vanished
  rows when a screen wants it), notifications in either direction, declining on
  the screen (a declined request is a conversation).

### 8.16 An operator can say something, and it lands where the work is (XIV-120)

Three sentences an operator knows and a customer needs (maintenance windows,
released features, a trial ending) were hand-sent email or nothing. **A notice
appears on the customer's own dashboard as a widget** (§8.3.1); mail is a second
channel with its own §8.7 preconditions, and none of them should stand between
an operator and "we upgrade on Sunday".

- **In the control plane, registry half, read directly**: XIV-102 in the easy
  direction. An operator writes where the schema is owned; a customer only
  reads, which is exactly what the grant permits. No collector, no interval.
- **The namespace is the grant**: readable tables are derived from
  `App\Registry\Entity` mappings, so a `Notice` filed under
  `Xivi\ControlPlane\Entity` would 500 every dashboard. **Recipients are an
  entity, not a `ManyToMany`**: a join table is not a class, has no metadata,
  and is invisible to the grant generator; anything that is a table but not an
  entity is outside `readableTables()` (`doctrine_migration_versions` is the
  only other member, named explicitly). The proof is a role: the test opens a
  connection as the restricted role and reads a notice through it, which is why
  the reader takes its entity manager as a constructor argument rather than
  being a repository that resolves the suite's privileged connection.
- **The author is a copy** (`author_label`): the reader's instance has no access
  to `operator` at all, so a join would be unreadable by the only party needing
  the value; and a renamed or revoked operator must not rewrite published
  authorship.
- **Everybody, or named customers, and the two are not folded**: recipient rows
  cascade away with a deprovisioned customer, so "no rows means everybody"
  would silently turn a three-company notice into an installation-wide one; a
  boolean says which was meant. Addressing is all-or-nothing (a notice naming a
  missing customer is refused entirely; reaching three of four while reporting
  success is the feature's own failure mode). "Every customer with module X" is
  a collector's question and therefore a feature, not an enum case.
- **Audience per notice**: `Everyone` or `Administrators` (a maintenance window
  vs a trial ending), coarse and says so; `ROLE_ADMIN` is the nearest true
  thing. A `@notices` permission was refused: it would let a customer switch off
  the channel the operator is relied on to have.
- **Dismissing is per person, written in the customer's own database** (the
  grant forces it, and per tenant would let whoever opens the dashboard first
  silence everybody's screen). The stored `notice_id` points across databases
  and is dropped when it resolves to nothing; no orphan hunter.
- **Live is between `published_at` and `expires_at`; withdrawing sets the second
  to now** (one concept, not two ways to stop). Withdrawing is not deleting:
  "what did we tell them in March" is a question. The operator screen leads with
  what is live and states who each notice went to, both asserted against
  fixtures shaped to catch a scope-ignoring query.
- **The widget costs a query in `panel()`, a stated departure from the
  cheap-by-contract rule**: "does this apply to you" *is* the database's
  question, and an unconditional card would be permanent furniture that makes
  the one week it speaks the week nobody notices. One indexed read on an open
  connection.
- **Not the answer to XIV-108**: a stranded signup has no tenant, no database,
  no user; the person waits outside the product, and reaching them is mail with
  an honesty decision attached.
- Not built, each deliberate: read receipts (a collector pointed the other way,
  and a dismissal reported as "read" would over-claim on the deciding screen),
  scheduling, severity levels, links or markup (a linkable notice is a phishing
  channel on the one screen nobody distrusts), translation (a notice is
  somebody's sentence, not a key), summaries (a second thing to disagree with
  the first).

### 8.17 A customer can reach whoever runs this installation (XIV-123)

§8.16's return path: an announcement is one-to-many and about the installation;
a ticket is one-to-one and about a problem.

- **A ticket is a customer's write**, so it goes in their own database
  (`support_ticket`) with `tenant:support:collect` bringing it back, XIV-102's
  shape reused, HTTP-to-control-plane rejection inherited word for word.
- **The answer comes back the other way**: status and reply live on the
  collected copy in the control plane, which the customer reads directly, so an
  operator who answers at 14:03 is on the customer's screen at 14:03.
  **`support_request` is therefore an `App\Registry\Entity`** (the namespace is
  the grant, meeting its second feature), unlike `purchase_intent`, which only
  an operator reads.
- **The leg that waits is the leg where nobody is watching**: customer-to-
  operator costs one collection interval (five minutes in `ScheduledJobs`,
  because somebody is waiting); operator-to-customer costs nothing. An
  uncollected ticket reads *not received yet* rather than borrowing a status;
  the operator screen prints collection time per row and its empty state names
  the command (an empty queue and a missing cron entry look identical forever).
  No interval is printed to customers: that is a guess at somebody else's
  crontab.
- **One reply per ticket, rewritten, `replied_at` moving with it**: a two-sided
  thread crosses the collector in one direction only and is a conversation
  product, a much bigger thing. A customer answers back by raising another
  ticket. Replying does not move the status (a hidden state change beside a
  visible state control is how they stop agreeing).
- **`SupportStatus` is `Open`, `InProgress`, `Closed`**, any state after any
  other (no lifecycle for a process nobody described). A status is storable here
  where §8.15 refused one, because whether somebody picked a question up is not
  observable from anywhere. No `Answered` (the reply is visible), no priority,
  category or SLA (promises about an arrangement this installation knows
  nothing about).
- **Everybody signed in may raise one**: raising commits nothing (the difference
  from `buy`), and the person who met the problem is the one who can describe
  it. A per-installation off-switch was refused: a channel between customer and
  operator that either end can quietly close. The firewall is the whole access
  control, proven on the POST as well as the GET. Tickets are the company's,
  not the reader's (a colleague should find Tuesday's answer), named, and the
  page says so.
- **Who raised it does not cross** (two-column pattern): the answer is delivered
  inside the product, so an operator answers the company without learning which
  member of staff typed the question.
- **Matched on `(tenant, reference)`, a random reference, never the id**: ids
  restart at 1 when a database is rebuilt (`tenant:reset` is supported), and the
  tenant is half the key so no customer can name another's row.
- **The collector removes nothing and writes only the customer's three
  columns**: the operator's half exists only in the control plane, so deletion
  destroys answers, and an upsert would discard a reply typed during a
  collection run. Nothing in this system deletes a support ticket.
- The FAQ is documentation and lives on the docs site, not in the application;
  no link from the support page either (the docs address is a deployment fact,
  and `README.md` names it for the people who can act on it). No mail in either
  direction; no attachments or screenshots (a file upload crossing the tenant
  boundary is a feature, not a column); no read receipts; no search or paging
  until tens stop being tens; nothing withdraws a ticket (a person says so, in
  the ticket).

### 8.18 Support enters a tenant only through a door the customer opens (2026-08-20)

Decided ahead of being built, because this is the kind of capability that gets
built the wrong way the day a support case is urgent enough to need it.

An operator will eventually have to see what a customer sees: subscription tiers
that differ in support level (§9.4) mean support cases, §8.17 gives those cases a
channel, and the fastest diagnosis of "my form is broken" is the form. §8.9 keeps
operators structurally outside tenants, and a database per tenant makes the back
door, `psql` into the customer's own database, permanently available and
permanently tempting. So the rule is written down before the temptation has a
deadline:

**Impersonation is requested by the operator and granted by the tenant's own
administrator, per incident.** The shape is remote-control software: support asks
to connect, the customer accepts, and only then is there a session. The grant is
an act in the tenant's own UI by somebody who is already an administrator there;
it is bounded rather than standing (a grant that outlives its incident is a
standing credential wearing a consent's clothes); and the request, the grant or
refusal, and everything done during the session land in the same history
customers already have for their records. What §8.10 and §8.11 established for
the operator surface, counts rather than contents, stays true up to the moment
the customer says otherwise, and becomes true again when the session ends.

**The back door stays what it is: an emergency, not a workflow.** Nothing makes
`psql` impossible for whoever runs the machine, and pretending otherwise would be
a guarantee this brief refuses to fake elsewhere (§8.9 on hostnames). What the
front door buys is that the honest path is also the convenient one, is visible to
the customer, and leaves a trail the back door never will, and a contract clause
can then say the honest path is the only one used, which is a sentence worth
being able to sign.

---

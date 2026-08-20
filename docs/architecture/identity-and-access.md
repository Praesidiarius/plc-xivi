## 8. Identity and access

### 8.1 Users live in the tenant database

Not in the control plane: pooling would put every customer's credentials in one
shared database while claiming physical isolation, and break single-`pg_dump`
export. One person at two customers is two rows. The security provider is bound
to the tenant entity manager: "who is this email" is answered only by the
database being served.

### 8.2 Identifiers are only unique within a tenant

A session minted for one customer and replayed against another with the same
email would authenticate as the wrong user (`admin@…` collides constantly).
Sessions carry the tenant they were created for; a mismatch invalidates.
Anything outliving a request that names a user has the same obligation.

### 8.3 The UI is server-rendered, in this repository

Form login, session cookie, Twig; not a SPA (v1's separate front-end build
meant a per-customer build artefact, the opposite of runtime module
availability).

- **It assumes JavaScript** (XIV-28): "server-rendered" survives, "works with
  JS off" was given up when collection rows needed to change shape as somebody
  picks.
- **Symfony UX Live Components, after htmx** (XIV-33): htmx lost on morphing
  under a moving caret, no state model, and one-vendor. Accepted costs: the
  write path is a function of the UI library, and **a refused save answers
  200, not 422** (only the body says no).
- **What a submitted record form means is not the controller's** (XIV-30):
  every renderer of the form asks the same service; none gets its own idea of a
  valid record.
- **One tiny browser-test layer, only over what only a browser sees** (XIV-31):
  a flaky end-to-end layer gets skipped, and a skipped net is worse than none.
  The browser is another process: the rollback is invisible to it, and it needs
  a hostname resolving from both containers. When the browser layer cannot
  reach something, fix the harness, not the application (XIV-105).
- **Components stay generic over module, record and shape**; an `OrderForm`
  would be the module-specific code §1 exists to avoid.

### 8.3.1 The dashboard is its widgets (XIV-81)

**A widget is a service that decides whether it has anything to say, then names
a template and hands it data.** Tagged iterator; nothing keeps a list. Null
means "does not apply"; a throwing widget takes the page down rather than being
silently omitted (a dashboard that drops panels cannot be trusted).

- **A widget's controls are its own state, not the URL's** (XIV-84): the
  dashboard decides whether a card exists, the card decides what is in it.
- **The seam lives in core** (XIV-66) so module packages can implement it;
  implementations stay where their dependencies are. A panel carries a
  translation domain, and `RecordPageUrl` keeps route names out of module
  templates.
- **A person arranges their own page** on §8.4.2's chain (person, installation,
  everything). Null is "never chose", `[]` is "deliberately cleared", and they
  are not folded. A saved layout is data referring to code: unknown keys are
  dropped, like stale references. A default is not a permission.
- **Panels defer, and `panel()` is cheap by contract**: data is a promise
  resolved only for a drawn panel. The mount is the dashboard's one generic
  component, so modules ship no front-end dependency.
- **A chart is for a trend, and nothing else** (XIV-121): `symfony/ux-chartjs`
  is in for the one admitted case, a numeric field's value over time, read off
  the values so a customer's own field gets a trend with no deploy. Dashboards
  of charts and cross-record aggregation stay refused. Permissions are asked
  again at the component's endpoint and it draws nothing rather than refusing
  (a card has no useful 404). The chart controller is the one lazy controller;
  a browser test counts non-transparent canvas pixels, because every failure
  here is a blank box under a green suite.

### 8.4 Authorization: grants, resolved per person

- **A closed enum of actions** (view, list, add, edit, delete, export, import)
  crossed with the customer's installed modules **at runtime**: nothing seeded,
  nothing migrated, nothing to drift.
- **Areas join the modules** (XIV-12): keys starting `@` (a module key cannot),
  same verbs, no scope.
- **Only grants are stored** (holder group-or-person in one table, one
  resolver, a union and a maximum). **Nothing can deny**, so resolution is
  order-independent; "everything except one thing" is a smaller grant.
- **Scope is all records or only your own**, for actions naming an existing
  record; add and import cannot be scoped and the enum says so.
- **`ROLE_ADMIN` is a bypass, not a group** (a removable group reintroduces the
  lock-out §8.4.1 refuses). It still gates the metadata editor, user management
  and the permission screens themselves.
- **Three enforcement seams**: `#[IsGranted]` on routes, a voter for one
  record, **a WHERE clause for lists** (a voter runs after the page is fetched
  and the total counted; the export carries the predicate too). The seams must
  agree: a refused record answers **404** (guessing ids reveals nothing); a
  viewable-but-unchangeable one answers 403.
- **Default deny**; the upgrade path is `tenant:permissions:grant-all`, a
  deliberate act and the way back in.
- **The build fails when a route names no permission** (the surface is defined
  by URL, so new controllers are covered the day they exist).
- **Pickers and the search endpoint are scoped** through
  `RecordAccessProvider`; a scoped reader sees a picker that omits the answer,
  the safer half, widened by a grant. The search endpoint carries `view` plus
  the same predicate (a search box enumerates harder than a dropdown leaks).
- **Not somewhere a customer expression can go** (XIV-88: it would restrict the
  page and not the total). Open: what a grant means for an uninstalled module.

### 8.4.1 Managing users, before managing permissions

The user manager came first: permissions need something to be granted to.
**Deactivate, never delete** (records and history carry user ids). `active`
takes **two mechanisms**: a user checker for sign-in, a request listener for
existing sessions (the checker is not consulted on session restore). **Every
refusal is about lock-out**: no self-deactivation, no removing your own admin,
never zero active administrators; there is no support desk behind this.

### 8.4.2 Language

- **Language and region are two settings** (XIV-50): `de` + `CH` composes
  `de_CH`; an English speaker at a Swiss company wants English words with Swiss
  figures. Chain: person, installation, bare language.
- **Dates show locally and store as ISO**; reaching for the storage constant to
  localize a display is the XIV-47 mistake.
- Language is per user, resolved per request, never parked in the session; the
  login page follows the browser.
- **A customer's own words are not translated**; a blueprint's labels are keys
  resolved **once at install** and written down (a per-render lookup would
  overrule renames), which is why renaming a shape exists.
- **A missing translation fails the build** (the fallback serves one paragraph
  in the wrong language, the quietest bug available).

### 8.4.3 A second permission axis, for the store (XIV-6)

Neither store verb fits `ModuleAction` (every case of which acts on a module's
records): browse is about no module; install is about one the customer does not
have, so a per-module grant has nothing to attach to. The authority is *may
decide what this installation consists of*.

- **What is second is the vocabulary, not the machinery**: `StoreAction` beside
  `ModuleAction`, `PermissionVerb` the tiny shared interface, one grant table,
  no migration. `PermissionCoverageTest` fails the build if the vocabularies
  ever share a word (a collision would silently resolve to whichever enum is
  tried first).
- Two voters, one per axis; a wrong-axis verb is dropped, not stored.
- **`grant-all` does not hand these out** (its contract is installed modules;
  `install` is permanent authority). Administrators use the bypass.
- **`buy` is a third case, not a third axis** (§8.15): `install` is authority
  over the system's shape, `buy` over the company's money, different people in
  a small company. It does not imply `install`.

### 8.4.4 A timezone to read moments in (XIV-83)

Storage was always right (`timestamptz`, UTC process): the rule is **no new
column is a zoneless `timestamp`**. The gap was display, biting where anything
groups by day (a UTC midnight moves an entry across "today").

- Chain with one extra link: person, installation, **what the effective region
  implies**, UTC. Derive only where the country has exactly one zone, never
  the first of several (a wrong zone is invisible on screen); both pickers name
  the zone in force beside their empty option. tz *links* (Calcutta/Kolkata)
  are one zone; genuinely agreeing zones are not collapsed.
- **Rendering is one request-scoped Twig setting**, not a filter per template;
  `date_default_timezone_set()` is rejected (it changes what is written).
  Grouping happens in PHP, so core takes a `\DateTimeZone`.
- A console command has no user and maybe no tenant; the chain lands on UTC and
  neither is an error.

### 8.5 The first user comes from provisioning

`tenant:provision --admin-email` prints a generated password once
(`random_bytes`, never clock-derived). **A generated password must be replaced
before the account is usable** (`must_change_password` holds every page at
`/account`; signing out stays allowed; applies only to passwords the system
generated). An administrator never types a colleague's password; changing your
own requires the current one (an unattended session must not take an account
over).

### 8.6 The instance's own settings (XIV-12)

One profile per customer (name, working currency), **in the tenant's database**
(their data; the registry's `tenant.name` stays the operator's label). One row
by constant primary key. **The currency is an ISO code, null rather than
guessed** (a guessed currency surfaces on the first priced document). Read and
change are separate grants; disabled fields rather than refusal.

**The customer's own mark** (XIV-49): in the tenant database, `bytea`, like a
document template (the attachments question stays unanswered). **PNG and JPEG;
SVG is refused**: an SVG is an XML document with scripts, the one credible PHP
sanitizer is GPL against this project's MIT, and a customer exports a PNG.
Ceilings are decided by reading the header, never the claimed type. **Nothing
is re-encoded** (bytes out are bytes in). The sign-in page carries it (tenancy
resolves before authentication; system hosts fall back to Xivi's mark); the
serving route is tenancy-scoped and deliberately not permission-gated (a logo
is a public mark), and nothing else on that row leaves through it (SMTP
credentials sit beside it; a test compares the body byte-for-byte). Cache by
fingerprint in the path (`immutable`; a stale address answers current bytes
with `no-store`). `alt` is empty beside the printed name, the company name on
the sign-in page. One upload; documents fit rather than stretch it (§5.7).

### 8.7 Who a tenant's mail comes from (XIV-37)

The question is who owns deliverability. **Through the customer's own SMTP when
named; through the instance until then, with their address as `Reply-To`, never
`From`** (our domain may not claim theirs; sending as their domain from our
infrastructure fails silently into spam). The name on the mail is
`InstanceName` (no second field to disagree). Empty `MAILER_SENDER` falls back
to `no-reply@` at the tenant's own primary domain.

- **The SMTP credential is encrypted like tenant DB passwords** and lives in
  the customer's own database, so `tenant:rotate-secrets` walks tenant
  databases too, tenant first (the control-plane row is the key to it).
- **Sending is synchronous** (no worker, and a queue nothing drains means the
  mail never goes). **A failed send is never swallowed** (`MailSendFailed`
  thrown on: "nothing happened" must not look like "it went out").
- **Dev and test cannot build a deliverable transport at all**: a guard ahead
  of every transport factory refuses outside prod, for `MAILER_DSN` and tenant
  credentials alike; one factory in front of the one place DSNs become
  transports is a guarantee, not a default. The catcher (§9.2) is visibility
  only.

### 8.8 An invitation instead of a password read off a screen (XIV-1)

The invitation path **generates no password at all** (an empty hash
authenticates against nothing). **The link is Symfony's signed login link, not
a token table**: a token table stores something replayable, a signature stores
nothing.

- The link only opens the door; `must_change_password` holds the invitee at
  `/account`, the signed link standing in for the current-password proof (a
  path refused for accounts that have one).
- **`invitation_seed`** makes the stateless link revocable: one signature
  input, rewritten on acceptance and reissue. Symfony's `max_uses` was rejected
  (cache-enforced; an eviction silently restores a consumed invitation).
- Inviting twice retires the first link and restarts the 24 hours; one live
  invitation per person.
- The seed is spent **after** the user checker, so a deactivated person's click
  is refused without consuming the link.
- Not offered for an account with a password (a quiet password reset
  otherwise); a generated password stays available when mail is broken.
- Sent through `TenantMailer` with no exception (one place decides who a
  message is from); the content is a system message in the catalogue, not an
  editable template. Off a cron (XIV-98): the router context is pointed at the
  tenant's hostname for the send and restored (a leaked context signs the next
  person's link for the previous domain); the locale is the signup form's.

### 8.9 An operator is not a tenant user (XIV-57)

An operator's subject is the set of tenants, so **an operator is a control-
plane row**: own entity, provider, firewall, host. Rejected: a promoted user of
a designated tenant (one customer's database becomes the key to every other's)
and no-accounts-behind-a-tunnel (a migration at the second operator).

- **The firewall's order is the boundary** (`main` matches every request, so
  the control-plane firewall is declared above it), host-scoped by a request
  matcher, never `host:` (a regex hostname's dots match anything). Tests read
  the compiled firewall map and prove the same email with two passwords
  refuses the tenant's.
- `CONTROL_PLANE_HOST` feeds `app.system_hosts` (the one "resolves no tenant"
  mechanism); provisioning refuses to route a tenant onto a system host.
- **The hostname is not a boundary** (XIV-93): anybody can set `Host:`. What
  isolates: the route does not exist elsewhere (404 both directions), the
  credential is answered only by the control plane's provider, and
  `ROLE_OPERATOR` is a role no tenant database can grant (tested with a tenant
  admin carrying the string); an operator holds no `ROLE_USER`, so tenant
  screens refuse them.
- **A fourth layer, the only one in front** (XIV-124):
  `CONTROL_PLANE_ALLOWED_IPS` refuses unlisted addresses before anything else
  runs. Empty means no restriction (the shipped default). Decided on
  `Request::getClientIp()`, inheriting `TRUSTED_PROXIES` (**an allow-list built
  on a raw header is worse than none**; both directions tested). The refusal is
  an empty 403 covering every path on the host; the log line names
  `REMOTE_ADDR` beside the resolved address (diagnoses forgotten proxies at a
  glance). A listener, not the Caddyfile (travels with the code, testable,
  inherits the proxy decision). **Locking yourself out is the accepted cost**;
  the console is the way back; a malformed entry is dropped-and-logged rather
  than 500ing customers. No per-operator addresses, no allow-list on the tenant
  app, no `--fix`.
- **Sessions**: separate firewalls get explicitly separate session contexts;
  `TenantSessionGuard` discards a tenant session replayed on a tenantless host.

**Revocation deactivates, never deletes** (XIV-92): four commands
(`list`/`revoke`/`restore`/`password`), because revoking happens in a hurry. A
wrong revoke is one command to undo; the `active` flag must exist before
anything references an operator (or the first audit column forces a
self-erasing `SET NULL` or a refusing FK); one codebase, one answer to
"somebody left". There is deliberately no delete.

- Enforced **twice** (checker plus request listener; a revoked operator
  otherwise keeps browsing until the session expires, watched happening). The
  duplication with §8.4.1 is deliberate: one class serving both sides would
  bridge the boundary.
- **Revoking the last active operator is refused, no `--force`** (the web side
  has no signup, invitation or reset). Counts active operators, not rows.
  Nothing guards self-revocation (a console has no self).
- `create` on an existing address stays an error (`password` exists; an
  overloaded create silently reinstates a revoked account).
- Not built: a permission model (one kind of operator), a screen (refusals live
  in `OperatorManager` so a page inherits them), a who-revoked-whom record (no
  actor at a console), invitations (the password is asked for, not generated:
  no account page to hold anybody on).

### 8.10 The tenant list, and the boundary it keeps (XIV-58)

The operator landing page draws the registry; every column is a `tenant` field.
`tenant:list` still works (a headless deployment has no browser).

- **The page opens no tenant connection at all**, proved by fixtures with no
  databases (a connecting page goes red, not quietly wrong). That property is
  why per-tenant figures are §8.11's design problem.
- **The entity never reaches the template**: a `Tenant` carries a DSN and an
  encrypted password, and every accidental leak path reads as harmless while
  being made; a readonly view object that never reads those columns is the
  single reader, and the test scans headers and body for the DSN's parts.
- **Status is designed around**: ordered by how much a row wants explaining,
  with an opening line naming customers not being served, drawn only when
  nonzero (a permanent zero-banner is furniture the eye learns to skip).
  Rejected: a staleness threshold (23 hours stuck is exactly as broken as 25)
  and a separate page or filter (the job is that nobody has to go looking).
- A tenant with no hostname is shown, not skipped (a run that died between row
  and domain is the row the page is for). No lifecycle buttons (the commands
  have refusals a button would have to reproduce).

### 8.11 What a tenant actually uses (XIV-59)

Users, last sign-in, record counts: none of it is in the control plane, and a
page opening fifty tenant connections would be the first thing to deliberately
touch many tenants in one request, degrading §7.4's structural guarantee to a
case-by-case argument.

**Decided: collect periodically (`tenant:usage:collect`, cron, no queue) into
the control plane; the page reads that.** One tenant at a time through
`TenantSwitcher::runFor()`, each connection closed before the next (or the
collector blocks a `DROP DATABASE`); one failure is recorded and the run
carries on but exits non-zero. Counting is shared with `tenant:deprovision`.

- **Own table (`tenant_usage`)**: a row is a *collection*, `collected_at` is
  what the others are relative to, and an uncollected customer has no row.
  One-way association (Doctrine cannot lazily load the inverse of a nullable
  one-to-one). `purchase_intent` (§8.15) is its sibling.
- **A stale figure presented as current is worse than none**: three states
  (not collected; could not be read, tried at …; figures with timestamp), and
  zero must not look like "could not count". A failed collection **drops** the
  previous figures; the stored failure is the exception's class, never the
  driver's message (which names host, port and role).
- **Counts, not contents**: the counter returns integers; `MAX(last_login_at)`
  says somebody was here, not who. Anything past that line needs a
  justification this page does not have.
- **Installed modules** (XIV-95): the collector was already reading each
  customer's metadata, so the real installed list is stored beside the figures;
  the comparison with `enabled_modules` happens at render time and is never
  stored (half is current, half is not), a failed collection drops the list
  (stale-row drift is the one thing the cell must never report), and drift is
  drawn as information, never error (*not recorded* / *not installed*).
  Reconciling the lists is a different feature with a higher bar.

### 8.12 A public surface that provisions nothing (XIV-64)

Signup is the first thing reachable by somebody who is nobody, and none of the
identity machinery transfers. **The endpoint records a signup and does nothing
else**: one `INSERT`, one email, no provisioner and no `TENANT_ADMIN_DSN`
reachable from its constructor graph (tested). Acting on it is XIV-98. Until
the two-image split this is a code boundary, not a privilege boundary, and
saying so is part of the claim.

- **Confirmation is the gate**; the token is the control plane's own **stored
  digest** (full entropy in the link, SHA-256 in the row, 24 h): §8.8's login
  link needs a user in a tenant database, and there is neither. Resubmitting
  unconfirmed is a resend (row rewritten, old link dead in the same write);
  resubmitting confirmed is refused (one confirmed address, one unprovisioned
  signup). **Following the link twice changes nothing**: mail scanners fetch
  every URL first, so a single-use token burns on the scanner.
- The confirmation mail is instance identity (there is no tenant to ask);
  written in the visitor's language, forwarded with the submission.
- **Two slug rules, never to be unified**: a provisioning slug is a Postgres
  identifier (underscores), a self-service slug a DNS label; a test asserts the
  disagreement from both sides. The name is derived server-side, shown,
  editable; **the derivation reads nothing from the request** (XIV-100: a
  locale-dependent rule let the check and the submission disagree about one
  name); the transliteration locale is a constant, `de`.
- **Reserved names**: the platform list, plus the first label of every system
  host (that signup would be routed onto the control plane's own name).
- **Squatting vs volume**: a name is held only by a confirmed address, one
  unprovisioned signup per address (accepted race: the second click loses).
  Volume gets three sliding-window limiters (per target email, per client, a
  looser one for checks); **the secret is checked before the limiter** (or
  anybody could exhaust a victim's bucket); **no global cap** (one busy
  afternoon must not be an outage; a compromised caller means rotating the
  secret).
- **The contract is a public API**: versioned path (`/api/signup/v1/`, additive
  within a version), one status table, fixed English error sentences (the
  visitor's words are §8.13's; a descriptive refusal once leaked which of three
  reasons made a name unavailable), and a shared secret compared in constant
  time that **refuses everybody when unset** (failing closed is noticed in
  minutes; an open endpoint is not noticed at all). `slug_taken` is one word
  for three situations, deliberately: whatever is distinguished can be
  enumerated. Server-side posting is the recommended integration; there is
  deliberately no CORS configuration anywhere.
- **Served on its own hostname** (`SIGNUP_HOST`), never near the control plane
  (a hostname configured into third-party sites ends up in their
  repositories). Its firewall is `security: false` (without a block it would
  sit inside `main`, whose provider reads customer databases), declared below
  the control-plane firewall.
- **Off means the route does not exist**, via a route loader that stamps host
  and `https` (routing config forbids env placeholders); one variable does both
  jobs. The framework's attribute autoloading once registered a second
  host-less copy of every signup route; a compiler pass removes them, and **the
  assertion is against the compiled router, not the loader**.
- Two `SignupStatus` cases, not three (a `provisioned` state would duplicate
  `tenant.slug`, free to disagree silently). One secret, one caller.

### 8.13 A landing page, and the scope is the decision (XIV-65)

One page, one form, on the signup host. **A landing page, not a marketing
site** (different authors, cadence, risk; a real site is its own site posting
to the published contract).

- **Three states, two switches, one `and`** (`SIGNUP_HOST` × `SIGNUP_PAGE`);
  the fourth state, a form with no intake, is *not expressible*, which is the
  combination that would fail worst.
- **The page shares the endpoint's hostname**, decided by the confirmation
  link: typing at one name and confirming at another is the shape of a
  phishing mail.
- **It goes through the front door** (posts to the contract with the secret, as
  the reference integration), as a kernel sub-request (a real socket deadlocks
  classic-mode workers and needs the container to resolve its own public
  name). What that proves is the contract; DNS and TLS are stated as not
  proven.
- **The availability check is an oracle, said out loud**: one bit, bounded per
  visitor, three situations behind one word; a deployment unwilling switches
  the page off and keeps the endpoint. **No CSRF token**: nothing here is
  authenticated, and the fix would be a session for every anonymous visitor.
- **Not a Live Component, structurally**: component actions answer on a
  bundle-registered route on every host, so a `SignupForm` would keep answering
  after the page was switched off. A plain controller plus sixty lines of
  Stimulus, with no transliteration in the browser (a copied rule is XIV-100
  again).
- **Content-only changes bypass the changelog gate mechanically**: the copy
  lives in `translations/landing.*`, and `bin/ci` exempts a branch whose entire
  diff is that catalogue plus the template; per branch, explicit list (the
  `messages` catalogue is product text).
- **The script is browser-tested** (XIV-105): every server-side assertion
  passes with the script deleted, and this is the one page strangers reach.
  Both obstacles were the harness's, fixed outside the application (a dotted
  compose alias, since Chromium answers `*.localhost` from loopback and the
  matcher test substitutes dots; a router script for `php -S` standing in for
  the TLS terminator, lying for that hostname only). Not covered, said
  plainly: the debounce and newest-answer-wins.

### 8.14 Turning a confirmed signup into a customer (XIV-98)

The privileged half: one console command (`signup:provision`) on cron, every
few minutes (a person is waiting). The third feature to reach command-plus-cron
from the no-worker constraint, and it is one decision, not three.

- An empty queue is a success; **nothing is ever given up on** (no attempt
  limit, no dead-letter): every retry-fixable failure is fixed elsewhere, and a
  self-disarming run makes the repair a two-step job nobody remembers.
- **`provision()` is not re-runnable; the steps after it are.** A half-made
  tenant is **cleaned up, not finished** (`deprovision()` is re-runnable): a
  tenant in `provisioning` has never served a request and is an empty database
  with a name. A tenant already serving is finished, not torn down.
- **Identity is the hostname, not the slug**: a tenant made here holds its
  derived hostname from the first flush; anything else with the same slug is
  somebody else's, neither resumed nor torn down, failing at `preflight`
  forever (a person must decide).
- Failures reach the tenant list (the row is persisted before the cluster is
  touched); the signup row records a **stage, not a message**, plus an attempt
  counter; still no third status.
- **The slug translation is hyphen to underscore, and nothing else**: the one
  rule a human performs in their head, and a bijection onto its image, so two
  customers cannot collide (a proof, not a hope). The intake checks the
  registry for the translated name **and** the hostname it would take, at ask
  time; untranslatable names are refused at the intake, except a *derived*
  suggestion is cut to fit while a *typed* one is refused.
- **The hostname**: the signup slug under the signup host's parent domain
  (`acme.xivi.app`); a single-label host keeps itself.
  `SelfServiceTenantHostname` owns it and the form delegates (two
  implementations of a promise drift, and the discovery is a customer reaching
  nothing).
- **The first administrator has no password anywhere**
  (`createWithoutPassword()` plus the signed login link; a generated password
  nobody reads is a live credential for the account's lifetime).
- **The customer is not told when it fails**, the honest gap: every signal is
  addressed to the operator, who can act. A mail after N attempts needs a
  decision about what it may honestly say.

### 8.15 A price a customer can see, and an ask that installs nothing (XIV-102)

**There is no payment gateway, and this ticket exists so there does not have to
be one yet.**

- **Rejected: install anyway, marked unpaid** (nothing uninstalls, so the flag
  is a note no code enforces and prices become decorative; "just for now" is
  how it becomes permanent). **Rejected: refuse and say get in touch** (a dead
  end plus homework the system could do). **Adopted: a purchase intent an
  operator fulfils**, §8.12's shape (anyone may ask; the thing happening is a
  separate, non-public act); a future gateway slots in where the operator
  stands.
- **The intent lives in the customer's own database** (§4.4's grant leaves a
  customer's write exactly one home, which is also the right one), collected by
  `tenant:purchase:collect`; the operator screen opens no tenant connection and
  prints the collection time.
- **Rejected, the tempting shape: the store POSTing to a control-plane
  endpoint**: it hands the public image a credential re-obtaining over the
  network the privilege the database refuses (§8.12's API exists because its
  caller is a third party). Also rejected: widening the grant by one `INSERT`,
  which costs the checkable sentence "no write privilege anywhere".
- **The price is copied onto the request**, frozen at the button press; the
  collector carries it untouched and never consults `ModuleCatalog`. Asking
  again rewrites the row (a duplicate-filled queue stops being read), refreshes
  the copy, keeps `created_at`.
- **No status column on either side**: fulfilment is observed (the customer has
  the module or not); the operator answers by installing, and the next
  collection sees it; no button can make the screen disagree with reality.
- **Who asked does not cross** (id and captured name stay in the tenant): the
  answer is delivered inside the product, so the operator answers the company
  without learning which member of staff asked.
- **`buy` is its own permission** (§8.4.3); the operator side has none (one
  kind of operator).
- **The placeholder must not look like a payment page**: no card fields (the
  test counts inputs), no totals or VAT row, no spinner, no promised timeframe,
  no thank-you. It says the cost, that this is a request, that nothing is
  charged, that a person will reply.
- **A free module says nothing** (no badge, no zero: a reader taught to skip
  the price line skips the one that matters); `unpriced` and `not_for_sale`
  never reach the store. A module priced after installation keeps working.
- **Money is drawn as stored** (decimal string plus ISO code, no locale
  formatter: floats, a possibly-absent currency, and shown must equal stored).
  An unset currency shows a bare number; only operator screens name the
  variable. VAT is named and moved on from (a gateway feature with a tax
  adviser in it).
- Not built: withdrawing a request, notifications either way, declining on the
  screen.

### 8.16 An operator can say something, and it lands where the work is (XIV-120)

**A notice appears on the customer's dashboard as a widget**; mail is a second
channel with §8.7's preconditions, none of which should stand between an
operator and "we upgrade on Sunday".

- **In the control plane, registry half, read directly** (no collector): an
  operator writes where the schema is owned, a customer only reads, which the
  grant already permits. XIV-102's constraint in the easy direction.
- **The namespace is the grant**: readable tables derive from
  `App\Registry\Entity` mappings, so a `Notice` filed in the surface's
  namespace would 500 every dashboard. **Recipients are an entity, not a
  `ManyToMany`**: a join table is not a class and is invisible to the grant
  generator; anything that is a table but not an entity is outside
  `readableTables()`. The proof opens a connection **as the restricted role**
  (why the reader takes its entity manager as a constructor argument).
- **The author is a copy** (`author_label`): the reader's instance cannot read
  `operator`, and revocation must not rewrite published authorship.
- **Everybody or named customers, not folded**: recipient rows cascade away
  with a deprovisioned customer, so "no rows means everybody" would silently
  widen a three-company notice to the installation; a boolean says which was
  meant. **Addressing is all-or-nothing** (a notice naming a missing customer
  is refused entirely: reaching three of four while reporting success is this
  feature's own failure mode). "Everyone with module X" is a collector's
  question, so a feature, not an enum case.
- **Audience per notice** (`Everyone`/`Administrators`), coarse and says so; a
  `@notices` permission was refused (a customer must not be able to switch off
  the channel the operator is relied on to have).
- **Dismissing is per person, written in the customer's own database** (the
  grant forces it; per tenant would let the first opener silence everybody).
  The stored `notice_id` points across databases and is dropped when it
  resolves to nothing; no orphan hunter.
- **Live is `published_at`..`expires_at`; withdrawing sets the end to now**
  (one concept). Withdrawing is not deleting ("what did we tell them in March"
  is a question). The operator screen leads with what is live and states who
  each notice went to, asserted against fixtures shaped to catch a
  scope-ignoring query.
- **The widget costs a query in `panel()`, a stated departure**: "does this
  apply to you" *is* the database's question, and an unconditional card is
  permanent furniture.
- **Not the answer to XIV-108**: a stranded signup has no tenant, database or
  user; the person waits outside the product.
- Not built, each deliberate: read receipts (a dismissal reported as "read"
  over-claims on the deciding screen), scheduling, severity levels, links or
  markup (a linkable notice is a phishing channel on the one screen nobody
  distrusts), translation (a notice is somebody's sentence, not a key),
  summaries.

### 8.17 A customer can reach whoever runs this installation (XIV-123)

§8.16's return path: an announcement is one-to-many about the installation, a
ticket one-to-one about a problem.

- **A ticket is a customer's write**, so it lives in their database
  (`support_ticket`) with `tenant:support:collect` bringing it back (XIV-102's
  shape; the HTTP-to-control-plane rejection inherited word for word).
- **The answer comes back the other way**: status and reply live on the
  collected copy, which the customer reads directly, so an answer at 14:03 is
  on their screen at 14:03. **`support_request` is therefore an
  `App\Registry\Entity`** (the namespace is the grant, second feature), unlike
  `purchase_intent`, which only operators read.
- **The leg that waits is the leg where nobody is watching**: customer-to-
  operator costs one collection interval (five minutes in `ScheduledJobs`,
  because somebody waits); operator-to-customer costs nothing. An uncollected
  ticket reads *not received yet*; the operator screen prints collection time
  and its empty state names the command (an empty queue and a missing cron
  entry look identical forever). No interval is printed to customers (a guess
  at somebody else's crontab).
- **One reply per ticket, rewritten, `replied_at` moving with it**: a two-sided
  thread crosses the collector in one direction only and is a conversation
  product. A customer answers back by raising another ticket. Replying does not
  move the status (a hidden state change beside a visible control).
- **`Open`, `InProgress`, `Closed`, any order**: storable here where §8.15
  refused one, because whether somebody picked a question up is not observable.
  No `Answered` (the reply is visible), no priority/category/SLA (promises
  about an arrangement the installation knows nothing about).
- **Everybody signed in may raise one**: raising commits nothing (the
  difference from `buy`), and the person who met the problem describes it. A
  per-installation off-switch was refused (a channel neither end may quietly
  close). The firewall is the whole access control, proved on the POST.
  Tickets are the company's, not the reader's, named, and the page says so.
- **Who raised it does not cross**: the answer arrives inside the product, so
  the operator answers the company.
- **Matched on `(tenant, reference)`, a random reference, never the id** (ids
  restart at 1 on a rebuilt database; the tenant half stops one customer
  naming another's row).
- **The collector removes nothing and writes only the customer's columns**: the
  operator's half exists only in the control plane, and an upsert would discard
  a reply typed during a run. Nothing deletes a support ticket.
- The FAQ is documentation and lives on the docs site; no link from the support
  page (the docs address is a deployment fact). No mail either way, no
  attachments (a file upload crossing the tenant boundary is a feature), no
  read receipts, no search until tens stop being tens, nothing withdraws a
  ticket.

### 8.18 Support enters a tenant only through a door the customer opens (2026-08-20)

Decided ahead of being built, because this capability gets built the wrong way
the day a support case is urgent enough to need it. Support tiers (§9.4) mean
support cases, §8.17 gives them a channel, and the fastest diagnosis of "my
form is broken" is the form; §8.9 keeps operators structurally outside tenants,
and `psql` into a customer's database is permanently available and permanently
tempting.

**Impersonation is requested by the operator and granted by the tenant's own
administrator, per incident**: support asks to connect, the customer accepts,
only then is there a session. The grant is an act in the tenant's own UI,
bounded rather than standing (a grant outliving its incident is a standing
credential in a consent's clothes), and the request, the grant or refusal, and
everything done in the session land in the customer's own history. §8.10 and
§8.11's line (counts, not contents) holds until the customer says otherwise and
holds again when the session ends.

**The back door stays an emergency, not a workflow**: nothing makes `psql`
impossible for whoever runs the machine, and pretending otherwise would fake a
guarantee (§8.9 on hostnames). The front door makes the honest path the
convenient one, visible to the customer, leaving a trail the back door never
will, and a contract clause can then say it is the only path used.

---

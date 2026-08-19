## 8. Identity and access

### 8.1 Users live in the tenant database

Not in the control plane. Pooling them centrally would put every customer's names,
emails and password hashes into one shared database while claiming physical
isolation for everything else (§4), and it would stop export-on-churn from being a
single `pg_dump`. The cost is that one person at two customers is two rows, which
for a B2B CRM is the honest representation anyway.

The security provider is therefore bound to the tenant entity manager: "who is
this email" is only ever answered by the database of the customer being served.

### 8.2 Identifiers are only unique within a tenant

This is the sharp edge of the decision above. The session holds a user
*identifier* and the provider reloads the user from it on each request — so a
session minted for one customer and replayed against another, where the same email
exists, would authenticate as that other customer's user. Emails collide in
practice: one person setting up several customers is `admin@…` in all of them.

Sessions therefore carry the tenant they were created for, and a mismatch
invalidates the session rather than trusting the identifier. Anything else that
outlives a request and names a user has the same obligation.

### 8.3 The UI is server-rendered, in this repository

Form login, session cookie, Twig. Explicitly not a separate SPA: in v1 the
frontend was its own build, which meant a per-customer `yarn build` at signup, the
enabled-module list compiled into each customer's bundle, and customers landing on
whatever commit was current the day they signed up. §3 wants module availability to
be a runtime concern; a build artefact per customer is the opposite of that.

**It assumes JavaScript, and it did not use to** (XIV-28). The old rule was that
the forms worked with scripting turned off, and it earned its keep: it kept the
UI honest, and several decisions here are the better for having been made under
it. It was dropped because of what it cost at the other end — a collection form
ending in one blank row of every kind, because switching a row's fields as
somebody picks needs scripting. At four kinds that is a mess, and the number of
kinds only grows.

**What replaces it is Symfony UX Live Components, and the distinction worth
keeping is exact.** Server-rendered stays true: every page and every re-render is
Twig, and nothing in `assets/` builds HTML. What is gone is "works with
JavaScript off". The two were always separate claims and only the second has been
given up.

**It was htmx first, and the swap is worth recording rather than quietly
rewriting** (XIV-28, then XIV-33). htmx did the job for one button and did it
well. Three things decided against it for the next one:

The morphing argument below was a prediction when it was written. **XIV-32 built
the feature it was predicting and it held**: typing a price updates the line
total and the document's totals around a caret that does not move, with a browser
test on the caret specifically, because the caret is the whole claim and nothing
server-side can see it. The decision is no longer on probation.

- **Morphing.** A form that redraws while somebody is typing in it has to update
  the changed nodes, not replace the region — swap the block a quantity field
  sits in and the caret goes with it, mid-number. Live Components morphs by
  default; htmx swaps, and preserving the caret means the idiomorph extension
  plus hand-managed `hx-preserve`.
- **State.** htmx has no model for state that is not in the markup or the URL. A
  component holds props, which is what a wizard step, a "show advanced" toggle or
  a dependent field needs.
- **One vendor.** The UI library being the framework's own is worth something on
  a codebase that reaches for the framework first everywhere else (§5.7).

The cost, accepted with open eyes: **the write path is now a function of the UI
library**, and the tests that used to press what a person presses now call the
component instead — which is why the browser layer below is not optional.

Three spike branches did the comparison rather than an argument: the whole form
as a component, only a collection as one, and the documented shape with the save
included. The middle one works and is not a pattern the library documents; the
last one is what shipped.

**A refused save answers 200, not 422.** A component that re-rendered is a
successful render, so only the body says no. That is a real loss — anything
speaking HTTP could previously tell a rejection from an acceptance — and it is
recorded here rather than discovered by whatever reads these responses next.

**What a submitted record form *means* is not the controller's** (XIV-30), and
that is why the move above was a change of caller rather than a rewrite. What a
form starts with, which submitted rows were really typed into, whether the
submission is valid and what gets written are none of them facts about HTTP; that
a controller was holding them is an accident of there having been one caller. The
rule to keep: whatever renders the form — a controller, a component, an import,
whatever comes next — asks the same service, and none of them gets its own idea of
what a valid record is.

**One browser runs, and only over what only a browser can see** (XIV-31). Every
other test calls the component directly and learns nothing about whether the page
it sits on does anything. Three assertions in a real browser close
that — the library is loaded, a row appears without the page reloading, and what
was already typed survives it. Deliberately three: an end-to-end layer is where
flakiness lives, flaky tests get skipped, and a skipped safety net is worse than
none because everybody believes it is there.

Two things it cannot share with the rest of the suite, and both are the same
fact. The browser is another process making real requests, so **the transaction
rollback everything else depends on is invisible to it** (§9.2's speed work) — its
tenant is committed and reclaimed on the next run. And it needs a hostname that
resolves *both* from the browser's container and from the application's own,
because the web server binds to the name it will later be asked for; the service
name will not do, because that one is deliberately served without a tenant.

**A third thing, found when the layer was pointed at a page that is bound**
(XIV-105). The server Panther starts speaks plain HTTP and cannot be made to speak
anything else, so a route confined to `https` — which the whole signup surface is,
for reasons §8.12 argues at length — is unreachable from this layer as shipped.
Both halves of the fix are outside the application, a compose alias and a router
script handed to `php -S`, and the argument for doing it that way rather than
loosening the routes under `when@test` is in §8.13. The rule it leaves behind is
worth stating here, where somebody will next hit it: **when the browser layer
cannot reach something, ask what the harness is missing before asking what the
application could give up.**

**The cost to watch is the components, not the library.** A live action is a
second way into the write path and a second place permissions have to be
answered, and the temptation is one component per module the moment something
does not quite fit. A component stays **generic over module, record and shape**
like everything else here, and mounts on a module key and an id rather than on
anything a particular module knows. One component that renders any record form is
fine; a `OrderForm` beside it would quietly become the module-specific code §1
exists to avoid — a finding about the engine rather than a shortcut worth taking.

### 8.3.1 The dashboard is its widgets (XIV-81)

The landing page shipped as a placeholder — a tile per module, two empty states,
and a docblock promising it would be replaced "once there are modules to show".
The first real thing to show up was a list of due follow-ups, and the cheap way
to add it was an `{% if %}` in the dashboard template with a variable the
controller passed down. That is the shape which makes the *second* widget a
rewrite rather than a file, so the seam was cut while there was one implementation
to cut it around.

**A widget is a service that decides whether it has anything to say, and if so
names a template and hands it data.** Nothing more: no registry to configure, no
per-user arrangement, no layout engine, nothing persisted. Discovery and ordering
are Symfony's tagged iterator, which is the reach-for-the-component rule applied
to a problem that would otherwise have grown a `dashboard.yaml`. Nothing keeps a
list of widgets, so nothing can disagree with the classes that exist. Returning
null is "this does not apply to you" rather than "I am empty"; a widget that
throws takes the page down rather than being quietly omitted, because a dashboard
that silently drops a panel is one nobody can trust to be complete.

**The module tiles were converted rather than left in place**, and that is most of
what makes this real. One interface with one implementation and a template that
still knew the answer would have been a special case wearing an abstraction.

**A widget's own controls are its own state, not the URL's** (XIV-84). The
follow-up lens shipped as three links carrying `?follow_ups=today`, on the
argument that a GET which changes what a page shows is a GET. That argument is
sound and it answered the wrong question. **Narrowing a summary is not
navigation**: nobody wants a history entry for it, nobody sends a colleague a link
to their own follow-up list, and — the part that only shows up with a second
widget — the address bar is shared, so every widget with a control on it would
have been negotiating for room on one URL. A page of five widgets whose state is
five query parameters is a page whose back button means nothing in particular.

So a widget that has a control owns it, as a Live Component (§8.3), and the panel
it hands the dashboard is the mount. The line this draws is worth stating because
it is not "components are nicer": **the dashboard decides whether a card exists,
the card decides what is in it.** Whether this customer does follow-ups at all is
a fact about the installation, settled before anything renders and unchanged by
anybody looking at it; which of them are due this week changes while they look.
Those are different lifetimes, and the widget interface is the seam between them.

The URL keeps what it was always for: which page you are on.

#### Whose dashboard it is, and a seam a module can reach (XIV-66)

Everything above stays. What XIV-66 adds is three things that were deliberately
out of scope while there was one implementation to cut the seam around: **the
widget interface moved into core**, **a person arranges their own page**, and
**a panel is fetched separately from the page it is on**.

##### The seam is in `packages/core` now, and only the seam moved

`DashboardWidget` and `WidgetPanel` are `Xivi\Core\Dashboard\`. The obstruction
was structural rather than aesthetic: deptrac's `App` layer is every class under
`App\`, a module package may depend on `Core` and nothing else, so an interface in
the application is an interface `packages/invoice` is forbidden to implement — and
unpaid invoices is probably the most useful thing this product can put on a
landing page. Core declares the seam, the application collects and orders what it
finds, exactly as `ValueDeriver`, `Lifecycle` and `Seed` already work. Core learns
a tag name and nothing else; it still has no idea what a user, a tenant or a
module package is.

**A seam in core does not mean everything using it moves.** The two existing
widgets read the application's own navigation and a table in `src/`; both stayed
where they were, implementing a core interface from up there. The temptation to
move them with it is the one worth naming, because "the interface is in core so
the implementations belong there" is a rule that would have dragged the
permission resolver and the tenant context down with them.

The module needed two more things to be genuinely self-sufficient, and both are
one line each rather than a mechanism: a **translation domain** on the panel, so a
module names its card out of its own catalogue; and `RecordPageUrl`, the sibling
of `RecordSearchUrl` (§7.6), because "12 unpaid invoices" being twelve *links* is
the whole difference between a statistic and a to-do list. Without the second the
module would spell `module_show` in its own Twig template, which is the §3
boundary leaking out through the one file deptrac cannot read.

##### A layout is the fourth instance of §8.4.2's chain, not a fourth variation

The person, then the installation (§8.6), then nothing — where nothing is every
widget that applies, in the order the tags declare, which is what every
installation had before the setting existed. `DashboardLayout` is deliberately
`FormattingLocale` and `DisplayTimezone` with a different value in it rather than
a fourth variation on the same sentence.

**One thing genuinely differs, and it is why the columns are nullable rather than
defaulting to a list.** A language, a region and a zone have no empty value; a
layout does. Null is "has never chosen" and follows the layer below, and `[]` is a
dashboard somebody deliberately cleared. Folding those together would hand
somebody back the page they had just emptied and make the checkboxes look broken.
It is also why going back to the default is a button of its own rather than saving
with nothing ticked, and why the customise link is beside the page heading rather
than among the panels: a link that lived among the widgets would vanish with them,
and the escape §8.4.2's chain owes a person must not be an administrator.

**A default is not a permission.** A widget left out of the installation's layout
is still on offer in everybody's picker. What a person may *see* is §8.4's
question, answered per module against records as well as tiles, and a preference
somebody can edit is not a place to answer it.

**A saved layout is data referring to code, which is the sharp part.** A key can
name a module the customer has uninstalled, a widget a later deploy renamed, or a
class somebody deleted, and a key nothing answers to is dropped — the same
treatment and the same argument as a stale `reference` (§7.6). The key lives on
the *panel* rather than on the interface, so a widget that returns null produces
no key at all and "does not apply to you" and "is not on offer to you" are one
fact rather than two that can disagree. §6.2's rule — a widget for an uninstalled
module is not offered — is therefore enforced nowhere: it falls out of the widget
returning null, which is the only place that fact is known.

##### Deferring, and what makes it worth anything

`loading="defer"` is already in `symfony/ux-live-component`, so the mechanism cost
no dependency. The part that needed designing is that **deferring the rendering
saves nothing on its own.** `panel()` is asked of every widget on every render — it
has to be, because the reader's layout is a list of keys and the keys come from the
panels — so a widget that counted rows in that method would have charged the page
for a card the reader had hidden, and a deferred one would have charged it twice.
So `panel()` is cheap by contract and the panel's data is a **promise** the
renderer resolves only for a panel it is actually drawing: XIV-84's line — the
dashboard decides whether a card exists, the card decides what is in it —
restated one level down.

**The mount is the dashboard's rather than each widget's**, which is the other
half of the module story: `loading="defer"` is an attribute on a Live Component,
and `symfony/ux-live-component` is not a dependency of `packages/invoice`. One
generic `DashboardPanel` component takes a widget key, so a module ships a class
and a plain Twig template with no front-end dependency of any kind.

**A widget declaring what it costs** stays a question rather than a requirement.
`defer` is a widget saying "this touches the database", which is as much as
anything currently acts on; a number would be a number nothing reads.

##### The "no charts yet" position, narrowed rather than reversed (XIV-121)

XIV-66 declined to add charting and gave the rule that would let something in
later: *"a dashboard that looks sad is fixed by useful numbers and actionable
lists, not by graphics; a chart earns its place where a **trend** is what is
being read, and nowhere else. Add the dependency for the one or two widgets that
need one. If that turns out to be zero, it was never needed."*

It did not turn out to be zero. **An article's price over time is a trend and
nothing else**, and it is the case the rule was written to admit: the same data
as a table — "on 3 March, 100.00 became 120.00" — is already on the record page
twice, in the history card and on the timeline page, and nobody reads it as a
series, because a column of numbers is not a shape. So the chart is not a second
way of showing what is shown; it is the one reading of that data a table cannot
give. `symfony/ux-chartjs` is in (MIT; Chart.js MIT, self-hosted through
AssetMapper like everything else — §8.3's no-CDN promise is unchanged).

**What is narrowed and what still stands.** The dependency is now paid for, so
the argument against the *next* chart can no longer be "it costs a dependency".
It has to be XIV-66's actual rule, which is unchanged: a chart is for a trend.
Dashboards of charts, revenue over time and anything aggregating across records
are still refused here — not because they cannot be drawn now, but because each
is a different design with a different subject and a different permission
question, and none of them has been through it.

**It is not a chart of `price`, it is a chart of a numeric field**, read off the
values rather than off a declared type, so a customer's own field (§6.1) gets a
trend with no deploy. One chart wired to one field of one module would have been
a special case with a dependency attached.

**The card is the dashboard split, one level down.** §8.3.1's line — the page
decides whether a card exists, the card decides what is in it — is applied to a
record instead of a dashboard, and inverted in one respect worth naming: the
record page mounts this unconditionally and the *card* decides whether it
exists, because whether a module takes follow-ups is a flag on the definition
and whether a record has anything numeric to draw is not.

**A control on a card is the card's, not the URL's** (XIV-84 again, and the same
sentence): which of two numbers somebody is looking at is not navigation, is not
worth a back-button entry and is not a link anybody sends a colleague.

**Permissions are asked twice and the second answer is silence.** A chart is a
number about records, so a reader must see nothing here about a record they may
not open (§8.4). The record page has voted `view` on the record; the component
asks again at its own endpoint, record-level rather than module-level, because
props are signed rather than secret. It **draws nothing** rather than refusing:
a controller answers a page and can answer 404, but there is no reading of "404"
a card inside somebody else's page can perform — thrown from a template it
becomes a 500, which is a worse outcome for exactly the same disclosure, namely
none.

**Chart.js is loaded lazily, and that is the difference between the cost below
and a real one.** `assets/controllers.json` marks the chart controller `fetch:
lazy` — the only controller in this application that is not eager — so the
library is imported when a page contains a canvas asking for it and on no other
page. The sign-in page, the dashboard, every list and every record of a module
with no numbers on it are byte-for-byte what they were. Eager would have put
200 KB of JavaScript on all of them for a card that appears on some article
pages. A JSON file holds no comment, so this paragraph is the only place that
`lazy` is explained.

**Which is why there is a browser test.** Lazy loading, the stepped
configuration and the small controller that formats the axis all fail the same
way — a blank box, a message in a console nobody is reading, and a green suite,
because every other test asserts what the *server* put in the page. The browser
test reads the canvas back and counts the pixels that are not transparent. §8.3's
warning about this layer stands and is why it is one assertion rather than a
suite.

**What it cost, measured against the built image.** `symfony/ux-chartjs` v3.4.0
plus Chart.js 4.5.1 and `@kurkle/color`: **+586 KB inside `frankenphp_public`**
(235 KB of AssetMapper sources, 243 KB of the compiled copies under
`public/assets`, 27 KB of PHP, and the rest autoloader and warmed cache), which
is **+175 KB, or +0.17%, on the image's own reported size** of 103 MB. A browser
downloads about 208 KB of JavaScript, once, from this installation's own host.


### 8.4 Authorization: grants, resolved per person

Waiting was the right call. The record-level half turned out to be a query
problem rather than a security-layer one, and designing it before the query layer
existed would have produced a check performed after loading — which is the wrong
answer in a way that looks right.

**What can be done is a closed enum**: view, list, add, edit, delete, export,
import, per module. That closure is what makes the *catalogue* free — it is the
enum crossed with the modules a customer has installed, worked out at runtime —
so there is no table of available permissions to seed when a module is installed
and none to migrate when a new action ships. Nothing can drift out of step with
the code, because nothing is written down twice. It is §5's field-type registry
argument applied to a second thing.

**Not everything grantable is a module** (XIV-12). The tenant profile (§8.6) is
the first thing worth granting that no module owns, so the catalogue is the enum
crossed with the customer's modules **and** a closed set of *areas* — still
worked out at runtime, still nothing seeded and nothing migrated. An area is
stored in `permission_grant.module_key`, which needs no schema change because
that column was never a join: it holds a string precisely so a grant can name
something the definitions do not have. Area keys begin with `@`, which a module
key cannot, so the two can never collide however a customer names a module. The
verbs stay ModuleAction's, and scope does not apply — there is one profile and it
is nobody's own. When something wants a verb this enum has not got — the store's
browse and install (XIV-6) — that is the moment to add a second axis, with a real
second case to design it against rather than a guess.

**Only grants are stored.** A grant says one holder may do one action to one
module's records, this far. The holder is a group or a person, in one table with
a check constraint enforcing exactly one — resolving somebody is a union of the
two, and two tables would mean writing that union twice and having it disagree
once.

**Scope is all records or only your own**, and it applies to every action that
names a record which already exists. Adding and importing name none, so the enum
says they cannot be scoped and every screen asks it rather than knowing.

**Nothing can deny.** Grants are additive, so resolution is a maximum rather than
a precedence table, and therefore order-independent. "Why can this person still
see that" never becomes a question with a complicated answer. The cost is that
"everything except one thing" has to be expressed as a smaller grant, which is
the trade every deny-list eventually wishes it had made.

**`ROLE_ADMIN` stays a bypass, not a group.** A group somebody can be removed
from would reintroduce exactly the lock-out §8.4.1 was built to refuse, and there
is no support desk behind this.

**Three enforcement seams, and the third is why this was entangled with §7.3:**

- A route carries `#[IsGranted]`, checked before the action runs.
- A record is decided by a voter, which is what a voter can do.
- **A list is decided by a WHERE clause**, which is what a voter cannot. By the
  time a voter runs, the page is fetched and the total is counted separately — a
  restriction reaching one and not the other prints the number of records
  somebody may not see directly underneath the ones they may. The predicate sits
  beside the soft-delete one in the compiler, exactly where §5.3 reserved the
  slot. The export carries it too, being the fastest way to leave with records
  you were shown one page of.

The two seams must agree, and the shape of their disagreement is the
vulnerability: a record kept out of a list that can still be opened by typing its
id is not protected, merely inconvenient to find. Refusing it answers 404 rather
than 403, so guessing ids reveals nothing; a record you may view but not change
answers 403, because that is true.

**Default deny, and the upgrade path is a command rather than a migration.**
Before this, anybody who could sign in could do anything. The migration that
added the tables writes no grants: it lands for every tenant at once (§4), and
deciding what a customer's people may do is not something to do to them in
passing. `tenant:permissions:grant-all` is the deliberate act, and also the way
back into an installation that has locked itself out.

**The build fails when a route names no permission.** The catalogue needs no
maintenance, but nothing in PHP makes somebody annotate a new route, and an
unprotected one is invisible — it works, for everybody, which is what a correct
one looks like. The surface is defined by the URL rather than by a list of
controllers, so a new controller is covered the day it is written.

`ROLE_ADMIN` still gates the metadata editor (§5.4), user management and the
permission screens themselves — the last because gating them with a module
permission would be circular. Importing is no longer among them: it is its own
grant, which is the answer §5.6 said this section would give it.

**And so is the endpoint it became** (XIV-36). Once a picker can be typed into, the
same argument applies harder: a search box is a way to enumerate a module by
letters, where a dropdown only leaked the page it drew. The route carries
`view` on the target module and the query carries the same `RecordAccess`
predicate a list compiles — both seams, because neither implies the other, and
there is a test that a reader scoped to their own records cannot find a
colleague's by name. It answers 404 for a module the customer does not have, like
every other module route.

**A reference picker is scoped** (XIV-13), which answers what this section used to
leave open. An unrestricted picker is a way to read the names of records somebody
may not open — point at one, read the label back — so the candidates go through
the same `RecordAccess` a list does. The cost is real and worth stating: somebody
scoped to their own records will see a picker that omits the answer they wanted,
with no message saying why. That is the safer half of the trade, and the half that
can be widened by a grant instead of by a deploy.

Core asks for that answer through `RecordAccessProvider`, because a query
following a link cannot know in advance which module it will land in — the same
seam as `InstanceCurrency`, one level further out.

Still open: what a grant means for a module the customer has uninstalled, which is
inert today and deliberately not deleted.

**Not somewhere a customer-authored expression can go**, and that is now written
down rather than left to be worked out again (XIV-88, §5.8). The third seam above
is a `WHERE` clause; an expression evaluates in PHP over a record already loaded,
so a rule written as one would restrict the page and not the total beside it —
which is this section's opening sentence, arriving through a new door.

### 8.4.1 Managing users, before managing permissions

Permissions need something to be granted *to*, and until there was a screen for
users the only way to have a second one was a console command against the
customer's database — which is not a thing a customer has. So the user manager
came first, deliberately, and is where the model of §8.4 attached: group
membership and a person's own grants are edited on the same page as their name.

The same argument ran a second time and produced the group screens. A permission
model with no screen is one only its author can use, and "run this command
against your customer's database" is not an answer.

**Deactivate, never delete.** Records carry the id of whoever owns them and
history carries the id of whoever made each change, so deleting a row leaves
records belonging to nobody and a timeline pointing at an absence. Deactivating
locks the person out, keeps every record attributable, and is reversible.

**`User::active` had to be made to mean something first.** The column existed
from the beginning and nothing read it: no user checker, no query filtering on
it. A deactivate button on top of that would have been worse than none, because
somebody would have relied on it. It now takes **two** mechanisms, and neither
covers the other's case: a user checker refuses the sign-in, and a request
listener ends a session that already exists — because a user checker is *not*
consulted when a session is restored, so without the second, withdrawing access
would take effect whenever the session happened to expire.

**Every refusal is about lock-out.** An administrator cannot deactivate their own
account, cannot take administrator away from themselves, and cannot leave the
installation with no active administrator at all. There is no support desk behind
this: getting back in would mean a console command against the customer's
database.

### 8.4.2 Language

**Language and region are two settings** (XIV-50). Which words somebody reads and
which country's conventions they write by are independent questions, and one
picker was answering both: choosing "Deutsch" got German-from-Germany, so a Swiss
reader was shown `1.234.500,00` where their country writes `1’234’500.00` — a
different decimal separator, not only a different grouping one. An
English-speaking colleague at a Swiss company is an ordinary hire, and wants
English words with Swiss figures. So the language is chosen from the catalogues
that exist and the region from the countries there are, and they are put back
together at the point of use — `de` and `CH` make `de_CH`.

The chain is the familiar one, and each step is a different promise: the person,
then the installation (§8.6, whose people are mostly in one country), then
nothing — where nothing leaves the bare language, which is what every
installation had before this existed.

**Dates are shown locally and stored as ISO**, and those are two formats with two
names. A date is kept as an ISO string because it then sorts and compares as text
without a cast (§5); the reader's form is computed from the locale's short
pattern with the year widened, since CLDR mostly writes it as two digits and a
record saying `15.08.26` is one somebody has to think about. Reaching for the
storage constant to localize a display is precisely the mistake `CurrencyFieldType`
made in XIV-47, where one method both formatted and normalized and localizing it
made every save refuse its own totals.


Each person picks the language they read the application in, stored on their own
row rather than the tenant's: one office is not one language, and a Swiss company
has German and French speakers in it. Resolved per request from the user and
never parked in the session, which would be state outliving the request that made
it (§7.4) — the one hazard this runtime otherwise does not have. The login page
has nobody to ask and follows the browser.

**A customer's own words are not translated.** Module labels, field labels and
choice options are their data (§5); two colleagues share one row, so a label that
changed with who was looking would have stopped being data. What a *blueprint*
ships is different — that is code, and it was English. Its labels are keys now,
resolved **once at install time** from the module's own catalogue and then
written down. Seeded, exactly like the preset they arrive with (§6.1), and silent
afterwards: a label looked up on every render would overrule the customer's rename
every page load, which would make the screen offering that rename a lie.

Which is why renaming a shape had to exist. Fields were always relabelable
(§5.4); the module holding them was not, so one installed in the wrong language
could not be corrected at all.

The engine and each module ship their own catalogues, so core can name a filter
operator without reaching into the application's file, and a module can name
itself without either of them.

**A missing translation fails the build.** It is the quietest bug available here:
the fallback keeps the page working and merely serves one paragraph of it in the
wrong language, on somebody else's screen, in a country nobody is looking at.

### 8.4.3 A second permission axis, for the store (XIV-6)

§8.4 predicted this one by name: *"when something wants a verb this enum has not
got — the store's browse and install (XIV-6) — that is the moment to add a second
axis, with a real second case to design it against rather than a guess."* The
store is that case, and the guess would have been wrong, so it is worth writing
down why there are now two axes rather than one.

**Every ModuleAction is something done to a module's records**, and a grant on
one names the module whose records they are. That sentence is the whole model,
and it is what makes the catalogue free — the enum crossed with the customer's
installed modules, worked out at runtime. Neither of the store's verbs fits it:

- **Browse** is about no module whatsoever. It is about the shop window.
- **Install** is about a module the customer specifically does **not** have,
  which is the sharp end: a per-module grant has nothing to attach to. "May
  install invoice" would be grantable only by somebody who could already see that
  invoice exists on a tenant where it does not, and would need granting again for
  every module ever shipped. The authority is not a list about modules; it is one
  sentence about the business — *may decide what this installation consists of*.

Adding them as ModuleAction cases would also have made §8.4's areas incoherent.
The areas' premise (XIV-12) is that the *verbs* stay ModuleAction's and only the
**subject** changes — a profile is viewed and edited like anything else. Here the
subject is fixed and the **verbs** are what changed, which is the other axis of
the same table.

**What is actually second is the vocabulary, not the machinery.** `StoreAction`
is a second enum of verbs; `PermissionVerb` is what it and `ModuleAction` have in
common, and it is deliberately tiny — a stored value, whether the verb can be
scoped, and how to label it. Everything else stays exactly as it was: one grant
table, one resolver, one resolved `PermissionSet`, additive grants, a maximum
rather than a precedence table. **And it costs no migration**, which is the same
argument §8.4 made about the catalogue, one level up: `permission_grant` was
already "a subject, a verb, a scope" and had opinions about none of them.

The one thing that did change is the column's *mapping*: `enumType:` names exactly
one enum class, so a column holding a verb from either vocabulary cannot use it.
The typing moved one layer out — the column is a string and the entity hands back
a `PermissionVerb`. That works only while the two vocabularies share no word, so
`PermissionCoverageTest` fails the build if they ever collide; a collision would
not throw, it would silently resolve to whichever enum was tried first, for grants
somebody had already been given.

**Two voters, one per axis.** `ModulePermissionVoter`'s whole subject is a
module's records; teaching it a second vocabulary would have made it the class
that knows about both axes, which is a job `PermissionVerbs` already does in one
place.

**A verb from the wrong axis is not stored.** The permission screens generate
their cells from what the customer has, so nothing legitimate posts
`('contact', 'install')` — but a hand-edited request can, and the row would sit in
the table reading as an authority and conferring nothing. Which verbs a subject
accepts is derived from the subject rather than listed, and the manager drops the
rest. Same policy as an unknown module key: ignored rather than explained.

**Nobody has these grants on upgrade, and that is deliberate.**
`tenant:permissions:grant-all` does not hand them out. Its contract is every
action on every *installed module*, and the store's install verb decides what the
installation consists of — permanently, since there is no uninstall — which a
command whose job is undoing a lock-out has no business granting in passing.
Administrators reach the store through the `ROLE_ADMIN` bypass; everybody else is
given it on the permission screens, by somebody who meant to.

*A third verb since §8.15 ([XIV-102]).* `buy` — may ask for a module that costs
money — and the interesting part is that it is a case rather than a third axis:
the subject is still `@store`, the scope still does not apply, and the permission
screens draw it because they iterate the enum. Splitting it from `install` is the
decision, and §8.15 has the argument: one is authority over what this installation
consists of and the other is authority over the company's money, and in a small
company they belong to different people more often than not. It does **not** imply
`install` — a purchase request installs nothing at all — and the paragraph above
applies to it word for word, `grant-all` included.

### 8.4.4 A timezone to read moments in (XIV-83)

**Storage needed no change at all, and that is the finding worth keeping.**
Postgres `timestamptz` normalises to UTC on write and holds no per-row zone, the
engine has always written moments through `Types::DATETIMETZ_IMMUTABLE` —
`<module>_history.occurred_at` is the oldest example — and the process runs with
`date.timezone = UTC`. So "store UTC, display local" was never a migration
waiting to happen; the storage half has been right since the first table and the
*display* half simply did not exist. Nothing converted anywhere. The one rule
that keeps it cheap is the one to hold going forward: **no new column is a
zoneless `timestamp`.**

That gap was invisible for as long as the only moments on screen were history
timestamps, because an hour's error in a label is cosmetic. It stops being
invisible the moment anything groups by day: the same hour crossing a calendar
boundary **moves** an entry rather than mislabelling it, and §5.2's timeline was
drawing "today, this week, this month" on UTC midnights. Somebody in Zurich
saving a record at 00:30 found it filed under yesterday on a page they had made
half an hour ago. The fault was already shipped and simply had nobody looking at
it.

**Timezone is the third setting of the shape §8.4.2 established**, and the region
is emphatically not it: `CH` is a country, `Europe/Zurich` is a zone, and a
country code says nothing about clocks on its own. `DisplayTimezone` walks the
familiar chain with one extra link, each a different promise — the person, then
the installation (§8.6), then **whatever the effective region implies**, then UTC.

**The third link is what makes this free for most customers.** They have already
chosen a country, and for most of the countries this serves that answer is
unambiguous: Switzerland is `Europe/Zurich` and there is nothing left to ask. A
Swiss company will never open this setting, which is the whole reason it derives
rather than demanding an answer on day one.

**Derive only where the country has exactly one zone, and never take the first of
several.** The head-of-list rule is the trap here, and it is a quiet one: CLDR
orders by identifier, so a head-of-list rule files a Madrid office in North
Africa. Where the country is ambiguous nothing is derived and the setting becomes
one somebody answers, because **a wrong zone is worse than an unanswered
question**: nothing on screen reveals it, since a timestamp in the wrong zone
still looks exactly like a timestamp. Which is also why both pickers name the
zone that is currently in force beside their empty option — the cheapest
available way to make an unnoticed default noticeable. Zones that *happen* to
agree today are not collapsed either, because a list of "close enough" pairs is
true only until one of them changes its rules; the rule stays arithmetic.

**One departure, and it is a different question wearing the same clothes.** India
lists `Asia/Calcutta` beside `Asia/Kolkata`: two names for one zone, because the
tz database keeps the old name as a link after a city is renamed. Counting
identifiers would have made India ambiguous and offered an Indian customer a
choice between Calcutta and Kolkata, which is not a choice. Recognising a link is
not the judgement rejected above — Berlin and Büsingen are two zones that agree,
Calcutta and Kolkata were never two zones — but telling them apart needs the tz
database rather than CLDR, so symfony/intl still says which zones a country has
and PHP's own `DateTimeZone::listIdentifiers()` is asked the narrow question of
which identifiers are canonical.

**Rendering is one setting on Twig rather than a filter on every template.** A
request-scoped listener turning `twig.date.timezone` per reader covers every
moment on every page with no new Twig extension and no `|date(…, timezone)`
threaded through a dozen templates. `date_default_timezone_set()` was the
alternative and is rejected: it would also change what gets *written*, and since
those are absolute instants that would still store correctly, the damage would be
quiet rather than loud. The application runs in UTC and keeps running in UTC.

The grouping is the one thing the Twig setting cannot reach, because it happens in
PHP before anything renders — so core takes a `\DateTimeZone` and applies it to
`now` rather than asking who is reading, the engine still not knowing what a user
is (§5.2). The entries themselves need no conversion at all, since comparing two
moments compares instants rather than wall clocks.

**A console command has no user and may have no tenant, and neither is an
error.** No tenant resolved is the ordinary condition in `bin/console` and on the
login page, so the chain simply runs out of things to ask and lands on UTC.

### 8.5 The first user comes from provisioning

`tenant:provision --admin-email=…` creates an admin and prints a generated password
once; `tenant:user:create` adds more. Passwords are `random_bytes`, never derived
from the clock — v1 generated them with `date +%s | sha256sum`, which reduces the
search space to "which second was this account created in".

That printed password is the one credential in the system a human has to read. It
exists because there is no mailer yet; when there is one, this becomes an invite
link and the printing goes away.

**A generated password has to be replaced before the account is usable.** It is a
way in rather than a credential: the administrator read it off a screen and passed
it on by whatever means was to hand — chat, a phone call, an email that will sit
in a mailbox for years — so at least two people know it. `app_user.must_change_password`
is set whenever the system generates one and cleared when the owner picks their
own, and until then every page redirects to the account page. Signing out stays
allowed: somebody who cannot change their password right now must still be able to
leave.

A hold rather than a first-run wizard, so there is no separate flow to keep in
step with the ordinary one. And it applies only to passwords *this* generated — a
password handed in by provisioning or a console command was chosen by whoever ran
it, and demanding they change it immediately would be telling them their own
decision was wrong.

The screens work the same way and share the same code path: adding a user
generates a password and shows it once, and an administrator never types one.
An administrator who picks a colleague's password knows their colleague's
password, which is a different system from the one this is trying to be. Changing
it afterwards belongs to the account owner, on `/account`, and needs the current
one — not because a password is secret from its owner, but because an unattended
session should not be enough to take an account over.

### 8.6 The instance's own settings (XIV-12)

One profile per customer: what they call themselves, and the currency they work
in. `/account` is one person's settings and everybody has one; this is the
installation's, and it is granted.

**In the tenant's database, not the control plane.** It is the customer's data,
edited by the customer, and the request already holds that connection. The
control plane's `tenant.name` stays what it always was — the operator's label in
the registry, which the customer cannot see and has no business renaming — and
the profile's company name is what they call themselves. Two facts rather than
one, and the chrome shows the second when it exists and the first until then, so
they never look like they disagree.

**One row, enforced by the primary key**, which is a constant rather than a
sequence: a second profile is a duplicate key rather than something to notice
later. The migration inserts it for every tenant carrying no opinions — an empty
name and no currency are exactly what "nobody has filled this in" looks like.

**The currency is a code, never a symbol or a formatted string.** symfony/intl
turns ISO 4217 into either, in whatever language is being read, and the list of
what exists is not ours to keep. Null rather than a default, because a currency
guessed for a customer is wrong quietly — it would surface on the first priced
thing they ever printed.

**Read and change are separate grants** (§8.4). Somebody may need to look up what
the instance prices in without being the person who decides it, so the page shows
its fields disabled rather than refusing them outright.

#### The customer's own mark (XIV-49)

What they are called was half the answer; what that looks like is the other half.
Distinct from the *instance* logo (XIV-48), which is Xivi's own and is supplied by
the deployment as a file: this one is the customer's, uploaded by them, and the
two only resemble each other in ending up inside the same `<img>`.

**In the tenant's database, in a `bytea` column, exactly as a document template
is** (§5.7). Nothing new is decided here — templates already settled where a
customer's small files live, and a logo is a smaller one of them. There is
precisely one, it is unmistakably theirs, and the per-customer backup, restore and
export-on-churn §4 hands us keep working with nothing added. The general
file-storage design attachments will need is still not being started.

**PNG and JPEG, and SVG is refused.** This is the decision worth the most words,
because SVG is what everybody wants for a logo and is the one candidate that is
not an image: it is an XML document, with a `<script>` element, event handlers on
every node and external references. Served in an `<img>` a browser will not run
it — but nothing keeps it in an `<img>`, and the route below is deliberately
readable without signing in, from the customer's own origin, which is the origin
their session cookie belongs to. Accepting it safely means sanitizing it, and
**the sanitizer is what settles it**: the one credible SVG sanitizer in PHP is
`enshrined/svg-sanitize`, GPL-2.0-or-later. This project is MIT and turned PHPWord
down over LGPL-3.0; a copyleft dependency is not a thing to take on for a nicer
logo. `symfony/html-sanitizer` is MIT and is not an answer — it parses HTML, and
an SVG through it comes out as either nothing or something that no longer draws.
Writing our own would be maintaining a security component over a format with
namespaces, entities and `xlink:href`, which is the thing "reach for the
framework's own first" exists to prevent. A customer with only an SVG exports a
PNG, which is one step in their design tool and not a step anybody here has to be
right about. WebP and AVIF are left out for a much smaller reason — they are safe,
they are simply not what anybody hands over — and could be added any time.

There is a size ceiling and a pixel ceiling, and they exist for different
reasons: the bytes sit on `tenant_profile`, which is read on nearly every page, so
the first is also the extra row every request carries; the second is not about our
own memory at all — nothing here decodes the image — but about not handing a
decompression bomb to the browser drawing the sign-in page. Both are decided by
reading the header, never by the file name or the `Content-Type` the upload
claimed, which is the same call §5.7's `.docx` check makes.

**Nothing is re-encoded.** What comes back out is byte for byte what went in,
against the obvious alternative of normalising everything through GD: re-encoding
is how a crisp wordmark acquires artefacts, and a customer whose logo came back
looking worse has no way of knowing we did it. The price is that the accepted list
has to be safe to serve untouched, which is what the paragraph above is about.

**The sign-in page carries it, and that reverses what XIV-49 first said.** The
original objection was disclosure — showing Acme's mark at `acme.xivi.app` tells a
visitor whose installation they have found. That objection was overtaken by
XIV-79, which made the login card's `<h1>` the hostname: the page says it in words
already, above the picture. What is left is the thing that matters, which is that
an installation should read as the customer's product from the first screen rather
than as ours with their name in the corner. It works because tenancy is resolved
before authentication — `TenantRequestListener` runs on the `Host` header at
priority 100 — so a tenant is in scope with no session at all. A system host
resolves no tenant and falls back to Xivi's own mark, which is right, because that
page is Xivi's.

**Serving it is the one narrowing of §8.4 in the application, and it is stated
rather than incidental.** The route is tenancy-scoped and not permission-gated,
because it cannot be both on a page where nobody has signed in; a logo is a public
mark by definition, printed on the customer's letterhead and website, and treating
it as a secret would be protecting something they publish. What is *not* given up
is tenancy: the action can only ever reach the profile of the host it was asked on.
And nothing else on that row comes out of the same door — the response is the image
and its type, which matters because the SMTP host, user and encrypted password
(§8.7) live on the very same row. There is a test that compares the body for
equality with the bytes rather than merely searching it, because an image plus
anything is not an image.

**Changing it is the profile's `edit` grant and not one of its own.** It is the
same act as changing the company name, on the same screen, in the same submission;
a permission of its own would be a second thing to grant to everybody who already
has the first, which is how a permission catalogue becomes the thing nobody
maintains.

**The cache is the fingerprint, and the fingerprint is in the URL.** The mark is on
every page including the sign-in one, comes out of the database and changes almost
never, so it wants a long lifetime — and a long lifetime that outlives a
replacement means a customer uploads a new logo, is shown the old one, and
reasonably concludes the upload failed. Putting a digest of the bytes in the path
gets both: a different logo is a different address, so the old address is never
asked for again and the bytes behind the new one may be declared `immutable`. A
path segment rather than a query string, because caches are entitled to ignore a
query string when deciding what a URL means. The remaining case is a page that was
itself cached before the change and still asks for the old address; that is
answered with the current bytes and `no-store`, because caching them under an
address that has already meant something else is exactly the promise `immutable`
must not break.

**`alt` differs between the two places it is drawn, and the rule is what generates
the difference.** A mark that repeats adjacent text is decorative; a mark that is
the only statement of identity is not. In the top bar the company name is printed
beside it, so it is `alt=""` and a screen reader is not made to say "Acme AG, Acme
AG". On the sign-in page nothing else names the company — the heading below the
card is the *hostname*, which is an address rather than a name — so there it is
named. Xivi's own mark stays decorative in both places, unchanged from XIV-48.

**One upload, not two**, as XIV-49 asked. A wide wordmark suits a bar and a square
one suits a letterhead, and the honest position is that this will be found out
rather than predicted; when it is, that is a second field and not a redesign. The
favicon was considered and left as Xivi's: a wordmark makes a poor sixteen-pixel
square, and the tab is the one place the reader is choosing between applications
rather than reading inside one.

**The document half landed as XIV-89** and is written up in §5.7, where the
reasoning belongs, because it turned out to be a change to the .docx pipeline
rather than an addition to the marker list. Two things decided there report back
here. The mark is drawn at its natural size at 96 dpi, capped to fit 40 × 20 mm
and never enlarged — and **that still does not want a second upload**: fitting
rather than stretching gives a wide wordmark and a square crest a sensible answer
from the one file, so the case for a second field remains what it was above,
about wanting a different *picture* rather than the same one at a different size.
And the engine reads these bytes out of `TenantProfile` directly: the route this
section adds is for a page, and a document is generated without a browser.

### 8.7 Who a tenant's mail comes from (XIV-37)

An email is sent *by a customer*, to their customer, and it has to look like it.
That is one question with three possible answers, and the difference between them
is entirely about **who owns deliverability** — who publishes the SPF record, who
holds the DKIM key, and whose reputation is spent when a recipient marks a message
as spam.

- **From this instance's domain, with the customer's name on it.** Works on day
  one and needs nothing from them. It is also honest in a way the third option is
  not: the mail really did come from us, and says so.
- **Through the customer's own SMTP server.** Genuinely from them, because their
  provider is the one entitled to claim their address. SPF, DKIM and reputation
  are theirs, which is the correct place for all three.
- **As the customer's domain, from our infrastructure.** Needs DNS records they
  have to add, and is the option that fails *silently into spam folders* when they
  do not. Rejected: the failure mode is invisible to everybody who could fix it.

**The second, with the first as the fallback.** A customer who has named an SMTP
server sends through it, under their own address. A customer who has not sends
through this instance's transport — and then their address is the **`Reply-To`,
never the `From`**, because our domain is not entitled to claim it and SPF exists
precisely to say so. Their *name* is still on the message, so a recipient sees who
it is from; a reply still reaches them. The feature works before a customer has
configured anything and becomes correct once they have, and the upgrade is one
form field rather than a migration.

**The name on the mail is the name in the bar.** There is no separate "sender
name" setting: it is `InstanceName` (§8.6) — the company name where they have set
one, the registry's label until then. A company with two names for itself is a
problem nobody has, and a second field would only be a way for the two to disagree.

**The instance's own address** is `MAILER_SENDER`, and it may be left empty. The
fallback is then `no-reply@` at the tenant's own primary domain, which is not a
guess: that hostname *is* this installation as far as that customer is concerned
(§4), so it is the literal truth of "sent from our infrastructure".

**The SMTP credential is stored the way tenant database passwords are stored** —
encrypted with `TenantSecretCipher`, the stored value naming the key it was
written with, so several keys are valid at once and rotation is a resumable job
(§9.2). Reused rather than reinvented: a second encryption mechanism is a second
thing to rotate and a second thing to forget to rotate. Which is exactly what
happened here in miniature, and is worth recording: this secret lives in the
**customer's own database** rather than the control plane, because it is their
setting edited on their settings page (§8.6) — so `tenant:rotate-secrets` now walks
every tenant database as well as the registry. A rotation that had not would have
reported "everything is on the active key", the operator would have dropped the old
one on the strength of it, and every customer's mail password would have become
unreadable — quietly, until the next invoice somebody tried to send. **The tenant's
database is rotated first**, because the control-plane row is the key to it: moving
that first and dying leaves the door on a key the next attempt may no longer hold.

**Sending is synchronous, and this is a decision rather than a stage.** Messenger
with an async transport wants a consumer process, and this runtime is FrankenPHP in
classic mode with no worker on purpose (§9.2), so nothing runs between requests. A
queue with nothing draining it is worse than a slow request: the mail simply never
goes. So a slow SMTP server is a slow request, accepted, and this is revisited when
there is a reason to run a process — one that is about more than mail. Nobody
should have to re-derive that from the runtime again.

**A failed send is never swallowed.** A document that fails to generate wastes
somebody's minute; an email is outbound and irreversible, and a send that failed
silently is a customer sitting there believing their invoice went out. Every
failure inside `TenantMailer` becomes `MailSendFailed` and is thrown on, so the
person who pressed the button is told and XIV-39 can write the attempt to the
timeline as a failure — "nothing happened" and "it went out" must not look the same.

**Dev and test cannot send real mail, and that is a transport decision rather than
a configured DSN.** §9.2 already recorded why the catcher is not a guarantee: it
sees what is pointed at it, and a DSN naming a real server is believed. With
per-tenant credentials that gap stopped being theoretical — the suite provisions
real tenants, so one fixture storing a real SMTP password would have been one send
from mailing an actual person. So a guard is registered ahead of every transport
factory symfony/mailer ships, and outside production nothing that could deliver is
ever **built**: not from `MAILER_DSN`, and not from a tenant's credentials,
because those go through the same factory rather than constructing a transport by
hand. That is the load-bearing observation — one place turns a DSN into a
transport, so one factory in front of it is a guarantee rather than a default.

### 8.8 An invitation instead of a password read off a screen (XIV-1)

§8.5 recorded the printed password as a placeholder and said what would replace
it: *"That printed password is the one credential in the system a human has to
read. It exists because there is no mailer yet; when there is one, this becomes
an invite link."* XIV-37 built the mailer, so this is that sentence being kept.

Adding a colleague now asks **how they get in the first time**, and the two
answers are genuinely different rather than one with a flag on it. The invitation
path **generates no password at all** — which is the ticket's own requirement and
the load-bearing part of it. A generated password created for somebody who is
about to choose their own is a credential sitting on the account that nobody will
ever rotate, because nobody knows it is there. So the hash stays empty, which is a
state nothing can authenticate against from either direction: Symfony refuses an
empty presented password before the hasher is reached, and `password_verify()`
against an empty hash is false whatever is presented.

**The link is Symfony's, not ours, and that was the decision worth making
slowly.** `security-http` ships exactly the object this ticket describes: a signed
URL carrying a user identifier and an expiry, verified by HMAC over
`kernel.secret` together with a chosen set of the user's own properties. Writing
an `invitation` table with a hashed token, an expiry column and a controller
comparing digests would have been re-implementing `SignatureHasher`, including the
parts that are quiet to get wrong — comparing in constant time, checking the
expiry before touching the database, and running the user checker on the way in.
It is also strictly worse in one respect that matters here: **a token table stores
something replayable and a signature stores nothing at all.** A dump of a tenant
database carries no invitation anybody can use.

What is left over after taking the framework's version is small, and it is the
honest departure to declare:

- **An invitee has no password, so a login link is not sufficient by itself.** It
  gets them through the door; `must_change_password` and the listener behind it
  then hold them at `/account` until they have chosen one. Both existed already,
  for generated passwords, and neither needed changing — the feature composes out
  of parts that were here, which is most of the argument for this shape. The one
  thing that did need adding is that the account page cannot ask an invited person
  for their *current* password, because there is none; what stands in for that
  proof is the signed link they arrived on, and the manager refuses that path
  outright for an account that already has a password.
- **A stateless link cannot be revoked, and an invitation has to be revoked
  twice** — when it is used, and when a second one supersedes it. That is what
  `app_user.invitation_seed` is for: one of the signature properties, so rewriting
  it invalidates every link already in a mailbox, and rewriting it is one
  `UPDATE`. It is not the token — it is one input to an HMAC keyed with the
  application secret, so what is written down is not a credential.

**Symfony's own answer to single-use was considered and rejected.** `max_uses` is
enforced with a *cache pool*, and a cache is evictable — an eviction would quietly
restore a consumed invitation. A security property that un-enforces itself under
memory pressure is not one. The seed does the same job in the tenant's own
database, transactionally with the acceptance, where it cannot evaporate.

**Inviting somebody twice retires the first link and restarts the 24 hours.**
There is never more than one live invitation per person. The alternative — letting
both run — would mean "I sent them a new one" was not a way to fix an invitation
that leaked, which is the situation the feature most needs to have an answer for.
Reissuing has to exist at all because 24 hours is short: somebody who reads their
mail on Monday cannot be told to have read it on Sunday.

**The seed is spent after the user checker, not before.** Acceptance rotates it
from a listener on `LoginSuccessEvent`, by which point `ActiveUserChecker` has
already had its say — so a deactivated person's click is refused *and does not
consume their link*. Reactivating them inside the window makes the invitation they
were already sent work, instead of having silently burnt it on a refusal they
never saw. Deactivation is covered from both ends: the link is refused at the
door, and an invitation is refused at the point of sending, because a link that
would be turned away is a promise the sign-in page then breaks.

**An invitation is not offered for an account that already has a password.** It
signs its holder in without one, so offering it there would make "invite" a
quieter version of "reset password" that the account owner never sees happen.
Resetting is the tool for that and always was. The converse escape hatch is
deliberately left open: an account awaiting an invitation can still be given a
generated password, which is the way out of an installation whose mail is not
working yet.

**A refused link lands on the sign-in page and says so**, rather than answering a
blank 403 to somebody who has no account here to sign in to. Symfony's own
sentence names the cause and one line of ours says what to do about it. A
deactivated account gets `ActiveUserChecker`'s message instead and deliberately
*not* the offer of a fresh invitation — that would send them back to somebody who
cannot help until the account is reactivated.

**The mail goes out through `TenantMailer` with no exception carved for it**, so a
customer with their own SMTP server sends it from their own address and a customer
without one sends it through this instance (§8.7). The argument for the other
answer is real and was weighed: an invitation is a message *about an account on
this installation* rather than a customer's correspondence with their customer, so
the instance identity is arguably the truthful one. It loses on three counts. The
recipient is a colleague at the customer's own company, which makes it their
internal mail and not ours; §8.7's whole point is that **one** place decides who a
message is from, and a second rule is a second thing to disagree with the first;
and the case that would have needed the exception is already covered without one —
a freshly provisioned tenant has configured no SMTP, so the instance fallback
applies of its own accord. Which is XIV-64's first user, where §8.7's "works on day
one" and this feature meet.

**The message is a system message and its content lives in code** — the ordinary
translation catalogue, in the frame every other email from this application uses
(§5.13). Not an XIV-38 email template, and each of that mechanism's three defining
properties is a reason why: those are per-module and this belongs to none, they are
customer-facing and this goes to a colleague, and they are tenant-editable — where
a tenant who edited the link out of this one would lock somebody out of an account
they have no other way to reach. It also has to work for a tenant that has
installed nothing and written nothing, which is exactly XIV-64's first user again.

**This is a dependency of XIV-64, not a nicety.** Self-service signup provisions a
tenant with nobody watching a terminal, so there is no screen to print a first
password to — and sending from a console command turned out to need the firewall's
login-link handler named rather than autowired, because the autowired one works
the firewall out from the current request and throws when there is not one. What
still has to be answered when that ticket arrives is the router's request context:
a URL generated off a cron has no hostname to be absolute against, and a tenant's
hostname is the one thing that link cannot get wrong.

**That sentence needs one correction now XIV-64 has landed** (§8.12), because it
predicted the wrong ticket. Signup does not provision, so nothing here is invoked
by it: the first user is created when [XIV-98] turns a confirmed signup into a
tenant, and that is where the request-context problem is still waiting.

**[XIV-98] has landed and answered it** (§8.14): the router's request context is
pointed at the tenant's own hostname for the duration of the send and restored
afterwards, because that loop runs over many customers in one process and a
leaked context would sign the next person's link for the previous person's
domain. The port is left as `DEFAULT_URI` put it, on the argument that the host
is the part only the tenant can supply and the port is a property of the
installation. The same ticket found a second thing this paragraph did not
predict: off a cron there is no locale either, and the answer is the language the
visitor was reading the signup form in.

The signup's own confirmation mail is deliberately none of this mechanism — there is
no tenant, therefore no user to sign a link for — and it builds its absolute URL
from configuration rather than from a request for the same reason this paragraph
warns about.

### 8.9 An operator is not a tenant user (XIV-57)

Everything above this section is about people who belong to one customer. §8.1 puts
them in that customer's own database and binds the security provider to the tenant
entity manager, so *who is admin@example.com* is a question only one database can
answer; §8.2 stamps the session with the tenant it was minted for, because those
identifiers collide across customers. Neither of those is a precaution. They are
the reason a cross-tenant leak is structurally impossible here rather than
carefully avoided: a request resolves exactly one tenant and can only ever see that
one.

**An operator is the first identity that does not fit that shape**, and it does not
fit for a reason that cannot be engineered away: their subject matter is *the set
of tenants*. Somebody who has to look at the registry, provision a customer or read
why a migration failed is by definition not about one customer, so there is no
tenant database that is the right place to keep them.

So: **an operator is a row in the control-plane database** — its own entity
(`Xivi\ControlPlane\Entity\Operator`), its own provider, its own firewall, its own
host. Nothing about a tenant user changes.

#### Two alternatives, rejected

**A promoted user of a designated tenant.** Nominate one customer's installation as
the administrative one and give a `ROLE_OPERATOR` there platform-wide powers. It
needs no new table, no new firewall and no new host, which is the whole of its
appeal. It is rejected because it makes one customer's database the key to every
other customer's: whoever can write to that tenant's `app_user` table — a bug in a
user screen, a stray SQL grant, a compromised administrator of what might be the
smallest customer on the platform — is an operator. It also inverts the ownership
the rest of §8 is built on. The rows in a customer's database are the customer's,
and an identity that governs their competitors is not.

**No accounts at all.** Bind the control plane to loopback and reach it over an SSH
tunnel, or put an authenticating proxy in front of it. Honest for exactly one
operator on exactly one machine, and it is a real answer for that case — which is
why it is worth recording rather than dismissing. It is rejected because the second
operator turns it into a migration: at that point there is no way to say who did
what, no way to remove one person's access without rotating everybody's, and the
work of adding accounts has to be done anyway, on a system that has since acquired
screens built on the assumption that whoever reached them is trusted.

#### The invariant: the firewall's *order* is the security boundary

The `main` firewall has no `host:` restriction, so it matches every request, and
Symfony takes the first firewall whose matcher accepts. **The control-plane
firewall is therefore declared above it, and that ordering is the boundary.** Move
it below and a control-plane sign-in falls through to `main`, whose provider is
`tenant_users` — so an operator's password would be checked against
`app_user` in whichever customer's database the hostname resolved to. That is
precisely the leak §8.1 and §8.2 exist to prevent, arriving through a line moved in
a YAML file rather than through a design mistake.

A comment saying "do not reorder these" is read by everybody except the person who
reorders them, so `ControlPlaneFirewallTest` asks the **compiled firewall map**
which firewall takes a control-plane request and which provider it would
authenticate against, and `ControlPlaneSignInTest` gives the same email address a
different password on each side and proves the tenant's one is refused. The
ordering fails the build rather than shipping.

**The firewall is host-scoped by a request matcher rather than by `host:`.** That
key is a regular expression, and a hostname written into one is a pattern in which
every dot matches any character — `control.example.com` also accepts
`controlXexample.com`, a name somebody else can own. A matcher comparing
normalised strings uses the same normalisation tenancy uses to decide that a host
is served without a tenant, so the firewall matches exactly the host on which no
tenant resolves.

#### Where it is served, and what makes it resolve no tenant

`CONTROL_PLANE_HOST` names it, and that parameter is written into
`app.system_hosts` in `config/services.yaml`. That is the whole mechanism for "a
control-plane request resolves no tenant" — §4's existing one, not a second: the
tenancy listener checks that list before it consults the registry, clears the
tenant connection and leaves it deliberately unusable. Reusing it rather than
inventing a parallel rule is what stops the two from ever disagreeing, and it means
the deployment step is one variable rather than two things to keep in step (see
*Running an installation → Hostnames* on the documentation site).

Provisioning refuses to route a tenant to any host on that list. Without the
refusal the mistake is silent in the worst way available: the row is created, the
tenancy listener never consults it, and that customer's users are shown the
platform's sign-in page instead of their own.

##### The hostname is not one of the boundaries (XIV-93)

Written down here because the paragraphs above read as though it were, and
because [XIV-93] was reported from inside this ticket rather than worked around.

**Anybody who can set `Host:` to the control-plane hostname reaches the
control-plane sign-in page**, from any address that terminates the connection,
not only from the name DNS points at. That was true before this application
configured `framework.trusted_hosts` and it is still true after: the
control-plane host is by definition one of the hostnames this installation
answers to, so it is *inside* the trusted-host pattern rather than outside it, and
a pattern cannot tell a request that arrived on the right address from one that
wrote the right string in a header. §4.3 has the full argument, including why
nothing about the topology in §4 can change it.

It is not a leak, and the reason is the three layers below rather than anything
about the name. The credential presented there is answerable only by the control
plane's own provider, no tenant resolves on that host, and `access_control` wants
a role no customer's database can grant — every one of which applies to a request
that arrived with a forged `Host` exactly as it applies to one that did not. What
the hostname buys is obscurity, which is why this section still asks for one that
is not guessable from the customer-facing domain.

**So "no tenant hostname can reach a control-plane route" is a narrower
guarantee than it reads.** What holds is that no *route* exists on another host
and no *tenant credential* is answered here. What does not hold, and never did,
is that only requests genuinely addressed to the control-plane name arrive at it.

Three layers keep a customer away from a control-plane route, and they are worth
distinguishing because only the first two are boundaries:

1. **The route does not exist on their hostname.** `ControlPlaneRequestListener`
   answers 404 for `/control/…` anywhere but the control-plane host, and for
   anything *but* `/control/…` on it. A 404 rather than a 403, because the path
   really is not there and because a 403 confirms there is something worth being
   refused from. It is a listener rather than a `host:` on the routes only because
   Symfony forbids environment placeholders in routing configuration, so a
   host-scoped route would have to carry a hostname compiled into the source.
2. **The credential is answered by the control plane.** The firewall above.
3. **`access_control` asks for `ROLE_OPERATOR`.** Third, and the weakest of the
   three by construction: a role is a string in a customer's own database, and
   nothing stops a customer's administrator writing `ROLE_OPERATOR` into their own
   row. The test suite creates exactly that person and proves they are still
   nobody here. Correspondingly, an operator holds **no `ROLE_USER`**, so the `^/`
   rule that guards the tenant application refuses them — which is why an operator
   who wanders towards a tenant screen is told no rather than collecting a 500
   from a connection that has no tenant behind it.

##### A fourth layer, and it is the only one in front (XIV-124)

The three above are all checks made **after the request has arrived**, by the
surface that can see every customer. That is not a criticism of them — they are
the layers that decide who gets in, and they hold against a forged `Host`
exactly as they hold against a real one. It is an observation about what was
missing, which is anything at all in *front*: until this ticket, the operator
console was a password prompt that the whole internet could attempt, and the
paragraph above says in as many words that no hostname setting changes that.

**`CONTROL_PLANE_ALLOWED_IPS` is a list of addresses and CIDR ranges, and a
request to the control-plane host from anywhere else is refused before anything
else looks at it** — at `kernel.request` priority 101, ahead of both the tenancy
and control-plane listeners, so an address that is not on the list cannot make
this installation consult its registry, resolve a route, touch a session or build
a firewall listener.

**It is the outermost of four and a replacement for none of them.** As the only
layer it would be bad design: an address is a claim about a network, and networks
are borrowed, shared behind one office NAT and spoofable on unfiltered paths. As
the fourth it is worth having, because it turns "anybody may attempt a password"
into "anybody on this list may attempt a password". Nothing about the firewall
ordering changed.

**Empty is the default and means no restriction**, which is `PlaceholderSecretGuard`'s
rule (§4.2) and `TrustedHosts`' (§4.3) for their reason: `bin/compose up`, the
suite and `bin/ci` all run on addresses no operator would ever write down.

###### The address comes from Symfony, and that is the whole of the design

`REMOTE_ADDR` is the *proxy's* address when there is a proxy, so the address this
is decided on is `Request::getClientIp()` — which consults `X-Forwarded-For` only
from an address in `TRUSTED_PROXIES` and only because §4.3 decided to believe
that header. **Nothing here reads a header.**

That is not a smaller version of the same thing. An allow-list built on a header
anybody can set is **worse than no allow-list at all**, because it admits
everybody who has read this repository while looking exactly like a restriction
to whoever configured it. Two tests hold it from both sides and only mean
anything together: with nothing trusted in front — the shipped topology — a
forged `X-Forwarded-For` naming an admitted address is ignored and the caller is
refused on the address their connection came from; with `TRUSTED_PROXIES` naming
that connection, the very same header *is* believed and the same caller is
admitted. The first alone would also pass for a listener that never looked at
forwarded headers, which would be wrong in the other direction: a deployment
behind a balancer would have to allow-list the balancer, which admits everybody
behind it.

###### A refusal says nothing and the log says everything

An empty **403**, which is the line `UntrustedHostListener` already draws for
§4.3's 400 rather than a second convention. Whoever is refused is by definition
not somebody this installation admits, and a body naming the variable — or
admitting that an allow-list exists — would be telling the one audience that
should not be told.

A 403 rather than the 404 `ControlPlaneRequestListener` uses beside it, because
those are different sentences: that listener answers 404 because the path
genuinely is not there on that host, and here the path is there and the caller is
not welcome. The distinction is for the operator who has locked *themselves* out,
who otherwise sees exactly what they would see if the control plane had never
been deployed. It covers every path on that host, including the assets and
profiler paths `ControlPlaneRequestListener` deliberately stands aside for: those
are exempt from *which host serves them*, not from *who may connect*, and one
answer for every path is also one that draws no map.

The `error` line **names `REMOTE_ADDR` beside the resolved address**, which is
the part that pays for itself: when the two are equal on an installation the
operator swears is behind a load balancer, `TRUSTED_PROXIES` has not been set and
every request in the installation is being attributed to the balancer. That
presents as "the allow-list refuses my office and my office address is correct",
and this line answers it in one glance.

###### Where it is enforced, and the one that was not built

**A listener, not the Caddyfile.** The web server is genuinely stronger — a
request refused there never reaches PHP — and the two are not exclusive, so
*Running an installation → The control plane* on the documentation site shows the
Caddy block for an operator who wants both. What shipped is the application's,
for three reasons about this codebase rather than about web servers. It **travels
with the code**, where a rule in a separately maintained Caddyfile can be a
release behind or absent with nothing here able to tell. It is **testable**,
which the assertion about a forged header above depends on entirely. And it
**inherits `TRUSTED_PROXIES`**, where Caddy would need to be told separately, in
its own syntax, which upstream may speak for a client — a second copy of §4.3's
decision and therefore a second place for it to be wrong.

###### Locking yourself out is the cost, and it is named rather than solved

**An operator who sets this wrong cannot sign in to fix it**, and unlike a
too-narrow trusted-host pattern there is no customer-facing symptom to notice
first: every customer keeps working, every dashboard stays green, and the only
sign is a 403 on a console one person visits — at whatever hour they next need
it, which by the nature of consoles is an hour when something is already wrong.

Three things reduce it and none of them removes it: `deploy:check-control-plane`
reports the list before anything depends on it, on the `deploy:check-*` family's
exit-code convention; an entry that is not an address is dropped and remembered
rather than switching the restriction off, because a restriction that silently
stops restricting is this whole layer's failure mode and *throwing* would be a
500 for every customer over one mistyped character in a variable about the
operator's own console; and the console is always the way back.

What is *not* claimed is that this is safe to set unattended. **An operator who
never runs the check can still lock themselves out**, and the honest mitigation
is that the door back in is a shell rather than a browser. That is the cost of
the layer, it is accepted rather than engineered away, and it is written down
here so that nobody has to rediscover it at two in the morning.

###### Deliberately not in this

**No per-operator addresses.** The list is the installation's, not an account's.
A column on `operator` would be a second place for the answer to live and would
be consulted only after the credential has been read, which is a layer in the
wrong place — the point of this one is that it decides before anything else does.

**No allow-list on the tenant application.** Customers are served on the
internet; that is what the product is.

**No `--fix`, and no way to widen the list from inside the application**, for
§4.3's reason: a running instance that could edit which addresses may reach its
own console could be made to admit anything.

#### Sessions

Separate firewalls have separate session contexts, so a token minted for an
operator is stored under a key `main` does not look for and vice versa. Symfony
would have given that for nothing, since a firewall's context defaults to its own
name — and both are written out in `security.yaml` anyway, because "an operator
session and a tenant session are not interchangeable" is a security property and
one holding only because nobody has changed a default is one line of somebody
else's release notes away from not holding. `TenantSessionGuard` covers the same
ground from the other side: a session stamped for a tenant, replayed on a host that
resolves none, is discarded.

#### An operator can be revoked, and it deactivates rather than deletes (XIV-92)

Everything above builds one account and never touches it again.
`control:operator:create` made an operator; nothing removed one, disabled one,
changed a password, or even said who existed. Every one of those was a statement
typed into `psql`.

§4.1 makes that argument about tenants and it lands harder here. **An operator is
the identity with the most reach in the installation, and it is the last one that
should need a database client to withdraw** — because revoking one is, by
construction, done in a hurry. Somebody has left, or a credential has leaked. That
is not the moment to be composing SQL against a table whose name is being checked
while it is typed.

So there are four commands, and they are one ticket rather than four because the
schema question decides all of their shapes:

| Command | What it does |
|---|---|
| `control:operator:list` | Who can sign in, revoked accounts included and marked |
| `control:operator:revoke <email>` | Withdraws access, keeps the account |
| `control:operator:restore <email>` | Puts it back, with the password it had |
| `control:operator:password <email>` | A new password, without asking for the old one |

##### Deactivate or delete: the argument is not inherited from §8.4.1

§8.4.1 settles this for a tenant user in one sentence — *deactivating locks the
person out, keeps every record attributable, and is reversible* — and **that
sentence does not carry over on its own.** Its load-bearing half is attribution:
records carry the id of whoever owns them and history carries the id of whoever
made each change, so deleting a tenant user leaves records belonging to nobody.
[XIV-57] looked at exactly that and concluded, correctly for the time, that
nothing in the control plane attributes anything to an operator, so revoking one
could be deleting the row.

That is still true today. Nothing in the control-plane schema references
`operator`. If attribution were the only argument, deletion would win, and the
`active` flag would be the promise-nothing-keeps that [XIV-57] refused to make.

Three other arguments carry it instead, and none of them is §8.4.1's.

**Deletion is the one lifecycle step nobody can undo, in the one situation where
people are moving fastest.** The address being revoked is often half-read off a
chat message during an incident. A wrong `revoke` is a sentence and a
`control:operator:restore`; a wrong `DELETE` is an account that has to be
recreated, with a new password, by somebody who now has two problems. That
asymmetry is the whole of why the reversible verb is the one that ships.

**The flag has to arrive before anything references an operator, not after.**
[XIV-98] provisions a tenant from a confirmed signup and [XIV-59]'s collection
surfaces sit next to it; the first column anybody will want on those rows is
*which operator did this*. The moment one exists, a deletable operator forces a
choice between `ON DELETE SET NULL` — an audit trail that erases itself exactly
when somebody is revoked in a hurry — and a foreign key that refuses the
revocation, which turns revoking back into a `psql` job. Both of those are
discovered with the schema already in production, and the migration that fixes
them is run at the worst possible time. Landing the column first costs one
`ALTER TABLE` today.

**And two answers to "somebody left" in one codebase is a cost by itself.** A
reader who knows how a tenant user is removed should not have to check whether an
operator works the other way round.

**What is given up** is that a row created by a typo is now permanent. The answer
is that `control:operator:list` makes it visible rather than invisible, that the
row holds a name, an address and a hash and nothing else, and that a second
irreversible command sitting next to the reversible one is the command somebody
types by accident. There is deliberately **no** `control:operator:delete`; §8.4.1
does not offer a delete button either, and shipping one here would make the flag
optional in the same week it was introduced.

##### What a revoked operator can still reach: nothing, and it took two mechanisms

**`Operator::active` is enforced twice, and neither mechanism covers the other's
case** — a checker on the `control_plane` firewall for the sign-in, a listener
for a session that already exists. This is the same pair `User::active` needed
(§8.4.1) and it is here for the same framework reason rather than by imitation:
"Symfony refreshes the user from the provider on every request" is true and
strongly suggests a revoked account falls out at the next click, and it does not.
With the listener removed and the checker in place, a revoked operator's next
request returns 200 and renders the tenant list with their name in the topbar —
every customer's hostname, plan and usage, for as long as the session lasts. That
was watched happening; the listener was written against it.

The duplication between the two sides is deliberate rather than an omission: one
checker reading both a tenant `User` and an `Operator` would be a single object
holding the rule for both sides of a boundary this section spends its length
arguing should have nothing in common, and deptrac would have to be told the
tenant application may reach into the control-plane package to get it.

##### The last operator

**Revoking the last operator who can still sign in is refused, with no `--force`
past it.** The control plane has no sign-up, no invitation and no password reset,
so the web has no way back in at all.

The refusal counts **active operators, not rows**, and that distinction is the
whole of it. Its absence from `tenant:deprovision`'s `--force` (§4.1) is a
deliberate departure: that command needs an escape hatch because removing a
customer unattended is a real operation, and there is no legitimate shape of
"remove the last operator" that this refusal blocks.

It is worth being honest about what the guard is *not*. It is not protection
against a catastrophe — whoever could type the revocation can type
`control:operator:create`, so the console is always a way back. It guards against
the accident, which is somebody revoking the wrong one of two addresses at speed.

Nothing guards self-revocation, because there is no *self* at a console: these
commands run with no session and no signed-in operator to compare against. §8.4.1
refuses an administrator deactivating their own account precisely because that
click comes from a session; the equivalent here would be a guess about who is
holding the keyboard.

##### `control:operator:create` on an existing address stays an error

The convenient reading is that creating over an existing address should just set
a new password — one verb, no second command to remember. It is refused, and
`control:operator:password` exists so that the refusal costs nothing: an
overloaded `create` would make a typo'd address indistinguishable from a
rotation, and would silently reinstate a revoked account through a command that
never mentions revocation.

#### What is deliberately not built yet

**No permission model.** Every operator has `ROLE_OPERATOR` and nothing else. There
is one kind of operator so far, and a read-only or billing-only operator is a
distinction to draw when there is a second kind to draw it against — §8.4's
catalogue exists because modules and verbs were both real by then.

**~~No `active` flag and no way to revoke one from the console.~~ Built** by
[XIV-92], and the section above is what replaced it. The gap was named here on
the grounds that an operator who cannot remove an account from the console will
remove it in `psql`; what shipped is deactivation rather than the
`control:operator:delete` this paragraph anticipated, plus a password change, a
listing, and a refusal to revoke the last operator who can still sign in. There
is deliberately still no delete.

**No screen for any of it.** The four lifecycle commands are console-only, like
the create they join. An operator page is a small step from §8.10's tenant list
and is where these belong eventually, which is why every refusal lives in
`OperatorManager` rather than in a command — the page inherits them instead of
reimplementing them.

**No record of who revoked whom.** There is no actor to record: a console command
has no signed-in operator, and inventing one would mean either an `--as` flag
nobody can be held to or a guess. When there is a screen there is an actor, and
that is the moment to record one — see the argument above for why the `active`
column landing *before* anything attributes anything to an operator is what makes
that cheap to add.

**No invitations and no sign-up.** `control:operator:create` is the only way an
operator comes into existence. Invitations exist on the tenant side (§8.8) because
an administrator has colleagues to admit and no way to hand them a password; an
operator is created at a console by somebody who already has one, and a mailed link
admitting its holder to every customer's registry is not a convenience worth
inventing before anybody has asked for it. The password is **asked for rather than
generated**, which departs from §8.5 deliberately: a generated password is safe
there because `must_change_password` holds the account until its owner replaces it,
and the control plane has no account page to hold anybody on. **That argument now
has a command behind it rather than only an absence** ([XIV-92]): asking for the
password was the right call when there was no way to rotate one at all, and
`control:operator:password` is that way. It does not change the decision — a
generated credential is still one two people know — but it removes the corner the
original reasoning was standing in.

**No page.** Signing in lands on a placeholder that says what it is and what
replaces it, which is [XIV-58], the tenant list. That is the expected shape of this
ticket, not an unfinished edge of it — the same shape `DashboardController` had
before there were modules to show. **That placeholder is gone**; §8.10 is what
took its place.

### 8.10 The tenant list, and the boundary it keeps (XIV-58)

The page an operator lands on is the registry, drawn as a table: name and slug,
status, plan, primary domain, created and provisioned, enabled modules. Every
column of it is a field of the `tenant` row, which is the whole design and is
worth saying out loud rather than treating as a coincidence of what happened to
be easy.

`tenant:list` **still works and was not replaced.** A headless deployment has no
browser, and the command is what somebody has in an SSH session at three in the
morning. What the page adds is not the data.

#### One request, one database — and here that database is the control plane's

**This page opens no tenant connection at all.** §4 makes that sentence true of
every request in the application, and §8.9 makes it true of this host in the
strongest available way: a control-plane request resolves no tenant, so the
`tenant` connection is not merely unused but deliberately unusable, and anything
touching it gets `NoTenantResolvedException` rather than the previous customer's
database.

That property is not a side effect of the columns this page happens to show. It
is the reason [XIV-59] — how many users a customer has, when anybody last signed
in, how many records are in there — is a design problem rather than a `LEFT
JOIN`. Those figures live in the customers' own databases, one connection each,
and a page listing forty tenants that fetched them inline would open forty
connections to draw a column. There are several defensible answers to that — a
roll-up written back to the registry on a schedule, an on-demand figure for one
tenant, an explicit per-row fetch the reader asks for — and **none of them can be
chosen honestly while a join looks available.** The first person who wants "just
the user count" here will find it is one line away and that nothing in the file
physically stops them. What stops them is knowing why it is not there, which is
why the argument is in `TenantListController`'s docblock as well as in this
paragraph.

`TenantListTest` proves it rather than asserting it in prose, and the fixtures
are what make the proof sharp: **the three tenants it lists have no databases at
all** — rows written straight into the registry, with DSNs naming a host that does
not resolve — so a page that connected would not be quietly wrong, it would be
red. Provisioning three real customers would have been the more realistic fixture
and a strictly weaker instrument.

#### The row also carries a credential, and the defence is a type

A `Tenant` holds `database_dsn` and `database_password`. Neither belongs on this
page or in its HTML, and neither ever arrives there on purpose: it arrives as a
`|json_encode` into a Stimulus data attribute, a `dump()` left in a template, a
serializer normalising an entity for a fragment, a profiler panel on a page
somebody pastes into a chat. Every one of those is a mistake that reads as
harmless while it is being made, so "be careful in the template" is not a
control.

**So the entity never reaches the template.** A readonly view object with a
private constructor and one static factory is the single place in the codebase
that reads a `Tenant` for this page, and it does not read those two columns. Dump
it, encode it, hand it to a JavaScript component: there is no credential in it.
That is a property of the type rather than of whoever edits the template next,
which is the only kind worth having.

The test asserts it from the other side anyway, over the headers as well as the
body, and looks for the DSN's *parts* as well as the whole so that a "which server
is this customer on" column parsed out of the DSN still fails. `TenantLogoTest`
set exactly this shape in XIV-49, for a tenant settings row that holds an SMTP
password beside the one column that is deliberately public. Both halves are
wanted: the type makes the leak impossible, and the test notices when somebody
decides the entity would be more convenient after all.

#### Status is designed around, not printed

A registry sorted by name is one in which a tenant stuck in `provisioning` since
Tuesday sits on the third screen between two healthy customers, in a cell that
looks like every other cell. Provisioning is measured in seconds, so a tenant
found in that state by somebody loading a page is not mid-flight — it is what a
run that died halfway leaves behind (§4.1), and it is the single thing an operator
wants to see from the doorway. So the table is ordered by how much a row wants
explaining and then by name, and the page opens with a line saying how many
customers are not being served and naming them — drawn only when that number is
not zero, because a banner permanently reading "0 customers are not being served"
is furniture and furniture is what the eye learns to skip. "Not being served"
rather than "broken" because it is a fact rather than a judgement: a suspended
customer belongs in the same count as a provisioning run that died.

**Rejected: computing "stuck" from a threshold** — `provisioning` with
`updated_at` older than a day, drawn as a warning. It is the obvious reading and
it is not built, because the threshold would be fiction. A tenant provisioning for
twenty-three hours is exactly as broken as one provisioning for twenty-five, and a
line drawn between them teaches the reader that everything under it is fine. What
the page says instead is weaker and true: this customer is not being served, the
row was created *then*, and it was provisioned — for a stuck tenant — never. That
is a date beside an em dash, and it reads as what it is. The reader supplies the
judgement, which is the half of "has it moved in a day" that no constant in this
repository could supply honestly.

**Rejected: a separate page, or a filter, for the unhealthy rows.** Both put the
thing worth seeing behind a click on a page whose entire job is that nobody has to
go looking. The cost of the ordering chosen instead is real and small: looking one
customer up by name now means finding them in the second group rather than in
strict alphabetical position. The registry is one row per customer, so this is a
list of tens; grouping a list of tens by state is a reading order, not an
obstacle. When it stops being tens the answer is a search box and paging, not a
different sort — and paging is the moment the ranking has to reach SQL, which is
when duplicating it as a `CASE` becomes a cost worth paying for a reason rather
than by default.

#### Two smaller decisions the ticket left open

**A tenant with no hostname is shown, not skipped.** `findAllWithDomains()` uses a
`leftJoin` where `findOneByHostname()` uses an inner one, because provisioning
writes the registry row before it routes a domain to it — so a run that died in
between leaves exactly a tenant with no domains, and an inner join would silently
omit the row this page is most needed for. It draws an em dash, which is the
honest rendering.

**The modules column is what the control plane believes, not what the customer
has.** §6.1 makes those two able to differ, and reconciling them means reading
each tenant's own metadata, which is a tenant connection this page does not open.
The column is `tenant.enabled_modules` and nothing else.

**[XIV-95] answered that without weakening it**, and the shape of the answer is
the point: the reconciliation happens where a tenant connection is already open —
in [XIV-59]'s collector, which was reading the customer's installed modules
already to know which shapes to count — and the page reads the result out of the
control plane like every other value here. The column now shows what the customer
*has*, names where that disagrees with what the registry says in both directions,
and carries the age of the collection it came from. See §8.11.

**No lifecycle actions.** Provision, suspend, migrate, rotate and deprovision all
have working commands, several of them with refusals and confirmations that a
button would have to reproduce (§4.1 is an essay about one of them). A page that
lists customers and a button that destroys one are different kinds of thing, and
the second gets its own ticket when somebody wants it.

### 8.11 What a tenant actually uses (XIV-59)

Three figures per customer: how many users they have, when anybody last signed
in, how many records are in there. Enough to tell somebody who is using this from
somebody who provisioned in March and never came back — which the registry alone
cannot say, because every column of it describes the *arrangement* with a
customer rather than what they do with it.

The data all exists. `User::$lastLoginAt` is written on every sign-in (§8.1), so
"last login" is `MAX(last_login_at)`; records are one table per module shape with
a soft delete, so a count is `COUNT(*) WHERE deleted_at IS NULL` per installed
shape. **None of it is in the control plane, and that is the whole ticket.**

#### The fan-out is the problem, and it is not mainly about speed

§4 is a database per customer, so there is no query that answers this for all of
them: fifty tenants with six modules each is fifty connections and three hundred
counts. On a page whose entire purpose is to be opened when somebody is already
worried, that is bad enough on its own.

The larger objection is what it would make true. **It would be the first thing in
the system that deliberately touches many tenants in one request.** §7.4's
guarantee — one request resolves one tenant, and the runtime keeps no state
between requests — is not a rule somebody follows; it is a *consequence* of how a
request works here, which is why the tenant connection on a control-plane host is
not merely unused but unusable (§8.9). A page that opened fifty tenant
connections would turn that consequence into a case-by-case argument, and the
second such page would not have to make the argument at all.

#### Decided: collect periodically, and let the page read the control plane

`tenant:usage:collect` walks the registry **one tenant at a time** through
`TenantSwitcher::runFor()`, writes what it finds into the control plane, and the
tenant list reads that table exactly as XIV-58 reads the registry. One request,
one database, still literally true — and XIV-58's proof that the page opens no
tenant connection passes unchanged, which is the test that would have gone red if
this had been built the obvious way.

**Periodically means a console command and the deployment's cron, not a queue.**
There is no worker process here and no consumer to supervise — the same
constraint that settled synchronous sending in [XIV-37]. A queue would add a
runtime component to a system that has none, for a job that takes seconds and
that nobody is waiting on.

**A run that fails for one tenant records that it failed for that tenant and
carries on.** One unreachable database must not cost the other forty-nine their
figures — but the run still exits non-zero, because under cron the exit status is
how anybody finds out at all.

**Each tenant's connection is closed before the next is opened**, which is not
tidiness: a collection run that sat attached to every customer's database at once
would be the reason an operator's `tenant:deprovision` fails ([XIV-94]). The
collector would have become the thing that blocks the operator, which is the
opposite of a tool for operators.

**The counting is shared with `tenant:deprovision`, not copied.** That command has
asked the same question since XIV-72 — it prints how much is in there before it
destroys it — so "switch to the tenant, read its own metadata, count each shape"
has one implementation and both callers use it.

#### Where the figures live: their own table, not columns on `tenant`

`tenant_usage`, one row per customer, and the argument is that **a row there is a
collection rather than a customer**: `collected_at` is not an extra column but the
column the others are relative to, a failure is a fact about the run rather than
about a customer whose `tenant.failure` would read as broken, and a customer
nobody has collected yet has **no row at all** — which five nullable columns on
`tenant` could not have said without a sixth meaning "the nulls above are real
nulls".

The association points one way only — `TenantUsage` knows its tenant and `Tenant`
knows nothing — because Doctrine cannot lazily load the inverse side of a nullable
one-to-one, so every `Tenant` hydrated anywhere would fetch a usage row nobody
asked for. The page fetches all the collections in one query and matches them by
slug: two queries against one database.

*This table has a sibling since §8.15 ([XIV-102]).* `purchase_intent` is filled by
`tenant:purchase:collect` on exactly this pattern, for exactly this reason — a
customer's request to buy a module is written into their own database because
§4.4's grant leaves nowhere else for a customer's write to go, so an operator sees
it the same way they see these figures. Every argument in this section transfers
and none of it is restated there; what §8.15 adds is why the alternative shapes,
which look cheaper, are not.

#### A stale figure presented as current is worse than no figure

The page shows the collection time beside the numbers, and it distinguishes three
states rather than two: *not collected yet*, *could not be read, tried at …*, and
the figures with their timestamp. **Zero and "we could not count" must not look
alike** — the same rule [XIV-39] drew for a mail that was not sent, one screen
along.

**A failed collection drops the previous figures rather than keeping them beside
the failure.** Keeping them would be more information and the wrong kind: the
numbers would then be as old as the last *success* while the timestamp beside them
says the last *attempt*, and a reader who takes in one and not the other has been
misled by the screen rather than by their own carelessness.

**The stored failure is the exception's class, never the driver's message.** A
connection error names the host, the port and the role — and §8.10's whole defence
is that a `Tenant`, and therefore a DSN, cannot reach an HTML page. Storing the
driver's words would smuggle those parts back in through a table whose rows are
rendered, waiting for somebody to print them "just for debugging".

#### Counts, not contents — and why the line is exactly there

An operator page exists to say **how much**, and the moment it says **what**, the
control plane has become a way to read a customer's data without their knowledge.
That is not a slippery-slope argument, it is a one-line argument: the code that
opens a tenant connection to count rows is a `SELECT *` away from selecting them,
and every seam here is shaped so that the tempting change is also an obvious one —
the counter can only return integers, and the user figures come back as one
aggregate row rather than as a `findAll()` that would pull every customer's names,
emails and password hashes through the control plane's process to reach the same
two numbers.

The one value here that is not a count is `MAX(last_login_at)`, which the ticket
asked for by name and which identifies nobody: it says somebody was here on
Tuesday, not who. That is the boundary, and anything past it — a name, a record
title, a "show me what they have" link — needs a different justification from
this one and does not have it. A customer's data belongs to the customer; a
platform that can read it whenever it likes has made *isolation* a claim about
intent rather than about architecture, which is the thing §4 exists to avoid.

#### What a tenant actually has installed, and where that disagrees (XIV-95)

§8.10 drew the modules column from `tenant.enabled_modules` and said out loud
that this is *what the control plane believes* rather than what the customer has.
§6.1 is why those are different sentences: once a module is installed the
customer's own definitions are the truth, installing does not retro-fit, and
`tenant:module:install` writes a tenant's metadata without touching the registry
row. So the column was honest and incomplete, and completing it meant reading
each customer's own metadata — a tenant connection that page does not open.

**The collector was already reading it.** `RecordCounter` walks
`MetadataRepository::all()` inside `TenantSwitcher::runFor()` to know which
shapes to count, so the real installed list was being read once per collection
and thrown away. It is now written to `tenant_usage.installed_modules` beside the
figures, under the same `collected_at`, and the page reads the control plane as
it always did. XIV-58's proof that the list opens no tenant connection passes
unchanged, which is the same test that would have gone red had this been built
the obvious way.

#### The disagreement is the useful part, and it is not stored

Three ways the two lists drift, all real: a module installed from a console that
provisioning never recorded; a module in `enabled_modules` whose tables a run
that died part-way never created (§4.1); and a module whose definitions the
customer has since diverged, which §6.1 makes their prerogative. An operator
looking at a customer that behaves oddly wants that answer without opening
`psql`.

**The comparison is made when the page is drawn, not when the collection runs.**
Storing the difference would have been one array instead of two and no work at
render time, and it would be a comparison between a database read last night and
a registry column anybody can change this morning: an operator who enables a
module at ten would go on being told at eleven that the registry does not know
about it, and one who disables a module would be told everything agrees. Half of
this comparison is genuinely current and half genuinely is not. So the row stores
only the half that was *observed*, and the page says how old it is.

The corollary is that a failed collection drops the installed list exactly as it
drops the figures. Keeping the last known list beside a failure would put an
observation from the previous run under a timestamp describing this one — and
this cell would then draw a module the tenant may no longer have as a
disagreement with the registry. **Drift invented by a stale row is the one thing
this cell must never report**, because a real one is meant to send somebody
looking.

For the same reason the installed list is read from the metadata rather than
taken from the keys of the per-module counts, which happen to be the same strings
today. Deriving it would make *what a customer has installed* a by-product of how
counting is implemented: the first time the counter learns to skip a shape, that
module vanishes from the installed list and the page reports a difference that
does not exist.

#### A difference is information, not a fault

**Nothing about drift is drawn as an error**, and that is a decision rather than a
styling choice. A module installed by hand is a legitimate state that somebody
chose; §6.1 says a customer's definitions win once installed. A page that told an
operator off for it would be a page they learn to stop reading — the same failure
§8.10 describes for a banner permanently saying "0 customers need attention". So
the cell names the two directions and stops: *not recorded* for a module the
tenant has that the registry does not list, since the control plane is the thing
that failed to write it down; *not installed* for the other way, named from the
customer's side because that is whose experience it is.

**Reconciling the two lists is a different feature with a much higher bar**:
writing the registry from a tenant's metadata means deciding which side is
authoritative, and §6.1's answer to that question is "the tenant, and the registry
is an arrangement" — which is not obviously what an operator pressing a button
would expect.

#### Where the per-module counts went, and why the row still fits

XIV-59 stored `records_by_module` and showed it in a `title` tooltip. A tooltip is
invisible on a touch screen and to a screen reader, so the answer to *of what* was
reaching a mouse and nobody else — and this ticket was drawing per-module
information into the same table anyway. Deciding it twice would have produced two
answers. So the counts moved out of the tooltip and onto the module names they
belong to, and a long list folds into a `<details>` — a control that a keyboard
reaches and a screen reader announces, which is exactly what the tooltip it
replaces was not. The ordering is what makes the folding safe: modules the two
sources disagree about sort first, so what folds away is only ever something both
sides already agree on. Same argument as §8.10's row ordering, one cell down.

**Rejected: truncating with an ellipsis.** It hides the end of whichever line is
longest, and the end of the line is where the disagreement is named — replacing a
hover with a different thing you cannot read.

#### The line is unchanged

Names and counts, never contents. Which modules a customer has and how many
records are in each is *how much*; what is in them is *what*. Reading a module's
definitions to learn its key is on the permitted side of that line — a
`ModuleDefinition` in hand is the whole shape, fields and collections and their
fields, and exactly one string of it leaves the collector. A field label would not
be, and a record certainly is not.

### 8.12 A public surface that provisions nothing (XIV-64)

Self-service signup is the first thing in this system reachable by somebody who is
nobody: no tenant, no account, no session, no invitation. Everything above this
section is about people who already belong somewhere — a customer's user (§8.1), an
operator (§8.9) — and the machinery that identifies them assumes it. None of it
transfers, and pretending it does is how this feature gets built wrong.

#### The naive shape, and why it is not a matter of being careful

A signup form calls something that creates a customer. The thing that creates a
customer here is `TenantProvisioner::provision()`, and it connects with
`TENANT_ADMIN_DSN` — the credential its own docblock describes as *"allowed to
CREATE DATABASE and CREATE ROLE; provisioning only"*. Wiring a public form to it
puts the most privileged operation in the system **one anonymous HTTP request away
from the open internet**, where the only things between the two are the parsing,
the authentication and the slug rules in front of it. Every one of those is code
somebody will change.

**So the endpoint records a signup and does nothing else.** One `INSERT` into one
table, one email, and no elevated credential anywhere in its reach. Turning a
confirmed row into a customer is [XIV-98], and it runs where an operator can see
it. That separation is not sequencing — it is what the ticket is for.

**And the claim is deliberately narrower than it sounds.** What is delivered here
is a **code** boundary: a separate service, its own table, its own controllers,
and no provisioner reachable from any of them. `SignupEndpointTest` walks the
constructor graph behind both controllers and asserts that neither
`TenantProvisioner` nor `TENANT_ADMIN_DSN` appears. It is **not** yet a privilege
boundary. There is one instance and one set of environment variables, so the
process that answers this request holds `TENANT_ADMIN_DSN` whether or not anything
in it reads the variable. Making the public surface a process that does not have
the credential at all is [XIV-96]. Saying "the endpoint cannot create a database"
without that sentence attached would be claiming a guarantee that does not hold
yet.

#### Confirmation is a pre-tenant identity, and none of §8.8 transfers

An address typed into a form proves nothing: anybody can type anybody's. So the
signup is confirmed by email before it holds anything, and this is the gate rather
than a nicety — without it the endpoint records names on behalf of people who never
asked.

[XIV-1]'s invitation is the nearest thing already built and it is unusable here,
for a reason that is structural rather than inconvenient: a login link is an HMAC
over a `UserInterface` **loaded from a provider**, and the provider for tenant
users is bound to a *tenant's* database (§8.1). There is no tenant. There is no
`app_user` row. Inventing one so the framework's helper could be used would mean
creating an account for somebody who may never confirm — which is precisely the
thing confirmation exists to avoid.

So the token is the control plane's own, and it is a **stored digest**: full
entropy from `random_bytes` in the link, SHA-256 of it in the row, never the token
itself. §8.8's objection to a token table was that *"a token table stores
something replayable and a signature stores nothing at all"*, and that objection
is answered by hashing rather than by not storing — a dump of the control-plane
database carries nothing anybody can present. The window is twenty-four hours, the
same an invitation gets, for the mirror image of §8.8's argument: there the
mitigation was that an administrator could send another, here the person can
reissue it themselves by submitting the form again, so the same window costs less.

`UriSigner` was the third candidate and loses to the requirement below: a signature
over an id and an expiry cannot be invalidated when a second submission supersedes
the first.

**A second submission from an unconfirmed address is a resend, not a conflict.**
The row is rewritten in place — new company name, new slug, new plan, new token,
new twenty-four hours — and the previous link stops working with the same write,
because the digest it is checked against has been overwritten. This is §8.8's
invitation rule reached from the same argument: *"I asked for another one"* has to
be the way to fix a mail that went to spam, and it is not if the first link is
still live in whatever mailbox it reached. Treating it as a conflict instead would
mean the only way out of a confirmation that never arrived is to own a second email
address.

**A second submission from a *confirmed* address is refused.** At that point the
address is holding a name and the second request is asking for a second
installation, which is a real request and not this endpoint's to grant quietly. One
confirmed address, one unprovisioned signup — see the abuse argument below for what
that buys.

**Following the link twice changes nothing, and that is the design rather than a
tolerance.** Confirmation is idempotent: the second call finds the row already
confirmed, keeps the moment of the *first* click, re-reserves nothing and sends
nothing. A single-use token would have been the reflex, and it is wrong here for a
reason that has nothing to do with attackers — people click twice, mail gets
forwarded, and any company with a mail gateway has a link scanner that fetches
every URL in a message **before its recipient sees it**. A single-use link is burnt
by the scanner and the human is told it is invalid. What actually makes a replay
worthless is that there is nothing to replay, and the token still expires and is
still superseded.

**The confirmation mail comes from the instance identity, not from a tenant's
SMTP.** §8.8 refused to carve an exception to §8.7 for the invitation, with a good
argument: one place decides who a message is from. This is not an exception to that
rule, it is a message the rule cannot be applied to — `TenantMailer` asks the
current tenant's profile whether they have their own server, and there is no tenant
to ask.

§8.7's fallback transplants exactly, though, and it is worth following because the
first version of this feature got it wrong. There, an empty `MAILER_SENDER` sends
from `no-reply@` at the *tenant's own primary domain*, and the argument for why
that is honest rather than a guess is that the hostname **is** this installation as
far as that customer is concerned. Replace the tenant with the signup host and the
sentence still holds: `SIGNUP_HOST` is the name the prospective customer's site
posted to and the name their confirmation link points at. So an empty
`MAILER_SENDER` means `no-reply@` there, and signup adds no deployment step at
all. The rejected alternative — requiring `MAILER_SENDER` whenever signup is on —
would have made switching signup on quietly rewrite the `From` of every *tenant's*
mail as well, since the two are one variable.

**It is written in the visitor's language**, which the calling site forwards with
the submission because there is nowhere else to get it: this person has no account
on this installation, so no stored preference, and the `Accept-Language` of a
server-to-server POST belongs to the calling server. A language this build does not
have falls back to the installation's default rather than being refused — the same
choice the translation catalogue makes one level down (§8.4.2), and the same check
that keeps a caller from handing an arbitrary string to the translator and to a
sixteen-character column.

#### Two slug rules, on purpose

`TenantProvisioner::SLUG_PATTERN` permits **underscores** and forbids
**hyphens**, which is exactly backwards for a string that becomes a DNS label:
`my_company.xivi.app` is not a valid hostname.

**It is not changed, and this paragraph exists so that nobody unifies the two.** It
is right for what it guards — a provisioning slug is also a PostgreSQL database and
role name, where an underscore is the ordinary separator and a hyphen would force
every identifier to be quoted. And `provision()` never derives a hostname from a
slug at all: hostnames are an explicit parameter, so an operator is free to route
`acme.example.com` at a tenant called `acme_ag` and nothing is inconsistent.

Self-service is the case where **nobody types the hostname**. The slug *is* the
subdomain, so it gets a second, stricter rule: one DNS label as RFC 1123 allows
it. The two overlap on names made only of lowercase letters and digits and
disagree everywhere else, in both directions, and `SelfServiceSlugTest` asserts
that disagreement from both sides so that replacing either pattern with the other
fails the build.

**A consequence to hand to [XIV-98]**: because the two rules disagree, a
hyphenated self-service slug can never equal an existing tenant's slug today, and
the intake's check against the registry only bites for names both rules accept.
Whatever mapping [XIV-98] chooses from a signup slug to a provisioning slug has to
be checked here as well, or two customers can be promised names that collide once
translated.

**That has landed and §8.14 records it.** The mapping is hyphen to underscore,
the intake now asks the registry about the translated name *and* about the
hostname it would take, and the names that have no translation at all — one
character, a leading digit, anything past fifty-six — are refused here with
`invalid_slug` rather than accepted and failed in a cron run.

**The name is derived from the company name, shown before submission, and
editable** — and the derivation is part of the contract rather than the form's
business. Two implementations of a transliteration rule disagree on the first
umlaut somebody types, so the endpoint derives it, hands back what it derived, and
§8.13's form shows that.

**The rule takes nothing from the request, which is [XIV-100]'s fix.** It was
locale-aware — `Bäckerei` is `baeckerei` to a German reader and `backerei` to the
default rules — and that was wrong for a reason that is easy to miss, because
there was only ever *one* derivation and both endpoints already called it. What
differed was the argument. `locale` is an **optional** field on both requests, its
documented job is to choose the language of the confirmation mail, and nothing
obliged a caller to send the same value to the availability check and to the
submission. So the preview said `muller-bau-ag`, the submission created
`mueller-bau-ag`, and the `available: true` had been computed about a name nobody
would ever be given.

Passing the locale more carefully does not fix that: two requests are two
requests, and any rule that reads an optional field can be made to disagree with
itself by a caller that forgets it once. So the request stops deciding. The mail's
language is a property of the *reader* and rightly varies; the slug is a hostname,
it is permanent, and it belongs to the *company* — which writes itself `Mueller`
whenever ASCII is required, on Monday in German and on Tuesday in English.
`SelfServiceSlug::TRANSLITERATION_LOCALE` is `de`, chosen deliberately for the
market this is sold into, and every other language keeps what it had: the locale
maps only add expansions on top of the generic ASCII rules, so `é` is still `e`
under `de` exactly as under `en`. What it costs is the handful of languages with
an expansion of their own — a Swedish `Å` is `a` here rather than `aa` — and a
deployment selling somewhere that trade is wrong changes one constant, which is a
decision about the installation rather than about a request.

**Reserved names are two lists.** The conventional one exists because those are
names a platform will want later and cannot take back. The second is computed from
`app.system_hosts` and the control-plane host, and it is a boundary rather than a
convention: [XIV-57] made `tenant:provision` refuse to route a tenant onto a system
host, and that refusal fires when [XIV-98] runs — long after somebody has confirmed
an address and been told the name is theirs. What is reserved is the **first label**
of each such host, because that is what collides.

#### Abuse: confirmation and volume are different problems

**Squatting** is answered by the two rules above, and the mechanism is worth stating
plainly: **a name is held only by a confirmed address**, and a confirmed address
may hold only one unprovisioned signup at a time. Holding a name therefore costs a
working mailbox and a clicked link, *per name*; without the second half the cost
is paid once and reused for as many names as you like.

The price of that is a race the design cannot remove and does not try to: two people
ask for `acme`, both are told it is free because nothing is held, and the second to
click their link is told it has gone. That is the anti-squatting rule costing
somebody something, and it is the right side to take — the alternative is holding
names for addresses that have proven nothing.

**Volume** is a separate harm and needs a separate answer: a script posting a
thousand addresses a minute has used this installation to send a thousand people
mail they did not ask for. `symfony/rate-limiter` (MIT, first-party, checked and
recorded in `THIRD-PARTY-NOTICES.md`) with three sliding windows: a small one per
email address, which bounds how much mail a stranger can aim at one *person*; a
loose one per client address, loose because [XIV-65]'s recommended integration is a
server-side post and an office behind one NAT is one address; and a much larger one
for availability checks, which write nothing and are made as somebody types.

Two things about it are worth knowing rather than discovering. **The secret is
checked before the limiter is touched** — otherwise anybody at all could exhaust a
chosen victim's bucket without holding the credential, turning a defence against
abuse into an instrument of it. And **there is no global cap**: with a server-side
integration every request arrives from one transport address and the client address
is supplied by the caller, so a compromised caller can spread itself across as many
buckets as it likes. The thing that answers a compromised caller is rotating the
secret, and a single ceiling on the endpoint would also be a single number that one
busy afternoon turns into an outage for everybody.

#### The contract is a public API, and its host is its own

The intake is an interface somebody else compiles against rather than a form's
private detail — that was the assumption when it was written, and §8.13 kept it
even though the landing page ended up in this repository: the page holds the
secret and posts to this contract like any other caller, so a deployment that
builds its own front end is on the same footing as the one shipped here. That
fixes four things: a documented request and response shape, next to the code
rather than only here; **a version in the path** (`/api/signup/v1/`), within which
fields and error codes may be *added* and added fields must be optional, while
anything removed, renamed or made required is a v2 served beside v1; **a stable
error vocabulary**, with its HTTP statuses decided in one table rather than at
each `return`; and **a shared secret**, compared in constant time.

Two of those carry an argument rather than a convention. The message beside an
error code is one **fixed** English sentence, for a developer's log — [XIV-65]
owns the words a visitor reads, in their language — and fixed rather than
descriptive is a security property rather than laziness: the *internal* refusal
message names which of three reasons made a slug unavailable, and the first
version of this endpoint returned it, undoing the paragraph below from inside the
response that paragraph was written for. And the secret **refuses everybody when
unset**: a deployment that set a host and forgot the secret has published an
anonymous endpoint, and failing closed makes that a feature that does not work,
which is noticed in minutes, rather than one that works for everybody, which is
not noticed at all.

**A server-side post is the recommended integration**, and the difference is where
the credential lives: in a browser-side design it is in the page's source, which is
to say in everybody's hands, and the endpoint additionally has to appear on a public
CORS origin list. There is deliberately **no CORS configuration anywhere in this
feature**, and that is not a gap to be filled in later — adding it is the change
that makes the browser-side design possible.

**`slug_taken` is one word for three situations**: a customer has the name, a
confirmed signup is holding it, and the platform keeps it. Distinguishing them would
be more informative and is deliberately not done, because whatever the endpoint
distinguishes, a caller can enumerate — and the useful action is the same in all
three cases. **The honest limit**, because it is a limit rather than a fix: "not
available" is still one bit, so the set of unavailable names is discoverable by
anybody entitled to call this. What keeps that from being an enumeration of the
customer list is the shared secret and the rate limiter, not the vocabulary. A
deployment that proxies the availability check straight through to anonymous
visitors has made that bit anonymous too, and should say so to itself.

**It is served on a hostname of its own**, not under `/control/`. §8.9 asks for the
control-plane host to be hard to guess, and a hostname configured into a third
party's marketing site is the opposite kind of secret — it ends up in somebody
else's deployment, somebody else's chat and eventually somebody else's repository.
Serving an anonymous endpoint there would also aim the internet's traffic at the
host that answers to the people who can see every customer. `SIGNUP_HOST` goes into
`app.system_hosts` exactly as `CONTROL_PLANE_HOST` does, so a signup request
resolves no tenant by the same mechanism rather than a second one, and the
application refuses to build a routing table when the two hosts are equal.

The firewall there is `security: false`, which is a decision rather than an
omission: `main` matches every host, so without a block of its own a request here
would sit inside the firewall whose provider looks people up in a customer's
database. Nothing would come of it in practice — no session, no credential the
provider is asked about — and "nothing would come of it in practice" is the wrong
standard for a boundary. It is declared *below* the control-plane firewall so that
a deployment which somehow got both hostnames equal ends up with an operator console
that still demands a password rather than one with `security: false` in front of it.

#### Off means the route does not exist

The three states are page-and-endpoint, endpoint-only, and neither; the endpoint
switch is this section's and the page's is §8.13's. Here, **off means
no route is registered** — not a route that answers 404. A registered route is a
controller the router can reach: it is in the compiled matcher, it is in
`debug:router`, and it is one misplaced access rule away from running. A route that
was never loaded is absent as a property of the routing table rather than of a check
somebody has to keep correct.

Symfony cannot say that in routing configuration, because **environment placeholders
are forbidden there** — the same constraint that made `ControlPlaneRequestListener` a
listener rather than a `host:` on the operator routes (§8.9). A route loader is the
framework's own answer: it runs at route-load time, it can read what a service can
read, and what it returns *is* the routing table. It also stamps the configured
hostname onto every route it returns, which is why no signup controller carries a
`host:` of its own, and it forces `https`, because the request carries a shared
secret and the confirmation link is how somebody proves control of a mailbox.

One variable does both jobs — empty `SIGNUP_HOST` is off, a hostname is on and says
where — rather than a flag beside a hostname, which is two facts that can disagree.

**That was not enough, and §8.13 found out why.** The loader was right about its
own collection and the routing table held a second, host-less copy of every signup
route registered by the framework's own `routing.controllers` — present even with
`SIGNUP_HOST` empty. The fix and the assertion that catches it are in §8.13; the
lesson to carry back here is that a claim about "the routing table" has to be
asserted against the router, because a loader can only ever be asked about what it
returns.

#### What is deliberately not built

**The landing page and the form.** §8.13's — which, in the event, is served from
this repository and on this hostname rather than from a site of its own; the
argument for that is there. What is provided from *here* is the derivation rule
and the availability check as part of the contract. The one page that was here
first — where a confirmation link lands — is the plainest in the repository on
purpose: it can only live on this side, because the token is a row in this
database, and it remains deliberately unlike the landing page rather than an
early draft of it.

**Provisioning.** [XIV-98]'s, and with it the removal of the row: this table holds
*live* signups only. That is why `SignupStatus` has two cases and not three — a
`provisioned` state here would be a second copy of a fact the registry already holds
in `tenant.slug`, free to disagree with it, and the disagreement would be silent.

**Any notion of which caller presented the secret.** There is one secret because
there is one caller. When there are two — a partner, a reseller — that is the moment
for a table of keys with a name against each.

### 8.13 A landing page, and the scope is the decision (XIV-65)

§8.12 built an intake and deliberately built no way in to it. This is the way in:
one page, one form, on the signup host. A visitor types their company name,
watches the address they will be given appear, edits it if they want it
different, and submits.

**It is a landing page and not a marketing site**, and that was weighed before it
was built rather than discovered while building it. The two have nothing in common
except this form: different authors, different release cadence, different risk
appetite, different reviewers. A marketing site in this repository would put a
copy edit through a suite that provisions PostgreSQL databases, and would put an
ERP release behind somebody's rewrite of a features page. So the scope is a
landing page, no pricing, no feature grid and no content model — and **the day a
real marketing site is wanted, this section reopens**. The answer then is not to
grow this page: it is a site of its own posting to the published contract, which
is exactly what §8.12 made the contract public *for* and what the "endpoint only"
state below exists to serve.

#### Three states, two switches, and one `and`

The page and the endpoint are wanted independently: **page and endpoint**, the
default when signup is on; **endpoint only**, for somebody who has built their own
front end, where the built-in page would be a second front door onto the same
intake, worse than theirs and confusing to find; and **neither**, for a single
company self-hosting, for whom an open endpoint that records signups is a
liability rather than a feature. The last is the shipped default.

`SIGNUP_HOST` is §8.12's and says whether there is an intake and where.
`SIGNUP_PAGE` is this one and says whether we also draw the form. They are
combined in one place and the combination is an `and` — so the fourth state, a
page with no intake behind it, is **not expressible** rather than refused by a
check. That is worth being deliberate about because it is the combination that
would fail worst: a form that renders, accepts a company name and then cannot post
anywhere looks like it works to everybody except the person filling it in.

A boolean here where §8.12 refused one for the endpoint, and the asymmetry is not
an inconsistency. That variable had a second job — it has to say *where* — so a
flag beside it would have been two facts that can disagree, and the disagreement
everybody eventually has is "enabled, but nobody said where". This one has no
second job, because where the page is served is already decided.

#### The page shares the endpoint's hostname

§8.12 argues at length that the *endpoint* must not be served on the control-plane
host, because a hostname configured into a third party's site ends up in somebody
else's repository and the operator console's should not. That argument is about
secrecy and the page has none to lose: it is anonymous, public and meant to be
linked to.

What decides it is the confirmation link. It lands on `SIGNUP_HOST/signup/confirm/…`
because only this side can answer it — the token is a row in the control-plane
database — and a visitor who filled in a form at one name and is asked to confirm
at another has been handed the exact shape of a phishing mail. One name, from the
form to the mailbox and back. A deployment that genuinely wants the form under its
marketing domain has a better tool than a second hostname here: it puts its own
page there and posts to the contract, which is what the "endpoint only" state is
for.

#### It goes through the front door, and the front door is what the test proves

The page could call `SignupIntake` directly; it is in the same process. It does
not, and there are two reasons that outlive the convenience.

**The secret is the design.** §8.12 recommends a server-side post carrying
`X-Xivi-Signup-Key` because the alternative puts the credential in the page's
source and forces a CORS origin list onto an anonymous endpoint. This page is the
*reference* implementation of that integration; one that reached past the contract
would be recommending one thing and doing another, and the first person to copy it
would copy the wrong half.

**The contract has to be exercised by something the company itself runs**, or its
shape, its header name, its status codes and its error vocabulary are proven only
by a test. Going through the front door means we are broken by the same change
that breaks a customer's integration, in our own staging, first.

**The request is real; the socket is not.** What crosses is a genuine `Request`
handed to the kernel as a sub-request, routed to the real controller and charged
to the real rate limiter. What that proves is the whole published contract. What
it does not prove is DNS, TLS and whatever proxy sits in front, and saying so is
part of the claim rather than a caveat on it.

A real socket was the alternative and lost on two grounds. FrankenPHP runs in
classic mode (§9.2), so a request occupies a worker: a page that opens a
connection back to its own server holds one worker while waiting for a second, and
with *n* workers, *n* simultaneous submissions deadlock the instance on precisely
the busiest day. And the container would have to resolve and trust its own public
name, which behind a terminating load balancer or split-horizon DNS it frequently
cannot — a landing page that works everywhere except production.

#### What the page gives away, said out loud

A live name check **is** an availability oracle offered to anonymous visitors.
§8.12 names this and asks a deployment that proxies the check to say so to itself;
this is that deployment saying so. `available: false` is one bit and a script can
walk it. What is left in front of that bit is a per-visitor bucket, which bounds a
walker rather than preventing one, and the fact that "unavailable" is one word for
three situations — so a walker cannot tell a customer from a reserved word. That
is the price of showing somebody their address before they commit to it, which is
the whole point of the ticket. A deployment unwilling to pay it switches the page
off and keeps the endpoint.

The visitor's own address is forwarded to the intake so the limiter counts per
visitor rather than per installation; without it the client bucket would be a
single counter for the internet, either large enough to bound nothing or small
enough to be an outage.

**No CSRF token**, which is a decision. CSRF protection stops a third-party page
spending a credential the browser holds, and there is none here: the signup host
has `security: false`, nothing on the page is authenticated, and a forged
cross-site post achieves exactly what the forger could achieve by posting from
their own server. The one thing a forgery buys is that the victim's address lands
in the client bucket instead of the attacker's — a rate-limiting nuisance, not a
boundary — and paying for it would mean starting a session for every anonymous
visitor to the one host in this system that has none.

#### Not a Live Component, and the reason is structural

XIV-33 adopted Symfony UX Live Components and this page is exactly their shape, so
the departure needs an argument. A live component answers at
`/_components/{name}/{action}`, a route the bundle registers once for every host
this installation serves, and the component is resolved from that route's
parameter rather than from any route of its own. A `SignupForm` component would
therefore keep answering after `SIGNUP_PAGE` had switched the page off, and on
every tenant's hostname besides — a page that is "off" while its actions still
run, which is the hidden-page failure §8.12 wrote a route loader to avoid. Nothing
in the bundle's configuration can say otherwise, because the route that reaches it
is not this feature's to bind.

So the page is a plain controller whose routes the loader owns, and the live half
is sixty lines of Stimulus posting to one of them. Server-rendered stays true, and
there is deliberately no transliteration in the script — a copy of the derivation
rule in the browser is XIV-100 again, one layer further out and worse, because the
customer would be reading our answer while the server recorded its own.

#### The defect this found in §8.12, which was live

`SignupRouteLoader` keeps its promise about the collection it returns and
`SignupRouteLoaderTest` proves it about that collection. It was not true of the
routing table: the framework autoconfigures every class carrying a `#[Route]`
attribute into its own loader, so the signup controllers were loaded twice — once
by this feature's loader with the host and `https` stamped on, and once by the
framework's with neither — and the survivor was whichever loaded last, which
happened to be the loader's purely because of key order in a YAML file.

With `SIGNUP_HOST` empty — the shipped default, the state a self-hosting company
relies on — `debug:router` still listed every signup route, on every hostname,
over plain HTTP. Only `SignupApiKey` failing closed on an unset secret kept that
from being an open intake, which is a defence in depth doing the entire job alone.
And moving two keys in `config/routes.yaml` silently unbound the host of the whole
feature; that is how it was found.

A compiler pass now takes those classes out of the framework's loader. **The
lesson that outlives the fix is where the assertion goes**: it is made against the
compiled **router** rather than against the loader, because a loader can only ever
be asked about what it returns, and the claim being made is about the table.

#### How a content-only change gets through the changelog gate

`bin/ci` requires every branch to add a `CHANGELOG.md` entry, which is right for
anything a reader has to act on and absurd for a comma moved on a signup form. The
entry would say nothing, which is how a changelog becomes noise; and the
alternative is `--no-changelog`, typed routinely, until it is typed on the branch
that did need one. **A gate people skip out of habit stops being a gate, and it
stops being one quietly.**

So the rule is stated mechanically instead. The landing page's copy lives in a
catalogue of its own, `translations/landing.*.yaml`, rather than as keys in
`messages` — and `bin/ci` exempts a branch whose **entire** diff is that catalogue
and the page template. It is narrow in two deliberate ways: the exemption is per
branch rather than per file, so one line of PHP anywhere and the gate applies
exactly as before; and what counts as content is a short explicit list rather than
a rule like "anything under `translations/`", because the application's own
catalogue is product text — renaming *Invoice* is a change a customer sees.

Giving the page its own translation domain was worth doing for that reason alone.
It is also the right shape independently: the person who edits marketing copy
should not be editing the file that names the engine's fields.

#### The sixty lines of script, and how a browser was made to reach them (XIV-105)

The section above ends by saying the live half is sixty lines of Stimulus. `bin/ci`
never ran a single one of them. Every route the page owns is driven by
`SignupPageTest` with a real client, and every one of those assertions would go on
passing with the script deleted — which is the shape [XIV-84] already made
expensive once. There, a `data-action` typo made every lens button on the
dashboard inert and the suite stayed green, because the server-side tests called
the action directly and no button was involved. This page has three `target`
attributes, two action descriptors and one value name, and it is the one page in
this repository strangers reach.

**So it is tested in a browser, and the interesting part was that it could not be.**
Panther serves the application with `php -S` — plain HTTP, an arbitrary port — and
the signup routes carry the signup host and `https` because the surface behind them
mints mailbox-proving links and carries a shared secret in a header. That is not an
oversight in the test: it is a genuine incompatibility between how the page is
bound and how the browser suite reaches an application, and **the binding is the
half that is right**. Relaxing it under `when@test` was rejected outright — it is
the exact property [XIV-65] fixed a live defect to establish, and
`SignupPageTest::testEverySignupRouteInTheRouterCameFromTheLoader` exists to fail
when it stops holding.

What made a browser affordable is that **both obstacles turned out to be the test
harness's rather than the application's**, and both are answered outside it: a
second compose network alias supplies the hostname, and a router script handed to
`php -S` stands in for the TLS terminator production has. Two details in that are
worth carrying because each cost a search. The alias could not be a `*.localhost`
name — Chromium answers every one of those from its own loopback before DNS is
consulted, so no amount of compose wiring reaches one — and the name it uses keeps
a dot, because `SignupFirewallTest` proves the signup firewall is scoped by a
matcher rather than by `security.yaml`'s `host:` regular expression *by asking for
the hostname with its dots substituted*, and a single-label host would have left
that test asserting nothing. And the router script lies for that one hostname and
no other, because the web server is started once for the whole browser suite and a
session cookie marked `Secure` is one a browser on `http://` will not store.

**Nothing in `src/`, `packages/` or `config/` changed**, which is a stronger claim
than a `when@test` seam and is why it was worth the search. `SignupNameTest` states
it as an assertion rather than as a paragraph: the routes it has just reached over
plain HTTP are still `https`-only and host-bound in the compiled router of the
process making the claim.

**Two tests, and both are chosen so they cannot pass by accident.** A free name
asserts the box holds the *expanded* transliteration, computed by calling the
server's own deriver, because that expansion is what §5's argument about [XIV-100]
is *for* and what any browser-side slugifier would get wrong — it would catch a
copy of the rule growing in the script, which is the failure this page is most
exposed to. A reserved name needs no fixture at all, because `admin` is reserved
by the code rather than by a row somebody committed, so the answer does not depend
on which browser class ran first.

The net was proved by breaking the page rather than by argument, the way [XIV-84]'s
own regression test was. Reproducing [XIV-84]'s literal `data-action` bug one
screen over turns both tests red, and so does renaming the controller's value,
which is the *silent* version: nothing appears in the console, the page renders
perfectly, and the box simply never fills in.

**What it costs is two Selenium sessions and no tenant.** The landing page resolves
no customer, so this is the only browser class that provisions nothing, and it is
the cheapest one there is: about three to five seconds against a suite that varies
by more than that between runs.

**What is still not covered, said plainly.** The debounce and the
newest-answer-wins sequencing are real logic and no test here touches them; a
regression in either shows up as a form that feels wrong rather than one that is
broken, and it would ship. The same is true of the submit path, which is a plain
form post and needs no script. What is closed is the wiring — the attributes, the
route the script calls, and the two places the answer is written — which is the
part that has shipped broken before.

### 8.14 Turning a confirmed signup into a customer (XIV-98)

§8.12 built a public surface that provisions nothing and said, in as many words,
that acting on what it records is a separate ticket which *"runs where an
operator can see it"*. This is that ticket. It is the privileged half — the one
that legitimately holds `TENANT_ADMIN_DSN` — and everything below follows from
that being true of one console command rather than of an HTTP endpoint.

#### A command and cron, and the constraint is the runtime rather than the feature

The obvious shape is a message dispatched when somebody clicks their confirmation
link and consumed by a worker. **There is no worker.** This deployment is
FrankenPHP in classic mode with no worker block on purpose (§9.2), so nothing
runs between requests and a queue with nothing draining it is strictly worse than
no queue: the customer's installation is simply never made, and the failure is
silence.

That is the third feature to reach the same answer from the same place — [XIV-37]
made sending mail synchronous, [XIV-59] made usage collection a cron entry — and
the reason to write it down a third time is that it is **not three decisions**.
The constraint is a property of the runtime, so it produces this answer for
anything that would otherwise want a consumer, and it stops producing it the day
somebody introduces one for a reason of its own. On that day, moving this onto it
is a small change. Inventing one for a job that takes seconds is not.

The cost is latency, and here it is customer-facing rather than housekeeping:
somebody who confirms at ten past two waits for the next run. So the recommended
cadence differs from [XIV-59]'s — every few minutes rather than nightly — and
nothing in the command assumes either.

**One failure must not cost the others**, which is [XIV-59]'s rule and is
inherited rather than restated. Two things about it are deliberately *un*like
`tenant:usage:collect`. An empty queue is a **success** here — no confirmed
signups is the ordinary state of a healthy installation on most nights, and a cron
entry that mails somebody nightly for being idle is one whose mail nobody reads
within a fortnight. And nothing is ever given up on: there is no attempt limit and
no dead-letter state, because every failure a retry could fix is one an operator
fixes *elsewhere* — a full disk, a mail server, a grant on the provisioning role —
and a run that had disarmed itself in the meantime would make the repair a
two-step job whose second step nobody remembers.

#### The hard part: which steps are idempotent, established rather than assumed

Provisioning is a registry row, a Postgres role, a database, a schema and then an
administrator, and it can stop at any of them. Nothing about that is transactional
and nothing could make it so — it spans the control-plane database, the Postgres
cluster, a customer's own database and somebody else's mail server. So the
question is not how to avoid stopping half way; it is what a run that stopped half
way leaves behind, and what the next run does with it. What a retry may do was
read out of the code rather than hoped for, and the answer decides the design:
**`provision()` is not re-runnable, at either end**, while the three steps after
it are idempotent as they stand.

**So a half-made tenant is cleaned up rather than finished**, and the cleanup is
`deprovision()` — which §4.1's [XIV-94] subsection made re-runnable in precisely
the way this needs. Destroying rather than resuming costs nothing, and that is an
argument rather than a shrug. A tenant still in `provisioning` has never served a
request — `TenantStatus::servesRequests()` says so and `TenantRequestListener`
enforces it — and its first user is created *after* `provision()` returns. There
is no session, no record and nobody holding a credential. It is an empty database
with a company's name on it. A tenant that *is* already serving is finished rather
than torn down, for the mirror reason.

#### Telling our own wreckage from somebody else's customer

The resume path is the dangerous one, because *"a tenant with this slug exists"*
is not the sentence *"a previous run of mine made it"*. An operator's own
`acme_ag`, provisioned by hand a year ago, matches on the slug — and walking into
it to create an administrator and mail a stranger a link into somebody else's
installation is the worst thing this feature could do.

**So identity is the hostname, not the slug.** A tenant made here is routed at
the address below and holds it as its primary domain, written in the same flush
as the registry row, so even the earliest wreckage carries it. A tenant that does
not hold that hostname is somebody else's, whatever it is called, and is neither
resumed nor torn down: the signup fails at `preflight` and a person decides which
of the two names has to move. That refusal repeats in every run for ever, which
is the right amount of pressure for something only a person can settle.

#### A stuck signup is visible where somebody already looks

[XIV-58] sorts a non-serving tenant to the top of the tenant list and names it in
the banner, and `provisioning` is the state it ranks first. That page is the
answer to "where does a half-made customer show up", and it needed nothing added
to it — what this ticket had to make sure of is that a failure *reaches* a state
it can draw, rather than sitting only in the intake table where nobody looks.

It does, because `provision()` persists its registry row before it touches the
cluster. Every failure from that point on leaves a row in `provisioning`. The
failures that leave **nothing** are the pre-flight ones — a name or a hostname
that is no longer available — and those are precisely what the intake checks
below exist to make unreachable. What survives them is the genuine race, and it
is reported in the run's output and counted on the signup row rather than drawn
anywhere. That is the honest limit of this criterion: a customer whose name an
operator took by hand between confirming and the next cron run is visible to
whoever reads the cron mail and to nobody else.

**What is recorded on the signup row is a stage, not a message**, which is
[XIV-59]'s rule one table along applied unchanged — and a stage additionally
answers the only question the stored value has to: whether trying again, unaided,
could ever work.

There is still **no third `SignupStatus`**, for §8.12's reason unchanged — a
status here would be a second copy of a fact `tenant.slug` already holds, free to
disagree with it. A failed signup is the same confirmed row it was a minute
earlier, still holding its name, with a counter and a stage beside it.

#### The slug trap, and how the collision is prevented rather than made unlikely

§8.12 kept two slug rules apart on purpose and handed the consequence here.

**The translation is hyphen to underscore, and nothing else.** It was chosen over
dropping the separator or appending a hash because it is the only rule a human
can perform in their head: an operator reading `acme-bau.xivi.app` in a support
ticket types `psql tenant_acme_bau` without looking anything up. It **cannot make
two customers collide, and that is a proof rather than a hope** — a self-service
slug contains no underscore, so the map is the identity on the shared alphabet
and sends the one remaining character to one that never occurred in the input, a
bijection onto its image. The intake's existing rule of one confirmed signup per
reserved name therefore carries over intact with no second uniqueness check.

**What does not carry over is the check against the registry, and that is the
sharp edge.** `tenant.slug` holds *provisioning* slugs. No self-service slug can
ever equal `acme_bau`, so the intake's `findOneBySlug()` looks it up, finds
nothing, and says `acme-bau` is free — and provisioning then refuses, days later,
after somebody has confirmed an address and been told the name is theirs. So the
intake now asks the registry about the **translated** name as well, at the moment
the name is asked for, and about the **hostname** it would take for the same
reason one noun along: `provision()` derives no hostname from a slug, so an
operator may perfectly well have routed `acme.xivi.app` at a tenant called
something else. Both refusals are `slug_taken`, which is §8.12's rule about one
word covering several situations, applied unchanged.

**The map is also partial**, where the two rules disagree about length and first
characters rather than about separators, and those names are refused at the intake
with `invalid_slug` rather than accepted and failed later. The two halves of that
are treated differently on purpose. A **derived** name is a suggestion this system
made, so the deriver cuts to the length provisioning will accept — a suggestion it
cannot honour is its own mistake to fix. A name somebody **typed** is refused,
because silently shortening what a customer asked to be called is worse than
telling them it is too long. The residual cost is real and is stated rather than
engineered around: a company whose name begins with a digit cannot have that name
as a slug, and is asked for another. Every scheme that would have rescued it —
prefixing a letter, appending a digit — makes the translation non-injective, and
losing the collision proof to save `3m` is the wrong trade.

#### What hostname a self-service tenant gets

**The signup slug as a label under the signup host's parent domain.** A
deployment serving signup at `signup.xivi.app` puts its customers at
`acme.xivi.app`; a single-label host — `localhost`, a container name — has no
parent to take and keeps itself, so a fresh checkout gets `acme.localhost`.

This was already the convention §8.13's form displayed beside the name box, where
`SignupPage::tenantDomain()` called itself *"a display hint"* and said the real
answer was this ticket's. It is now a fact, in
`Provisioning\SelfServiceTenantHostname`, and **the page delegates to it**. That
direction is the load-bearing part: two implementations of "the domain a customer
sits under" is two implementations of a promise, and the way anybody would
discover they had drifted is a customer typing the address they were shown and
reaching nothing.

It also explains, retrospectively, why §8.12 reserves the **first label** of every
system host rather than the whole string. A control plane at `control.xivi.app`
is collided with by a signup for `control` precisely because that signup would be
routed at `control.xivi.app`. That paragraph was written against this convention;
it becomes correct rather than merely well-aimed now the convention is code.

#### The first user gets a link, and no password exists anywhere

`tenant:provision --admin-email` prints a generated password because an operator
is watching a terminal. **Nobody is watching this**, and §8.5's own note said the
printing goes away once there was a mailer. So the first administrator is created
with `createWithoutPassword()` — the hash stays empty, which nothing can
authenticate against from either direction — and §8.8's signed login link is what
admits them. A generated password nobody ever reads is a live credential sitting
on the account for as long as the account does.

Note this is a **different** token from §8.12's confirmation token, and the two
are not interchangeable: that one proved an address existed before there was a
tenant, and this one signs somebody into a tenant that now does. §8.12 explains
at length why the framework's login link could not be used for the first job.

§8.8 predicted two problems with sending one off a cron and left them here, and
both are real. **There is no request, so there is no hostname to be absolute
against**: the router's request context is pointed at the tenant's own hostname
for the duration of the send and restored afterwards, because the run is a loop in
one process and a leaked context would sign the next person's link for the
previous person's domain — a link that *works*, and admits somebody to the wrong
installation. The **port** is deliberately left as configuration put it: the host
is the part only the tenant can supply, while the port is a property of the
installation. **And there is no locale either** — an invitation is ordinarily
written in the language of whoever pressed the button, and nobody pressed
anything, so the best answer available is the language the visitor was reading the
signup form in, which the row has carried since §8.12 recorded it for the
confirmation mail.

The mail itself needed no exception carved for it. §8.8 refused one and was
right: a freshly provisioned tenant has configured no SMTP server, so §8.7's
instance fallback applies of its own accord and the message goes out under
`no-reply@` at the customer's own new hostname. "Works on day one" and "the first
user of a self-service signup" meet exactly where §8.8 said they would.

#### The customer is not told when it fails, and that is the honest gap

The run's non-zero exit, the addresses in the cron mail, the attempt counter on
the row and the banner on the tenant list are all addressed to the **operator**,
who is the only party who can act. Nothing is mailed to the person waiting.

That is deliberate for the ordinary case — nearly every failure here is
transient, the next run fixes it, and "we could not set up your installation"
followed twenty minutes later by "here is your login" is worse than twenty
minutes of quiet — and it is a genuine gap for the case that never resolves. A
signup stuck at `preflight` waits for ever while a person is expected to notice.
The counter is what makes that legible: one attempt is a bad afternoon, two
hundred is a name somebody has to give back. A mail after N attempts is the
obvious next move and is not built here, because it needs a decision about what
it may honestly say, and that decision is worth more than the twenty lines it
would take.

#### Privilege

This half holds `CREATE DATABASE` and `CREATE ROLE` and must run only on the
non-public side. Today that is a console command in one deployment, which is a
code boundary and not yet a privilege boundary — §8.12's own honest limit,
unchanged. When [XIV-96] separates the deployments, `signup:provision` and
`Provisioning\SignupProvisioner` belong in the internal image and
`TENANT_ADMIN_DSN` belongs only in its environment. Note also §4.1's finding that
the provisioning role needs more than `CREATEDB CREATEROLE` on Postgres 16 and
later; a deployment narrowing it has that work to do first.

### 8.15 A price a customer can see, and an ask that installs nothing (XIV-102)

The customer-facing half of §6.5. That section gave a module a price, the ability
to set one, and a single seam to read it through; this one puts the figure on the
screen a customer is standing in front of and answers the question the figure
raises — *so how do I get it?*

**There is no payment gateway, and this ticket exists so that there does not have
to be one yet.** A gateway is a decision with PCI scope, a merchant agreement, a
refund policy and a webhook endpoint behind it, and none of those are things a
pricing feature should have to wait for. So the answer is a placeholder, and the
whole ticket is about what the placeholder *is*.

#### The question that decides it, and the two answers that were rejected

**Install anyway, and record that it is unpaid.** Rejected, and written down here
so that nobody re-proposes it as "just for now".

It is the smallest change and it makes the price decorative. A module installed
on the strength of an unpaid flag is a module the customer has: their definitions
are in their own database, their records are in their own tables, and §6.2's rule
that nothing here uninstalls anything means there is no mechanism to take it back
— by design, and rightly. So the flag is a note in a table that no code enforces,
and the first customer who notices gets every priced module for nothing. Worse
than the loss is what it teaches: a price that can be ignored by pressing the
ordinary button is not a price, and every screen that displays one afterwards is
making a claim the system does not stand behind. "Just for now" is exactly how
that becomes permanent — the flag ships, nothing breaks, and the thing that was
supposed to replace it never has a forcing function.

**Refuse, and say to get in touch.** Honest, and it is the fallback rather than
the design. It answers the customer's question with a dead end and hands them a
task — find the address, write the mail, describe which module — while the system
that knows all three sits there not doing it. A self-service product whose
self-service stops at the word "price" has a hole where the interesting half is.

**Record a purchase intent that an operator fulfils.** Adopted, and it is the
shape this codebase already reaches for rather than a new one. §8.12 answers the
same question one layer up: a public surface records an intent and does nothing
privileged, and a non-public process acts on it, because *"anyone may ask" and
"the thing happens" are deliberately not the same event*. Substitute a customer
for a stranger and installing a module for provisioning a tenant and the sentence
survives intact.

The forward-looking half is why it is worth more than the other two: **the day a
gateway lands it slots in where the operator currently stands.** A payment
confirmation is a thing that answers an outstanding request, which is exactly what
an operator installing the module is today. Neither of the rejected shapes leaves
anything for it to slot into — one has already installed the module and the other
has recorded nothing at all.

#### Where the intent is stored, and why there was only ever one answer

**In the customer's own database**, one row per module, in
`module_purchase_intent`.

§4.4 decides this rather than a preference. The customer-facing instance's
database role holds `SELECT` on the registry tables and **nothing else** — no
`INSERT`, `UPDATE` or `DELETE` anywhere in the control-plane database, on any
table, present or future, which is precisely the guarantee [XIV-96] was for and
which `RegistryGrantsTest` proves against a real role on a real connection. A
feature whose first requirement is a write made by a customer's own request
therefore has exactly one database available to it.

That constraint turns out to point at the right place anyway, which is worth
saying so that nobody reads this as a workaround: it is the customer's own fact,
it sits beside the thing it is about, and it cannot leak between tenants for the
same structural reason nothing else can (§7.4).

**How an operator sees it: `tenant:purchase:collect`, which is [XIV-59]'s
collector reused rather than reinvented**, writing copies into `purchase_intent`
in the control plane so the operator's screen opens no tenant connection at all.
Every sentence of §8.11's argument transfers and none of it is restated here.

**The honest cost, stated rather than buried:** an operator learns about a request
within one collection interval rather than the instant it is made. That is small
against what happens next — a person deciding about money and then installing a
module by hand — and the screen prints the collection time beside every row so
nobody has to guess how fresh the list is.

#### The shape that was rejected, and it is the tempting one

**Have the store POST to a control-plane HTTP endpoint**, exactly as §8.13's
landing page posts to §8.12's signup intake. It is genuinely the same pattern,
it removes the collector and the interval, and it is wrong here.

It would hand the customer-facing image **a credential that lets it write the
control plane** — a shared secret and a reachable internal host — and thereby
re-obtain over the network precisely the privilege the database refuses it. §4.4's
entire argument is that the sharp boundary is the grant rather than the topology,
because *"not routed" and "not present" are different guarantees and only the
second survives somebody's mistake*. A secret in the public image's environment is
a boundary made of care again, and it is the first thing a copied `.env` undoes.

The pattern is not being misapplied by declining it, either: §8.12's contract is
an HTTP API **because the caller is a third party** — somebody else's website,
compiled against a published shape. Here the caller and the callee are two images
built from one repository by one company against one database. Inventing a network
boundary between them, in the one direction the database has been deliberately
closed, would be reaching for the mechanism and dropping the reason.

Also rejected, more briefly: **widening the grant** so the public role could
`INSERT` into one control-plane table. It is one line of SQL and it costs the
sentence "the role holds no write privilege anywhere", which is the sentence that
makes the guarantee checkable — a role with one exception has a second one coming.

#### The copy, which §6.5 asked for by name

**The price goes onto the request as a copy**, amount and currency, frozen at the
moment somebody pressed the button. §6.5 left this as an instruction rather than a
suggestion, and it is [XIV-67]'s rule about payment terms and §5.9's about invoice
totals arriving at the same place: what was agreed is a fact about the
transaction, never a live lookup. The collector carries the copy across untouched
and **never consults `ModuleCatalog`**, so the operator's screen cannot drift back
to the live figure by somebody being helpful.

**Asking again rewrites the row rather than adding one**, which is §8.12's
`reissue()` for its reason: somebody pressing the button twice is asking again,
most likely because nobody replied, and an operator's queue full of duplicates is
a queue that stops being read. The copied price is refreshed with it, because a
second press is somebody reading today's figure. `created_at` is not, because how
long this has been outstanding is the number that says how badly it went.

#### There is no status column, on either side

Fulfilment is **observed**, not tracked. The customer either has the module or
they do not, their own metadata is the truth about that (§6.1), the collector is
already inside their database, and nothing here uninstalls anything (§6.2) — so a
status column would be a second copy of a fact the customer's database already
holds, free to disagree with it. That is §8.14's argument for refusing a
`provisioned` status on a signup, and it lands the same way.

The visible consequence is that **the operator's screen has no button on it.** An
operator answers a request by installing the module — `tenant:module:install`,
which §6.3 kept precisely so that a page is never the only way to do something —
and the next collection sees it. A "mark as fulfilled" control would be a way to
make this screen disagree with reality, on the one screen somebody opens to find
out whether they still owe anybody anything.

#### Who asked does not cross, and the gap that leaves is named

The tenant-side row records the person's id and the name they had at the time —
`follow_up`'s two-column pattern, so somebody leaving does not take the record of
a purchase request with them — and **neither value ever leaves that database.**
§8.11 drew the line at *how much* rather than *what*, and a customer's own people
are on the far side of it.

So an operator knows **which company wants which module** and does not know whom
to write to. They reach the customer the way they already reach them, which is the
arrangement the registry describes. That is a real limitation and it is the right
side of the line; a contact column here would be a second copy of somebody's
personal data in a database they cannot see, kept for a conversation that happens
elsewhere anyway.

#### Buying is its own permission, and that is a decision rather than an omission

**`StoreAction::Buy`, a third case on [XIV-6]'s axis** rather than a reuse of
`install`. The two are close enough that folding them together is the obvious
move, and the reason not to has nothing to do with software: `install` is *"may
decide what this installation consists of"* — authority over the shape of the
system — and `buy` is authority over the company's money, which in a small company
belongs to somebody else. The direction of the mistake decides it: granting an
office manager `install` so they can add follow-ups, and thereby granting them the
ability to order something the owner has to pay for, is a surprise nobody consented
to.

A third *axis* was not needed and was not added — the subject is still `@store`,
the scope still does not apply, and the permission screens draw the new verb
because they iterate the enum.

**The operator's side has no permission at all**, and the asymmetry is not an
inconsistency. A tenant has many users with different authority over the company's
money; this installation has operators, all of whom are the company running Xivi.
Inventing a "may see purchase requests" grant before there is a second kind of
operator would be modelling a guess (§8.9) — the same sentence §6.5's pricing
screen carries.

#### What the placeholder must not be, and each absence is a decision

**It must not look like a payment page.** A form that looks like checkout and
quietly does nothing is worse than a sentence saying what is actually going on,
because it teaches people to type card numbers into pages that do not take them —
a habit worth not creating in software somebody uses at work every day. Item by
item, and each is asserted rather than intended:

- **No card fields**, of any kind, disabled or otherwise. `ModulePurchaseTest`
  counts the page's inputs — bluntly, because that assertion is what goes red when
  a later ticket makes the page friendlier.
- **No total, no line items, no VAT row.** The price appears once, as what the
  module costs. A total is the visual grammar of a page about to charge you.
- **No "processing", no spinner, no confirmation number.** There is no
  transaction to have a state.
- **No promise of when.** An installation that said "within 24 hours" would be
  making a commitment on behalf of a company this code knows nothing about.
- **No congratulation.** Not "thank you for your purchase", which is the exact lie
  the whole ticket refuses to tell.

What it does say is what it costs, that pressing the button is a request rather
than a payment, that nothing is charged, and that a person will reply.

#### A free module says nothing, and that is what makes this ticket invisible

**Absence of a price is the ordinary case in this store and it looks ordinary.**
Not a "Free" badge on every tile, not a zero. Almost everything here is free and
always will be for a deployment that sells nothing, so a badge everywhere is noise
everywhere — and worse, a page that says "Free" on every card has taught its
reader to skip that line, which is the line that matters on the one card that is
not.

The acceptance criterion that guards it is that **the existing store tests pass
unchanged**, and they do: `ModuleStoreTest` is untouched by this ticket, because
`publish()` there already prices every module `free` (§6.5 made that necessary)
and every screen and every check behaves for a free module exactly as it did
before.

The other two pricing states never reach the store at all — `unpriced` and
`not_for_sale` are withheld by `CatalogEntry::isOfferedInStore()` (§6.5) — so the
presentation only ever has two cases to draw.

**A module priced after installation keeps working**, and the store says nothing
about it: the customer sees "you already have this", no button appears, and
nothing anywhere treats "priced and installed" as an anomaly to correct. §6.5
proves that rule against the control plane with a photograph; `ModulePurchaseTest`
proves the customer's side of it, including that their fields are exactly as they
were.

#### Money on the screen, and a currency that may be unset

**Drawn as it is stored** — a decimal string at two places with the ISO 4217 code
beside it — and deliberately not through a locale-aware currency formatter. Three
reasons, in increasing order of weight: `NumberFormatter::formatCurrency()` takes
a float and §5.9 is that nothing on a money path is ever a float; the currency may
be absent, in which case there is nothing to format *with*; and this figure is
copied verbatim onto the purchase request, so the value shown and the value stored
have to be the same string rather than the same number rounded twice. On a
customer's own invoice a formatted amount is right; on a price they are about to
commit to, the stored value is.

**An unset currency shows a bare number, and the customer is told nothing about
why.** §8.6 refuses to guess a currency for a customer because a guessed one is
wrong quietly, and §6.5 refuses to guess one for a price list; the same refusal
here means `49.00` stands alone when `PRICE_CURRENCY` is empty, which is the state
this repository ships in and the state the test suite runs in. The *operator's*
screens name the variable in that situation because an operator can go and set it;
the customer's screen does not, because they cannot, and a sentence about somebody
else's environment file is a deployment detail offered in place of an answer.

#### VAT, named and moved on from

§6.5 settled on a **one-off** price, so tax on that sale is a real question and it
is not this ticket's. Nothing here computes, displays or stores a tax amount, and
the figure a customer sees is the figure §6.5 stores with no claim attached about
whether it includes anything. When a gateway lands, the deployment's own VAT
position — where it is registered, whether the customer is a business in another
member state, whether reverse charge applies — arrives with it, and it is a
feature with a tax adviser in it rather than a column. Recording that it was
noticed is the whole of what this paragraph is for.

#### What is deliberately not built

No gateway, no invoice to the customer for the purchase, no recurring billing, no
refunds, no tax handling — all out of scope by the ticket. Beyond those, three
smaller absences worth naming rather than leaving to be discovered:

- **Nothing withdraws a request.** A customer who changes their mind tells
  somebody, exactly as they would about the request itself. The collector already
  removes a collected row whose request has gone, so the machinery is there when a
  screen for it is wanted.
- **Nobody is notified.** No mail to the operator when a request arrives, and none
  to the customer when it is fulfilled — the second being visible anyway, since the
  module appears. This is §8.14's honest gap in a smaller form, and the same
  argument applies: a notification needs a decision about what it may honestly say.
- **The operator cannot decline one on the screen.** They get in touch, which is
  what the page tells the customer will happen; a declined request is a
  conversation rather than a state.

---

### 8.16 An operator can say something, and it lands where the work is (XIV-120)

The previous iteration had `LicenseClientNotification` — a title, a summary, a
body, a date, the client it was for and a status. This one had nothing, which
meant **whoever runs an installation could see every customer and tell them
nothing**. Three sentences an operator knows and a customer needs:

* *"This installation will be unavailable on Sunday morning while we upgrade."*
* *"The invoice module gained payment terms; your existing invoices are
  unchanged."*
* *"Your trial ends in a week."*

All three were an email somebody sent by hand from their own client, if they
remembered.

#### Where it appears, and why not by mail

**On the customer's own dashboard**, as a widget (§8.3.1). Mail is a second
channel with its own deliverability problems, and §8.7 is a whole section about
how much has to be true before a customer's installation sends a message
reliably; none of that should stand between an operator and *"we are upgrading on
Sunday"*. This is information that belongs where the work is.

It is a widget rather than a banner welded into the layout because [XIV-66] had
just built the seam that makes a card a class and a template, and inventing a
second mechanism for the same job a week later is exactly what that seam exists
to prevent.

#### Which database it lives in, and why this is [XIV-102] in the easy direction

**In the control plane, in the registry half, read directly by every instance
that has to show it.** No collector, no interval, no copy.

§8.15 met this boundary from the other side and had no such luxury. A purchase
request is a **write** made by a customer's own request, §4.4 gives the
customer-facing instance's role `SELECT` on the registry tables and no write
privilege anywhere in that database, so that row had exactly one home — the
customer's own — and an operator sees it only because `tenant:purchase:collect`
copies it back. A notice is written by an operator on the instance that owns the
schema and is only **read** by a customer, and reading the registry is precisely
what the grant already permits. **The constraint that made that ticket expensive
makes this one cheap**, and neither is a workaround.

That was confirmed against the grant rather than assumed, and the confirmation
turned out to have teeth.

**The namespace is the grant.** The readable list is derived by walking the
control entity manager's mapping and taking the table name of every class under
`App\Registry\Entity\`, and nothing else. So a `Notice` declared in
`Xivi\ControlPlane\Entity` — which is where an operator's feature would naturally
be filed — would land on the *withheld* list beside `operator` and
`signup_request`, and the first customer dashboard to render would meet a
permission error.

**And the recipients are an entity rather than a `ManyToMany`, which is the
finding worth keeping.** A many-to-many's join table is not a class, has no
metadata, and is therefore *invisible to the grant generator*. The general form is
the part that outlives this feature: **anything that is a table but not an entity
is outside `readableTables()`**, and the only other member of that set today is
`doctrine_migration_versions`, which is named explicitly for that reason. Nothing
enforces this in general — a test would have to know what tables a future feature
means to read — so it is written down here and asserted for these two tables by
name.

**The proof is a role, not an argument.** The test creates the role, runs the
grant statements, opens a connection **as that role** and reads a notice through
it. That is why the reading class takes its entity manager as a constructor
argument instead of being a `ServiceEntityRepository`: a repository resolves its
own connection out of the `ManagerRegistry`, so the test could only ever have
exercised it as the suite's own privileged account, which is a test that proves
nothing while passing.

**The cost of that arrangement lands on a deploy, and it is worth stating.** A
release that adds a registry table means `deploy:registry-grants` has to be run
again — that is why the command derives its list from the mapping rather than
maintaining a script (§4.4) — and an installation that skips it gets a
customer-facing instance whose role cannot read `notice`. The widget asks on
every dashboard render, so the failure is immediate, loud and total for that
instance rather than latent: the landing page 500s, which §8.3.1 already decided
is the right behaviour for a widget that throws. That is the honest reading of
the trade this feature makes. A `deploy:check-grants` beside
`deploy:check-hosts` and `deploy:check-secrets` — asking the database whether the
role can read what this build intends to read — is the obvious next move and is
not built here; it is a bigger thing than a notice board and would deserve
deciding on its own.

**The author is a copy, and the reason is stronger than the usual one.**
`notice.author_label` is the operator's name as it was when they published, not a
foreign key to `operator` — because the reader the column exists for is a
customer, and §4.4 gives their instance no access to that table at all. A join
would be unreadable by the only party that needs the value. The ordinary reason
holds too: an operator later revoked or renamed must not rewrite the authorship
of something already published.

#### Everybody, or named customers

Both, as the ticket asked, and **they are not folded into one**: "no recipient
rows" and "everybody" would look identical on the screen and mean different things
in fact, because recipient rows cascade away with a deprovisioned customer, so an
announcement addressed to three companies would silently become an announcement to
the *entire installation* on the day the last of them left. A boolean says which of
the two somebody meant and no cascade can change it.

**A third case — "every customer who has module X" — was considered and is not
here.** It is a different kind of question. The registry knows which modules are
*enabled* for a tenant; what a customer has actually installed is their own
metadata (§6.1), one boundary and one database away, and answering it for every
customer is a collector's job. That is a feature, not a case in an enum.

**Addressing is all-or-nothing.** A notice naming a customer who is not there is
refused entirely, with the name in the message, rather than published to the ones
that resolved — because reaching three of four companies while reporting success
is this feature's characteristic failure wearing a different hat. So is a notice
addressed to named customers and naming none, which is refused for the same
reason: *the operator would believe they had told somebody.*

#### Who inside a tenant sees it, decided per notice

`NoticeAudience` is `Everyone` or `Administrators`, on the notice.

A maintenance window is for everybody who might sit down to work on Sunday; a
trial ending is for whoever pays, and putting it on the screen of a colleague who
cannot act on it is either noise or an awkward conversation somebody did not
choose to start. A global rule would have to pick one and be wrong about the
other every time, which is why the ticket asked for this and why the answer is a
column rather than a setting.

**The second case is coarse and says so.** A tenant's own authority model is
§8.4's grants — per person, per module, per verb — and none of it describes "the
person who pays", because nothing in the product has ever needed to. `ROLE_ADMIN`
is the nearest true thing an installation knows. That is honest for a trial
ending and would be dishonest dressed up as anything finer.

**A permission was considered and refused.** A `@notices` area on §8.4.3's second
axis would let a customer decide who reads announcements — and therefore let a
customer switch off a channel the operator is relied upon to have. The addressing
belongs to the sender here, which is the one place in this product where that is
true, and it is true because the sender is the party running the installation.

#### Dismissing, and where that write goes

**A customer can dismiss a notice, per person, and the row lands in their own
database.** §4.4 decides that rather than a preference: the customer-facing
instance may read the control plane and may write nothing there. **The feature
reads across the boundary and writes on this side of it**, which is the
arrangement the grant was built to force.

**Per person, not per tenant.** Dismissing is *"I have read this"*. A
tenant-wide dismissal would let whoever opened the dashboard first take a
maintenance window off everybody else's screen, which is the silence the whole
ticket is against.

**`notice_id` is an integer with nothing to point at**, since the row it names is
in another database. That makes it the same kind of value as a saved dashboard
layout's widget key (§8.3.1) or a stale `reference` (§7.6): data referring to
something outside this database, resolved where it is read and dropped when it
resolves to nothing. There is deliberately no process hunting orphans, because a
cross-database garbage collector is a much worse thing to own than a few bytes.

#### Stopping one, and what an operator can see

**A notice is live between `published_at` and `expires_at`, and withdrawing is
the second of those being set to now** — one concept rather than two ways of
saying *stop showing this*, free to disagree, with every reader having to remember
both. The cost is that an operator cannot afterwards tell whether something ran
out or was pulled, which is a fact about the past nobody has asked for.

**Withdrawing is not deleting.** The row stays on the operator's screen, marked
ended, because *"what did we tell them in March"* is a question somebody asks and
a list that answered it by having no row would answer it wrongly. That is the
purchase screen's argument for keeping fulfilled requests, reused.

**The operator's screen leads with what is live and states who each notice went
to.** Those are the two facts an operator's belief rests on, and both are the
kind that fail silently — a count that included ended notices, or a row that
printed another notice's customers, would leave the page looking exactly as it
does when it is right. So the count is asserted against a page holding both live
and ended notices, and the addressing is asserted with two notices addressed to
*different* customers, which is the only shape in which a query that ignores its
scope can be caught.

#### The widget costs a query in `panel()`, which is a departure

`DashboardWidget::panel()` is documented as cheap by contract (§8.3.1): it is
asked of every widget on every render, before the reader's layout is applied, so
a widget that counts rows there charges the page for a card somebody may have
hidden. `NoticeWidget` asks the registry whether anything is live for this
customer, which is a database read. **That is deliberate, and the alternative is
worse:** for a notice, "does this apply to you" *is* the question the database
holds, and a widget that returned a panel unconditionally would put a permanent,
usually-empty card on every dashboard in every installation — furniture, which
§8.10 and the purchase screen both refuse — and would make the one week it says
something the week nobody notices it. The cost is bounded rather than hand-waved:
an installation that announces nothing, which is most of them most weeks, pays one
indexed read on a connection that was already open.

#### [XIV-108] revisited, and the answer is no

The ticket asked whether this is the mechanism [XIV-108] was waiting for — a
signup that never provisions leaves somebody waiting in silence, and §8.14's own
honest gap is that nothing is mailed to the person waiting. **It is not, and the
reason is structural rather than a matter of effort.**

A notice appears in an installation, addressed to a tenant, on the dashboard of a
user. A stranded signup has **none of those three**: provisioning is precisely
what has not happened, so there is no tenant row to address, no database to hold
a dismissal, and no user account to sign in and read anything. The person is
waiting *outside* the product. Reaching them needs a channel that works before
they are a customer, which is the mail §8.12 already sends them their
confirmation on, and the thing genuinely blocking it is what §8.14 said: a
decision about what such a mail may honestly say.

So [XIV-108] is unblocked by nothing here and keeps its own ticket. What this
does give it is one thing: the moment a stranded signup *does* become a customer,
an operator has a way to tell them what happened.

#### What is deliberately not built

**No read receipts.** An operator can see that a notice is live and what it was
addressed to; they cannot see that anybody read it. Knowing that would mean
collecting a fact out of every customer's database — [XIV-102]'s collector
pointed the other way — and it is a feature rather than a column: a walk over
every tenant, a table of collected counts, and a page that says how old the
figures are. **This is the honest gap in this ticket**, it is the half of *"not
silent"* that is not answered, and the operator's screen says so on the screen
rather than leaving somebody to assume the number is somewhere. Reusing
dismissals as receipts was considered and refused: a dismissal is a button
somebody pressed, and reporting it as "read" would over-claim on exactly the
screen an operator uses to decide whether they have communicated.

**No scheduling.** Publishing is immediate. A notice dated for Friday is a real
thing to want and needs a third state on the operator's screen — live, ended, and
*pending* — which is more page than the feature has earned. The column is
compared against `now` rather than assumed, so the day it lands is a form field.

**No severity, no colour, no icon per notice.** Every notice is drawn the same
way, so nothing competes for attention by claiming to be urgent. The day a
genuine emergency channel is wanted it should look different from this one rather
than being this one with a flag set.

**No links, no markdown, no HTML, no image.** The body is plain text rendered as
plain text. The moment a notice can carry a link it is a channel somebody can be
phished through, on the one screen in the product a customer has no reason to
distrust — and an operator writing to every customer of an ERP they depend on is
a serious act that should not look like a campaign.

**No translation.** A notice is written once, in the language the operator wrote
it in, and shown to everybody exactly as written — including a customer reading
the rest of the interface in German (§8.4.2). Every other string in this product
comes out of a catalogue, and this one cannot: it is somebody's sentence, not a
key. The alternative is a form with one box per language, which is a real answer
for a deployment that needs it and is not this one.

**No summary.** The previous iteration had a title, a summary and a body; a
summary is a second thing to write that can disagree with the first. A title and
a body is what an announcement is.

---

### 8.17 A customer can reach whoever runs this installation (XIV-123)

The previous iteration had a `Support` module — tickets, replies, an FAQ. This
one had **no channel from a customer to the operator at all**: not a ticket, not
a contact form, not an address. A customer whose invoice module was behaving
oddly, or who wanted a module they could not see in the store, had whatever email
address they happened to be given when they signed up.

#### The pair this completes

§8.16 gave the operator a way to talk **to** customers. This is the return path,
and the two are one feature seen from both ends:

* an announcement is one-to-many, scheduled, and about the installation;
* a ticket is one-to-one, unscheduled, and about a problem.

Neither substitutes for the other, and building only the first would have been
odd — an operator who can broadcast and cannot be replied to.

#### Where it lives, and the constraint that decides it

A ticket is **written by a customer**, which is [XIV-102]'s direction and
therefore [XIV-102]'s constraint: §4.4 gives the customer-facing instance
`SELECT` on the registry tables and no write privilege anywhere in the
control-plane database. So the ticket cannot be written there, and it goes where
every write a customer's request makes goes — into their own database, as
`support_ticket` — with `tenant:support:collect` bringing it back for an operator
to read. That is `tenant:purchase:collect`'s shape with different columns, reused
rather than re-derived, and §8.15's rejection of an HTTP call to the control plane
is inherited word for word.

#### But the answer comes back the other way, and that is the design

Here is where this stops being [XIV-102] and starts being both tickets at once.

**The status and the reply live on the collected copy, in the control plane, and
the customer reads them directly.** Reading the registry is precisely what §4.4's
grant has always permitted (§8.16 is a whole section about how cheap that
direction is), so an operator who answers at 14:03 has answered on the customer's
screen at 14:03. There is no second collector pointing into every customer's
database, no push, and nothing that can be stale.

That decides the one thing about this feature that could have been got quietly
wrong: **`support_request` is an `App\Registry\Entity` class**, not one of
`Xivi\ControlPlane\Entity`'s — §8.16's rule that the namespace *is* the grant,
meeting a second feature. Filed beside [XIV-102]'s `purchase_intent`, which is the
obvious place for it, every customer's support page would have met SQLSTATE 42501.
The difference between the two tables is exactly the difference between the two
features: a purchase request is collected **for an operator to read**, and a
support ticket is collected so that an operator can **answer**. It carries §8.16's
deploy cost with it unchanged, and `CHANGELOG.md` names it as an action bullet the
same way.

#### The delay, decided rather than inherited

Collection means a delay, in one direction only, and it is worth being exact
about which:

| | Who is waiting | How long |
| --- | --- | --- |
| Customer → operator | nobody is watching a screen; the operator is not sitting in the product | one collection interval |
| Operator → customer | the person who asked, who came back to look | none at all |

**The leg that waits is the leg where nobody is watching.** That asymmetry is
what makes the interval acceptable, and it is why the alternative — a secret in
the public image — buys so little for what it costs.

Three things follow, and all three are built:

1. **`App\Monitoring\ScheduledJobs` carries `tenant:support:collect` at five
   minutes**, which is `signup:provision`'s cadence rather than
   `tenant:purchase:collect`'s ten, and for `signup:provision`'s reason: somebody
   is waiting rather than something is being counted. §4.5 exists because
   `tenant:purchase:collect` shipped into *no* list of cron entries at all; the
   list is now what `deploy:crontab` prints, so this job reaches a crontab
   without anybody remembering it.
2. **The customer's own screen says so.** A ticket nobody has collected reads
   *not received yet* rather than borrowing a status it has not got — §8.11's
   *absence says it exactly*, pointed at the person the delay happens to. That is
   the honest rendering, and it is what stops a quiet product looking like a
   broken one. The flash after raising one says the same thing in words, and
   deliberately does not thank anybody or promise a time (§8.15's rule).
3. **The operator's screen prints the collection time on every row**, and its
   empty state names the command. This matters more here than on the purchase
   screen: an empty support queue and a cron entry nobody ever wrote look
   identical, for ever.

**No interval is printed anywhere in the product.** How often tickets are
collected is a line in the crontab of whoever runs the installation, and a figure
on a customer's screen would be this repository guessing at somebody else's file
— §8.15's refusal to promise "within 24 hours", one screen over.

#### Replies are in scope, and the shape is one column

The ticket asked whether replies were a first slice or a later one. They are in,
because **the mechanism is already paid for**: a status the customer can see
requires a control-plane row the customer can read, and once that exists an
operator's answer is one `TEXT` column on it arriving by the same route. Building
the whole read path and then telling the customer to check their email would have
been the odd outcome.

What is *not* built is a thread. There is one reply per ticket; sending another
rewrites it, and `replied_at` moves with it, because a second version of an
answer is the answer. A customer cannot answer back in place — they raise another
ticket, and the operator sees both.

The reason is a boundary rather than effort. **A customer's message crosses the
collector and an operator's does not**, so a two-sided thread is not one feature
symmetrically applied: it is a message table on each side, an interleaving that
has to survive a collection interval, and an operator's screen that has to make
sense while half the conversation is in flight. That is a conversation product,
and it is a much bigger thing than this ticket. What is here is the honest first
slice: **a customer can ask, an operator can answer, and both can see where it
has got to.**

**Replying does not move the status**, which is a decision rather than an
oversight. An operator who answers and considers it finished closes it; one who
answers and expects to hear more leaves it in progress. A hidden state change on
a screen that also has a visible state control is how the two stop agreeing.

#### The status, and the states that are not there

`SupportStatus` is `Open`, `InProgress`, `Closed`.

§8.15 refused a status column outright on a purchase request, and the argument
was good: fulfilment there is *observable* — the customer either has the module or
they do not, and a column would have been a second copy of a fact free to
disagree with it. **None of that transfers.** Whether somebody has picked up a
question is not observable from anywhere; it exists in an operator's head until
they say so, and a customer staring at silence is the entire problem this ticket
is about. So a status is a real thing to store here, and each case earns its place
by saying something nothing else on the row says — which is why there is no
`Answered`, a reply being visible two columns away, and no priority, category or
SLA, each of which is a promise this installation knows nothing about the
arrangement between the two companies to keep.

Any state may follow any other. A lifecycle (§5.8) would be modelling a process
nobody has described, and an operator reopening something they closed by mistake
is an ordinary Tuesday.

#### Who may raise one: everybody signed in

Not administrators only, and not a per-installation setting.

**Raising a ticket commits nothing** — no money, no install, no change to the
installation — which is the whole of the difference from [XIV-102]'s `buy`, where
the argument for a separate grant was that pressing a button obliges the company
to pay somebody. And **the person who met the problem is the person who can
describe it**: routing a bug report through an administrator means the
description travels through somebody who did not see it happen, or does not
travel at all.

**A per-installation setting was refused**, and not only on the grounds that it
is more than this needs. It is a switch whose only possible effect is to stop
somebody with a problem reaching the people who can fix it — §8.16's argument for
refusing a `@notices` permission, pointed the other way: the channel between a
customer and their operator should not be something either end can quietly close.

The firewall is the whole of the access control, and
`SupportTicketTest::testSomebodyWhoIsNotSignedInCannotReachIt` proves it through
a real request, on the POST as well as the GET, because a form that is not drawn
is not a check.

**The tickets are the company's, not the reader's.** A colleague who asked the
same question on Tuesday should find the answer rather than ask it again, which
is most of what a screen buys over an email. The name of whoever raised each one
is on the row, so nothing is anonymous — it is simply not private between
colleagues, and the page says so where somebody deciding what to type will meet
it.

#### What a ticket carries, and who does not cross

A subject, a body, a date, who raised it, and a status. The ticket asked for
exactly that and nothing was added.

**Who raised it does not cross**, on §8.15's two-column pattern and for §8.11's
line — held here where crossing it is most tempting, because an operator would
obviously like to know whom to write back to.

**They do not need to, and that is what makes this line free rather than merely
principled.** The answer is delivered inside the product — it lands on the
collected row and the customer reads it on the screen they raised the ticket on —
so an operator answers the company without ever learning which of its staff typed
the question.

#### The reference, and a rebuilt database

The collected copy is matched on a random `reference` generated in the customer's
database, on the pair `(tenant, reference)`.

The primary key would have been the obvious choice and is wrong. **Ids are a
sequence per database**, so a customer whose database is rebuilt — `tenant:reset`
does exactly that, and §4.1's rebuild is a supported operation — starts again at
1, and the next collection would find "ticket 1" and overwrite the row holding an
operator's answer to a different question. And the tenant is half of the key
because a reference is a value produced inside a *customer's* database: matching
on it alone would let one customer name another's row by producing the same
string.

#### The collector removes nothing, which is where it differs from [XIV-102]'s

[XIV-102]'s collector deletes a collected row whose request has gone from the
customer's database, and the reason is good: a queue half full of requests that
no longer exist is a queue somebody stops trusting.

**The support collector deliberately does not**, because the operator's half of
the row — the status, the reply, who wrote it and when — exists **only here**.
Deleting it would destroy the answer rather than tidy up after it, and *"we
answered them in March and then their database was rebuilt"* is a question
somebody asks. Nothing in this system deletes a support ticket.

For the same reason the collector writes the customer's three columns and touches
nothing else. A collection that rewrote the whole row — the obvious
implementation, and the one an upsert produces — would discard an answer whenever
a run overlapped with somebody typing one, on a job that runs every five minutes,
and the visible symptom would be a customer shown their own question back with
the answer gone.

#### The FAQ is out of scope, and its home is named

The previous iteration's `Support` module bundled tickets, replies **and an FAQ**,
and the third of those is a different feature wearing a similar name. An FAQ is
**documentation**, and this project has a documentation site in its own
repository — <https://praesidiarius.github.io/plc-xivi-docs/>. If a customer's
question has a written answer, that is where the answer belongs: written once,
versioned with the product, readable by somebody who has not signed in yet, and
editable without a deploy. Reproducing it inside the application would mean a
second place for the same sentences to be wrong in.

**And no link to it from the support page either**, which is the smaller decision
inside the larger one. The docs site's address is a fact about *this* deployment
— a company that forked Xivi has its own — so putting it on a customer's screen
means a new environment variable and a new deployment fact, for a link. `README.md`
names it for the people who can act on it. That is where it stays until somebody
asks.

#### What is deliberately not built

**No mail, in either direction.** Not to the operator when a ticket arrives and
not to the customer when it is answered. §8.7 is a section about how much has to
be true before an installation sends a message reliably, and none of it should
stand between somebody with a problem and the people who can fix it — §8.16 made
the same call for notices. The customer sees the answer where they asked; the
operator sees the queue where they work.

**No attachments and no screenshots.** A screenshot is the single most useful
thing a support ticket could carry and it is not here, because it is a file
upload crossing a tenant boundary — stored in the customer's database, copied or
referenced by a collector, and served to an operator on a different host. That is a feature, not a column, and it is the first thing to
build when this one has been used in anger.

**No read receipts and no "the operator has seen it".** `InProgress` is somebody
*saying* they have picked it up, which is a claim a person makes rather than a
fact a system observes — and §8.16's refusal to report a dismissal as "read"
applies here word for word.

**No search, no paging, no filtering.** One company's tickets are a list of tens
over the life of an installation, and the operator's page is every company's list
sorted by who has waited longest. When it stops being tens the answer is the same
one §8.10 gives for the tenant list: a search box, and the ordering reaching SQL.

**Nothing withdraws a ticket.** A customer who solves it themselves says so, in
the ticket, which is what a person does anyway.

---


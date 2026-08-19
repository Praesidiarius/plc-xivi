## 6. Extensibility

Three composable layers, all "one codebase, no forks":

- **Fields** extend an entity's *shape* → metadata rows, no code.
- **Events** extend an entity's *behavior* → module bundles with EventDispatcher
  subscribers.
- **View extensions** extend an entity's *UI* → tagged services contributing panels.

### 6.1 Where a shape comes from

Three sources, and the discipline is knowing which one a given need belongs to:

- **Preset** — a named field set for *one module*, shipped with that module, in
  code, versioned alongside it. `basic` and `extended` for Contact. There are a
  handful per module, they are identical for every customer who picks one, and
  changing one means the module changed — so a release is the honest way to ship
  it. *Built:* a preset names a subset of the blueprint's own fields rather than
  redeclaring them, so there is one description of what a contact can hold and a
  couple of answers to how much of it you want. `tenant:module:install` takes
  `--preset` and lists the choices when you do not.

  **Fields only, never collections**, and not arbitrarily: a field a preset left
  out can be added back in the editor (§5.4), so choosing the smaller preset is
  reversible. Nothing can add a *collection* back — that needs a table, which only
  the installer creates — so a preset omitting one would be a decision the
  customer could never undo. Every collection a module declares is installed every
  time — and since §7.2.1 a collection the module gained *later* can be taken as
  well, which is the same rule arrived at from the other side: the installer is
  still the only thing that makes a table.

  Nothing records which preset was used. Storing it would only invite something to
  re-apply it later, and a preset is a seed with no further say.
- **Template** — how a customer is set up *across* modules: install these modules
  with these presets, then add these fields. "Dentist practice" is a template, not
  a preset; nothing about it belongs to any single module. It is data, in the
  control plane next to plan and enabled modules, because adding one means a new
  market rather than a code change — and needing a deploy to onboard a vertical is
  v1's compiled-in module list wearing a different hat.

  *Its second half is a shape pack, and §6.6 is what that may contain (XIV-141).*
  "Then add these fields" was one clause here and turns out to be the load-bearing
  one: a vertical is *"Contact with different fields"*, a preset can only ever mean
  *"Contact with fewer fields"*, and the gap between those two sentences is the
  whole of a trade. §6.6 draws the boundary — a pack may do nothing a customer
  could not do by hand in the editor (§5.4) — finds that the boundary encloses
  almost nothing until the editor can set a choice field's `choices`, and decides
  what the file may never contain whatever the editor grows.
- **Metadata rows** — anything one specific customer needs. The moment a preset is
  named after a customer, it has stopped being a preset.

A preset is a seed, not a type. Once installed, the tenant's definitions are the
truth and the preset has no further say — which is also why presets do not make
§7.2 worse: customers are *designed* to diverge from each other, so "we do not
retro-fit blueprint changes" is the stated model rather than a limitation.

**That rule is unchanged, and §7.2.1 is its one sanctioned exception (XIV-70).**
Nothing retro-fits: a blueprint that grows still reaches into no installation, a
release still rewrites nobody's definitions, and a deploy still changes no
customer's shape. What exists now is the other half of the sentence — an
*explicit* way to say yes. A customer is shown what their installed module is
missing relative to the blueprint they could have, including the fields a smaller
preset left out, and takes it item by item or dismisses it. It only ever adds, and
a key they already have is never offered — whatever they have since renamed or
narrowed it to — so the thing this rule exists to protect is protected by
construction rather than by care. The preset choice is therefore no longer a
one-way door in the direction that mattered: the smaller preset can be grown into
the larger, while nothing can take away what was installed. See §7.2.1 for the
whole of it, including what happens to a field somebody deleted on purpose.

Templates reference presets instead of duplicating them, which is why they need
nothing new from the engine: a template is a list of installations it already
knows how to perform, plus rows it already supports.

### 6.2 How far along a module is (XIV-7)

A module has a **state**, platform-wide: `development` or `published`, a closed
set that grows by adding a case — early access is the obvious next one.

**Global, never per tenant.** Whether a module is finished is a fact about the
module. A customer being offered a half-built one because somebody flipped a row
on their tenant is §4's configuration drift in a new costume, so there is nowhere
to say it per tenant and nothing to keep in step.

**In the control plane, not in the blueprint.** The tempting alternative is a
field on `ModuleBlueprint`, which would be global for free and impossible to
disagree with the build. It was rejected on the same rule that puts presets in
code and templates in data (§6.1): a preset changing *is* the module changing,
whereas publishing is a decision about whether customers may have it — the same
kind of decision as a tenant's plan or status, and those live in the control
plane. It also means a module can be pulled back out of the store without a
deploy, on the day that matters.

**A module with no row is in development.** That is what makes "a new module
starts in development" free rather than a sync step whose only job is to write
down the default. A row appears the first time somebody decides otherwise, and
survives being moved back, because when a module left the store is worth knowing.

The two halves — what exists (the build, via `ModuleRegistry`) and what state each
is in (the control plane) — are joined in exactly one place, `ModuleCatalog`. It
is what knows that a state row can name a module this deploy no longer ships:
listed and flagged, never offered, since the store cannot install what the build
does not carry.

**It gates the store (§6, XIV-6) and nothing else.** Installing is deliberately
not gated: a module is developed by installing it somewhere, which is the exact
case the state exists to describe, so `tenant:module:install` names the state and
proceeds. Nor does taking a module out of the store uninstall it anywhere — a
state says what may be installed from here, never what is removed.

*Updated by §6.5 ([XIV-101]).* The row carries a second decision now — what this
deployment charges — and it gates the store alongside the state rather than
through it: a module is offered when the platform says it is finished **and** this
deployment says it is for sale, and either saying no is enough. The two are
deliberately separate axes; "published and not for sale" is a real and useful
combination. The sentence above about uninstalling is the one that transfers word
for word, and §6.5 leans on it rather than restating it.

### 6.3 The store, and installing without a shell (XIV-6)

A customer who signs up lands in an empty installation. Until the store, the only
way to put anything in it was `tenant:module:install` — the operator's shell, not
theirs — which made self-service onboarding (XIV-64) half a feature.

**Browsing reads the control plane; installing writes only the tenant's
database.** That split is the load-bearing one and it is not new: what may be
offered is `ModuleCatalog::offeredInStore()`, published *and* present in this
build (§6.2), while "does this customer have module X" is answered from their own
metadata and nowhere else. `Tenant::$enabledModules` is not involved and must not
become involved — a control-plane write on a tenant-facing install path is exactly
what [XIV-60] is separating out.

**One installer, two front doors.** The store calls the same
`ModuleInstaller::install($blueprint, $preset, $locale)` the command does. A
headless deployment keeps its path, and a module installed from a screen is the
same module — which is a property worth having a test compare rather than a claim
worth repeating.

**The preset is permanent and the wizard says so.** §6.1 does not retro-fit, and
the additive upgrade that would make the choice reversible is [XIV-70], which is
deliberately *not* a prerequisite: there is nothing installed today that needs
upgrading, and building the store first is what tells anybody whether the smaller
preset is ever chosen. So for this iteration the screen is simply honest — every
preset's fields listed in full, radios rather than a select so both futures are on
the screen at once, and the sentence *this choice cannot be changed later* above
the choice rather than under it. A friendly dropdown is the worst possible
presentation of an irreversible decision.

*Updated by §7.2.1 ([XIV-70]).* That upgrade exists now, so the sentence has
been corrected rather than kept: the fields a smaller preset leaves out can be
taken afterwards, one at a time, and a screen claiming otherwise would be telling
somebody they are making a decision they are not. The screen still says the part
that is still true and was always the load-bearing half — **nothing rewrites what
is installed here** — and the layout argument above is unchanged, because the
choice is still worth reading before it is made.

**Requirements are refused with guidance, never chained.** Invoice needs Contact
and Order; the installer already refuses and names what is missing (XIV-23). The
store checks first so the install is not *offered*, because finding out on submit
— after choosing a preset nothing can change — is a worse way to learn it. It
does not chain-install, and the reason is the paragraph above: each chained module
carries its own permanent preset choice, so a chain makes two irreversible
decisions on somebody's behalf while they think they are making one.

*Sharpened 2026-08-20.* Checked first, but **shown anyway**. A module whose
requirements this tenant lacks stays on the shelf, with the missing piece named
and the way there stated: install Contact first, or take the upgrade that adds
what it needs (§7.2.1). Hiding it was considered and rejected, because hiding
turns the store into a different catalogue per tenant, and the customer who would
want the module is exactly the one who never learns it exists. Withholding by
*deployment* decision is a different axis and stays as it is: §6.2's state and
§6.5's `unpriced` and `not_for_sale` are the operator saying "not offered here",
which is theirs to say. "You cannot have this *yet*" is a sentence that must
always come with directions.

**Nothing about a module is written here.** Presets, requirements, collections and
labels all come off the blueprint, so a module added to a future build appears in
the store complete. Whether it appears at all is its state (§6.2), which is a row
somebody can change without a deploy.

Not in it, on purpose: payment, since every module is free in this iteration and
the state enum already anticipates more states; and uninstalling, which means
deciding what happens to the records and is a larger question than installing one.

*Half-corrected by §6.5 ([XIV-101]).* "Every module is free in this iteration" was
true when it was written and is why the migration that added the price column
backfills every existing row to `free` — that is recording a fact rather than
inventing one. What has changed is that free is now a *decision* somebody made
rather than the absence of one, and a module nobody has priced is withheld from
the store instead of given away. Payment itself is still not in this: [XIV-102]
is what a customer sees and what they pay with.

*And finished by §8.15 ([XIV-102]).* What a customer sees is the price, where
there is one, and nothing at all where there is not — a free module's screens are
byte-identical to what this section describes, which is the property that made the
store's existing tests pass unchanged. What they pay with is **nothing**: there is
no gateway, a priced module cannot be installed from here at all, and the button
records a request an operator answers. "Uninstalling is not in this" is unchanged
and is load-bearing for that argument, which is why "install it and mark it
unpaid" was rejected rather than deferred.

### 6.4 Asking an installation what it is (XIV-76)

§6.1 has a consequence that only shows up when somebody new arrives: **the
repository cannot describe a tenant.** A module's blueprint is the shape a
customer was installed with, their own definitions are the truth from that moment
on, and nothing retro-fits a blueprint change into them. So reading
`ContactModule.php` and assuming it describes a contact is reading the *starting*
shape — and being wrong about it silently, in exactly the way [XIV-70] is about.

That makes "what fields does this customer's `contact` have, of which types, with
which options, which variants, which collections, which of them are derived" a
question with **no answer in the repository and, until this, no command behind it
either**. It is per tenant, it is structured, and a table in a terminal is a poor
shape for it.

#### The introspector, and the two front doors on it

One service, `Xivi\ControlPlane\Introspection\TenantInspector`, answers three
questions as plain arrays: which tenants exist and whether each one's schema is
current; what one tenant's installed modules actually look like; and what the
module catalogue holds. It reads through the application's own services —
`TenantRepository`, `ModuleCatalog`, `MetadataRepository` behind `TenantSwitcher`
— and writes no SQL of its own, because a second way of asking the engine what it
holds is a second thing to keep in step with the engine.

Two callers: `bin/console tenant:inspect`, and a committed MCP extension. **The
command is not an afterthought.** Nothing an agent can ask may be tool-only —
Mate's server is a process that can drop mid-session, and an agent told to prefer
tools it can no longer see is worse off than one that never had them. `--json`
prints the structure the tools return, byte for byte, so a wrong tool result can
be told apart from wrong data.

#### Where a project's own MCP tools live, and why it is a package

Mate discovers extensions from `vendor/composer/installed.json`, by
`extra.ai-mate` in a package's own `composer.json`. It also always loads the
*root* project as a pseudo-extension, whose scan directory defaults to
`mate/src` — and `mate/` is gitignored, which is the whole reason the setup here
delivered nothing to a second developer.

So the extension is an ordinary composer package, `packages/xivi-mate`, reached
through the path repository the modules already use. It is committed, it reaches
a fresh clone, and it earns three things a directory could not: it appears in
`mate debug:extensions` and can be switched off in `mate/extensions.php`, its
`INSTRUCTIONS.md` is aggregated into the server's MCP handshake, and — the
decisive one — **it is a `require-dev` package, so `composer install --no-dev`
leaves it out of the production image entirely.** That is a stronger dev-only
guarantee than the `exclude:` list in `config/services.yaml`, which the
introspector and `tenant:inspect` get as well.

It is a fourth deptrac layer sitting *above* the application rather than beside
the modules, and the direction is the point: the tools may depend on the app, and
nothing in the app may depend on the tools.

**The bridge boots a fresh kernel per call and shuts it down.** Mate's server is
its own process with its own container, so a tool reaches this application by
constructing `App\Kernel` — the project's autoloader is already in scope. Caching
that kernel is the obvious optimisation and is wrong three times over: a held
tenant connection and metadata cache is §7.4's cross-tenant leak in a process that
lives for an afternoon; a held connection **blocks `DROP DATABASE`**, which is
what the lifecycle tools do; and a container compiled before an edit answers
questions about the code after it, which is [XIV-63]'s stale-artifact failure
disguised as a broken tool.

#### Destructive tools are exposed, and the argument for withholding them fails

The instinct is to expose reads only. It does not survive contact:

- **An agent with a shell can already run every one of these commands.**
  Withholding them changes ergonomics, not authority.
- **It pushes agents toward improvising.** Before [XIV-72], rebuilding a test
  tenant here meant hand-written `DELETE`, `DROP DATABASE` and `DROP ROLE`, which
  is strictly more dangerous than a tool that names the database, the role and the
  record count before it acts.
- **The commands already ship their guardrails**, and a tool that *calls the
  command* reuses them rather than reimplementing them: the confirmation defaults
  to no, an unattended run is refused outright without `--force`, and
  `tenant:reset` refuses an unsatisfiable module set before touching anything.

What a terminal did for free was somebody *reading the warning*. Nobody reads it
here, so both lifecycle tools take a census before acting — database, role,
hostnames, installed modules — and return it in the result under `destroyed`, or
under `would_have_destroyed` when the command refused. An agent that has removed
the wrong tenant can say which one, out of the same message that told it the call
worked.

Provisioning, installing a module, migrating and creating users are deliberately
**not** tools. `bin/console list tenant` already prints them with their
descriptions, and wrapping a command that describes itself buys ergonomics while
doubling the surface to keep in step.

### 6.5 A module can have a price, and the operator sets it (XIV-101)

Modules were free, and §6.3 said so in as many words while leaving payment out of
the store on purpose. This is the first half of undoing that: **the price
existing, being set, and being readable.** Payment itself, and what a customer
sees in the store, are [XIV-102] and depend on this.

The governing sentence is that **what a module costs is not the code's business**.
The company deploying Xivi decides what its customers pay, and the whole of the
design below follows from taking that seriously rather than from anything about
money.

#### Where it lives, and why not on the blueprint

**On the control-plane `module` row, beside `state`.** Not on `ModuleBlueprint`,
and this is [XIV-7]'s argument reused rather than a new one (§6.2).

A blueprint is *code*. It ships identically to every deployment, so a price in
`packages/invoice/` would be a price every installation inherits and none of them
chose — and the deployments differ on exactly this point: one sells the invoice
module, the next bundles it into a contract it negotiates per customer, a third
runs this for one company and sells nothing at all. `App\Registry\Entity\Module`
already carries `key` and `state` for that reason, and `ModuleCatalog` is already
the seam where the build's half and the control plane's half meet.

**No `packages/*` blueprint names a price, and none may.** There is nothing to
enforce that with a test, because there is nothing in `ModuleBlueprint` to put one
in — the absence is the enforcement, and the day somebody adds a `price:`
parameter to that constructor is the day this section is what they should be made
to read.

#### Three decisions and one absence, and the absence is the load-bearing part

`ModulePricing` has four cases where the ticket asked for three, and the fourth
is what makes the three mean anything.

| | what it says | offered in the store |
| --- | --- | --- |
| `unpriced` | nobody has decided | no |
| `free` | it costs nothing | yes |
| `priced` | it costs this much | yes |
| `not_for_sale` | this deployment does not sell it | no |

**A null price is not free.** Collapsing "free" and "no price set yet" is how a
module ships at zero on the day somebody adds the column, and nothing anywhere
says it happened. So `unpriced` is a value in the column rather than an absence of
one — it has to be, because unlike `state`, the price cannot borrow "no row at
all" as its default: the row is frequently already there for the other decision.
A module somebody publishes and does not price is therefore explicitly undecided,
and is **withheld from the store** until somebody says which of the other three it
is.

That last part is a behaviour change and the visible cost of the ticket: before
this, publishing was sufficient. It is deliberate, it is said at every point where
somebody could be surprised by it — `module:list`, `module:state` at the moment of
publishing, and a banner at the top of the operator screen — and the alternative
is the failure this whole section exists to prevent.

**`not_for_sale` is not `development` in different words.** `development` is a
statement about the *module*: it is not finished, platform-wide, for everybody.
`not_for_sale` is a statement about *this company's price list* for a module that
is finished. A deployment that bundles the invoice module into a negotiated
contract, or has retired it, needs to say so without telling every reader that the
code is unfinished. The two axes are independent and either saying no is enough;
folding them into one enum would have produced a four-value list in which
"published and not for sale" had no spelling.

**A not-for-sale module is not listed at all**, rather than listed and unbuyable.
The open question the ticket left, decided: the store is a place to obtain
modules, so a row nobody can act on is an advertisement for something the
deployment has decided not to sell, and the reader's only available response to it
is to ask why. A deployment that genuinely wants to tease something is asking for
a "coming soon" list with a date on it, which is a different feature and would be
badly served by this one pretending to be it.

**Zero is refused as a price.** `priced 0.00` is `free` spelled in a way nothing
can distinguish from a form somebody submitted before finishing, so `ModulePrice`
throws on it — and rounds before it judges, so `0.004` is refused as the `0.00` it
was about to be stored as. Three states only stay distinguishable if the boundary
between two of them cannot be reached by typing a number.

#### One-off, not recurring — and what was rejected

**Decided: a one-off price.** One number, per module, and no period.

Recurring was the serious alternative and it changes the data model rather than a
field. `Tenant::$plan` exists and defaults to `standard`, which looks like
subscription thinking already in the air — and it was checked rather than trusted:
**nothing consumes it.** It is displayed by `tenant:list`, by the tenant list page
and by the introspector, it is written once at provisioning from a signup, and no
code anywhere reads it to decide anything. It is a label.

So "recurring" here would not be a `period` column added to a working billing
system. It would be a `period` column with **no** billing system behind it, and
the things it implies do not exist and are each their own ticket: a billing period
and where its boundaries fall, renewal, proration when somebody installs
mid-period, a grace period, dunning, what happens to an installed module when a
renewal fails — and that last one collides head-on with §6.2's rule that nothing
here uninstalls anything. Shipping the column and none of that is worse than
shipping neither, because it looks like the feature: a screen that offers "per
month" is a screen that promises somebody will be charged monthly.

**A one-off price is the smaller honest thing**, and it is not a dead end. When
recurring is genuinely wanted, `ModulePricing` grows a case the same way
`ModuleState` was designed to, and by then there will be a purchase record for a
period to hang off — which is where it belongs, since a period is a term of a
transaction rather than a property of a module. Rejected along with it: putting
`plan` to work as a pricing tier, which would have made a per-tenant label into a
billing input while nothing validated it and while no tenant's plan had ever been
chosen by anybody deciding about money.

#### The currency is the instance's, and `InstanceCurrency` does not fit

**A price list is an instance fact, not a tenant fact.** The deployment sells in
one currency; a tenant whose profile says something else does not change what the
deployment charges.

`Xivi\Core\Money\InstanceCurrency` is named for the instance and looks like the
answer. It is not, and reusing it fails twice in opposite directions — neither
failure being about the name:

- Its one implementation is `App\Tenant\Settings\ProfileCurrency`, which reads the
  **tenant profile** (§8.6) — the currency a customer writes their *own* invoices
  in. Rendering this deployment's price list through it would relabel francs as
  euros for a customer whose profile says EUR: the same digits, a different claim,
  agreed to by nobody at either end.
- A control-plane request resolves no tenant by construction (§8.9), so
  `ProfileCurrency` correctly returns null there, for ever. The one page on which
  somebody decides what a module costs would be the one page unable to say what it
  costs it *in*.

So this is **not a second currency concept**: same ISO 4217 code, same
`Money\Amount`, same two decimal places, and `App\Registry\Pricing\PriceCurrency`
deliberately does **not** implement `InstanceCurrency`, so that it cannot be
autowired into a field type by somebody who reads the interface name and stops
there. What differs is whose fact it is.

**It is a deployment parameter, `PRICE_CURRENCY` → `app.price_currency`**, and
that needs defending because the ticket is emphatic that a *price* must not live
in `.env`. It must not, and the argument does not transfer. A price changes, which
is the reason this ticket exists; a deployment's selling currency is picked once
at installation, and changing it does not adjust a price — it invalidates every
figure on the list at the same moment, since 49.00 CHF and 49.00 EUR are not the
same offer. That is a re-pricing exercise with a person in it, and making it need
a deploy is the correct amount of friction. It is also §4.4's shape for
`app.control_plane_host`: a fact about where and how this software is installed,
set in the environment, therefore identical in both images with nothing to keep in
step.

Rejected: a single editable row in the control plane — a table, a row, a column, a
screen and a migration for a value that changes roughly never. The note worth
leaving is what would change the answer: the day a deployment sells into two
markets in two currencies, this is wrong and a per-price-list currency is right.
That has exchange rates behind it, exactly as `CurrencyFieldType` says about
per-record currencies, and it is a feature rather than a field option.

**Empty is a real answer and is the default.** §8.6 refuses to guess a currency
for a customer because a guessed one is wrong quietly and surfaces on the first
priced thing they print; the same holds one level up. Unset, prices are bare
numbers and the operator screen names the variable.

#### The money is §5.9's money

A decimal string at two places, `NUMERIC(12, 2)` in the column, arithmetic through
`Money\Amount` on `brick/math`, scale taken from `Amount::SCALE` so this and an
invoice line round from one constant under one rule. Nothing on the path from the
request parameter to the column is ever a float. A system that got money right in
every customer's documents and then priced its own modules in `float` would be an
embarrassing exception, and the exception would be written by whoever found a
`float $price` easier to add than to read this paragraph.

#### Changing a price touches nothing anybody already has

**This is [XIV-67]'s argument about payment terms, and it transfers exactly.**
What was agreed is a fact about the transaction rather than a live lookup, so
raising a module's price must not retroactively change what an existing customer
is deemed to owe, and must not uninstall anything.

Structurally that is already true and stays true by construction: a customer's
modules live in *their own* database, are put there by `ModuleInstaller`, and are
read back out of their own metadata (§6.1, §6.3). Nothing on that path consults
the control plane's price column, and `ModuleCatalog::priceAt()` writes two columns
of one control-plane row and reaches nothing else. §6.2 already settled the same
point for state — a decision here says what may be obtained from now on, never
what is removed — and the price inherits it rather than restating it.

**Proved rather than asserted.** `tests/Functional/ControlPlane/ModulePriceTest.php`
provisions a real tenant, really installs a module into it, really writes a record
through `RecordWriter`, and photographs everything observable about the result —
the module definition, its table, every field with its label and requiredness, the
record count and the records' data. It then walks the price through free, priced,
a **rise**, and a withdrawal from sale altogether, and compares the photograph. A
test asserting "the price setter does not call the installer" would have passed for
any number of ways of doing the wrong thing indirectly.

The forward-looking half is a rule for [XIV-102] rather than code here: when a
purchase is recorded, the price goes onto that record as a **copy**, exactly as an
invoice stores its own due date (§5.16) and its own totals (§5.9). Nothing about a
sale is ever recomputed from this row afterwards. *That has landed and §8.15
records it*: the copy is on the customer's own purchase-request row, the collector
that shows it to an operator carries it across untouched and never consults this
class at all, and raising a price is proved not to move a figure somebody was
already quoted.

#### Reading and setting land on opposite sides of the [XIV-96] split

The tension is real and is resolved rather than noticed.

`App\Registry` stays in `src/` and is compiled into the customer-facing image,
because it is what a customer's own request needs in order to be served at all
(§3.1). That includes the two new columns, `ModulePrice`, `PriceCurrency` and
`ModuleCatalog` — **reading a price is a customer-facing concern**, since [XIV-102]
will draw it in that customer's store.

The operator screen that *sets* one is `Xivi\ControlPlane\Controller\ModulePricingController`,
in the package, and is therefore absent from that image: §4.4's builder stage
refuses to finish if the namespace survives anywhere under `/app`. Nothing in
`src/` or `config/` names it, which is what
`tests/Unit/Deployment/ControlPlaneIsOptionalAtBuildTimeTest.php` checks.

**And the guarantee underneath is the grant, not the routing.** §4.4 gives the
customer-facing instance's role `SELECT` on the registry tables and nothing else,
so `UPDATE module SET price_amount = …` from the process facing the internet is
refused by PostgreSQL whatever a controller there does. `ModuleCatalog::priceAt()`
therefore joins `moveTo()` on the short list of **writers that live in `src/` and
are only ever called from the package** — §4.4 names that list and it now has two
entries. Splitting the writer out into the package was weighed and rejected: it
would put half of one entity's invariants in `src/` and half in a bundle, so
`ModulePrice`'s rules would be enforced by whichever half a future caller happened
to go through.

The screen keeps [XIV-58]'s boundary as well: every value on it is a `module` row
crossed with the blueprints this build compiled in, so it opens no tenant
connection, and the test asserts that the same way `TenantListTest` does.

**It is also the second page on that surface**, which XIV-58's template said to
wait for before adding a nav. So the header moved into a partial both pages
include, rather than being copied.

#### Readable through one seam

`ModuleCatalog::price()` mirrors `state()`; `CatalogEntry` carries a
`ModulePrice`; `CatalogEntry::isOfferedInStore()` holds the whole rule — in the
build, published, and for sale — so the store, the operator screen and the
introspector ask one question rather than three each composing their own. There is
no second service reading the `module` table, and there must not be.

`module:price` exists beside the screen for the reason §6.3 gives about
`tenant:module:install`: a page is not a reason to take a command away, and a
headless deployment has no browser pointed at the control plane.

*Read by one more thing since §8.15 ([XIV-102]).* `ModuleStore` asks
`ModuleCatalog::price()` for every offer it draws, which is the fourth reader and
the first customer-facing one. It is worth noticing that this is exactly what the
[XIV-96] paragraph above predicted: reading a price is a customer-facing concern,
so the read side of this feature compiles into the public image and the operator
screen that writes it does not.

### 6.6 A vertical as data, and whether it can be uploaded (XIV-141)

§3.2 closed modules. The question it left open is the interesting half: a vertical
is mostly *shape* rather than behaviour, shape is data (§5), and data does not need
a deploy — so can somebody who is not us hand a customer a file that turns their
Contact module into a law firm's, or a care home's?

The answer is **not yet, and the obstacle is not the file format.** What follows is
the whole of it, because the finding that decides it is one nobody expects.

#### "Preset" is the wrong word, and §6.1 already has the right one

The middle position as it was proposed — *modules stay closed, presets are open* —
does not survive contact with what a preset is. A `ModulePreset` is a `key`, a
`label`, a `description` and **a list of field keys taken from the blueprint's own
fields**. It cannot add a field, rename one, reorder anything, or change a field's
options. It is a subset and nothing else.

So a shareable *Law Firm preset*, in the word's actual meaning, is **Contact with
fewer fields** — and there is no arrangement of the contact module's nine fields
that makes a law firm. The phrase the ticket reached for was *"Contact with
different fields"*, and *different* is precisely the thing a preset cannot express.
Worse, it would be redundant even where it works: since [XIV-70] a customer can
install the extended preset and decline what they do not want, item by item, on a
screen built for it (§7.2.1). Uploading a subset would add a file format to reach a
place two clicks already reach.

§6.1 already names the thing that was actually wanted, and has since this brief was
written: a **template** — *install these modules with these presets, then add these
fields*. "Dentist practice" is a template, not a preset. It is data, it lives in the
control plane, and the reason given there is exactly the reason XIV-141 was raised:
**needing a deploy to onboard a vertical is v1's compiled-in module list wearing a
different hat.** The middle way is not a new idea to be evaluated; it is an idea
this brief has been carrying, unbuilt, for a while. What XIV-141 adds is the part
§6.1 never spelled out — what "then add these fields" may actually contain, and
whether the file holding it may arrive from outside.

To keep the two apart, the second half of a template has a name here: a **shape
pack** is the list of edits applied to an installed module's definitions after the
modules are in.

#### The boundary, and it is the right one

> **A pack may do nothing a customer could not do by hand in the metadata editor.**

That is what makes a pack *data* rather than code, and every property worth having
falls out of it rather than being added on top. There is nothing to execute, so
there is no sandbox to get right. It grants no privilege — whoever applies it is
already an administrator who could sit down and make the same twenty edits one at a
time, which is §5.4's authority unchanged. Every outcome is reachable through a UI
somebody is allowed to use, so "what could a malicious pack do" has the same answer
as "what could a malicious administrator do", which is a question this system
already answers and does not have to answer twice. And it is reviewable by
*reading* it, which is the property a module never had.

It also gives an implementation with no new engine in it: a pack is a sequence of
`MetadataEditor::addField()`, `updateField()` and `renameShape()` calls, and it
inherits every refusal those already make — a bad key, a taken key, an unknown
type, a rule the existing records could not keep (§5.4). A pack cannot talk the
editor into anything the editor would refuse a person, because it *is* a person's
edits with the typing removed.

#### And today that boundary encloses almost nothing

This is the finding. **The metadata editor cannot configure a choice field's
choices, and it cannot tell a reference field which module it points at.**
`FieldController::optionsFrom()` draws exactly `max_length`, `min` and `max`, plus
the two per-type settings [XIV-36] and [XIV-27] introduced — `autocomplete` and
`sequence`. `choices` and `module` are on §5.4's own list of settings *the form
must not touch*, and they are on it because the form has no control for them and
saving the whole options array used to wipe them.

Meanwhile the add-field form's type select is `$this->fieldTypes->all()`. So a
customer can add a `choice` field today and get a select with nothing in it, or a
`reference` field with no target that renders every value as `#id`. Both types
degrade politely — `ChoiceFieldType::constraints()` skips its `Assert\Choice` when
the list is empty, which its own comment calls "a confusing way to say
misconfigured" — and neither is usable. That is a live gap in the editor,
independent of packs, and it is the reason this section ends where it does.

What survives the boundary today is: add text, textarea, integer, decimal, date,
email and currency fields, with labels, `required`, `unique`, `filterable`,
`listed`, `title`, a position, a width and a length or range; relabel and reorder
the module's own fields; rename the module. What does not survive is **a choice
field with choices** and **a link to another module** — which are the two things a
vertical is mostly made of. A law firm needs a matter type; a care home needs a
care level; both need to point at something.

So the boundary is right and the editor is too small. The prerequisite is not an
upload mechanism, it is the sentence §5.4 has been half-writing since [XIV-36]: **a
type says which of its options are the customer's to set.** `choices` wants a
capability interface and a control the same way `sequence` got one; a reference's
`module` and `variant` want the same, with the added question of what happens to
stored ids if a target is changed after records exist. That is a ticket of its own,
it is worth doing whether or not a pack ever ships, and until it lands a pack is a
file that can rename things.

#### The file, and what it cannot say

A law firm flavour of the contact module, written out in full so the trade-offs are
visible rather than described:

```yaml
# law-firm.pack.yaml
pack: law-firm
format: 1
label:  { en: Law firm,   de: Anwaltskanzlei }
description:
  en: Contacts as a practice keeps them — clients, opposing parties, courts.
  de: Kontakte, wie eine Kanzlei sie führt — Mandanten, Gegenparteien, Gerichte.

# What has to be installed already. A pack installs nothing itself: §6.3 refuses
# to chain-install for a reason that applies here word for word, since each
# module carries its own preset choice.
requires: [contact]

shapes:
  contact:
    rename: { en: Client, de: Mandant }          # renameShape()
    fields:
      # Existing fields, adjusted. The key must already be there; `type` and
      # `key` are not sayable, here or anywhere, because §5.4 has no answer for
      # either (§7.2).
      - key: company_name
        label:    { en: Firm or authority, de: Firma oder Behörde }
        position: 10
      - key: birthday
        listed: false

      # New fields. The key must NOT already be there — a key the shape has is
      # skipped and reported, which is §7.2.1's rule arrived at from this side.
      - key: matter_number
        add:      text
        label:    { en: Matter number, de: Aktenzeichen }
        unique:   true
        listed:   true
        position: 20
        options:  { max_length: 32 }

      - key: matter_type
        add:      choice
        label:    { en: Matter type, de: Rechtsgebiet }
        filterable: true
        position: 30
        options:
          choices:                                 # ← nothing can apply this today
            litigation: { en: Litigation, de: Prozess }
            advisory:   { en: Advisory,   de: Beratung }
            notarial:   { en: Notarial,   de: Notariat }

      - key: responsible_partner
        add:      reference
        label:    { en: Responsible partner, de: Verantwortlicher Partner }
        position: 40
        options:
          module:  contact                         # ← nor this
          variant: person
```

Two lines in that file — the two that carry the vertical — are instructions the
system has no way to perform. That is the section above, made concrete.

And here is what the format has nowhere to put, which matters more than what it
has:

- **A collection.** *Matters*, as rows belonging to a client, is the shape a law
  firm actually wants and is the first thing anybody would try to write here.
- **A variant.** "Client" and "opposing party" as *kinds* of contact is
  `ModuleBlueprint::$variantField` plus a field's `variants` list, and neither is
  editable by anybody — `updateField()` does not take `variants` at all.
- **A lifecycle** (§5.8), a **deriver** (§5.9), a **document template** (§5.7), a
  **number series** beyond what the editor's own numbering page does (§5.10), or
  any validation rule other than `required` and `unique`.
- **Translations.** A module ships a catalogue and the installer seeds labels
  through it (§6.1); a pack has no catalogue, so its labels are literals and it has
  to carry a language map or install in one language only. The map above is the
  honest version and it is also the version that goes stale silently.

Which leads to the naming, and it is a decision rather than pedantry: this can be
called a **field pack** and must not be called a *Law Firm*. Something named after
a trade promises the trade; a file that supplies four field names and a rename
would be sold as the first and delivered as the second, and the customer finds out
after they have configured around it. The apply screen shows the literal list of
changes, the way §7.2.1's confirmation does, and the marketing word stays with
[XIV-139]'s packages, where there is a module behind it.

#### Collections are out, and the reason is a table name

The hardest edge, and it does not fall the way the boundary rule alone suggests.
"Only the installer makes a table" is no longer the whole story: since [XIV-70],
`ModuleInstaller::adoptCollection()` creates one on an administrator's click
(§7.2.1). So "DDL on an admin's click" is already sanctioned, and refusing a pack
on that ground alone would be quoting a rule that has moved.

The real objection is narrower and worse. **A table name in an uploaded file is a
claim on an identifier in the customer's database, and it is permanent.**
`createRecordTable()` refuses to adopt a table it did not create — deliberately —
so a pack that creates `contact_matter` in a tenant has made it impossible for any
future *module* of ours declaring that table to ever install there. Not difficult:
impossible, for that customer, for good, with the failure arriving years later as
"this one tenant cannot install Matters" and no way back that does not involve
dropping a table with their data in it. Prefixing uploaded tables would contain it
and is exactly the kind of decision that cannot be un-made afterwards.

So: **fields only, never collections** — which is word for word the rule §6.1
already gives presets, arrived at independently and for an unrelated reason. There
it is because a collection cannot be added back; here it is because a collection
cannot be taken back. Two different arguments landing on the same line is the
strongest evidence available that the line is in the right place.

#### Applied once, and never a standing authority

A pack is a **seed**, on §6.1's terms and for §6.1's reason. It is applied, an
audit line records that it was, and from that instant the customer's definitions
are the truth and the file has no further say. Nothing stores it as the shape of
that tenant, nothing re-applies it, and there is no "update the pack" operation.

The alternative is the thing to name so it stays rejected: a stored, re-appliable
pack is a **second schema authority** sitting over a customer's definitions, and it
reopens every guarantee §6.1 makes — it would want to correct a label somebody
changed, restore a field somebody removed, and reconcile itself on a schedule. That
is not a bigger version of this feature. It is the retro-fit the whole brief
refuses, arriving as a file instead of as a deploy.

Applying once is also what disposes of the third-party-breakage problem [XIV-141]
worried about arriving through the back door. A pack names field keys and a
shipped module's blueprint moves; if the pack is applied once, at a moment somebody
is watching, then drift is a **report** — *this pack expected `company_name` and
this module has no such field; three of its eleven changes were skipped* — rather
than a break. Nothing is left holding a stale reference, because nothing is left
holding anything. A pack we ship in a package ([XIV-139]) is ours to keep in step;
a pack somebody else wrote goes stale, says so on the screen when it does, and
costs the customer a message rather than an outage.

#### Who may apply one, and into what

Two questions that look like one, and the answers are opposite.

**A tenant administrator, into their own installation: yes.** It is the same
authority the metadata editor already grants (§5.4, admin-only), doing the same
edits faster, previewed in full before anything is written. Whoever wrote the file
— a consultant, a partner, the customer's own IT — is irrelevant to the risk,
because the boundary makes the file's *origin* not matter. That is the whole point
of the boundary.

**An operator, into the store, listed for everyone: no.** That is not a bigger
version of the same thing; it re-opens the two questions §3.2 just closed. Whoever
lists it is vouching for it, and a pack that is wrong for a law firm is wrong for
every law firm that installs it — the fact that it cannot execute code limits the
*security* exposure and does nothing about the liability. So the rule is:
**anybody may author a pack; nobody but us may publish one.** Ours travel inside
[XIV-139]'s packages, where they are curated exactly as the module list is;
everybody else's travel as a file the customer chooses to apply, at their own risk,
with a preview.

That also settles the transport question honestly, and it is the least intuitive
conclusion here. §6.1 already puts templates in the control plane as data, which
already means **our** verticals need no deploy. Uploading buys exactly one thing on
top of that: a file that reaches a customer without an operator touching anything.
That is a real benefit and it is a narrow one, and it is not worth designing the
format around — so the format is designed for the control plane first, and uploading
is a second front door onto the same parser whenever somebody wants it.

#### The decision

**Not built, and not refused.** The order is:

1. **The editor learns `choices`, and a reference's `module` and `variant`** —
   §5.4's own unfinished sentence, worth doing on its own merits because the add
   form currently offers two types it cannot configure. Until this exists a pack
   cannot express a vertical, and a pack that cannot express a vertical is a
   feature with no reason to exist.
2. **Templates land** ([XIV-139] and §9.3), with the shape pack as their second
   half, in the control plane, ours. This is where the format is written and where
   it earns its first real use.
3. **Uploading is a separate, later decision**, onto the same format, and nothing
   in steps 1 and 2 forecloses it.

**Checked against [XIV-139] and [XIV-140]**, as XIV-141 required. Neither changes.
[XIV-139]'s "presets travel with it" is the acceptance criterion this section
answers: a vertical that is "Contact with different fields" is expressible as a
*shape pack* carried by the package, and it is **not** expressible as a preset, so
that criterion needs the pack rather than §6.1's presets and should say so.
[XIV-140] is unaffected and its lean is confirmed — packages are the grouping, the
catalogue stays curated (§3.2), and a store designed for a curated set is a store
that never has to answer "who else may put something here".

---


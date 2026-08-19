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
4. Tag the merge commit `v<version>` and push the tag. That is what publishes:
   `.github/workflows/release.yml` posts the file from step 1 as the GitHub
   release, and fails if the file is missing or the tag disagrees with step 3.

There used to be a fifth step — updating a hand-written version line near the top
of `README.md` — and a gate in the release workflow to catch the times it was
forgotten, because 17.0.3 shipped saying 17.0.2. **The line is gone instead**
(XIV-112). `Version::CURRENT` was always the number the application actually
serves; a second copy of it in prose could only ever agree or be wrong, and the
cheapest way to stop it being wrong was to stop writing it twice. The README
links here rather than restating anything.

`bin/ci` gates on this file having changed, which keeps working: new work always
lands in `Unreleased` here.

## [Unreleased]

### Added

- **Sections, so a form of twenty-five fields is readable** ([XIV-119]) — group
  fields under headings of your own: *Contact details*, *Billing*, *Notes*. Make
  and order them on *Sections* beside the field editor, then pick one per field
  in the *Section* column. The record page groups the same way, so the form and
  the page agree. Nothing about a record changes — a section is a heading and
  nothing else. §5.4 has the reasoning, including why it is not a collection.
- **A field in no section works exactly as before** ([XIV-119]) — which is every
  field in every installation until you make one. Nothing moves, nothing is
  rewritten, and there is no upgrade step: fields with no section are drawn first,
  in their own order, above whatever you group afterwards.
- **Deleting a section deletes no fields** ([XIV-119]) — it takes the heading and
  leaves everything under it, which the confirmation says before it happens,
  together with how many fields come back to the top of the form. Collapsing a
  section is deliberately not in this.
- **A phone field, so one number is one value** ([XIV-114]) — `+41 79 123 45 67`,
  `0791234567` and `079 123 45 67` are one number, and until now they were three
  values in a text box: the filter found one of them, a duplicate check found
  none of them, and an export was whatever each person had typed. A `phone` field
  stores whatever is typed as `+41791234567`, shows it back as `079 123 45 67` to
  a Swiss reader and `+41 79 123 45 67` to everybody else, and refuses what it
  cannot read. §5.23 has the reasoning.
- **The country comes from your company profile, not a new setting** ([XIV-114])
  — `079 123 45 67` is only a number if you know where it was dialled, and the
  *Country* on *Company profile* is where that comes from. Nothing new to fill
  in. A single field can be told to assume a different one — a *Country* column
  in the field editor, for the supplier list whose numbers are all German — and
  a field that says nothing follows the company.
- **Contact's Phone field is a phone number now, and it is filterable**
  ([XIV-114]) — on **new** installations. An installation that already has
  Contact keeps the text field it has: changing a field's type would put stored
  values at risk, so nothing reaches into your database. Your existing Phone
  field goes on working exactly as before.
- **"Marked as unique" now catches the same number typed two ways** ([XIV-114])
  — a consequence worth knowing before you tick the box on a phone field: two
  records that used to look different because one had spaces in it are now the
  duplicate they always were, and the second one is refused.
- **Action: an import can now refuse rows it used to accept** ([XIV-114]) — a
  spreadsheet column of hand-typed numbers going into a `phone` field is checked,
  and a value that is not a diallable number stops the import with the value and
  the country named: *"079 123 45" is not a phone number that can be dialled in
  Switzerland*. This is only about fields of the new type — a text field imports
  exactly as it did.
- **A number with an extension is refused, on purpose** ([XIV-114]) —
  `+41 44 668 18 00 ext. 12` is not stored, because the storage format has no room
  for an extension and would drop it silently, filing a switchboard and everybody
  behind it under one number. The message says to put the extension in a field of
  its own, which the field editor can add without a deploy.
- **A voucher can be used on an order** ([XIV-104]) — pick one on the order and
  what the customer owes changes: money off, a percentage off, or a free article.
  The discount is **its own line**, so the lines you quoted still say what you
  quoted and the document shows the discount separately, named after the voucher's
  own code. It comes off **before VAT**, so the tax is worked out on what is
  actually being charged. On an order carrying more than one VAT rate the discount
  becomes one line per rate, each with its share, and the rappen that will not
  divide goes on the last of them — so the VAT breakdown still adds up to the
  total. §5.24 has the whole argument.
- **The discount line is the engine's, not yours** ([XIV-104]) — it is written on
  every save from the voucher the order names, so it cannot be typed over or
  deleted by hand and there is no button offering to add one. A *subtotal* line
  stays exactly as it was: you add, move and delete those, and only the figure in
  them is computed.
- **A use is counted when the order is saved** ([XIV-104]) — not when the code is
  typed, so a code entered and then abandoned costs nothing, and an order that
  fails to save consumes nothing. **Taking the voucher off a draft gives the use
  back**, and so does deleting the order: the count says how many documents carry
  the voucher. A cancelled order still carries it, so it still counts.
- **A voucher that cannot be used says which way** ([XIV-104]) — expired, not yet
  valid, or already used as many times as it allows: the sentence names which, on
  the field, with everything you typed still in the form. The limit is checked at
  the moment of saving, so two people checking out at once cannot both take the
  last use.
- **An invoice made from a discounted order is discounted too** ([XIV-104]) — the
  discount comes across as a line like every other. On the invoice it is an
  ordinary line you can edit or remove, because what to bill is decided there.
- **The Voucher field appears only if you have both modules** ([XIV-104]) — an
  installation with orders and no vouchers gets no voucher field, no discount
  line kind and no trace of any of this. **If you buy vouchers later**, the field
  is offered on the order module's *upgrade* screen rather than appearing on its
  own — take it there when you want it. Installing several modules at once now
  orders them so that an optional one lands before the module that uses it.
- **A voucher can be applied to one line instead of the whole order** ([XIV-122])
  — a voucher now says which of the two it is, and the two do different things.
  *Amount off the order* and *Percentage off the order* add their own line, exactly
  as before. *Amount off one line* and *Percentage off one line* are put on the
  line you want them on, in a new **Voucher** column on the order's lines, and
  reduce that line. Both come off before VAT. §5.25 has the whole argument.
- **The reduced line shows what came off it** ([XIV-122]) — a new **Discount**
  column beside the line total, so the line reads `199.95 − 29.99 = 169.96` and
  whoever receives the document can check it rather than being asked to trust a
  total. The column is worked out from the voucher on every save; it cannot be
  typed over.
- **A line voucher reaches a custom line** ([XIV-122]) — which is the point of
  choosing the line rather than having the voucher find one. A hand-typed line has
  no catalogue article on it, and that is exactly where a negotiated discount
  lands.
- **A voucher on three lines of one order is one use** ([XIV-122]) — the count has
  always said how many *documents* carry the voucher, and that has not changed: a
  single-use voucher can cover every line of one order, and it is the next order
  that is refused. Moving a voucher from one line to another costs nothing.
- **Action: the voucher kinds have changed, and existing vouchers need re-making**
  ([XIV-122]) — the three kinds *Amount off*, *Percentage off* and *Free article*
  are replaced by the four above. Vouchers are still a **development** module and
  are not in the store, so this affects nobody who has bought one — but a
  development installation with vouchers in it will find them showing no kind, and
  the fix is to create them again. This was done now precisely so that it could be
  a change to a declaration rather than a migration later.
- **"Free article" is gone, and is now a line voucher at 100%** ([XIV-122]) — put
  the article on the order the way you put on any other, and a voucher restricted
  to that article at *Percentage off one line: 100* makes it free. One more step
  when typing the order, and in exchange the free item is a line you chose, at a
  quantity you chose, priced from your catalogue.
- **A voucher can be limited to lines for one article** ([XIV-122]) — the article
  link is now an optional **restriction** rather than the thing being given away.
  Name one and the voucher only goes on lines for it; leave it empty and it goes
  on any line at all.
- **Consequence: every voucher kind is now offered even without the Articles
  module** ([XIV-122]) — previously the free-article kind was hidden from an
  installation with no catalogue. It is not hidden any more, and that is
  deliberate: *Amount off one line* is a perfectly good voucher for somebody who
  keeps no catalogue. What such an installation does not get is the *Only on lines
  for* field, which is simply not installed — so there is still no picker with
  nothing behind it.
- **Putting a voucher in the wrong place says so** ([XIV-122]) — a line voucher
  put on the order, or an order voucher put on a line, is refused when you save
  with a sentence naming which way round it goes. So is a restricted voucher on a
  line for something else, and that one names the article.
- **A fixed amount larger than the line takes the whole line and stops**
  ([XIV-122]) — twenty francs off a fifteen-franc line is fifteen francs off, not
  a line worth minus five. The same rule the whole-order discount has had.
- **An invoice made from an order with a reduced line is reduced too** ([XIV-122])
  — the discount column comes across with the price and the rate, so the bill
  charges what was agreed. As with the discount *line*, it is an ordinary editable
  figure once it is on the invoice.
- **The Voucher field on a line follows the same rule as the one on the order**
  ([XIV-122]) — no vouchers module, no column; buy vouchers later and the column
  is offered on the order module's *upgrade* screen rather than appearing on its
  own.
- **A Knowledge module, for what your experienced people know** ([XIV-132]) — a
  very simple place to write down the answers that currently live in one person's
  head: how a refund past thirty days is handled, which supplier to call when the
  usual one is out, what was agreed with a customer in 2023. An entry has a
  title, a topic and a formatted body ([XIV-131]); the list has the topic and the
  date it last changed; the filter bar finds an entry by any word in it. Install
  it from the store like any other module — it needs no other module first, so a
  brand-new installation can have this and nothing else. §5.22 has the whole
  argument.
- **Knowledge entries are internal, and stay internal** ([XIV-132]) — nothing
  here is published, shared with a contact or sent by email, and the module is
  built so it cannot be: it names no contact and declares no recipient, so the
  *send this record* button is not on the page at all.
- **Writing is a separate grant from reading** ([XIV-132]) — no new permission
  concept was added, and none was needed: *View* and *List* on Knowledge make
  somebody a reader, *Add* and *Edit* make them a writer. The recommended default
  is that most people read and a few write, and since nothing is granted until
  you grant it, that is what you get by doing nothing.
- **What the search does and does not do** ([XIV-132]) — *contains* matches text,
  ignoring capitals. It is substring matching, not full-text search: there is no
  stemming, so the plural does not find the singular, and no ranking, so results
  come back in whatever order the list is sorted by rather than best first. At a
  few dozen entries this is indistinguishable from search; at a few thousand it
  will not be, and that is a separate ticket rather than a promise this makes.
- **Every module's list now shows when a record last changed** ([XIV-132]) — a
  *Changed* column beside *Owner*, filled from the same history that has always
  been there. It exists because a knowledge entry that is quietly three years out
  of date is worse than one that is missing, and it is useful on every other list
  for the same reason.
- **A price can already include VAT** ([XIV-116]) — an order and an invoice now
  say how to read their own prices: *Prices exclude VAT*, which is what every
  document has always been, or *Prices include VAT*, which is how a shop quotes
  a lamp at 19.95 on the shelf. Type 19.95, and 19.95 is the total that prints —
  the net and the VAT are worked out backwards out of it and the VAT takes the
  remainder, so the figure the customer checks against your price list can never
  come out a rappen off. Dividing by 1.081 yourself and typing 18.46 gave 19.96.
  The VAT breakdown reads the same in both modes and still adds up to the totals,
  including on a document carrying 8.1% and 2.6% at once. §5.9 has the argument
  and the exact arithmetic.
- **Set what your prices mean once** ([XIV-116]) — *Company profile* has a new
  *Prices* setting beside the currency, and every new order and invoice starts on
  it. It is copied onto the document rather than consulted afterwards, so a
  business doing both changes it on the one document that differs, and an invoice
  is priced like the order it was made from even if you switch in between.
- **Nothing already stored changes, and no document you have sent reads
  differently** ([XIV-116]) — every existing order and invoice keeps its stored
  totals to the rappen, and a blank setting means exactly what it always meant:
  prices exclude VAT. Changing the setting later never restates a document,
  including a draft you go back and edit.
- **On upgrade:** an existing installation does not gain the field until somebody
  takes it. *Price basis* appears in *what your module has grown* on Orders and
  on Invoices (§7.2.1) — two separate offers, because they are two modules — and
  until it is taken those modules price exactly as they do today. The *Prices*
  setting on the profile is there either way and has no effect on a module that
  has not taken the field.
- **Action if you switch a document to inclusive prices and print it:** a Word
  template written before this ticket says nothing about which mode a document is
  in, while its unit-price and line-total columns now show gross figures. Add the
  new `[vat_mode]` marker to the template — it is in the reference list beside
  `[gross_total]` — so the recipient reads *Prices include VAT* on the page. A
  document left on *Prices exclude VAT* needs no change.
- **A field can hold formatted text** ([XIV-131]) — a new field type, *Formatted
  text*, written as Markdown: headings, bold, italic, lists, links and tables. Add
  one in the field editor like any other. The form is a plain textarea with a
  live preview under it — no toolbar, no editor to download, and nothing fetched
  from anywhere. The record page draws the formatting; a list column, a
  spreadsheet export cell and a generated Word document get the words with the
  marks taken off, so `**Warning:**` prints as `Warning:` rather than as
  punctuation you never meant to send. Filters and search match what you typed,
  marks included. §5.21 has the argument for each of those, including why this is
  a new type rather than a checkbox on *Long text*.
- **Nothing changes for a *Long text* field** ([XIV-131]) — existing textarea
  fields keep their type, their widget and their behaviour, in every tenant.
  There is deliberately **no way to convert one into a formatted field**: it would
  silently reinterpret every value already stored — a parts list typed with `*`
  bullets would change meaning in every record with nothing to see. Add a
  formatted field and move the text across if you want one.
- **An article is sold in a unit, and a line says so** ([XIV-118]) — hours, days,
  pieces, kg, m, m² or litres, shipped as a starting set and seeded into your own
  definitions at install like every other label (§6.1). An order line and an
  invoice line show it beside the quantity, on the record page and in a generated
  document, so `2.5` reads `2.5 hours`. The unit is the *article's*: an order line
  takes a copy of it the way it already takes the title and the price ([XIV-18]),
  which means an order placed in hours goes on saying hours after the catalogue
  changes, and the page marks the line as drifted when the two disagree. A custom
  line has no article and gets the same list to pick from by hand; a comment or
  subtotal line has no quantity and is not offered one. **Nothing changes for an
  article that has no unit** — its lines read exactly as they did — and the field
  is optional for that reason. §5.20 has the whole argument, including why units
  are not pluralised and what still has to happen before you can add "pallet".
- **On upgrade:** an existing installation does not gain the field until somebody
  takes it. It appears in *what your module has grown* on each of Articles, Orders
  and Invoices (§7.2.1) and is added by choosing it there — three separate offers,
  because they are three modules. Taking it on Articles alone gets you a unit
  nothing prints. Nothing already stored changes, and no document you have sent
  reads differently.
- **Action after taking it on Orders or Invoices:** the line's form row is a
  twelfths grid and the blueprint made room by narrowing the description, which an
  upgrade deliberately does not do to a field you already have (§7.2.1 only ever
  adds). So the row adds up to thirteen and the unit wraps to a line of its own
  below the number. Narrow *Description* by one in the field editor — 4 to 3 on an
  order line, 3 to 2 on an invoice line — and it sits beside the quantity. A new
  installation is already laid out that way.
- **A record's numbers, as a line** ([XIV-121]) — an article's page now carries a
  small chart of what its price has been over time, and a sentence saying how
  many times it has changed and between which two figures. It is **not a chart of
  `price`**: any numeric field is offered — a VAT rate, a quantity, a stock level
  you added yourself — and a picker on the card chooses which one is drawn, so a
  field you invented gets a trend with no new version of Xivi. A reference is
  never offered, because plotting one would be plotting record ids.
- **Nothing new is stored, and nothing has to be switched on.** The chart is read
  out of the record history that has been kept since the beginning: it records
  the values, not just the fact that something changed, so every price your
  catalogue has ever had is already there and every article you have — including
  the ones created years ago — has a chart today. An article nobody has ever
  edited draws a flat line and says "unchanged since" the day it was made, which
  is a real answer rather than an empty box.
- **A reader sees nothing about a record they may not open**, chart included; the
  card is checked against the record itself and not merely the module, so
  somebody restricted to their own records cannot read a colleague's prices off
  an axis.
- **The first chart in Xivi, and the only one for now** ([XIV-121]) — charting
  was deliberately left out in [XIV-66] on the argument that a chart earns its
  place where a *trend* is what is being read and nowhere else. That argument is
  unchanged; a price over time is the case it admits. Dashboards of charts and
  anything totalling across records are still not built. The library is Chart.js,
  MIT, served from your own installation like every other asset — **no request
  leaves a customer's browser to a third party**, which is unchanged and remains
  the promise. It costs about 590 KB inside the customer-facing image, roughly a
  sixth of one percent of it. Reasoning in `docs/architecture.md` §8.3.1, and why
  history could answer this without a new table in §5.2.
- **An operator can say something to customers, and it appears where they work**
  ([XIV-120]) — a new *Notices* screen in the control plane writes an
  announcement addressed to every customer or to named ones, and it turns up as a
  card on their own dashboard: a maintenance window, a release note, a trial
  running out. Each notice carries a date and the name of whoever wrote it, and
  says per notice whether everybody in an installation sees it or only its
  administrators. A reader can dismiss one for themselves, which does not hide it
  from their colleagues, and an operator can withdraw one, which takes it off
  every dashboard at once. §8.16 has the argument, including why this needed no
  new privilege where [XIV-102] needed a whole collector.
- **On upgrade: re-run `bin/console deploy:registry-grants`** ([XIV-120]). The
  registry gained two tables (`notice`, `notice_recipient`), and a
  customer-facing instance whose database role has not been granted `SELECT` on
  them will fail to draw the dashboard — loudly, on the first request after the
  deploy, for everybody. The command prints today's statements, which is why it
  prints rather than maintains a script. Nothing else about the grant changes and
  **no new write privilege is needed anywhere**.

- **Xivi has documentation, and it is published**
  ([XIV-112](https://xivi.youtrack.cloud/issue/XIV-112)) —
  <https://praesidiarius.github.io/plc-xivi-docs/>. Installing Xivi, running an
  installation, and what a record, a module and a tenant actually are, for the two
  people who meet Xivi from outside the code: whoever deploys it and whoever uses
  it. Source in [plc-xivi-docs](https://github.com/Praesidiarius/plc-xivi-docs),
  built on every push and gated on having no broken links.
- **The control plane can be restricted to a list of addresses**
  ([XIV-124]) — `CONTROL_PLANE_ALLOWED_IPS` takes addresses and CIDR ranges, IPv4
  and IPv6, and a request to the control-plane host from anywhere else is refused
  with an empty 403 before anything else looks at it. **Empty is the default and
  means no restriction**, so an installation that sets nothing is unchanged, and
  customers are never affected either way. It is a fourth layer in front of the
  sign-in, the operator-only provider and `ROLE_OPERATOR` rather than a
  replacement for any of them — worth having because the control-plane *hostname*
  has never been a boundary (§4.3). The address is resolved through
  `TRUSTED_PROXIES`, never from a raw header, so **set `TRUSTED_PROXIES` too if
  there is a load balancer in front** or every request will look like it came
  from the balancer. Reasoning in `docs/architecture.md` §8.9.
- **`deploy:check-control-plane` reports what that list admits**
  ([XIV-124]) — run it **before** you depend on the variable. It names what is
  admitted, refuses to be quiet about an entry that is not an address, answers
  `--address=198.51.100.7` directly, and offers the address your SSH session came
  from. Exit 3 if the list is unusable or would refuse an address you asked
  about. **Getting this wrong locks the operator out with no customer-facing
  symptom**, which is the whole reason the command exists.
- **Scheduled jobs can tell an external monitor that they ran** ([XIV-126]) —
  optional, off by default, and an installation that sets nothing behaves exactly
  as before. Set `XIVI_MONITOR_PINGS` to `command=<ping url>` pairs and each
  watched command pings `<url>/start` when it begins and `<url>/<exit code>` when
  it ends, so the *service* raises the alarm when a job stops running. The ping
  carries the fact and the exit code and nothing else — no tenant, no customer,
  no counts, no version. Healthchecks is the recommendation because you can
  self-host it (BSD-3-Clause); Better Stack's heartbeat URLs work unchanged. The
  reasoning, the comparison of four services, and why an in-house checker is
  rejected for good are `docs/architecture.md` §4.5.
- **`bin/console deploy:crontab`** ([XIV-126]) — prints the cron entries this
  build needs, with what goes stale without each one and whether anything is
  watching it. Output is a crontab, comments and all, so it can be redirected
  rather than retyped. **Action:** run it on your installation. It exits 3 if
  some jobs are watched and others are not, which is the state that looks like
  being covered and is not.
- **`tenant:purchase:collect` was missing from every list of cron entries**
  ([XIV-126]) — it shipped with [XIV-102] and reached no documentation page and
  no crontab, so a customer's request to buy a module never reached the operator
  screen on an installation that followed the instructions. **Action:** if you
  set your crontab up before today, add it.
- **A customer can now reach whoever runs their installation** ([XIV-123]) — a
  *Support* page under your own name in the top bar, where anybody signed in can
  describe a problem and read the answer. It is the return path for [XIV-120]'s
  notices: those are the operator talking to you, this is you talking back. §8.17
  has the argument.
- **What a ticket carries, and what happens to it** ([XIV-123]) — a subject, a
  description, who asked and when, and a state the operator moves: *waiting for
  an answer*, *being looked at*, *closed*. The operator's reply appears on the
  same page. Everybody in your company sees the company's tickets, so a colleague
  finds the answer instead of asking again. No priorities, no categories and no
  attachments — this is a way to reach a person, not a helpdesk product.
- **A ticket takes a few minutes to arrive, and the page says so** ([XIV-123]) —
  the question is written into your own installation's database and collected
  from there, so it reads *Not received yet* until a collection has run. The
  answer goes the other way with no wait at all: an operator's reply is on your
  screen the moment they write it. **Action for operators:** add
  `tenant:support:collect` to your crontab — `bin/console deploy:crontab` prints
  it, every five minutes, and nothing reaches the support screen without it.
- **Action: run `bin/console deploy:registry-grants` again** ([XIV-123]) — this
  release adds a registry table, `support_request`, and a customer-facing
  instance's database role has to be granted `SELECT` on it. An installation that
  skips this gets a support page that fails outright for every customer. The
  command prints the SQL; a database administrator runs it.
- **An operator answers every customer from one screen** ([XIV-123]) — *Support*
  in the control plane lists every company's tickets, oldest waiting first, with
  the time of the last collection on every row so an empty queue can be told from
  a collector nobody scheduled. Who typed the question stays in the customer's
  own database and never reaches this screen, exactly as with a purchase request:
  an operator answers the company, on the screen the company reads.
- **The FAQ half of the old Support module is deliberately not here**
  ([XIV-123]) — an FAQ is documentation, and it belongs on the
  [documentation site](https://praesidiarius.github.io/plc-xivi-docs/), where it
  is written once and can be read by somebody who has not signed in.
- **A deploy now finds out when the registry grants were never re-run**
  ([XIV-143]) — the two bullets above and below asking you to run
  `deploy:registry-grants` again ([XIV-120], [XIV-123]) were the only thing
  standing between a forgotten `GRANT` and a dashboard that fails for every user
  of every tenant. `bin/console deploy:check-grants` asks PostgreSQL what the
  customer-facing role may actually do and names every table where that differs
  from what this release needs — in both directions, so a role that has been given
  `INSERT` on a registry table is reported too, and so is one that is quietly a
  superuser. `bin/deploy` runs it on every release, right after the control-plane
  migration and before any container is replaced, and a difference stops the
  deploy. It **tells you what to run and repairs nothing**: the command that
  changes privileges is still one a database administrator runs. §4.4 has the
  argument.
- **Action, if you run the two-image deployment: set `XIVI_PUBLIC_ROLE`**
  ([XIV-143]) — name the database role your customer-facing instance connects as,
  and the check above happens on every deploy. Left empty, which is the default
  and is right for an installation served entirely by the internal image, nothing
  is checked and nothing changes. No password is needed: the check reads the
  catalogue rather than connecting as the role.

### Fixed

- **A choice field you add now has choices, and a reference has something to
  point at** ([XIV-144]) — the field editor offered both types and drew no
  control for either, so adding one gave you a dropdown with nothing in it, or a
  link that showed `#41` instead of a name. Nothing said so: an empty choice list
  meant the field accepted *anything*. Adding a **Choice** field now asks for its
  options, one per line, and adding a **Reference** asks which module it points
  at — and neither can be created without an answer. §5.4 has the reasoning.
- **Your own options, on a module's own fields** ([XIV-144]) — the wholesaler
  who sells by the pallet can add it to Article's *Unit*, and the workshop can
  add a topic to Knowledge. Options can be added and renamed on any choice field;
  **renaming one changes what the page says and moves no record**, because what
  is stored is a short code derived from the first label you give and it never
  changes again.
- **Action: an option your records hold cannot be removed** ([XIV-144]) — it
  would leave those records holding a value that is no longer on the list, and
  they could not be saved again until somebody fixed them. The refusal names the
  option and how many records hold it, and the options page shows that count
  beside each one before you try. Options on a module's *own* fields cannot be
  removed at all — an order's states are what its lifecycle moves records
  between.
- **Action: a reference cannot be repointed once records point through it**
  ([XIV-144]) — an id only means something in the module it came from, so moving
  the target would leave every stored link naming the wrong record, silently.
  Empty the field first. A field that came with a module cannot be repointed at
  all.
- **`importmap.php` is a generated file again**
  ([XIV-111](https://xivi.youtrack.cloud/issue/XIV-111)). It carried a comment
  explaining why only one of Tom Select's four stylesheets is pulled in — real
  reasoning, in a file Symfony Flex regenerates from a template whenever a
  package is added. It was dropped twice in two days and caught both times only
  because somebody read the diff. The reasoning moved to `docs/architecture.md`
  §5.4, and a test now holds the fact, so a recipe that adds the other three
  fails `bin/ci` instead of shipping three stylesheets served to nobody.
- **A deprovisioning test no longer fails about one run in ten** ([XIV-142]) —
  `pg_terminate_backend` sends SIGTERM and returns as soon as the signal is away,
  so the session it ended is still in `pg_stat_activity` for a millisecond or
  three afterwards; the test asked in the very next statement and, under eight
  parallel workers, sometimes got its answer before the backend had gone. It now
  polls to the same five-second deadline `DROP DATABASE … WITH (FORCE)` keeps,
  and still demands that the session really went. **Nothing in Xivi changed**:
  the drop waits for those backends itself, which the brief now records (§4.1).
- **The landing page's live name check is tested in a real browser** ([XIV-105])
  — the script that fills in your address as you type your company name was
  covered by nothing, exactly like the dashboard buttons that shipped inert under
  [XIV-84]. Two tests now type into the form and require the answer to arrive, and
  both go red when the wiring is broken. **Nothing in Xivi changed**: the signup
  routes are still bound to `SIGNUP_HOST` and still `https`-only, and the test
  asserts that as it runs. What moved is the test harness — a compose alias and a
  router script for the suite's own web server — and the suite's `SIGNUP_HOST` is
  now `signup.e2e`, since Chromium cannot be pointed at a `*.localhost` name.
  Reasoning, and what is still not covered, in `docs/architecture.md` §8.13.

### Changed

- **Markdown is rendered in one place now** ([XIV-131]) — the converter, the
  raw-HTML escaping and the sanitizer policy moved out of the email renderer into
  a single core service that email and formatted fields share. **No behaviour
  changed for email**; what changed is that what an email is allowed to contain
  and what a record page is allowed to contain can no longer drift apart. If you
  have overridden the `email` HTML-sanitizer policy in your own configuration, it
  is now called `markdown`.
- **`README.md` is 84 lines instead of 962**
  ([XIV-112](https://xivi.youtrack.cloud/issue/XIV-112)) — what Xivi is, a
  Quickstart that fits on a screen, and links. Nothing was rewritten and nothing
  was dropped: the deployment half is now *Running an installation* on the
  [documentation site](https://praesidiarius.github.io/plc-xivi-docs/running/) —
  configuration, hostnames, deploying, the cron entries, monitoring, self-service
  signup and the command reference, in the order somebody deploying meets them —
  and the
  feature inventory is
  [What exists today](https://praesidiarius.github.io/plc-xivi-docs/what-exists/).
- **`DEVELOPING.md` is new**, and is where the developer half went: `bin/compose`
  and why it exists, dev tenants, Adminer, Mailpit, Symfony AI Mate, `bin/ci`,
  worktree stacks and the package layout. It stays in the repository because it
  has to travel with the commit that changes it.
- **The README no longer carries a hand-written version, and the release
  checklist lost the step that maintained it.** `Version::CURRENT` is the number,
  the release workflow still gates on *that*, and the third gate over the README
  line is gone with the line. **Action on your next release:** *Cutting a
  release* is four steps now, not five.
- **`deploy:check-hosts`, `deploy:check-secrets` and a refused provisioning now
  point at the documentation site** instead of at a README section that has
  moved.
- **`docs/architecture.md` stays here**, and the README now says why: it is the
  record of *why* each decision was made, it is cited by section number
  throughout the issue tracker, and it has to travel with the commit that changes
  the behaviour it describes. The site is the other half — what an installation
  *is*, rather than why it was built that way.

### Decided

- **Only we publish modules** ([XIV-141],
  [§3.2](docs/architecture.md#32-only-we-publish-modules-xiv-141)). There is no
  third-party module and no plugin registry, and the brief now says so where the
  package boundary is argued. Three consequences are written down rather than
  implied: **`Xivi\Core` is explicitly not a public API and has no deprecation
  policy**, which verticals we own is a deliberate list, and deptrac's module
  boundary and the per-package `composer.json` files were built as though the
  answer were open and are kept for what they prevent *inside* this repository —
  not an abandoned plugin plan.
- **An uploadable vertical is a *shape pack*, not a preset — and it is blocked on
  the metadata editor rather than on a file format** ([XIV-141],
  [§6.6](docs/architecture.md#66-a-vertical-as-data-and-whether-it-can-be-uploaded-xiv-141)).
  A `ModulePreset` names a subset of a module's own fields, so a shareable "Law
  Firm preset" can only ever mean *Contact with fewer fields*. §6.6 sets the
  boundary a pack would have — it may do nothing a customer could not do by hand
  in the editor (§5.4) — and finds that the boundary encloses almost nothing
  today, because the editor cannot set a choice field's `choices` or a reference
  field's target module. Fields only, never collections; applied once, never a
  standing schema authority; a tenant administrator may apply one, and nobody but
  us may publish one.
- **The add-field form offers two types it cannot configure** ([XIV-141]).
  `choice` and `reference` are in the type select, and there is no control for
  `choices` or for a reference's `module` — so a field added that way is an empty
  select, or a link that renders every value as `#id`. Found while answering the
  above; §5.4's unfinished sentence, *a type says which of its options are the
  customer's to set*, is what closes it and now has a second reason to exist.

[XIV-18]: https://xivi.youtrack.cloud/issue/XIV-18
[XIV-66]: https://xivi.youtrack.cloud/issue/XIV-66
[XIV-84]: https://xivi.youtrack.cloud/issue/XIV-84
[XIV-102]: https://xivi.youtrack.cloud/issue/XIV-102
[XIV-116]: https://xivi.youtrack.cloud/issue/XIV-116
[XIV-105]: https://xivi.youtrack.cloud/issue/XIV-105
[XIV-118]: https://xivi.youtrack.cloud/issue/XIV-118
[XIV-121]: https://xivi.youtrack.cloud/issue/XIV-121
[XIV-124]: https://xivi.youtrack.cloud/issue/XIV-124
[XIV-126]: https://xivi.youtrack.cloud/issue/XIV-126
[XIV-131]: https://xivi.youtrack.cloud/issue/XIV-131
[XIV-132]: https://xivi.youtrack.cloud/issue/XIV-132
[XIV-142]: https://xivi.youtrack.cloud/issue/XIV-142
[XIV-120]: https://xivi.youtrack.cloud/issue/XIV-120
[XIV-141]: https://xivi.youtrack.cloud/issue/XIV-141
[XIV-114]: https://xivi.youtrack.cloud/issue/XIV-114
[XIV-104]: https://xivi.youtrack.cloud/issue/XIV-104
[XIV-122]: https://xivi.youtrack.cloud/issue/XIV-122
[XIV-123]: https://xivi.youtrack.cloud/issue/XIV-123
[XIV-143]: https://xivi.youtrack.cloud/issue/XIV-143
[XIV-144]: https://xivi.youtrack.cloud/issue/XIV-144
[XIV-119]: https://xivi.youtrack.cloud/issue/XIV-119

## Releases

| Version | Date | What it was |
| --- | --- | --- |
| [17.0.6](docs/changelog/17.0.6.md) | 2026-08-18 | Two images, a price list, vouchers, dashboards you arrange — and a day of guarantees made checkable |
| [17.0.5](docs/changelog/17.0.5.md) | 2026-08-17 | Follow-ups end to end, a control plane you can sign in to, self-service signup, and a build that survives GitHub being down |
| [17.0.4](docs/changelog/17.0.4.md) | 2026-08-16 | The bill for a fast week: a reset that survives, a bounded test volume, and a sign-in page of its own |
| [17.0.3](docs/changelog/17.0.3.md) | 2026-08-16 | Mail end to end, a module store, invitations — and the tooling that made a day like that possible |
| [17.0.2](docs/changelog/17.0.2.md) | 2026-08-16 | Four modules, the money and documents they needed, and a front end that changed twice |
| [17.0.1](docs/changelog/17.0.1.md) | 2026-08-15 | Permissions, localization, and the test suite from 165s to 10s |
| [17.0.0](docs/changelog/17.0.0.md) | 2026-08-14 | The first numbered version: the engine, tenancy, and everything built before versioning began |

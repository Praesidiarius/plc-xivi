## 5. Data model: metadata-driven, not EAV

**Storage shape per entity:**

- Fixed system columns: `id`, `created_at`, `updated_at`, `owner`, soft-delete, etc.
- A JSONB `data` column for the custom long tail.
- **Column promotion**: fields that are hot, unique, or heavily filtered get promoted to
  real columns per tenant, with backfill.

**Metadata layer is the actual product.** Per-tenant definitions of modules and fields —
type, validation rules, UI hints, required/unique/filterable — drive the form, the API
contract, the validation, and the storage shape from one source of truth.

**Field types are a closed registry**, implemented as tagged services. Each field type
owns:

- its storage mapping (JSONB representation and promoted-column type)
- its form type
- its validation constraints
- its normalizer/denormalizer
- its filter/sort behavior in the query layer

Definition rows carry the UI hints beside the rules: `required`, `unique`,
`filterable`, and whether the list shows a column for the field (§5.4).

Closed, not open: adding a field type is a deliberate code change, not customer config.

**A widget is an option, not a type** (XIV-36). The test to apply to the next
candidate is: **does turning it on change what is stored, what validates, how the
field filters, or how it exports?** If not, it is an option on the existing type.
If so, it may be a type. A type per widget is how a small closed set stops being
one.

Which types offer it is the *type's* declaration, which is a first step
toward what §5.4 says the real shape is: a type saying which of its options are
the customer's to set.

It lands very differently on the two types that have it. On `choice` the options
are a closed list in the field's own settings and are already in the page, so
autocomplete is client-side filtering: no endpoint, no permission question, no
ceiling. On `reference` it is the half that was actually broken at scale, and it
needed a server round trip — see §7.6.

**The stylesheet it brings, and why only one of four** (XIV-36, moved here by
XIV-143). Tom Select is what the autocomplete controller attaches to a select,
and it arrives with two JavaScript dependencies and a *choice* of four
stylesheets — default, Bootstrap 4, Bootstrap 5, and a bare one. This
application can use exactly one, and which one is settled by
`assets/controllers.json`; the other three would be downloaded into
`assets/vendor/` and served to nobody.

**That decision used to be a comment in `importmap.php`, which is the wrong file
to keep it in.** Flex regenerates that file when a package is added, and it has
already dropped the comment twice — during XIV-103 and again during XIV-126 —
each time caught by somebody reading a diff rather than by anything that would
notice reliably. An absence that looks like an oversight is one somebody
helpfully corrects, so the reasoning lives here, where nothing rewrites it. The
same argument XIV-111 makes about `config/bundles.php`, one file along.

**A type may need an answer only the application has** (XIV-11). `currency` shows
the price in the currency this installation works in, which lives in the tenant
profile (§8.6) — and core is handed a connection without ever learning whose it
is. So core declares the question as an interface (`InstanceCurrency`) and the
application answers it, the same shape as the entity manager and the connection
being bound in `config/services.yaml`. A field type reaching into a customer's
settings table on its own would be the boundary in §3 quietly gone.

Two consequences of that type worth writing down. **Money is stored as a decimal
string, never a float** — 19.90 has no exact binary representation, and the place
a lost hundredth of a cent turns up is an invoice. And **the currency is not
stored beside the amount**: one per installation means a column of prices adds
up, where per record it would need exchange rates behind it to mean anything.

**Relations stay relational.** Real link tables, real foreign keys. Relations are the one
thing both EAV and JSON are bad at, and a CRM is relational at its core. Relations are
*described* in metadata but *stored* relationally. See §5.1 for the first kind of relation
that exists.

**Validation** is built dynamically from metadata using Symfony's `Collection`
constraint plus per-field constraints, including a custom unique-field constraint
— which since XIV-109 is the *readable* half of uniqueness rather than the
enforcing one, sitting in front of a real unique index on the column (§7.2).

**Records are not Doctrine entities.** Their shape is decided per tenant at
runtime, and mapping that through the ORM means fighting it; the fixed-shape
things — users, and the metadata definitions themselves — stay entities. Records
go through DBAL, in one repository that is the only place knowing *where* a field
physically lives. That is the seam column promotion lands in later without
anything above it noticing, and the query layer (§7.3) will want to build SQL
anyway.

**A module's table is created per customer, not per deploy.** Migrations describe
what every tenant shares; a module's table exists only where that module is
enabled, so the installer creates it when the module is installed for that
customer. Metadata tables themselves are ordinary migrations, since every tenant
has them.

**Definitions are read fully loaded.** A definition fetched inside one tenant's
context and touched outside it would lazily load its fields on whatever
connection is current — throwing when no tenant is resolved, and quietly reading
another customer's database when one is. §7.4 is not only about caches: any
object that outlives the context it was loaded in is the same bug. A module's
collections and *their* fields are loaded with it for the same reason.

### 5.1 Shapes: modules and collections

A **shape** is a set of fields describing the rows of one table. There are two
kinds, and they differ only in what reaches them:

- A **module** is browsable. It has a URL, it is in the navigation, and its
  records stand on their own. Its rows carry an owner.
- A **collection** is not. A contact's addresses have no URL, appear nowhere in
  the navigation, and cannot be reached except through the contact that owns
  them. Its rows carry a parent instead of an owner, are edited inside the
  parent's form, and are soft-deleted with it.

Everything else is shared: the same `field_definition` rows, the same field-type
registry, the same record repository, the same validator, the same form builder.
Adding addresses to Contact added a declaration, a table, and the composition of
two form types — no second repository, no address entity, no address controller.
That is the claim in §1 being tested by something harder than one flat module,
and it is why the two kinds share a base rather than the engine growing a
parallel path for children.

**A collection's rows may come in kinds** (§5.5, XIV-20). An order line is an
item, a comment or a subtotal, and which fields it carries follows from which it
is — a comment line has no price. This is the same mechanism a module's variants
use and it needed almost nothing: `ShapeDefinition` carried a variant field all
along and `CollectionDefinition` simply never passed one up, which is what §5.5
meant by describing *shapes* rather than modules.

Three decisions fell out of building it:

- **Adding a row is choosing its kind**, and since XIV-29 that is a **button per
  kind** rather than a blank row of each. The old arrangement existed because
  switching a row's fields as somebody picks needed scripting and the forms did
  not depend on any; the guarantee is gone (§8.3) and this is the first thing it
  was holding up. The rule underneath survives unchanged — a row's fields follow
  from its kind, so the kind is settled before the fields are drawn, and which
  button was pressed is how it gets settled.
  A collection *without* kinds keeps its one blank row: one row to type an
  address into is an affordance, and it was the plural that made the other a
  mess.
  **The buttons are live actions on the component that owns the form** (§8.3),
  so pressing one re-renders it with a row more and nothing else happens: adding
  or removing a row is explicitly *not* a save, because somebody halfway through
  a form has asked for neither writing nor validating.
  **A row that arrives from the browser gets its fields from what was sent.**
  `allow_add` builds a submitted row from nothing, so the kind is not there to
  read at PRE_SET_DATA time and the row would come back holding only the fields
  every kind shares — dropping, on the way in, values somebody had typed. The
  fields are built again at PRE_SUBMIT, where the kind is legible.
- **A kind is fixed once the row exists**, and travels hidden rather than as a
  select. Offering to change it is offering to make a row disagree with the
  fields it is showing. (A *module's* variant is still editable on its form —
  that is a separate question, and nobody has asked it yet.)
- **A blank row carries its kind and is still blank.** Otherwise saving any
  record would mint one empty line of every kind, which is the bug this rule
  exists to have already fixed.

**Rows keep the order the customer put them in** (XIV-21), on a `position`
column beside the parent id, numbered in **tens** and renumbered on every save.
Typing a number rather than pressing move-up and move-down, which used to be the
difference between a form submission each and one save. Since §8.3 that reason is
spent and the choice is open again — though typing 15 between 10 and 20 is a
thing people already know how to do, and buttons that swap two rows are a worse
fit for moving one line past nine others.

**Moving a row is not a change to it.** The id does not move and the history says
nothing happened, because nothing about the row did — where it sits is a property
of the list. That also makes an import keep the order of its file for free: rows
are numbered as they arrive, whether they arrive from a form or a spreadsheet.

The column is added to existing collection tables by a **data-driven migration**,
which is unusual here and unavoidable: a collection's table is created per
customer by the installer rather than by a migration, so only the tenant's own
`shape_definition` knows which tables exist.

**A field may be inherited from the record it points at** (XIV-18). An order line
names an article and shows its description and price — *copied* when the line is
written, not read through afterwards.

**Numbers come in three kinds, and the difference is meaning rather than
storage** (XIV-22): `integer` for things you count, `decimal` for things you
measure, `currency` for money. The last two are the same string in the database
and differ in what they print — a currency symbol beside a number of hours is
wrong in a way no amount of formatting fixes, which is why the engine grew the
middle one rather than letting quantities borrow the money type. **How many
places is the field's own setting** — hours want two, kilos might want three —
and a scale beyond what the storage promises is clamped rather than refused, so
forty places means "lots" instead of an error about a number nobody was going to
type. A *unit* belongs to the article rather than to the line, and §5.20 is where
it now lives — for four tickets this paragraph said it was "deliberately absent"
and pointed at an article module that had no unit either, so a line read `2.5` of
nothing and the sentence described a place that did not exist (XIV-118).

**A field can be derived rather than typed** — a line's total, a subtotal's
figure.

**How wide a field is drawn is the field type's answer, until somebody disagrees**
(XIV-43). It is a **proportion, in twelfths, never a class name** — what the grid
is called belongs to §8.3 and outlives whichever framework renders it. Ordering
(XIV-21) plus width *is* the layout: the grid wraps a line once its columns pass
twelve, which is why this needed no layout editor, no rows as an entity, and no
drag surface in the metadata editor.

**A collection is deliberately not a link between modules.** Contact → Company is
a different thing: both sides exist independently, either can be browsed, and the
target module may not even be installed for that customer (§3). Conflating the
two is how a CRM ends up with orphaned addresses nobody can reach. When
module-to-module links arrive they are their own mechanism; §7 tracks them.

#### How long a collection can get, measured (XIV-68)

**Nothing bounds one.** `findChildren()` has no `LIMIT`, the record page draws
every row it returns, and so does the form. Everything else that reads records
has a ceiling — a list is 25 a page, a reference picker stops at 200 and says so
— and this is the one path with none. XIV-68 named three possible bounds and
refused to choose between them until somebody had a number. This is the number.

Measured by `tests/Measurement/CollectionCeilingTest.php`, which builds an order
of N article lines against a catalogue of 250 articles and asks for the two pages
that draw them. It is in no test suite: `bin/ci` should not spend four minutes
building ten thousand order lines. **This section is the evidence and nothing
else**; the decision taken from it is the next one, and everything below was
written before it was taken — the numbers are what they were on the day, with the
corrections named where they landed.

Per request, `APP_DEBUG=0`, memory counted at the allocator:

| rows | read ms | read bytes | read queries | read MB | edit ms | edit bytes | edit queries | edit MB |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 60 | 32 K | 50 | 0.5 | 226 | 132 K | 31 | 5.7 |
| 100 | 240 | 186 K | 391 | 1.7 | 1 392 | 1.2 M | 205 | 45.6 |
| 500 | 682 | 870 K | 1 600 | 7.0 | 5 878 | 5.8 M | 973 | 221 |
| 1 000 | 785 | 1.7 M | 2 896 | 13.4 | 11 756 | 11.6 M | 1 933 | 444 |
| 5 000 | 3 505 | 8.6 M | 14 416 | 66.4 | 59 463 | 58.3 M | 9 613 | 2 206 |
| 10 000 | 7 714 | 17.1 M | 28 816 | 132.5 | 125 017 | 116.6 M | 19 213 | 4 396 |

Everything is linear in the row count, which is the first thing worth saying: the
page does not degrade at some threshold, it scales exactly, and the constant is
large. **13 KB of memory per row on the read view and 0.44 MB per row on the
form** — a factor of thirty-three between two pages showing the same rows.

**Where it falls over is memory, and the number is 128M**: what a PHP request is
allowed, which is the stock default and is not raised anywhere here. The **edit
form crosses it at roughly 250 lines** and the **read view at roughly 9 500**.
Above that the page does not get slow, it answers 500 — and not a 500 anybody can
read: pinned at 512M to watch it happen, the form dies with "Allowed memory size
exhausted" inside Twig, half-rendered.

**Two hundred and fifty lines is a real document**, which is what makes this
finding worth acting on. Ten thousand is not.

**Neither view is expensive for the reason the ticket predicted.** XIV-68 blamed
the row of inputs; the inputs are not what costs.

- **The read view's queries were the drift check, and XIV-54 removed them.** An
  order line inherits three values from its article (§5.1's copied values) and
  `InheritedValues::driftedIn()` resolved the reference once per inherited field
  with no memo — **three identical `SELECT`s per line**, which was 28 800 of the
  read view's 28 816 queries at ten thousand rows. The table above was measured
  before XIV-54 landed, and predicted that ticket would remove "a percent or so"
  and leave the page O(N). **That prediction was wrong**, because XIV-54 widened
  its own scope to cover exactly this: `InheritedValues` reads through the shared
  `ReferenceTargets` memo now rather than going to `RecordRepository` per field.

  Re-measured on the merged tree, same catalogue of 250 articles:

  | rows | read queries before | read queries after | read ms before | read ms after |
  | ---: | ---: | ---: | ---: | ---: |
  | 10 | 50 | **18** | 47 | 47 |
  | 100 | 391 | **18** | 240 | 91 |
  | 500 | 1 600 | **18** | 682 | 323 |
  | 1 000 | 2 896 | **18** | 785 | 555 |

  Flat, not merely bounded by the catalogue. What remains linear on the read view
  is bytes and memory, which is the rendering rather than the reading — so the
  read view's ceiling is still where the table above puts it, and it is no longer
  a query problem at all.
- **The form's queries were the picker, and XIV-87 removed them without moving
  the ceiling.** `RecordReferenceType` resolved its candidate list through a lazy
  option, which the resolver computes per form *instance* — and a collection row
  is a form, so five hundred lines rebuilt the same list five hundred times.
  Reading it once per request took the 500-line form from **973 queries to 13**,
  the same 13 a 100-line form makes, and about a third off the render time.

  **It moved memory from 221 MB to 212 MB and the byte count not at all** —
  5 830 106 before, 5 830 105 after. That is the finding worth keeping: every row
  still *renders* two hundred `<option>` elements whether the list behind them
  was read once or five hundred times, so the edit form's limit is a **rendering**
  cost rather than a query cost. XIV-68's estimate that fixing the picker would
  move the ceiling from ~250 lines to ~400 was extrapolated from shrinking the
  *catalogue*, which also removes the options from the HTML; batching the reads
  does not. What would actually move it is a control that never emits the options
  — which is XIV-36's autocomplete, arriving from a direction nobody chose it for.

- **And it did.** XIV-36 makes a picker with more than twenty candidates a search
  box, and a catalogue of 250 articles is exactly such a picker — so a 500-line
  order form stopped emitting 200 `<option>` elements per row without anybody
  choosing that as the fix. Measured on one machine, both branches back to back,
  the same 500 lines against the same 250 articles:

  | | bytes | peak MB | queries | ms |
  | --- | ---: | ---: | ---: | ---: |
  | before (XIV-87) | 5 829 901 | 268.9 | 13 | 4 186 |
  | after (XIV-36) | **2 173 433** | **233.6** | 15 | **3 032** |

  **Bytes −63%, memory −13%, time −25%, and two queries more.** The two are the
  candidate list `auto` decides on — read once for the request, not once per row
  — and one priming statement; both are flat in the row count. Memory moves far
  less than bytes because §5.1's other finding still holds: the weight of the
  form is the *forms*, one per row with a `FormView` behind it, and no widget
  changes that. So this raises the ceiling somewhat and does not remove it, which
  is the honest reading of the same table that predicted it.

  **It needed the memo to be true.** An autocompleting picker has no candidate
  list to name the linked record out of, so each row asked separately what its
  article was called — 494 queries on the first measurement, worse than what
  XIV-87 had just fixed. Reading through `ReferenceTargets` and priming the
  rows in `RecordFormData` (§5.3's argument, applied to the form rather than the
  page) is what brings it to 15.

- **The form's weight is the form.** Every row is a Symfony form of eight
  controls with a `FormView` behind it, and that is the bulk of the 0.44 MB.
  Measured against a catalogue of 25 articles instead of 250 — which shrinks the
  picker from 200 options to 25 — the same 500-line form drops from 5.8 MB and
  221 MB to 2.3 MB and 168 MB. So the picker is 60% of the *bytes* and only a
  quarter of the *memory*: `RecordReferenceType` re-resolves its candidates per
  form instance, which is two queries and 200 `<option>`s per row and is worth
  fixing, but fixing it moves the ceiling from ~250 lines to ~400, not to
  thousands. A page that builds one form per row is expensive per row, and no
  amount of query work changes that.

**The record page has a second unbounded render nobody has counted.** The history
card shows five entries (§5.2), and `_history.html.twig` draws every collection
change inside each of them — so the entry recording the creation of a
10 000-line order is 10 000 list items on the record page, beside the 10 000 rows
of the lines table. Bounding `findChildren()` alone would leave that standing.

Two things that turned out **not** to be the problem, recorded so nobody
re-measures them: writing a long document is fast — 2.4 s for ten thousand lines
with the derivers running, two per cent of what drawing the form costs — and the
row data itself is small. The rows are not the weight; what the rows make the
page build is.

#### Four hundred rows, and the room to draw them (XIV-68)

The decision the table above was taken for. **Four hundred rows is the supported
size of a collection**, refused above that at write time, and a request is
allowed 256M so that four hundred renders.

**The cap alone was not sufficient, which is the part that is easy to miss.**
Re-measured on the merged tree after XIV-36 took 63% off the form's bytes, same
250-article catalogue, `APP_DEBUG=0`, per request:

| rows | edit form peak |
| ---: | ---: |
| 100 | 35.9 MB |
| 300 | 105.9 MB |
| **400** | **140.3 MB** |
| 500 | 173.1 MB |

The 100 and 400 rows were taken again after the cap and the ini change landed —
35.9 MB and 140.3 MB, both answering 200 — and 500 cannot be taken again, because
the cap now refuses to build the fixture. That is the right way round: the tool's
job is no longer finding the ceiling but confirming that the supported size still
draws, so its default sizes stop at 400 and measuring past it is a deliberate act.

**Shape C — paginating the edit form — was declined rather than forgotten.** It
was the right answer while the ceiling looked like 250 rows and a legitimate
3 000-line order looked plausible. Against a 400-row cap it is a large change to
what a partial submit means: positions are renumbered across the whole list
(XIV-21), a collection the writer is handed is *the* contents of that collection
and anything missing is removed, and every derived total on the record is a fact
about all of its lines at once (§5.9). Paying for all three to serve a case that
does not arise is the trade being refused.

**The read view keeps no bound of its own**, and that is a decision rather than an
omission. The same argument retires the history render noted above:
`_history.html.twig` draws every collection change inside each of the five shown
entries, so the creation entry of an N-line order is N list items — alarming at
10 000 and unremarkable at 400.

#### Counting the rows before the form is built (XIV-90)

**A cap you can only enforce by first doing the thing it forbids is not a cap.**

Measured on this branch by `testWhatAnOverLongSubmissionCosts()`, an order of
article lines posted to the component's `save` action, `APP_DEBUG=0`, per
request:

| submitted rows | peak MB | ms | status | |
| ---: | ---: | ---: | ---: | --- |
| 400 | 250.2 | 4 383 | 204 | saved |
| **401, before** | **273.3** | 6 282 | 200 | refused, *after* building it |
| 401, after | **1.9** | 31 | 200 | refused before building it |

**273 MB against the 256M a request is allowed** — so the refusal could not be
rendered, and what a hand-crafted over-long post actually produced was the
"Allowed memory size exhausted" out of the middle of Twig that the cap exists to
remove. The fix takes it to 1.9 MB and 31 ms.

**Two things in that table are worth reading beyond this ticket.** The first is
that 400 rows *submitted* costs 250 MB where 400 rows *drawn* costs 140 — the
submission pays for the form twice and the write once, and it sits at 98% of the
allowance rather than the 55% the render sits at. The headroom the cap was chosen
with is real for the page and thin for the post. The second is that the "before"
figure is 273 and not the 280 a doubling predicts, because the throwaway form and
the real one overlap rather than stack cleanly; the shape of the argument is
unchanged and the arithmetic in the ticket was close enough to act on.

**The 98% is accepted, deliberately, and this is the record of that** (decided
2026-08-18). Four hundred stays the cap and 256M stays the limit, knowing that a
save at exactly the supported size uses almost all of its allowance. The argument
is that the number is not the risk it looks like: a real order is well under a
hundred lines, so the case sitting at 98% is a document nobody writes, reached
deliberately by somebody adding four hundred rows. The failure if it is wrong is
also the mild one — a save refuses with a sentence, which is what XIV-90 built,
rather than the half-rendered exhaustion the cap was introduced to remove.

What is *not* accepted is being surprised by it later. Two things follow. **The
per-row constant on the post path is about 0.62 MB, not the 0.35 the render pays**
— roughly double, because the submission builds the form twice and writes once —
so anything that grows a row grows the post path twice as fast, and it is that
figure a future change has to be held against. And **the next move, if this ever
does bite, is the limit rather than the cap**: four hundred rows is a promise made
to customers, while `memory_limit` is a line in an ini file, and the two are not
equally expensive to change.

**What is still open.** Reaching the cap through the interface takes four hundred
"add row" round trips, and the four hundred and first is not refused: the button
appends and the re-render then costs what a 401-row submission costs. Nobody
arrives there by accident and this ticket did not close it, because refusing an
add needs a sentence of its own — "nothing was saved" is the wrong thing to say
about a row that was never added — and that is a decision rather than a line of
code.

### 5.2 History is per module, and per action

Every change to a record is recorded: who, when, and what changed.

**One history table per module, not one for the system, and not one per shape.**
`contact_history`, `order_history`. A single `history(entity_type, entity_id)`
table is the design this project has already watched fail: at 60M rows the
relating id meant an order, a contact or an article depending on the row, so it
**could not carry a foreign key** — no integrity, no cascade, orphans
accumulating, and a planner with nothing useful to narrow on. Splitting per
module is what makes `record_id` mean one thing, and therefore what makes a real
foreign key possible. Size is the lesser half of the argument.

§4 does the other half of the work here without being asked: history lives in one
customer's database, so the table that was 60M rows shared is now many small ones.

A collection's events go in **its parent module's** table, tagged with which
collection and which row. An address has no independent life (§5.1), nobody asks
for an address's timeline, and the timeline anyone does want — the contact's —
stays a single indexed read instead of a union.

**Fixed shape, not metadata-driven.** The columns are identical for every module,
so this is an ordinary table created by the installer alongside the module's own,
with no field definitions. Making history describable would buy nothing and cost
every index.

**One entry per action, not per row touched.** Fixing an email and adding an
address in one save is one line in the timeline, not three. The grouping key is
the *record*, so an import touching 500 contacts still writes 500 entries.

That granularity is why writing goes through **`RecordWriter`**, and why it is
the *only* supported way to write a record; `RecordRepository`'s mutating methods
are internal to it. Otherwise the first import to call the repository directly
would silently write no history, and a history with holes in it is worse than
none, because it is trusted.

**Merge rules**, so the timeline stays readable:

- The same field changed twice in one action records first `from` to last `to`.
- A value that ends where it started is not a change.
- An empty diff writes no entry at all. "Edited, nothing changed" is most of what
  makes these logs unreadable. `created` and `deleted` are always recorded.
- `action` is the root record's own verb: adding an address to an existing contact
  is an update *of the contact*.
- Deleting a record writes one entry; its collections cascade silently.

**Values only, no reads.** Recording who *looked* at a record is a different
feature with roughly a hundred times the volume and a different retention answer;
it is an optional extra later, not part of this.

**One exception, and it is deliberate: generating a document** (§5.7, XIV-4). It
changes nothing, so by the rule above it does not belong here — but a letter that
went out is a fact about the record's life in a way that opening a page is not,
and it is rare, deliberate and attributable where a page view is none of those.
The entry names the template and the format, because a timeline saying only
"document generated" answers the least interesting half of the question. It is
dispatched as the same `RecordChanged` event a change dispatches, so there is one
answer to "who did this, and when" and one listener that knows how to write it
down. That the rule now has an exception is the thing to watch: the next
candidate should have to argue the same three properties.

**Reading it back is paged, and an entry is one line** (XIV-3). A timeline is the
one part of a record that grows without limit, so the record page shows a fixed
handful and says how many there are, and the whole thing is a page of its own —
twenty-five at a time, grouped into today, this week, this month, this year and
earlier, with anything older than a month opening closed. What each entry
*changed* sits behind a native `<details>` rather than under every row: printing
every diff is what made fifty entries unreadable, and it is also what made the
page's cost grow with the record's age instead of with the window being shown.

It is ordered by **when things happened**, with the id breaking ties. Ordering by
id alone gives the same answer for as long as rows are only appended as things
happen — and a different one the moment anything writes an entry with an older
timestamp, which a backfilling import reasonably would. That was invisible while
the page was one flat list and is not once it draws a boundary between days.

**Because it stores the values, it is also a time series** (XIV-121), and that
is worth stating here rather than being rediscovered. The diff holds `from` and
`to` and not merely the fact that something moved; the `created` entry records
every field the record was born with as a change from null; `RecordWriter` is the
only supported way to write a record, so there is no path that writes a value and
no entry; and nothing prunes the table. Put together, the chain of values for any
one field is unbroken from a record's creation to the moment it is read — so
"what was this article's price in March" is a question this table already
answers, and a second table holding a price series would be a copy of facts
already recorded, kept in step by hand, with two answers the first time it was
not. §8.3.1 is where that gets drawn as a line; `HistoryRepository` grew one
extra read for it, selecting only the entries with a `fields` branch and
returning them oldest first, because a trend is the opposite shape from a
timeline: the whole life of one value, with the *old* end carrying the
information.

Still to decide: retention and whether `occurred_at` wants range partitioning.
Cheap now, expensive at 60M rows. And field types will need a way to say "do not
record this value" before the first sensitive type ships.

**Retention has a second consumer now**, which changes what deciding it means.
While this table was only a timeline, dropping entries older than some horizon
would have cost a page nobody scrolls to the end of. Since XIV-121 it is also
where a chart comes from, and pruning the far past is precisely pruning the half
of a trend that carries the shape. Whatever retention turns out to be, it has to
be a decision made in front of that, not one made about a log.

### 5.3 Asking questions: the query layer

A `RecordQuery` — conditions, ordering, one page — compiled to SQL against the
customer's own definitions. It is what §7.3 called the highest-risk component,
and it was built after collections precisely so that it was designed against a
to-many relation rather than retrofitted with one.

**Nothing from a user is concatenated.** A filter the engine cannot answer raises
rather than being dropped — a condition that silently does nothing shows a list
that looks like a result and is not one, which is worse than an error because
somebody acts on it.

**A condition on a collection is a semi-join.** `EXISTS`, never `JOIN`.

**Sorting by a collection is refused.** Refusing is the feature; quietly picking
one would be a wrong answer that looks right.

**The field type owns its comparisons**, as §5 always said it would. The compiler
therefore has no switch on field type, and column promotion will change the
accessor without touching any of it.

**Every ordering ends on the record id.** Without a total order two records
sharing a sort value may swap between pages, so one is shown twice and another
never. Any list with a LIMIT needs that tiebreaker.

Deliberately not built: `OR` between conditions, which needs a tree and a UI to
build one rather than the list of ANDs that covers the honest 90%; and keyset
paging, which is the answer when someone is on page 400 and until then costs a
sort key in every URL. LIMIT/OFFSET is correct, and slower the deeper it goes.

Deliberately **never** built, which is a different word: a filter somebody writes
as an *expression* rather than as a condition (XIV-88, §5.8). It would have to be
evaluated in PHP over records that are already loaded, so it could not narrow the
page it is meant to narrow, and the count beside it least of all.

**One closed disjunction, which is not that `OR`** (XIV-36). A `RecordQuery` may
carry a `Search`: one string, looked for across a fixed set of the shape's own
fields, compiled as a single parenthesised group and ANDed with everything else.
What the paragraph above refuses is a tree: something that composes, that a URL
can express, and that needs an interface to build. This composes with nothing,
and its fields come from the definitions rather than from a request.

#### Once a set of records is in hand, read what it names (XIV-54)

A reference renders as the *name* of the record it points at (§7.6), and a name
is a second row from a second table. Asked for one value at a time — which is how
a template renders — that is a lookup per value.

**The number that matters is a collection's, not a list's.** XIV-46 measured this
against a 25-row list and XIV-53 then removed most of what it measured, so both
earlier conclusions were about the wrong number. `findChildren()` has **no
LIMIT**: a record page draws every row a collection has, so an invoice with 500
lines draws 500 rows and each one names an article. Measured on an order page
before this existed: 34 queries at 5 lines, 214 at 50, **2014 at 500** — four per
row, because a line asks about its article twice over (the reference for its name,
the drift check for whether the price copied off it still matches, §5.9) and the
drift half had no memo at all. The same rows drawn into a .docx cost 503 for 500
lines, which is the worse place to pay it: the request is already waiting on a
converter.

**The objection that had blocked batching was about rendering and not about
data.** There is indeed no moment during rendering at which every id is known —
`display()` is called per value, one row at a time. But both call sites hold the
whole set *before* rendering starts: a list has its page back from `findBy()`, a
record page has every row back from `findChildren()`. So the priming pass has an
obvious home, and the shape is one `WHERE id IN (…)` per target module — the same
move `findChildrenOfAny()` already makes one level up, copied rather than
reinvented.

Afterwards, the same pages: **16 queries at 5, 50 and 500 lines** — flat, and the
assertion in `ReferencePrimingTest` is `assertSame` between two sizes precisely so
that a bound which starts growing again fails rather than merely gets slower. The
document path: 503 → 4. The list, which was never the case this was built for and
was primed because by then it was one line: 32 → 8 for 25 rows naming 25
contacts.

Not touched here, and named so it is not confused with this: the unbounded row
count itself is XIV-68. Priming makes 500 rows cheap to *name*; it does not make
drawing 500 rows a good idea.

---

### 5.4 The metadata editor

**Definitions are read once per tenant** (XIV-53). Every field type asks for its
own shape, every reference for its target's, every reverse-link group again — so
metadata was the largest single source of queries on every page measured
(XIV-46). A record list naming twenty-five different contacts made 83 queries and
now makes 33.

The lifetime is the whole design, because a cache of one customer's definitions
handed to another is §7.4's hazard and would look like wrong labels rather than
like an error. It is **emptied whenever the tenant context moves**, in the same
breath as dropping the identity map and closing the connection, because they are
one fact about one boundary — a web request is a process and cannot outlive
itself, but a console command walking every tenant can. Writers empty it too: a
page still showing the shape somebody has just edited would look like the edit
had failed.

There is deliberately no tenant *key*. Keying it would make it look safe to keep
entries across a switch, and a definition kept across that boundary would load
its fields on whatever connection is current — the hazard, not the fix.


A customer changing the shape of their own module, without SQL and without a
deploy. A field added here appears in the form, the list, the validation and the
filter bar at once, because all four read the same rows — which is §5's claim
stopping being an argument and becoming a page.

Admin only. That makes it the first thing in the application to need more than
"signed in": §8.4 leaves the real model open, and changing what a module *is*
seemed the wrong place to keep waiting for it.

It edits any shape, so a collection's fields are editable exactly like a
module's (§5.1).

**What it refuses, and why each refusal is the feature:**

- **A field's type cannot be changed.** Not a disabled control — there is no
  `setType()`. Stored values may not survive a new type, and "convert what you
  can" is data loss on a click. This is the half of §7.2 still open.
- **A field's key cannot be changed.** The key is where the value lives, so
  renaming one would orphan every value it names. The label is the part people
  read, and that is freely editable.
- **A rule cannot be switched on if existing records would fail it.** Making a
  field required or unique is a promise about data that already exists; applying
  it blind leaves records nobody can save until they work out why. The editor
  counts first and refuses with the number. Relaxing a rule is always allowed,
  because it cannot invalidate anything. **The unique half also names the shared
  values** (XIV-109), because a count on its own is not something anybody can act
  on: four records among six hundred, with nothing to search for. The values are
  the search terms. And the flag no longer only decides what a validator checks —
  ticking it builds a unique index on the column and unticking it drops one, in
  the same transaction as the definition row; see §7.2 for why that had to change
  and what it means for a save that loses a race.
- **A module's own fields cannot be removed.** Only the ones the customer added.
  §7.2's other half, unchanged.

**What the form does not mention, it does not touch** (XIV-26). Options are where
the declarative half of the engine lives — a choice field's `choices`, a
reference's `module`, an order line's `inherit`, a numbered field's `sequence` —
and this form draws three settings. A setting a form does not mention is one it
can neither wipe nor invent.

**A type says which of its options are the customer's to set** (XIV-36, then
XIV-27), so the editor holds **one declared list of option to capability
interface** — `autocomplete` to `Autocompletes`, `sequence` to `Numbers` — and
resolves it once against the registry. A third option is a marker interface, a
line in that list and a control in the template, rather than another branch
through the controller.

What stays per option, deliberately, is *drawing* it. A select of three fixed
answers and a numbering pattern with a live preview and a counter beside it have
nothing in common except the question "may this type have one"; generalising the
control as well would mean inventing a widget-description language to save two
`{% if %}`s, which is the speculative generalisation §1 warns about wearing a
different hat.

#### The two the editor offered and could not configure (XIV-144)

**The fix is the fourth and fifth entries in that list, and one new idea.** A
capability may now say that it is **not optional** — `NeedsAnAnswer`, which
`Enumerates` and `PointsAtAModule` extend, and whose one method is the list of
options a field of that type is not finished without.

**A sixth entry followed, and it is a different shape** ([XIV-127], §5.26). Every
entry so far names one option answering one question. A `choice` field's values
may now come from its own options *or* from a shared list, which is two complete
answers to one question — so `needs()` is a list of *questions*, each carrying the
options that answer it, and the rule is still "every question answered, by
something". The editor's side of it got stricter rather than looser: **every** way
of answering has to be drawable, because a type offering two answers of which
this form can ask only one is finishable *and* has a setting unreachable from the
only screen there is.

**A test over the registry is the part that stops this recurring**, and it is
deliberately not a test about `choice` and `reference`. The defect was never that
two types were forgotten; it was that nothing anywhere compared what a type needs
with what the editor can ask, so they drifted apart in silence and the twelfth
type would have drifted the same way. `EditorConfiguresEveryTypeTest` walks the
container's own registry and asserts the comparison, and — because an invariant
nobody has watched fail is an invariant nobody knows is connected to anything,
which is what deptrac taught this project in XIV-60 — it also plants the
violation: a type declaring a need no control exists for, refused by the same
function the select is built from.

**And a seventh, which is optional again** ([XIV-136], §5.27). A period field may
say what it is exclusive within — the room, the machine, the person — and a field
that says nothing is an ordinary field whose values may overlap each other freely,
which is what a project's duration wants. `ExcludesOverlaps` cost the same three
things as the third: an interface, a line in that list, one `<select>` in the field
table. What is new about it is the *control*: it is the first one whose answers
come from the shape being edited rather than from a fixed list or from the
container, since what a period is exclusive within is a field beside it.

##### Removing an option that records hold: refused, and [XIV-127] follows this

A list is the first of these settings that somebody **edits** rather than
answers, and editing has a direction the others do not: taking an entry away.

The decision is **refuse, with the values named and counted**, and it is the same
decision as making a field unique, reached the same way. Removing an option a
record holds destroys nothing — the value stays in the JSON and `display()` falls
back to printing it raw — but it leaves that record failing its own field's
validation, so the next person to open it and press Save is told their record is
invalid for a reason that has nothing to do with what they were doing. That is
the trap this section already refuses in general terms. Rewriting the affected
records to some other option is data loss on a click; leaving them broken is the
trap; so the answer is no, with enough in the sentence to make it a yes next
time. The options page prints the count beside every option, because a rule
somebody meets as a refusal is a rule they learn one failure at a time.

**Retiring an option — keeping it valid for the records that have it and taking
it out of the picker — is the genuinely better answer for the customer who has
stopped selling by the pallet and has four hundred old orders that were, and it
is deliberately not built.** It is a third state per option, every reader of
`choices` has to understand it, and it is the same question [XIV-127] has to
answer for a shared list. Building it here would be building a third of that
feature early and unbuilding it later, which is precisely the argument §5.20 used
to keep units out of a table of their own.

**[XIV-127] must follow this answer rather than inventing its own.** It proposes
lists a customer maintains once and several fields across several modules point
at — "our units", "our topics", "our payment terms" — and the removal question
there is this question with more records behind it. A shared list that quietly
lost an entry would break records in modules the person removing it was not
looking at. So: **a list somebody's records point into cannot lose an entry while
they point into it, whether the list lives in the field or beside it**, and if
[XIV-127] wants a friendlier answer than a refusal, the friendlier answer is
retirement and it has to arrive for both at once.

**[XIV-127] landed and followed it** (§5.26). A shared list refuses a removal
with the same sentence reached the same way, and names the *fields* as well as
the values and counts, because its records are in modules the person removing the
entry is not looking at. It did **not** build retirement, and said so plainly:
the condition above is what stopped it — a third state per entry that has to
arrive for `choices` and for a list at once, or a customer meets one picker that
can retire and one that cannot. What it did instead was remove the commonest
reason for asking: `Zurich` and `Zürich` become one entry rather than a dead one
somebody wants hidden.

##### A module's own field's options may be added to and never taken from

This section's oldest rule, one level down. A module's own **fields** cannot be
removed because the module's code is written against them; a module's own
`choice` field's **options** are that same code's expectations written into the
definition — an order's `status` list is the states its lifecycle moves records
between, a contact's `kind` list is the variants the module ships forms for.
Either one losing an entry breaks the module rather than the record, and from a
table cell.

So on a module's own field: **add and rename, never remove.** The half that
matters is the first: the wholesaler who wants "pallet" beside the seven shipped
units (§5.20) and the workshop that wants "machine" beside the six shipped topics
(§5.22) are the two customers this whole change was written for, and both of them
are adding. Renaming is free by construction — see below.

**Adding to one has consequences worth naming**, and they are the engine working
as designed rather than a hole in this rule. The variants of a shape *are* its
variant field's options (§5.5) — "no second list to keep in step" — so a customer
who adds an option to Contact's `kind` has added a third kind of contact, which
appears in the picker, the filter bar and the record form with the module's own
two. A state added to an order's `status` is a state the lifecycle has no
transition into or out of, so records can be filtered by it and nothing can move
them there. Both are legible, neither breaks anything, and both are what the same
customer would have got by adding their own `choice` field — which is the test
this rule is really applying.

The refusal is blunt on purpose: *any* removal, not only of the options the
module happens to name. The definition records which **fields** came with the
module and does not record which **options** did, so there is no way to tell a
customer's own eighth unit from the seven the installer wrote. Refusing all of
them costs somebody a dead entry in a dropdown they added by mistake; allowing
all of them costs somebody their order lifecycle. Provenance per option is
[XIV-127]'s to model, and it is the right place for it.

**It was not the right place, and [XIV-127] says why** (§5.26). A shared list
models entries as rows and *could* record who added each one — and does not,
because on a shared list the question never arises: nothing seeds a list, so
every entry in one is the customer's and a provenance column would hold a single
value. Narrowing the refusal above is therefore still open and is still a problem
about options inside a *field definition*, which is this section's shape rather
than something a list can hand it.

**And a module's own `choice` field cannot be pointed at a shared list either**,
which is this rule holding against the longer route: a list the customer
maintains is a list the customer can take entries out of, so allowing it would
let a table cell somewhere else do exactly what the paragraph above refuses. A
customer who wants their own vocabulary on a module's field adds a field of their
own and points that at the list.

##### The value is derived from the label, once

What every record holds is a **key**; what the page shows is a **label the
customer may rename**. That split already existed (§5.20) and had never been
exposed to anybody, because until now the only way to write an option was a
module's installer.

The editor asks for labels and derives the key from the first one it is given,
then never touches it again: "Pallet" becomes `pallet`, and renaming the option
to "Palette (EUR)" afterwards changes what the page says and moves no record.
Asking for the key as well would mean asking somebody who wants a seventh unit to
understand a distinction that only matters when it is too late to change it. The
derivation is `AsciiSlugger` pinned to `de`, with the argument [XIV-100] makes at
length about the self-service slug: the value is permanent and the language
somebody happened to have the page open in is not.

The trade is that a **typo in a label is permanent in the key**. It is the right
trade — nobody but an export column ever sees a key, the label is fixable in the
editor, and the alternative is a rename that silently orphans records.

##### A reference's target: refused once anything points through it

An id is only meaningful in the module it was chosen from. Repointing a populated
reference leaves every stored id addressing a row that is either somebody else's
record or nothing at all, and **nothing would report it**: the ids are valid
integers and every page would carry on naming records, the wrong ones. That is
the quietest failure in this section, so it is the one refused with a count
rather than warned about — the field may be repointed while it is empty, and not
once records point through it. A module's own reference is refused outright, on
the rule above: its target is what the module's own forms, documents and totals
expect.

Two smaller decisions go with it. The target must name a module **this customer
has installed**, checked on the write path rather than in the select, because a
target that is not installed is exactly as broken as no target. And moving a
target **clears the `variant`** beside it — a variant is a value of the old
module's variant field and narrows nothing in the new one, which would be an
empty picker arrived at from the other direction. The variant itself still has no
control; a reference that says nothing about it offers every record of its target
module, which makes it an optional setting of the ordinary kind.

##### Where the controls are, and what is still missing

The target is a `<select>` in the field table's row and in the add form, on the
same terms as the country and the search box. The **list is a page of its own**,
for numbering's reason: it is a row per option, a rename that must not move a
record and a removal that may be refused with a paragraph, and in a table cell
the change with the most consequences would look like the cheapest one on the
row. The add form asks for the list in a textarea, one label per line, because
the engine will not write a choice field without one — the question has to be
asked before the field exists.

Deliberately **not** in this:

- **retiring an option**, argued above, and with it any way to remove an option
  that history holds;
- **repairing an unfinished field** other than by pointing it somewhere or giving
  it options — a `choice` field with no list that predates this rule is *marked*
  in the editor and is otherwise left exactly as it was, because nothing new can
  reach that state;
- **the `variant` narrowing**, which still has no control and is still cleared
  rather than migrated when a target moves;
- **provenance per option**, which is what would let a module's own field give up
  an option the customer added to it — still open after [XIV-127], and still this
  section's problem rather than a shared list's;
- **changing a field's type**, which is §7.2's open half and is not made any
  easier by any of this.

#### Sections: a form of twenty-five you can read (XIV-119)

A field carried `position` and `width` and nothing else, so a module's form was
**one flat run of inputs**, however many there were. That is fine for Contact's
eight. It stops being fine the moment somebody does the thing this product exists
to let them do: a contact with billing details, delivery details, three custom
references and six fields of their own is twenty-five inputs in one column, and
nobody can find anything. The order module's own form got busier the day before
this was written (XIV-122), which is the same argument arriving without being
asked for.

A **section** is a heading and the fields under it — *Contact details*,
*Billing*, *Notes* — with the fields keeping their order and their width inside
it.

##### A section is not a collection, and that has to be said out loud

The two will look similar to whoever arrives next, and they are not similar at
all. §5.1's **collection** is a second way of grouping *records*: it has a table,
rows, field definitions of its own, a foreign key back to the record that owns
it, its own validation and its own history. A **section** has a word and a
number. A field in a section is the same field, in the same record, under the
same key of the same JSON payload; it is stored the same way, validated by the
same rules, found by the same filter, and named by the same document marker —
§5.7 addresses fields by key and has never heard of any of this. Only the form
and the record page draw it differently.

**Everything below follows from keeping that true**, and the moment any of it
stops being true a section has quietly become a second collection, which is a
feature this product already has and does not need twice.

That is why a section is **a value rather than a row**: it has no table, no id
and nothing that can point at it but a string on a field, so there is nothing for
a query to join to. Giving it a table would be handing somebody the join, and the
first join is the moment it stops being presentation. It is also why the grouping
never enters the **form tree**. Symfony's own way to group controls is
`inherit_data`, and it would work — but it moves the grouping into the form,
which is where the submitted array is shaped, where the `data-model` paths are
built, and where `RecordSubmission::mapViolations()` finds a field by key among
the direct children of `fields`. A presentation decision able to reach any of
those is no longer only a presentation decision. So the form tree stays flat and
the *template* draws the runs, which is Symfony's other answer to this and the
right one here.

##### Where a section lives: the membership on the field, the section on the module

The **membership is a property of the field** — `field_definition.section_key`,
null for none. A container holding a list of fields was rejected: a field already
carries its own order and its own width, so a container would be a second place
deciding the same thing, free to disagree with the first. Naming it from the
field also means an ungrouped field is simply one that names none, which is every
field in every tenant on the day this arrived — so the migration is two nullable
columns, no backfill, and every existing definition is untouched by construction
rather than by care.

The **section itself lives on the module** — `shape_definition.sections`, a JSON
map of key to label and position — because a section has to be able to exist
while it is still empty, and neither its name nor its order can be stored on a
field that is not in it yet. On the row rather than in a table of its own is
`followUpsEnabled`'s argument unchanged: "what this customer has, and how it is
set up" is one question with one answer, and the lifetime comes free — uninstalling
a module takes its headings with it.

**Not on `ShapeDefinition`**, and this is the one place the feature is narrower
than the editor. A collection's fields are drawn as a row inside the form and as
a row of a *table* on the record page, and a table row has nowhere to put a
heading. A section offered on a collection would be a control that did nothing on
half the pages it appeared on, which is precisely the defect XIV-144 is named
after — so the select is drawn on the module's own shape only, and the engine
refuses a section on anything else for the request that arrives without a page.

##### Ordering is a number the customer sets

Not the position of its first field, and the deciding case is the empty one: a
section is empty for exactly as long as it takes somebody to make one and then
move a field into it, and a heading that vanished between those two clicks would
be a control that appeared not to work. So a section carries a `position` exactly
as a field does, in tens, on the same numeric control — type 15 to put something
between. Inferring it would also mean that reordering a *field* silently
reordered a *section*, which is the same accident §5.4 already refused when a
record's name was guessed from field order.

**The ungrouped fields are drawn first**, before any section, under no heading.
That is the decision that costs an existing customer nothing: every field in
every tenant is ungrouped, so a shape with no sections yields one run holding
every field in its own order — the flat run that has always been drawn — and the
first section somebody creates appends a heading *below* what they already had
rather than pushing twenty-two fields down the page. A field naming a section
that no longer exists reads as ungrouped rather than disappearing; nothing here
can produce that state, but an import can, and a control that silently vanishes
takes its value with it on the next save. A section with no fields draws no
heading at all — it is kept in the editor, which is what lets somebody make one
before filling it.

##### The record page groups too, from the same method

**Showing a form in sections and a record as a flat list would be worse than not
grouping at all**, so the record page groups, and the grouping is decided exactly
once — `ModuleDefinition::getFieldGroupsFor()`. Both templates are handed the
answer rather than the ingredients, because two templates reading the same
definitions is the place grouping quietly diverges, and six months later somebody
is looking at a form in four sections beside a record page in one list. The test
asserts the two pages against *each other* rather than against two expectations
that could both be edited to match a bug.

The form keeps one thing the record page does not need: when there are no
sections it renders through `form_widget(form.fields)`, the same call it always
made, unconditionally. "An existing definition draws exactly as it does today" is
a promise, and the way to keep a promise like that is to run the same line of
code rather than a second one believed to produce the same bytes.

##### The controls, and the name is the customer's word

Making, naming, ordering and removing a section is a **page of its own**, for the
third time in this section and for numbering's reason: the field table is a
control per field, and a section is not a fact about a field. What *is* in the
table is one `<select>` per row saying which heading that field is under, which
is instantaneous and reversible — pick one, pick the blank again, nothing
happened — and therefore fits a cell.

The blank option is a real answer and the common one, which makes this the one
control on that row where nonsense is **refused rather than shrugged off**. A
width of 40 and a country that does not exist are read as "no opinion", because
there the honest response to a tampered form is to change nothing; here changing
nothing means saying no, since reading an unknown section key as blank would move
somebody's field and report success.

A section's **name is the customer's word, not a translation key**, and that is
not a new decision — a field's label and a shape's label are the same kind of
thing and are stored strings that go to the page as they are (§8.4.2). The key is
derived from the first name given and never touched again, which is XIV-144's
split for a choice field's options one level up: renaming a section changes what
the page says and moves no field, so renaming is free by construction. The trade
is the same one, and it is the right one: a typo is permanent in a key nobody
ever sees.

**Removing a section removes the heading and nothing else** — §5.4's oldest rule
one level up. The fields keep their values, their order, their widths and their
rules, and are drawn at the top of the form again; the confirmation says so, and
says how many, because a section *looks* like a container and "3 fields come back
to the top" is a different decision from "31 do". The fields are cleared in the
same transaction as the heading rather than left pointing at nothing: a
definition that is merely interpreted correctly is not the same as one that is
right.

Deliberately **not** in this:

- **collapsing a section**, which is a nice thing and a state to keep somewhere —
  per person, or per module, or in the browser — and every one of those answers is
  a decision this ticket did not need to make. Half-building it would mean a
  control that folds and forgets;
- **a module declaring its own sections** in its blueprint, with everything §7.2.1
  would then have to answer about offering one to a customer who has already
  arranged their own form;
- **tabs, wizards and conditional visibility.** The last is genuinely wanted and
  is a different feature: it is a rule about a *record* rather than a fact about a
  form, and XIV-88 has already written down why a rule a customer authors is not
  an expression language.

**Numbering is the one that does not fit in the table** (XIV-27). Its control is a
page of its own, because what a customer is deciding there is a pattern, the
number that pattern will produce and the counter it will come out of — and the
last two have to be shown *as it is typed*, before anything is saved. §5.10 has
the argument, including why the pattern syntax stayed a template rather than
becoming an expression language. Since XIV-91 that page also *starts* numbering,
which stayed off the table until the questions it asks about records had answers
— and is still not a control in this table, because everything else here is
instantaneous and reversible and that one writes numbers into records that
already exist.

**A field can say it names the record.** Something has to decide what a record is
*called* — the heading on its page today, and whatever names it in a link or a
picker once §7.6 arrives. The metadata used to have no answer, so the record page
guessed from the required fields, first two: right for a contact, wrong for an
invoice whose required fields are `status` and `issued_on`, and tied to field
order, so reordering fields in the editor silently renamed every record. It is a
flag now, and the guess survives only as the fallback for anyone who has not
marked one — a wrong heading beats a blank one.

**A field can be on the list, or not.** Without that, every field a customer adds
widens the table until nothing is readable — a strange punishment for using the
engine as intended. It is a UI hint and nothing more: the value is still on the
record, still in the form, still validated and still queryable. A module's own
fields are its designed shape and appear by default; one added later does not
until somebody ticks the box, because an addition should not silently rearrange a
list people read every day. With nothing ticked the list falls back to the first
field, since a table with no columns is not a table.

**Removing a field takes the definition and leaves the values.** This is the
answer to the half of §7.2 that is settled. Deleting the data too would be
irreversible on a click; leaving it means adding a field with the same key brings
it back, so removal is reversible by construction. The confirmation says so
plainly and says how many records still hold a value — somebody clicking Remove
reasonably assumes the data goes with it, and finding out afterwards would be too
late. For a product sold on data protection that also means the opposite promise
has to be available: **purging values is a separate, explicit operation, and it
does not exist yet.** Until it does, "remove" means "hide", and the UI says the
word.

---

### 5.5 Variants: one shape, more than one kind of record

A contact is a person or a company. They share an email, a phone number and an
address; they do not share a first name, and a company cannot satisfy a rule that
says one is required.

**One module, not two.** The deciding argument is not tidiness, it is the
reference: "select a contact anywhere you select a contact" has to work for both,
and with two modules that selection is a **polymorphic** column — an id plus a
type saying which table it points at. That is the shape that cannot carry a
foreign key, and §5.2 already refused it once for exactly that reason. One module
makes every link a plain key into one table. Shared machinery follows for free:
addresses, history, filtering and the record page are declared once.

**A shape names one choice field as the one that decides**, and the variants
*are* that field's options — so adding "Partner" is adding an option, and there is
no second list to disagree with it. A field then names the variants it belongs
to; empty, the default and the common case, means all of them.

    contact.variant_field = kind
    kind      choice: person | company     (all variants)
    first_name                             [person]
    company_name                           [company]
    email, phone, addresses[]              (all variants)

**Where it applies, and where it deliberately does not.** The form asks for one
variant's fields, the validator checks that variant's rules, and the record page
shows what the record actually has. Storage is untouched: a value belonging to
another variant stays in the payload and travels across saves, because it is
somebody's data — the same reason removing a field leaves its values alone
(§7.2). Validation lets those keys through unchecked while still rejecting a key
the shape has never heard of.

**Adding a record asks which kind first.** The fields depend on the answer, so
something has to settle it before the form is drawn. This used to be forced —
switching them as somebody picked would have needed scripting, which the forms
did not depend on — and since §8.3 it is a choice, kept because "new person" or
"new company" is how a CRM usually puts it and a record's kind is a bigger
decision than a row's.

**The list names records rather than showing their fields.** With variants the
only thing every row has is its name (§5.4), so that is the first column and it
sorts across every field a name is built from — ordering people by a field only
companies have was the first thing that went wrong here.

---

### 5.6 Getting data out, and later back in

A module's records as a spreadsheet: a customer's backup, and the way data
arrives from whatever system they were on before.

**One sheet per shape**, mirroring the storage — a contact has many addresses and
they cannot share its row (§5.1), so the child sheet carries `parent_id` and the
file can be read back as the structure it left as.

**Headers are field keys, not labels.** A key is the one thing about a field that
cannot change; the editor refuses to rename one (§5.4). A file exported today
therefore still matches its module after somebody relabels a column. Import will
accept either — lenient in, stable out.

**Values are in storage form**: an ISO date, a choice's stored value rather than
its label, a reference's id rather than the record's name. A file that reads
beautifully and cannot be imported would be the wrong trade.

**An export carries the query the list was showing**, including the children of
exactly those records — a filtered export that quietly included everybody else's
addresses would be worse than no filter at all.

Variants need nothing: every field is a column, `kind` says which apply, the rest
are blank (§5.5). And nothing in the exporter knows what a contact is — the
columns come from the customer's definitions, so a field added in the editor
appears in the file with no code changed.

`openspout`, which is MIT and streams rather than building a workbook in memory.
(PhpSpreadsheet is also MIT since v2 — it was LGPL — but it holds whole documents
in memory, and none of its formulas or charts are wanted here.)

**Import is the other half, and is built.** It parses, matches columns to fields,
validates every row through the existing validator — which is already
variant-aware — and applies the file in one transaction or not at all. Half an
import is a state nobody can reason about. It writes through `RecordWriter` like
everything else, so every imported row gets a history entry attributed to whoever
imported it, for free (§5.2). It needs an upload but no file *storage*: parse and
discard, which sidesteps §7 entirely.

**A check is the import, rolled back.** `check()` and `apply()` run the same
statements on the same connection; one commits and one does not. A dry run down a
separate path would be a dry run of something else, trusted right up until the day
the two disagreed — and it is the only way to catch what only a write can, such as
two rows of one file claiming the same unique email. The second write finds that,
because by then the first one is really there. DBAL 4 nests transactions with
savepoints, so this works underneath a test suite that is itself a transaction.

**Lenient in, stable out.** A header may be a field's key or its label. A field
with no column keeps the value it had — a three-column file corrects three things
rather than blanking everything else — while a column that is present and empty
does clear its field, because that is what deleting a cell means.

**`id` decides create or update.** A numeric id updates that record, and one that
names nothing is refused rather than quietly inserted: a mistyped id would
otherwise duplicate the record it was meant to correct. An empty id creates.
Anything else — `acme-1` — is a name the file made up for a record it is creating,
which is what lets a migration from another system bring children with it, since
the child sheet can point at a parent that does not exist yet.

**A collection sheet speaks for the whole collection.** For every record the file
names, a row the sheet does not list is a row that gets removed — otherwise a
round trip could never delete an address. A collection with *no* sheet is left
alone, because saying nothing is not the same as saying "none". Removal is the one
thing an import destroys, so the check counts it separately and the page says so
in as many words. A collection row naming a parent the file does not contain is
refused: attaching it would mean loading a record the file never mentioned, which
is a two-line file reaching into anything.

**Both halves are tested against each other** — export a module, import the file
back unedited, and nothing may change or double. Either half can be correct on its
own and still disagree about a sheet name, a header or a stored value.

Two things deliberately left: the file is read into memory before anything is
written, because the sheets must be cross-referenced before the first row is saved
and a spreadsheet promises no order — fine for what a customer exports and edits,
and wrong for hundreds of thousands of rows. And importing is admin-only for now.
It is not a more dangerous way to edit a record than the form is, but it is a much
faster one, and §7.5 is where that gets a real answer.

### 5.7 Documents from templates (XIV-4)

A record, on paper: the customer writes a .docx in Word, puts `[markers]` where
the values go, uploads it, and downloads a filled-in copy of it as a .docx or a
PDF from any record it applies to.

**The placeholder list is derived, not documented.** It is the customer's own
field definitions crossed with a handful of markers every template ends up
wanting — the record's number, its dates, today — so a field added this morning
is a marker this afternoon and one removed stops being offered. One class
computes both the reference list and the values that fill it, because a screen
somebody writes their template against and the substitution that happens later
have to agree about every word; two functions computing them separately is a
feature that works until a field is renamed. Values are rendered through the
field type, so a date reads as a date and a price as "CHF 19.90" — the same
`display()` the list already uses.

**Two kinds of marker, and the difference is what they are about.** A record
marker describes the contact being written to, and there is one list per variant
because a person and a company hold different fields. A general marker —
`[today]`, `[tenant.name]`, `[user.name]` — describes the moment, and belongs
under none of the variants; listing them per variant read as something the
contact *has*. General keys are namespaced, because a customer's fields become
markers under their own names and the contact module already ships a
`company_name`. Core declares the general ones it cannot know as an interface and
the application answers with whole markers rather than values, so the next one
needs no change to the engine.

**A template may name a variant** (§5.5): a letter to a person is a different
document from a letter to a company, and one naming no variant is offered
everywhere.

**Uploading and generating are two permissions.** They fall out of §8.4 for free
— the enum crossed with the modules — and they are genuinely different jobs:
whoever designs the invoice is not whoever sends one, and a template decides what
every future document of that kind looks like, which is a larger thing to hand
out than the documents.

**Three libraries, and the licence decided two of them.** This project is MIT and
its dependencies have to be usable on those terms:

- **`anourvalar/office`** fills the .docx. PHPWord is the obvious choice and is
  LGPL-3.0; this one is MIT and does the part that is actually hard, which is
  that Word splits a placeholder somebody typed by hand across several runs in
  the XML, where a naive string replace finds nothing.
- **Gotenberg**, through `sensiolabs/gotenberg-bundle`, makes the PDF. Both MIT.
  It is a container wrapping LibreOffice rather than a library, and that is the
  point: **no pure-PHP PDF library can read a .docx at all.** They render HTML, so
  the pipeline with one would be docx → HTML → PDF, and the header, the footer,
  the page numbering and the fonts of the template are approximations by the end
  of it. LibreOffice is what a person would use to export the document
  themselves. (dompdf is LGPL-2.1, mPDF GPL-2.0, TCPDF LGPL-3.0 — the licences
  rule them out before the fidelity does.)
- Core declares `PdfConverter` and the application answers it with Gotenberg, the
  same seam as `InstanceCurrency` (§5): the engine fills a template and never
  learns that the converter is a service on a network.

**Uploaded templates live in the tenant's own database**, in a bytea column.
These are the first files the system keeps, and the general file-storage question
— the one attachments will ask — is deliberately *not* answered here. Templates
are small and few and unmistakably one customer's, so the isolation §4 already
provides costs nothing extra: no volume, no bucket, no path to get wrong, and
backup, restore and export-on-churn keep working per customer with nothing added.
Attachments are many, large and long-lived, and will want a different answer;
this one is bounded on purpose.

**Choosing is a page, shown as a modal.** A record carries one button, not a
list: fifty templates on a contact would be a column of a hundred buttons. The
button links to a chooser page and Bootstrap opens that same form in a modal —
the modal and the page are one form, which is why the route takes its template
and format as query parameters rather than in the path. That arrangement was
built so the download worked either way (§8.3); what it is worth now is that
there is one route and one form rather than two.

**Word's placeholder text has to be settled before converting.** A letterhead is
mostly content controls — the boxes somebody clicks into — and one nobody has
typed in yet carries `showingPlcHdr`. Word displays that text and prints it;
LibreOffice renders nothing for it, so a document came out complete as a .docx
and missing its whole sender block as a PDF. The generator drops the flag on the
way out, which is all it takes: the words are the control's own content, and
without the flag every reader treats them as ordinary text. The first bug this
feature had, and a fair warning about the class: **Word and LibreOffice agreeing
about a file is not the same as their agreeing about what to draw.**

**A converter that is down is not a broken record.** The .docx is offered beside
the PDF for exactly that case, and the page says so rather than showing a stack
trace.

**A placeholder nothing will fill is now said out loud** (XIV-25). An order
template printed `[contacŧ]` into a finished document instead of the customer's
name — the last character being U+0167, `t` with a stroke, which is AltGr and the
key beside `t` on a Swiss layout and at body-text size is indistinguishable from
the letter it is not. Nothing is called that, so the generator left the words
alone. **That behaviour is right and the silence was not.** Blanking an unknown
marker would swallow the mistake, and the rule above already fills every marker
the engine *knows* with the empty string precisely so that nothing prints its own
brackets by accident. What was missing is that nobody was told: a bracket in a
finished PDF has two readings — "the engine failed to replace it" and "you typed
something else" — which look identical on the page, and the first is where
everybody starts.

**It is a comparison, and both halves already existed.** `TemplateTokens` reads
the `[tokens]` out of the .docx and `DocumentMarkers::keysFor()` says what this
module's vocabulary is — its fields across every variant, the general markers,
and the collection markers including the per-kind forms (§5.11). What the second
does not answer, the first reports. The scan was **extracted rather than
written**: `RepeatingBlocks` was already stripping the markup and reading
brackets out of the resulting text, because Word cuts a placeholder somebody
typed in one go across several runs, and a third private copy of that trick is
how three scanners come to disagree about what a marker is — with the
disagreement surfacing as a report that calls a good template broken, or misses
the one that is. The extraction moved where the `strip_tags` lives and changed
nothing the generator decides.

**It reports and it does not refuse.** Square brackets in a letter are legal
prose, a customer may be half-way through writing a template, and a token nobody
recognises may well be one somebody meant. So the upload is accepted and a second
sentence appears beside it, and **the wording says what will happen to the text
rather than what is wrong with it**: "`[contacŧ]` will be printed just as it is —
there is no placeholder by that name."

**Said beside the template, not only at upload.** The check runs for every
template each time the templates page is drawn, which is what covers the case
upload-time checking cannot: a template written against `[vat_number]` goes stale
the afternoon somebody removes that field, nothing about the file changed, and
the one moment a check on upload would have caught it is long past. The cost is
one unzip per template on a rarely-visited page holding few of them, which is
affordable exactly because templates are small and few for the reasons given
above. The upload also says it in a flash, so the same sentence appears twice on
that one screen — kept on purpose, because the flash is about the upload somebody
just made and the line on the row is about the template from then on, and a
template sorted into the middle of a long list would otherwise say this where
nobody has a reason to look. One translation key writes both.

**The vocabulary, not the record.** A template naming no kind of record and using
`[company_name]` is not reported, even though that marker comes out blank on a
person. It is a real marker of the module; the reason it is empty is the record
in front of it rather than the template. Reporting it would put the upload page
in an argument with the reference list printed beside it, which is a worse
wrongness than the one it would catch.

**Unused markers are deliberately not reported**, and the ticket left that open.
A template that never mentions the record it belongs to may well be a mistake,
but "you did not use `[status]`" belongs on every upload of every template and is
therefore noise nobody reads twice — and a reader who has learned to skip that
line skips the unknown tokens sitting next to it. The two are not the same kind
of fact: an unknown token has a wrong answer behind it, an unused one is a
preference about how somebody's letter reads. If it is ever wanted it wants a
quieter place than this one.

**And no copy button on the reference list**, which the ticket also raised. It
would help only at the moment somebody types a *new* token, and the more common
way a template goes wrong — a field renamed under a template written months ago —
is untouched by it; the report catches both. It would also mean an interactive
control inside `_markers.html.twig`, which the email templates page (§5.13) draws
from the same macro and has no upload to protect. Worth doing on its own ticket
if hand-typed tokens turn out to be a recurring source of this, rather than as a
reflex attached to the one that reports them.

*Repeating blocks, once still to decide, are §5.11 — a template can lay out a
contact's addresses or an invoice's lines, and a table row carrying a collection
marker is what grows.*

#### A marker that draws a picture (XIV-89)

**`[tenant.logo]` is a change to this pipeline rather than a key in a list**,
which is why it was split out of XIV-49 and given a ticket of its own. Everything
above resolves to **text**, and `anourvalar/office` has no image path in it at
all — a driver for the spreadsheet side and nothing equivalent for a Word
drawing — so this is DrawingML written by hand, and the marker's run is
**replaced by an element instead of having its text substituted**, which is the
opposite of the one operation the whole feature is built out of. `DocumentImages`
is the class, and the OPC bookkeeping that costs — the media part, the
relationship, the content type and the extent, each of them per *part* rather
than per document — is written out there. The seam it reads through is a second
method on `DocumentContext`, so core still never learns what a tenant or a logo
is.

**The tolerant pattern moved to `TemplateTokens`**, beside the scan XIV-25 put
there. `RepeatingBlocks` had it privately and the library has its own copy inside
itself; a third would have earned the same paragraph XIV-25 wrote about three
scanners disagreeing about what a marker is. Two callers in this repository now
share one.

**How big: natural size at 96 dpi, scaled down to fit 40 × 20 mm, never scaled
up.** Three decisions rather than one, argued at `DocumentImages::extentOf()`.

**This does not want a second upload**, which the ticket asked about and §8.6 left
open. Fitting rather than stretching already gives a wide wordmark and a square
crest a sensible answer from one file, and §8.6's case for a second field was
about wanting a *different picture* in a different place rather than the same one
at a different size. If 40 × 20 turns out to be wrong for somebody, the next thing
to add is a size on the profile — one number, beside the picture they already
uploaded — and not a second picture, which would be a second thing to keep in step
for the sake of a measurement.

**The format is decided by decoding the bytes, and the list is PNG and JPEG.** The
seam hands over raw bytes and nothing else, so the question is asked of the thing
being embedded rather than answered by a label somebody chose — the same call
`LogoFormat` makes about an upload and the .docx check makes about a template. The
list is §8.6's and the reason it is that list is a licence rather than a
preference: the only credible SVG sanitizer in PHP is GPL-2.0-or-later and this
project is MIT. Both of these are formats Word embeds natively, so nothing is
converted and nothing is re-encoded on the way through. **No dependency was added
for any of this** — `ZipArchive`, `getimagesizefromstring` and `preg_*` are all
core PHP.

**An installation with no logo generates a document with nothing there**, and that
costs no code. The marker stays in the vocabulary whether or not anybody has
uploaded one — a marker that appeared and disappeared with the data behind it
would mean a template written this week naming something the review calls unknown
next week — so `dataFor()` still offers it as the empty string. The image pass
runs *before* the library and simply finds nothing to do, and the ordinary
blanking finishes the job. Blank beats brackets, unchanged. The same ordering is
why the image pass runs *after* `RepeatingBlocks` (§5.11): a mark inside a
repeating row is one marker before expansion and several afterwards, and each copy
needs a drawing id of its own.

**`TemplateReview` needed nothing.** It compares the tokens in the file against
`DocumentMarkers::keysFor()`, which is built from `general()`, which is where the
marker is declared — so the review stopped calling it unfillable by virtue of the
marker existing. That is the payoff for XIV-25's rule that the reference list, the
substitution and the report all read one vocabulary.

**The reference list says which marker draws a picture**, which the ticket raised
and is worth the one word it costs. `[tenant.logo]` beside `[tenant.name]`, under
one heading, in the same brackets, with a label that reads like the name of a file,
is a token that gets pasted into the middle of a sentence — and what comes back is
a picture wedged into a line of prose, which reads as the engine misbehaving. So a
marker carries a *kind* and the row carries a badge. A kind rather than a boolean
because the next one is plausibly a barcode. **The email templates page filters it
out entirely** rather than badging it: an email has no `<w:drawing>`, and what a
picture in one would be — a fetched URL or a CID attachment — is a design question
about email rather than a line missing (§5.13). Until that is answered the marker
comes out blank in an email, and advertising something that comes out blank is
what that page already refuses to do with collection markers.

**Documents are generated without a browser**, and this is the constraint that was
easiest to lose. XIV-49 added a public route serving these bytes and reaching for
it here would have worked in development and failed wherever the application
cannot address itself. `InstanceContext::images()` reads the column, and the test
that proves it generates a document with no request in flight at all.

**The PDF is proved, not assumed.** Gotenberg is what turns this into what the
recipient sees, and this feature has already been bitten once by Word and
LibreOffice agreeing that a file is valid and disagreeing about what to draw
(`showingPlcHdr`, above). So the suite converts both the body case and the
letterhead case and searches the PDF for an image XObject — and generates the same
document with no logo and asserts the PDF has none, because without that half the
test passes on a converter that puts an image in every PDF it makes.

*Emails are §5.13, and are deliberately not this. They are written in the
application rather than uploaded, and the reason is worth reading next to the
paragraphs above rather than assumed from them.*

### 5.8 Lifecycles (XIV-14)

A module may declare the states its records move through and the moves allowed
between them: draft → active → done, cancelled from either.

**On symfony/workflow**, because the framework has this and a hand-rolled state
machine would be a second one to maintain (§5.7's rule, applied again). Two
things had to be adapted, and both are worth knowing:

- **A record is not an entity**, so neither marking store the component ships
  fits — `MethodMarkingStore` wants a getter this class of object cannot have.
  The replacement is nine lines, because **the state lives in an ordinary
  `choice` field the module already declares**. That is the design decision
  underneath: a lifecycle is a *rule over a value*, not a second place the state
  is kept. The state filters, lists, exports and shows up in history for free,
  and there is no second store to disagree with the first.
- **Definitions are built from the blueprint**, not from `framework.workflows`.
  That YAML would have to name every module every customer might install, and
  which modules a customer has is a runtime question (§3). `DefinitionBuilder`
  exists for exactly this.

**Two traps the component sets, both found by tests rather than by reading.**
`StateMachine` is the right class — a record is in one state, where `Workflow` is
a Petri net whose subject holds several places at once. And **a transition with
two `from` places means "from both at once"**, not "from either": "cancel, from
draft or from active" is spelled as two transitions sharing a name, which is what
the builder here does. Neither class name nor signature says any of this.

**Moving a record is its own permission** (§8.4): one grant per module, not per
transition. Sending an invoice is a different authority from correcting a typo in
one, which is why it is not `edit` — but "may confirm and not cancel" would need
the grant table to carry a third thing, so it waits for somebody who actually
needs it.

**A state can end editing.** A finished record loses the edit button and the
route behind it; the button is a courtesy and the URL is the rule. That is the
first time the engine refuses a save on a module's say-so, which narrows §7.1
without answering it: this is a declared rule, not a subscriber's veto.

**The timeline gets its own verb for it.** "Somebody sent this invoice" and
"somebody fixed a typo in it" are different facts about a document, and an audit
trail that called both "updated" would bury the first.

#### A condition on a transition, and why it is not an expression language (XIV-88)

*Written while `LifecycleTransition` still carried no condition. It carries one
now — XIV-110 built it, in the shape this argument concluded with — and the
mechanism is the subsection below. This one is kept as it was, because the
reasoning about what a condition must **not** be is the part that stays true.*

`LifecycleTransition` carries no condition, and there is nowhere else to put one.
"Confirming an order needs at least one line" cannot be said anywhere today:
field validation is per field and unconditional, so it can only demand the line
of a draft as well, which is not the rule anybody means — the contact half of
that sentence is already a `required` field precisely because it *is* true of a
draft — and `RecordWriter` validates nothing, so the save a transition makes is
one that nothing inspects. The hole is real. This section records that it was
looked at deliberately rather than left lying, and that the answer proposed for
it was turned down.

The proposal was Symfony's ExpressionLanguage, raised while XIV-27 was rejecting
it for the numbering pattern. That rejection was narrow and the question
underneath is general: **is there anywhere in Xivi that wants a small, safe,
customer-authored expression?** The answer is **no, today**, and the argument is
written out here so that the next person to suggest it inherits it instead of
re-deriving it. The component is an *evaluator*: it parses PHP-like expressions
over variables that must be declared explicitly, and returns a value. Nothing is
ambiently in scope, and parsed expressions cache through PSR-6. It is MIT and
would be an acceptable dependency; it appears in `composer.lock` today only as an
optional peer of packages we already have, and is not installed.

**Two rules decide most of the candidates, and this project already learned
both.**

*Not where the answer has to become a `WHERE` clause.* §8.4 is explicit that
record-level permission is a query problem rather than a security-layer one — "a
check performed after loading, which is the wrong answer in a way that looks
right" — and §5.3 says the same of filters, where nothing from a user is
concatenated and the comparisons are a closed enum. An expression evaluates in
PHP over a record that has already been loaded, which is the wrong side of the
`LIMIT`: a list page is twenty-five records **and a separately counted total**,
and a predicate that can only run over the twenty-five prints a number of records
somebody may not see directly above the ones they may. **Permissions and filters
are therefore ruled out here, in as many words**, because §8.4's mistake would be
arriving through a new door and the sign was only on the old one.

*Not where the engine has to read the thing rather than run it.* That is XIV-27's
finding and it generalises. `NumberFormat::period()` decides which counter a
number comes out of by looking for `{year}` in the pattern **text**, and the
editor turns that into a promise kept before anything is saved. An evaluator can
only answer by evaluating, and `'ORD-' ~ (annual ? year : '')` has no static
answer at all. A useful third phrasing of the pair: it fits where the output is a
value, and badly where the output is text whose structure the engine inspects.

**The candidates, each with its verdict.**

- **Lifecycle guards — passes both rules, and still not this component's job.** A
  guard is a boolean over one record already in hand, and nothing needs to
  interrogate one without running it: the record page decides whether to draw a
  button by evaluating it against the record it is already showing. It is also
  the only candidate where there is currently *no* way to express the thing at
  all, which is the strongest argument any of them has. What decides it is asking
  who writes one. §7.1's narrowing — the paragraph above, about the engine
  refusing on a rule the module *declared* rather than a subscriber vetoing at
  runtime — is about a rule **a module** declared, and a module is code. Against
  code an expression string is strictly worse than a PHP predicate: PHPStan
  cannot see into it, neither can an IDE, renaming a field key breaks it
  silently, and it buys nothing a typed callable does not already give. This
  component earns its keep only where the author *cannot* ship PHP, which means a
  customer — and a customer cannot author a lifecycle at all. There is nowhere to
  keep one: options live on `FieldDefinition` and a lifecycle is not a field;
  this section says a lifecycle is part of what a module *is*, so changing one is
  a release rather than something a customer configures (§6.1); and XIV-27 set
  the standard for handing a customer a small language, which is a page that
  shows it working rather than a text box validated on submit. So: a guard is
  worth having, and when it arrives it should be a predicate declared beside the
  transition. Whether a customer may author one is a separate decision, and it is
  not one to make by accident inside a ticket about an evaluator.

- **Customer-authored derived values (§5.9) — declined on the transaction.** A
  deriver runs inside the save's transaction, so a customer's own slow or
  throwing expression fails somebody's save, and the failure would arrive at the
  point in the code least able to explain itself. The derivers anybody would want
  to imitate are the money ones, which would put a second implementation of
  §5.9's rounding rule in a text box — the exact duplication XIV-32 refused when
  the alternative was JavaScript. It inherits the storage and editor problem
  above unchanged: a deriver is a service, and services are code.

- **Conditional content in documents and email (§5.7, §5.13) — not blocked by
  either rule, blocked by a question that came first.** A boolean gate around a
  block would leave §5.13's escaping property intact, which is the thing to check
  before anything else: the block's body is still template text, and markers
  inside it are substituted into the Markdown **source** before CommonMark parses
  it, so record values stay escaped by construction. What stopped it was that
  §5.13 deliberately left repeating blocks out because Markdown has no unit to be
  one — a list item, a table row, a fenced block — and a conditional block asks
  the identical question with the same three candidate answers. **§5.13.1 has
  since answered the repeating half, and answered it by refusing to have a block
  at all**: `[lines]` is one marker that renders a table whose shape ships in
  code. That closes the question for collections and reopens nothing for
  conditions, because a condition has no equivalent trick — there is no "the
  whole thing, already laid out" for *maybe show this paragraph*, so a
  conditional still needs a unit to wrap and still has the same three candidates.
  What is settled is that a block arriving for conditions would be the first
  block syntax in an email body rather than the second, which makes it more of a
  decision than it looked like before, not less.

- **Validation rules (§5.4) — ruled out, by rule 1 arriving from a new
  direction.** A rule cannot be switched on if existing records would fail it:
  the editor counts them first and refuses with the number, and since XIV-109 the
  `unique` half names the shared values too. Counting how many records fail an
  arbitrary PHP expression means loading every record in the module and
  evaluating each one — a full read of a customer's table performed in order to
  draw a form, and one that gets slower for exactly the customers who have most
  to lose from the rule being wrong. The `unique` half is worse still: that flag
  now builds a unique expression index on the column, and there is no index for
  an arbitrary expression.

- **Conditional numbering — XIV-27, unchanged.** Rule 2, and nobody has asked.

**One thing that would be found and misread, so it is said here.**
symfony/workflow ships guards of its own, configured as expressions, and they are
the framework's own answer to this — which is normally the end of the argument
here (§5.7's rule). They are dispatched as `workflow.guard` events, and this
section builds its state machines with **no event dispatcher** on purpose,
because the component's events are a second place behaviour could hide. Adopting
its guards means adopting the seam this section refused. A condition, when there
is one, is evaluated by `RecordLifecycle` — which is where the refusal already
lives, and where `TransitionRefused` already carries a message somebody can act
on.

**What would change the answer.** A customer asking to state a rule of their own
about their own records and being told no — a guard, most likely, since that is
the one with no workaround. At that point three things are needed and none of
them exist: somewhere in the tenant's metadata for a per-transition option to
live, an editor page built to XIV-27's standard, and a written decision about
what an expression may see and what happens when one throws. Until somebody is
actually blocked, this is the abstraction §1 says has to be earned, and it has
one hypothetical use case rather than two real ones.

#### The condition itself: a transition that refuses (XIV-110)

The hole the subsection above found on the way is not closed by declining
anything, and this is what closes it. An order with no lines and a total of zero
confirmed cleanly — the button was drawn, the POST went through, and a document
with nothing on it became a confirmed sale. A lifecycle that can only refuse the
moves the **graph** forbids and never the moves the **record** forbids is doing
half of what this section claims it does, and §7.1's narrowing rests on the other
half: *"the engine refusing on a rule the module declared, not a subscriber
vetoing at runtime"*.

**`TransitionGuard`, declared beside the transition it is about.** One method,
`refusal()`, returning null when the move may be taken and otherwise a
translation key saying why not. It is not a service and is constructed inline in
the blueprint like `LineTotals` and `NumberFormat`, which is what keeps the
condition in the same place as the declaration it conditions — a tagged service
would have put the pairing somewhere other than the thing being paired. One guard
per transition rather than a list: "may this move be taken now" is one question,
and a module wanting two conditions writes one guard that asks both, which is
also the only way it gets to choose which of the two sentences comes back. A list
would need a rule for whose message wins, invented by the engine on behalf of a
module that knows better.

**The button and the enforcement are the same predicate asked twice, and both
have to exist.** A transition offered and then refused is worse than one not
offered, so the record page does not draw a button it knows would fail. But
hiding a button is not enforcement — a retyped POST is not a button — so
`RecordLifecycle::apply()` asks the same guard again, against the record as it is
at that moment, and *that* answer is the one that decides. Both come out of a
single method, `offeredFor()`, so there is no second evaluation to disagree with
the first; `enabledFor()` is a filter over its result rather than a second walk.
The order is deliberate: the state machine answers first and the guard second, so
a move the record's state already forbids costs nothing to not offer.

**A refused move is shown with its reason rather than silently dropped.** This
is the part that is not obvious. Hiding the button is right, but a refusal
nobody reads is a sentence written for a request only somebody retyping a URL
will ever make — so the page prints the module's reason where the button would
have been. That is the same three-state shape the send card beside it already
had for a recipient it cannot resolve (§5.14): a button, a reason instead of one,
or nothing at all.

**The message is the module's, in the module's own catalogue.** The guard hands
back a key and the engine puts it together with the module's key as the domain,
which is the same catalogue and the same domain the transition's *label* is read
from — so the button and the explanation for its absence are written in one file,
by one person, in one voice. "Cannot confirm" is something the engine could have
said on its own and is of no use to anybody; *"an order needs at least one line
before it can be confirmed"* is the message, and only the module can write it.
`TransitionRefused` gained a third constructor for it, and the third is a
different kind from the other two: "not a step this record has" and "not from
where it is" are facts about the lifecycle that the engine can phrase itself.

**What the predicate is handed, and what that costs.** A `GuardedRecord`: the
record, and a way to reach its rows. The rows are the reason the class exists —
"at least one line" is a question about a collection, and a collection is not in
the record's `data` — and handing a guard a bare record would mean every module's
guard knowing about the metadata and record repositories. They are **lazy**, so a
guard that reads only a header field makes no query at all, and **memoised**, so
a lifecycle with three guarded moves reads a collection once between them. One
object per ask, one query per collection asked about.

The cost question is the one §5.1 and XIV-54 both point at, and the answer is
established rather than assumed: **a list page never asks a lifecycle anything.**
Only the record page does, about the one record it is showing. So the whole bill
for a guard that reads rows is one query, on a page that is already loading those
same rows in order to draw them. The record page could therefore have handed the
rows over instead of paying for them again, and deliberately does not: a second
way in is a second thing to keep true, and a guard that behaves differently
depending on whether its caller remembered to prime it is a bug waiting for a
quiet afternoon. If a list page ever wants transition buttons per row, that is the
point at which this has to change — the rows would need priming for the page the
way `RecordPrimer` primes references, and a predicate evaluated inside a `LIMIT`
is on the wrong side of §8.4's line in any case.

**`RecordWriter` did not change, and that is the boundary.** It still validates
nothing, so the save a transition makes is still inspected by nobody. Whether the
engine may refuse a *save* on a module's say-so is XIV-73's question and a much
larger one — it reaches the form, the importer, the demo generator and every
caller holding the service — where refusing a *transition* touches one route with
one button on it. A guard is a condition on a move, not on a write, and the two
are not the same mechanism wearing different hats: a record may be saved in a
state a guard would refuse to move it out of, and that is correct, because saving
a half-finished draft is the ordinary thing to do with one.

**The one place that had to learn a new answer** is demo data. The generator
walks a record to its sampled destination one legal move at a time (§5.17), and
one demo order in seven is generated with no lines — which the order module's own
guard now refuses to confirm. It stops and leaves the record where it is, which
is the answer it already gave for a destination with no path to it. Writing the
state anyway would put records in a demo tenant that no person using the
application could have produced, which is exactly what XIV-73 spent a ticket
undoing.

**The rule that decides where a guard may go**, learned immediately and worth
stating: not on the only way out. The order's guard is on `confirm` and
emphatically not on `cancel`, because an empty order is precisely the kind
somebody wants to be rid of, and a guard that traps a record in a state it cannot
leave is worse than the bug it was fixing. It is also not on `deliver`, which is
unreachable without confirming first and would only be a second copy of the same
rule.

**And what the guard deliberately does not say.** The survey named "a total of
zero" alongside "no lines", and only one of them is a mistake. An order can
legitimately come to nothing — a goodwill replacement, a line discounted in full,
a sample priced at zero — and refusing those would be the engine having an opinion
about somebody else's pricing. An order with *no lines at all* cannot be any of
those things, because there is nothing on it to have been priced.

---

### 5.9 Derived values, and the money that needed them (XIV-16)

A module may work values out while a record is being saved. `ValueDeriver` is
handed the record's fields **and its rows**, before anything is written and
inside the save's transaction; whatever it puts there is what lands in the table,
what the history entry describes, and what the next reader sees.

**This answers the non-veto half of §7.1.** A module may take part in a save, and
what it may do there is *derive*. It may not cancel, and there is deliberately
nothing to cancel with — no return value, no stoppable event, no flag. A save
that fails for a reason the page cannot name is the failure mode that question
was written about; a save that produces more than was typed is not.

**Rows as well as fields**, because the interesting derived values need both: an
order's total is a fact about its lines, and a subtotal line is a fact about the
lines *above* it. Rows arrive in the order they will be stored in (XIV-21), which
is what makes "the lines since the previous subtotal" computable at all.

**A collection missing from the derivation is one the save is not touching**, and
that distinction is load-bearing rather than pedantic: an empty list means "no
rows", which deletes what is there. A lifecycle transition writes the header
alone, and without the distinction confirming an order would zero its totals.

**A collection nobody can type into is derived**, read off its fields rather than
stored as a flag — the same trick §5.5 plays with the variant field. Such a
collection is off the form, out of the import and export, and out of the history:
its rows restate what other rows already say, and the change that moved them is
in the same entry anyway.

#### What the money model decided

The order module is the first thing to use this, and four decisions came with it.
They belong here rather than in that package because the invoice module has to
make the same ones.

- **Totals are stored, not computed when read.** "Orders over 5000" has to be a
  `WHERE` clause, and what a confirmed order came to is a fact about that day
  rather than the result of running today's code over yesterday's lines.
- **VAT is per line, not per document.** A document mixing 8.1% and 2.6% is an
  ordinary week in Switzerland. The article carries the rate, the line copies it
  like the price (§5.1's inherited values), and the per-rate breakdown is stored
  as a derived collection — so it cannot disagree with the tax total beside it.
  The rate on the article is **a number, not a choice of the three Swiss ones**:
  those are this year's rates and this is not a Swiss engine. Empty means no VAT,
  which is the right answer for a customer who is not registered for it, and such
  a customer sees no VAT table at all rather than one full of zeroes.
- **Rounding has one answer**, written down in `Money\Amount` and nowhere else:
  a line total is rounded to two places as it is computed, and **VAT is grouped
  per rate before it is rounded**. Rounding to five rappen is deliberately
  absent: that is a rule about paying cash, not about what an invoice says.
- **A discount is a line with a negative price**, not a percentage on the header.
  A discount reduces the VAT base it was given against, and only a line can say
  which rate that was — a header field would be guessing on any document with two
  rates.

**The same arithmetic runs twice, and only once exists** (XIV-32). The form shows
these figures while somebody is typing, before anything is saved, and it gets
them by running the *same derivers* over values that are not going to be stored —
`DerivedValues` is that call, extracted from the writer when the second caller
appeared. The alternative was recomputing in the browser, which would be a second
implementation of the rounding rule above; the two would agree until they did
not, and the place they disagreed would be a rappen on an invoice, shown to the
person deciding whether to send it. What differs between the callers is what they
do afterwards, which is what should differ.

Nothing about that preview is validated. Somebody who has typed `2.` is
mid-number, not wrong, and the shape validation belongs to the save.

**A line contributes if it has a price, not if it is the right kind.** Comment
lines and subtotal lines fall out of the summing for having no quantity and no
unit price, which is a fact about the line rather than a branch about its kind —
so a fifth kind of line needs no arithmetic written for it. A subtotal is the one
thing asked about by kind, because a subtotal is defined by being one.

**The first decision above is not only about money.** §5.16 applies the same
argument to a date: an invoice's due date is derived and then stored, because
payment terms that change must not restate a deadline somebody was already given.

#### A price that already has the VAT in it (XIV-116)

The four decisions above all assume the price typed is a **net** price and the
tax goes on top. That is one of the two ways prices are quoted, and for a large
part of this product's market it is the wrong one. A shop in Zurich, Vienna or
Munich prices a lamp at 19.95 *including* 8.1%, because that is the number on
the shelf and, for anything sold to a consumer, the number the law says has to be
shown.

Until this ticket such a shop could not enter that number. They had to divide by
1.081 themselves, type 18.46, and hope the arithmetic came back — and at 19.95 it
does not: 18.46 plus 8.1% of 18.46 is **19.96**. A rappen above the shelf price,
on the customer's own document, and nobody in the building can explain it. That
rappen is not a rounding bug to be tightened away; it is what happens when a
number is derived from a derived number, and the only fix is to stop deriving the
one the customer typed.

**The mode is a value on the document, defaulted from the tenant.** Three shapes
were on the table and the argument is the one §5.16 already made about a date:

- **Per line** is wrong, and it is worth saying why rather than assuming it. Every
  *other* money decision here is per line, including the rate — a document mixing
  8.1% and 2.6% is an ordinary week. But a rate genuinely differs line by line,
  and how to read a price does not: a document with some lines quoted gross and
  some quoted net has a price column whose meaning changes halfway down it, and no
  recipient can check such a column at all.
- **Per tenant alone** is where the answer *comes from* — a shop is a shop, a
  consultancy is a consultancy, and it is what makes an article's `price` field
  unambiguous, since the catalogue is priced in whatever the installation says.
  What it cannot be is the thing the arithmetic reads. The day somebody changes
  the setting, every draft in the building would silently reprice, and every
  document ever saved would recompute differently the next time anybody touched
  it. That is the exact failure §5.9's first decision and §5.16's whole argument
  exist to prevent: **what was agreed is a fact about that document.**
- **Per document, materialised from the tenant's setting when the document is
  created** — which is both. The setting seeds the field once, on a blank form;
  the field is what `DerivesTotals` reads from then on; and a business that does
  both is covered by the one document that differs. The chain is the one
  [XIV-50], [XIV-67] and [XIV-83] already walk (`ProfileVatMode` implements
  `DefaultVatMode`, beside `ProfileCurrency` and `ProfilePaymentTerms`), and
  deliberately not a fourth variation of it.

**Null at the top, for the third time on that row.** An installation nobody has
asked writes nothing onto a new document, which is *not* the same as answering
"excluded" even though both produce a net-priced document: only the first leaves
an existing customer's records shaped exactly as they were. It is the same call
§8.6 makes about the currency and §5.16 about the payment term, and it lands in
the safe direction.

**An invoice takes the mode from the order it was seeded from**, not from the
settings page it happens to be saved on. §5.12's rule again: an invoice quotes
what was agreed on the day, and a price column that changed meaning because
somebody edited a setting afterwards would be the one figure on a sent document
that kept moving.

##### The arithmetic, and where the remainder lands

**Inclusive is not a second deriver.** It is `DerivesTotals` — still the only
thing computing any of this ([XIV-73]) — running the same loop the other way.
Everything before the last three lines is shared: a line total is quantity times
price rounded to two places, a comment line contributes nothing, a subtotal
restates the block above it, and the VAT table has one row per rate with a rate of
nothing getting no row. What the mode changes is which total the lines gave you:

| | exclusive | inclusive |
| --- | --- | --- |
| the lines sum to | the **net** total | the **gross** total |
| per rate | tax = `net × rate`, rounded once | net = `gross ÷ (1 + rate)`, rounded once |
| | | tax = **`gross − net`**, the remainder |
| the other total | gross = net + tax | net = gross − tax |

**The gross the customer typed is the gross that prints**, and the whole design
is in service of that one sentence. Deriving a net and then re-deriving a gross
from it is precisely the mistake that produces the rappen, so the gross is never
recomputed: it is the sum of a line-total column that was already rounded, and
the tax is whatever is left of it once the net has come out.

**So the remainder lands on the tax, and the rule generalises: the figure
somebody typed is exact, and the derived figure absorbs what is left over.**
[XIV-104] is deciding the same question for discounts and this is the answer to
agree with.

**There is no remainder to place *across* rates**, which is the other half of the
question and it turns out to have been answered in 2026 by the third decision
above. VAT is grouped per rate before it is rounded, so each rate's gross is split
into a net and a tax that add back to exactly that rate's gross; a document at
8.1% and 2.6% is two exact splits summed, and neither of them ever produces a
leftover rappen for the other to absorb. Nothing had to be decided about which
rate wins, because no rate ever loses.

`Amount` gained one operation for this, `withoutPercent()`, and it is the first
one on that class that rounds inside itself. That is not an inconsistency: every
other operation there is exact and can honestly defer the decision, and division
cannot — 19.95 ÷ 1.081 goes on forever. brick/math says the same thing in its
signature, since `dividedBy()` demands a scale and a rounding mode and throws
without them, so what happens here is the framework's own operation with §5.9's
rule applied to it rather than a division helper invented for the occasion.

##### What did not move

**No stored total in any existing record can read differently.** Totals are
stored rather than recomputed on read, so a record nobody saves is untouched by
construction; the migration adds one nullable column to `tenant_profile` and
writes into no document at all; and a record that *is* re-saved derives the same
figures, because an empty `vat_mode` reads as excluded and the tenant's setting is
never consulted while deriving. `VatMode::of()` is the single place that mapping
lives, and it maps *every* way of saying nothing — a null, an empty string, a key
that is not in the values at all, a value nobody could have meant — onto excluded
rather than throwing, because this runs inside a save's transaction.

**Existing tenants take the field deliberately**, through §7.2.1's offer, exactly
as [XIV-118] did with the unit. A customer who never takes it has a shape
identical to the one they had before this ticket and derives identically; a
customer who takes it and answers nothing is in the same position with a blank
field.

**Two values and not three.** "No VAT" was already representable and always has
been — it is a *rate* of nothing, which the third decision above settled. A third
mode here would be a second way to say something the rate already says, free to
disagree with it.

**What the recipient reads.** The mode is an ordinary header field, so it is an
ordinary document marker (§5.7) and appears in the reference list beside
`[gross_total]` with nothing added. Its shipped labels are therefore **whole
sentences** — "Prices include VAT", not "included" — because a template prints
`[vat_mode]` and gets the *option's* label with no field name beside it, and a
recipient reading one word next to a totals block is being asked to work out
included in what. Like every label it becomes the customer's on install (§6.1),
which is the point: "exkl. MWST" against "zzgl. MwSt." is house style as much as
translation. A template written before this ticket is unchanged and prints
nothing new, which is correct, because every document it can print is net-priced;
a shop that switches adds one marker.

---

### 5.10 Document numbers (XIV-15, XIV-27)

A field may be numbered from a sequence: `ORD-2026-0001`, `INV-2026-0001`. Two
things can go wrong with a document number and both are fatal — one that changes
after somebody has read it down the phone, and two documents carrying the same
one — so the mechanism is small and the decisions are written down.

**Declared as an option, not as a field type.**
`NumberFormat::from('ORD-{year}-{number:4}')` spreads into any text field's
options, the way inherited values do (§5.1), so it
is per customer and changeable without a deployment — **and, since XIV-27,
changeable by the customer**, on a page of their own in the metadata editor. For
two releases this section claimed that and it was false: the mechanism was
theirs, the control was missing, and every Xivi customer's orders were called
`ORD-` whether they sold orders or Aufträge.

**One pattern instead of three settings.** Prefix, padding and "resets each year"
were never independent, so **the pattern decides the period** — a number
containing `{year}` resets each year, one without it does not — and the third
setting cannot be set wrongly because it cannot be set at all. The width earns
its keep twice: it is what makes sorting the text sort the numbers.

**The counter is a table, and allocation is one statement.**
`INSERT ... ON CONFLICT DO UPDATE ... RETURNING` against a unique index on
(shape, field, period), which closes the read-then-increment race — the one bug
here that cannot be cleaned up afterwards, because both documents may already
have been sent. A Postgres `SEQUENCE` was the other candidate and loses on both
counts: it cannot restart each year without an `ALTER` that two January
transactions race through, and `nextval` survives a rollback.

**Allocated inside the save's transaction**, through the §5.9 seam — the first
thing to use it that is not a module, which is the useful confirmation that the
engine needed exactly what a module needed. A save that fails gives its number
back.

**Gaps, decided.** The number is assigned on the **first save**, not when a
document is issued: it is what the record is *called* in lists and links (§5.4),
and a draft with nothing to be called by is a worse problem than a gap. A record
that is created and later deleted **keeps its number** and the sequence moves on.
Records are soft-deleted (§5), so that is a hole in a list rather than a hole in
the books — the document behind the missing number is still there to be looked
at, which is exactly what somebody asking about it wants.

**The year is the year the number is allocated in**, never a date on the record.
Otherwise backdating an order to December reaches into last year's numbering,
which is a book that is closed.

#### The customer's own numbering (XIV-27)

**A page, not a cell in the field table, and it shows the number it will
produce.** `ORD-{year}-{number:4}` is a small language and every one of its
failure modes is quiet: a pattern with no `{number}` numbers nothing — the field
simply goes on being an ordinary text field — and a width too narrow stops
sorting correctly once the counter passes it, on a list somebody reads every day.
None of that can be explained by validating a text box on submit. What answers all
of it is rendering the next number *from the pattern as typed*, which turns a
syntax somebody has to learn into something they watch working. That is the whole
justification for a Live Component (§8.3) here rather than another column beside
the width and the search-box setting.

**The syntax stays a template, and that decision is now load-bearing.** Symfony's
ExpressionLanguage was proposed for it and rejected; the argument in full is on
XIV-27 and the short version is that `NumberFormat` reads the pattern **without
running it** — `{number}` decides whether the field is numbered at all, `{year}`
decides *which counter* the number comes from — and this page turns both into
promises kept before anything is saved. An evaluator can only answer by
evaluating, and `'ORD-' ~ (annual ? year : '')` has no static answer at all: an
expression language is precisely the tool that makes static derivation
impossible, and static derivation is what the numbering rests on. It would also
have inverted the ergonomics — the pattern is 95% literal text with two holes in
it — and answered none of the things this ticket was actually about.

**Refused rather than silently inert.** A pattern with no counter in it means one
thing to a blueprint and another to a form. To a blueprint it means "this field
is not numbered", which is right and should stay silent. To somebody who has just
typed it into the editor it would be silence in place of an answer, and they
would find out at their first blank invoice — so `MetadataEditor` refuses it,
**on the write path** rather than in the controller, which is where an import or
a console command meets the same rule.

**Which counter the next number comes from is said out loud, before saving.**
This is the part nobody guesses. Switching from `ORD-{number:4}` to
`ORD-{year}-{number:4}` does not reset anything: it starts drawing from a
different counter, one that has always existed and has never been used, so the
next order is `ORD-2026-0001` after `ORD-0087`. Defensible, surprising, and
therefore a sentence on the page rather than a footnote in a changelog. Nothing
is renumbered by any of this, and the page says that too — the numbers already
given out are on documents customers are holding, and the metadata editor cannot
reach them.

**The counter's next value is settable, and that is the one control here that can
produce a duplicate.** It earns its place because without it numbering can only
be adopted by a business on its first day of trading: somebody migrating from
another system arrives mid-sequence and their next invoice has to be 1043. So it
exists, and **it only moves forward**. The guard is one statement —
`ON CONFLICT DO UPDATE ... WHERE next_value <= :next` — for the same reason
allocation is: reading the counter in PHP and writing it back is the
read-then-write race this whole feature was designed around, and it would lose in
the way that matters, by consuming a number between the check and the write. No
rows come back when the condition fails, which is how the caller learns it was
refused. The page warns before the refusal happens; the refusal does not depend
on the page.

**Which types may be numbered is declared, not asked with `instanceof`.** A
`text` field can carry a document number and nothing else can: `ORD-2026-0001` is
a string in every part of itself, including the leading zeros that make it sort,
and an `integer` would store 1 and print 1. So `TextFieldType` implements
`Numbers`, XIV-36's `Autocompletes` stays as it is, and the editor holds **one
declared list of option to capability** — which is the shape §5.4 has been
describing since it was written, arrived at from two examples rather than
invented from one.

#### Making a field numbered, and stopping (XIV-91)

For two releases the numbering page appeared only on a field that was numbered
already, and the reason was never squeamishness about scope: turning numbering
*on* is a question about **records**, not about definitions, and it had three
answers a ticket about patterns could only have guessed at. Here they are,
answered.

**The rows that have no number: a backfill, in creation order, once.** This is
the decision, and it is §5.10's own rule rather than a preference.
`AssignsNumbers` fills an empty field on *any* save, which is what makes
"assigned once and never changes" work for a record that has one. Left alone,
switching a populated field to numbered would hand out numbers **in the order
somebody happens to open the records** — the oldest contact becomes 0001 by being
edited on a Tuesday, and a number that is supposed to record when a document was
made records when it was last touched. So the rows with nothing in them are
numbered on the spot, oldest first, in one transaction.

The alternative was numbering **only on creation**, and it loses twice. It leaves
every existing record permanently blank in a field the module may be using as the
record's title (§5.4) — a list ordered by the thing the record is called, with
three hundred blanks at the top of it — and it is not even a change to this
feature: "only on creation" means altering how `AssignsNumbers` behaves for every
already-numbered field in every tenant, to fix a case none of them are in. A
ticket about turning numbering on for one field is the wrong place to change what
happens to every field that already has it.

The backfill is irreversible and is therefore **stated before it happens**, on a
confirmation page in §4.1's tone: it names the pattern, how many records will be
written to, what the first and last of them will be called, and that it cannot be
undone; the tick arrives unticked and the controller requires it, because a
`required` attribute is a courtesy to somebody using the page and nothing at all
to a form posted around it. It writes the one column and deliberately does **not**
go through `RecordWriter`: one administrative act is not several hundred edits,
and putting it through the record writer would bump `updated_at` to today on the
whole table — stamping every document as changed today in the act of giving it a
number is precisely the confusion this section is trying to prevent. What
replaces the history entry is the confirmation, which says it once beforehand
rather than three hundred times afterwards.

**The values somebody already typed: the column is read, and the guard is not
touched.** A text field being made numbered may hold `RE-2026-0007` that a person
typed, and a counter starting at 1 knows nothing about it — the guard above reads
the counter and the collision is in the column. So `NumberFormat::render()` is run
**backwards**: a value is one of ours when the pattern's literals line up exactly
and the holes are digits, which makes recognition and production the same rule
read in two directions and unable to drift apart. The counter is then floored
above the highest recognised value, through a statement that takes `GREATEST` and
therefore has no failure mode at all.

Everything the pattern could not have rendered — `Referenz 12`, last year's
numbers under a `{year}` pattern — is left exactly where it is, and that is an
answer rather than an omission: a number this counter hands out can never come out
looking like `Referenz 12`, so it cannot be duplicated by it. The same check
guards the wind-forward control, *beside* XIV-27's in-statement refusal and never
in place of it: a column scan is a read and can be raced, `ON CONFLICT … WHERE`
cannot be, so the scan narrows and the statement guarantees. Dropping either
leaves a duplicate nobody catches.

**A numbered field becomes `derived`, and that is what closes it going forward.**
Otherwise a person could type a number the counter is about to give out, at any
moment, next to a counter with no way of hearing about it — the duplicate the
column scan just closed, reopened one save later and permanently. So the two move
together: numbering is not a setting that can be on while the field is still an
ordinary text box. The same rule decides what may be numbered — a `text` field on
a module's own shape that **nothing else already fills in**, because an order's
total and an invoice's due date belong to a deriver and two derivers with an
opinion about one column is a race decided by declaration order. System-ness is
deliberately *not* a bar: a module's own text field is still the customer's data
in the customer's copy of the module (§6.1), and §5.4's rule is about *removing* a
module's field, which orphans values, where this creates none.

**Turning it off is a page, not an emptied text box.** Un-numbering leaves every
record carrying a number nothing maintains, so it says so: the numbers stay,
because they are on documents customers are holding and nothing in the metadata
editor may reach them; the field becomes an ordinary text box anybody may type in;
and **the counter is kept**, which is the decision worth reading — deleting the
row would be tidier and would mean that switching numbering back on next month
started at 1 and walked straight back through numbers already printed. A counter
nobody draws from costs one row. An emptied pattern is still refused rather than
read as "off" (`MetadataEditor`), because blanking a text box is not that
conversation and reading it as one would make the most consequential change here
the one that takes the least typing.

**Where the control lives, and why not in the field table.** On the numbering page
XIV-27 built, reached from a link that now appears on a plain text field too. The
field table is a row per field and a control per column, and every one of those
controls is instantaneous and reversible: tick "listed", untick it, nothing
happened. This one writes numbers into records that already exist. A checkbox in
that row would make the most consequential change on the page look like the
cheapest one.

#### A numbered field is a unique field (XIV-109)

For one release this section ended with a window, written down rather than
papered over: the column scan runs inside the transaction that turns numbering
on, the field is not `derived` until that transaction commits, and a record saved
on another connection in those milliseconds could slip a hand-typed value in
beside the counter's freshly-applied floor. Administrator-only, small, and a
window. The honest fix named there was a unique index on the column, in §7.2's
territory rather than XIV-91's.

**§7.2 built it, and it lands here as a statement rather than as a lock.** This
section opens by saying that two documents carrying the same number is one of the
two fatal failures of this feature. Everything above keeps that promise with
arithmetic — a counter that moves in one statement, only forward, and a scan of
what somebody typed before it existed — and arithmetic is not a constraint: it is
complete about the numbers the counter gave out and blind to every other way a
string can reach that column. So the definition now says what the feature has
always meant. **Turning numbering on marks the field `unique` beside `derived`**,
which builds the index §7.2 describes, and the shipped order and invoice
blueprints declare it too.

Three things follow, and the third is the one that closes the window.

**The promise stops depending on the engine being the only writer.** `derived`
means nothing but the engine fills the field, and that was the whole guarantee; a
row arriving by any other route — an import that predates the flag, a restore, a
column somebody edited — could carry a number the counter would later hand out
again. Now it cannot be written at all.

**It can refuse, and that is right.** A column that already holds the same
reference on two records cannot be made to promise unique numbers, so turning
numbering on is refused there, with the values named (§7.2). The confirmation page
says so before anybody agrees to anything.

**The window is gone rather than narrowed.** `CREATE UNIQUE INDEX` takes a `SHARE`
lock on the table and holds it until the transaction commits, and that lock
conflicts with every insert and update. Marking the field unique is therefore the
*first* step of turning numbering on, and from that line onward no other
connection can write a row of the module at all: the scan that follows is not a
read that can be raced but a read of a table nobody may change, and the floor it
computes is true when it is applied. Neither XIV-91's floor nor XIV-27's
in-statement counter guard is weakened or removed — they answer different
questions and a lock that stops being taken for some future reason must not take a
counter with it.

**Turning numbering off leaves the field unique**, which is the decision worth
reading twice. Un-numbering makes the field an ordinary text box anybody may type
in, and that is exactly the moment the index earns its keep: the numbers on those
records are on documents customers are holding, and the first thing a text box
invites is somebody typing one of them a second time. Relaxing the promise as a
side effect of a change about something else would be the opposite of what the
rest of this section does. A customer who really means it unticks the box
themselves.

---

### 5.11 Repeating blocks in templates (XIV-17)

An invoice whose template cannot list its lines is not an invoice. §5.7 left this
open and it is now closed: **a table row containing a collection marker draws
itself once per row of that collection.**

`anourvalar/office` does not do it — its repeating rows are a spreadsheet
feature — so the document is preprocessed before the library ever sees it:
**the rows are multiplied first and substituted second**, and the library still
only ever substitutes markers.

**No syntax to open and close a block.** Writing `[lines.description]` in a cell
is what makes that row repeat, and the `<w:tr>` is the unit because it is the
unit Word gives a person.

**How much the template cares about kinds is the template's business**, which is
the decision the ticket asked for. The rejected alternative was for the engine to
hand each row a pre-formatted set of markers so that one block fits all — less to
lay out, and it would have meant the engine choosing how somebody's invoice
looks, which is the one thing a template is for.

**Consecutive blocks for one collection are a group**, replaced as a whole by the
rows in the order the collection holds them (XIV-21).

**An email does none of this, and §5.13.1 is where that is argued.** There a
collection is *one* marker — `[lines]` — rendering a whole table whose shape
ships in code, which is the opposite of everything above. The two are meant to
disagree: this section's whole argument is that a template exists to decide how
somebody's invoice looks and the engine must not take that away, and an email has
no layout worth designing (§5.13) — so in an email there is nothing to take. Read
side by side without that sentence, the difference looks like an oversight.

---

### 5.12 One record made from another (XIV-19)

An invoice is made from an order, a delivery note from an order, an order from a
quotation. It is the commonest thing an ERP does and it is always the same thing:
copy a header, copy the lines, keep a link back, and never take the same line
twice. So it is **declared** — `Seed` names the source module, the field holding
the link, and the fields and rows to bring along — rather than written once per
pair of modules, which would be a class per pair.

**Copied, never read through.** The new record holds its own values from the
moment it exists. That is what lets an invoice stay correct after the order is
edited, and what lets a second invoice hold different lines from the first. Once
issued, an invoice is a document and stops following anything. The link is kept
beside the copy so reporting still knows where it came from — the same shape as
an order line's article (§5.1), one level up.

**Seeding is not saving.** What comes back is a *form*, filled in, that somebody
reads and changes before pressing save. A document that appeared fully formed the
moment a button was pressed is a document nobody checked. It is also why the
seeded page is the ordinary new-record form: a seeded form and an edited one are
the same page, which is what makes the seeded one editable at all.

**What is left is read, not stored.** A "quantity invoiced" column on the order
line, kept in step by whoever writes an invoice, is a second record of a fact the
invoices already hold, and the two disagree the first time somebody deletes one.
So each seeded row records **which row it came from**, and what a source row has
left is its quantity minus what every document made from it took. A row with
nothing left is not offered again; the order's own page shows what is still
outstanding on the line rather than in a total nobody can check against a line.

That row reference is a plain number rather than a `reference` field, because a
reference points at a *record* and a collection row is not one — it has no page
and no life of its own (§5.1). What it is for is arithmetic, not a link somebody
follows.

**Through the reader's own permissions.** Working out what is left means reading
the other module's records, and being allowed to open an order is not being
allowed to read the invoices made from it (§8.4). Somebody without that grant is
told the order is wholly uninvoiced — the safe direction to be wrong in, and they
cannot make an invoice either way.

**A sent document is not edited; it is corrected by another document.** An
invoice that has gone out loses both the transition back to draft and the right
to be changed, because the customer is holding a copy and the two would disagree.
Correcting one is a credit note — a second document that says what it corrects —
which is also the only version of the fix an auditor can follow. This is the
lifecycle rule (§5.8) doing the work rather than a special case: a state that
ends editing already existed, and *sent* is one.

**Two things are deliberately not copied**: a line's total and a subtotal's
figure. Both are derived on save (§5.9), so a partial invoice restates its own
subtotals instead of repeating the order's — which on an invoice for half the
lines would be the most convincing wrong number in the system.

*What this cost the engine is the measure of the six tickets before it.* The
invoice module is a declaration and a translation file: no controller, no entity,
no form, no class the engine calls. The one thing it did cost was moving the
order module's totals into core behind a `LineTotals` declaration — two modules
needing identical sums is the engine's problem rather than theirs, and the
alternative was the same hundred lines twice, drifting apart the first time
somebody fixed a rounding bug in one of them.

---

### 5.13 Email templates, written here rather than uploaded (XIV-38)

The counterpart to §5.7, and the ticket that says why the two are not the same
shape. A document template is a .docx because a letter's layout is somebody's
design work and Word is where that work happens. An email has no layout worth
designing — it is text — so asking somebody to edit it in Word, upload it, and
upload it again to fix a typo would be ceremony bought with nothing. An email
template is therefore **a form in this application**: a name, a subject and a
body in **Markdown**, kept as text in the customer's own database rather than as
the blob a .docx needs.

**The base template ships in code and a tenant cannot edit it.** A customer
writes the content part; the wrapper — the HTML skeleton, the footer, the sender
block — is ours. That is §6.1's existing rule rather than a new one: presets live
in code, templates live in data. Somebody who could edit the wrapper could break
every email they send, and the wrapper is not the thing they wanted to change.

**There is one of it, not a named set**, and this was decided rather than
assumed. A second base template only earns its place when two emails need
different frames, and nothing needs that yet: what varies between a reminder and
an order confirmation is the words, which is exactly the part a tenant already
writes. A set would also have to be chosen from — a column on `email_template`
whose only value would be "the default", plus a picker beside the fields somebody
actually came to fill in. When a real second frame turns up it brings its own
requirements with it, and adding a column then is a smaller thing than guessing
at one now.

**The markers are `DocumentMarkers`, not a second implementation.** The same
class, the same keys, the same values rendered through the same field types
(§5.7), including the general ones the application answers through
`DocumentContext`. So a field added this morning is a marker in an email this
afternoon, and there is no second vocabulary to keep in step with the first. That
reuse is most of why this feature is small.

**Repeating blocks were deliberately out of scope**, and §5.13.1 is the ticket
that answered the question this one left open. `RepeatingBlocks` (§5.11) scans
`<w:tr>` elements out of Word's XML: the table row is the unit because it is the
unit Word gives a person. Markdown has no equivalent, and choosing one — a list
item? a table row? a fenced block? — was a design question rather than a port. A
collection marker written into an email came out blank, which is the same "blank
beats brackets" call any unfilled marker gets, and the page did not offer the
tokens.

**Markdown, and the two things that follow it.** `league/commonmark`
(BSD-3-Clause) turns the body into HTML — permissive, which is the bar §5.7 set
when PHPWord was rejected on LGPL. It brings `league/config` (BSD-3-Clause) and
through it `nette/schema` and `nette/utils`, which are offered under
BSD-3-Clause *or* GPL-2.0 *or* GPL-3.0: a disjunction the licensee chooses from,
so the BSD terms are the ones taken. Worth writing down because a grep for "GPL"
in the lock file finds them and says nothing about which licence is in force.

**Raw HTML is disabled *and* the output is sanitized**, which is one more than
the ticket asked for, and the two defend against different things.

- **Disabled** (`html_input: escape`) is the primary decision, and it is not
  really about the template author — they are a signed-in colleague. It is about
  the *values*: markers are substituted into the Markdown source **before** it is
  parsed, so a contact whose company name contains a script tag would otherwise
  be a route from one customer's record into the markup of an email. Escaping at
  parse time closes it at the point where "text somebody typed" and "markup" are
  still distinguishable. Substituting after parsing was the alternative and is
  the unsafe one — it would mean hand-escaping each value at the moment the code
  has stopped thinking about escaping. The price is that a value containing `*`
  or `_` can read as Markdown, which is a formatting oddity in one email against
  a script tag in every one.
- **Sanitized** (`symfony/html-sanitizer`, MIT) is the second layer and is not
  ceremony. CommonMark emits markup of its own from ordinary Markdown, and
  `[click](javascript:…)` is a link somebody can type with no raw HTML involved
  at all. The sanitizer is what makes the allowed elements, attributes and URL
  schemes a *policy* — Symfony's component and Symfony's configuration rather
  than an allow-list written here — and what keeps the pipeline honest if raw
  HTML is ever turned back on for a reason nobody has thought of yet.

**The Markdown source is the plain-text part.** A well-formed email carries both,
and here the thing somebody typed *is* the text alternative, markers filled in.
Nothing generates it by stripping tags out of the HTML, which is the step that
quietly produces a text part nobody wants to read — and that is the quiet
argument for Markdown over a rich-text editor, which would have left us doing
exactly that. A textarea is the whole editor; the preview belongs to XIV-39.

**Writing templates is its own permission**, `email_templates`, beside
`templates` and `document` rather than folded into either. Whoever words the
dunning letter is not whoever designs the stationery, and neither is whoever
presses send — which is XIV-39's third permission, and the sharpest of the three.

**Core answers with the contents and stops.** `EmailRenderer` hands back a
subject, an HTML document and a text alternative, not a `Symfony\…\Mime\Email`:
building the message would mean core deciding who it is from and who it goes to,
and it knows neither. Those are the application's facts and XIV-37's subject.

#### 5.13.1 A collection in an email body (XIV-62)

The question §5.13 declined to answer, left open because it was a design
decision rather than a port. **`[lines]` is one marker that renders the whole
collection as a table, and the shape it renders into ships in code.**

**Why the document answer does not carry over.** §5.11 got its unit for free: a
`<w:tr>` is the unit because it is the unit Word gives a person, so the template
author builds the row they want and gets it that many times. Markdown gives no
unit at all, so there was nothing to port and every candidate cost something. A
Markdown table row is the closest thing to the docx model and is text held
together by punctuation, so a line description containing `|` breaks the
template rather than the line. A list item is natural Markdown and a poor fit,
because line items have columns and a list has one. Explicit `[lines]…[/lines]`
delimiters are unambiguous, multi-line and format-independent — and a template
language arriving by the side door, in a system whose markers are flat
substitutions and deliberately nothing else.

**All three exist to let a tenant hand-build the line table, and that is what
rules them out.** §5.13's argument for Markdown was that *an email has no layout
worth designing*. It is why there is no .docx here, no rich-text editor and no
per-tenant wrapper. Handing somebody a repeating construct so that they can lay
out their own table takes that argument back three paragraphs after making it.

**So this diverges from the document side on purpose, and the divergence is the
decision rather than an inconsistency to apologise for.** In Word the layout
*is* the deliverable — a template exists to decide how somebody's invoice looks,
and an engine that pre-formatted the cells would be taking that away, which is
exactly what §5.11 refused. In an email it is not, and there is a second reason
now: XIV-40 attaches the generated document, where the lines are already laid
out properly. What a message wants beside that attachment is a **summary** — a
few lines and a total — rather than a second full rendering of it. Anybody
reading the two sections side by side will see a repeating row on one and a
single marker on the other; this paragraph is why, because without it that reads
as an oversight.

**The grammar is the document's own, not a second one.** `collection[:kind].field`
is what §5.11 already writes; this reads the same production with the field part
allowed to be absent, or to be a list:

- `[lines]` — every row, in the columns below;
- `[lines:article]` — only the rows of that kind;
- `[lines.description,line_total]` — those columns, in that order;
- `[lines:article.description,line_total]` — both.

Overloading the colon to mean "columns" — `[lines:description,quantity]` — was
the other candidate and was rejected on exactly this: the colon already means
"of this kind" one screen away, and `[lines:article]` would then have had two
readings depending on whether the tenant happened to have a field called
`article`. Extending an existing production costs a reader nothing; giving a
separator a second meaning costs them the first one. The happy consequence is
that **every collection token from the document reference list means something
here** — `[lines.description]` pasted out of a .docx is a one-column table
rather than the blank it used to be.

**It expands to Markdown, before CommonMark parses it, and that is the
load-bearing part.** §5.13 made marker substitution happen on the *source*, with
`html_input: escape`, so that a record value containing a script tag becomes
text without anybody remembering to make it so. A `[lines]` that expanded to
**HTML** would arrive after that decision had been made, hand raw markup to the
sanitizer as its only defence, and — worse — have no sensible plain-text form,
so the text alternative §5.13 gets for free would quietly degrade to a table's
worth of nothing. A pipe table keeps both: values still enter as source and are
still escaped by the parser, and the text part is still the thing somebody would
read. `EmailCollectionKindsTest` and `EmailTemplateTest` both prove it with
markup in a record value.

The price is that a cell containing the table's own punctuation has to be
escaped, which is one small solvable problem instead of a class of them: the
backslash first and then the pipe, because escaping a delimiter with a character
that is itself special and not escaping *that* is the classic way to leave a
hole. A newline in a cell becomes a space — a pipe table's row *is* a line, and
the usual answer, a literal `<br>`, is the one thing this must not emit.

Two consequences of the same rule are worth naming. Tables are **not**
CommonMark — they are GitHub's extension to it — so the converter gained
`TableExtension`, named rather than taken as part of the GitHub-flavoured bundle
that would also bring autolinking, strikethrough and task lists nothing asked
for. And a table is a **block**, so it needs a blank line on each side; the
source is measured rather than padded blindly, because padding unconditionally
would leave a stray blank line in the plain-text half every time the marker
already stood alone, and that half is the one a person reads.

**A collection whose rows are not all the same thing goes into one table**, in
the collection's own order, with the union of the fields as columns and an empty
cell where a row's kind carries nothing. §5.11 left this to the template, which
is not an option once the shape ships in code, so the engine answers. The other
two candidates are worse for reasons the document side already found:

- **one table per kind** sorts the invoice by kind, and a comment line sits
  *between* two article lines (XIV-21) — this is precisely what §5.11 rejected
  when it made consecutive blocks a group;
- **the default kind alone, the rest named explicitly** sends an order
  confirmation listing four of six lines, which is the only one of the three
  that can be *wrong* rather than merely plain.

The union costs an empty cell where a comment line meets the money columns,
which is what a printed invoice looks like anyway. There is no layout here to
protect, and that absence is exactly the difference from Word that made §5.11
push kinds back to the template in the first place.

**Two fields are left out of the default columns, and neither is a guess about
what matters.** The field that says which kind a row is, because it is the
discriminator rather than a column — §5.1 has it travelling hidden for the same
reason, and "Comment, Article, Article" beside rows that already look different
is noise. And a field another field is copied *from* (XIV-18): an order line's
description is inherited from the article it names, so a table with both prints
the same words twice under two headings. Nothing else is capped. A cap would be
the engine guessing which of somebody's fields matter, and being wrong about it
drops the total off the end of the table without saying so; naming the columns is
one line, and the placeholder panel prints a worked example of the form built
from that collection's own fields.

**The panel offers the tokens and says what they do.** It has to say so: `[lines]`
sits in a list beside `[first_name]` looking exactly like it, and one of the two
expands to a whole table — which is the same mistake XIV-89's picture badge
exists to prevent one row further down the same list. It is deliberately *not*
the document page's section: there a token names a column, and printing those
here would be printing a vocabulary that means something else on the page it is
printed on.

**A collection marker in the subject line comes out blank**, because a table is
not a subject. It is blanked rather than left as brackets, which is the rule
every marker the engine knows and cannot fill already gets (§5.7).

**The substitution stopped being a `strtr()`**, and kept the property that was
`strtr()`'s whole justification. One left-to-right pass that never re-reads what
it has written is what stops a contact whose name contains `[today]` from having
it substituted; `preg_replace_callback` keeps that exactly — scanning resumes
after each replacement — and buys the thing `strtr()` could not do, which is to
decide per token what kind of marker it is. Collections are asked first, because
`dataFor()` blanks every `[lines.description]` for the document side's benefit
and consulting the flat map first would blank the very tokens this exists to
fill. A token nothing answers is still printed as it was typed, which is XIV-25's
rule and the thing a change of mechanism could most easily have dropped
unnoticed.

**The wrapper gained its one `<style>` block**, and it contradicts nothing §5.13
says. CommonMark emits a bare `<table>` — no borders, no padding, every cell
touching the next — and inline styles are not available for it: the markup comes
out of the parser and the sanitizer, and reaching in to add attributes afterwards
would mean editing HTML the application has just decided to trust. So a block,
scoped to the customer's own words so it cannot reach the frame, and the argument
against relying on one does not apply: a client that drops it shows a plain
table, which is legible, where a dropped *frame* would be collapse.

### 5.14 Sending one from a record (XIV-39)

The ticket where §5.13's contents and §8.7's transport meet, and the shape is
§5.7's on purpose: **one button on the record and a chooser behind it**, never a
button per template. A contact with fifty templates would otherwise carry a
column of fifty buttons, which is the layout the document chooser already
replaced once. The modal and the page are one form, for the same reason they are
there — one description of what a send asks for rather than two that agree today.

**Two ways out of the chooser, and the fast one is the one that needed care.**
"Send" without a preview is what somebody wants on the tenth invoice of the
morning, and it is right to offer it. It is also irreversible with no undo, so
what makes it safe is not a confirmation dialog nobody reads: it is that the
**resolved recipient and the subject are on the screen before the button is
pressed**. "Preview and send" is the same form posting somewhere else, and what
it renders is the base template with this record's markers filled in — the only
honest way to find out that `[contacŧ]` was typed with the wrong letter before a
customer reads it. The preview shows who the message will appear to be from as
well as what it says, because a customer with their own SMTP server and one
without get different answers (§8.7) and a preview that omits it is a preview of
something else.

#### Where the recipient comes from, which is the weight of this

The engine does not know which field holds an email address and cannot: a module
is a declaration, and "the customer's address" is a fact about *that* module's
shape. So a module **declares** it, the way `Seed` declares where a record is
made from and `LineTotals` declares which fields are money. Guessing instead —
the first field of type `email`, or one literally called `email` — is a rule that
works on the contact module and silently picks the wrong address for the first
customer who adds an `invoice_email` beside the one they use.

Two cases, because both are ordinary:

    new MailRecipient('email')                      // a contact's own address
    new MailRecipient('email', through: 'contact')  // an invoice has none; its contact does

**One hop through a `reference` (§7.6), and a second is deliberately
impossible.** It is the same rule the query layer already holds for filtering
through a link, arrived at from another direction: `invoice.order.contact.email`
is two joins whose cost cannot be estimated from the declaration, and it makes
"where did this address come from" a three-part answer on a screen where somebody
is about to send a customer a bill. The case that would have wanted two hops does
not arise, because an invoice already *copies* its contact from the order it was
seeded from (§5.12) — the same copying that keeps an invoice correct after the
order is edited is what keeps this to one hop.

**The hop is read unscoped, and that is XIV-42's split arriving again.** There,
the *name* of a linked record is read unscoped because an order whose customer
reads `#14` is an order nobody can use, while the *link* is offered only where
the reader could open the target. An address is the first half: whoever may send
an invoice may reach the address that invoice is for, or "may send invoices"
would quietly be two grants with the second one unnameable. What protects the
address is that the send grant is on the module holding the link.

**The declaration is read from the blueprint; whether it still applies is read
from the customer's definitions.** A tenant who deleted their email field has a
shape that does not send mail, and that is answered once for the module rather
than as a problem repeated on every one of its records.

#### The address is shown, editable, and never written back

A wrong address is not recoverable and the person pressing the button is the last
check there is, so it is a field rather than a label. Editing it is emphatically
not an edit of the record: **sending a mail somewhere once is not a correction to
the contact**, and a screen whose "send" quietly rewrote a customer's email
address would be the worst kind of surprise.

It is a *correction* rather than a *substitute*, which is why **a record whose
address cannot be resolved offers no send** — and is refused if the send is
posted by hand anyway. Allowing a free-typed address on a record that names
nobody would make the declaration optional and turn the send screen into a way to
mail anybody at all from inside somebody else's ERP.

**And it says why, in the customer's own words.** "No recipient" sends somebody
looking at the wrong record; "The Customer this record names has no Email" says
which record is missing what, and both nouns are the tenant's own field labels
(§6.1) rather than anything the engine could have written down. Five cases — no
link, a stale link, an empty field, a value that is not an address, and a module
that never declared one — and the last of those draws **nothing at all**: an
articles list has nobody to write to, and a page apologising for the absence of a
feature it does not have is noise on every record of it.

#### The timeline, and why a failure is a verb rather than a flag

§5.2 admitted one entry that changes nothing — a document generated — and warned
that the next candidate should have to argue the same three properties. A send
argues them harder: it is rare, deliberate and attributable, and unlike a
document it **left the building and cannot be recalled**. Recorded: who, when,
which template, to what address, and what the subject said, with the recipient
stored rather than resolved again later, so editing the contact next year does
not rewrite who a mail was sent to.

**A failure is `email_failed`, a verb of its own, not an `email_sent` row with a
flag inside it.** A timeline is read by scanning its left-hand column, so a
failure that only announced itself in the detail is a failure somebody reads
past — and "nothing in the timeline" and "it went out" would still look the same,
which is exactly what §8.7 said must not happen. It is written by the object that
performs the send rather than by the controller, because that is the only place
that cannot forget one of the two outcomes: put the history write in the caller
and the happy path gets an entry while the `catch` block gets a flash message.
The person who pressed the button is told either way — that is what `MailSendFailed`
being thrown rather than swallowed is for.

**Sending is its own permission**, `send_email`, beside `templates`, `document`
and `email_templates`. The third of that row and the sharpest: a document that
should not have been generated stays on somebody's laptop, and a mail that should
not have been sent is in a customer's inbox. It is also the one of the four that
names a record, so it can be scoped to "only my own" where the template
permissions cannot.

*Attaching the document to the send is §5.15, and the seam described above is
where it arrived: one more argument on the method that builds the `Mime\Email`,
one more key on the named constructor for the timeline entry.*

### 5.15 The invoice goes with the mail (XIV-40)

The two features meeting, and the thing anybody actually wants an ERP to do with
an email. §5.7 already turns a template, a record and a format into a document
and §5.14 already sends one of §5.13's messages from a record, so the picker
gains a document and a format and the file goes out attached. That part is small
and is not what this section is about.

**Attaching means generating, so it takes both grants.** `send_email` is on the
route already; `document` is asked for again at the moment an attachment is
actually requested, and refused with a 403. The reason is the one §5.7 gave for
splitting `templates` from `document` in the first place, arriving one step
further along: somebody may be trusted to write to a customer and not to produce
that customer's invoice, and a send that quietly generated one would make the
second grant unenforceable through the first. "The picker was not on their
screen" is not a check — the form is a POST anybody can retype.

The second grant is asked on the **record**, not the module, because `document`
is scopable (§8.4). A check against the module alone would answer yes for
somebody scoped to their own customers and hand them everybody's.

#### One entry, and the attachment is a key on it

A document generated in order to be attached is **not a second event**. The
timeline records the send, and the send names what went with it:

    {"email": {"template": "Order confirmation", "recipient": "…", "subject": "…",
               "attachment": {"template": "Invoice", "format": "pdf"}}}

`attachment` holds exactly the pair a `document_generated` entry would have held,
which is the decision stated in the data rather than argued in prose.

Both entries was the alternative and it is worse in both directions. Reading
forwards, one button press would produce two lines — "document generated",
"email sent" — describing a single act, and §5.2 admitted the document entry on
the argument that it is *rare, deliberate and attributable*, which a side effect
of pressing Send is not on its own. Reading backwards, the pair would be
**indistinguishable from two acts that really happened**: somebody downloading a
PDF and then, for their own reasons, writing to the customer. Those are different
facts and a timeline that renders them identically has lost the one it was kept
for. Naming the attachment inside the send keeps "was the invoice actually on
that mail" answerable, which two adjacent rows never could.

What makes it mechanical rather than a rule to remember is that the generator has
two ways out: `pdf()`/`docx()`, which announce, and `contents()`, which does not.
The attachment path takes the second. That is also what lets the preview build
the very document it is previewing without a preview appearing in somebody's
history, which the announcing methods could not have done at all.

#### Failure is two-sided, and the two sides look different

- **The document could not be made** — a template that will not fill, a converter
  that is down. Nothing is sent, and **nothing is written to the timeline.** No
  message was ever built, so there was no send to have failed, and an
  `email_failed` row would assert an attempt that did not happen — §5.14 spent
  its argument on the verb being true, and this is the same argument used to
  refuse an entry rather than to add one. It joins the refusals §5.14 already
  makes silently: an unresolved recipient, an address that is not one, no
  template chosen. The person is told on the screen, in the document layer's own
  words, so the sentence is visibly about a document rather than about mail.
- **The send failed after the document was made.** That is `email_failed` exactly
  as §5.14 wrote it — and the entry **names the attachment**, which is what tells
  the two apart a year later: an `email_failed` carrying a document is a document
  that was made and a mail server that refused it, a different afternoon
  entirely from one that could not be produced.

Neither half half-succeeds, and the ordering is what guarantees it rather than
care: the document is generated and measured *before* a `Mime\Email` exists, so
"a failed generation sends nothing at all" is true by construction.

#### A ceiling, because a bounce is the worst outcome

Seven mebibytes of document, configurable as `XIVI_MAX_ATTACHMENT_BYTES`.

The number is chosen against what **receiving** servers accept, not against what
this one can produce, because that is where the failure being prevented happens.
Attachments travel base64-encoded — four bytes on the wire for every three — so
seven MiB of PDF arrives as a message of roughly nine and a half, inside the
10 MB that is both the most common conservative limit and Postfix's own default.
Gmail and Exchange Online take twenty-five; choosing against *their* number would
mean a document this installation is happy with and a quarter of the internet
bounces.

A bounce is what the ceiling buys off, and it is expensive: it arrives hours
later, at an address that is frequently nobody's inbox, about a message the
sender has stopped thinking about. The invoice simply does not arrive and nobody
finds out. A refusal on the screen naming the size and the ceiling is a worse
minute and a far better afternoon.

**Configurable because the honest answer is that we cannot know.** The authority
is the relay a deployment actually sends through, and an operator who runs their
own knows a number this project does not. The default is for the deployment that
has not thought about it, which is most of them. The check is on the document
rather than the assembled message: it is the part that varies by three orders of
magnitude — an email's words are kilobytes, the same shape every time — and a
limit somebody can compare against a file size they can see is one they can act
on.

**The preview generates too.** It costs a second conversion on the
preview-then-send path, and it is worth it: the preview exists so that what
arrives holds no surprises, and "the converter is down" and "this is too big to
send" are precisely the two surprises that would otherwise wait until the
irreversible button. The file name and the size on that screen are the real ones.

---

### 5.16 When an invoice falls due, and what makes it late (XIV-67)

An invoice had `issued_on` and a status and **no due date**, so "is this late" had
no answer — no widget, no list, and no dunning letter, which is the obvious thing
an ERP is expected to do with mail it can now send (§5.15). Two decisions make it
answerable, and both are the general shape rather than an invoice-specific one.

#### The date is stored, and this is §5.9's argument applied to a date

The tempting version computes it on read: `issued_on` plus the customer's terms,
worked out whenever somebody asks. It is wrong, and quietly. **Terms change.** The
day somebody edits a customer from thirty days to fourteen, every invoice ever
sent to them silently becomes due earlier — some retroactively overdue, for a
deadline that was never agreed. The other direction is worse: tightening terms
would make an invoice that was paid on time look late in its own history.

**What was agreed is a fact about that document.** §5.9 already argues this about
money: totals are derived and then *stored*, because a price list that changes
must not restate an invoice somebody has already been sent. A due date is the same
argument about a different kind of value, so it is the same mechanism — a
`ValueDeriver`, writing into an ordinary derived field, inside the save's
transaction and visible in the history entry that save produces.

**Materialised at the transition to `sent`**, which is not a tidy choice. That is
where the lifecycle already locks (§5.8) — the module's own words are "sent is the
end of editing… the customer has the document now" — and it is the first moment a
deadline means anything to anybody. A draft has no due date and does not need one:
nobody owes anything for a document that has not left the building.

**Written only into an empty field**, which is what "agreed once and never
restated" reduces to — the same rule §5.10 follows for a document number. So an
invoice cannot acquire a later deadline by being sent twice, and marking one paid
or cancelling it leaves the state and touches nothing. That last part is what keeps
an invoice predating this feature from quietly acquiring a due date, out of today's
terms, on the day somebody settles it.

**Existing invoices are not backfilled.** The column is nullable and a missing due
date means **not overdue**, never overdue. Backfilling would mean guessing which
terms were in force months ago, and guessing wrong in the direction that tells
somebody a paid invoice was late is worse than an empty column.

#### Overdue is a read, not a fifth state

The other tempting version is a state beside draft, sent, paid and cancelled. It
should not be one, for a reason that is structural rather than aesthetic: **every
existing transition is something a person performs** — send, pay, cancel. Nothing
performs *overdue*; the calendar does, and there is no worker process here to act
on its behalf (§8.7, XIV-59).

So overdue is `status = sent AND due_date < today`, evaluated when read. Nothing
is stored, so refining the definition later migrates nothing. It is expressed
twice from one declaration — as a question about a record in hand, and as query
conditions, because counting overdue invoices by loading every invoice and asking
each one is the N+1 that a dashboard cannot afford on the first page after
signing in.

Strictly before today, not on or before: an invoice due today is due today, and
telling somebody their customer is late on the morning the bill falls due is how a
dunning list loses its credibility.

#### Three layers, and a payment term is a number of days

A term is a property of the *relationship* rather than of a document, so it lives
where the relationship does and defaults downward — the shape §8.4.2's language
and region settings already use, arrived at a third time:

- **the tenant's**, on the profile beside currency and region (§8.6);
- **the contact's**, which overrides it;
- **the invoice's own date**, materialised from whichever applied at the time.

The layer above always *overrides* rather than combines, so reading the effective
value is a `??` chain and never an arithmetic nobody can reproduce from the screens
it was typed on. The invoice stores the resulting **date** and not a copy of the
number of days: the date is what was agreed, and the days are the rule it came from
rather than a second fact about the document.

**Null at the top, rather than thirty.** A term nobody chose is not a term, and a
default here would put a deadline on the next invoice every existing tenant sends
— for a date nobody in that company agreed to give. It is the same call §8.6 makes
about the currency, and it lands in the safe direction: no term means no due date,
and no due date means not overdue.

**Days, and what that rejects.** "2/10 net 30" — a discount for paying early — is
two deadlines with two different amounts behind them, which the money model has no
room for while `status` is binary; a document settleable for less than its gross
total is a change to §5.9 rather than a change to a date. "Net 30, end of month" is
real and common and is a *rounding rule applied to the answer this already
produces*, so it can arrive later as an option on the same field without restating
anybody's terms. A free-text term — "on receipt", "before delivery" — is
unfilterable and uncomparable, which defeats the whole point, since the question
being answered is which of these is late and text cannot be compared to a calendar.
Zero is a real term and not an absence: payable on receipt.

#### Reading the customer's terms crosses no boundary

`invoice` declares `requires: [order, contact]`, which is a **metadata**
requirement (XIV-23) and not a code dependency — deptrac forbids one module package
importing another. So the declaration takes **one hop through a `reference`**
(§7.6) and names the field by key, exactly as §5.15's mail recipient does and for
the same reason: an invoice has no payment terms of its own and never will, because
they belong to the customer being invoiced. One hop, and a second is deliberately
impossible; the invoice already copies its contact down from the order it was
seeded from (§5.12), which is what keeps one hop enough.

Following it is an unscoped read of the other module, the same split XIV-42 made:
whoever may send an invoice may know when it falls due, or "may send invoices"
would quietly be two permissions with the second one unnameable. Nothing leaks in
the other direction either — the term is read once, at the moment the document is
sent, and what is kept afterwards is a date on the invoice rather than a restatement
of that customer's terms on every document ever addressed to them.

**What this deliberately does not do.** Partial payments (`status` is binary and
changing that is a much larger change to the money model), credit notes (§5.9's
module already says correcting a sent invoice is a second document), and dunning
letters — that is §5.14 plus a template. This only makes it possible to know who to
write to.

---

### 5.17 Demo data a field can have an opinion about (XIV-24)

The generator walks a module's definitions and asks each field's *type* for a
value, which is the whole reason it is worth having: it fills a field somebody
added in the editor this morning without having heard of that field (§5.4), and
a new field type gets demo data by implementing one method rather than by
editing a generator. Being dumb is the feature.

It is also the complaint. **The generator knows a field's type and its bounds and
nothing about what it means.** `tax_rate` allows anything from 0 to 100, so a
uniform draw across that range produced 63.90, 40.55, 15.10 — every value valid,
almost none a VAT rate, and a set of invoice totals that are arithmetically
perfect and tell you nothing about whether the feature works, which is what the
data was generated for. From the other direction, an article's `title` came out
"Kuhn GmbH", because the vocabulary has names and nothing tells it that a name on
an article is not a person's.

**A range is not a distribution.** Real numeric fields cluster hard, and the
uniform draw across everything allowed is the one shape real data never has.

So the question was not "how does the generator guess better" — that road ends in
a table of special cases keyed on field names, a second place that knows what an
article is beside the article module, which is the tax §5 exists to remove. The
question is **what is the smallest thing a field can say about itself so that the
guess is good**, and the precedent was already there: inherited values, number
formats and column widths are all declarations on the field.

**Hence one option: `samples`, a list of values the field's demo data is drawn
from.** Read in one place, `Xivi\Core\Demo\FieldSampler`. No field type changed,
and a field that declares nothing consumes the seeded sequence exactly as it did
before — the criterion protecting every field nobody has said anything about, and
asserted rather than assumed.

**Which record gets which value stays the seed's business.** The draw uses the
same `mt_rand` sequence as everything else, so `--seed` still makes a run
repeatable with declarations in play.

**A declared value is treated as though somebody had typed it**, which sets the
standard of care: a declared value the field would refuse is a value the
generator will write, in the same way a `min` above a `max` is.

**Weighting is repetition, not a second concept**, and **two declarations are
dropped rather than trusted** — a `null` among a *required* field's samples, and
the whole list on a *unique* field — because both would break the promise the
generator is actually measured on, that everything it makes passes the module's
own validation.

**What a sample means, per type.** A literal value, everywhere, which is why the
mechanism needed no type to cooperate: text and textarea take strings, decimal,
currency and integer take numbers, date takes an ISO string, and a choice takes
one of its own keys — a choice already has its options, but a list of them is how
you say that four in five orders are `draft`. It is meaningless on a `reference`,
whose values are record ids belonging to one tenant's database, and no module
declares it there; a collection is not a field at all, and its rows' fields carry
their own declarations one level down like any other field. Nothing is
half-supported: the sampler does not switch on type, so the only question is
whether a literal in code means the same thing in every tenant, and for a
reference it does not.

**Code-only, in the sense that no form draws it.** The option is stored like every
other and the editor already leaves alone what it does not draw (§5.4), so a
tenant that acquires one keeps it — but there is no control, and adding one is
the deferred work §5.4 already names: a *type* saying which of its options are
the customer's to set, and how they are typed in. Until then a "sample values"
box would have to guess how to parse `8.1, 2.6` for a decimal, a date and a
choice from one textarea. The customer who adds a field in the editor is not left
out by this: they get exactly what they got before, which is a valid value for a
field nobody has described. Plausibility requires somebody to say what the field
means, and the person who knows is whoever declared it.

**Existing installations keep their definitions**, as ever (§6.1): a blueprint is
a seed, installing is idempotent, and nothing retro-fits a changed declaration
onto a customer who already has the module. A tenant installed before this keeps
the uniform tax rates it was given; a tenant installed after gets rates somebody
can read an invoice off. Migrating them would be the engine overruling the rule
that the customer's definitions are the truth, for demo data.

#### And a field the engine owns, the generator says nothing about (XIV-73)

The other half of the same question, found on a freshly generated tenant whose
orders were numbered `Distinctio voluptatem dolorum praesentiu`. The generator
had always skipped a *collection* the definition marks derived — its rows follow
from the others — and had never asked the same about a **field**, so every value
the engine computes was overwritten with a random one before the engine saw the
record.

**A deriver that always recomputes survives that; one that fills only when the
field is empty is defeated by it.** `DerivesTotals` is the first kind, which is
why the totals were never actually wrong. `AssignsNumbers` (§5.10) and
`DerivesDueDate` (§5.16) are the second, because "assigned once and never
restated" reduces to exactly that condition — so the invented value did not lose
an argument with them, it *suppressed* them. That is the pattern worth carrying
away: a derived value nothing can be typed over is safe, and a derived value that
is agreed once has to be protected at the point where values are made up.

**It also spent numbering nobody could give back.** The handful of records whose
invented value happened to come out empty *did* allocate, so three hundred
generated orders left the tenant's counter reading 29, with two hundred and
seventy-one records in front of the next genuine order carrying no number at all.
Clearing the demo records does not undo that. Generating demo data must leave a
counter at exactly the number of records generated, and the suite asserts the
counter rather than the numbers alone.

#### Demo data drives the lifecycle rather than assigning a state

The state is not derived and never will be — it is an ordinary `choice` field a
person moves through a workflow (§5.8) — so skipping it is not the answer to the
same question. But sampling it wrote records that were `cancelled` or `paid`
having never been cancelled or paid: no history, and states the lifecycle would
not have allowed anything to reach directly.

**The decision is that the generator walks the lifecycle.** The sampled state is
read as a *destination*: the record is created in the module's initial state and
then moved along the shortest run of legal transitions, through the same
`RecordLifecycle::apply()` and `RecordWriter::save()` that a person's click goes
through. `Lifecycle::pathTo()` is the graph search that answers "how does
something get from here to there", and it lives on the lifecycle because that is
where the transitions are declared.

The alternative — accept the initial state, leave every demo record a draft — is
cheaper and was rejected on what it fails to produce. A tenant of nothing but
drafts exercises no transition, locks no record, and has **no due dates at all**,
because §5.16 materialises one on the way into `sent` and on no other save. The
feature this ticket found broken would have had no demo data to be broken in.
Driving the lifecycle also turns the generator into the broadest test of the
engine there is: it is the only caller that writes every field of every module,
and now the only one that moves every record through every module's workflow.

**It costs a save per transition**, and that is the honest price: 5,000 contacts
are unchanged at about 2.5 seconds, while 5,000 orders went from 3.5 to 5.2 —
roughly half as long again, for 1.3 extra saves per record on average and a
history entry each. A module with no lifecycle pays nothing.

**How far a record gets is the module's business, not the generator's.** Drawing
uniformly over an order's four states would make a quarter of every demo tenant
cancelled, which is not a business anybody runs — so the distribution is declared
where every other opinion about demo values is declared, as a `samples` list on
the status field, weighted by repetition like all the others. The generator picks
a destination and walks to it, and knows neither what a draft is nor how many
there should be.

### 5.18 Follow-ups, and where §5.2's argument stops (XIV-80)

A follow-up is something somebody decided to do about one record, by one date:
call them back on Friday, chase this invoice next week. A priority, a due date,
an optional assignee, a thread of notes, and a done stamp that can be taken off
again.

**One shared pair of tables, which is the opposite of what history does.** §5.2
splits history per module for two reasons, and only one of them survives the move
here. The integrity reason is real and is given up deliberately (below); the
*size* reason is not: history is written automatically, on every save, by
everybody, and grows without bound, whereas a follow-up is typed by a person who
decided to type it, and a customer producing a thousand a year is a busy
customer. Paying for per-module tables — an installer that creates them, the
63-character identifier guard in `ModuleInstaller::assertTableNameFits()` to
widen, every already-installed module to retro-fit — buys nothing in return. So
`follow_up` and `follow_up_note` are ordinary Doctrine entities in the tenant
database, created by a `migrations/tenant` migration beside `User` and
`PermissionGroup`.

**`record_id` therefore carries no foreign key, and cannot**, because the table it
points into depends on what `module` says. That is precisely the property §5.2
refused to give up, given up here with the reason written down: this table is
small, hand-written, and always read with a module definition already in hand, so
nothing ever has to work out which table a row means from the row alone. Two
consequences belong to code rather than to the database, and both are stated in
the migration and at the entity:

- **Every read joins through to the record and honours `deleted_at IS NULL`.**
  Records are soft-deleted (§5), so a cascade would have nothing to fire on even
  if there were one, and a follow-up on a deleted record would otherwise surface
  on a widget about a customer somebody removed last month. The check is a second
  query rather than a join, and that is forced: the module's table is named at
  runtime and is not a mapped entity, so DQL cannot reach it.
- **A hard purge, when one is ever built, has to sweep `follow_up` itself.**
  Nothing in Postgres will remind whoever writes it.

**The note's foreign key is real and cascades**, which is the same rule producing
the opposite answer one level down: `follow_up_note.follow_up_id` means one table
forever.

**Users are denormalised, and here they did not have to be.** Core stores an owner
id without a constraint because it genuinely does not know what a user is; these
entities live next to `User` in the same database and *could* have joined. The
answer is still no, for two reasons pointing the same way: a task should outlive
the person it was assigned to, and a label captured at write time keeps saying who
they were after a rename — §5.2's argument for `user_label`, reused. Deleting a
user clears the *assignment* and keeps the name, through a listener rather than
`ON DELETE SET NULL`, since there is no constraint to hang that on. Who *made* a
follow-up is not touched: that is a fact about something that happened, like a
history row's `user_id`, while the assignee is a live claim on somebody's
attention and a person who is gone has none.

**Two new verbs, granted per module like everything else** (§8.4).
`follow_up_create` covers opening one and writing a note on it — a note is what a
follow-up is *for*, and somebody who may create the task but not say anything
about it has been given a feature with its mouth taped shut. `follow_up_complete`
covers marking done **and** reopening, because done is a nullable timestamp rather
than a state and the two directions are one edit pointing two ways; anybody who
can close a follow-up they should not have can undo it, which is what makes
closing safe. Reading follows the module's own `view`: a follow-up says nothing
the record does not already say to whoever may open it. Adding these cost one
schema change nobody predicted — `permission_grant.action` was `varchar(16)` and
`follow_up_complete` is eighteen characters, so it is 31 now, as wide as a history
row's `action`.

**A note is editable and deletable by its author and by nobody else, including an
administrator.** The one place this feature departs from §8.4, and the only place
in the application where `ROLE_ADMIN` is not a bypass. It follows that a deleted
user's notes become nobody's to edit — the correct end state, and the reason the
rule is expressed against the stored author id rather than against a relation.

**A follow-up may only be assigned to somebody who may view its record.**
Otherwise a task lands on a list whose owner cannot open what it is about, and a
dashboard is left choosing between leaking the record's title and silently hiding
work somebody was given. Checked at assignment, through the same
`PermissionResolver` every other check uses — which is why the write path takes
its actor as a parameter rather than reading the token: resolving somebody *other*
than the current user was already the shape of the code. **Revoking the grant
afterwards is deliberately not retroactive.** There is no cascade and no listener
on grant changes: a screen about people must not silently unassign somebody's
outstanding work with no record of having done it. The residue is handled where it
shows, by listing such a follow-up without a link to its record.

**The rules live in a service, underneath §8.4's three seams.** A route carries
`#[IsGranted]`, a voter decides a record, a WHERE clause decides a list — and all
three are things a form post goes through. An import, a console command and a
future API are not, and this is exactly the kind of feature that grows one of
them, so `FollowUpManager` is the fourth seam and the one that cannot be walked
around. Grants scoped to "own records" are honoured there through the same
`RecordAccess` the list compiles from, so a record kept out of somebody's list
cannot have a follow-up put on it by typing its id.

**Per module, opt-in, on by default, and reversible** — which is the one thing
that makes it unlike a preset (§6.1). Because no table is created per module,
the switch is a boolean on the customer's `ModuleDefinition` rather than DDL, so
it can be turned round for as long as the installation lives; the store asks at
install time as a courtesy, not because it is the last chance. It lives there
rather than in a table of its own because "what this customer has, and how it is
set up" is already one row with one answer, and a second table keyed by module key
would be a second place to say a module exists. Switching it off deletes nothing:
existing follow-ups stop being offered and come back if it is switched on again, a
toggle that threw rows away being one nobody would dare use.

**`due_at` and `done_at` are `timestamptz`**, like `<module>_history.occurred_at`.
A deadline is an instant two people in two countries have to agree about. The
row's own `created_at`/`updated_at` are zoneless like every neighbouring table's,
and `updated_at` means *last activity on the thread* rather than the last edit of
the follow-up's own fields — writing or editing a note bumps it, since a
timestamp standing still while three people argue underneath it answers a question
nobody asked.

**Two indexes and no more**: `(module, record_id)` for the record page and
`(assignee_id, done_at, due_at)` for the dashboard. Over-indexing is the other
half of what made the old history table hurt, and this is the table people write
by hand.

#### Reading them back: three ceilings and no floor (XIV-81)

The dashboard asks one question — what is on my list — through three lenses that
are **upper bounds and nest**: due today, due this week, all. Narrowing only ever
removes rows from the far end, which is what makes three links read as one
control with a range rather than as three different questions.

**Today means up to the end of today, which is deliberately the inverse of
§5.16.** An invoice is overdue *strictly before* today, because telling a
customer they are late on the morning their bill falls due is how a dunning list
loses its credibility. A follow-up is the other kind of deadline entirely: it is a
note somebody wrote to themselves, and what is due at 16:30 is exactly what they
want on their dashboard at 09:00. The two predicates disagree on purpose, and the
one in `FollowUpRepository::openFor()` says so at the line, because the
inconsistency is the sort a later reader tidies away.

**And there is no lower bound at all.** `AND due_at >= …` would look like the
missing half of a range and would mean a follow-up somebody *missed* dropping off
the widget at the moment it started to matter. A missed follow-up quietly
disappearing is the worst behaviour available here, so overdue work stays in every
lens including *today*, sorted to the top, and the only way off the list is
marking it done.

**Which day the week starts on is a locale question, not a constant.** ICU
answers it — Sunday for an American reader, Monday for a Swiss one — through
`IntlCalendar`, asked with the locale `FormattingLocale` composes, since it is the
*region* half that decides. symfony/intl is this codebase's usual door onto CLDR
and has no opinion here: `Countries`, `Currencies` and `Timezones` are lists of
things, and the first day of the week is a rule rather than a list. So ICU is
asked which day it is and the remaining arithmetic — how many days back that is —
stays one subtraction modulo seven. Boundaries are then drawn on
`DateTimeImmutable` in the zone `DisplayTimezone` resolved (§8.4.4), never in UTC
and never in seconds: a week measured in 604800 seconds ends an hour early across
a spring clock change.

**Resolving a follow-up back to its record is the expensive half, and it is
batched per module.** Finding the follow-ups is one indexed read; naming them is
not, because `record_id` means a different table per `module` value and none of
those is a mapped entity. So the work is grouped by module and read in batches —
`RecordRepository::findAny()`, the sibling of `findChildrenOfAny()` — and the cost
is the number of modules somebody has work in rather than the number of
follow-ups they are carrying. §5.16 names that N+1 as the one a dashboard cannot
afford on the first page after signing in. It is **asserted rather than believed**:
a test grows the list tenfold and requires the query count not to move, because
the way this regresses is somebody writing a perfectly readable loop.

**A follow-up whose record the reader may no longer view is shown without its
record.** Its own text, due moment and priority appear; the title is not rendered
and there is no link. That is the residue of revocation not being retroactive, and
it is the same split XIV-42 made between a reference's *name* and a *link* to it,
arrived at from the other direction: there the name is shown to everybody because
whoever sees the referring record can already see what it refers to, and here
nothing about the record has been disclosed yet, so the name goes with the link.
A grant scoped to *own records* over somebody else's record gives the same answer
for the same reason. **A follow-up on a soft-deleted record is excluded entirely**
rather than anonymised — there is nothing to open, and "shown without a link" and
"not shown" are answers to different questions.

**A module whose follow-ups have been switched off drops out too**, which is what
"existing follow-ups stop being offered" above means when something goes looking
for them. Nothing is deleted and turning the switch back brings them back.

**Not built: a lens for unassigned follow-ups.** The widget is *mine*. A view of
work nobody has taken is a different screen with a different question behind it,
closer to a queue than to a dashboard, and it should be built when somebody asks
for the queue.

#### The record page, and a Live Component that owns no writes (XIV-82)

The panel sits **above the record's own fields, at full width, and nowhere else**.
A follow-up is a claim on somebody's attention and a claim below the fold is one
that has been missed — so it is not in the right-hand column, which is where the
things you may want to read live rather than the things you have to. It is
emphatically **not on the list**: twenty-five records each asking what is
outstanding on them is the N+1 §5.16 warned about, and a list is for scanning
records rather than for reading the work outstanding on them.

**The component decides what is on the screen; routes do the writing.** This is
the one place in the application where a Live Component does *not* own its own
save, and it reverses what `RecordForm` established (§8.3), so it is worth the
paragraph. `PermissionCoverageTest` defines the enforcement surface **by the
URL** — every route carrying `{module}` must name a permission, and a permission
no route names is reported as a control that lies. A `#[LiveAction]` is
dispatched through the library's endpoint at `/_components/…`, which carries no
module, so a write living only there would be invisible to the one check that
exists because unprotected things are invisible. `FollowUpController` therefore
holds six ordinary POST routes with `#[IsGranted]` on them — which is also what
XIV-80 promised twice while building the engine — and the record page already
worked this way for its other two mutations, since the lifecycle transitions and
the delete are plain posted forms.

What is left to the component is what a component is for. Three pieces of state —
the archive, the create form, and which note is being rewritten — and each earns
its keep by keeping markup **out of the document** rather than by hiding it with
CSS. That is the whole reason it is not a `<details>`, which is what the linked
records on the same page use: a `<details>` still has to be sent the forty settled
follow-ups it is hiding, and the create form's assignee picker costs a permission
resolution per user in the tenant that most record pages should never pay for.

**The archive is a counter, not a section**, for the same reason. A record with
forty settled follow-ups must not push its own fields off the screen, so what sits
on the page is one small button saying how many there are.

**And what is in it is history, which does not change** (XIV-85). This shipped
wrong: the archive drew the same note thread the open list does, so a settled
follow-up came with an add box, an edit link and a delete link, and every one of
them worked — an edit even bumped the follow-up's `updated_at`, so something
finished last month reported activity today. `done_at` is now a state and not
merely a flag on a list: while it is set, the only thing permitted is reopening,
and adding a note, rewriting one, removing one and reassigning all refuse.

Two details follow from calling it a state rather than a filter. **Marking done
something already done is refused rather than treated as a no-op**, because a
second stamp would overwrite the moment it was actually settled — the one fact the
archive exists to keep. And **reopening is deliberately not subject to the rule it
enforces**, which is what makes this reversible rather than a trapdoor: an item
put back on the list is ordinary again in every respect.

**The check is on the write path and not only in the panel**, the same split this
section already makes for note authorship. A screen that stops drawing the note
box helps whoever is looking at it and does nothing for the page that was open
across somebody else pressing Done — which is the case that produced the bug
report, and the only one a hidden button cannot address.

**Priority renders as a coloured left border, and the mapping is not an
identity**: `info → info`, `warning → warning`, `important → danger`. Two of the
three agree by coincidence, which is the trap — a template printing the stored
word would look correct until `important`, which Bootstrap has no context for, and
the loudest priority would render with no colour at all. The table is written out
in full including the two identities, so that the arrow to `danger` reads as a
decision and a fourth priority fails to compile.

It lives in **one Twig function, `follow_up_tone()`**, which both screens that
draw a priority go through: this panel and XIV-81's dashboard widget. Two copies
were already drifting — the widget shipped first with a `{% set %}` of its own as
an explicit stopgap, and that copy read `info → secondary` — which is exactly the
failure a non-identity mapping invites. It is a Twig function rather than a method
on `FollowUpPriority` because the enum is what the database holds and `text-bg-*`
is the template's vocabulary; the enum's own docblock makes that argument, and
`can()`, `display()`, `record_title()` and `is_overdue()` are the precedent for
handing a template a computed answer.

**A follow-up has no text of its own; its first note is what it is about.** That
is why `create()` takes a note, and why the panel renders the whole thread rather
than a title with a conversation underneath it. Notes read oldest first — the one
place in this application where newest-first would be wrong — and each carries its
author label and its timestamp. Edit and delete are drawn for the author alone;
the hiding is a courtesy over the manager's rule, never the rule.

**A module with follow-ups switched off renders nothing at all** — no panel, no
counter, no empty state. The switch is reversible by design, and a customer who
turned the feature off is entitled to a page with no trace of it; a box saying "no
follow-ups" is the feature refusing to leave. The page asks before it mounts the
component, and the component asks again for the page that was open across the
moment somebody switched it.

**Timestamps render through an ordinary `|date`**, because XIV-83's listener has
already told Twig which zone the reader is on (§8.4.4). The input half is the
mirror image and does need code: `datetime-local` sends a wall-clock reading with
no zone attached, so the controller reads it *in the reader's zone* before storing
it into a `timestamptz`. Getting that wrong is invisible in the country the server
sits in and an hour or nine out everywhere else.

**Overdue styling is deliberately absent.** The due moment is shown and never
coloured late: what "due" means belongs to the dashboard widget (XIV-81), and two
screens deciding it separately is two answers to one question.

**One rule lives in the controller rather than in the manager**, and only one:
that the follow-up named in the path is on the record named in the path. The
`#[IsGranted]` votes on the module in the URL while the manager resolves the
module off the follow-up row, so without the check the two would be talking about
different records and both be satisfied. It answers 404, so that a wrong id and
somebody else's id stay indistinguishable (§8.4).

**`ENFORCED_WITHOUT_A_ROUTE` is gone.** XIV-80 shipped the engine before any
screen called it, so its two verbs were granted, enforced by a service and named
by no route — held in a documented list with the ticket that would empty it
written into each entry. These routes emptied it, and the mechanism went with the
entries: a hatch nothing goes through is one that rots open, and an empty list
makes the test guarding it an assertion that cannot fail. The next engine-first
ticket of that shape should put it back rather than weaken the check.

---

### 5.19 Vouchers, and a counter with a rule in it (XIV-103)

A tenant can create vouchers: a code they hand out, worth one of three things,
good between two dates and redeemable a bounded number of times. **Applying one
to an order is [XIV-104]** and is deliberately absent from this section — what is
built here is the voucher *existing*, being valid, and being redeemable. §5.24 is
the other half, and the seam between them turned out to be exactly the one method
call this section predicted.

> **The kinds below have since been reshaped** ([XIV-122], §5.25). There are now
> four rather than three, they say a *mode* as well as an arithmetic, and
> `free_article` is gone — dissolved into "a line voucher restricted to that
> article at 100%" rather than renamed. Everything this section argues about
> *why* the kind is a variant, why the module does not `require` articles, and
> how the counter works is unchanged and is why the reshape cost a blueprint
> edit. What it says about the article link being **required**, and about that
> being load-bearing twice, is the one claim §5.25 deliberately overturned.

Most of it is a blueprint like every module before it. One part of it is not, and
that part is the reason this section is long: **a usage limit is a counter, and a
counter that two requests can reach is the one thing a declaration cannot
express.**

#### The kind is a variant

Money off, a percentage off, or a free article. Three kinds, one shape (§5.5),
and the deciding fact is the one §5.5 already names — *the fields depend on the
answer*. An absolute voucher has an amount and no percentage; a free-article
voucher has neither and carries a link and a quantity instead. They use the field
types that already exist: `currency`, `decimal` and `reference`, with nothing
added to the engine for any of them.

Both alternatives lose, and how they lose is worth recording.

**Three modules** would put three entries in the navigation for one idea, and
would make "which voucher was used on this order" a *polymorphic* reference — an
id plus a type saying which table it points at. That is the shape §5.2 refused
once already, and [XIV-104] would have to carry it for ever.

**One shape with a nullable field per kind** would offer every customer an
amount, a percentage, an article and a quantity on every voucher, with nothing
anywhere saying that filling two of them in is nonsense. The rule that only one
applies would live in validation the engine cannot express, and the form would
ask four questions where one is meant.

§5.5's consequence follows for free and is a feature here rather than a cost:
adding a voucher **asks which kind first**, because the fields depend on the
answer and something has to settle it before the form is drawn.

#### It does not require the article module, and nothing had to be built for that

Only one of the three kinds needs an article to point at, and `requires` is per
module rather than per variant ([XIV-23]). Declaring it would mean a customer who
wants `GIVE-10` off a total cannot have vouchers at all unless they also keep a
catalogue — a whole module refused over a kind they were never going to use.

So it is **`uses`**, which is exactly the distinction [XIV-23] drew for the order
module's article lines: installing succeeds, and the part that depends on the
missing module is not offered.

**The question that mattered was whether hiding a kind already existed, and it
did.** `AvailableVariants` has hidden a variant whose *required* reference points
at an uninstalled module since [XIV-23]; what was untested until now is that it
does the same for a **module's own** variants rather than only for a collection's
row kinds — the same class asked about a different shape, which is what §5.5
meant by describing *shapes* rather than modules. Both the record form and the
"which kind" chooser already ask it. Nothing had to be built;
`VoucherWithoutArticlesTest` is the evidence, and it checks the URL as well as
the page, because a hidden kind reachable by typing is not hidden.

§7.6's other answer — a link into an uninstalled module matches nothing and reads
as `#id` — stays the right **fallback** and is the wrong primary mechanism. It is
what should happen to a voucher created while articles existed and read after
they were removed. Offering somebody a kind whose only meaningful field is a
picker with nothing in it is a different thing: broken rather than degraded.

#### The code: chosen or generated, and folded in one place

`GIVE-10` is the point. A code people can say out loud beats one that is merely
unguessable, so the customer may type their own — and a duplicate is refused with
a message on the field while the form is open.

**Case is decided by folding, not by comparing.** The tempting alternative is a
case-insensitive comparison wherever a code is looked up, and it loses for a
structural reason: since [XIV-109] a `unique` field is enforced by a **unique
expression index over `data ->> 'code'`**, which is case-sensitive. A
case-insensitive rule in PHP and a case-sensitive index do not differ in style —
they *disagree about what a duplicate is*, and the database is the one that is
actually true.

So the fold happens **on the way in**, in `VoucherCodeFieldType::toStorage()` —
the engine's own normalisation seam, which every writer, validator and comparison
already passes through, so nothing downstream has to know case exists.

**A field type rather than an option on `text`.** The interface's own docblock
says a type owns storage, validation, the form control and the display, and that
a new one is "one class and no configuration". A `case: upper` option on
`TextFieldType` would have worked and would have put a voucher's rules in core,
where the next reader has no way of knowing why a text field grew a case setting.
The cost is stated rather than hidden: the registry is global, so "Voucher code"
appears in the metadata editor's type dropdown for every module in every tenant.
Hiding it would need the engine to learn which module may offer which type — a
concept it does not have and should not grow for one dropdown entry.

**Two alphabets, because two different things choose the characters.** What a
customer may type is wide, because `GIVE-10` contains `I`, `1` and `0` and
narrowing the set would refuse the one code anybody would actually write. What
the **generator** may pick is Crockford's set, narrow for a reason that does not
apply to a chosen code: nobody chose those characters, so nothing is lost by
leaving out the ones that get read wrong.

Eight characters in two groups of four — `HK4T-9PQM`, read out in two breaths —
from `random_int()` rather than `mt_rand()`. **Not a sequence**: document numbers
are sequential because gaps in the books are questions (§5.10), whereas anybody
holding `AB-0004` could guess `AB-0005`, and that is somebody else's money.

**Asking for a generated code is leaving the box empty.** A `ValueDeriver` fills
it, which is §5.10's rule with the field left editable — fill it if it is empty,
never touch it if it is not — so a code is assigned once and survives every later
save. It is deliberately not `SafeToPreview`: a generator run at typing speed
would hand back a different code on every keystroke.

A "generate" button was considered and costs more than it is worth here. It would
need a capability interface in core, a `LiveAction` on the application's record
form and a form theme block to render the control — three changes to shared
surfaces, none of them about vouchers, to replace a rule one sentence of help
text can state. **What is genuinely lost is that the code is not visible until
after the save**, which is the same trade §5.10 already makes; the code is the
record's title, so the next page is headed with it. If a second module ever wants
a generated value, the capability becomes worth building.

#### Once, N times, unlimited — and unlimited is not a number

One optional integer. "Once" is 1, "N times" is N, and **unlimited is nothing
stored at all**: `RecordRepository` drops nulls out of the payload, so an
unlimited voucher does not carry the key.

There is deliberately no sentinel — not 0, not -1, not a very large number. A
sentinel is a value arithmetic will happily compare against, so
`redeemed < 999999999` is true for reasons that have nothing to do with anybody
having asked for an unlimited voucher, and it stops being true on the day a
promotion outruns whoever picked the constant. Absence cannot be compared by
accident, and it forces the rule to be written out as `IS NULL` in the one
statement that matters.

A three-way choice field — once / limited / unlimited — plus a number was the
alternative and is worse. The shape's variant field is already the discount kind,
so a second choice could not hide the number the way variants hide fields, and
somebody could pick "unlimited" with 5 still in the box beside it: two controls
that can disagree, to say what one empty box says.

The floor is 1. Zero redemptions is not a voucher, it is a voucher somebody
switched off, and the dates are how that is said.

#### The counter, which is the engineering

**A redemption is an allocation.** Taking the last use of a voucher is the same
act as taking the next invoice number: a shared counter moves, exactly one caller
may have each value, and two callers arriving in the same millisecond must not
both be told yes. §5.10 solved that once and this is the same solution with a
ceiling on it.

**Where the count lives: a table of its own, `voucher_redemption`, one row per
voucher, unique on `voucher_id`.** Not a field on the record, because a record is
written whole and a whole-document write has no `WHERE` to put a limit in. **It
is not the customer's field either**: a redemption count is engine bookkeeping,
like `position` on a collection row (§5.1) and like `number_sequence`, and nobody
should be able to rename it, delete it in the metadata editor, type over it in a
form or import a spreadsheet that zeroes it.

Reusing `number_sequence` itself — shape `voucher`, field `redemptions`, period =
the record id — was considered and rejected. The table is already in every tenant
with the right index and the ergonomics are nearly free, but its column is called
`next_value` and its rows mean "what this counter will give out next". A row
there meaning "how many times this has been used" would be legible only to
whoever wrote it, and `period` would be holding a record id in a column
documented as a year. One table, one meaning.

The table is created by a **tenant migration**, so every customer has it whether
or not they install the module. An empty two-column table is a cheaper thing to
own than a new engine concept — "a module may declare a side table" — invented so
that one module can avoid it; `number_sequence` is in every tenant on the same
terms. There is no foreign key to `voucher` and there cannot be one: a module's
record table is created per customer by the installer, so at migration time the
table it would point at does not exist in most databases and may never exist in
some. A counter row can therefore outlive the voucher it counted, which is the
same thing soft deletion already does to everything else.

**One statement**, and the limit is inside it: there is no `SELECT FOR UPDATE`,
no advisory lock, no retry loop and no window between the check and the write.
When the limit is reached no row comes back, and that absence *is* the refusal,
exactly as it is for [XIV-27]'s counter wind-forward.

**Inside the caller's transaction**, like `NumberAllocator`: a checkout that
fails after redeeming gives the redemption back, because the lock and the
increment both belong to the transaction that failed.

#### Proving it, and what a single-process test cannot prove

`VoucherRedemptionRaceTest` is [XIV-109]'s `UniqueValueRaceTest` reused rather
than reinvented, because the two tickets are the same bug in two places. It
carries `#[SkipDatabaseRollback]`, provisions a customer of its own, commits what
it writes and takes the customer away at the end — a race cannot be tested inside
DAMA's transaction, because two connections that are the same connection cannot
conflict and two writes nobody commits cannot be seen. Every statement goes
through the production class on a connection of its own, so what is under test is
the application rather than a copy of its SQL.

The interleaving is performed, one statement at a time: both connections read the
count and **both are told there is room** (a race whose first half does not happen
is not the race); the first redeems and does not commit; the second redeems and
**blocks**, proved with `lock_timeout` rather than assumed; the first commits and
the second is refused. Both endings are checked — the winner commits and the loser
is refused, and the winner rolls back and the loser is let through, because a
checkout that never happened must not consume a use.

**What that cannot prove, said plainly.** Every one of those assertions would also
pass against a version that read the count in PHP, compared it and wrote it back,
because a single-threaded test cannot get between two statements another process
is running and that version's window is between two statements of its own. What
*can* be checked exactly is that there is no second statement for a window to be
between: DBAL's own logging middleware records what the driver executes, and
`testARedemptionIsExactlyOneStatement` asserts there is one, carrying both the
`ON CONFLICT` and the `WHERE`. It was verified the way round that matters — the
guard was temporarily rewritten as a read, a comparison and an update, and that
is the only test in the file that noticed.

#### Validity: expiry is a read

`valid_from` and `valid_until`, both optional, and **expired is not a stored
state**. This is [XIV-67]'s argument about overdue invoices applied without a word
changed: every state a record has is something a person performs, and *nothing
performs expiry — the calendar does*. A stored flag would need a job mutating
customers' records on a schedule with no human act behind it, and there is no
worker process here; it would also be wrong between midnight and whenever that job
next ran, which is exactly the window in which somebody redeems the voucher it
was meant to have closed.

An empty date is not a boundary, in both directions: no `valid_from` means it has
always been good, no `valid_until` means it never stops. Reading an absent date as
a passed one would expire every voucher created without filling the field in,
silently, at the till.

Both ends are inclusive — a voucher good until the 31st is good on the 31st — the
same rule §5.16 keeps about an invoice falling due today.

`VoucherValidity` expresses it twice from one declaration, as `Overdue` does: a
question about a record in hand, and query conditions for a list. **There is
deliberately no `validFilters()`.** "Currently valid" is
`(from IS NULL OR from <= today) AND (until IS NULL OR until >= today)` — a
conjunction of two disjunctions — and §7 question 3 records that `OR` between
conditions is one of the two things the query layer still cannot express. Expired
and not-yet-started are each a single condition and compile fine, which is why
those exist and the more useful third does not. Faking it by ANDing
`until >= today` alone would quietly drop every voucher with no end date, which is
most of them.

#### Deliberately not in this

**Applying a voucher to anything** ([XIV-104]). The seam between the two tickets
is one method call, and the shape of the discount is already declared: an amount,
a percentage, or an article and a quantity. *Answered by §5.24*, which took the
prediction literally: `Money\DocumentDiscounts` is one method, and the three kinds
collapse into one sentence — every one of them is a line. *And by §5.25*, which
found that sentence governs one of two modes and that the other reduces the line
it is applied to — through the same one method, which is the part of the
prediction that mattered.

**Module pricing** ([XIV-101], [XIV-102]) is a different feature that happens to
also involve money. A voucher against a module purchase is not this and has not
been designed into it: these vouchers are the customer's, in the customer's own
database, about the customer's own sales.

---

### 5.20 A unit belongs to the article (XIV-118)

An order line saying `2.5` is a line the customer has to ring up about. One
saying `2.5 hours` — or `0.75 kg` — is a line they can check, and for anything
sold by time or by weight that difference is what makes the price defensible.

§5.1 has said since XIV-22 that a unit belongs to the *article* rather than to
the line. That was right and it was half a decision: the article module declared
a title, a description, a price and a VAT rate and no unit at all, so the
sentence pointed at a place that did not exist. This is the other half.

**The unit is a field on the article, and the line takes a copy.** Ownership and
rendering are different questions and they get different answers: a desk is sold
by the piece on every order it will ever appear on, so the fact lives on the
article — and a line still has to *print* it, which it does through the
inheritance XIV-18 already built for the title and the price (§5.1). Not a second
path, and everything that mechanism already does comes with it: an order placed
in hours still says hours after the catalogue is re-priced by the day, a deleted
article does not empty the line, and the drift marker on the record page watches
the unit exactly as it watches the price.

On the **invoice** it arrives by the seed (§5.12) instead, and that is this
project following itself rather than disagreeing with itself: *nothing* on an
invoice line is read through the article, because an invoice quotes what was
agreed on the day. A unit read live from the catalogue would be the one field on
a sent document that kept moving.

#### Where the list comes from

Three shapes were on the table and §6.1 decides between them.

- **A `choice` field the customer fills in themselves** is the cheapest and gives
  a new customer nothing at all on their first day, and gives every installation
  its own spelling of *hour / hours / Std. / h*.
- **A managed list** — a small table of units, maintained on a screen of its own,
  referenced by articles — is consistent within a tenant, and it is a screen. For
  seven words that is a screen to find, learn and keep. Worse, it would be a
  second half-answer to a question [XIV-127] is asking properly: a list a customer
  maintains **once** and several fields across several modules point at, with
  colour, hierarchy and a merge. Units are one instance of that question rather
  than a special case of it, and a table built here would be a third of that
  feature, built early and then unbuilt.
- **A shipped set, seeded like everything else**, which is what §6.1 already
  describes a blueprint as doing. Seven values — hours, days, pieces, kg, m, m²,
  litres — written into the customer's own definitions when the module is
  installed, translated into their language on the way in like every other label,
  and theirs from that moment.

**The third**, because it is the only one that gives a new customer something
sensible on day one.

**They can add "pallet" now** ([XIV-144]). This was the honest limit here for as
long as this field has existed: the metadata editor drew no control for a choice
field's options, so the shipped seven were the seven. It was closed as the defect
it always was rather than as a feature — the editor *offered* the `choice` type
and could not configure it, which §5.4 has the argument for — and it was closed
without being closed unit-shaped, which was the condition this section put on
whoever got there. **Every variant field and every lifecycle's status field is a
`choice` field too**, and their options are load-bearing: so a module's own
field's options may be **added to and renamed, never removed**. Nobody deletes
`confirmed` from a table cell; a wholesaler adds "pallet" to the seven. Which
options a module itself named is not recorded anywhere, which is why the refusal
covers all of them and why per-option provenance is still [XIV-127]'s to model.

*Met again by §5.22 ([XIV-132]), and that is what closed it.* The knowledge
module's topics ran into this same wall from a second direction — a workshop that
wants "machine" could not have it — and two modules hitting one gap was the
argument for closing it once rather than a second time by hand. It was closed in
§5.4 rather than in [XIV-127]: what those two customers needed was a control on
the field they already had, and a shared list is still the right home for "our
units" when it arrives.

*It has arrived* (§5.26), **and the unit field is deliberately not moved onto
one.** A shared list is now buildable and a customer may make one; the article's
`unit` is a module's own `choice` field and stays that way, because §5.4 refuses
to point a module's own field at a list the customer maintains — the order line
and the invoice line compare against these *values*, so an entry taken out of a
list would break an inherited unit rather than a picker. "Our units" as a shared
list is a thing a customer can now build for a field of their own; the seven the
catalogue ships remain the catalogue's.

#### The values are keys; the labels are the customer's

The **value** is what every record holds and what an inherited copy is compared
against, so it is a stable ASCII key — `m2`, never `m²`. The **label** is what a
document prints and what the customer may rename.

That split is why the seven live in one place in core
(`Xivi\Core\Field\Units`) rather than being written out three times. The
article's field, the order line's and the invoice line's must agree on the
*values* or an inherited `hour` renders as the word "hour" on somebody's invoice
— the line's field is a `choice` of the same list precisely so that it can turn
the key back into the customer's word. Modules may not depend on each other (§3),
so core is the only place all three can share, and it is the same shape
`LineTotals`, `Seed` and `InheritedValue` already take: a declaration core owns
and modules spread into their own options. The *labels* stay per module, one
`unit:` block per catalogue, because a module that borrowed another's vocabulary
would be a module that cannot be installed on its own.

#### Plurals: no, and here is why that is a decision

"1 hour" and "2 hours" are different words, and so are "Stunde" and "Stunden".
The ICU catalogues in this project handle exactly that — **for sentences the
engine says**. A unit label is not one of those.

A choice field's labels stop being catalogue keys the moment the module is
installed: what is stored in the definition is text, in the tenant's language,
which the customer may rename to anything at all. There is no key left to look a
plural form up under. A customer's own "Palette" would have none either, so
pluralising the seven shipped ones would produce a document where some units
agreed with their number and some did not — which is worse than one where none
do.

So **a unit is a short, invariant label**, written in the form a line usually
needs: the plural where the word has one, because a quantity of exactly one is
the exception on an invoice and `2.5 hour` is a worse error than `1 hours`. Most
of the list settles the question by itself — `kg`, `m`, `m²` have no plural in
any language this ships in, and German's "Stück" and "Liter" have none either.

#### The two lines that have no article

**A custom line gets the same field and somebody types into it.** That is a
decision and not the default: a custom line is priced by hand with nothing to
inherit from, and it *also* carries a quantity — so leaving the unit off it would
recreate `2.5` of nothing on the one kind of line where every other value is
being typed anyway. It is offered the same seven, so a hand-written line and an
article line read alike on the document.

**Comment and subtotal lines are not offered one**, because they have no quantity
for a unit to qualify. That falls out of the variants (§5.5) rather than being
written anywhere.

#### An article that has no unit

Optional, and that is load-bearing rather than lenient. Every article that
existed before this field did has no unit, and a line for one has to read exactly
as it read the day before: a quantity, and nothing after it. A required unit
would have made the field a migration of somebody's catalogue instead of an
addition to it — and installing still retro-fits nobody (§6.1), so an existing
customer's articles gain the field only when they take it from the offer §7.2.1
makes.

That offer has one visible cost and it is the rule working rather than failing.
The blueprint made room for the unit by narrowing the line's description — the
form row is a twelfths grid (§5.1) and thirteen twelfths wrap — but an upgrade
only ever *adds*, and changing the width of a field somebody already has is
exactly the retro-fit §7.2.1 refuses to do. So a customer who takes the unit onto
their order lines gets it on a line of its own until they narrow the description
themselves, which is one number in a box in the field editor. The alternative was
an upgrade that quietly re-laid-out a form somebody had arranged, which is worse
than a form that wraps.

The case is also deliberately in the demo data: `null` sits among the samples, so
a generated tenant contains articles sold as themselves — a yearly maintenance
fee — beside ones sold by the hour.

#### Deliberately not in this

**Conversion.** Buying by the kilo and selling by the gram needs a factor, a
direction and a rounding rule per pair of units, and it changes what a price
*means*. It is a genuinely larger feature and nothing here implies it: a unit is
a label beside a number.

---

### 5.21 A field with formatting in it (XIV-131)

The longest thing a record could hold was a `textarea`, which is plain text. No
headings, no lists, no emphasis, no links. That is right for a note and wrong for
a procedure, for an article description that goes on a document, and for the
knowledge-base entry [XIV-132] is waiting on.

**The answer is Markdown, and the reason is that the dangerous half was already
built.** [XIV-38] and [XIV-62] put Markdown into email, and the valuable part of
that work is not the rendering — CommonMark is a library and turning text into
HTML is a line of code. The valuable part is a *safety property*: substitution
happens on the Markdown **source**, before anything is parsed, with
`html_input: escape`, so a record value containing a script tag becomes text
**without anybody remembering to make it so**. A sanitizer sits behind that as a
second layer, and link schemes are confined to http, https and mailto.

A rich-text editor storing HTML is the alternative and it loses on exactly that.
A value that is already markup arrives on the far side of the escaping decision,
leaving the sanitizer as the only thing between one customer's data and the
markup of a page — which is the trade §5.13.1 refused when it insisted a
collection expand to Markdown rather than to HTML. It also costs a dependency;
`league/commonmark` is installed, and nothing was added for this.

#### A new type, not an option on `textarea` — and [XIV-113] should follow this

The question is real and was close. An option means every existing textarea keeps
working and a customer ticks a box; a separate type means a reader knows what a
field holds from the type alone. It went to the separate type, `markdown`, for
three reasons.

**The precedent is one file away and went the same way.** `TextareaFieldType`
exists rather than being an option on `text`, and its own docblock says why:
*everything that follows from the length differs* — the widget, the default
maximum, the operators worth offering. Everything that follows from *formatting*
differs at least as much. The widget gains a preview. The record page draws a
block instead of a value on a line. A Word document is given something different
from what the page shows. A list cell is given something different again. That is
four divergences, which is not a flag on a type; it is a type.

**Whether a value is markup-bearing has to be readable from the type.**
`$type instanceof HoldsFormattedText` is a question the container answers once.
`$field->getOption('markdown') === true` is a question every caller answers
again, in the display path, the document path, the export path and the form path
— and two answers is how one of them ends up unescaped. This is the same argument
the section below makes about there being one converter, applied one level up:
the property being defended is that "text somebody typed" and "markup" stay
distinguishable *by construction*, and a boolean in a JSON options blob is not a
construction, it is a convention.

**A checkbox is retroactive and a type cannot be.** Ticking it reinterprets every
value already stored in that field, at once. A parts list typed with `*` bullets
and `_snake_case_` product codes changes meaning in every record, with no
migration, no history entry — §5.2 records *changes*, and nothing changed — and
nothing on any screen to say it happened. Choosing a type when the field is
created cannot do that, which is why "an existing `textarea` field is unaffected"
is a property of the design here rather than something a test had to defend.

The cost is accepted and is real: **there is no path from an existing `textarea`
to this.** A customer who wants their notes formatted has to add a field and move
the text. That is a conversion of stored data and belongs in §7.2's territory as
an explicit operation with a screen and a confirmation, not as a checkbox that
silently reinterprets what somebody already wrote.

**[XIV-113] weighs the identical question for references and should follow this
answer rather than reaching its own.** It is much larger and unbuilt, which makes
it the wrong place to decide a convention and the right place to inherit one; and
every reason above is *stronger* there, not weaker. A `multiple` option on
`reference` would change the **storage shape** of the value — an integer becomes
a list of integers — so the retroactivity argument stops being about how a string
reads and becomes about whether the stored value can be read at all. If a case
ever does justify an option where this justified a type, it will be a case where
the option changes neither what the value *is* nor how it must be escaped, and
the ticket that finds one should say so here.

#### One converter, configured in one place

`EmailRenderer` built its own `MarkdownConverter` in its constructor, which was
right while it was the only thing that had one. A second caller makes that
configuration a policy, so it moved whole into `Xivi\Core\Markdown\MarkdownRenderer`
and both callers are handed the same object.

**Two converters with two configurations is how one of them ends up unescaped**,
and the failure is quiet: somebody tightens what a link may point at, tightens it
in the one they were looking at, and the other stays open for a year with nothing
going red. There is now one `Environment`, one `MarkdownConverter` and one
sanitizer, so a change to what is permitted cannot apply to email and not to a
record page.

**The sanitizer policy was renamed rather than duplicated** — `email` became
`markdown` in `config/packages/html_sanitizer.yaml` — and it is deliberately the
*strictest* caller's rather than the union of what both would accept. Two of its
rules were written about email: relative links are dropped because a message has
no base URL, and `data:` media are dropped because a data URI is how an image
gets past a mail client's remote-content warning. Neither costs a record page
anything worth having. A relative link typed into a field would resolve against
whichever record it was read on, which is not something anybody means, and an
image in a field is [XIV-115]'s question. **A policy that relaxes for the newer
caller is two policies with one name**, which is the thing the extraction exists
to prevent.

#### The editor is a textarea and a preview

A toolbar means a JavaScript editor, and [XIV-33] settled the front end on Live
Components precisely so that the interactive parts of this system are
server-rendered — while the documentation promises a customer's browser makes no
CDN calls. So the control is the text, and the honesty is the preview underneath
it.

**The preview costs nothing, and that is not luck.** The record form already
carries `data-model` on the form element because [XIV-32]'s totals had to follow
somebody typing into a quantity box, so every keystroke already round-trips and
re-renders. A preview block inside the field's own widget therefore follows the
typing without a line of JavaScript being written for it. It is a form theme
block hung off the form type's prefix, which is the only way to give one kind of
field a different appearance when nothing renders fields one at a time —
`RecordForm` calls `form_widget(form.fields)` once and knows nothing about what
is in it, which is the §5 claim doing its job.

A toolbar is a later question if anybody asks. Nothing here forecloses it.

#### What a value is worth in each of the places it goes

A field's value goes to more places than a form, and leaving three of them to
emerge from whichever function happened to be nearest is how two screens end up
telling a reader different things about the same record. Each was decided.

- **The record page** gets the **rendered markup**. This is the only place in the
  application where a record's own value reaches a page as markup rather than as
  text, and it is safe for one specific reason rather than by habit: it was
  parsed with raw HTML escaped and then sanitized, by the same object an email
  goes through. It takes the whole row rather than a quarter of one, because a
  heading and a list drawn in a narrow column wrap to two words a line and the
  formatting that is the point of the field becomes unreadable.
- **A document** (§5.7) gets **the words with the marks taken off** —
  `Warning: do not…`, not `**Warning:** do not…`. A .docx is not HTML, so the
  formatting cannot survive the trip whatever is decided; given that, the only
  question left is whether the punctuation travels with it, and punctuation
  printed on a customer's invoice is punctuation nobody meant to send. *"The
  source, as typed"* is the defensible alternative and it was rejected on that
  one sentence.
- **A list column** gets the same, for the same reason arriving from a different
  direction: a cell has one line and no room for a block, so a cell reading
  `**bold**` is strictly worse than one reading `bold`. A collection's rows on
  the record page are a table too and get the same treatment.
- **An export** (§5.6) gets **the source, untouched**, and needed no decision in
  code because the exporter already works in storage form. It is still a decision
  and is written down here: an export has to be importable, so it carries what
  was stored rather than a rendering of it. That is also the one place a customer
  can get their formatting back out intact.
- **A filter and a search** match **the source**. Searching for `Warning` finds a
  record whose text says `**Warning:**` because `contains` runs on the stored
  string; searching for `**` finds every record with emphasis in it. Matching the
  rendered words instead would mean rendering every row on every query, or
  keeping a second derived copy of every value to search against, and neither
  buys anything worth having.

**The plain rendering asks the parser, not the string.** Stripping `*` and `#`
with a regular expression would be a second and worse implementation of a grammar
already in the room, and it would disagree with the rendered half the first time
somebody typed a literal asterisk. It is also **not** "the HTML with the tags
taken out": that would mean un-escaping entities afterwards to get readable text
back, and a pipeline that escapes and then un-escapes is one refactor away from
handing markup to a caller that trusted it. What it does instead is walk the
parsed document and read the literals off it, so the markup is never built at
all — which is asserted by giving the renderer a sanitizer that throws.

#### Deliberately not in this

**Images and file embeds**, which are [XIV-115]. The sanitizer policy already
refuses `data:` and relative sources, so nothing here quietly half-supports them.

**Tables beyond what `TableExtension` already gives**, and no other CommonMark
extension either. The grammar is `CommonMarkCoreExtension` plus tables, named
individually rather than taken as the GitHub-flavoured bundle — the smaller the
grammar somebody writes against, the fewer ways their text surprises them, and
every addition is a new shape of markup the sanitizer's policy would have to have
an opinion about.

**Collaborative editing**, of any kind.

**A module blueprint that declares one.** The engine has the type and the metadata
editor offers it; no shipped module changed its own fields to use it, because
installing does not retro-fit (§6.1) and a blueprint change would have meant new
tenants and existing ones disagreeing about what an article description is for no
gain this ticket needed.

*Answered by §5.22 ([XIV-132]).* One does now, and it is a new module rather than
a changed one — which is the same rule arriving from the other side. Nothing
retro-fitted, nobody's article description changed, and the first blueprint to
declare a `markdown` field is one whose customers are choosing it by installing
it.

---

### 5.22 An internal knowledge base, and how much of it was already here (XIV-132)

Every business runs on knowledge that lives in one person's head. *How do we
handle a refund past thirty days? Which supplier when the usual one is out? What
did we agree with this customer in 2023?* When that person is on holiday nobody
else can answer, because the answer has never had anywhere to live.

So: a module where experienced staff write entries and everybody else reads them.
**A very simple wiki, and the emphasis is on simple.**

This section is short on purpose, and its shortness is the finding.

#### The engine work this needed was none

An entry is a record with a title and a body. That makes this ticket a test of
the claim §1 has been making since the first module — *the engine describes
modules, it was not built around one* — and the test came back clean in a way the
earlier modules could not demonstrate. Contact proved a module could be
described. Article brought two field types. Order and invoice brought line
totals, seeding, numbering and payment terms. Voucher brought a field type and a
counter. **This one brought a blueprint, a translation file and a bundle**, and
`packages/knowledge` contains nothing else: no controller, no entity, no form
type, no template, no service, no migration, no field type.

Four things a knowledge base needs that were already there, and none of them was
asked for:

- **Who wrote it and who changed it** is §5.2's record history, on every record
  of every module, plus the `owner_id`, `created_at` and `updated_at` the
  installer puts on every module's table. The module therefore declares **no**
  `author` field and no `written_on` field, and their absence is the decision
  rather than an omission: a date field is a date somebody has to remember to
  set, and one they forgot is a record that is confidently wrong about itself.
- **Write versus read** is the per-module permission axis (§8.4), which already
  splits `add` and `edit` from `view` and `list`. No new permission concept, no
  "editor" role, nothing seeded at install.
- **Searching** is `Operator::Contains` over a field flagged `filterable`, which
  compiles to a case-insensitive `ILIKE` (§5.3). The whole feature is one boolean
  in the declaration. Its ceiling is real and is written down two headings below.
- **A formatted body** is [XIV-131]'s `markdown` type, merged the day before
  this one and naming a knowledge-base entry in its own docblock as the thing it
  was for. §5.21 closed by saying no shipped module declared one; this is the
  module that does, and it is a *new* module for exactly the reason that section
  gives — nothing about anybody's existing data changed.

One thing was added outside the package and it is a shared template rather than
the engine: the module list grew a **Changed** column, argued under *staleness*
below. That is the honest total.

#### Categorising: a plain `choice`, and a note for [XIV-127]

Six topics — process, policy, customer, supplier, product, other — as an ordinary
`choice` field, seeded into the customer's definitions at install like every
other label (§6.1). The stored value is `supplier` for ever; what is shown is a
row in their database from the moment they install it.

**[XIV-127] is the right answer and is unbuilt.** It proposes shared lists a
customer maintains once and uses across modules, which is where "our topics"
belongs — next to "our units" (§5.20) and "our payment terms" (§5.16). The choice
in front of this ticket was therefore between a plain choice field and building
half of [XIV-127] inside one module, and half of it is the worse option by some
distance: a half-shared list is a second mechanism [XIV-127] would have to
migrate customers off, and the customer would be the one who met the migration.

A choice field costs nothing to give up when it lands. The stored values are
strings, a shared list will also store strings, and the field's *type* changes
while the values do not — the cheapest thing §7.2 has to do. **This module is
recorded here as [XIV-127]'s first consumer**, so that whoever builds it has a
caller to design against rather than a hypothesis.

**It landed, and this module still ships a plain `choice`** (§5.26). Being the
recorded consumer is what shaped the design — a shared list stores the same
strings a `choice` field does, precisely so that this module's six topics could
one day be a list without moving a record — and it is also why `topic` was left
alone: §5.4 refuses to point a *module's own* field at a customer's list, since
the customer could then take an entry out of one and change what the module
declares. A workshop that wants its own vocabulary adds a field of its own and
points that at a list, which is the shape this section was asking for.

**The honest limit was §5.20's, word for word, arriving at a second module — and
finding it twice is what closed it** ([XIV-144]). A customer can add a seventh
topic: the field editor draws a control for a choice field's options now, on this
module's own `topic` field like any other. What it will not do is take one of the
six away, because this field came with the module and §5.4 refuses that for every
module's own field. `other` therefore stays useful for the reason it was put
there — it is what somebody files an entry under while they are deciding whether
they want a topic of their own — and it is no longer the difference between a gap
and a wall.

Deliberately **not required**. Somebody writing down what they know at half past
five should not be stopped by a dropdown they have no opinion about, and an entry
filed under nothing still answers the question it was written to answer.

#### Linking: no, and "no" is the whole decision

§7.6's references would do it, and a reference into a module the customer has not
installed matches nothing and reads harmlessly (§5.19) — so *"this entry is about
the invoice module"* or *"about this customer"* would have been safe to build.
It was still refused for the first slice.

The reason is that a link has to earn its way in from **both** ends, and only one
end was on offer. Pointing an entry at a contact costs a field and buys a filter.
Reading it back from the *contact's* page is the half people actually want —
"what do we know about this customer" — and that is §7.6's linked-records panel,
which would then put a knowledge card on every contact, article and invoice page
in the system. That is a much larger change than a `reference` field, and it is
one nobody asked for.

The consequence is worth having on its own: this module declares no `requires`,
no `uses` and no `reference`, which makes it **the first module that installs
into a completely empty tenant**. Somebody who signed up an hour ago can write
down what they know before they have a single contact.

If linking is ever wanted, an entry gaining a `reference` field is additive and
retro-fits nobody (§6.1, §7.2.1). Nothing here forecloses it.

#### Keeping it current: showing the age, not scheduling a review

**A knowledge base's failure mode is not being empty — it is being confidently
wrong.** An entry written in 2023 describing a process that changed in 2024,
which somebody reads and follows. Empty is obvious and harmless; stale looks
exactly like current.

A review date is the machinery this invites and it was refused. It is a field
somebody has to set, a second one somebody has to answer, and a notification
somewhere for when it passes — and an entry whose review date has lapsed is
still, on the page, indistinguishable from one that has not. What actually
defends against the failure is that **the age is on the screen next to the
entry**, and §5.2 has recorded it all along.

The record page already showed *Created* and *Changed* in its right-hand card.
That is the right place to find the answer and the wrong place to *notice* it: by
the time somebody is reading the page they have decided this is what they came
for. So the module list grew a **Changed** column, beside the *Owner* column that
has been there since the list existed.

**Both are system columns and that is the argument.** `owner_id` and `updated_at`
are written by the engine on every record of every module, neither is a field
anybody declared, and drawing the second next to the first is completing a pair
rather than introducing an idea. Neither sorts, for the same reason: a
`RecordQuery` orders on the customer's own definitions and these are not among
them. The date shows without the time, because a list is scanned rather than
read.

It lands on **every** module's list, which is deliberate rather than collateral.
"Which of these did somebody touch today" is asked of a list of orders as often
as of a list of entries, and a column the engine can fill for nothing on every
module is not a knowledge-base feature that leaked out — it is the generic thing
this ticket happened to be the first to need.

#### The search ceiling, stated rather than discovered

`contains` is `ILIKE '%word%'`. What that gives is case-insensitive substring
matching over the stored source (§5.21 decided that it matches the source rather
than the rendering). What it is **not**:

- **No stemming.** "Lieferanten" does not find "Lieferant"; the substring runs
  one way only.
- **No ranking.** Ten matches come back in whatever order the list is sorted by,
  not best first, so the most relevant entry is wherever the alphabet puts it.
- **No phrases or proximity** beyond the literal substring.
- **No index.** The query cannot use an ordinary btree, so the cost grows with
  the number of entries.

At a few dozen entries nobody can tell the difference. At a few thousand somebody
will want the difference badly, and giving it to them means `tsvector`, a GIN
index and a field type in the engine that knows about both — which is a ticket,
not a paragraph. **It is deliberately not a reason to hold this back**: a
knowledge base with substring search in it beats one that does not exist, and the
upgrade is invisible to the data because the stored value does not change.

`KnowledgeModuleTest` asserts the ceiling as well as the feature — the plural
failing to find the singular is a test rather than a sentence — so the day
somebody builds full text there is a red line pointing at exactly what changed.

#### Who may write, and what the default is

The permission axis covers this with nothing added. What was worth deciding is
the **default**, and there were two candidates: everybody who can read can write,
or writing is granted deliberately.

**Writing is granted deliberately.** For knowledge people will *act* on, an entry
somebody wrote in passing and got wrong is worse than an entry nobody wrote, and
"who may put something in here" is a question a business should have answered on
purpose. It can be relaxed by a grant on the day a customer decides otherwise;
the other direction — noticing afterwards that everybody has been editing the
refund policy — cannot be undone by a setting.

**And it needed nothing built, because §8.4's platform default is already deny.**
Nothing is granted at install, so a customer who does nothing gets exactly this.
`view` and `list` on Knowledge make a reader; `add` and `edit` make a writer.

#### What this must not become

**Not a wiki.** No page trees, no `[[cross-link]]` syntax, no namespaces, no
revisions-with-diffs beyond what §5.2 already records. Each of those is what
turns a wiki into a product somebody has to administer; this is a list of entries
with a search box.

**Not customer-facing**, and the declaration keeps that rather than anybody's
care. Nothing here is published, shared with a contact or attached to a document.
The module names no contact and declares no `mailRecipient`, so §5.14's *send
this record* path has nothing to resolve an address through and the button is not
drawn — an entry cannot be mailed to somebody by accident because there is
nowhere for the address to come from. If publishing a subset is ever wanted it is
a different feature with a different security argument, and it should arrive as
one.

---

### 5.23 A phone number is one number (XIV-114)

Phone numbers were typed into `text` fields, so `+41 79 123 45 67`, `0791234567`
and `079 123 45 67` were three different values for one number. A search found
one of them, a duplicate check found none of them, an export was whatever each
person had happened to type, and nothing downstream could ever have rung any of
it. `phone` is a field type now: whatever is typed is stored as **E.164**
(`+41791234567`), and what cannot be read is refused with a sentence rather than
kept as a string.

**`toStorage()` is the seam, which is §5.19's argument one step harder**, and it
buys the property worth stating as a property: **the form, the importer and the
query compiler cannot disagree about what a phone number is, because none of them
has an opinion.** `PhoneNumberTest` proves that by going in one door and out
another — a number typed `079 123 45 67` into the record form is found by a
filter typed `+41 79 123 45 67` in the URL of the list page — rather than by
calling `toStorage()` and asserting what comes back, which would test the method
and say nothing about the seam.

Two consequences are taken rather than discovered — **`unique` starts working**,
since [XIV-109]'s index is over the stored string, and **an import of existing
data will refuse rows**, because ten years of hand-typed numbers contain some
that are not numbers. A third is outside anybody's control:

- **Google's metadata moves, so validity moves with it.** `isValidNumber()` is a
  question about a table in the package rather than about arithmetic, and
  countries open and retire ranges. A `composer update` can therefore change
  whether a number is acceptable — in both directions. Nothing revalidates on
  read, deliberately: a stored number is a fact about a customer (§5.9) and a
  library update is not a reason to stop showing somebody their own data. What it
  does mean is that the same spreadsheet can import cleanly on one release and be
  refused on the next, which is a thing to know before it happens rather than
  after.

**The country comes from the chain that already exists, and that is the decision
this ticket is mostly about.** `079 123 45 67` is only a number if you know where
it was dialled; the same digits are a valid Swiss mobile and a valid German
landline. §8.6 gives an installation a region, [XIV-50] built the chain that
reads it and [XIV-83] extended the same shape to the timezone — so a fourth
country setting was the thing to avoid, and none was added.
`Xivi\Core\Region\InstanceRegion` is the fourth instance of the seam
`InstanceCurrency`, `DefaultPaymentTerms` and `DefaultVatMode` keep, and
`ProfileRegion` answers it by **delegating to
`FormattingLocale::instanceRegion()`** rather than reading the profile a second
time: a fourth *reader* of one setting is the same mistake in cheaper clothes.

**The person is deliberately not in that chain, and display is where they come
back.** `FormattingLocale::of()` starts with the reader's own region; parsing
starts one link down, because how a number is *shown* is about who is looking and
how it is *stored* must not be. A French colleague at a Swiss company typing a
local number is typing a Swiss number, and a chain that asked who was looking
would store `+33…` for them and `+41…` for everybody else — the same digits
becoming two different customers depending on whose screen they were entered
from. Display then takes the opposite rule: national where the number is local to
the reader, international where it is not, read off `\Locale::getDefault()`,
which is [XIV-50]'s chain arriving where `DateFieldType` and `CurrencyFieldType`
already collect it. Core still does not know what a user is.

**A per-field override, because it is an option with a default rather than a
setting.** A customer whose `supplier_phone` only ever holds German numbers can
say so on that one field; every other phone field goes on following the profile
and nobody opens the metadata editor to get the common case. It is the **third**
entry in §5.4's declared option-to-capability list, and the first added since
that list stopped being a pair of `instanceof`s — which is the evidence that the
list was worth declaring. It cost one capability interface (`AssumesACountry`),
one line in `FieldController::PER_TYPE` and one `<select>` in the field table.
No branch was added anywhere. Changing it decides how the *next* value typed into
that field is read and rewrites nothing already stored, which is worth saying out
loud because the tempting reading is the other one.

**Extensions are refused, and the reason is arithmetic rather than taste.**
E.164 has no room for an extension and `format(E164)` **drops it silently** —
measured, and asserted in `PhoneFieldTypeTest` so that the day the library
changes its mind something goes red. A second field is the right answer and the
customer already has it — the metadata editor adds one without a deploy (§5.4) —
so the refusal says exactly that.

**The dependency is the lite build, and the trade is measured.**
`giggsey/libphonenumber-for-php-lite` against `giggsey/libphonenumber-for-php`:
2.8 MB against 25 MB installed, and the 22 MB is geocoding, carrier lookup,
short-number data and number-to-timezone mapping, none of which this feature
touches. [XIV-96] took the customer image from 7.3 GB to 462 MB, so the full
build would have spent 5% of that image on which carrier owns a prefix. The
argument lives in `packages/core/src/Phone/PhoneNumbers.php` — the file that uses
it — so that whoever is later tempted to swap the requirement meets the reasoning
before the diff. **It is also the first Apache-2.0 dependency in a production
image**, which is compatible with this project's MIT and is not MIT: it carries a
notice requirement and an express patent grant, and `THIRD-PARTY-NOTICES.md` has
a section shaped for that now rather than a bullet in a list of exceptions.

**Contact's `phone` becomes one, and nobody's database moves.** The blueprint
declares the new type and marks the field filterable, because a filter over a
canonical value is a filter that finds things. A tenant that installed Contact
before this release keeps a text field and goes on keeping it — §6.1, and
`ModuleUpgrade` never offers a key the shape already has, whatever it now looks
like. Changing it for them would be a type change, which §5.4 refuses because
stored values may not survive one; §7.2's open half is still open and this does
not reopen it.

**Deliberately not in this:** nothing is *sent* to a phone number. No SMS, no
verification codes, no click-to-dial. This is a field type.

---

### 5.24 A voucher on an order (XIV-104)

§5.19 made a voucher exist, be valid, and be redeemable. This is the other half:
putting one on an order and changing what the customer owes. The seam between the
two turned out to be one method call in each direction — one to ask what a
voucher is worth, one to take a use of it — and almost everything below is about
where those two calls happen rather than about what they compute.

**Only where both modules are installed.** §6.1 says a customer's own module list
is the truth, and this has to be invisible to a tenant who has vouchers and no
orders, or the reverse. How that is arranged is the last part of this section and
is the one part that needed something new in the engine.

#### The rule that decided most of it

**A discount is a derived value, and derived values are the engine's** (§5.9).
`DerivesTotals` already works an order's totals out as a `ValueDeriver`, inside
the save's transaction, writing into ordinary derived fields. A voucher changes
the total, so it belongs in that path — not in a controller, not in a template,
and never written by hand. Writing derived values by hand is [XIV-73]'s bug: it
produces records that look plausible and are wrong.

Two decisions came with the ticket and are not re-argued here, only followed:

- **The voucher applies before VAT.** It reduces the net, and VAT is computed on
  the reduced net, rather than being deducted from a gross figure.
- **A discount is its own line.** Not a mutation of the lines it discounts, and
  not a field on the header — which is [XIV-16]'s own rule about discounts,
  arriving where it was always going to. **[XIV-122] later gave a voucher a
  *mode*, and this rule turns out to govern one of the two** — the mode where
  there is no line for the money to belong to. A voucher applied to a single line
  reduces that line instead, which is not a departure from this: see §5.25.

Together they unify the three kinds §5.19 declared, and that unification is the
whole reason the implementation is small: **every kind is a line.** An absolute
voucher is a `-10.00` line with nothing to distribute; a relative voucher is the
same line with the amount computed from what the lines came to; a free-article
voucher is a line at quantity N and a price of nothing. Nothing downstream — the
document, the invoice seeded from it, the VAT grouping — has to know which kind
it was.

It settles presentation too. The customer's document shows what they were quoted,
on the lines they were quoted, with the discount stated separately. Nothing
silently reads `1 × Widget @ 100.00 = 90.00`.

`DerivesTotals` needed no apportionment step to make the VAT work: it already
builds the table by grouping lines on `tax_rate`, summing each group's
`line_total` and applying the rate to the group once. A negative line joins that
grouping like any other.

#### Which rate a discount line carries

The sub-question those decisions leave, and the only genuinely open one.

A discount line must have a rate or it falls out of the grouping entirely — and a
discount outside the VAT table means tax computed on undiscounted nets, which
contradicts the first decision. On a single-rate order there is one answer and
one discount line. On a mixed-rate order no single line can carry the right rate,
so it becomes **one discount line per rate present**, each carrying that rate and
its share, pro rata on that rate's own net:

    Discount (8.1%)   −6.67
    Discount (2.6%)   −3.33

The distribution therefore comes back as *lines*, which is better than the
alternative it replaced: it is visible on the document and adds up in front of the
reader instead of inside a deriver.

**Where the remainder lands is decided and written down.** Rounded shares do not
have to add back — ten francs over three rates that sold equal amounts is 3.33
three times, which is 9.99, and a ten-franc voucher that took 9.99 off is a
voucher that lied by a rappen. So the shares before the last are computed and
subtracted, and **the last line takes the balance**. The lines are emitted sorted
by rate, the same order the VAT table is in, so "the last one" is the highest rate
on the document and a reader checking the column meets the odd rappen in the same
place they meet it everywhere else.

That agrees with [XIV-116], which settled the neighbouring question hours before
this one started: *the figure somebody stated is exact and the derived figure
absorbs what is left over.* There the stated figure is a gross price and the
derived one is the tax within a rate; here the stated figure is what the voucher
is worth and the derived ones are its per-rate shares. Neither remainder crosses
a rate boundary in a way that changes what a rate owes: each rate's discount line
joins that rate's own group and is taxed with it.

**Inclusive VAT needed no case of its own**, which is worth saying because it did
not exist when this ticket was written. The mode says how to read the price
column (§5.9, [XIV-116]), and a discount line is in that column like every other
line: on a shelf-priced order the discount comes off the gross, and the net and
the tax follow from it by the same division. A tenth off a gross is a tenth off
the net inside it.

**A voucher worth more than the order is capped by it.** The shares are computed
over the rates that sold something positive, and the discount stops at what they
came to. A negative total is money owed back to a customer, which nothing
downstream is built to hand over — §5.19 caps the percentage at 100 for the same
reason.

#### One deriver, and a seam rather than a second one

The arithmetic could not be a second `ValueDeriver`, and the reason is written
into `ValueDeriver` itself: **order between derivers is unspecified**, on purpose,
because two modules wanting the same field is not an argument the engine settles.
A discount deriver and a totals deriver are not two modules disagreeing, though —
they are two halves of one sum, and they have a strict order in both directions.
The discount lines must be in the grouping before the VAT table is computed from
it, and the *amount* of a relative discount is a fact about what the lines came
to, so it cannot be worked out before they are summed. A second deriver would
have been correct roughly half the time, and the half it was wrong in would store
an order's totals computed without its own discount.

So there stays exactly one deriver for a document's money, and what it does not
know it asks: `Money\DocumentDiscounts` is a one-method seam that core defines and
the voucher package implements. Core's half of the contract is deliberately
narrow — *how much comes off, and which lines to add* — and it contains no
voucher vocabulary at all. Where the money lands, which rate carries which share,
which line absorbs the rappen and whether the discount is capped are all
arithmetic about a document, and §5.9 has one place for that.

**The voucher package finds the order's field rather than being told it.** §3
forbids either package importing the other, and neither needs to: a link between
modules is a `reference` field carrying the *key* of the module it points at
([XIV-13]), and that key is in the customer's own definitions. So "does this
document name a voucher" is answered by reading the shape and looking for a
reference into `voucher` — the same reading the record page does in reverse when
it lists the orders naming a contact. It also means this works for any module a
customer points at vouchers, including one they built themselves in the metadata
editor.

**Three answers, and the third is the interesting one.** A source returns `null`
for *not mine*, an empty `Discount` for *mine and worth nothing today*, and a
discount otherwise. Collapsing the first two would break one case each way: an
invoice carrying discount lines copied down from its order (§5.12) would have them
taken off it by a module that has never heard of vouchers, or a voucher removed
from a draft would leave its discount on the order for ever.

#### The engine owns these lines, and a subtotal was not the precedent

A generated line must not be editable or deletable by hand, or it desynchronises
from the voucher that produced it. `SUBTOTAL_LINE` looked like the precedent, and
**establishing what the editor actually does with a subtotal row is what showed it
was not**: a subtotal's *figure* is derived and the row is the customer's — they
add it from a button, move it by typing a position, and delete it — and the whole
of its protection is that `line_total` is a `derived` field, which the form draws
disabled and the writer recomputes. That protects a *column*. A discount line
needs the *row* protected, because it is a fact about a voucher somebody redeemed
rather than a heading somebody wanted.

Three things do that, and only the first is enforcement:

- **The deriver writes them on every save.** Rows of the generated kind are taken
  out of the submitted set before the sums are computed, and whatever the voucher
  is worth now is written in their place — reusing the ids of the rows it
  replaced, so editing an order does not churn a row per save. A request that
  edits one, deletes one or invents one therefore changes nothing: the next
  derivation states the truth again. That is what `OrderVoucherTest` asserts, and
  it asserts it through the record form's own save action rather than by calling a
  guard.
- **The form draws them disabled.** `CollectionRowType` is told which kind the
  module generates and `RecordType` disables every field of such a row — the same
  mechanism a derived field has used since [XIV-20], one level up. A disabled
  field ignores what is submitted, so this is a second, independent refusal rather
  than decoration.
- **The kind is not offered.** `AvailableVariants` — the one class that answers
  "which kinds can be created here", and which both the form and the kind chooser
  already ask — leaves it out. The kind itself stays an ordinary option on the
  customer's variant field, because rows of it have to render and §5.5 is explicit
  that the variants *are* the field's options with no second list to disagree.

**Taking the discount lines off the form entirely was the alternative** and is
worse in a way that is easy to miss: a row that is not submitted has no id to
carry, so the writer would delete three rows and insert three identical ones on
every save — churning ids, filling the timeline with "line removed / line added"
and leaving a tombstone behind each time.

#### What is stored, and what is re-read

[XIV-67] settled this for payment terms and [XIV-16] for totals: **what was agreed
is a fact about the document.** The discount line's amount is stored like every
other line total, and the order's reference merely says which voucher it was.
Recomputing from the voucher on every *read* is the mistake §5.9 exists to
prevent, and nothing here does it — deleting the voucher afterwards changes
nothing on the order, which is asserted rather than asserted-about.

What the deriver does do is recompute on every *save*, from the voucher's current
values, exactly as a line total recomputes from its quantity and price. So editing
a voucher changes what an order that is still open will say the next time somebody
saves it. That is deliberate, and what keeps it away from a document somebody has
been given is §5.8: a **locked** record cannot be saved, and a record that cannot
be saved is never derived again.

**The window is wider than "a draft", and it is worth being exact about it.** The
order module locks `delivered` and `cancelled` and not `confirmed`, so a confirmed
order re-saved after the voucher was edited does restate its discount. That is not
a hole this feature opened: every derived figure on that order — the line totals,
the subtotals, the VAT table — has exactly the same window, and has since
[XIV-16]. Narrowing it for the discount alone would be a rule about vouchers
wearing the clothes of a rule about documents, and the place to narrow it, if it
is ever worth narrowing, is the lifecycle.

**A voucher that cannot be read leaves the lines alone.** Deleted, or its module
uninstalled — the deriver has nothing to say and changes nothing, rather than
reading the absence as "no discount" and quietly taking money off a document
somebody has already been given.

#### Redemption is a write, and it happens once

[XIV-103] built a guarded counter — its own tenant table and one
`ON CONFLICT … DO UPDATE … WHERE` statement — and said this ticket would be its
caller. It is, and everything interesting is *when*.

The caller is a subscriber on `RecordChanged`, which is dispatched **inside the
writer's transaction** (§5.2). That one fact buys all three properties the ticket
asked for:

- **A use is taken when the order commits**, not when somebody types a code into a
  form and wanders off. It has to be: the live form re-derives on every keystroke
  ([XIV-32]), so a redemption on that path would burn a voucher per character.
- **A save that fails takes nothing**, because the redemption is a statement in
  the transaction that rolled back. Nothing has to remember to undo anything,
  which is the property [XIV-103] chose its statement's shape for.
- **A refusal takes the save down with it.** Whether a use is left cannot be known
  any earlier than the statement that fails to increment the count, so the
  refusal has to be able to happen at the write.

**Removing a voucher from a draft gives the use back**, which was the open
question, and the invariant that decides it is worth stating in one line: **the
count is the number of documents that carry the voucher.** Naming one takes a
use, un-naming it gives that use back, swapping one for another does both, and
deleting the document gives it back. Anything else about the order — a line
edited, a status confirmed — does nothing at all, which is most of the traffic and
is why the subscriber reads the *field diff* rather than the record.

Leaving the count up instead would burn a single-use voucher on somebody's
mistake, with nobody in the building able to put it right: the counter is engine
bookkeeping and is deliberately not a field a customer can edit (§5.19). Giving it
back needs a second statement — `redeemed_count - 1` with a floor of zero — and
[XIV-103]'s guarded statement stays the only way a use is *taken*, which is the
part that had to stay true for [XIV-122]'s second caller.

**A cancelled order keeps its use**, and that is the one edge this invariant
leaves visibly imperfect. A cancelled order still carries the voucher, it is a
record of what happened (§5.8), and the lifecycle has locked it so nobody can take
the voucher off it either. Releasing on cancellation would be a fourth rule about
a fifth state; the honest answer for now is that the count says how many documents
carry it, and a cancelled order is one of them.

#### Refusing, and saying which

An expired, not-yet-started, exhausted or unreadable voucher is refused with a
sentence naming which — four sentences, not one, because a code that has been
used up, a promotion that starts next month and a voucher somebody deleted are
three different situations with three different things to do about them.

That needed something the engine did not have. §7.1's question was "may a
subscriber refuse a save", and it was half-answered: a subscriber has always been
able to take the transaction down by throwing, because the event is dispatched
inside it — what it could not do is *say what happened*, so a refusal was a stack
trace shown to somebody who typed a code that had already been used.
`Record\RecordRefused` is that missing half, and its shape is copied from
[XIV-109]'s `DuplicateValue`: it names the field it is about, the record form
catches it exactly as it catches a duplicate, and the sentence lands on that
control with everything the person typed still in the form. A reader cannot tell
the two apart and should not be able to.

**The deriver still cannot refuse**, and nothing here weakens that: `ValueDeriver`
has no return value, no stoppable event and no flag. What may refuse is a
subscriber, at the write, for a rule that can only be checked there. A rule that
could have been a field definition, a lifecycle transition or a validation
constraint belongs in one of those, where somebody meets it before pressing save.

**Validity is checked when the use is taken, once, and never again.** An order
agreed while a promotion was running keeps its discount after the promotion ends,
because expiry is the calendar rather than an act (§5.19) and re-checking on every
save would take the discount off a draft somebody merely opened the following
week.

**There is deliberately no transition guard** ([XIV-110]). "This order's voucher
has since expired" would refuse to confirm an order the shop has already agreed
to, on the grounds that the shop took too long to confirm it.

**A voucher that is already gone cannot be named through the form at all**, and
that is the engine's answer rather than this feature's: a `reference` control
offers the records that exist, so an id naming a deleted one does not survive the
submit — the field arrives empty and the order is saved without a voucher. The
refusal above is therefore a backstop for the callers that are not the form, the
importer and the demo generator among them, and it is tested at the writer because
that is the only place it can be reached.

#### The field exists only where both modules do

The negative half of the ticket, and the one part that needed a new rule in the
engine.

An order may name a voucher and vouchers are a module a customer may not have
bought — the link is `uses` rather than `requires` ([XIV-23]), because an order
book is a perfectly good thing to keep without ever running a promotion. What that
customer must not get is a **"Voucher" control with an empty picker behind it** on
every order they ever type.

[XIV-23]'s answer works for a row *kind*: the whole kind is hidden, so the link
inside it is never drawn. A field on the record itself has no kind to hide it
with. So it is hidden the only other way a field can be — **by not being
installed** — and that turns out to be the better answer rather than the
remaining one, because a definition that does not exist is invisible everywhere at
once: the form, the list, the record page, the import, the export, the document
templates and the history all read the customer's definitions, and not one of them
needs to learn a rule. `Module\AvailableFields` is that rule, in one place, asked
by the installer and by the upgrade offer.

Three consequences, each of which had to be arranged:

- **The upgrade offer asks the same question** (§7.2.1). Without it, an order-only
  customer would be *invited* to take a link into a module they have not got —
  nothing would refuse the invitation, and they would end up with exactly the
  empty picker the install skipped.
- **A customer who buys vouchers later is offered the field** by that same screen,
  which is what it is for. Installing is a seed and the definitions are the truth
  afterwards (§6.1), so nothing retro-fits and nobody is edited without being
  asked.
- **`ModuleInstallOrder` follows `uses` edges within the requested set**
  ([XIV-72]). Installing four modules from one command line must not depend on the
  order somebody typed them in, and an order installed before vouchers is an order
  with no voucher field on it. The edge is followed only when both modules are
  being installed anyway — nothing is pulled in and nothing is refused, which is
  the whole distinction between the two words.

**The rule is narrowed twice.** It applies only to a `reference` field, and only
to one that is not scoped to a variant — because a variant is
`AvailableVariants`' business and the two rules would fight: a voucher's own
`article` link belongs to the free-article kind, and taking the field away here
would make that kind look fillable and offer it with nothing in it, which is
precisely the failure [XIV-103] wrote a test against.

#### The invoice needed almost nothing

§5.12 seeds an invoice from an order by copying its lines, and a discount is a
line — so a bill for a discounted order comes out discounted with no new
machinery, which was the point of deciding it was a line in the first place.

The one thing the invoice module had to gain is the `discount` kind itself, and
**as an ordinary kind rather than a generated one**. The seed copies the kind
along with the figures, and a value the field had never heard of would fail the
choice constraint and refuse to bill a discounted order at all. But nothing
*generates* one on an invoice: an invoice names no voucher, so a discount line
there is a copy, and from that moment it is a line with a negative price and a
label saying what it is — which is what [XIV-16] has called a discount since
before vouchers existed. That also means it stays editable and deletable there,
which is right: what it says was decided on the order, and what to bill is decided
on the invoice.

#### Deliberately not in this

**A line voucher** ([XIV-122]), which reduces a single line rather than the
document. Two things were arranged so that it fits around this rather than being
retro-fitted: the redemption counter now has a release as well as a take and both
go through the one guarded statement, and `DerivesTotals` asks a *list* of
discount sources, so a document can carry an order voucher's own line and a
reduced line at once. *Answered by §5.25*, and one of the two hooks was used
differently than expected: the counter's release is what the set diff is built on
and was exactly right, but the list of sources stayed one entry long — a line
voucher turned out to be a second **answer** from the same source rather than a
second source, because both modes are decided from one record in one save and a
second source answering separately about the header and the lines would have had
nothing reconciling the two.

**Applying a voucher directly to an invoice.** A separate question with a separate
answer, and this ticket is orders only.

**A partial invoice takes the whole discount.** The seed's `outstanding`
arithmetic draws down on quantity, and a discount line has a quantity of one, so
the first invoice made from a discounted order carries the discount and a second
one does not. That is a defensible answer and it is not a decided one; if it
matters it wants its own ticket.

**The discount line does not appear until the first save.** The live form
recomputes the totals on every keystroke ([XIV-32]), so picking a voucher moves
the figures immediately — but the *line* it moved them by is a row the deriver
invented, and a row invented mid-typing has no index in the form it would have to
be drawn into. So the totals follow the voucher live and the line under them
appears when the order is saved. Showing it live means the preview inserting rows
into a form somebody is typing in, which is a bigger change to XIV-32 than the
thing it would show.

**A cancelled order keeps its use.** See above: it still carries the voucher, it
is locked, and nobody can take the voucher off it.

**Anything about who pays for the discount.** No accounting split, no cost centre,
no reporting on promotions. What is here is a document that says what the
customer owes.

---

### 5.25 Two ways to apply a voucher (XIV-122)

§5.24 put a voucher on an order and settled that **a discount is its own line**.
This is the other way of applying one, and the first thing to say is that the two
are not in tension — which they were read as being, and which is worth writing
down because the reading was reasonable.

**A voucher has a mode, and the mode decides both where it may be applied and
what it does.**

| mode | applied to | what it does |
| --- | --- | --- |
| **order** | the whole document | **adds its own line**, as §5.24 settled |
| **line** | one line, chosen when applied | **reduces that line** |

§5.24's rule governs the order mode, where there *is* no line for the money to
belong to, so it needs one of its own. The line mode has a line already and
reducing it is the natural reading — and adding a second line beside it would be
a document saying the same thing twice. Two modes, two answers, neither
overruling the other.

Everything below is the detail that follows.

#### The mode and the kind are one field, and that is what says which combinations exist

There are two independent questions — order or line, amount or percentage — and
both change what a voucher *is*. §5.5's rule is that the variants are the variant
field's options and the fields depend on the answer, so the shape asks **one
question with four answers** rather than two questions the engine could not
relate:

    order_amount        Amount off the order
    order_percentage    Percentage off the order
    line_amount         Amount off one line
    line_percentage     Percentage off one line

Which combinations exist is therefore a **list rather than a rule**, and the list
is all four. Each is a promotion somebody runs. What is decided by their absence
is the fifth thing a `mode` field beside a `kind` field would have allowed: **an
order voucher restricted to an article**, which is not "ten francs off" at all but
a rule about which orders qualify — a different and much larger feature. The
restriction is declared on the two line variants and on no others, so the engine
refuses it by not offering it, which is exactly the work §5.5 does and validation
would otherwise have to.

A `mode` choice beside a `kind` choice was the alternative, and it is [XIV-103]'s
own "one shape with a nullable field per kind" mistake one level up: nothing
anywhere could say that an order voucher restricted to an article is nonsense,
because a variant can hide a field and a plain choice field cannot.

#### The line is chosen when the voucher is applied, and that is what reaches a custom line

An earlier revision had the voucher name an **article** and the engine hunt for
the line selling it. That cannot reach a **custom line**, which has no article —
and a custom line is exactly where a negotiated discount lands. It would have
missed the case the feature exists for.

So the line is chosen by the voucher **being named on it**: the order's line
collection carries a `voucher` reference, and putting a voucher there is the whole
of applying one. That asks nothing of the line at all, which is the property the
article-hunting design could not have.

The article reference survives as an **optional restriction** rather than as the
targeting mechanism. Named, and the voucher may only go on a line carrying that
article; empty, and it may go on any line, custom included.

*Free article* then falls out of the general rule as **"line mode, restricted to
article X, 100%"** rather than being a kind of its own, and [XIV-103]'s
`free_article` is gone rather than renamed — it described neither half of the
shape any more. What it stops doing is *adding* the article as a line: the article
goes on the order the way every other article does, and the voucher takes its
price off. One more step for whoever types the order, and in exchange the free
article is a line somebody chose at a quantity somebody chose, priced from the
catalogue, rather than a row appearing underneath at a quantity the voucher
decided months earlier.

#### The consequence that removes a guard, recorded rather than noticed

[XIV-103] made the article reference `required: true` **specifically** so that
`AvailableVariants` would hide that kind from a tenant without the Article module;
its blueprint comment called this *"load-bearing twice"*. An optional reference is
not a reason to hide a kind, and `AvailableVariants` correctly says nothing about
one. **So that guard no longer fires, and all four kinds are offered to every
customer.**

That is the right outcome rather than a regression — "ten francs off one line" is
a perfectly good voucher for a tenant with no catalogue, and hiding it would
refuse them a feature that works, which is the opposite of what [XIV-23] hides a
kind for. But the **empty picker** [XIV-23] was really avoiding is still worth
avoiding, and it is, one class over.

`Module\AvailableFields` was narrowed by §5.24 to leave *variant-scoped* fields
alone, because a variant is `AvailableVariants`' business and the two rules would
fight. That narrowing was written when the only variant-scoped reference in the
codebase was required, so the two spellings agreed. They have come apart, and the
narrowing is **halved**:

> A **required** variant-scoped reference is `AvailableVariants`' to hide. An
> **optional** one is `AvailableFields`' to take away.

Between them every reference into a module is covered exactly once, and nothing
overlaps. The kind is offered to a customer with no catalogue; the restriction
simply is not a field they have.

The same class had to learn about **collections**, for the same reason and with no
new argument: an order *line* may name a voucher just as an order may, and §5.1's
claim is that a shape is a shape. Both places a definition is born now ask it —
the installer for a collection installed with its module, and the upgrade offer
(§7.2.1) for a field offered afterwards. Without it every order line in a tenant
that never bought vouchers would carry a picker with nothing behind it, which is
precisely what §5.24 spends its last section preventing on the header.

`VoucherWithoutArticlesTest` is where the loss is recorded rather than discovered:
the method that asserted two kinds of three now asserts four of four, and a second
one asserts that the restriction is not installed at all.

#### The reduction is a column, and the recipient can check it

A reduced line has to *say* it was reduced. §5.24 refused to let a document read
`1 × Widget @ 100.00 = 90.00` in the other mode and the same objection holds here:
that line asks its reader to take the arithmetic on trust. So the line gains a
derived **discount column**, and the line total under it is what is left:

    Consulting   3 × 66.65    199.95   −29.99   169.96

`LineTotals::$lineDiscount` is how a module names it, beside the `discountKind` it
already named for the other mode. A module may have one, the other, both or
neither, and an invoice is the interesting case: it has the **column and no
kind** — it can carry a reduction the order worked out without anything on it
granting one.

**What protects it is the derived flag, and here that is the right precedent
rather than the wrong one.** §5.24 found that a subtotal protects *a column, not a
row*, and needed three mechanisms because the engine owned the whole discount row.
A line voucher reduces a row the customer owns and edits freely — their own
article line — so a column is exactly what needs protecting, and the flag that has
done that since [XIV-20] does it. The deriver restates the column from the voucher
on every save, so a request forging a smaller figure into it has that figure
overwritten before anything is stored, and the form draws it disabled on top of
that.

#### Two passes, and no new arithmetic

`DerivesTotals` walked the rows once, because everything a discount did was
appended underneath them. A line reduction is not appended: what a line
contributes to the subtotal above it and to its rate's VAT base is not known until
the seam has answered, and the seam cannot answer until it has seen the lines.

So the loop is split at exactly that point — first pass works out what each row
*charges*, the seam is asked once in between, second pass takes the reductions off
and places the money. The rounding rule, the subtotal rule, the per-rate grouping
and the treatment of a row the engine wrote last time are the same statements in
the same order, one indentation level further down.

**Before VAT in both modes**, which is what makes both intelligible: a reduced line
joins its own rate's group carrying the reduced figure, so the tax is computed on
what was actually charged. And a line discount needs **no apportionment at all**,
which is the whole difference from §5.24's order voucher: it stays on its own line,
so it joins exactly one rate by being part of it. [XIV-116]'s rule about
remainders never crossing a rate boundary is satisfied by there being no share to
distribute.

**One seam, not two.** `Money\DocumentDiscounts` still has one method. A line
voucher turned out to be a second *answer* from the same source rather than a
second source, which is the better outcome: both modes are decided from one record
in one save, and a source answering separately about the header and the lines
would have had nothing anywhere reconciling the two. `Discount` carries `off` for
the document and `perLine` for the lines; `DiscountLine` is gone with the
free-article kind that was its only producer.

**When both are on one document the line reductions happen first**, and an order
percentage is a percentage of what is left. That is the only reading that is not
arbitrary — "a tenth off this order" is a tenth of what the order costs, and what
it costs already reflects the tenth somebody negotiated off one line. It also
keeps the two from ever adding up to more than the document charges.

#### The bound, decided rather than emergent

- **A percentage is capped at 100** by the field, unchanged from §5.19, and for the
  unchanged reason: a 120% voucher is a document that owes the customer money.
- **A fixed amount larger than the line is floored at the line**, not refused. A
  negative line is money owed back and nothing downstream hands any over; a
  refusal would be the engine declining an arithmetic it can perform, when the
  shop said "twenty off this", the line was worth fifteen, and fifteen off is
  plainly what was meant. It is §5.24's document-wide cap reached one level down,
  and a line that charges nothing or less than nothing takes nothing off.

#### One voucher on several lines is one use

The question this ticket left open, and the answer is [XIV-104]'s invariant
unchanged rather than a new one: **the count is the number of documents that carry
the voucher.** An order with `HALF-OFF` on three of its lines is one order carrying
`HALF-OFF`, so it takes one use.

"One use per line" loses on what a limit *means*. A customer told "this voucher may
be used five times" reads that as five customers, or five visits — a promotion
whose budget is five. Under per-line counting it is spent by the first shopper who
buys five things, and the five-customer promotion ends at the first customer. The
limit would be counting keystrokes rather than deals.

It also keeps **one counter**. Per-line counting needs a use to be a *(voucher,
line)* pair, which a counter keyed by `voucher_id` cannot express — so it would
have meant a second table with a second rule in it, and two counters that must
agree are two counters that will not.

**Which makes the diff a set.** [XIV-104] read one field's before-and-after because
a voucher could only be in one place; there are now many places, so what is
compared is the set of vouchers the document carries, before and after. Gaining
one takes a use, losing one gives it back, swapping does both, deleting the
document gives every one back — and **moving a voucher from one line to another
does nothing at all**, which is what the set buys and a per-field diff could not.
Dragging it down a row is two field changes and no change to the document; a naive
reading would release and re-take, and on a single-use voucher at its limit would
refuse a save that changed nothing about how many times the voucher had been used.

The "before" set is reconstructed from `RecordChanges` rather than read, because by
the time the subscriber runs the rows are already written: a row that was added is
taken out, a row that was removed is put back with the voucher its summary
remembers, and a row whose voucher changed gets the one it came in with. The
history entry and the subscriber read the same facts, which is what makes the
reconstruction trustworthy rather than clever.

One small thing had to be added for the delete path. `RecordChanged` is dispatched
*after* the delete inside the same transaction (§5.2), so the one moment at which
what a record carried matters most is also the one moment its rows are behind a
tombstone. `RecordRepository::findChildren()` gained the `includeDeleted` flag
`find()` already had, and every ordinary caller sees what it saw before. This was
found by a test rather than by reading: the first version released nothing on
deletion and said so.

#### Where the mode is enforced, and why it is there

A line voucher put on the order, or an order voucher put on a line, is **worth
nothing in the deriver and refused at the write** — with a sentence that names the
fix rather than the rule, because it is a mistake with an obvious one. A voucher
on a line that breaks its article restriction is refused the same way, by name, so
that whoever is holding it knows where it does work.

Both halves are needed. A refusal cannot happen in a deriver (§5.24), and the
deriver runs on every keystroke of a form somebody is still typing into — so the
rule would fire while they were halfway through choosing. And silently discounting
nothing would put a figure on the page that nobody could explain. What the deriver
must not do is *guess*: an order voucher dropped on a line is not "probably meant
for the order".

It could not have been a field definition or a validation constraint either, which
is §5.24's own test for anything landing at the write: whether this voucher may go
on this line depends on the **voucher's** kind and the **line's** article, and a
constraint on either record cannot see the other.

#### Deliberately not in this

**A voucher on two documents at once is still two uses**, and a cancelled order
still keeps its use. §5.24's edges are unchanged.

**Nothing shows a customer which of their lines a voucher would be worth most on.**
Choosing is a person's job here.

**The reduction has no reason on it.** "15% off, agreed with the customer on the
phone" is a note, and the line's description is where a note goes.

**A line still carries at most one voucher.** Two vouchers on one line is a stacking
rule — which comes first, whether the second is a percentage of the first's
result — and nothing here has an opinion about it. The field is a single reference,
so the question cannot be asked yet.

**And one cost is accepted rather than fixed.** The *Discount* column names no
module, so `AvailableFields` has no opinion about it and an installation with
orders and no vouchers gets a column nothing can ever fill in. Hiding it would
need a rule saying "this field is only writable while that other field exists",
which is one module's internals living in the engine — and §5.4 already gives the
better answer, since the field editor removes a field somebody does not want. It
is asserted in `OrderWithoutVouchersTest` so that it stays a decision rather than
becoming a discovery.

---

### 5.26 A list a customer keeps, beside the fields that use it (XIV-127)

Found by reading `OnePlc/PLC_X_Tag`, which is richer than its name: three tables
holding a *dimension a customer invents*, its values — each with a colour, an
icon and a parent — and a link onto any entity. What it solved that Xivi did not
is one thing said three ways.

A `choice` field owns its own options (§5.4), which is right for a closed set
belonging to one field — an order's status, a contact's kind — and has three
consequences for a set that belongs to the *business*:

- **options are bare strings.** A status is text in a column and nothing is
  visible at a glance;
- **each field's list is its own.** *Region* on a contact and *Region* on an
  order are unrelated strings that drift apart the moment somebody edits one, and
  nothing anywhere can tell they were meant to be the same list;
- **nothing tidies them.** The editor makes it easy to accumulate `Zürich`,
  `Zurich` and `zurich`, and there was no operation that turned them back into
  one.

#### Not a module, and the reason is §3 rather than taste

The obvious alternative was a module — the unit a customer installs, with a store
entry, a price, permissions and records people browse. It loses on two counts and
the second is structural.

**Nobody browses a region.** A list has no record page, no follow-ups, no export
and no history; everything in §6.3's shape would have been a field left empty.

**A module may not depend on another module** (§3). A list that *contact*,
*order* and *invoice* fields all point at can therefore only live where all three
can see it, which is core. A "lists" module would have been a module every other
module secretly required — precisely the shape deptrac exists to refuse. This is
§5.20's argument for putting the seven units in `Xivi\Core\Field\Units` rather
than in the article module, one level up and with rows instead of a constant.

So: **a core concept beside field definitions**, two tables in the customer's own
database, edited on a screen of its own under `/lists` and reached from the menu
under somebody's own name rather than from any module's tab. Admin only, on
§5.4's reasoning: changing the vocabulary several modules share is the same kind
of change as changing what a module *is*.

#### An option on `choice`, not a field type — and §5.21's objection is answered

§5.21 argues, at length and correctly, that an option is the wrong shape when
ticking it *reinterprets* everything already stored: a checkbox that turns a
`textarea` into Markdown changes what every value already in the column means, at
once, with no migration and nothing on any screen to say it happened. [XIV-113]
is told there to follow that answer. This ticket does not, and the difference is
not special pleading:

- **nothing about the value changes.** A shared list stores the same kind of
  string in the same column that a `choice` field's own options do. There is no
  storage shape to reinterpret and no escaping decision anywhere near it — which
  is the escape clause §5.21 wrote for itself: *a case where the option changes
  neither what the value is nor how it must be escaped*;
- **almost nothing that follows differs.** §5.21's test is how many things
  diverge, and for `markdown` it counted four. Here the widget is the same
  select, the operators are the same, the filter is the same and the storage is
  the same. What differs is where the labels come from and whether a value can
  carry a colour, and both are answered inside `ChoiceFieldType` without a caller
  learning anything;
- **the retroactivity that *is* real is refused rather than allowed.** Pointing a
  populated field at a list whose entries do not include what its records hold
  would leave those records failing their own field's validation — §5.4's trap.
  So it is counted first and refused with the values named
  (`valuesAreNotOnTheList`), in both directions, and what survives that check is a
  change that reinterprets nothing, because every value still means what it
  meant.

A field type would also have cost the thing this ticket is for. A customer with
three modules each carrying a "Region" choice field wants to **unify** them; with
a new type that is three fields recreated and three columns moved by hand.
§5.21 accepted exactly that cost for `markdown` because there was no safe path
from a `textarea`. Here there is one, so it is built.

**It fits XIV-144's shape rather than sitting beside it.** `ChoiceFieldType`
gains `PointsAtAList` — the sixth capability, and the first that adds an *answer*
rather than a question — one line in `FieldController::PER_TYPE` and one
`<select>` in the field table. Nothing else in that class learned that lists
exist.

#### One question, two answers

XIV-144 gave a type a way to say what it cannot work without: `needs()`, a flat
list of options, every one required. A `choice` field's values may now come from
its own options *or* from a list, and both are complete answers to one question —
so `needs()` became a list of *questions*, each carrying the options that answer
it, and the rule is still one sentence: **every question answered, by
something.**

Flattening was considered and is wrong in both directions. Naming both as
separate needs says a choice field needs both, which would refuse every
definition in every tenant. Naming only `choices` says a field pointing at a list
is unfinished, which is the badge XIV-144 added and the wrong thing to show. The
nesting is one axis, and it is the axis that was missing.

`configurable()` reads it strictly: **every** way of answering must be drawable.
A type offering two answers of which the editor can ask for only one *is*
finishable through the form, so a laxer reading would pass — and the second
answer would be unreachable from the only screen there is, which is XIV-144's
silent gap one level in.

#### Colour: eight, and the boundary is not aesthetic

A hex per entry was the obvious answer and it fails on one fact about §8.3: every
page is Bootstrap 5.3, which has **two themes**, and a colour a customer picked
against a white page is a colour the dark one still has to read. `#f5f5f5` for
"archived" is invisible at night, and nothing would report it — the customer who
picked it is not the customer who reads it.

So the palette is exactly **the colours the theme has a dark answer for**, which
is the eight Bootstrap redefines under `[data-bs-theme=dark]`. Eight is therefore
an answer rather than a round number: a ninth would be a colour with no dark
counterpart, which is the thing being avoided.

Two things follow that are worth naming. `text-bg-{tone}` is **not** used, though
it is the obvious Bootstrap badge class: it computes a readable foreground
against a *fixed* brand colour, and the brand colours are not redefined in dark
mode — so a chip built from it is legible and identical in both themes, which is
not what "survives dark mode" means. And the icons are a **bounded set of
twelve**, for the same two reasons plus one: the name is interpolated into
`class="bi bi-…"`, so a free string would be a customer's typing in the page's
markup, and a *wrong* free string renders nothing at all, because Bootstrap Icons
has no fallback glyph.

The chip is drawn through `value_badge(field, value)`, which asks the **field**
rather than its type — the third function on `FieldDisplayExtension` with that
shape, after `record_link()` and `formatted()`, and written that way for their
reason: a page switching on `field.type == 'choice'` is a page to edit the next
time something has a colour. Null means "draw this the way you always did", which
is what every field in every tenant answers until somebody colours an entry.

#### Hierarchy: one level, and it changes the picker and nothing else

A parent gives "category and sub-category", which is what a customer means by one
and what the previous system's lists were used for. It is **one level deep**:
arbitrary depth buys a tree widget, a cycle check and a recursive query, and a
parent that must itself be a root makes cycles impossible by construction rather
than by a guard.

**What a hierarchy does to a filter is nothing, and that is the decision.**
Filtering on "Switzerland" matches records holding Switzerland, not records
holding Zürich. The argument is not about SQL: the count printed beside an entry,
the refusal that reads that count and the merge that acts on it all count the
value *exactly*, and a filter that counted differently would be a second notion
of "records holding this" free to disagree with the first — which is the drift
this codebase refuses everywhere else. If "including everything under it" is ever
wanted, it is an **operator of its own** (§5.3), not a change to what `=` means
depending on which field it is applied to.

So a hierarchy is read rather than queried: the picker indents a child under its
parent, and the list page shows the tree.

#### Merge, which is XIV-91's backfill wearing a different hat

Merging "Zurich" into "Zürich" rewrites a value on **every record holding it**,
across every module and every collection whose fields point at the list, and
there is no way back — afterwards nothing anywhere remembers which records used
to say the other thing. That is §5.10's backfill exactly, and its answer
transfers rather than being re-derived: **say what will happen, how many records
it touches, and that it cannot be undone, before doing it.**

Four things it inherits, and each was decided once:

- **a page of its own.** Everything else on the list screen is instantaneous and
  reversible; in a row of that table the change with the most consequences would
  look like the cheapest one on the page;
- **the plan is per field, and keeps the fields with nothing in them.** "Orders →
  Region: none" says this reaches orders too and that today there is nothing
  there, which is a fact about the *change*; dropping it would let the page be
  read as "this only touches contacts", which is a fact about this afternoon;
- **the confirmation is required in the controller**, not only as a `required`
  attribute. An attribute is a courtesy to somebody using the page and nothing at
  all to a form posted around it;
- **no history entry, and `updated_at` is left alone.** A merge is one
  administrative act against a column, not four hundred edits to four hundred
  records; stamping every order as changed today in the act of saying two regions
  were always one is the confusion §5.10 objected to. `replaceValue()` is
  `@internal` to the merge on exactly `setValues()`'s terms.

**Collections are in it** (§5.1), and that is not a nicety: a merge that rewrote
the module's rows and skipped the order lines would leave half of somebody's data
saying "Zurich" for ever, with nothing anywhere to say so.

The figures reported afterwards come from the statements rather than from the
plan, because a record saved between the two is one more record rewritten and the
sentence somebody reads afterwards should be about what happened — XIV-91's split
again, for its reason.

#### Removing an entry records hold: refused, as §5.4 requires

§5.4 states the rule in a form that reaches both mechanisms — *a list somebody's
records point into cannot lose an entry while they point into it, whether the
list lives in the field or beside it* — and this is the "beside it" half,
implemented as the same refusal reached the same way. What is different is the
**reach**, and it is why the message names the fields as well as the values and
the counts: removing an option from a field's own list breaks records in that
field, and removing an entry from a shared list breaks records in modules the
person doing it is not looking at.

The same rule one level up: **a list cannot be deleted while any field points at
it**, with those fields named. And one this ticket adds: **an entry that other
entries sit under cannot be removed** while they do, because a customer removing
"Switzerland" has said nothing at all about what should become of "Zürich" and
"Bern", and choosing for them would be a structural change arriving as a side
effect of a different one.

**Retirement is not built, and here is the plain answer §5.4 asked for.** Keeping
an entry valid for the records that have it and out of the picker is still the
genuinely better answer for the wholesaler with four hundred old orders. It is
not built because §5.4 set the condition — *it has to arrive for both mechanisms
at once* — and meeting that condition is a change to `choices` handling, to this
list, to every reader of both, to two pickers, to two refusals and to the export:
a third state per entry that every reader has to understand. Building it here
would have shipped half of it and left a customer to find out which half they
were using. What this ticket does instead is remove the *commonest* reason people
ask for it: `Zurich` and `Zürich` become one entry rather than a dead one
somebody wants hidden.

**Per-option provenance stays undone, and the reason turned out to be better than
"not yet".** XIV-144 refuses *any* removal from a module's own `choice` field
because the definition records which **fields** came with the module and not
which **options**, so a customer's own eighth unit is indistinguishable from the
seven the installer wrote. Modelling entries as rows *would* let a shared list
record who added each one — and it does not, because on a shared list the
question does not arise: **nothing seeds a list.** Every list and every entry in
one is the customer's, so a provenance column would hold one value. Narrowing
XIV-144's refusal is therefore still open and is still XIV-144's shape of problem
— options inside a field definition — rather than something this ticket can hand
it.

#### The relationship to [XIV-113], settled

[XIV-113] asks whether a field may hold **several** values. The previous system
answered it in the same breath as this one, through a many-to-many link table,
and that is the half deliberately left here: **one answer to that question, in
one place.**

The settlement is that **a shared list is what a multi-value field points at.**
This ticket builds no link table and no multi-value anything; a field takes one
value out of a list exactly as a `choice` field always has. Whatever [XIV-113]
builds — and §5.21 tells it to build a **type**, because a `multiple` option
would change the storage shape of the value, which is the retroactivity argument
at its strongest — points at the same `value_list` rows through the same `list`
option, declares the same `PointsAtAList` capability, and inherits every refusal
here for free: `ValueListUsage` finds a field by its type's capability rather
than by its type's name, so the counts, the removal refusal and the merge follow
without being written again.

`ValueListReachesEveryTypeTest` is what keeps that promise honest. It asserts,
over the container's own registry, that every type declaring `PointsAtAList`
names `list` among its answers — because the scan reads exactly that option, and
a second type keeping its key anywhere else would be invisible to the counts, to
the refusal and to the merge, silently. It plants the violation too, on XIV-60's
lesson.

#### What is deliberately not here

- **retirement**, argued above, and with it any way to remove an entry that
  history holds;
- **a list a module can seed.** A blueprint writes a customer's *definitions*
  (§6.1) and a shared list is not one, which keeps that rule intact and keeps
  provenance a non-question. A module wanting a vocabulary still ships a `choice`
  field with options, as §5.20 and §5.22 do;
- **a module's own `choice` field pointed at a list**, refused outright: an
  order's `status` options are the states its lifecycle moves records between,
  and a list the customer maintains is a list the customer can take entries out
  of, so allowing it would be `optionsAreTheModules()` defeated by a longer
  route. A customer wanting their own vocabulary on a module's field adds a field
  of their own and points that at the list;
- **more than one level of nesting**, and any operator that reads one;
- **colour and icon anywhere but the record list and the record page.** A
  document and an export get `display()`, which is the label — the same decision
  §5.21 made about a formatted field in a table cell.

---

### 5.27 A period as one thing, and two of them that cannot overlap (XIV-136)

Prompted by reading a **care home** built on the previous engine, and the question
was never whether Xivi should ship those modules — it should not — but whether
Xivi could *express* them, which is the real test of an engine that claims a
customer can build their own shapes.

Mostly it could. A resident is a record, a room is a record, one references the
other, care notes are a collection, a lifecycle moves somebody from *enquiry* to
*resident* to *left*. What it could not express is the thing a care home and a
hotel are both made of: **a period, and the rule that two of them cannot
overlap.**

Two `date` fields hold the dates and not the relationship, so nothing in the
engine can answer *who is in room 3 today* or *is this room free next week* — every
module would write that query itself, slightly differently each time — and *two
bookings for one room on one night* cannot be prevented at all. Everything else a
vertical needs is a field, a reference or a collection; this is engine work by
definition, and it is why the engine could not express a hotel.

#### A type, not a pair of fields the engine understands as belonging together

The alternative was two `date` fields plus a rule somewhere saying they are one
value. It is more flexible on paper — every field capability already built would
apply to each half — and it fails on the thing this ticket exists for: **overlap
prevention needs something to constrain, and a pair of loose fields gives it
nothing to hold.** A constraint would have to be described in terms of two field
keys that nothing guarantees still exist, still hold dates, or still belong
together; the first person to remove one half would leave a rule about a field
that is not there.

So a period is one value in one key. `DateRangeFieldType` and
`DateTimeRangeFieldType` are the two types; `Period` is the value they hand back.

#### Stored as one ISO-8601 interval, which is what kept the rest of the engine still

`2026-08-01/2026-08-05`, or `2026-08-01/..` for an open end. The obvious
alternative — a JSON object with two keys — was rejected on evidence rather than
taste: **a stored value that stops being a scalar is a change to five things at
once.** The spreadsheet export writes cells; the history diff compares stored
values; the importer reads one column per field; `IS EMPTY` compiles to a test on
`data ->> 'stay'`; and the accessor every other part of the query layer builds is
`data ->> key`. As one string none of them learned anything, and it sorts by start
date as text — the same property §5's date fields are kept in ISO for.

**Two field types rather than one with a precision option**, and the engine's own
seam decided it. `FieldType::comparableSql()` is handed an accessor and nothing
else — no field, no options — because that is what stops the query compiler
growing a switch on type. A precision kept as a per-field option would be
invisible exactly there, at the one point where the SQL has to know whether it is
building a `daterange` or a `tsrange`. Making it the type answers a second
question for free: §5.4 refuses to change a field's type because stored values may
not survive one, and a precision somebody could flip on a populated field *is* a
stored value not surviving.

#### The end bound is exclusive, and that is the decision the feature turns on

`[from, until)`. **`until` is the first moment outside the period, not the last
one inside it.** A stay from the 1st to the 5th occupies the nights of the 1st to
the 4th, the room is free again *on* the 5th, and the next booking may start that
day.

Three reasons compound, and `Period`'s own docblock has them: it is the only
bound that means the same thing at both precisions, Postgres' `daterange` already
canonicalises to `[)`, and the arithmetic comes out with no ±1 anywhere.

What it costs is that a tenancy whose last day is the 5th is entered as ending on
the **6th**, which is genuinely surprising the first time. That is paid where it
is felt rather than argued away: the field's own help text says what the second box
means, and `PeriodFieldTest` asserts the boundary day in both directions at both
precisions, with failure messages that state the bound rather than the line number.

**This is deliberately not an option.** A field where each customer chose the
bound would mean the same two dates meaning different things on two modules of one
installation, and a constraint whose meaning depends on a setting somebody can
change under records that already exist.

Note the contrast with §5.19, which is not an inconsistency: a voucher's
`valid_from`/`valid_until` are **two fields**, both inclusive, describing when a
*rule* applies rather than a stretch of time something occupies. Nothing overlaps
anything there, and nothing needed to.

#### Open at the end, never at the start, and never by accident

A tenancy with no agreed end is ordinary and `[from, ∞)` says it exactly. A period
with no *beginning* is not: everything this holds starts on a day somebody can
name, and unbounded-below would be a value overlapping every past period ever
recorded, arrived at by leaving a box empty.

The interface has three controls — from, until, and **a checkbox that says there is
no end** — because two cannot answer the question. An empty end box is also what a
half-filled form looks like, so the two are told apart rather than guessed at: a
typed end always wins over the tick (dropping something somebody typed is the one
outcome no reading justifies), a ticked blank is `[from, ∞)`, and **a blank nobody
ticked is refused** with a sentence saying to fill it in or tick the box. That
refusal is §8.3.1's argument applied to a blank: a control meaning two opposite
things depending on what somebody intended is a control that reports nothing.

#### The constraint is XIV-109's finding one level harder, and its conclusion transfers exactly

*Is this room free next week* is [XIV-109]'s read; the booking that follows is
its write; between them is the millisecond in which the other guest books, and
**no amount of care in PHP closes it** — so this engine has no application-level
check for overlap at all. That is worth saying plainly, because the absence looks
like an omission: a validator here would catch almost everything, would tempt the
next reader into believing it were the rule, and would still let two guests into
one room on the afternoon it mattered. The constraint is not a second opinion
behind a validator; it is the only opinion.

`EXCLUDE USING gist` is the range equivalent of that ticket's partial unique
index: a unique index whose equality has been replaced by an operator per column.
The predicate is partial three times over — soft-deleted rows, a record with no
scope and a record with no period each occupy nothing — and it is built in the
transaction that writes the definition, exactly as the unique index is.

#### What a period is exclusive *within* is configurable, because there is no global answer

"No overlaps" is never a statement about a module. It is a statement about a
**resource** — one room, one machine, one carer, one van — and which field names
the resource is exactly what the engine cannot guess. So it is a per-field option
(`exclusive_within`), on the same terms as [XIV-114]'s per-field country, and that
option is also the on switch: a period field naming no scope has no constraint,
which is what a project's duration and an employment's dates want, since those
overlap each other constantly and always should.

It is the **sixth** entry in the capability list §5.4 describes (`ExcludesOverlaps`),
and it cost what the third, fourth and fifth cost: one interface, one line in
`FieldController::PER_TYPE`, one control in the field table. No branch was added
anywhere.

Three things it deliberately does not do. **A composite scope** — "one room *and*
one bed" — is expressible in Postgres and is not built, because the cost is not in
the database but in the editor and in the refusals, where "which of these four
fields do your records conflict on" is a sentence nobody can write. **There is no
"nowhere"**: a module where no two periods may overlap at all would be a module
holding exactly one resource, and the honest expression of that is a scope field
with one option in it. **A collection's period cannot be made exclusive**, on the
same terms `unique` is refused there: within one parent record and across the whole
table are different rules and the engine will not guess (§7).

Switching it on over records that already overlap is **refused with the pairs
named** — `#41/#58 in "3"` — which is XIV-109's courtesy about duplicate values,
one feature along. Pairs rather than values, because an overlap is a relationship
and neither record is wrong on its own.

#### The awkward part: an index expression must be immutable, and dates do not parse immutably

`(data ->> 'stay')::date` **cannot be indexed**, because `date_in` is only
*stable*. This is the one place where the JSONB storage model costs something
real, and it is worth recording because the obvious spelling fails with an error
most people meet for the first time here. The way out — building the value from
integers at known offsets in a fixed-width ISO string, named once per tenant as
`xivi_date_range(text)` and `xivi_datetime_range(text)` — is in `PeriodSql`.

**The cost of that is a rule.** Postgres does not re-evaluate an index when a
function it was built over changes, so editing `PeriodSql` in place would leave
every existing constraint enforcing the old rule silently. A change there is a new
migration that redefines the function *and* rebuilds every constraint over it.

**Datetimes are `tsrange` over naive UTC timestamps rather than `tstzrange`**, and
that is not a shortcut: everything the engine stores is UTC (§8.4.4), so the wall
clock in the stored string *is* the instant. `tstzrange` would need
`make_timestamptz`, which reads the session's `TimeZone` and is therefore not
immutable — the index would depend on the setting of whichever connection last
wrote to it.

#### Filtering answers the question in the query

One operator, `overlaps`, and it is the only comparison in the engine that is
about two stretches rather than a point. `Equals` on a period asks whether two
stays are the identical stay, which nobody wants to know; `GreaterThan` would have
to mean "starts after" or "ends after" and nothing can say which.

The compiler emits `<range> && <range>` and knows nothing about periods, because
**it applies the type's own `comparableSql()` to the bound parameter as well as to
the column** — the method is a pure text→SQL transform, so one definition of "a
period" is used twice and there is no second parser to disagree with the first. A
lone date is read as that whole day, at both precisions, which is what makes
`filter[0][op]=overlaps&filter[0][value]=2026-08-19` — *which of these overlap
today* — a URL somebody can type.

That it is answered *in* the query rather than in PHP is asserted by arrangement
rather than by result: sixty records, a page of twenty-five, and the three that
overlap are the oldest, so a filter applied after loading a page would return
nothing at all.

#### Timezones: one more implementation of a seam that already existed

A date range is zoneless, like a `date` field and for the same reason. A datetime
range is stored in UTC and read in the reader's zone, which is [XIV-83]'s chain —
and the chain had to reach somewhere new. XIV-83 rendered every moment by turning
one knob on Twig, which works for anything that arrives at a template as a
`\DateTimeInterface`; a period arrives as *one value that a field type turns into a
sentence*, in PHP, before Twig sees anything but a string.

So core declares `ReaderTimezone` — the fifth instance of the seam
`InstanceCurrency`, `DefaultPaymentTerms`, `DefaultVatMode` and `InstanceRegion`
keep — and the application answers it by **delegating to `DisplayTimezone`**,
which is the same object the request listener asks. §8.4.4's own argument about
`ProfileRegion`: a second *reader* of one setting is the same mistake in cheaper
clothes, and here it would show a booking's own times in one zone and the "changed
at" beside them in another, on the same screen, with nothing looking wrong enough
to check.

The zone decides more than the clock. A slot stored `22:00Z–23:30Z` is Tuesday
night in Greenwich and Wednesday morning in Zurich, so it decides **which day the
period is filed under** — which is exactly where §8.4.4 says this goes wrong when
it goes wrong. A datetime period whose ends fall on one day *in the reader's zone*
is written with the date once; that collapse happens in Zurich and not in
Greenwich.

#### What this is not

**Not a booking module.** No availability search, no calendar, no pricing by
season, no "next free room". [XIV-135] is where a domain like that would live and
is a placeholder for a reason.

**Not a rewrite of anything.** No existing `date` field moved, no tenant's data
was touched, and the migration creates two functions and an extension and not one
row. A customer who has been holding *from* and *until* in two date fields keeps
them: converting would be a type change, and §5.4 refuses those because stored
values may not survive one.

**And demo data had to be built against its own constraint.** The generator writes
in batches inside a transaction and `tenant:reset` destroys before it builds, so
one pair of overlapping generated periods costs the whole tenant — the hazard two
tickets met this week from other directions. `sample()` therefore places each
record in its own week (or its own pair of hours) off the sequence, exactly as
`PhoneFieldType` uses the sequence to avoid colliding on a unique index, and never
offers an open end on an exclusive field, because a period with no end covers every
later one.

The point of all of it is that a vertical can now be **built** on this engine — a
care home, a hotel, a workshop, a hire fleet — not that Xivi ships one.

---


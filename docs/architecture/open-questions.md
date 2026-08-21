## 7. Open design questions

Not yet decided, or only partly. Decide deliberately rather than by accident.
Numbering is stable, because code cites these by number; a settled question
keeps its slot and gains a one-line answer rather than being removed.

1. **Veto events.** May a subscriber cancel a host action? The answer is
   asymmetric. A module takes part in a save by *deriving* (§5.9;
   `ValueDeriver` has nothing to cancel with, on purpose) and refuses through
   rules it *declares*, the lifecycle guards of §5.8. A runtime veto from a
   subscriber stays open. XIV-88 examined customer-authored expressions across
   every candidate and declined them; §5.8 has the argument.

2. **Metadata migration.** What happens to stored values when a definition
   changes. Decided so far:

   - **Deleting a field** removes the definition and keeps the values.
     Reversible, destroys nothing.
   - **Switching on a rule** that existing records would fail is refused; the
     editor counts first and names the offending values.
   - **`unique`** is a partial unique expression index on `data ->> 'key'`
     (`WHERE deleted_at IS NULL AND … IS NOT NULL`). `UniqueIndex::follow()`
     creates and drops it in the same transaction as the flag it enforces. The
     validator stays in front for the readable message; the index is what is
     true. Empty values do not collide, and soft-deleted records do not reserve
     values. Existing duplicates refuse the flag, with the shared values named.
     It is built in-transaction rather than `CONCURRENTLY`, which fails soft
     and leaves an `INVALID` index enforcing nothing. `unique` on a
     collection's field is refused outright, because whole-table and
     within-one-parent are different rules and the engine will not guess. That
     half is still open. **`unique` on a field holding several values is refused
     too** (XIV-113, §5.29), and for a sharper reason: the expression is the
     array's own text, so the index would build and silently mean "no two
     records hold the same whole set".
   - **Additive upgrades** are §7.2.1, built. **A module's own field
     options** are §7.2.2, read live and never written.
   - **A field changing type** is built, below.
   - **Purging a removed field's values** stays open, deliberately beside type
     change and never inside it. One is data loss somebody asked for, the
     other is data loss nobody did, and a purge must never ride along on a
     conversion.

   *Type change (XIV-146).* **Legality is the tenant's data's to decide, not
   a table of type pairs.** Every value is converted through the new type's
   own reading (`toStorage()`) and judged by its own constraints, behind a
   dry run that reads the whole column rather than a sample. A change every
   row survives happens; a change any row fails is refused with the count and
   the values named. Emptying the failing rows is the customer's explicit
   second choice, taken with the report in front of them, and refused
   outright on a `required` field. **Whether the door is one-way is said
   before it closes**, computed the same way legality is: the converted value
   is read back through the old type, and the change is reversible exactly
   when every row lands on what it holds today. A `unique` breach is reported
   from the converted values, never attempted and rolled back; the index
   comes down for the rewrite and back up with the definition, because a row
   converted early can collide with one not converted yet. **Every value the
   run converts or empties goes to the record's history first**, under a verb
   of its own, which is the condition on which a lossy conversion happens at
   all; that is the one place the old spelling survives. It is not
   `RecordWriter`'s diff, because that reads both sides with the type as it is
   now and a type change is exactly a change to what that is. A **derived**
   field is refused, since the engine fills it. A module that derives has
   every touched record re-derived afterwards, at module granularity, because
   §5.9 never says which fields a deriver read. A **shipped module may not
   request a conversion**: every write in §7.2.1 is an insert, and a
   conversion restates values somebody typed. The module may make it obvious;
   the customer is who makes it happen. `contact.phone` is the worked example
   (§5.23).

3. **Query layer.** Built, see §5.3. Still open, and narrower than the question
   was: `OR` between conditions, and keyset paging. XIV-113 met the first of
   those and did not build it: a field naming several records filters by
   containment, one value at a time, and "has any of these" is left unoffered
   rather than compiled into a disjunction one operator can see and nothing else
   can (§5.29).

4. **Doctrine multi-tenancy hazards.** Web requests are safe by construction,
   because the runtime is deliberately not a worker (§9.2). Any process that
   serves several tenants in sequence, console commands today and consumers if
   they ever arrive, goes through `TenantSwitcher`, which drops the identity
   map, the metadata cache and the connection on every switch, and **closes**
   the connection. A fan-out holding fifty connections blocks the
   `DROP DATABASE` an operator is running; XIV-94 turned that from a blocked
   operator into a collection killed mid-count, which is quieter and not
   better. **It also empties the two debug logs whenever a tenant is left**
   (XIV-162): `doctrine.debug_data_holder` keeps every statement with its bound
   parameters and a backtrace, nothing in a console process ever empties it,
   and a walk therefore grew by a tenant's worth of it per tenant: 120 kB each,
   measured over 300 rehearsal tenants, against a 256 MB limit. The reset
   lives at the switch rather than in each command because six things walk the
   fleet and the number only goes up; it fires only when a tenant is actually
   being left, so a web request entering one from nothing keeps what the
   profiler is there to show. Debug-only: `bin/deploy` runs with debug off,
   where neither service exists and the walk was always flat. Still open for
   shared caches.

5. **Authorization model.** Settled, see §8.4. The record-level half is a
   WHERE clause, never a post-load check or a voter; a list is twenty-five
   records and a total, and a voter is handed one subject. Collections inherit
   through their parent (§5.1). Still open: whether a reference picker should
   be scoped, and what a permission means for a module the customer has since
   uninstalled.

6. **Links between records.** Built. A link **is** a field type: `reference`
   stores the target's id, and the widget, the display and the filtering come
   from the type. It works across modules too (XIV-13). The reverse list
   crosses modules and groups by the module doing the pointing. A filter may
   take exactly one hop through a link, compiled as `EXISTS`, and the target
   module's own permissions apply inside the subquery; no grant there matches
   nothing, which is an answer rather than an error. A link into an
   uninstalled module, and a link to a deleted record, read as `#id` text. A
   second type names *several* records (XIV-113, §5.29); it stores an array,
   filters by containment, is refused an ordering and a `unique` flag, and is
   counted by the same reverse list, which asks the type which comparison finds
   it.
   Deleting a pointed-at record is allowed and the link goes stale, because
   records are soft-deleted and nothing is destroyed. The *name* a reference
   shows is read unscoped, since an order whose customer says `#14` is
   unusable; the *anchor* is offered only where the reader may open the
   target, because a record they may not view answers 404 (§8.4). Past a
   threshold a picker becomes a search box over one generic endpoint, scoped
   by the same `RecordAccess` reading the picker used and shared with
   `CandidateLists`. Deliberately open: nothing enforces that a stored id
   points at something.

### 7.2.1 Taking what a module grew, without retro-fitting it (XIV-70)

The additive-only slice of question 2, built. §6.1 stands underneath it:
installing does not retro-fit, a blueprint is a seed, the customer's
definitions are the truth. This is the explicit way to say yes afterwards.

- **An offer, at `/m/{module}/upgrade`.** It shows what this copy of the
  module is missing relative to the blueprint, chosen per item, because
  fifteen additions are fifteen decisions. Fields and collections only; every
  write is an insert. Removal, type change and purge are not here, and since
  XIV-146 the middle one is a decision rather than an absence: a conversion
  restates values somebody typed, so a module may make one obvious and only a
  customer may make it happen. Nothing
  records that a module is "upgraded", because a tenant holding an arbitrary
  subset of the blueprint is the normal state, not a partial one.
- **A key the shape already has is never offered**, whatever it looks like
  now. That is deliberately cruder than diffing definitions, so a relabelled,
  resized or relaxed field survives by construction rather than by a rule
  somebody must remember.
- **The offer diffs against the blueprint, never a preset.** Which preset was
  installed is recorded nowhere, on purpose, since storing it would invite
  something to re-apply it. Every preset is a subset of the blueprint anyway.
- **A decline is written at the moment it is made**, to
  `shape_definition.declined_additions`, because afterwards a deleted field
  and a never-had field are indistinguishable. Two moments qualify: dismissing
  an offer, and removing a field in the metadata editor. Dismissals stay
  visible with a way back. An installation that predates the feature has
  nothing declined and is shown everything once.
- **Admin-only, on the metadata editor's authority** (§5.4, §8.4.3), and
  deliberately no console command. Installing is done *for* a customer, but
  this changes the shape of records they already have, and an operator's shell
  doing that is §6.1's refused retro-fit in a different hat.
- **The confirmation states two softenings.** A blueprint rule the existing
  records could not keep arrives switched off; turning it on afterwards is the
  editor's existing refusal-with-count, and `unique` usually survives, checked
  rather than reasoned about. A derived field arrives empty; the deriver fills
  it on the next save, and nothing here invents a plausible value (XIV-73).
- **What a key the shape *has* is worth is §7.2.2's question, not this one.** A
  module-owned option is read live from the blueprint and never offered, which
  is neither an addition nor an offer and would blur the sentence above.

### 7.2.2 A module's own field options, read live (XIV-176)

Not an addition and not an offer, which is why it is here rather than inside
§7.2.1. That section is about a key the shape has not got; this is about a key
it has, whose value was never the customer's.

- **The options in a field's row are two things in one bag.** Label, width,
  position, listed, filterable, required and section are decisions somebody
  made in the editor. `variant`, `samples`, `inherit` and `scale` are the
  module's: no control draws them, no editor path writes them, and the
  installer is the only thing that ever put them there.
- **§6.1 protects the customer's decisions, not their row.** So the second
  group is read live from the blueprint and **nothing is written**: no screen,
  no console command, no consent, no per-tenant state. The precedent is already
  shipped: `FieldDefinition::$width` is null for a field nobody had an opinion
  about, and null keeps following what the type wants.
- **Declared, in `ModuleOwnedOptions`.** One list keyed by option name, because
  option keys are shared across types and no two types disagree about who owns
  one. `FieldController::PER_TYPE` is the inverse declaration, and a unit test
  holds the two to being disjoint and to covering every key any shipped
  blueprint sets. A method on the type is the escape hatch if a type ever does
  disagree.
- **Read live: `variant` only.** Not `scale`, which decides how a value already
  in storage is read back, which is §5.21's complaint and which XIV-146 settled:
  only the customer may make a conversion happen. Not `samples`, which reaches
  demo generation and nothing else. Not `inherit`, because `InheritedValue::of()`
  is asked of the definition directly in three places, so resolving the key
  without rewiring them would have `tenant:inspect` report a value the engine
  does not use. Declaring and live-reading are separate questions, and only
  `variant`'s live read is earned.
- **A narrowing is honoured only where the tenant's shapes can express it**:
  target module installed, `variant_field` set, every named variant among that
  field's own choices. Otherwise the option is dropped and the field behaves as
  an unnarrowed reference. Not padding, because `QueryCompiler::variantGroup()`
  compiles to `FALSE` for a shape with no variant field, so an unguarded live
  read empties every picker in the tenant instead of widening it.
- **No consent, and the argument for asking does not survive contact with what
  would be asked.** §7.2.1 asks because each of its writes changes the shape of
  records somebody already has. This writes nothing, and the thing being
  consented to is invisible in the editor. A decline would leave a picker
  offering values the save refuses, which is the defect XIV-172 fixed.
- **A value a record already holds is kept.** `RecordCandidates::held()` applies
  neither the module's own rule nor the field's kinds, so a document keeps the
  voucher it was agreed with after the family was renamed, exactly as XIV-175
  kept the one that expired. Nothing refuses a save that changes nothing, since
  a use is taken once and re-saving re-checks nothing (§5.9); a document
  *taking* such a voucher afresh still meets XIV-122's refusal naming the field.
- **The number is takeable and nothing acts on it.**
  `MetadataEditor::recordsPointingOutside()` counts the live records whose link
  points outside the effective narrowing, joining to the target shape, and
  `tenant:inspect` prints it beside the effective options. Same discipline as
  the two counts next to it: count, name, never fix.
- **Out of scope, filed as XIV-179:** shape-level `variant_field` and
  field-level `variants` are columns rather than options, so nothing here
  reaches them. XIV-133's article narrowing stays new-installations-only until
  they do.

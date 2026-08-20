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
     half is still open.
   - **Additive upgrades** are §7.2.1, built.
   - **A field changing type** is decided on paper (below); XIV-146 builds it.
   - **Purging a removed field's values** stays open, deliberately beside type
     change and never inside it. One is data loss somebody asked for, the
     other is data loss nobody did, and a purge must never ride along on a
     conversion.

   *Type change, on paper (2026-08-20, XIV-146).* Legality is the tenant's
   data's to decide, not a table of type pairs. The conversion runs per row,
   through the new type's own reading (`toStorage()`), behind a dry run. A
   change every row survives simply happens; a change any row fails is refused
   with the count and the values. Emptying the failing rows is only ever the
   customer's explicit second choice, made with the report in front of them,
   and the run writes every value it converts or empties to the record's
   history first. Whether the door is one-way is said before it closes. A
   field something derives from re-derives, or the change is refused while the
   derivation exists (§5.9).

3. **Query layer.** Built, see §5.3. Still open, and narrower than the question
   was: `OR` between conditions, and keyset paging.

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
   uninstalled module, and a link to a deleted record, read as `#id` text.
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
  write is an insert. Removal, type change and purge are not here. Nothing
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

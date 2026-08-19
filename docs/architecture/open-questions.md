## 7. Open design questions

Not yet decided, or only partly. Decide deliberately rather than by accident.
Numbering is stable: code cites these by number, so a settled question keeps its
slot and a one-line answer rather than being removed.

1. **Veto events.** May a subscriber cancel a host action? Answered
   asymmetrically: a module takes part in a save by *deriving* (§5.9;
   `ValueDeriver` has nothing to cancel with, on purpose) and refuses through
   rules it *declares* (lifecycle guards, §5.8), never by a runtime veto from a
   subscriber. That last half stays open. Customer-authored expressions were
   examined across every candidate and declined (XIV-88); §5.8 has the argument.

2. **Metadata migration.** What happens to stored values when a definition
   changes. Decided so far:

   - **Deleting a field** removes the definition and keeps the values.
     Reversible, destroys nothing.
   - **Switching on a rule** that existing records would fail is refused with a
     count and the offending values named.
   - **`unique`** is a partial unique expression index on `data ->> 'key'`
     (`WHERE deleted_at IS NULL AND … IS NOT NULL`), created and dropped by
     `UniqueIndex::follow()` in the same transaction as the flag it enforces.
     The validator stays in front of it for the readable message; the index is
     what is true. Empty values do not collide; soft-deleted records do not
     reserve values. Existing duplicates refuse the flag, naming the shared
     values. Built in-transaction, not `CONCURRENTLY`, which fails soft and
     leaves an `INVALID` index enforcing nothing. `unique` on a collection's
     field is refused outright: whole-table and within-one-parent are different
     rules and the engine will not guess. Still open.
   - **Additive upgrades** are §7.2.1, built.
   - **A field changing type** is decided on paper (below); XIV-146 builds it.
   - **Purging a removed field's values** stays open, deliberately beside type
     change and never inside it: one is data loss somebody asked for, the other
     is data loss nobody did, and a purge must never ride along on a conversion.

   *Type change, on paper (2026-08-20, XIV-146).* Legality is the tenant's
   data's to decide, not a table of type pairs: per row, through the new type's
   own reading (`toStorage()`), behind a dry run. A change every row survives
   simply happens; a change any row fails is refused with the count and the
   values. Emptying the failing rows is only ever the customer's explicit second
   choice, made with the report in front of them, and every value the run
   converts or empties is written to the record's history first. Whether the
   door is one-way is said before it closes. A field something derives from
   re-derives, or the change is refused while the derivation exists (§5.9).

3. **Query layer.** Built, see §5.3. Still open, narrower than the question was:
   `OR` between conditions, and keyset paging.

4. **Doctrine multi-tenancy hazards.** Web requests are safe by construction:
   the runtime is deliberately not a worker (§9.2). For any process that serves
   several tenants in sequence (console commands, future consumers), the rule is
   `TenantSwitcher`: drop the identity map, the metadata cache and the
   connection on every switch, and **close** the connection: a fan-out that
   holds fifty connections blocks the `DROP DATABASE` an operator is running,
   and gets killed for it instead (XIV-94). Still open for shared caches.

5. **Authorization model.** Settled, see §8.4. The record-level half is a WHERE
   clause, never a post-load check or a voter (a list is twenty-five records and
   a total; a voter is handed one subject). Collections inherit through their
   parent (§5.1). Still open: whether a reference picker should be scoped, and
   what a permission means for a module the customer has since uninstalled.

6. **Links between records.** Built: a link **is** a field type. `reference`
   stores the target's id; widget, display and filtering come from the type.
   Across modules too (XIV-13): the reverse list crosses modules and is grouped
   by the module doing the pointing; a filter may take exactly one hop through a
   link, compiled as `EXISTS`, with the target module's own permissions applied
   inside the subquery (no grant there matches nothing, which is an answer, not
   an error). A link into an uninstalled module, and a link to a deleted record,
   read as `#id` text: deleting a pointed-at record is allowed and the link goes
   stale, because records are soft-deleted and nothing is destroyed. The *name*
   a reference shows is read unscoped (an order whose customer says `#14` is
   unusable); the *anchor* is offered only where the reader may open the target,
   because a record they may not view answers 404 (§8.4). Past a threshold a
   picker becomes a search box over one generic endpoint, scoped by the same
   `RecordAccess` reading the picker used, shared with `CandidateLists`.
   Deliberately open: nothing enforces that a stored id points at something.

### 7.2.1 Taking what a module grew, without retro-fitting it (XIV-70)

The additive-only slice of question 2, built. §6.1 stands underneath it:
installing does not retro-fit, a blueprint is a seed, the customer's definitions
are the truth. This is the explicit way to say yes afterwards.

- **An offer, at `/m/{module}/upgrade`**: what this copy of the module is
  missing relative to the blueprint, chosen per item, and fifteen additions are
  fifteen decisions. Fields and collections only; every write is an insert.
  Removal, type change and purge are not here. Nothing records that a module is
  "upgraded", because a tenant holding an arbitrary subset of the blueprint is
  the normal state, not a partial one.
- **A key the shape already has is never offered**, whatever it looks like now.
  Deliberately cruder than diffing definitions: a relabelled, resized, relaxed
  field survives by construction rather than by a rule somebody must remember.
- **Diffed against the blueprint, never a preset.** Which preset was installed
  is recorded nowhere, on purpose (storing it would invite something to
  re-apply it), and every preset is a subset of the blueprint anyway.
- **Declines are written at the moment they are made**, to
  `shape_definition.declined_additions`, because afterwards a deleted field and
  a never-had field are indistinguishable. Two moments qualify: dismissing an
  offer, and removing a field in the metadata editor. Dismissals stay visible
  with a way back. An installation that predates the feature has nothing
  declined and is shown everything once.
- **Admin-only, on the metadata editor's authority** (§5.4, §8.4.3), and
  deliberately no console command: installing is done *for* a customer, but this
  changes the shape of records they already have, and an operator's shell doing
  that is §6.1's refused retro-fit in a different hat.
- **The confirmation states two softenings**: a blueprint rule the existing
  records could not keep arrives switched off (turning it on afterwards is the
  editor's existing refusal-with-count; `unique` usually survives, checked, not
  reasoned about), and a derived field arrives empty; the deriver fills it on
  the next save, and nothing here invents a plausible value (XIV-73).

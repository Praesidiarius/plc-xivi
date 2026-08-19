## 7. Open design questions

Not yet decided. Decide deliberately rather than by accident.

1. **Veto events.** May a subscriber cancel a host action (e.g. `BeforeSave`)? Stoppable
   events give modules real power but make host behavior depend on what's installed.
   *Narrowed by §5.8.* A lifecycle now refuses a save — an edit of a locked record — but
   it is the engine refusing on a rule the module *declared*, not a subscriber vetoing at
   runtime. The question is still open for subscribers; what a module can say
   declaratively is turning out to cover more of it than expected.
   *Half answered by §5.9 — XIV-16.* A module may now take part in a save, and what it may
   do there is **derive**: fill in values that follow from what was typed, on the record and
   on its rows. `ValueDeriver` has nothing to cancel with, on purpose. So the answer is
   asymmetric and deliberately so — taking part is allowed, refusing is not, and the half
   still open is exactly the half that makes host behaviour depend on what is installed.
   *Asked from the other side and answered — XIV-88.* Whether the declarative half should
   become a **customer-authored expression** — Symfony's ExpressionLanguage, proposed after
   XIV-27 rejected it for the numbering pattern — was examined across every candidate in the
   system and declined. §5.8 carries the argument and the list; the short version is that a
   guard on a transition is the one place that passes both of the rules this project has
   learned, and that a module declaring one writes PHP, against which an expression string is
   strictly worse.
2. **Metadata migration.** What happens when a field changes type, or is deleted while
   data exists in it? Needs a real answer before the metadata editor ships.
   *Half settled — see §5.4.* Deleting a field is now decided: the definition goes and
   the values stay, which makes removal reversible and destroys nothing. Switching on a
   rule that existing records would fail is refused with a count. What remains open is the
   genuinely hard part — a field **changing type** — plus purging values, which is the
   deliberate counterpart to leaving them.
   *Also covers a blueprint that grows.* Contact gained an addresses collection after
   customers already had the module, and installing does not retro-fit it — §6.1 says the
   customer's definitions are the truth once installed, so silently adding to them here
   would overrule that. Adding a table and definition rows destroys nothing, so an
   explicit, additive-only upgrade is the obvious first slice of this question; it is not
   built yet.

   *How `unique` is actually enforced, since it was asked here* (XIV-109). By a **unique
   expression index** on the customer's own record table, `data ->> 'key'`, partial:
   `WHERE deleted_at IS NULL AND (data ->> 'key') IS NOT NULL`. It is created when the flag
   goes on, dropped when it goes off, and removed with the field — one method,
   `UniqueIndex::follow()`, called by everything that writes the flag, in the same
   transaction as the definition row it belongs to, so a definition claiming a rule the
   database is not keeping cannot exist even for a moment.

   Until then it was a validator that queried the table and let the save proceed, which is a
   read followed by a write with nothing across the gap: under READ COMMITTED two saves
   arriving together both found nothing and both inserted, and two people pressing Save was
   the whole of the reproduction. The validator **stays**, first, and the split is the
   ordinary one — *it produces the readable message and the index is what is true*. Almost
   every duplicate is still caught while the form is open, on the field it belongs to, with
   nothing written; the index fires only on the sliver the validator structurally cannot see,
   and a `UniqueConstraintViolationException` is turned back into a message on that same
   field rather than into a 500 (`DuplicateValue`).

   Three decisions were needed and are recorded here rather than in a ticket.
   **Partial in both directions**: several records with nothing in a field are not colliding,
   and a plain `UNIQUE` would silently make "unique" mean "unique and mandatory"; a
   soft-deleted record keeps its value but must not reserve it, or deleting a contact would
   quietly retire their email address for ever. Both predicates are exactly the validator's
   own WHERE clause, so the two can never disagree about which rows count.
   **Existing duplicates are refused, not fixed**: applying the rule anyway would leave
   records nobody can save — the trap this very question already refuses in general terms —
   and picking a winner would be data loss on a tick box. What changed is that the refusal
   now names the shared values as well as counting the records, because a count on its own
   leaves somebody scrolling a list looking for rows they cannot describe.
   **Built inside the transaction rather than `CONCURRENTLY`**: a concurrent build cannot be
   in a transaction, so the flag and the index could not land as one act, and it *fails soft*
   — a duplicate leaves an `INVALID` index that exists and enforces nothing, which is the
   exact failure being closed. The cost is a `SHARE` lock on one shape's table for the length
   of the build, taken during a deliberate administrative act; §4.2 makes the same trade for
   the deploy-time half.

   Existing customers get theirs from a tenant migration that reads **their own definitions**
   — not the blueprints (§6.1) — and builds one index per unique field. A tenant whose column
   already holds a duplicate stops there, loudly, and is retried with `--slug` once the
   records are fixed; the alternative, skipping the field and logging a line, would leave the
   one customer most likely to be bitten as the one customer with nothing enforcing it.

   What this does **not** answer is `unique` on a collection's field, which the installer
   still refuses outright: unique across the whole table and unique within one parent are
   different rules, and the engine will not guess which was meant. The index would be one
   line either way; the question is not a technical one and is still open.

   *The type-change half, decided on paper 2026-08-20; XIV-146 is the build.*
   **Legality is the tenant's data's to decide, not a table of type pairs.** A type
   change is a per-row conversion through the new type's own reading (`toStorage()`,
   the seam XIV-114 built), preceded by a dry run, and the same change can be legal
   for one tenant and refused for another because their data differs. A change every
   row survives simply happens. A change any row fails is **refused with the §5.4
   report**, a count and the offending values named, never a guess. Emptying the
   failing rows is available only as the customer's explicit second choice, made
   with that report in front of them, and every value the run converts or empties is
   written to the record's history first, so even a lossy conversion leaves the road
   back readable. Whether the door is one-way is said before it closes: a conversion
   whose reverse is lossless says so, and the rest are offered as final. A converted
   field that something derives from re-derives, or the change is refused while the
   derivation exists (§5.9). Purging a removed field's values stays open, and stays
   deliberately beside this rather than inside it: one is data loss somebody asked
   for and the other is data loss nobody did, and the pairing is the reason a purge
   must never ride along on a conversion.
3. **Query layer.** Filtering, sorting, and pagination across mixed real-column and JSONB
   storage, without degenerating into concatenated SQL. This is the highest-risk
   component in the system.
   *Built — see §5.3.* Both consequences collections predicted held: a condition on a
   child is a semi-join, and sorting by one is refused. What is still open is narrower
   than the question was: `OR` between conditions, and keyset paging.
4. **Doctrine multi-tenancy hazards.** Entity manager, metadata cache, result cache, and
   any warmed pools must not leak across tenants within a worker process. Critical under
   FrankenPHP/RoadRunner-style long-running workers.
   *Partly settled — see §9.2. The runtime is deliberately not a worker, which removes
   this class of bug for web requests. It remains open for shared caches and for any
   process that serves several tenants in sequence: console commands, and message
   consumers when they arrive.*
   *Exercised in earnest by §8.11 — XIV-59.* A command that walks every customer to
   count what is in their database is precisely "a process that serves several tenants
   in sequence", and it holds because `TenantSwitcher` drops the identity map, the
   metadata cache and the connection on every switch. What that ticket adds to this
   question is a second requirement nobody had written down: the switch must also
   *close* the connection, or a fan-out over fifty customers ends up attached to all
   fifty and blocks the `DROP DATABASE` an operator is running ([XIV-94]). Leaking
   state across tenants and holding resources across tenants turn out to be the same
   discipline. *XIV-94 changed the punishment, not the rule:* a removal now
   terminates whatever is attached, so a leaked connection no longer stops an
   operator — it gets killed under the collection instead, mid-count, which is a
   quieter failure and not a better one.
5. **Authorization model.** Roles, permissions, per-module access, and record-level
   rules. Entangled with §7.3: "only the records I own" is a WHERE clause, not a
   check performed after loading. See §8.4. Collections inherit the answer rather
   than needing their own: a child's access resolves through its parent, which is
   why its rows carry a parent and no owner of their own (§5.1).
   *Settled — see §8.4.* The entanglement was real and load-bearing: the record-level
   half could not be a voter, because a voter is handed one subject and a list is
   twenty-five plus a total. What remains open is narrower than the question was:
   whether a reference picker should be scoped, and what a permission means for a
   module the customer has since uninstalled.
6. **Links between records.** *Half built.* A link **is** a field type — that question is
   answered: `reference` stores the target's id, and the widget, the display and the
   filtering all come from the type like any other value. A person points at their company
   (§5.5), and the company's list of people is the reverse read back by query rather than
   stored twice. Optionally narrowed to a variant, so a picker offers companies rather
   than everybody.
   *Built across modules too — XIV-13.* A reference may point at any module the customer
   has, and four things follow from it:
   - **The reverse list crosses modules.** A record's page asks every installed module
     which of its fields point here, and groups what it finds by the module doing the
     pointing. Named by module rather than by field, because an order and an invoice may
     both call their link "Contact" and a list keyed by label alone silently shows one.
   - **A filter may take one hop through a link**, compiled as `EXISTS` over the target's
     table. One hop: `order.contact.city` from an invoice is a second join and a cost
     nobody can estimate from the URL. **The target module's own permissions apply inside
     the subquery** — following a link is reading the other module, and a filter that
     ignored that would sift records by values somebody may not see (§8.4). No grant there
     means the predicate matches nothing, which is an answer rather than an error.
   - **A link into a module the customer does not have matches nothing and reads as
     `#id`.** Not installed is a runtime fact about that customer (§3), not a broken
     reference, and a page that renders beats one that does not.
   - **Deleting a record that others point at is allowed, and the link goes stale.**
     Refusing would mean a contact can never be deleted once anything has ever named it,
     and there is no foreign key on a JSONB value to enforce it with anyway. Records are
     soft-deleted, so nothing is destroyed; the link says `#id` rather than pretending,
     which is the same honesty the display already had.

   - **A reference is a way to get to what it names, and the name is shown either way**
     (XIV-42). Two questions, answered separately and on purpose. *The name* is read
     unscoped: whoever may see the record holding the link can read what it points at, and
     an order whose customer said `#14` would be an order nobody can use. *The link* is
     offered only where the reader may actually open the target, because a record somebody
     may not view answers **404** rather than 403 (§8.4) — so an anchor there would send
     them to a page saying the thing does not exist, which is worse than not offering one.
     A stale reference and one into an uninstalled module are the same: text, never an
     anchor.

     The link is a **second seam** rather than something `display()` returns, because that
     method's output goes into .docx templates, spreadsheet cells and the record titles
     the picker shows — an `<a>` in there is markup printed on a letter.

   - **What a set of records names is read before it is rendered** (XIV-54). Naming a
     record is a second row from a second table, and a collection has no LIMIT — so the
     cost that mattered was a record page's rows rather than a list's. One
     `WHERE id IN (…)` per target module, filled into a memo the name, the link and the
     inherited-value drift check share, under exactly the access rule above. See §5.3 for
     the argument and the numbers.

   - **A picker somebody may type into, and the endpoint behind it** (XIV-36). A
     reference's dropdown was capped at two hundred and said so (XIV-35), which is honest
     and is not a way to find the nine thousandth contact. Past a threshold the control
     becomes a search box that pages through the endpoint instead, so under `auto` a
     truncated picker cannot happen: the ceiling is replaced rather than raised, and the
     notice survives for `never`, where the ceiling survives too. Whether it does this is
     an option on the field and not a field type (§5).

     **The endpoint is the sharp part, and it is scoped exactly as the picker is**
     (XIV-13, §8.4). An unrestricted search is strictly worse than the unrestricted
     picker that ticket closed: a picker leaks the names it happens to render, once, on a
     page somebody was allowed to open, where a search box lets them enumerate a module a
     letter at a time. Same `RecordAccess`, same `View` check on the target module, no
     exception for administrators written into the query. One route, generic over module
     and variant and sorting and paging by the same title fields the dropdown used — a
     module-specific search route would be the code the engine exists not to have — and
     what the widget may *find* and what the form will *accept* go through one reading, or
     a record somebody clicks comes back as an invalid choice.

     The reading is shared with `CandidateLists` (XIV-87) for that reason, and core learns
     the URL through an interface the application answers, the same seam as
     `InstanceCurrency` and `RecordAccessProvider`.

   Still open: nothing enforces that the id points at something, which is the price of the
   above and is deliberate rather than forgotten.

*Numbering is stable — code comments cite these by number, so a settled question keeps
its slot and gains a note rather than being removed.*

### 7.2.1 Taking what a module grew, without retro-fitting it (XIV-70)

The additive-only slice question 2 named, built. **§6.1 is unchanged and is the
thing this is built on top of**: installing does not retro-fit, a blueprint is a
seed, and from the moment a module is installed the customer's own definitions
are the truth. Nothing here reaches into anybody's database on a deploy.

What was missing was never the rule; it was that there was no *explicit* way to
say yes. Contact gained an `addresses` collection and a `payment_terms` field
([XIV-67]) after customers already had Contact, and those customers could have
neither — the collection because only the installer makes a table, the field
because nothing offered it. A tenant who installed the `basic` preset had no path
to the extended shape at all. So this is an **offer**: what your copy of a module
is missing relative to the blueprint, shown, chosen item by item, and taken by
somebody with the authority to change what a module is.

**Additive only, and the boundary is what makes it safe.** A field the blueprint
has and the shape has not is offered; a collection the blueprint has and the
module has not is offered, which means creating its table. That is the whole
list. Removing a field the blueprint dropped is *not* here — §5.4 decided that
removal keeps the values, and no module author gets to take a customer's field
away — and a field **changing type** is not here either, which is the half of
question 2 that stays open. Above all, nothing that already exists is touched:
every write is an insert.

**A key the shape already has is never offered**, whatever it now looks like.
That one test is the whole protection a customised field gets, and it is
deliberately cruder than comparing a definition against its blueprint: somebody
whose `phone` is now called "Mobile", four columns wide and no longer required
has made three decisions, and an upgrade clever enough to notice the difference
would be tempted to correct them. So a relabelled field, a changed width, a
relaxed rule and a reordered form all survive by construction rather than by a
rule somebody has to remember.

**The offer is diffed against the blueprint, never against a preset.** That falls
out of §6.1 rather than being a shortcut: nothing records which preset a module
was installed with, deliberately, because storing it would invite something to
re-apply it. It does not need to be recorded — every preset names a subset of the
blueprint's own fields, so "what this customer has, versus what the module
declares" already covers the extended preset's extras without anything
remembering the word *extended*.

#### What "missing" means, once somebody has edited things

The design question of the ticket, because two absences are indistinguishable. A
field the customer **deleted on purpose** looks exactly like one they **never
had**: §5.4's removal takes the definition and leaves nothing behind to tell them
apart with. Guessing is wrong in a way somebody notices, in both directions —
read every absence as "never had" and the offer nags them for ever about a
decision they already made; read it as "deleted" and a field a preset left out is
invisible to the customer who now wants it.

So **nothing is inferred afterwards; a decision is written down at the moment it
is made**, which is the only moment it is unambiguous. Two moments qualify, and
both write to the same place — a `declined_additions` map on the shape's own row:

- **Dismissing an addition** on the upgrade screen. It stops being offered.
- **Removing a field** in the metadata editor. Deleting something is as clear an
  answer to "do you want this" as declining it, and the write happens in
  `MetadataEditor::removeField()` while the intent is still legible.

Dismissals are kept **visible**, in a list of their own with a way back, because
a decision nobody can see is not a decision but a disappearance — the same
promise §5.4 makes by leaving the values behind when a field is removed.

Two consequences are stated rather than hidden. A removal records the key
whatever it is, including a field the blueprint never declared, so a future
module declaring `nickname` does not reopen a question this customer closed —
which is the right answer anyway. And an installation that predates this feature
has **nothing declined**, so the first time an administrator opens the screen
they are shown everything, including things deleted back when there was nowhere
to write the decision down. Asking once is a smaller imposition than nagging for
ever or deciding on somebody's behalf, and a migration cannot know what those
deletions meant.

**On the shape's row rather than in a table of its own**, for the reason
`ModuleDefinition::$followUpsEnabled` gives: "what this customer has, and how it
is set up" is already one question with one answer. It also gets the lifetime
right for free — uninstalling takes the declines with it, and a fresh install is
a fresh choice.

#### Per item, and who may do it

**Per addition rather than all or nothing.** Fifteen additions are fifteen
decisions and a customer is allowed to want four of them; all-or-nothing would be
simpler and would make declining one field cost somebody the other fourteen. It
also costs no state: there is no partial condition to describe, because a tenant
already holds an arbitrary subset of the blueprint — that is what §6.1 says a
tenant *is* — so taking four of fifteen leaves the installation in exactly the
kind of state it was already in. Nothing anywhere records that a module is
"upgraded", and nothing should.

**Administrators, on the metadata editor's authority rather than the store's**
(§5.4, §8.4.3). The store's install grant is about putting a module the customer
does not have into the installation; this changes the shape of every record in
one they already have, which is the sentence §5.4 uses to explain why field
editing is admin-only. It sits under `/m/{module}` beside the field editor and
names no module permission, for the same reason that editor does not.

**Never a side effect of a deploy, and not something an operator does to them.**
There is deliberately no console command. A headless second front door was the
obvious symmetry with `tenant:module:install` (§6.3) and it is the wrong one
here: installing is done *for* a customer at their request, whereas this is a
customer deciding what their own records are, and an operator's shell doing it to
them is precisely the retro-fit §6.1 refuses, wearing a different hat.

#### Two things the confirmation has to say

The middle screen is [XIV-91]'s shape — name what is about to happen, name how
much of it there is, default to no, and require the confirmation in the
controller rather than only as a `required` attribute. Nothing here destroys
anything and it is confirmed anyway, because "a table appears in your database
and every record in this module gains four fields" is a sentence somebody should
read before it is true rather than after.

- **A rule the records could not keep arrives switched off.** A blueprint field
  can be `required`, and every record that already exists is empty in a field
  that has just appeared — installing it required would leave a module nobody can
  save a record in, which is exactly what §5.4 refuses to do to somebody.
  Refusing the *addition* over it would be worse, since a tenant with data could
  then never take a required field at all. So it arrives optional, the page says
  which ones and why, and switching it on afterwards is the editor's existing
  conversation with its existing count and its existing refusal. It is a **count,
  not a policy**: `unique` normally survives, because two records with nothing in
  a field are not duplicates of each other — and it is checked rather than
  reasoned about, because §5.4 leaves values behind when a field is removed, so a
  key can carry duplicates without carrying a definition.
- **A derived field arrives empty.** Nothing here writes a value into a record.
  A field a `ValueDeriver` owns (§5.9) belongs to the engine, and this code
  inventing a plausible total, due date or document number would produce records
  that look right and are wrong (XIV-73). The definition arrives, the record is
  untouched, and the deriver fills it the next time that record is saved — which
  the page says in as many words rather than letting somebody discover it.

**What stays open** is what question 2 still has: a field changing type, purging
the values a removed field left behind, and removing a field the blueprint
dropped. None of the three is additive, and this slice was chosen precisely
because everything in it destroys nothing.


---


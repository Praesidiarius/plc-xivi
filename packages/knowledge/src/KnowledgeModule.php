<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Xivi\Knowledge;

use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\GroupedList;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * What the experienced people know, written down where the others can read it
 * (XIV-132).
 *
 * Every business runs on knowledge that lives in one person's head. *How do we
 * handle a refund past thirty days? Which supplier do we call when the usual one
 * is out? What did we actually agree with this customer in 2023?* When that
 * person is on holiday nobody else can answer, and the answer is not written
 * anywhere because there has never been anywhere to write it.
 *
 * So: entries. Experienced staff write them, everybody else reads them. Three
 * fields — a title, a topic and a formatted body — and a search box that is
 * really the filter bar every module already has.
 *
 * ### This is a blueprint, and that is the point of it
 *
 * An entry is a record with a title and a body, which means the interesting
 * question this ticket asks is not "what shall we build" but **"is the engine as
 * general as §1 claims"**. The answer turned out to be yes, in a way worth
 * writing down: nothing was added to core for this module, no field type, no
 * service, no controller, no template, no migration. What a knowledge base needs
 * beyond a title and a body, it already had:
 *
 * - **Who wrote it and who changed it** is §5.2's record history, on every
 *   record of every module, without anybody asking for it. There is deliberately
 *   no `author` field and no `last_reviewed` field below, and their absence is a
 *   decision rather than an omission — see the block on staleness further down.
 * - **Write versus read** is the per-module permission axis (§8.4), which
 *   already separates `add` and `edit` from `view` and `list`. No new permission
 *   concept, no "editor" role, nothing seeded at install.
 * - **Searching** is `Operator::Contains`, which compiles to a case-insensitive
 *   `ILIKE` over the stored string. Its ceiling is stated honestly in §5.22 and
 *   is repeated on `BODY` below, because a ceiling nobody wrote down is a
 *   ceiling somebody discovers at the worst moment.
 * - **A formatted body** is the `markdown` field type ([XIV-131], §5.21), which
 *   landed a day before this and named a knowledge-base entry in its own docblock
 *   as the thing it was for. This is the first module blueprint to declare one.
 *
 * **That held for eight days** (XIV-168). The first thing this module ever asked
 * the engine for was a **grouped index**: one card per topic instead of a page
 * of rows, because rows answer "which entry is this" and a knowledge base is
 * browsed rather than looked up. The claim above survives in the form that was
 * worth making, because what arrived is general. A module declares
 * {@see \Xivi\Core\Module\GroupedList} and the engine does the rest, so this
 * file gained one line and there is still no knowledge-base code anywhere. §5.22
 * carries the correction, rather than leaving the original claim to quietly stop
 * being true.
 *
 * ### What it deliberately is not
 *
 * **Not a wiki.** No page trees, no `[[cross-link]]` syntax, no namespaces, no
 * revisions with diffs beyond what §5.2 already records. Each of those is what
 * turns a wiki into a product somebody has to administer, and what this is meant
 * to be is a list of entries with a search box. The engine could carry some of
 * them; none of them earns the maintenance.
 *
 * **Not customer-facing.** These are a company's internal notes. Nothing here is
 * published, shared with a contact, or attached to a document — which is not a
 * feature that was left out but a *boundary*, and one the declaration keeps by
 * construction rather than by intention: this module declares no
 * `mailRecipient`, so the "send this record by email" path (§5.14) is not
 * offered on it at all, and it names no contact for one to be resolved through.
 * Document templates (§5.7) are per module and a customer would have to create
 * one deliberately. If publishing a subset is ever wanted it is a different
 * feature with a different security argument, and it should be asked for as one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class KnowledgeModule implements ModuleProvider
{
    public const string KEY = 'knowledge';

    /** The question the entry answers, and therefore what it is called. */
    public const string TITLE = 'title';

    /** Which corner of the business it is about — see below on [XIV-127]. */
    public const string TOPIC = 'topic';

    /** The answer itself, in Markdown (§5.21). */
    public const string BODY = 'body';

    /**
     * The six topics an entry can be filed under.
     *
     * Values, not labels: what is stored is `supplier` for ever and what is
     * shown is whatever that customer has since renamed it to (§6.1). The list
     * is short on purpose — a categorisation with twenty entries in it is one
     * nobody uses consistently, and a knowledge base with inconsistent
     * categories is a knowledge base with none.
     */
    public const string PROCESS = 'process';
    public const string POLICY = 'policy';
    public const string CUSTOMER = 'customer';
    public const string SUPPLIER = 'supplier';
    public const string PRODUCT = 'product';
    public const string OTHER = 'other';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'knowledge_entry',
            fields: [
                // **The question, not the subject.** "Refunds" is a heading;
                // "How do we handle a refund after thirty days" is an entry
                // somebody can tell at a glance answers the thing they came
                // looking for. The samples below are written that way for the
                // same reason the article module's are written as things to
                // sell (§5.17): the engine sees a required text field called
                // `title` and offers what it offers a name, so without them a
                // demo knowledge base would be a list of Swiss company names.
                new FieldBlueprint(
                    key: self::TITLE,
                    label: 'field.title',
                    type: 'text',
                    required: true,
                    // The heading on its page and the link in the list (§5.4),
                    // and — because a title field is a *title* field — the thing
                    // the reference picker and the search endpoint match on if
                    // anything ever points here.
                    title: true,
                    // So "everything about deposits" is a filter as well as a
                    // search of the body. Most people look for the title first.
                    filterable: true,
                    position: 10,
                    options: [
                        'max_length' => 180,
                        'samples' => [
                            'Rückgabe nach mehr als 30 Tagen',
                            'Welcher Lieferant, wenn Meier nicht liefern kann',
                            'Anzahlung: ab welchem Betrag verlangen wir eine',
                            'Kundenrabatt über 15 Prozent — wer darf das freigeben',
                            'Reklamation: was wir zuerst prüfen',
                            'Mahnlauf, Schritt für Schritt',
                            'Wie wir eine Gutschrift verbuchen',
                            'Abholung im Lager: was die Kundin mitbringen muss',
                            'Garantiefall beim Lieferanten anmelden',
                            'Was in eine Offerte für die öffentliche Hand gehört',
                            'Preise für Montage vor Ort',
                            'Übergabe an die Buchhaltung am Monatsende',
                        ],
                    ],
                ),
                // **A plain `choice`, and that is a decision with a date on it.**
                //
                // [XIV-127] proposes shared lists a customer maintains once and
                // uses across modules — the obvious right home for "our topics",
                // next to "our payment terms" and "our units". It is unbuilt. So
                // the choice here is between a plain choice field and building
                // half of that ticket inside this module, and building half of
                // it is the worse of the two by some distance: a half-shared
                // list is a second mechanism [XIV-127] would then have to
                // migrate off, and the customer would meet the migration.
                //
                // A choice field costs nothing to give up when that lands. The
                // stored values are strings; a shared list will also store
                // strings; the field's type changes and the values do not, which
                // is the cheapest kind of upgrade §7.2 has. **This module is a
                // consumer of [XIV-127] when it arrives** and is written down as
                // one in §5.22 so that whoever builds it has a first caller.
                //
                // **That limit was §5.20's word for word, and finding it here
                // is what closed it** (XIV-144). A topic we did not ship — a
                // workshop's "machine", a practice's "patient" — can be added in
                // the field editor now, which draws a control for a choice
                // field's options. The six below cannot be taken *away*: this
                // field came with the module, and §5.4 refuses that for every
                // module's own field. `other` stays useful all the same — it is
                // where an entry goes while somebody decides whether they want a
                // topic of their own.
                //
                // **Not required**, deliberately. Somebody writing down what they
                // know at half past five should not be stopped by a dropdown
                // they have no opinion about, and an entry filed under nothing is
                // still an entry that answers the question. `other` exists for
                // the person who does want to answer and finds none of the five
                // fits.
                new FieldBlueprint(
                    key: self::TOPIC,
                    label: 'field.topic',
                    type: 'choice',
                    // The one column worth having next to the title, and the one
                    // filter worth having next to the search. Both come from
                    // this single flag (§5.3).
                    filterable: true,
                    position: 20,
                    options: [
                        'choices' => [
                            // How we do something: the steps, in order.
                            self::PROCESS => 'topic.process',
                            // What we have decided: the rule, and its edges.
                            self::POLICY => 'topic.policy',
                            // What was agreed with somebody who buys from us.
                            self::CUSTOMER => 'topic.customer',
                            // What we know about the people we buy from.
                            self::SUPPLIER => 'topic.supplier',
                            // What we know about the thing itself.
                            self::PRODUCT => 'topic.product',
                            self::OTHER => 'topic.other',
                        ],
                        // Weighted towards the two that carry most of a real
                        // knowledge base, so a seeded tenant looks like one
                        // somebody has been using rather than like a uniform
                        // draw across six categories (§5.17).
                        'samples' => [
                            self::PROCESS, self::PROCESS, self::PROCESS,
                            self::POLICY, self::POLICY,
                            self::CUSTOMER, self::SUPPLIER, self::PRODUCT,
                            self::OTHER,
                        ],
                    ],
                ),
                // **The entry itself**, and the reason this module waited for
                // [XIV-131] rather than shipping with a `textarea`.
                //
                // A procedure is a numbered list, a policy has a heading and an
                // exception under it, and both of those written into a plain
                // textarea are a wall of text that people skim and get wrong.
                // The `markdown` type gives the writer a list and a heading and
                // gives the reader a block on the record page that looks like
                // what was typed — with the escaping decided once, in core, on
                // the source before it is parsed (§5.21). Nothing about that is
                // this module's problem, which is the whole argument for the
                // field type having been its own ticket.
                //
                // **Not a list column**, for the reason the article module's
                // description is not one: a paragraph in a table squeezes every
                // other column into nothing, and the list exists to *find* an
                // entry rather than to read it. It is still filterable, and that
                // is the sentence that matters most in this file —
                //
                // **this flag is the search.** `Operator::Contains` compiles to a
                // case-insensitive `ILIKE %word%` over the stored source, so
                // "find the entry that mentions Meier" is a filter row and needed
                // no code. What that is **not** is full-text search: no
                // stemming, so "supplier" does not find "suppliers"; no ranking,
                // so ten matches come back in whatever order the list is sorted
                // by rather than best first; no phrase handling beyond the
                // substring; and the query cannot use an ordinary index, so the
                // cost grows with the number of entries. At a few dozen entries
                // nobody can tell the difference and at a few thousand somebody
                // will want it badly. That is a separate ticket — Postgres has
                // `tsvector` and a GIN index and the engine would need a field
                // type that knows about them — and it is deliberately not a
                // reason to hold this back, because a knowledge base with
                // substring search in it beats one that does not exist.
                new FieldBlueprint(
                    key: self::BODY,
                    label: 'field.body',
                    type: 'markdown',
                    required: true,
                    filterable: true,
                    listed: false,
                    position: 30,
                ),
            ],
            // A book somebody writes in. `journal-text` rather than `book`,
            // because the entries are written here rather than shelved here.
            icon: 'journal-text',
            // **The index is a card per topic, not a page of rows** (XIV-168).
            //
            // A knowledge base read as twenty-five rows with a pager under them
            // is a knowledge base nobody browses. Rows answer "which entry is
            // this"; what somebody arriving actually wants is the shape of what
            // has been written down, which topics exist and what is filed under
            // each, and that is a page of cards.
            //
            // **One line, and no knowledge-base code behind it.** The whole
            // capability is the engine's ({@see GroupedList}), which is the
            // correction §5.22 needed: the finding there was that this module
            // cost the engine nothing, and this is the first thing it asked for.
            // The finding survives in the form that mattered, because what
            // arrived is general. Articles by category and contacts by kind are
            // one line each and no further work.
            //
            // It names `topic` because `topic` is the only field here that could
            // be named: a title is a card per record and a body is a card per
            // paragraph. The engine refuses both by taking only a field whose
            // type enumerates its own values.
            groupedList: new GroupedList(self::TOPIC),
            // **No presets** (§6.1), on the article module's reasoning and more
            // so: three fields is already the smallest honest version of this,
            // and the only field a smaller preset could leave out is the body,
            // which is the thing an entry *is*.
            //
            // **No `requires` and no `uses`**, which makes this the first module
            // that installs into a completely empty tenant. That is not an
            // accident of it being small — it follows from the linking decision
            // recorded in §5.22. An entry carries no `reference` field, so there
            // is no module for it to point at and nothing for a customer to
            // install first. Somebody who signs up today and wants to write down
            // what they know can have this and nothing else.
        );
    }
}

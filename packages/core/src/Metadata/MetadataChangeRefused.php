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

namespace Xivi\Core\Metadata;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A change to a customer's definitions that the engine will not make (§5.4).
 *
 * Every one of these is a refusal to do something that would leave data the
 * application can no longer read, save, or explain. They carry the reason in
 * full, because the person reading it is a customer changing their own module,
 * not a developer with the source open.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MetadataChangeRefused extends \RuntimeException
{
    /**
     * What to show the person who caused it, in their language (XIV-8).
     *
     * The exception's own message stays English and goes to the log, where the
     * reader is a developer; this is the half a customer sees. Two audiences,
     * two sentences, and neither has to be a compromise for the other.
     */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters, string $domain = 'xivi'): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, $domain);

        return $refusal;
    }

    public static function badKey(string $key): self
    {
        return self::of(
            sprintf(
                'A field name must start with a letter and contain only lowercase letters, numbers and '
                . 'underscores. "%s" does not.',
                $key,
            ),
            'metadata.bad_key',
            ['%key%' => $key],
        );
    }

    public static function emptyLabel(): self
    {
        return self::of(
            'A shape needs a label: it is what the navigation and every page heading call it.',
            'metadata.empty_label',
            [],
        );
    }

    public static function keyTaken(string $key, string $shape): self
    {
        return self::of(
            sprintf('"%s" already has a field named "%s".', $shape, $key),
            'metadata.key_taken',
            ['%key%' => $key, '%shape%' => $shape],
        );
    }

    public static function systemField(string $key): self
    {
        return self::of(
            sprintf(
                'The field "%s" came with the module and cannot be removed. Fields you added yourself can be '
                . '(docs/architecture.md §7.2).',
                $key,
            ),
            'metadata.system_field',
            ['%key%' => $key],
        );
    }

    /**
     * A numbering pattern that would number nothing (XIV-27).
     *
     * {@see \Xivi\Core\Numbering\NumberFormat} treats a pattern without
     * `{number}` in it as "this field is not a sequence", which is the right
     * answer for a blueprint and the wrong one for a form: somebody who has just
     * typed a pattern into the metadata editor and been told nothing would have
     * no way of telling silence from success, and would find out when their
     * first invoice came out blank.
     *
     * An emptied box lands here too, and still does after XIV-91. Turning
     * numbering *off* is a real thing now, and it is deliberately **not** this:
     * it is a page of its own that says what happens to the numbers already on
     * records before it happens ({@see MetadataEditor::setNumbering()} with
     * null). Blanking a text box is not that conversation, and reading it as
     * "off" would make the most consequential change here the one that takes the
     * least typing.
     */
    public static function patternNumbersNothing(string $pattern): self
    {
        return self::of(
            sprintf(
                'A numbering pattern has to say where the counter goes: it needs {number} in it, as in '
                . 'ORD-{year}-{number:4}. "%s" would leave this field numbering nothing.',
                $pattern,
            ),
            'metadata.pattern_numbers_nothing',
            ['%pattern%' => $pattern],
        );
    }

    /**
     * The unique half of the rule above, refused with the values named
     * (XIV-109).
     *
     * **Because a count is not actionable and this is.** "That rule would make 4
     * existing records invalid" is true and leaves somebody scrolling six
     * hundred contacts looking for four they cannot describe. The values that
     * are actually shared are the search terms — paste one into the filter bar
     * and the colliding records are on the screen — so the refusal hands them
     * over rather than making the customer derive them.
     *
     * **Refuse rather than fix, and that is the decision.** The alternatives
     * were to make the field unique anyway and leave the duplicates unsaveable —
     * which is the trap §5.4 refuses in general terms, records nobody can save
     * until they work out why — or to have the engine pick a winner and clear
     * the losers, which is data loss on a tick box. So the answer is no, with
     * enough in the sentence to make it a yes next time.
     *
     * There is no plural to handle: a value cannot be *shared* by fewer than two
     * records, so the count is always at least two.
     *
     * @param array<string, int> $duplicates value => how many records hold it, worst first.
     *                                       The caller is expected to have asked for one *more*
     *                                       than it wants shown, which is how this can tell
     *                                       "there are exactly five" from "there are at least
     *                                       five" without a second query
     * @param int                $shown      how many of them the message lists; the rest become
     *                                       an ellipsis, because a column duplicated a thousand
     *                                       ways is a column that was never meant to be unique
     *                                       and printing all of it is a refusal nobody reads
     */
    public static function valuesAreShared(string $key, int $records, array $duplicates, int $shown): self
    {
        $named = \array_slice(array_keys($duplicates), 0, $shown);

        $values = implode(', ', array_map(
            static fn (string $value): string => sprintf('"%s"', $value),
            $named,
        ));

        if (\count($duplicates) > $shown) {
            // An ellipsis rather than "and 37 others": how many *more* distinct
            // values there are would need a second query over the whole column,
            // and the reader's next action — go and fix these — is the same
            // either way.
            $values .= ' …';
        }

        return self::of(
            sprintf(
                '%d existing records already share a value in "%s", so it cannot be made unique. On more '
                . 'than one record: %s. Fix those records first, or leave "%s" as it is.',
                $records,
                $key,
                $values,
                $key,
            ),
            'metadata.values_are_shared',
            ['%count%' => $records, '%key%' => $key, '%values%' => $values],
        );
    }

    /**
     * A field of a type that cannot work until something is set, arriving with
     * it unset (XIV-144).
     *
     * **On the write path, and that is what makes it the fix rather than a
     * second opinion.** The editor's form now draws a control for both of these
     * — a choice field's options and a reference's target — so somebody using
     * the page meets a required box rather than this sentence. What this covers
     * is every other way a definition gets written: a form posted around the
     * page, an import, a console command, and whatever the next caller turns out
     * to be. The defect this exists for was precisely a rule that lived nowhere:
     * the type knew it needed a list, and nothing anywhere asked it.
     *
     * The option is named as it is stored rather than translated into whatever
     * the control happens to be labelled. It is the string in the definition, it
     * is what an importer's column would be called, and a reader who has hit
     * this from something other than the form is a reader who needs the exact
     * name.
     *
     * **It names every way of answering, not one of them** ([XIV-127]). A
     * `choice` field's values may now come from its own options *or* from a
     * shared list, and a message naming only the first would send somebody off
     * to type options into a field they had meant to point at "our regions".
     * Which is also why the options arrive as a list rather than one at a time:
     * a question is refused once, with all of its answers, rather than once per
     * answer.
     *
     * @param non-empty-list<string> $options the ways of answering, any one of which would do
     */
    public static function optionUnanswered(string $type, array $options, string $key): self
    {
        $named = implode(' or ', array_map(
            static fn (string $option): string => sprintf('"%s"', $option),
            $options,
        ));

        return self::of(
            sprintf(
                'A "%s" field does nothing until %s is set, so "%s" was not saved. A choice field with no '
                . 'options offers nothing to pick and accepts anything; a reference with no target has nowhere '
                . 'to look a record up.',
                $type,
                $named,
                $key,
            ),
            'metadata.option_unanswered',
            ['%type%' => $type, '%option%' => $named, '%key%' => $key],
        );
    }

    /**
     * Taking an option away from under the records that hold it (XIV-144).
     *
     * **The same decision as {@see self::valuesAreShared()}, reached the same
     * way**: refuse, and name what has to be dealt with. Removing an option a
     * record holds does not delete anything — the value stays in the JSON, and
     * `display()` falls back to printing it raw — but it leaves that record
     * failing its own field's validation, so the next person to open it and
     * press Save is told their record is invalid for a reason that has nothing
     * to do with what they were doing. That is exactly the trap §5.4 refuses
     * when a rule is switched on, and a list somebody edits is the same trap
     * with a friendlier control in front of it.
     *
     * The alternatives were both worse. Rewriting the affected records to some
     * other option is data loss on a click. Keeping the option alive but hidden
     * — retiring rather than removing it — is the genuinely better answer for
     * the customer who has stopped selling by the pallet and has four hundred
     * old orders that were, and it is deliberately **not** built here: it is a
     * third state per option, it has to be understood by every reader of
     * `choices`, and §5.4 says why that decision belongs with [XIV-127] rather
     * than in front of it.
     *
     * @param array<string, int> $held value => how many live records hold it
     */
    public static function optionsAreHeld(string $key, array $held): self
    {
        $records = array_sum($held);

        $values = implode(', ', array_map(
            static fn (string $value, int $count): string => sprintf('"%s" (%d)', $value, $count),
            array_keys($held),
            array_values($held),
        ));

        return self::of(
            sprintf(
                '%d existing records hold an option you are removing from "%s": %s. They would be left with a '
                . 'value that is no longer on the list and could not be saved again until somebody fixed them. '
                . 'Change those records first, or keep the option.',
                $records,
                $key,
                $values,
            ),
            'metadata.options_are_held',
            ['%count%' => $records, '%key%' => $key, '%values%' => $values],
        );
    }

    /**
     * Taking an option away from a field the module declared (XIV-144).
     *
     * §5.4's oldest rule, one level down. A module's own *fields* cannot be
     * removed because the module's code is written against them; a module's own
     * field's *options* are the same statement about the same code — an order's
     * `status` list is the states its lifecycle moves records between, a
     * contact's `kind` list is the variants the module ships forms for, and
     * either one losing an entry breaks the module rather than the record.
     *
     * **Adding and renaming stay open**, which is the half that matters: the
     * wholesaler who wants "pallet" beside the seven shipped units and the
     * workshop that wants "machine" beside the six shipped topics are the two
     * customers this whole ticket was written for (§5.20, §5.22), and both of
     * them are adding.
     *
     * The refusal is blunt on purpose — *any* removal, not only the ones the
     * module names. The definition records which *fields* came with the module
     * and does not record which *options* did, so there is no way to tell the
     * customer's own seventh unit from the six the installer wrote. Refusing all
     * of them costs somebody a dead entry in a dropdown they added by mistake;
     * allowing all of them costs somebody their order lifecycle. [XIV-127] is
     * where provenance gets modelled properly, and it is the right place for it.
     */
    public static function optionsAreTheModules(string $key): self
    {
        return self::of(
            sprintf(
                'The field "%s" came with the module, and its options are part of what the module does — the '
                . 'states it moves records between, the kinds of record it knows. You can add options and '
                . 'rename them; removing one is not offered (docs/architecture.md §5.4).',
                $key,
            ),
            'metadata.options_are_the_modules',
            ['%key%' => $key],
        );
    }

    /**
     * Repointing a reference that records already point through (XIV-144).
     *
     * The quietest of all of these if it were allowed, which is why it is the
     * one refused with a count rather than warned about. A stored reference is a
     * plain integer, so every one of them is still a *valid* id after the target
     * moves — it simply addresses a different record, in a different module, and
     * every page carries on rendering a name. Nothing is broken in any way
     * anything can detect; the data is just wrong now, and there is no way back,
     * because "which record did this mean" was only ever answerable by knowing
     * which module the id came from.
     */
    public static function targetIsHeld(string $key, string $from, string $to, int $records): self
    {
        return self::of(
            sprintf(
                '%d existing records point through "%s" at records in "%s". An id only means anything in the '
                . 'module it was chosen from, so pointing this field at "%s" would leave every one of them '
                . 'naming the wrong record, or none. Empty those records first, or leave it pointing at "%s".',
                $records,
                $key,
                $from,
                $to,
                $from,
            ),
            'metadata.target_is_held',
            ['%count%' => $records, '%key%' => $key, '%from%' => $from, '%to%' => $to],
        );
    }

    /** A module's own reference points where the module's own code expects (XIV-144). */
    public static function targetIsTheModules(string $key, string $module): self
    {
        return self::of(
            sprintf(
                'The field "%s" came with the module and points at "%s" because the module\'s own forms, '
                . 'documents and totals expect it to. Fields you added yourself can be pointed wherever you '
                . 'like.',
                $key,
                $module,
            ),
            'metadata.target_is_the_modules',
            ['%key%' => $key, '%module%' => $module],
        );
    }

    /**
     * A target this customer has not got (XIV-144).
     *
     * Separate from {@see ModuleNotInstalled} because the reader is different:
     * that one answers "this URL names a module you do not have", and this one
     * is a field that would silently look up records in a table that is not
     * there. The select on the page is built from what *is* installed, so
     * arriving here means a form posted around it — or a module uninstalled
     * between the page and the save.
     */
    public static function unknownTarget(string $key, string $module): self
    {
        return self::of(
            sprintf('No module named "%s" is installed here, so "%s" cannot point at it.', $module, $key),
            'metadata.unknown_target',
            ['%module%' => $module, '%key%' => $key],
        );
    }

    /**
     * A field put into a heading that is not on this module (XIV-119).
     *
     * **Refused rather than read as "no section",** and the difference matters
     * exactly once: the control is a select whose blank option *does* mean
     * ungrouped, so treating an unknown key as blank would make a hand-edited
     * form move a field out of its section and report success. Everywhere else
     * in this editor nonsense from a tampered form is quietly ignored — a width
     * of 40, a country that does not exist — because there the honest response
     * to nonsense is to change nothing. Here changing nothing means saying no.
     */
    public static function unknownSection(string $key, string $section): self
    {
        return self::of(
            sprintf('There is no section named "%s" on this module, so "%s" cannot be put in it.', $section, $key),
            'metadata.unknown_section',
            ['%section%' => $section, '%key%' => $key],
        );
    }

    /**
     * A field pointed at a shared list this customer has not got ([XIV-127]).
     *
     * {@see self::unknownTarget()}'s twin, word for word in intent: the select
     * on the page is built from the lists that exist, so arriving here means a
     * form posted around it — or a list deleted between the page and the save.
     * A field pointing at a list that is not there offers an empty picker and
     * validates nothing, which is the state {@see \Xivi\Core\Field\NeedsAnAnswer}
     * exists to keep definitions out of.
     */
    public static function unknownList(string $key, string $list): self
    {
        return self::of(
            sprintf('No shared list named "%s" exists here, so "%s" cannot take its values from it.', $list, $key),
            'metadata.unknown_list',
            ['%list%' => $list, '%key%' => $key],
        );
    }

    /**
     * A heading with nothing written on it (XIV-119).
     *
     * The name is the entire content of a section — there is nothing else to
     * decide about one — and a blank heading on a form is a horizontal rule with
     * a mystery over it. The key is derived from the name as well, so a nameless
     * section would not even have a stable identity.
     */
    public static function sectionNeedsAName(): self
    {
        return self::of(
            'A section is a heading on the form, so it needs a name: it is the only thing it says.',
            'metadata.section_needs_a_name',
            [],
        );
    }

    /**
     * A shared list attached under records whose values it has not got
     * ([XIV-127]).
     *
     * **This ticket's own refusal, and the one that made a shared list an option
     * on `choice` rather than a field type of its own.** §5.21 argues that a
     * checkbox reinterpreting stored data is the reason to reach for a type
     * instead — ticking it changes what every value already in the column
     * *means*, at once, with no migration and nothing on any screen to say it
     * happened. That argument is exactly right and it is answered here rather
     * than dodged: the values records hold are counted against the list first,
     * and the ones the list has never heard of are named. What is left after
     * that is a change that reinterprets nothing, because every value survives
     * it with the same meaning.
     *
     * The alternatives were the usual two and lose the usual way. Rewriting the
     * offending records to some entry of the new list is data loss on a select.
     * Attaching it anyway leaves records that no longer validate, which is §5.4's
     * trap — somebody opens a contact next week, presses Save, and is told it is
     * invalid for a reason that has nothing to do with what they were doing.
     *
     * @param array<string, int> $held the values that are not on the list, and how many records hold each
     */
    public static function valuesAreNotOnTheList(string $key, string $list, array $held): self
    {
        $records = array_sum($held);

        $values = implode(', ', array_map(
            static fn (string $value, int $count): string => sprintf('"%s" (%d)', $value, $count),
            array_keys($held),
            array_values($held),
        ));

        return self::of(
            sprintf(
                '%d existing records hold a value in "%s" that is not on the list "%s": %s. Pointing the field '
                . 'at that list would leave them holding something it does not contain, and they could not be '
                . 'saved again until somebody fixed them. Add those values to the list first, or change the '
                . 'records.',
                $records,
                $key,
                $list,
                $values,
            ),
            'metadata.values_are_not_on_the_list',
            ['%count%' => $records, '%key%' => $key, '%list%' => $list, '%values%' => $values],
        );
    }

    /**
     * The same refusal in the other direction: a field taken *off* a shared list
     * while records hold entries of it ([XIV-127]).
     *
     * Pointing a field back at its own options is the one way out of a shared
     * list, and it has the same hazard as going in — every value records hold
     * has to exist on the other side. It gets a sentence of its own rather than
     * sharing the one above because there is no list to name in it, and a
     * refusal that said "not on the list """ would be a refusal nobody could
     * read.
     *
     * @param array<string, int> $held value => how many records hold it
     */
    public static function valuesAreNotAmongItsOptions(string $key, array $held): self
    {
        $records = array_sum($held);

        $values = implode(', ', array_map(
            static fn (string $value, int $count): string => sprintf('"%s" (%d)', $value, $count),
            array_keys($held),
            array_values($held),
        ));

        return self::of(
            sprintf(
                '%d existing records hold a value in "%s" that is not one of the field\'s own options: %s. '
                . 'Taking the field off the shared list would leave them holding something the field does not '
                . 'offer. Add those options to the field first, or leave it on the list.',
                $records,
                $key,
                $values,
            ),
            'metadata.values_are_not_among_its_options',
            ['%count%' => $records, '%key%' => $key, '%values%' => $values],
        );
    }

    /**
     * A module's own choice field pointed at a shared list ([XIV-127]).
     *
     * §5.4's oldest rule reaching one step further. A module's own `choice`
     * field's options are its code's expectations written into the definition —
     * an order's `status` list is the states its lifecycle moves records
     * between, a contact's `kind` list is the variants it ships forms for — and
     * {@see self::optionsAreTheModules()} already refuses removing one from a
     * table cell. Pointing that field at a list the customer maintains would be
     * the same removal by a longer route: the list is theirs, so anything they
     * take out of it takes an option out of the module's field, and the refusal
     * they would meet would be about a list rather than about an order.
     *
     * A customer who wants their own vocabulary on a module's field adds a field
     * of their own and points *that* at the list, which is what the whole
     * mechanism is for.
     */
    public static function listIsTheModules(string $key): self
    {
        return self::of(
            sprintf(
                'The field "%s" came with the module, and its options are part of what the module does — the '
                . 'states it moves records between, the kinds of record it knows. It cannot take them from a '
                . 'list you maintain. Add a field of your own and point that at the list instead '
                . '(docs/architecture.md §5.4).',
                $key,
            ),
            'metadata.list_is_the_modules',
            ['%key%' => $key],
        );
    }

    /**
     * Taking an entry out of a shared list that records still hold ([XIV-127]).
     *
     * **The same answer as {@see self::optionsAreHeld()}, and that is the
     * point.** §5.4 states the rule in a form that reaches both mechanisms — *a
     * list somebody's records point into cannot lose an entry while they point
     * into it, whether the list lives in the field or beside it* — because a
     * customer cannot be expected to know which of the two a given picker is,
     * and learning the difference by being refused in one and not the other is
     * the worst way to find out.
     *
     * What is different here is only the *reach*, and it is why this message
     * names the fields as well as the counts: removing an option from a field's
     * own list breaks records in that field, and removing an entry from a shared
     * list breaks records in every module pointing at it — including modules the
     * person doing the removing is not looking at.
     *
     * **Retiring an entry** — keeping it valid for the records that have it and
     * taking it out of the picker — is still the better answer for the
     * wholesaler with four hundred old orders, and is still not built. §5.4 says
     * why: it is a third state that every reader of a list has to understand,
     * and it has to arrive for a field's own options at the same time or a
     * customer meets one mechanism that can retire and one that cannot.
     *
     * @param array<string, int> $held  value => how many live records hold it, across every field
     * @param list<string>       $where "Contacts → Region" and so on, for the ones that do
     */
    public static function entriesAreHeld(string $list, array $held, array $where): self
    {
        $records = array_sum($held);

        $values = implode(', ', array_map(
            static fn (string $value, int $count): string => sprintf('"%s" (%d)', $value, $count),
            array_keys($held),
            array_values($held),
        ));

        return self::of(
            sprintf(
                '%d existing records hold an entry you are removing from "%s": %s. They are in %s. Those '
                . 'records would be left holding a value the list no longer has, and could not be saved again '
                . 'until somebody fixed them. Change those records first, or keep the entry.',
                $records,
                $list,
                $values,
                implode(', ', $where),
            ),
            'metadata.entries_are_held',
            [
                '%count%' => $records,
                '%list%' => $list,
                '%values%' => $values,
                '%where%' => implode(', ', $where),
            ],
        );
    }

    /**
     * Taking away an entry that other entries sit under ([XIV-127]).
     *
     * Refused rather than quietly promoting the children to roots, on this
     * section's usual grounds: a customer removing "Switzerland" has said
     * nothing at all about what should happen to "Zürich" and "Bern", and
     * choosing for them is a structural change nobody asked for arriving as a
     * side effect of a different one. Two clicks — clear the parents, then
     * remove — say what happened at every step.
     */
    public static function entryHasChildren(string $label, int $children): self
    {
        return self::of(
            sprintf(
                '%d entries sit under "%s", so it cannot be removed. Move them somewhere else or make them '
                . 'top-level entries first.',
                $children,
                $label,
            ),
            'metadata.entry_has_children',
            ['%count%' => $children, '%label%' => $label],
        );
    }

    /**
     * Deleting a shared list that fields still take their values from
     * ([XIV-127]).
     *
     * The rule above, one level up. A list that disappeared from under a field
     * would leave that field validating nothing and offering an empty picker —
     * the exact state XIV-144 spent a ticket making unreachable — and it would
     * do it to fields in modules the person deleting the list is not looking at.
     * So the fields are named, because "three fields point at this" without
     * saying which three is a refusal nobody can act on.
     *
     * @param list<string> $fields "Contacts → Region" and so on
     */
    public static function listIsInUse(string $list, array $fields): self
    {
        return self::of(
            sprintf(
                '"%s" cannot be deleted: %d fields take their values from it — %s. Point those fields '
                . 'somewhere else first, and the list can go.',
                $list,
                \count($fields),
                implode(', ', $fields),
            ),
            'metadata.list_is_in_use',
            ['%list%' => $list, '%count%' => \count($fields), '%fields%' => implode(', ', $fields)],
        );
    }

    /**
     * A merge that names something that is not an entry of this list, or that
     * names one entry twice ([XIV-127]).
     *
     * Both are only reachable from a form posted around the page — the page
     * offers the list's own entries and never offers an entry as its own target
     * — so this is the sentence at the end of the road rather than one anybody
     * meets. It exists at all because the alternative on that road is a merge
     * that silently does nothing, or one that rewrites every record holding a
     * value to itself and then deletes the entry it just kept.
     */
    public static function cannotMergeThat(string $list): self
    {
        return self::of(
            sprintf(
                'A merge has to name two different entries of "%s". Pick the entry to keep and the entry to '
                . 'merge into it.',
                $list,
            ),
            'metadata.cannot_merge_that',
            ['%list%' => $list],
        );
    }

    /** A shared list needs a key nothing else already has ([XIV-127]). */
    public static function listKeyTaken(string $key): self
    {
        return self::of(
            sprintf('A shared list named "%s" already exists.', $key),
            'metadata.list_key_taken',
            ['%key%' => $key],
        );
    }

    /**
     * A shared list needs a name ([XIV-127]).
     *
     * Its own sentence rather than {@see self::emptyLabel()}'s, because that one
     * says "it is what the navigation and every page heading call it" and a list
     * is neither. What a list's label is for is the select in the field editor,
     * which is where somebody meets it.
     */
    public static function emptyListLabel(): self
    {
        return self::of(
            'A list needs a name: it is what the field editor offers when somebody points a field at it.',
            'metadata.empty_list_label',
            [],
        );
    }

    /**
     * Sections offered on something that is not a module (XIV-119).
     *
     * A collection's fields are drawn as one row inside a form and as a row of a
     * *table* on the record page, and a table row has nowhere to put a heading.
     * The editor draws no control for this on a collection; this is the same
     * rule on the write path, where a posted form meets it too.
     */
    public static function sectionsAreForModules(string $shape): self
    {
        return self::of(
            sprintf(
                '"%s" is a list of rows inside a record rather than a form of its own, so it has no sections '
                . 'to put fields in.',
                $shape,
            ),
            'metadata.sections_are_for_modules',
            ['%shape%' => $shape],
        );
    }

    /**
     * A period told to be exclusive within something that is not a field of its
     * own shape (XIV-136).
     *
     * Covers both the misspelling and the field that has since been removed, and
     * says the same thing about each: **there is nothing to be exclusive within,
     * so there would be no constraint.** Silence was the alternative and it is
     * the worse one — a customer who has just switched on "no two of these may
     * overlap" and been told nothing would believe the rule was in force, and
     * find out the day two guests arrive.
     *
     * A field cannot be exclusive within *itself* either, and that lands here for
     * the same reason: `data ->> 'stay' = data ->> 'stay'` is true of every row
     * against itself and of nothing else, so the constraint would refuse only two
     * records holding the identical period — which is not the rule anybody meant.
     */
    public static function scopeIsNotAField(string $key, string $scope): self
    {
        return self::of(
            sprintf(
                '"%s" cannot be exclusive within "%s": this module has no field called that. Periods are '
                . 'exclusive within something — a room, a machine, a person — and that something is a field '
                . 'beside them.',
                $key,
                $scope,
            ),
            'metadata.scope_is_not_a_field',
            ['%key%' => $key, '%scope%' => $scope],
        );
    }

    /**
     * The same rule, refused on a collection's field (XIV-136).
     *
     * Exactly the argument the installer makes about `unique` on a collection:
     * across the whole table and within one parent record are different rules, and
     * the engine will not guess which was meant (§7). It is sharper here, because
     * both readings are plausible — one row of a booking's collection must not
     * overlap another row *of the same booking*, or of any booking — and picking
     * one silently would build a constraint enforcing a rule nobody chose.
     */
    public static function scopeOnACollection(string $key, string $shape): self
    {
        return self::of(
            sprintf(
                '"%s" is a field of the collection "%s", and periods there cannot be made exclusive: within '
                . 'one parent record and across the whole table are different rules and the engine will not '
                . 'guess (docs/architecture.md §7).',
                $key,
                $shape,
            ),
            'metadata.scope_on_a_collection',
            ['%key%' => $key, '%shape%' => $shape],
        );
    }

    /**
     * Records that already overlap, refused with the pairs named (XIV-136).
     *
     * {@see self::valuesAreShared()}'s argument, one feature along: a count is
     * true and useless, and what somebody needs is *which records*. Here that is
     * pairs rather than values — an overlap is a relationship between two records
     * and neither of them is wrong on its own — so the sentence names the two ids
     * and the scope they collide in, which is what somebody types into the URL
     * bar to go and look at them.
     *
     * **Refuse rather than fix**, for the reason that ticket sets out at length:
     * building the constraint anyway would leave records nobody can save, and
     * having the engine choose a winner would be data loss on a select box.
     *
     * @param list<array{scope: string, first: int, second: int}> $conflicts one *more* than will be
     *                                                                       shown, so this can tell
     *                                                                       "exactly five" from "at
     *                                                                       least five" without a
     *                                                                       second query
     */
    public static function periodsAlreadyOverlap(string $key, string $scope, array $conflicts, int $shown): self
    {
        $pairs = implode(', ', array_map(
            static fn (array $pair): string => sprintf('#%d/#%d in "%s"', $pair['first'], $pair['second'], $pair['scope']),
            \array_slice($conflicts, 0, $shown),
        ));

        if (\count($conflicts) > $shown) {
            $pairs .= ' …';
        }

        return self::of(
            sprintf(
                'Records already overlap in "%s", so it cannot be made exclusive within "%s" yet: %s. Move '
                . 'those records apart first, or leave "%s" as it is.',
                $key,
                $scope,
                $pairs,
                $key,
            ),
            'metadata.periods_already_overlap',
            ['%key%' => $key, '%scope%' => $scope, '%pairs%' => $pairs],
        );
    }

    public static function wouldInvalidateRecords(string $key, int $records): self
    {
        return self::of(
            sprintf(
                'That rule would make %d existing record%s invalid, and they could not be saved again until '
                . 'somebody fixed them. Fix the records first, or leave "%s" as it is.',
                $records,
                $records === 1 ? '' : 's',
                $key,
            ),
            'metadata.would_invalidate',
            ['%count%' => $records, '%key%' => $key],
        );
    }
}

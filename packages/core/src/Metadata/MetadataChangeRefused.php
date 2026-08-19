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
     */
    public static function optionUnanswered(string $type, string $option, string $key): self
    {
        return self::of(
            sprintf(
                'A "%s" field does nothing until "%s" is set, so "%s" was not saved. A choice field with no '
                . 'options offers nothing to pick and accepts anything; a reference with no target has nowhere '
                . 'to look a record up.',
                $type,
                $option,
                $key,
            ),
            'metadata.option_unanswered',
            ['%type%' => $type, '%option%' => $option, '%key%' => $key],
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

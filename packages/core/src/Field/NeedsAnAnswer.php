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

namespace Xivi\Core\Field;

/**
 * A field type that does nothing at all until somebody has answered something
 * (XIV-144).
 *
 * The capabilities before this one — {@see Autocompletes} (XIV-36),
 * {@see Numbers} (XIV-27), {@see AssumesACountry} (XIV-114) — all describe an
 * option a field *may* have, and every one of them has a sensible answer for a
 * field that says nothing: decide the search box from the count, do not number
 * this field, follow the installation's country. **These two do not.** A
 * `choice` field with no options offers nothing to pick and, because its
 * `Assert\Choice` cannot be built from an empty list, accepts anything at all; a
 * `reference` with no target renders every value it holds as `#41`. Neither is
 * an error anywhere — they are a control that looks like it works and does
 * nothing, which is what §8.3.1 exists to prevent.
 *
 * So the type says so, and it says it in the one place that can be checked: a
 * list of the options a field of this type is not finished without. Two things
 * read it and they are deliberately in different layers, which is the whole
 * design:
 *
 *  * the **editor** will not offer a type it cannot ask the question for — the
 *    add-field select is built from the types whose every need it draws a
 *    control for ({@see \App\Controller\FieldController}), so a type registered
 *    tomorrow that needs something nobody has built a control for is simply not
 *    offered rather than being offered broken;
 *  * the **engine** refuses to write a definition whose needs are unanswered
 *    ({@see \Xivi\Core\Metadata\MetadataEditor}), which holds for the importer,
 *    the console and whatever comes next, not only for the one screen.
 *
 * **Necessary, not sufficient, and not a validator.** This says which options
 * have to be *there*; what a good answer looks like is the option's own
 * business, and the editor checks a target module against what is installed the
 * same way it checks a country against symfony/intl.
 *
 * **Nothing here says the answer may be changed freely.** An answer that records
 * already depend on is a different question and it is the editor's: see §5.4 on
 * why removing a choice somebody's records hold, or repointing a reference
 * records already point through, is refused rather than done quietly.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface NeedsAnAnswer extends FieldType
{
    /**
     * The questions a field of this type is not finished without, and the
     * options that answer each.
     *
     * One entry per *question*; the strings inside it are the ways of answering
     * that one question, and **any one of them finishes it**. The names are the
     * ones stored in the definition's own options — the same strings the
     * editor's per-type list is keyed by — so that "the type needs this" and
     * "the editor draws this" are two statements about one thing and can be
     * compared. That comparison is a test
     * ({@see \App\Tests\Functional\Engine\EditorConfiguresEveryTypeTest}), and it
     * is what stops this defect coming back the next time somebody writes a
     * field type.
     *
     * **It was a flat list until [XIV-127], and the nesting is that ticket's
     * evidence rather than its speculation.** XIV-144 wrote this with one answer
     * per question because both of its questions had exactly one: a reference's
     * target is the target, and a choice field's options were the options. Then
     * a shared list arrived — *take your values from "our regions"* — and a
     * choice field acquired a second, equally complete answer to the same
     * question, "where do this field's values come from". Flattening that back
     * into two independent needs would say a choice field needs both, which is
     * false and would refuse every definition in every tenant; leaving the list
     * out of `needs()` altogether would say a field pointing at one is
     * unfinished, which is the badge XIV-144 added and the wrong thing to show.
     * So the shape grew the one axis it was missing, and the rule it encodes is
     * still one sentence: **every question answered, by something.**
     *
     * @return list<non-empty-list<string>>
     */
    public function needs(): array;
}

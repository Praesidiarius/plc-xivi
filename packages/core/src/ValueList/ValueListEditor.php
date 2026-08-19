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

namespace Xivi\Core\ValueList;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Entity\ValueListEntry;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Metadata\MetadataCache;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Record\RecordRepository;

/**
 * Changing the shared lists a customer keeps (XIV-127).
 *
 * {@see MetadataEditor}'s twin, one concept over, and written to the same three
 * rules because §5.4 says a shared list is that section's questions with more
 * records behind them:
 *
 *  * **count first, refuse with the number, never fix anything.** Every refusal
 *    here is one of that class's, reached the same way, and one of them is
 *    literally the same sentence with a wider reach;
 *  * **the value is derived from the label once and then frozen**, so renaming
 *    an entry moves no record. Not a second derivation either — it is
 *    {@see ChoiceFieldType::valueFor()}, the one XIV-144 wrote, because two
 *    slugs that agree today are two slugs that disagree the first time somebody
 *    improves one;
 *  * **a change that reaches records says so before it happens.** Only one
 *    operation here does — {@see self::merge()} — and it is the one with a page
 *    and a confirmation in front of it (XIV-91).
 *
 * **The refusals are `MetadataChangeRefused` rather than an exception of this
 * feature's own.** They are refusals to change a customer's definitions, they
 * are read by the same flash on the same kind of page, and a second exception
 * type would mean every controller catching two. What varies is the sentence,
 * which is where the difference actually lives.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ValueListEditor
{
    /**
     * How many held values a refusal names before it gives up and says "…".
     *
     * {@see MetadataEditor::DUPLICATES_NAMED}'s number and its argument: enough
     * to recognise a pattern, short enough to fit in a sentence somebody reads
     * rather than skims.
     */
    private const int VALUES_NAMED = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValueLists $lists,
        private ValueListUsage $usage,
        private RecordRepository $records,
        private MetadataCache $cache,
    ) {
    }

    /**
     * A new list, named by the customer and keyed from that name once.
     *
     * The key is derived rather than asked for, on
     * {@see ChoiceFieldType::valueFor()}'s argument: it is permanent, it is what
     * a field definition holds, and asking somebody who wants a list of regions
     * to understand the difference between a key and a name is asking them to
     * make a decision that only matters when it is too late to change it.
     *
     * A list arrives **empty**, which is the one place this differs from adding
     * a `choice` field. A choice field with no options is a field that does
     * nothing, so the engine refuses to write one (XIV-144); a list with no
     * entries is a list somebody is about to fill in, and nothing points at it
     * yet — the refusal that matters is on the *field*, which cannot be pointed
     * at a list that would leave its records stranded, and that check reads the
     * list as it is at the moment of pointing.
     *
     * @throws MetadataChangeRefused
     */
    public function create(string $label): ValueList
    {
        $label = trim($label);

        if ($label === '') {
            throw MetadataChangeRefused::emptyListLabel();
        }

        $taken = [];

        foreach ($this->lists->all() as $existing) {
            $taken[$existing->getKey()] = true;
        }

        $key = ChoiceFieldType::valueFor($label, $taken);

        if ($this->lists->exists($key)) {
            // Belt and braces: the uniquifier above has already made this
            // impossible from the keys it was given, and the keys it was given
            // came from the cache. A list created in another request between the
            // two would be a collision the database reports as a constraint
            // violation, which is a stack trace where a sentence belongs.
            throw MetadataChangeRefused::listKeyTaken($key);
        }

        $list = new ValueList($key, $label);

        $this->entityManager->persist($list);
        $this->entityManager->flush();
        $this->cache->clear();

        return $list;
    }

    /**
     * Throwing a whole list away, which is refused while anything points at it.
     *
     * The entry-level rule one level up. A list deleted from under a field would
     * leave that field offering an empty picker and validating nothing — the
     * state XIV-144 spent a ticket making unreachable — and it would do it to
     * fields in modules the person deleting is not looking at. So the fields are
     * named.
     *
     * A list nothing points at goes, entries and all, and **that is not a
     * removal of anything records hold**: a list nothing points at is a list no
     * record can be holding a value from, because the only way a value gets into
     * a record is through a field that points here.
     *
     * @throws MetadataChangeRefused
     */
    public function delete(ValueList $list): void
    {
        $uses = $this->usage->of($list);

        if ($uses !== []) {
            throw MetadataChangeRefused::listIsInUse(
                $list->getLabel(),
                array_map(static fn (ValueListUse $use): string => $use->label(), $uses),
            );
        }

        $this->entityManager->remove($list);
        $this->entityManager->flush();
        $this->cache->clear();
    }

    /**
     * One save of the whole list: what was renamed, recoloured, reparented,
     * reordered, added and ticked for removal.
     *
     * **One method rather than six, and it is the removals that decide it.**
     * Every other change here is instantaneous and reversible — a colour, a
     * label, a position — and taking an entry away is neither, so the two have
     * to be judged together or a save that renames three entries and removes one
     * could half-apply. The order below is the same one {@see MetadataEditor}
     * argues for: what the change would do to **records** is checked before
     * anything at all is written, so a refusal leaves the list exactly as it
     * was.
     *
     * @param array<string, array{label?: string, tone?: ?string, icon?: ?string, parent?: ?string, position?: int}> $entries
     *                                                                                                                        by value, for the entries that stay
     * @param list<string>                                                                                           $remove  the values ticked for removal
     * @param list<string>                                                                                           $added   labels of new entries, one per line as typed
     *
     * @throws MetadataChangeRefused
     */
    public function update(ValueList $list, string $label, array $entries, array $remove, array $added = []): void
    {
        // The list's own name goes through the same save rather than through
        // {@see self::rename()} beside it, and that is not tidiness. Two calls
        // would mean a refusal about an entry arriving *after* the rename had
        // already been written — the half-done state XIV-27 spent an ordering
        // argument avoiding, and the one somebody would meet as "it said no and
        // renamed it anyway".
        $label = trim($label);

        if ($label === '') {
            throw MetadataChangeRefused::emptyListLabel();
        }

        $remove = array_values(array_filter(
            $remove,
            static fn (string $value): bool => $list->getEntry($value) !== null,
        ));

        $this->assertEntriesAreFree($list, $remove);
        $this->assertNothingIsOrphaned($list, $remove);

        $list->setLabel($label);

        foreach ($list->getEntries() as $entry) {
            $change = $entries[$entry->getValue()] ?? null;

            if ($change === null || \in_array($entry->getValue(), $remove, true)) {
                continue;
            }

            // A blank label keeps the old one, on the options page's rule: a
            // blank in a dropdown is indistinguishable from the placeholder and
            // is nobody's intention, and the entry cannot simply be dropped
            // either, because dropping it is the operation with a conversation
            // attached.
            $label = trim($change['label'] ?? '');
            $entry->setLabel($label === '' ? $entry->getLabel() : $label);
            $entry->setTone(ValueTone::tryOf($change['tone'] ?? null));
            $entry->setIcon(ValueIcon::tryOf($change['icon'] ?? null));
            $entry->setPosition($change['position'] ?? $entry->getPosition());
            $entry->setParent($this->parentFor($list, $entry, $change['parent'] ?? null, $remove));
        }

        foreach ($remove as $value) {
            $entry = $list->getEntry($value);

            if ($entry !== null) {
                $list->removeEntry($entry);
                $this->entityManager->remove($entry);
            }
        }

        $this->add($list, $added);

        $this->entityManager->flush();
        $this->cache->clear();
    }

    /**
     * New entries, from labels typed one per line.
     *
     * Keys derived against **everything the list already has**, including the
     * entries this save is removing, so that a value somebody is taking away
     * cannot be handed straight back to a different label in the same
     * transaction — which would be a rename that moved records, wearing a
     * disguise.
     *
     * @param list<string> $labels
     */
    private function add(ValueList $list, array $labels): void
    {
        $taken = [];

        foreach ($list->getEntries() as $entry) {
            $taken[$entry->getValue()] = true;
        }

        $position = 0;

        foreach ($list->getEntries() as $entry) {
            $position = max($position, $entry->getPosition());
        }

        foreach ($labels as $label) {
            $label = trim($label);

            if ($label === '') {
                // Blank lines are how a textarea is typed in, not an entry
                // called nothing.
                continue;
            }

            $value = ChoiceFieldType::valueFor($label, $taken);
            $taken[$value] = true;

            $this->entityManager->persist(new ValueListEntry($list, $value, $label, $position += 10));
        }
    }

    /**
     * Nothing records hold may be taken away ([XIV-144]'s answer, [XIV-127]'s
     * reach).
     *
     * §5.4 states the rule so that it covers both mechanisms at once — *a list
     * somebody's records point into cannot lose an entry while they point into
     * it, whether the list lives in the field or beside it* — and this is the
     * "beside it" half. The counts come from every field pointing at the list,
     * summed, because that is the number in the sentence somebody reads and
     * because the whole hazard of a shared list is that the records are
     * somewhere the person removing the entry is not looking.
     *
     * **Retirement is the friendlier answer and is deliberately not built**;
     * §5.4 says why, and says that when it arrives it has to arrive for a
     * field's own options at the same time.
     *
     * @param list<string> $remove
     *
     * @throws MetadataChangeRefused
     */
    private function assertEntriesAreFree(ValueList $list, array $remove): void
    {
        if ($remove === []) {
            return;
        }

        $held = $this->usage->recordsHolding($list, $remove);

        if ($held === []) {
            return;
        }

        $uses = [];

        foreach ($this->usage->of($list) as $use) {
            if ($this->records->valueCountsAmong($use->shape, $use->field, $remove) !== []) {
                $uses[] = $use->label();
            }
        }

        throw MetadataChangeRefused::entriesAreHeld(
            $list->getLabel(),
            \array_slice($held, 0, self::VALUES_NAMED, preserve_keys: true),
            $uses,
        );
    }

    /**
     * An entry that other entries sit under cannot be taken away under them.
     *
     * Refused rather than quietly promoting the children, because a customer
     * removing "Switzerland" has said nothing about what should become of
     * "Zürich" and "Bern", and deciding for them would be a structural change
     * arriving as a side effect of a different one. Removing a parent *and* its
     * children in one save is fine and is the case this is careful to allow —
     * it is only an orphan that is refused.
     *
     * @param list<string> $remove
     *
     * @throws MetadataChangeRefused
     */
    private function assertNothingIsOrphaned(ValueList $list, array $remove): void
    {
        foreach ($remove as $value) {
            $entry = $list->getEntry($value);

            if ($entry === null) {
                continue;
            }

            $orphans = 0;

            foreach ($list->getEntries() as $child) {
                if ($child->getParent() === $entry && !\in_array($child->getValue(), $remove, true)) {
                    ++$orphans;
                }
            }

            if ($orphans > 0) {
                throw MetadataChangeRefused::entryHasChildren($entry->getLabel(), $orphans);
            }
        }
    }

    /**
     * The parent a submitted value names, or null.
     *
     * **Anything that is not a root is read as no parent at all**, which is the
     * same answer {@see \App\Controller\FieldController} gives a tampered
     * autocomplete or country select: the control offers the answers there are,
     * so anything else is a hand-edited form, and the honest response to one of
     * those is to change nothing rather than to invent a structure nobody asked
     * for. Three things are dropped that way, and each would be a third level of
     * nesting or a cycle: an entry naming itself, an entry naming one of its own
     * children, and an entry with children of its own naming anybody.
     *
     * §5.4 has the argument for one level rather than many, and it is the reason
     * this method is nine lines rather than a cycle check.
     *
     * @param list<string> $remove entries on their way out, which cannot become anybody's parent
     */
    private function parentFor(ValueList $list, ValueListEntry $entry, ?string $parent, array $remove): ?ValueListEntry
    {
        if ($parent === null || $parent === '' || $parent === $entry->getValue()) {
            return null;
        }

        if (\in_array($parent, $remove, true)) {
            return null;
        }

        foreach ($list->getEntries() as $child) {
            if ($child->getParent() === $entry) {
                // This entry has children, so it cannot become somebody's child
                // without making a third level.
                return null;
            }
        }

        $candidate = $list->getEntry($parent);

        return $candidate !== null && $candidate->getParent() === null ? $candidate : null;
    }

    /**
     * Merging one entry into another: the irreversible one (XIV-127).
     *
     * **What it does, in one sentence, because that sentence is the feature.**
     * Every live record holding `$from`, in every field pointing at this list,
     * in every module and every collection, is rewritten to hold `$into`; then
     * `$from` is taken off the list. Afterwards nothing anywhere remembers which
     * records used to say the other thing.
     *
     * **In one transaction**, so that a browser closing or a statement failing
     * part-way leaves the list and every record exactly as they were. The
     * alternative — rewrite, then delete — has a state in which the values have
     * moved and the entry is still on the list, which is survivable, and a state
     * in which some modules have moved and others have not, which is the one
     * nobody could untangle.
     *
     * **The confirmation is not here**, and that is on purpose. It is the
     * controller's, on XIV-91's rule: a required checkbox in a template is a
     * courtesy to somebody using the page and nothing at all to a form posted
     * around it, so the decision belongs where every caller has to make it and
     * this method belongs where the work is. A test that calls this directly
     * proves the rewrite; only a test that goes through the controller proves
     * the confirmation.
     *
     * @return int how many records were actually rewritten — read from the
     *             statements rather than from the plan, because a record saved
     *             between the two is one more record rewritten and the sentence
     *             afterwards should be about what happened
     *
     * @throws MetadataChangeRefused
     */
    public function merge(ValueList $list, string $from, string $into): int
    {
        $going = $list->getEntry($from);
        $staying = $list->getEntry($into);

        if ($going === null || $staying === null || $from === $into) {
            throw MetadataChangeRefused::cannotMergeThat($list->getLabel());
        }

        $written = 0;

        $this->entityManager->getConnection()->transactional(function () use ($list, $going, $staying, $from, $into, &$written): void {
            foreach ($this->usage->of($list) as $use) {
                $written += $this->records->replaceValue($use->shape, $use->field, $from, $into);
            }

            // The survivor comes up to the top if it was sitting under the entry
            // that is going, because its parent is about to stop existing.
            if ($staying->getParent() === $going) {
                $staying->setParent(null);
            }

            // And whatever sat under the entry that is going moves under the one
            // that replaced it — or under *its* parent, if it has one, because a
            // shared list is one level deep (§5.4) and a merge is not the place
            // to quietly make it two.
            $newParent = $staying->getParent() ?? $staying;

            foreach ($list->getEntries() as $child) {
                if ($child->getParent() === $going) {
                    $child->setParent($child === $newParent ? null : $newParent);
                }
            }

            $list->removeEntry($going);
            $this->entityManager->remove($going);
            $this->entityManager->flush();
        });

        $this->cache->clear();

        return $written;
    }
}

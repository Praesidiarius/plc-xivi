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

namespace App\Tests\Functional\Engine;

use App\Tests\Support\UnaskableType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Query\Operator;
use Xivi\Core\ValueList\ValueListUsage;

/**
 * A shared list reaches every field type that says it points at one (XIV-127).
 *
 * **XIV-144's registry test, one concept over, and written for the same reason
 * rather than by analogy.** That one exists because nothing anywhere compared
 * what a type *needs* with what the editor can *ask*, so two of them drifted
 * apart in silence. This one exists because nothing would compare what a type
 * says it *is* with the one option name every screen in this feature actually
 * reads.
 *
 * The defect it guards is worse than the one it is modelled on, which is why it
 * is worth a file. Everything consequential about a shared list — the count
 * beside each entry, the refusal that names where the records are, the merge
 * that rewrites them — comes out of one scan over the field definitions, and
 * that scan reads `options['list']`. A second type declaring
 * {@see PointsAtAList} and storing its list key under any other name would be
 * found by none of it:
 *
 *  * its records would never be counted, so every entry would look free;
 *  * an entry its records hold would therefore be removable from under them —
 *    the one thing §5.4 refuses in every other spelling;
 *  * a merge would rewrite every other field and leave that one saying the old
 *    thing for ever.
 *
 * **And nothing would report any of it.** Which is exactly the shape of failure
 * §8.3.1 exists to prevent, and exactly why this is a test about the registry
 * rather than about `choice`.
 *
 * Two halves, both needed, as in the test it is modelled on: the invariant over
 * every type the container really registers, and the **planted violation**,
 * because an invariant nobody has watched fail is an invariant nobody knows is
 * connected to anything (XIV-60).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ValueListReachesEveryTypeTest extends KernelTestCase
{
    /**
     * Every registered type that points at a shared list does so through the
     * option the scan reads.
     *
     * The message names what to do about it, because whoever this fails on is
     * somebody who has just written a field type and is about to conclude the
     * test is wrong.
     */
    public function testEveryTypePointingAtAListIsFoundByTheScan(): void
    {
        self::bootKernel();

        /** @var FieldTypeRegistry $registry */
        $registry = self::getContainer()->get(FieldTypeRegistry::class);

        foreach ($registry->all() as $key => $type) {
            self::assertTrue(ValueListUsage::readsItsList($type), sprintf(
                'The field type "%s" declares PointsAtAList and does not name "%s" among the ways of '
                . 'answering where its values come from. ValueListUsage reads exactly that option, so a '
                . 'field of this type would be invisible to the counts, to the refusal that stops an entry '
                . 'being removed from under records holding it, and to the merge — silently '
                . '(docs/architecture/data-model.md §5.4).',
                $key,
                ChoiceFieldType::LIST,
            ));
        }
    }

    /**
     * And `choice` is found *because* it declares the capability, not because
     * the scan happens to know what a choice is.
     */
    public function testTheChoiceTypeIsTheOneThatPointsAtAList(): void
    {
        self::bootKernel();

        /** @var FieldTypeRegistry $registry */
        $registry = self::getContainer()->get(FieldTypeRegistry::class);

        self::assertInstanceOf(PointsAtAList::class, $registry->get('choice'));
        self::assertNotInstanceOf(PointsAtAList::class, $registry->get('reference'));

        // **And the multi-value reference does not either** (XIV-113), which is
        // a decision rather than an omission and is therefore written down where
        // it can go red. Its values are records, not entries somebody keeps on a
        // list, so it points at a *module* and nothing here reaches it.
        self::assertNotInstanceOf(PointsAtAList::class, $registry->get('multi_reference'));

        // **And the promise §5.26 made about a multi-value field is exercised at
        // last** ([XIV-169]). That section settled, before either type existed,
        // that a field holding several of a list's entries would point at the
        // same `value_list` rows through the same option and the same
        // capability, and would inherit every refusal because this scan finds
        // fields by capability rather than by type name. `multi_choice` is that
        // field, and it declares this rather than keeping a list of its own: the
        // count beside an entry, the refusal that stops one being removed from
        // under records holding it, and the merge all reach it without
        // ValueListUsage learning a second name.
        self::assertInstanceOf(PointsAtAList::class, $registry->get('multi_choice'));
    }

    /**
     * A type that says it points at a list and keeps the key somewhere else is
     * refused by the same rule.
     *
     * The planted violation, fed to the real function rather than to a copy of
     * it. Not registered in the container, because a test that altered the
     * container would be testing a container nobody runs.
     */
    public function testATypeKeepingItsListSomewhereElseIsCaught(): void
    {
        self::assertFalse(
            ValueListUsage::readsItsList(new class extends UnaskableType implements PointsAtAList {
                public function needs(): array
                {
                    // A perfectly reasonable-looking declaration, and the scan
                    // would never find a field of this type: it reads `list` and
                    // this one keeps its key in `vocabulary`.
                    return [[ChoiceFieldType::CHOICES, 'vocabulary']];
                }

                /** Beside the point of this violation, and one option is one value ([XIV-169]). */
                public function findsHoldersBy(): Operator
                {
                    return Operator::Equals;
                }

                /**
                 * What `Enumerates` has asked of its implementors since XIV-168.
                 * Nothing in this test calls it: the violation being planted is
                 * about where the *list key* is kept, and the scan under test
                 * reads a declaration rather than any values.
                 */
                public function optionsOf(FieldDefinition $field): array
                {
                    return [];
                }
            }),
            'a type pointing at a list through some other option must not be invisible to the scan',
        );
    }
}

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

use App\Controller\FieldController;
use App\Tests\Support\UnaskableType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;

/**
 * The editor does not offer a field type it cannot configure (XIV-144).
 *
 * **The test this ticket exists for**, and it is deliberately about the
 * *registry* rather than about today's list of types. The defect was not that
 * `choice` and `reference` were forgotten; it was that nothing anywhere compared
 * the types a customer may add with the settings the editor can ask about, so
 * two of them drifted apart silently and the eleventh field type would have
 * drifted the same way.
 *
 * Two halves, and both are needed:
 *
 *  * **the invariant**, over every type the container actually registers: what
 *    the type says it cannot work without is something this editor draws a
 *    control for. That is what goes red when somebody writes a twelfth type and
 *    does not add its line;
 *  * **the planted violation**, because an invariant that has never been seen to
 *    fail is an invariant nobody knows is connected to anything — the lesson
 *    deptrac taught this project when every layer in it collected nothing for
 *    four months (XIV-60). A type that needs an answer nobody drew is defined
 *    here and offered to the same rule, which refuses it.
 *
 * A kernel test rather than a unit test only because the registry is a container
 * service: what is being asserted needs no tenant, no database and no request.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EditorConfiguresEveryTypeTest extends KernelTestCase
{
    /**
     * Every registered type is one the editor can ask the customer's question
     * for.
     *
     * The message names what to do about it, because whoever this fails on is
     * somebody who has just written a field type and is about to conclude the
     * test is wrong.
     */
    public function testTheEditorCanConfigureEveryRegisteredFieldType(): void
    {
        self::bootKernel();

        /** @var FieldTypeRegistry $registry */
        $registry = self::getContainer()->get(FieldTypeRegistry::class);

        foreach ($registry->all() as $key => $type) {
            self::assertTrue(FieldController::configurable($type), sprintf(
                'The field type "%s" says it needs %s, and the metadata editor draws no control for %s. '
                . 'Add the option to FieldController::PER_TYPE with the capability interface that declares '
                . 'it, and a control to templates/field/index.html.twig — or the type cannot be added by a '
                . 'customer at all (docs/architecture/data-model.md §5.4).',
                $key,
                implode(', ', self::optionsOf($type)),
                implode(', ', array_diff(
                    self::optionsOf($type),
                    array_keys(FieldController::PER_TYPE),
                )) ?: 'one of them',
            ));
        }
    }

    /**
     * And the two this ticket was about are configurable *because* of their
     * capability, not by accident.
     *
     * Reads the declared list rather than the rendered page: this is the wiring
     * — option to capability interface — and the page is tested where a customer
     * uses it ({@see FieldChoicesUiTest}).
     */
    public function testTheTwoOptionsThisTicketAddedAreWiredToACapability(): void
    {
        self::assertArrayHasKey(ChoiceFieldType::CHOICES, FieldController::PER_TYPE);
        self::assertArrayHasKey(ReferenceFieldType::MODULE, FieldController::PER_TYPE);

        self::bootKernel();

        /** @var FieldTypeRegistry $registry */
        $registry = self::getContainer()->get(FieldTypeRegistry::class);

        $choice = $registry->get('choice');
        $reference = $registry->get('reference');

        self::assertInstanceOf(NeedsAnAnswer::class, $choice);
        self::assertInstanceOf(NeedsAnAnswer::class, $reference);
        // One question each, and the choice field's has **two** answers since
        // [XIV-127]: its own options, or a shared list it is pointed at. Written
        // out rather than flattened, because the nesting is the statement — a
        // flat list would say a choice field needs both, which would refuse every
        // definition in every tenant.
        self::assertSame([[ChoiceFieldType::CHOICES, ChoiceFieldType::LIST]], $choice->needs());
        self::assertSame([[ReferenceFieldType::MODULE]], $reference->needs());
    }

    /**
     * Every way of answering is drawable, not merely one of them ([XIV-127]).
     *
     * The planted violation for the alternation itself, and it is the one this
     * ticket could most plausibly have broken: a type offering two answers of
     * which the editor can only ask for one *is* finishable through the form, so
     * a laxer rule would pass — and the second answer would be unreachable from
     * the only screen there is, which is XIV-144's silent gap one level in.
     */
    public function testATypeWhoseSecondAnswerNobodyDrewIsNotOfferedForAdding(): void
    {
        self::assertFalse(
            FieldController::configurable(new class extends UnaskableType {
                public function needs(): array
                {
                    // The first answer is drawn and the second is not, which is
                    // exactly the shape a `choice` field would have had if
                    // PER_TYPE had never learned about shared lists.
                    return [[ChoiceFieldType::CHOICES, 'bucket']];
                }
            }),
        );
    }

    /**
     * Every option any type names, flattened, for the message above.
     *
     * @return list<string>
     */
    private static function optionsOf(FieldType $type): array
    {
        return $type instanceof NeedsAnAnswer ? array_merge(...$type->needs()) : [];
    }

    /**
     * A type needing something nobody built a control for is refused by the same
     * rule.
     *
     * The planted violation. It is not registered in the container — a test that
     * altered the container would be testing a container nobody runs — because
     * the rule it is fed to is a pure function of the type and the declared
     * list, which is exactly why that function is public and static.
     */
    public function testATypeNeedingSomethingNobodyDrewIsNotOfferedForAdding(): void
    {
        self::assertFalse(
            FieldController::configurable(new class extends UnaskableType {
                public function needs(): array
                {
                    // An option no capability in PER_TYPE is keyed by, which is
                    // what "somebody wrote a field type and no control for it"
                    // looks like from here.
                    return [['bucket']];
                }
            }),
            'a type whose need nothing draws must not be offered in the add-field select',
        );
    }

    /**
     * And a type that needs nothing is offered, which is what stops the rule
     * above from being "no new types allowed".
     */
    public function testATypeThatNeedsNothingIsOffered(): void
    {
        self::bootKernel();

        /** @var FieldTypeRegistry $registry */
        $registry = self::getContainer()->get(FieldTypeRegistry::class);
        $text = $registry->get('text');

        self::assertNotInstanceOf(NeedsAnAnswer::class, $text);
        self::assertTrue(FieldController::configurable($text));
    }
}

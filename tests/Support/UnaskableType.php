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

namespace App\Tests\Support;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Query\Operator;

/**
 * A field type nobody built a control for — the planted violation, with only
 * {@see self::needs()} left to fill in (XIV-144, then XIV-127).
 *
 * **Why the violations are planted at all.** An invariant nobody has watched
 * fail is an invariant nobody knows is connected to anything, which is the
 * lesson deptrac taught this project when every layer in it collected nothing
 * for four months (XIV-60). So the rule that builds the add-field select is fed
 * a type it must refuse, and the test goes red if it stops refusing.
 *
 * **Why it is a class here rather than an anonymous one in the test.** XIV-144
 * had one violation and one anonymous class. XIV-127 needs a second — a type
 * whose *second* answer nobody drew, which is a different defect and the one
 * this ticket could most plausibly have introduced — and two copies of thirteen
 * empty methods is how the second one quietly stops matching the first. The only
 * thing that differs between them is the declaration, so the only thing a
 * subclass overrides is the declaration.
 *
 * It is deliberately **not registered in the container**: a test that altered
 * the container would be testing a container nobody runs. The rule it is fed to
 * is a pure function of the type and a declared list, which is exactly why that
 * function is public and static.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
abstract class UnaskableType implements NeedsAnAnswer
{
    public function key(): string
    {
        return 'attachment';
    }

    public function label(): string
    {
        return 'Attachment';
    }

    public function constraints(FieldDefinition $field): array
    {
        return [new Assert\Type('string')];
    }

    public function sample(FieldDefinition $field, int $sequence): mixed
    {
        return null;
    }

    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        return $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): mixed
    {
        return $value;
    }

    public function formType(): string
    {
        return TextType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [];
    }

    public function display(mixed $value, FieldDefinition $field): string
    {
        return (string) $value;
    }

    public function operators(): array
    {
        return [Operator::Equals];
    }

    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    public function defaultWidth(): int
    {
        return 6;
    }
}

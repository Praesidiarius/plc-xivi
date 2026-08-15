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

namespace Xivi\Core\Lifecycle;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Xivi\Core\Record\Record;

/**
 * Where symfony/workflow finds a record's state, and where it puts it back
 * (XIV-14).
 *
 * The component expects an entity with a property; a record is a payload whose
 * shape is decided per tenant at runtime (§5), so neither of the marking stores
 * it ships fits — `MethodMarkingStore` would need a getter this class of object
 * cannot have. Writing one is the whole adaptation, and it is nine lines,
 * because a record already *is* a bag of values and one of them is the state.
 *
 * One store per lifecycle, since the field it reads is the module's own.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordMarkingStore implements MarkingStoreInterface
{
    public function __construct(private Lifecycle $lifecycle)
    {
    }

    public function getMarking(object $subject): Marking
    {
        \assert($subject instanceof Record);

        $state = $subject->get($this->lifecycle->field);

        // A record saved before the lifecycle existed, or one whose field was
        // never filled in, is at the beginning rather than nowhere: a workflow
        // with no marking refuses every transition, which would strand exactly
        // the records that need moving.
        return new Marking([\is_string($state) && $state !== '' ? $state : $this->lifecycle->initial => 1]);
    }

    /** @param array<string, mixed> $context */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        \assert($subject instanceof Record);

        // Single-state by construction: these are workflows, not Petri nets, so
        // there is exactly one place to be at a time.
        $places = array_keys($marking->getPlaces());

        $subject->set($this->lifecycle->field, $places[0] ?? $this->lifecycle->initial);
    }
}

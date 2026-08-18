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

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The per-tenant unique field of §5.
 *
 * **This is the readable half, not the enforcing half** (XIV-109). For two
 * releases it was both, and that was the bug: a query that finds nothing and
 * then lets a save proceed is a read followed by a write with no lock across the
 * gap, so two saves arriving together both found nothing and both inserted.
 * There is now a unique expression index behind every unique field
 * ({@see \Xivi\Core\Record\UniqueIndex}), created and dropped as the flag moves,
 * and *that* is what is true.
 *
 * This stays, and stays first, because an index is a refusal and not a sentence.
 * It fires while the form is still on the screen, puts its message on the field
 * it is about, and lets somebody fix a typo without ever meeting a failed write.
 * The index catches only what this cannot see — the moment between the read and
 * the write — and {@see \Xivi\Core\Record\DuplicateValue} carries that back to
 * the same place this puts its message.
 *
 * The two agree on which rows count, deliberately: live records only, empty
 * values exempt. See the index for why each of those is the answer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class UniqueFieldValue extends Constraint
{
    public string $message = 'Another record already uses this value.';

    /**
     * @param string   $moduleKey the module whose table to look in
     * @param string   $fieldKey  the field that must be unique within it
     * @param int|null $exceptId  the record being edited, which must not collide with itself
     */
    public function __construct(
        public string $moduleKey = '',
        public string $fieldKey = '',
        public ?int $exceptId = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }
}

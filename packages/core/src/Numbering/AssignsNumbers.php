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

namespace Xivi\Core\Numbering;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\ValueDeriver;

/**
 * Giving a record its number as it is first saved (XIV-15).
 *
 * A {@see ValueDeriver}, which is the seam XIV-16 built for order totals — and
 * the first thing to use it that is not a module. That is the useful part of the
 * design being confirmed: the engine needed exactly what a module needed, so
 * there is no second mechanism for the engine's own derived values.
 *
 * **Only when the field is empty**, which is what "assigned once and never
 * changes" reduces to. An existing record carries its number through every save
 * because the number is already there; the field is derived, so no form ever
 * submits one to overwrite it with.
 *
 * **Allocated inside the save's transaction** ({@see NumberAllocator}), so a
 * save that fails gives the number back. A record that is created and later
 * deleted keeps its number — records are soft-deleted (§5), so that is a hole in
 * a list rather than a hole in the books, and the document behind the missing
 * number is still there to be looked at. That is the whole answer to gaps, and
 * it is deliberate: the alternative, handing the number out only once a document
 * is issued, leaves every draft with nothing to be called in a list or a link.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AssignsNumbers implements ValueDeriver
{
    public function __construct(private NumberAllocator $allocator)
    {
    }

    public function supports(ModuleDefinition $module): bool
    {
        foreach ($module->getFields() as $field) {
            if (NumberFormat::of($field) !== null) {
                return true;
            }
        }

        return false;
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        $now = new \DateTimeImmutable();

        foreach ($module->getFields() as $field) {
            $format = NumberFormat::of($field);
            $value = $derivation->fields[$field->getKey()] ?? null;

            if ($format === null || ($value !== null && $value !== '')) {
                continue;
            }

            $derivation->fields[$field->getKey()] = $format->render(
                $this->allocator->next($module->getKey(), $field->getKey(), $format->period($now)),
                $now,
            );
        }
    }
}

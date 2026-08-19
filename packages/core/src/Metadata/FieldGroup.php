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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * One run of fields drawn together, with or without a heading over it
 * (XIV-119).
 *
 * **This type exists so that the form and the record page cannot disagree.**
 * They are two templates reading the same definitions, which is exactly the
 * place grouping quietly diverges — one of them sorts by section and the other
 * by position, and six months later a customer is looking at a form in four
 * sections and a record page in one list. So the grouping is decided once, by
 * {@see ModuleDefinition::getFieldGroupsFor()}, and both templates are handed
 * the answer rather than the ingredients.
 *
 * A group with no section is the fields nobody has put anywhere. There is
 * exactly one of those and it is drawn **first**, which is the decision that
 * makes an existing definition unaffected: with no sections at all a shape
 * yields one group holding every field in its own order, which is the flat run
 * the form has always drawn.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldGroup
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(
        /** Null for the fields in no section, which is every field until somebody makes one. */
        public ?Section $section,
        public array $fields,
    ) {
    }

    /**
     * What the heading says, or null when there is no heading to draw.
     *
     * **The customer's own word, never a translation key.** That is not a new
     * decision: a field's label and a shape's label are both stored strings that
     * go to the page as they are, because the person who typed "Rechnungsdaten"
     * is not naming something this codebase has an English original of (§8.4.2).
     * A section name is the same kind of thing and gets the same treatment,
     * rather than a second answer to a question §5.4 has already answered.
     */
    public function heading(): ?string
    {
        return $this->section?->label;
    }
}

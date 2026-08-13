<?php

declare(strict_types=1);

namespace Xivi\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * `display(field, value)` in a template.
 *
 * Without it every list view would need to ask what kind of thing it is holding
 * — is this a date, does it need formatting — which is knowledge the field type
 * already owns. A template asking that question is a template that has to be
 * changed each time a field type is added.
 */
final class FieldDisplayExtension extends AbstractExtension
{
    public function __construct(private readonly FieldTypeRegistry $fieldTypes)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display', $this->display(...)),
        ];
    }

    public function display(FieldDefinition $field, mixed $value): string
    {
        return $this->fieldTypes->get($field->getType())->display($value, $field);
    }
}

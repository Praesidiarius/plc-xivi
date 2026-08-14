<?php

declare(strict_types=1);

namespace Xivi\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
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
            new TwigFunction('display_stored', $this->displayStored(...)),
            new TwigFunction('in_field_order', $this->inFieldOrder(...)),
        ];
    }

    /**
     * The same entries, in the order the shape declares its fields.
     *
     * Needed because `jsonb` does not keep the order keys were written in —
     * Postgres sorts them by length and then bytes — so a history entry read back
     * would list an address as "Bern, Work, Bahnhofstrasse 5" (§5.2). The
     * definitions still know the intended order, so it is restored here rather
     * than stored twice.
     *
     * Keys the shape no longer declares keep their place at the end instead of
     * disappearing: history outlives the fields it describes.
     *
     * @param array<string, mixed> $entries
     *
     * @return array<string, mixed>
     */
    public function inFieldOrder(?ShapeDefinition $shape, array $entries): array
    {
        if ($shape === null) {
            return $entries;
        }

        $ordered = [];

        foreach ($shape->getFields() as $field) {
            if (\array_key_exists($field->getKey(), $entries)) {
                $ordered[$field->getKey()] = $entries[$field->getKey()];
            }
        }

        return [...$ordered, ...array_diff_key($entries, $ordered)];
    }

    public function display(FieldDefinition $field, mixed $value): string
    {
        return $this->fieldTypes->get($field->getType())->display($value, $field);
    }

    /**
     * The same, for a value that is still in storage form.
     *
     * History keeps what it recorded exactly as it was stored (§5.2), so a date
     * in an old entry is a string rather than a date object. Sending it through
     * the type both ways means an entry from last year renders like the field it
     * describes, instead of like whatever JSON happened to hold.
     */
    public function displayStored(FieldDefinition $field, mixed $stored): string
    {
        $type = $this->fieldTypes->get($field->getType());

        return $type->display($type->fromStorage($stored, $field), $field);
    }
}

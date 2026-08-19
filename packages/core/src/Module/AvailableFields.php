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

namespace Xivi\Core\Module;

use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Which of a blueprint's fields a customer can actually have (XIV-104).
 *
 * The header-field counterpart of {@see \Xivi\Core\Metadata\AvailableVariants},
 * and it exists for a case that one cannot reach. An order may name a voucher,
 * and vouchers are a module a customer may not have bought: the link is `uses`
 * rather than `requires` (XIV-23), because an order book is a perfectly good
 * thing to keep without ever running a promotion. What that customer must not
 * get is a **"Voucher" control with an empty picker behind it** on every order
 * they ever type — offered, unfillable, and describing a feature they do not own.
 *
 * XIV-23's answer works for a *row kind*: the whole kind is hidden, so the link
 * inside it is never drawn. A field on the record itself has no kind to hide it
 * with. So it is hidden the only other way a field can be, which is by **not
 * being installed** — and that turns out to be the better answer rather than the
 * remaining one, because a definition that does not exist is invisible
 * everywhere at once: the form, the list, the record page, the import, the
 * export, the document templates and the history all read the customer's
 * definitions, and none of them needs to learn a rule.
 *
 * ### It is not retro-fitting, and §7.2.1 is what carries it afterwards
 *
 * Installing is a seed and the customer's definitions are the truth from that
 * moment (§6.1), so this decides once and never revisits. A customer who buys
 * vouchers a year after their orders is therefore *offered* the field by the
 * upgrade screen ({@see ModuleUpgrade}), which is exactly what that screen is
 * for and which asks them rather than deciding for them. The same rule runs
 * there, so an order-only customer is not offered a field pointing at a module
 * they have not got.
 *
 * ### Two narrowings, and both matter
 *
 * **A field scoped to a variant is left alone**, because a variant is
 * {@see \Xivi\Core\Metadata\AvailableVariants}' business and the two rules would
 * fight. A voucher's `article` link belongs to the free-article kind only, and
 * that class hides the *kind* by noticing the required link inside it — take the
 * field away here and the kind would look fillable and be offered with nothing in
 * it, which is the precise failure XIV-103 wrote a test against.
 *
 * **Only a reference into a module.** Nothing else about a blueprint depends on
 * what else is installed, and a rule that grew past this would start deciding
 * which of somebody's fields they are allowed to have.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AvailableFields
{
    public function __construct(private MetadataRepository $metadata)
    {
    }

    /**
     * The same fields, minus the ones pointing nowhere.
     *
     * @param list<FieldBlueprint> $fields
     *
     * @return list<FieldBlueprint>
     */
    public function of(array $fields): array
    {
        return array_values(array_filter($fields, $this->has(...)));
    }

    /** Whether this customer can be given that field at all. */
    public function has(FieldBlueprint $field): bool
    {
        // The key as a string, the way {@see \Xivi\Core\Metadata\AvailableVariants}
        // asks the same question: a field type's key is what a *definition*
        // carries, and the class behind it is not something core matches on.
        if ($field->type !== 'reference' || $field->variants !== []) {
            return true;
        }

        $module = $field->options[ReferenceFieldType::MODULE] ?? null;

        // A reference with no module named is a module author's mistake and not
        // this class's to report: the field type says so at the moment somebody
        // opens a form, with the better message.
        return !\is_string($module) || $this->metadata->find($module) !== null;
    }
}

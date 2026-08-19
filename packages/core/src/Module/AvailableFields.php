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
 * ### Two narrowings, and one of them was halved (XIV-122)
 *
 * **A *required* field scoped to a variant is left alone**, because a variant is
 * {@see \Xivi\Core\Metadata\AvailableVariants}' business and the two rules would
 * fight over it. That class hides the whole *kind* when a required link inside it
 * points nowhere — take the field away here and the kind would look fillable and
 * be offered with nothing in it, which is the precise failure XIV-103 wrote a test
 * against.
 *
 * **An *optional* one is taken away, and that is this class's alone.** XIV-104
 * wrote the narrowing as "scoped to a variant" because the only such reference in
 * the codebase then was required, so the two spellings agreed. XIV-122 made one
 * optional — a line voucher may name an article as a restriction or name none —
 * and the spellings came apart: `AvailableVariants` deliberately says nothing
 * about an optional link, since a link nobody has to fill in is a link that can
 * stay empty. So there is no fight to avoid, and nothing else would hide the
 * field. Left in, an installation with no catalogue would be shown a "Restricted
 * to" picker with nothing behind it on every voucher of that kind — the exact
 * empty picker the paragraphs above exist to prevent, arriving through the gap
 * between the two rules rather than in front of either.
 *
 * Stated as one sentence: **a required variant-scoped reference is
 * `AvailableVariants`' to hide; an optional one is this class's.** Between them
 * every reference into a module is covered exactly once.
 *
 * **Only a reference into a module.** Nothing else about a blueprint depends on
 * what else is installed, and a rule that grew past this would start deciding
 * which of somebody's fields they are allowed to have.
 *
 * ### It applies to a collection's fields too (XIV-122)
 *
 * A line may name a voucher just as a record may, and an order line does. Nothing
 * about the argument above is about *where* the field sits — a shape is a shape
 * (§5.1) — and the class was only ever asked about a module's own fields because
 * XIV-104 had no collection field to ask about. Both places a definition is born
 * ask it now: the installer, for a collection installed with its module, and
 * {@see ModuleUpgrade}, for a collection field offered afterwards.
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
     * @param string|null          $own    the key of the module these fields belong to
     *
     * @return list<FieldBlueprint>
     */
    public function of(array $fields, ?string $own = null): array
    {
        return array_values(array_filter($fields, fn (FieldBlueprint $field): bool => $this->has($field, $own)));
    }

    /**
     * Whether this customer can be given that field at all.
     *
     * @param string|null $own the key of the module the field belongs to, when the
     *                         caller knows it. **A module always has itself**, and
     *                         saying so is not a special case but the only correct
     *                         reading: a contact's `company` link points at
     *                         contacts, and asking the metadata whether contacts
     *                         are installed *while installing them* answers no,
     *                         because the definitions are written at the end of the
     *                         same method. Without this a self-reference would be
     *                         dropped from every installation that ever had one —
     *                         which is exactly what happened the first time this
     *                         class was widened past a required field (XIV-122),
     *                         and what the suite said within the minute
     */
    public function has(FieldBlueprint $field, ?string $own = null): bool
    {
        // The key as a string, the way {@see \Xivi\Core\Metadata\AvailableVariants}
        // asks the same question: a field type's key is what a *definition*
        // carries, and the class behind it is not something core matches on.
        if ($field->type !== 'reference') {
            return true;
        }

        // Scoped to a variant *and* required is the one case that belongs to
        // somebody else — see the class docblock, where the halving of this
        // narrowing is argued at length. Optional falls through, because nothing
        // else in the engine would hide it.
        if ($field->variants !== [] && $field->required) {
            return true;
        }

        $module = $field->options[ReferenceFieldType::MODULE] ?? null;

        // A reference with no module named is a module author's mistake and not
        // this class's to report: the field type says so at the moment somebody
        // opens a form, with the better message.
        return !\is_string($module) || $module === $own || $this->metadata->find($module) !== null;
    }
}

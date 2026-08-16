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

namespace Xivi\Core\Demo;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * One value for one field: whatever the field said it wanted, or the type's
 * guess when it said nothing (XIV-24).
 *
 * The generator knows a field's *type* and its *bounds* and nothing about what it
 * means, which is most of its value — it fills a field somebody added in the
 * editor this morning without having heard of that field (§5.4). It is also the
 * whole of the complaint: `tax_rate` allows 0 to 100, so a uniform draw across
 * that range produced 63.90 and 40.55, every one of them valid and almost none a
 * VAT rate. **A range is not a distribution.** Real numeric fields cluster hard,
 * and the uniform draw is the one shape real data never has.
 *
 * So the question was never "how does the generator guess better" — a table of
 * special cases keyed on field names would put a second place that knows what an
 * article is beside the module itself, which is the tax §5 exists to remove. The
 * question is **what is the smallest thing a field can say about itself so the
 * guess is good**, and the precedent was already there: inherited values, number
 * formats and column widths are all declarations on the field rather than code
 * that knows the module.
 *
 * Hence one option, `samples`: a list of values this field's demo data should be
 * drawn from. One line on the field that has an opinion, nothing at all on the
 * thousands that do not, and **no field type had to change** — the list is taken
 * before the type is asked, and every type keeps sampling exactly as it did.
 *
 * **A declared value is treated as though somebody had typed it**, and nothing
 * here converts it: the write goes through `RecordWriter`, which runs every value
 * through its type's `toStorage()` — so `8.1` on a decimal is stored `8.10`, the
 * same as it would be from the form. A declaration is therefore worth exactly as
 * much care as a default: a value the field would refuse is a value the generator
 * will happily write, in the same way a `min` above a `max` is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldSampler
{
    /**
     * The option a field declares its demo values in.
     *
     * Code-only for now, in the sense that no form draws it — and stored like
     * every other option, so a tenant that has one keeps it. The editor already
     * leaves alone what it does not draw (§5.4).
     */
    public const string OPTION = 'samples';

    public function __construct(private FieldTypeRegistry $fieldTypes)
    {
    }

    public function sample(FieldDefinition $field, int $sequence): mixed
    {
        $declared = self::declaredOn($field);

        // The fallback is the point of the acceptance criterion, so it is worth
        // saying what it costs: an array lookup, and — because nothing above this
        // line draws a random number — *not one call to mt_rand*. A field that
        // declares nothing therefore consumes the seeded sequence exactly as it
        // did before this class existed, which is what makes "generates exactly
        // as it does today" a fact rather than a hope.
        if ($declared === []) {
            return $this->fieldTypes->get($field->getType())->sample($field, $sequence);
        }

        // Drawn from the same seeded sequence as everything else, so `--seed`
        // still makes a run repeatable — which record got which rate is as fixed
        // as which record got which name.
        return $declared[mt_rand(0, \count($declared) - 1)];
    }

    /**
     * The values this field declared, minus the ones it cannot have.
     *
     * **Weighting is repetition, not a second concept.** "Some articles with no
     * VAT at all" needs either weights beside the values or an empty value among
     * them, and the second is smaller: the list stays a list, the draw stays
     * uniform, and a value that should come up more often is simply written
     * twice. `FakerSampleValues::country()` has been doing exactly that with
     * `['CH', 'CH', 'CH', 'DE', ...]` since it was written, so this is the
     * project's own idiom rather than a new one.
     *
     * Two things are dropped rather than trusted, because both would break the
     * promise the generator is actually measured on — that every record it makes
     * is one the module's own validation accepts:
     *
     * - **`null` on a required field.** Empty is a real value and belongs in the
     *   list; a required field is the one place it cannot be, and the field
     *   already says so.
     * - **Everything, on a unique field.** A fixed list is the one thing that
     *   cannot fill a unique column: the second record drawn from it collides.
     *   The type's own sample knows to put the sequence number on the end, so the
     *   honest answer is to let it — a field cannot be both "these few values"
     *   and "a different value every time".
     *
     * @return list<mixed>
     */
    private static function declaredOn(FieldDefinition $field): array
    {
        $declared = $field->getOption(self::OPTION);

        if (!\is_array($declared) || $declared === [] || $field->isUnique()) {
            return [];
        }

        // A map rather than a list — which is what a hand-edited options JSON
        // comes back as once a key has been removed from it — is read as its
        // values. Refusing it would fail a whole demo run over a gap in an array,
        // and there is nothing else the keys could have meant here.
        $declared = array_values($declared);

        if (!$field->isRequired()) {
            return $declared;
        }

        return array_values(array_filter(
            $declared,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }
}

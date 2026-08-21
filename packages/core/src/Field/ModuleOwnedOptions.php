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

namespace Xivi\Core\Field;

use Xivi\Core\Demo\FieldSampler;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\DecimalFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\InheritedValue;

/**
 * The options in a field's `options` that belong to the module rather than to
 * the customer, and what they are worth right now ([XIV-176]).
 *
 * §6.1 says a blueprint is a seed and nothing retro-fits it, and §7.2.1 says a
 * key the shape already has is never offered. Both are right about what they are
 * about, and between them they made a module unable to correct its own field
 * options on a tenant that already installed it: XIV-172 narrowed the order's
 * two voucher pickers by adding `variant` to the blueprint, and every existing
 * tenant kept `{"module":"voucher","samples":[null]}` for ever, with no route
 * for the narrowing to arrive.
 *
 * **§6.1 protects the customer's decisions, not their row.** `variant` is not a
 * decision anybody made: `_type_options.html.twig` draws no control for it,
 * {@see \Xivi\Core\Metadata\MetadataEditor::updateField()} has no branch that
 * writes it, and the only writer besides {@see \Xivi\Core\Module\ModuleInstaller}
 * is `assertOptionsSurvive()`, clearing it when a field's target module moves.
 * So it is read live from the blueprint and **nothing is written**: no screen, no
 * console command, no consent, no per-tenant state.
 *
 * The precedent is already shipped one file over, on {@see FieldDefinition::$width}:
 * null there means "whatever this kind of field wants, and keeps following it",
 * which is an effective value that follows the code for every field nobody
 * overrode. This is the same rule for an option nobody can override.
 *
 * ### One list rather than a flag on each option
 *
 * Option keys are strings shared across types (`min` means the same thing on
 * every type that has one), and no two types disagree about who owns a key. So
 * ownership is a property of the key, and it is written down once here. The
 * inverse declaration already exists: {@see \App\Controller\FieldController::PER_TYPE}
 * lists every option the editor draws a control for, and
 * `ModuleOwnedOptionsAreDeclaredTest` holds the two lists to being disjoint and
 * to covering every key any shipped blueprint sets.
 *
 * If a type ever does disagree about a key, the escape hatch is a method on the
 * type, the way {@see Enumerates::findsHoldersBy()} moved a question onto the
 * type when the editor could no longer answer it. Nothing needs that today.
 *
 * ### Declared and live-read are separate questions
 *
 * {@see self::DECLARED} is who owns the key. {@see self::LIVE} is which of them
 * this class actually resolves, and it is smaller on purpose:
 *
 * - **`scale` is not live-read.** It decides how a value already in storage is
 *   read back, which is §5.21's complaint and which XIV-146 settled: a
 *   conversion restates values somebody typed, so a module may make it obvious
 *   and only the customer may make it happen.
 * - **`samples` is not live-read.** Nothing needs it. It affects demo generation
 *   and nothing else, and it was proposed for tidiness rather than for a
 *   failure.
 * - **`inherit` is not live-read**, and this is the one worth reading twice.
 *   {@see InheritedValue::of()} is static and is asked of the definition
 *   directly by {@see \Xivi\Core\Record\InheritedValues} twice and by
 *   {@see \Xivi\Core\Mail\CollectionTables} once. Putting the key on
 *   {@see self::LIVE} without rewiring those three would mean `tenant:inspect`
 *   reporting a value the engine does not use, which is a worse state than not
 *   resolving it at all. Only `variant`'s live read is earned, by XIV-172, so
 *   the wiring is not done here on the chance that a module changes an
 *   `inherit` some day. If one does, rewire those three call sites through this
 *   class and add the key here, in that order.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleOwnedOptions
{
    /**
     * Every option key a module owns: no control draws it, no editor path writes
     * it, and the installer is the only thing that ever put it there.
     *
     * @var list<string>
     */
    public const array DECLARED = [
        ReferenceFieldType::VARIANT,
        FieldSampler::OPTION,
        InheritedValue::OPTION,
        DecimalFieldType::SCALE,
    ];

    /**
     * And the ones whose value is taken from the blueprint every time it is
     * read. A subset of the above, and see the class docblock for why the other
     * two are not on it.
     *
     * @var list<string>
     */
    public const array LIVE = [
        ReferenceFieldType::VARIANT,
    ];

    public function __construct(
        private ModuleRegistry $modules,
        // For the guard below, which asks what the *tenant's* shapes can
        // express. Read through the same cache every request already reads, so
        // resolving a narrowing costs a query the page had made anyway.
        private MetadataRepository $metadata,
    ) {
    }

    /**
     * What this field's options are worth now: the stored ones, with the
     * live-read keys taken from the blueprint this build ships.
     *
     * Three ways of being none of this class's business, and each of them hands
     * the stored options straight back:
     *
     * - **A field the customer added.** `is_system` is false, so no module ever
     *   claimed these options and none of them is a module's to correct.
     * - **A module this build no longer ships.** There is no blueprint to read,
     *   and inventing one would be worse than leaving the definition alone.
     * - **A field the blueprint no longer declares.** The customer keeps it,
     *   which is §5.4's promise, and it keeps what it was installed with.
     *
     * A key the blueprint *stopped* setting is removed rather than left, which
     * is the same sentence read the other way: the effective value of a
     * module-owned key is what the module says today, including "nothing".
     *
     * @return array<string, mixed>
     */
    public function of(FieldDefinition $field): array
    {
        $stored = $field->getOptions();

        if (!$field->isSystem()) {
            return $stored;
        }

        $declared = $this->blueprintOf($field);

        if ($declared === null) {
            return $stored;
        }

        $effective = $stored;

        foreach (self::LIVE as $key) {
            if (\array_key_exists($key, $declared->options)) {
                // Assigned rather than removed and re-added, so a key the tenant
                // already stored keeps its position and `tenant:inspect` prints
                // the same JSON in the same order it always did.
                $effective[$key] = $declared->options[$key];
            } else {
                unset($effective[$key]);
            }
        }

        return $this->guarded($effective);
    }

    /**
     * A module-owned narrowing is honoured only where the tenant's own shapes
     * can express it, and dropped otherwise.
     *
     * **Without this a narrowing does not merely fail to apply, it empties every
     * picker in the tenant.** {@see \Xivi\Core\Query\QueryCompiler::variantGroup()}
     * returns the SQL string `FALSE` when the target shape has no variant field,
     * and {@see \Xivi\Core\Record\RecordCandidates::isOneOf()} returns false for
     * the same case, both deliberately, because "which of these kinds is it"
     * has no true answer for a shape with no kinds. Live-reading a narrowing
     * into a tenant whose target module cannot name those kinds would therefore
     * turn a working picker into one that lists nothing at all. That is the
     * failure this method exists to prevent, and it is why deleting it is not
     * tidying.
     *
     * The real case, not a hypothetical one: XIV-133 narrows an order line's
     * article to `plain` and `sku`, and a tenant whose article module has no
     * `kind` field cannot say either. The pre-XIV-122 voucher shape, whose kinds
     * were `absolute`, `relative` and `free_article`, fails the same way.
     *
     * Three conditions, in the order they stop being answerable: the target
     * module is installed, it names a variant field, and every kind the
     * narrowing names is among that field's own stored choices. Failing any of
     * them drops the option, so the field behaves as an unnarrowed reference,
     * which is a working picker rather than an empty one.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function guarded(array $options): array
    {
        $variants = ReferenceFieldType::variantsIn($options);

        if ($variants === []) {
            return $options;
        }

        $target = $this->metadata->find(ReferenceFieldType::moduleIn($options));
        $choices = [];

        if ($target !== null && $target->getVariantField() !== null) {
            $choices = $target->getVariants();
        }

        foreach ($variants as $variant) {
            if (!\array_key_exists($variant, $choices)) {
                unset($options[ReferenceFieldType::VARIANT]);

                return $options;
            }
        }

        return $options;
    }

    /**
     * The blueprint field this definition was installed from, or null when there
     * is not one any more.
     *
     * Matched by shape and key rather than by anything stored on the row,
     * because nothing on a definition records where it came from and §6.1 is
     * deliberate about that: recording which preset installed a tenant would
     * invite something to re-apply it.
     *
     * A collection's fields are looked up in that collection and never in the
     * module, which matters rather than reads as care: an order's `lines`
     * collection and the order itself both have a field called `voucher`, and a
     * search that fell through to the module's list would hand a line the
     * document's narrowing.
     */
    private function blueprintOf(FieldDefinition $field): ?FieldBlueprint
    {
        $shape = $field->getShape();
        $module = $shape instanceof CollectionDefinition ? $shape->getParent() : $shape;

        if (!$module instanceof ModuleDefinition || !$this->modules->has($module->getKey())) {
            return null;
        }

        $blueprint = $this->modules->get($module->getKey());
        $fields = $blueprint->fields;

        if ($shape instanceof CollectionDefinition) {
            $fields = null;

            foreach ($blueprint->collections as $collection) {
                if ($collection->key === $shape->getKey()) {
                    $fields = $collection->fields;

                    break;
                }
            }

            if ($fields === null) {
                return null;
            }
        }

        foreach ($fields as $declared) {
            if ($declared->key === $field->getKey()) {
                return $declared;
            }
        }

        return null;
    }
}

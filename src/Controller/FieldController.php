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

namespace App\Controller;

use App\Tenant\Security\NoModulePermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Numbers;
use Xivi\Core\Field\UnknownFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Numbering\AssignsNumbers;
use Xivi\Core\Numbering\CounterRefused;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Numbering\NumberFormat;

/**
 * The metadata editor: a customer changing the shape of their own module.
 *
 * This is the engine's claim made usable. Everything else reads field
 * definitions; this writes them, and a field added here appears in the form, the
 * list, the validation and the filter bar without anything being deployed —
 * because all four are reading the same rows (§5.4).
 *
 * Admin only, which is the first thing in the application to need more than
 * "signed in". §8.4 says the real model is unfinished; changing what a module
 * *is* seemed the wrong place to keep waiting for it.
 *
 * It edits any shape, so a collection's fields are editable exactly like a
 * module's — the same reason there is one record repository (§5.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/fields', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted('ROLE_ADMIN')]
#[NoModulePermission(
    'Changing what a module *is* is not one of the things you do *to* its '
    .'records, so it is not one of its permissions. A grant says who may edit a '
    .'contact; this decides what a contact has. Administrators only (§5.4).',
)]
final class FieldController extends AbstractController
{
    /**
     * The settings this form draws, and therefore the only ones it may change.
     *
     * A short list on purpose: everything else a field carries is declared by
     * its module and has no control here (XIV-26). These three are drawn for
     * every field whether or not they mean anything on it, which is what makes
     * them different from the per-type settings below — the better question
     * §5.4 asked, and the one PER_TYPE answers.
     */
    private const array SETTINGS = ['max_length', 'min', 'max'];

    /**
     * The settings whose control depends on the field's *type*, and what
     * declares each (XIV-36, XIV-27).
     *
     * Everything in SETTINGS above is a number and is drawn for every field
     * whether or not it means anything there. These are not: a `text` field has
     * nothing to autocomplete against and a `date` cannot be a document number,
     * and a control that does nothing is worse than an absent one — somebody
     * fills it in and waits for something to happen.
     *
     * So the *type* is asked, by implementing a capability interface. XIV-36
     * introduced that with a single `instanceof` and said in as many words that
     * generalising from one example would be guessing; there are two now, which
     * is the point at which the shape §5.4 has always described — **a type
     * saying which of its options are the customer's to set** — can be written
     * from evidence rather than from imagination. This is that list. A third
     * option is a capability interface, a line here and a control in the
     * template, rather than another branch through this class.
     *
     * What stays per option, deliberately, is *drawing* it: a select of three
     * fixed answers and a numbering pattern with a live preview have nothing in
     * common but the question "may this type have one". Generalising the control
     * as well would be inventing a widget description language to save two
     * `{% if %}`s.
     *
     * @var array<string, class-string<FieldType>>
     */
    private const array PER_TYPE = [
        Autocomplete::OPTION => Autocompletes::class,
        NumberFormat::OPTION => Numbers::class,
    ];

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly MetadataEditor $editor,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TranslatorInterface $translator,
        private readonly NumberAllocator $counters,
    ) {
    }

    #[Route('', name: 'field_index', methods: ['GET'])]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('field/index.html.twig', [
            'module' => $definition,
            'shapes' => self::shapesOf($definition),
            'types' => $this->fieldTypes->all(),
            // Which types offer which per-type control, as lists of type keys
            // (XIV-36, XIV-27). Resolved here rather than in the template
            // because Twig has no `instanceof`, and giving it one so a page
            // could ask what a service implements would be a worse answer than
            // a list of strings.
            'settable' => $this->settableByType(),
            'autocompleteChoices' => Autocomplete::settable(),
            // And which *fields* are actually numbered, which the type cannot
            // answer: the link to the numbering page appears on those, and the
            // page itself refuses everything else on the same test.
            'numbered' => $this->numberedIn($definition),
        ]);
    }

    /**
     * Numbering, on its own page rather than in a cell of the field table
     * (XIV-27).
     *
     * The table is a row per field and a control per column, which fits a
     * checkbox and a select and does not fit this. What a customer is deciding
     * here is a small language with quiet failure modes — a pattern that numbers
     * nothing, a width too narrow to keep sorting, a `{year}` that silently
     * changes which counter the next number comes out of — and the answer to
     * every one of those is the same: **show them the number it will produce**,
     * beside a sentence naming the counter it will come from. That needs room,
     * and it needs to re-render as somebody types, which is what
     * {@see \App\Twig\Components\FieldNumbering} is for.
     *
     * Reachable only for a field that is numbered already, and that is this
     * ticket's scope valve rather than an oversight: making an ordinary text
     * field numbered is a question about the records it already holds, and §5.10
     * says at length why that is a different feature.
     */
    #[Route('/{field}/numbering', name: 'field_numbering', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function numbering(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->numberedField($definition, $field);

        return $this->render('field/numbering.html.twig', [
            'module' => $definition,
            'field' => $target,
        ]);
    }

    /**
     * Saving both halves of it, or neither (XIV-27).
     *
     * **The counter first.** It is the half that can be refused for a reason the
     * customer has to act on — a number at or below one already given out — and
     * doing it first means a refusal leaves the definition exactly as it was.
     * The other order would save the pattern, fail on the counter, and leave
     * somebody looking at a page that had done half of what they asked without
     * saying which half.
     *
     * Neither half is validated here beyond finding the counter to write to. The
     * pattern is checked by {@see MetadataEditor}, on the write path, where
     * every other caller meets it too; a controller that checked it as well
     * would be a second copy of the rule, and the second copy is the one that
     * gets forgotten.
     */
    #[Route('/{field}/numbering', name: 'field_numbering_save', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function saveNumbering(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->numberedField($definition, $field);
            $pattern = trim((string) $request->request->get(NumberFormat::OPTION));

            try {
                $this->restartCounter($definition, $target, $pattern, trim((string) $request->request->get('next_value')));

                $this->editor->updateField(
                    field: $target,
                    label: $target->getLabel(),
                    required: $target->isRequired(),
                    unique: $target->isUnique(),
                    filterable: $target->isFilterable(),
                    listed: $target->isListed(),
                    title: $target->isTitle(),
                    position: $target->getPosition(),
                    // The one thing this page changes. Everything else is the
                    // field as it already is, and everything the page has never
                    // heard of is left alone by the merge (XIV-26) — this form
                    // draws one control and therefore names one option.
                    options: [NumberFormat::OPTION => $pattern],
                    width: $target->getWidth(),
                );

                $this->addFlash('success', $this->translator->trans('flash.numbering_saved', [
                    '%field%' => $target->getLabel(),
                ]));
            } catch (MetadataChangeRefused|CounterRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                // Back to the numbering page rather than to the list: what was
                // typed was refused, and the page it was typed on is where the
                // explanation makes sense.
                return $this->redirectToRoute('field_numbering', ['module' => $module, 'field' => $field]);
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * Winding the counter forward, when a number was asked for.
     *
     * Blank means "leave the counter alone", which is what almost every save of
     * this page means: changing a prefix has nothing to do with where the
     * sequence has got to. A counter is not a setting with a current value the
     * form round-trips — it is a fact about what has been given out — so the
     * control is empty until somebody deliberately types in it.
     *
     * The counter it writes to is the one the **new** pattern implies, not the
     * old one. That is the whole of the surprise this page exists to make
     * visible: adding `{year}` moves to a counter of its own, and somebody
     * setting 1043 while adding it means 1043 in the new numbering.
     *
     * @throws CounterRefused
     */
    private function restartCounter(
        ModuleDefinition $module,
        FieldDefinition $field,
        string $pattern,
        string $next,
    ): void {
        $format = NumberFormat::parse($pattern);

        if ($next === '' || !ctype_digit($next) || $format === null) {
            // Three ways of having nothing to do, and none of them is this
            // method's to complain about. An unusable pattern is refused a few
            // lines below with a sentence about patterns, which is the thing
            // that was actually wrong. Anything that is not digits is a number
            // control that has been edited by hand — `-5` or `four` — and it is
            // treated as blank rather than clamped, the same way a width is
            // (see widthFrom): a form that quietly turns nonsense into a value
            // tells somebody they got what they asked for. Zero *is* digits and
            // goes through, to be refused by the counter as the wind-back it is.
            return;
        }

        $this->counters->restartAt(
            $module->getKey(),
            $field->getKey(),
            $format->period(new \DateTimeImmutable()),
            (int) $next,
        );
    }

    /**
     * What a customer calls one of their own shapes (XIV-8).
     *
     * Their module arrived named by whatever language it was installed in, and
     * "Contacts" is not the word a German office uses. Renaming it is the same
     * kind of change as relabelling a field, so it lives on the same screen and
     * goes through the same editor.
     */
    #[Route('/{shape}/rename', name: 'shape_rename', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function rename(string $module, int $shape, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            try {
                $target = $this->shape($definition, $shape);
                $this->editor->renameShape($target, (string) $request->request->get('label'));

                $this->addFlash('success', $this->translator->trans('flash.shape_renamed', [
                    '%shape%' => $target->getLabel(),
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    #[Route('/add', name: 'field_add', methods: ['POST'])]
    public function add(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $shape = $this->shape($definition, (int) $request->request->get('shape'));

            try {
                $field = $this->editor->addField(
                    shape: $shape,
                    key: (string) $request->request->get('key'),
                    label: (string) $request->request->get('label'),
                    type: (string) $request->request->get('type'),
                    required: $request->request->getBoolean('required'),
                    unique: $request->request->getBoolean('unique'),
                    filterable: $request->request->getBoolean('filterable'),
                    listed: $request->request->getBoolean('listed'),
                    title: $request->request->getBoolean('title'),
                    options: $this->optionsFrom($request, (string) $request->request->get('type')),
                );

                $this->addFlash('success', $this->translator->trans('flash.field_added', ['%field%' => $field->getLabel()]));
                // UnknownFieldType too: the select is built from the registry, so a
                // type it does not know means a tampered form, which is a message
                // rather than a stack trace.
            } catch (MetadataChangeRefused|UnknownFieldType $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    #[Route('/{field}', name: 'field_update', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function update(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->field($definition, $field);

            try {
                $this->editor->updateField(
                    field: $target,
                    label: (string) $request->request->get('label'),
                    required: $request->request->getBoolean('required'),
                    unique: $request->request->getBoolean('unique'),
                    filterable: $request->request->getBoolean('filterable'),
                    listed: $request->request->getBoolean('listed'),
                    title: $request->request->getBoolean('title'),
                    position: $request->request->getInt('position', $target->getPosition()),
                    options: $this->optionsFrom($request, $target->getType()),
                    // Blank means "however wide this kind of field usually is"
                    // (XIV-43), which is a real answer and not a missing one.
                    width: self::widthFrom($request),
                );

                $this->addFlash('success', $this->translator->trans('flash.field_saved', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * The width a form sent, or null for "follow the field type" (XIV-43).
     *
     * An empty box is the default rather than a zero: the control offers a blank
     * option and that blank is what almost every field should keep. Anything
     * outside 1-12 is nonsense from a hand-edited form and is treated as blank
     * rather than clamped, because a form that quietly turns 40 into 12 tells
     * somebody they got what they asked for.
     */
    private static function widthFrom(Request $request): ?int
    {
        $width = trim((string) $request->request->get('width'));

        if ($width === '' || !ctype_digit($width)) {
            return null;
        }

        $width = (int) $width;

        return $width >= 1 && $width <= 12 ? $width : null;
    }

    /**
     * The confirmation, which exists to say what removing a field does *not* do.
     *
     * Somebody clicking delete reasonably assumes the data goes with it. It does
     * not, so this says so, and says how many records are holding a value.
     */
    #[Route('/{field}/delete', name: 'field_confirm_delete', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function confirmDelete(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->field($definition, $field);

        return $this->render('field/delete.html.twig', [
            'module' => $definition,
            'field' => $target,
            'holding' => $this->editor->recordsHolding($target),
        ]);
    }

    #[Route('/{field}/delete', name: 'field_delete', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->field($definition, $field);

            try {
                $this->editor->removeField($target);
                $this->addFlash('success', $this->translator->trans('flash.field_removed', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * The module and its collections, in the order they are edited.
     *
     * @return list<ShapeDefinition>
     */
    private static function shapesOf(ModuleDefinition $module): array
    {
        return [$module, ...array_values($module->getCollections()->toArray())];
    }

    /**
     * The per-type settings this form draws, and only those (XIV-26).
     *
     * **Every one of them is named on every save, cleared ones as null**, and
     * nothing else is named at all. That is the contract MetadataEditor merges
     * on: what the form does not mention it does not touch, so a field's
     * `choices`, its `module`, what it inherits and how it is numbered all
     * survive an edit this form has no idea it is next to.
     *
     * Blank means gone rather than absent, so a limit somebody has emptied out
     * really is emptied — a form that could only ever add a setting would be the
     * opposite bug.
     *
     * The autocomplete setting joins them for the types that offer it (XIV-36),
     * and is named on exactly the same terms: the select always sends a value,
     * blank means "decide from the count", and blank therefore clears rather
     * than leaves alone. A type that does not offer it is not named at all, so a
     * text field's save says nothing about autocomplete and could not clear one
     * even if something had put it there.
     *
     * @return array<string, int|string|null>
     */
    private function optionsFrom(Request $request, string $type): array
    {
        $options = [];

        foreach (self::SETTINGS as $option) {
            $value = trim((string) $request->request->get($option, ''));
            $options[$option] = $value === '' ? null : (int) $value;
        }

        if ($this->offers(Autocomplete::OPTION, $type)) {
            // Through the enum rather than trusted: the control offers three
            // answers and anything else is a hand-edited form, which should read
            // as "no opinion" rather than land a word in the definitions that
            // nothing knows how to interpret.
            $chosen = Autocomplete::tryFrom(trim((string) $request->request->get(Autocomplete::OPTION, '')));
            $options[Autocomplete::OPTION] = $chosen === null || $chosen === Autocomplete::Auto
                ? null
                : $chosen->value;
        }

        return $options;
    }

    /**
     * Which types offer which per-type option, resolved once from PER_TYPE.
     *
     * The template asks `field.type in settable.autocomplete`, which is a list
     * of strings rather than a service question — Twig has no `instanceof`, and
     * it should not grow one so that a page can interrogate the container.
     *
     * @return array<string, list<string>>
     */
    private function settableByType(): array
    {
        $offered = [];

        foreach (self::PER_TYPE as $option => $capability) {
            $offered[$option] = [];

            foreach ($this->fieldTypes->all() as $key => $type) {
                if ($type instanceof $capability) {
                    $offered[$option][] = (string) $key;
                }
            }
        }

        return $offered;
    }

    /**
     * Whether a type key names one that offers this option.
     *
     * An unknown key is a no rather than an exception: `add` already answers for
     * a tampered type select with a message, and this runs while building the
     * arguments for that same call.
     */
    private function offers(string $option, string $type): bool
    {
        return \in_array($type, $this->settableByType()[$option] ?? [], true);
    }

    /**
     * The ids of the fields whose numbering a customer may edit (XIV-27).
     *
     * Three things at once, and each of them is a real narrowing:
     *
     *  * **the module's own shape, not a collection's.** {@see AssignsNumbers}
     *    walks a module's fields and nothing else, so a numbered field on an
     *    order *line* would be a page promising a number that no save would ever
     *    assign.
     *  * **a type that declares {@see Numbers}**, which is the capability half.
     *  * **a pattern that is already a sequence.** Whether a field is numbered
     *    is not a fact about its type, and turning numbering on is the follow-up
     *    §5.10 argues for rather than a control this page draws.
     *
     * @return list<int>
     */
    private function numberedIn(ModuleDefinition $module): array
    {
        $ids = [];

        foreach ($module->getFields() as $field) {
            if ($this->offers(NumberFormat::OPTION, $field->getType()) && NumberFormat::of($field) !== null) {
                $id = $field->getId();

                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * One of them, by id, or a 404.
     *
     * Both numbering routes go through this, so the link the list draws and the
     * page it leads to cannot disagree — and a hand-typed URL naming an ordinary
     * text field is not found rather than being an accidental way to reach the
     * feature the scope valve deferred.
     */
    private function numberedField(ModuleDefinition $module, int $id): FieldDefinition
    {
        $field = $this->field($module, $id);

        if (!\in_array($id, $this->numberedIn($module), true)) {
            throw $this->createNotFoundException(
                sprintf('Field %d of "%s" is not numbered, so it has no numbering to edit.', $id, $module->getKey()),
            );
        }

        return $field;
    }

    /** A shape of *this* module, so an id from a form cannot reach another one. */
    private function shape(ModuleDefinition $module, int $id): ShapeDefinition
    {
        foreach (self::shapesOf($module) as $shape) {
            if ($shape->getId() === $id) {
                return $shape;
            }
        }

        throw $this->createNotFoundException(sprintf('No shape %d on "%s".', $id, $module->getKey()));
    }

    /** Likewise a field: an id in a URL is not a licence to edit another module's. */
    private function field(ModuleDefinition $module, int $id): FieldDefinition
    {
        foreach (self::shapesOf($module) as $shape) {
            foreach ($shape->getFields() as $field) {
                if ($field->getId() === $id) {
                    return $field;
                }
            }
        }

        throw $this->createNotFoundException(sprintf('No field %d on "%s".', $id, $module->getKey()));
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}

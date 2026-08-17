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
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\UnknownFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

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
     * its module and has no control here (XIV-26). Which settings a *type*
     * would let a customer set is a better question and a different ticket.
     */
    private const array SETTINGS = ['max_length', 'min', 'max'];

    /**
     * The one setting whose control depends on the field's *type* (XIV-36).
     *
     * Everything in SETTINGS above is a number and is drawn for every field
     * whether or not it means anything there. Autocomplete is not: a `text`
     * field has nothing to autocomplete against, and a select offering the
     * choice beside it would be a control that does nothing, which is worse than
     * an absent one.
     *
     * So the *type* is asked, by implementing {@see Autocompletes}. That is a
     * first, small step toward what §5.4 says the real shape is — a type
     * declaring which of its options are the customer's to set, so this form can
     * draw the right controls per type rather than three fixed ones — and it is
     * deliberately not that shape yet. Generalising from one option would be
     * guessing at XIV-27's interface with one example; when XIV-27 arrives, this
     * constant and the `instanceof` behind it become a lookup in a declared list.
     */
    private const string AUTOCOMPLETE = Autocomplete::OPTION;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly MetadataEditor $editor,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TranslatorInterface $translator,
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
            // Which types offer the autocomplete control, as a list of keys
            // (XIV-36). Resolved here rather than in the template because Twig
            // has no `instanceof`, and giving it one so a page could ask what a
            // service implements would be a worse answer than a list of strings.
            'autocompletable' => $this->autocompletable(),
            'autocompleteChoices' => Autocomplete::settable(),
        ]);
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

        if ($this->offersAutocomplete($type)) {
            // Through the enum rather than trusted: the control offers three
            // answers and anything else is a hand-edited form, which should read
            // as "no opinion" rather than land a word in the definitions that
            // nothing knows how to interpret.
            $chosen = Autocomplete::tryFrom(trim((string) $request->request->get(self::AUTOCOMPLETE, '')));
            $options[self::AUTOCOMPLETE] = $chosen === null || $chosen === Autocomplete::Auto
                ? null
                : $chosen->value;
        }

        return $options;
    }

    /**
     * The field types whose fields may be autocompleted.
     *
     * @return list<string>
     */
    private function autocompletable(): array
    {
        $keys = [];

        foreach ($this->fieldTypes->all() as $key => $type) {
            if ($type instanceof Autocompletes) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Whether a type key names one of them.
     *
     * An unknown key is a no rather than an exception: `add` already answers for
     * a tampered type select with a message, and this runs while building the
     * arguments for that same call.
     */
    private function offersAutocomplete(string $type): bool
    {
        return \in_array($type, $this->autocompletable(), true);
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

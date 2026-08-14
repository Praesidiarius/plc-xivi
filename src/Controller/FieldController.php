<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
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
 */
#[Route('/m/{module}/fields', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted('ROLE_ADMIN')]
final class FieldController extends AbstractController
{
    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly MetadataEditor $editor,
        private readonly FieldTypeRegistry $fieldTypes,
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
        ]);
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
                    options: self::optionsFrom($request),
                );

                $this->addFlash('success', sprintf('Added "%s".', $field->getLabel()));
                // UnknownFieldType too: the select is built from the registry, so a
                // type it does not know means a tampered form, which is a message
                // rather than a stack trace.
            } catch (MetadataChangeRefused | UnknownFieldType $e) {
                $this->addFlash('warning', $e->getMessage());
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
                    position: $request->request->getInt('position', $target->getPosition()),
                    options: self::optionsFrom($request),
                );

                $this->addFlash('success', sprintf('Saved "%s".', $target->getLabel()));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
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
                $this->addFlash('success', sprintf('Removed "%s". Its values are still stored.', $target->getLabel()));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->getMessage());
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
     * Per-type settings. Only what a type actually reads is kept.
     *
     * @return array<string, int>
     */
    private static function optionsFrom(Request $request): array
    {
        $options = [];

        foreach (['max_length', 'min', 'max'] as $option) {
            $value = trim((string) $request->request->get($option, ''));

            if ($value !== '') {
                $options[$option] = (int) $value;
            }
        }

        return $options;
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

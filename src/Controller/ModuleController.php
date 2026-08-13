<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Form\RecordType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Validation\RecordValidator;

/**
 * Browsing and editing records, for every module.
 *
 * One controller, no matter how many modules exist — a module that needed its
 * own would mean the engine had failed to describe it. Which module is being
 * served comes from the URL, and its shape from that customer's definitions.
 */
#[Route('/m/{module}', requirements: ['module' => '[a-z][a-z0-9_]*'])]
final class ModuleController extends AbstractController
{
    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly RecordValidator $validator,
    ) {
    }

    #[Route('', name: 'module_index', methods: ['GET'])]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('module/index.html.twig', [
            'module' => $definition,
            'records' => $this->records->findAll($definition),
            'total' => $this->records->countAll($definition),
        ]);
    }

    #[Route('/new', name: 'module_new', methods: ['GET', 'POST'])]
    public function new(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = new Record();

        return $this->edit($definition, $record, $request);
    }

    #[Route('/{id}', name: 'module_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
    public function editRecord(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        return $this->edit($definition, $record, $request);
    }

    #[Route('/{id}/delete', name: 'module_delete', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        if ($this->isCsrfTokenValid('delete-record-' . $id, (string) $request->request->get('_token'))) {
            $this->records->delete($definition, $record);
            $this->addFlash('success', 'Deleted.');
        }

        return $this->redirectToRoute('module_index', ['module' => $module]);
    }

    private function edit(ModuleDefinition $definition, Record $record, Request $request): Response
    {
        $form = $this->createForm(RecordType::class, $record->data, ['module' => $definition]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            /** @var array<string, mixed> $submitted */
            $submitted = $form->getData();
            $violations = $this->validator->validate($definition, $submitted, $record->id);

            if (\count($violations) === 0) {
                $record->data = $submitted;
                $record->ownerId ??= $this->currentUserId();
                $this->records->save($definition, $record);

                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('module_index', ['module' => $definition->getKey()]);
            }

            self::mapViolations($violations, $form);
        }

        return $this->render('module/form.html.twig', [
            'module' => $definition,
            'record' => $record,
            'form' => $form,
        ]);
    }

    /**
     * The engine validates an array, so its violations point at array keys
     * ("[email]"). Putting each one back on the field it came from is what lets a
     * single validator serve the form, an import, and whatever API comes later.
     *
     * @param FormInterface<array<string, mixed>> $form
     */
    private static function mapViolations(ConstraintViolationListInterface $violations, FormInterface $form): void
    {
        foreach ($violations as $violation) {
            $field = trim($violation->getPropertyPath(), '[]');

            $target = $form->has($field) ? $form->get($field) : $form;
            $target->addError(new FormError(
                // Unknown keys report against the form itself, where they are at
                // least visible, rather than being dropped for having no field.
                $form->has($field) ? (string) $violation->getMessage() : sprintf('%s: %s', $field, $violation->getMessage()),
            ));
        }
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            // Not installed for *this* customer is a 404, not an error: another
            // customer may well have it.
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}

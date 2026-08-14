<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Form\ModuleRecordType;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\RecordQueryFactory;
use Xivi\Core\Query\UnsupportedQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
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
        // Every write goes through the writer, never the repository: it owns the
        // transaction and the history entry (§5.2).
        private readonly RecordWriter $writer,
        private readonly RecordValidator $validator,
        private readonly HistoryRepository $history,
        private readonly RecordQueryFactory $queries,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'module_index', methods: ['GET'])]
    public function index(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $query = $this->queries->fromQueryParameters($request->query->all());

        try {
            $records = $this->records->findBy($definition, $query);
            $total = $this->records->countBy($definition, $query);
        } catch (UnsupportedQuery $e) {
            // The query is in the URL, so it can be hand-edited into something
            // the engine will not answer. That is a message and an unfiltered
            // list, not a 500 — and the exception already explains itself.
            $this->addFlash('warning', $e->getMessage());

            $query = new RecordQuery();
            $records = $this->records->findBy($definition, $query);
            $total = $this->records->countBy($definition, $query);
        }

        return $this->render('module/index.html.twig', [
            'module' => $definition,
            'records' => $records,
            'owners' => $this->ownerNames($records),
            'total' => $total,
            'query' => $query,
            'filterable' => $this->queries->filterablePaths($definition),
            'operators' => Operator::cases(),
            'pages' => (int) ceil($total / max(1, $query->perPage)),
        ]);
    }

    #[Route('/new', name: 'module_new', methods: ['GET', 'POST'])]
    public function new(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = new Record();

        return $this->edit($definition, $record, $request);
    }

    /**
     * One record, read-only: its own values, the rows of its collections, and
     * what has happened to it.
     *
     * Everything here is rendered from the customer's definitions, so a field
     * they added shows up with nothing touched — the same claim the form makes,
     * on a page that only reads.
     */
    #[Route('/{id}', name: 'module_show', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function show(string $module, int $id): Response
    {
        $definition = $this->definition($module);
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        $children = [];
        foreach ($definition->getCollections() as $collection) {
            $children[$collection->getKey()] = $this->records->findChildren($collection, $id);
        }

        return $this->render('module/show.html.twig', [
            'module' => $definition,
            'record' => $record,
            'children' => $children,
            'titleFields' => self::titleFields($definition),
            'owner' => $record->ownerId === null ? null : ($this->ownerNames([$record])[$record->ownerId] ?? null),
            'history' => $this->history->findFor($definition, $id),
        ]);
    }

    /**
     * Which fields name a record, for a heading.
     *
     * The metadata has no idea: nothing marks a field as the one a record is
     * called by, and inventing that flag on the way to a detail page would be
     * deciding it by accident. The stand-in is the fields the module says a
     * record cannot exist without — required fields are the ones always there to
     * print, which for a contact is the first and last name. Capped at two so a
     * module with six required fields does not get a heading like a sentence.
     *
     * A real "title field" belongs in the definitions; see §9.3.
     *
     * @return list<FieldDefinition>
     */
    private static function titleFields(ModuleDefinition $module): array
    {
        $required = [];

        foreach ($module->getFields() as $field) {
            if ($field->isRequired()) {
                $required[] = $field;
            }
        }

        if ($required !== []) {
            return \array_slice($required, 0, 2);
        }

        $first = $module->getFields()->first();

        return $first === false ? [] : [$first];
    }

    #[Route('/{id}/edit', name: 'module_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
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
            $this->writer->delete($definition, $record);
            $this->addFlash('success', 'Deleted.');
        }

        return $this->redirectToRoute('module_index', ['module' => $module]);
    }

    private function edit(ModuleDefinition $definition, Record $record, Request $request): Response
    {
        $form = $this->createForm(ModuleRecordType::class, $this->formData($definition, $record), [
            'module' => $definition,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            /** @var array{fields: array<string, mixed>} $submitted */
            $submitted = $form->getData();
            $children = self::childRows($definition, $form->getData());

            // Each part is checked against its own definitions: the contact
            // against the contact's, every address against the address ones. The
            // validator is handed a shape and never asks which kind it is.
            $violations = $this->validator->validate($definition, $submitted['fields'], $record->id);
            self::mapViolations($violations, $form->get('fields'));
            $valid = \count($violations) === 0;

            foreach ($children as $key => $rows) {
                $collection = $definition->getCollection($key);
                \assert($collection !== null);

                foreach ($rows as $row) {
                    $rowViolations = $this->validator->validate($collection, $row['data'], $row['id']);

                    if (\count($rowViolations) > 0) {
                        $valid = false;
                        self::mapViolations(
                            $rowViolations,
                            $form->get('collections')->get($key)->get((string) $row['index'])->get('fields'),
                        );
                    }
                }
            }

            if ($valid) {
                $record->data = $submitted['fields'];
                $record->ownerId ??= $this->currentUserId();

                // One call, one transaction, one history entry — the record and
                // its collections are one action, not several.
                $this->writer->save($definition, $record, array_map(
                    static fn (array $rows): array => array_map(
                        static fn (array $row): array => ['id' => $row['id'], 'data' => $row['data']],
                        $rows,
                    ),
                    $children,
                ));

                $this->addFlash('success', 'Saved.');

                // Back to the record rather than the list: it is what was just
                // worked on, and its history now says what the save did.
                return $this->redirectToRoute('module_show', [
                    'module' => $definition->getKey(),
                    'id' => $record->id,
                ]);
            }
        }

        return $this->render('module/form.html.twig', [
            'module' => $definition,
            'record' => $record,
            'form' => $form,
        ]);
    }

    /**
     * What the form starts with: the record's own values, plus the rows of each
     * collection it owns, plus one blank row.
     *
     * The blank row is what makes the page work with scripting turned off — it
     * is somewhere to type the first address without a button that adds one.
     * Left alone it costs nothing, since an empty row is not saved.
     *
     * @return array<string, mixed>
     */
    private function formData(ModuleDefinition $definition, Record $record): array
    {
        $data = ['fields' => $record->data];

        foreach ($definition->getCollections() as $collection) {
            $children = $record->isNew() ? [] : $this->records->findChildren($collection, (int) $record->id);

            $rows = array_map(
                static fn (Record $child): array => ['id' => (string) $child->id, 'fields' => $child->data],
                $children,
            );
            $rows[] = ['id' => '', 'fields' => []];

            $data['collections'][$collection->getKey()] = $rows;
        }

        return $data;
    }

    /**
     * The submitted collection rows, keyed by collection, carrying the position
     * they hold in the form so a violation can be put back on the row it came
     * from.
     *
     * Rows the person added and left completely empty are dropped rather than
     * validated: clicking "add address" and changing your mind is not an attempt
     * to save a blank address. The same rule means clearing every field of an
     * existing row deletes it, which is what emptying something out looks like
     * to anyone who is not thinking about databases.
     *
     * @param array<string, mixed> $submitted
     *
     * @return array<string, list<array{index: int, id: int|null, data: array<string, mixed>}>>
     */
    private static function childRows(ModuleDefinition $definition, array $submitted): array
    {
        /** @var array<string, array<int, array{id?: string|null, fields?: array<string, mixed>}>> $collections */
        $collections = $submitted['collections'] ?? [];
        $rows = [];

        foreach ($definition->getCollections() as $collection) {
            $rows[$collection->getKey()] = [];

            foreach ($collections[$collection->getKey()] ?? [] as $index => $entry) {
                $fields = $entry['fields'] ?? [];

                if (self::isBlank($fields)) {
                    continue;
                }

                $id = ($entry['id'] ?? '') === '' ? null : (int) $entry['id'];
                $rows[$collection->getKey()][] = ['index' => $index, 'id' => $id, 'data' => $fields];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $fields */
    private static function isBlank(array $fields): bool
    {
        foreach ($fields as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
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

    /**
     * Owner names for a page of records.
     *
     * The engine stores an owner id and deliberately knows nothing about users —
     * there is not even a foreign key, because core has no idea what a user is.
     * Resolving those ids to people is the application's job, and doing it here
     * in one query keeps that boundary intact.
     *
     * @param list<Record> $records
     *
     * @return array<int, string>
     */
    private function ownerNames(array $records): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (Record $record): ?int => $record->ownerId, $records),
        )));

        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            $names[(int) $user->getId()] = $user->getName();
        }

        return $names;
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

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
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Form\ModuleRecordType;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Query\Filter;
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
    private const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        // Every write goes through the writer, never the repository: it owns the
        // transaction and the history entry (§5.2).
        private readonly RecordWriter $writer,
        private readonly RecordValidator $validator,
        private readonly HistoryRepository $history,
        private readonly RecordQueryFactory $queries,
        private readonly RecordExporter $exporter,
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
            'columns' => self::listedFields($definition),
            // Every field the name is built from: sorting by only the first
            // would order a list of people by a field only companies have.
            'nameSort' => implode(',', array_map(
                static fn (FieldDefinition $f): string => $f->getKey(),
                $definition->getTitleFields(),
            )),
            'records' => $records,
            'owners' => $this->ownerNames($records),
            'total' => $total,
            'query' => $query,
            'filterable' => $this->queries->filterablePaths($definition),
            'operators' => Operator::cases(),
            'pages' => (int) ceil($total / max(1, $query->perPage)),
        ]);
    }

    /**
     * A new record, of a kind chosen before the form is drawn (§5.5).
     *
     * A company has no first name, so the form cannot be built until it knows
     * which variant it is for — and switching the fields as somebody picks would
     * need JavaScript, which the forms here do not depend on. Asking first is
     * both simpler and how a CRM usually puts it: "new person" or "new company".
     */
    /**
     * The records you are looking at, as a spreadsheet.
     *
     * The same query the list ran, so a filtered export contains what the filter
     * showed — anything else would be a file that quietly disagrees with the page
     * it came from. Written to a temporary file and streamed, because a
     * spreadsheet is a zip and the writer needs to seek.
     */
    #[Route('/export', name: 'module_export', methods: ['GET'])]
    public function export(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $query = $this->queries->fromQueryParameters($request->query->all());

        $path = (string) tempnam(sys_get_temp_dir(), 'xivi-export-');
        $this->exporter->toFile($definition, $query, $path);

        $response = $this->file($path, sprintf('%s-%s.xlsx', $module, date('Y-m-d')))
            ->deleteFileAfterSend(true);

        // Said rather than guessed. Left to itself the response sniffs the file
        // through symfony/mime, which is not installed — and there is nothing to
        // work out: we wrote the thing.
        $response->headers->set('Content-Type', self::XLSX);

        return $response;
    }

    #[Route('/new', name: 'module_new', methods: ['GET', 'POST'])]
    public function new(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $variants = $definition->getVariants();
        $record = new Record();

        if ($variants !== []) {
            $chosen = (string) $request->query->get('variant', '');

            if (!isset($variants[$chosen])) {
                return $this->render('module/choose_variant.html.twig', [
                    'module' => $definition,
                    'variants' => $variants,
                ]);
            }

            // Seeded rather than left to the form, so the record knows what it is
            // before anything asks which fields it has.
            $record->set((string) $definition->getVariantField(), $chosen);
        }

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
            'fields' => $definition->getFieldsFor($definition->variantOf($record->data)),
            'children' => $children,
            'linked' => $this->linkedTo($definition, $record),
            'owner' => $record->ownerId === null ? null : ($this->ownerNames([$record])[$record->ownerId] ?? null),
            'history' => $this->history->findFor($definition, $id),
        ]);
    }

    /**
     * Records elsewhere that point at this one (§7.6).
     *
     * The reverse of a reference, and read rather than stored: a person carries
     * its company, so a company's list of people is a query over that field.
     * Storing both sides would be two records of one fact, which is two things
     * to keep in step and one of them eventually wrong.
     *
     * Only self-references for now — a link from another module would mean
     * looking through every installed module for fields pointing here, which is
     * §7.6's remaining half.
     *
     * @return array<string, list<Record>> field label => the records pointing here
     */
    private function linkedTo(ModuleDefinition $module, Record $record): array
    {
        $linked = [];

        foreach ($module->getFields() as $field) {
            if ($field->getType() !== 'reference' || ReferenceFieldType::targetModule($field) !== $module->getKey()) {
                continue;
            }

            $found = $this->records->findBy($module, new RecordQuery(
                [new Filter($field->getKey(), Operator::Equals, (int) $record->id)],
            ));

            if ($found !== []) {
                $linked[$field->getLabel()] = $found;
            }
        }

        return $linked;
    }

    /**
     * The columns after the name: the fields marked for the list (§5.4), minus
     * the ones that make up the name itself.
     *
     * The name is always the first column, so a list of mixed variants reads —
     * people and companies are named differently, and neither has the other's
     * fields (§5.5). It also means a table can never end up with no columns.
     *
     * @return list<FieldDefinition>
     */
    private static function listedFields(ModuleDefinition $module): array
    {
        $titles = $module->getTitleFields();
        $listed = [];

        foreach ($module->getFields() as $field) {
            // Title fields are not columns: the first column *is* the record's
            // name, which with variants is the only thing every row has (§5.5) —
            // a company has no first name to put under "First name".
            if ($field->isListed() && !\in_array($field, $titles, true)) {
                $listed[] = $field;
            }
        }

        return $listed;
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
            'variant' => $definition->variantOf($record->data),
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
                // Merged, not replaced. The form only carries this variant's
                // fields, and a value belonging to another variant is somebody's
                // data — the same reason removing a field leaves its values
                // alone (§7.2).
                $record->data = [...$record->data, ...$submitted['fields']];
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

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

use App\Tenant\Entity\User;
use App\Tenant\Security\ModuleRecord;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\DocumentFailed;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Core\Document\DocumentGenerator;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Document\DocumentTemplateRepository;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * A record, on paper (XIV-4).
 *
 * Two jobs, and the ticket asked for them to be two permissions: keeping the
 * module's templates, and making a document from one. Whoever designs the
 * invoice is not whoever sends one, and a template decides what every future
 * document of that kind looks like — a larger thing to hand out than the
 * documents themselves.
 *
 * Its own controller rather than more of ModuleController, which browses and
 * edits records; the same split the importer already makes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}', requirements: ['module' => '[a-z][a-z0-9_]*'])]
final class DocumentController extends AbstractController
{
    private const string CSRF = 'module-templates';

    /** Big enough for a letterhead with a logo, small enough not to be a payload. */
    private const int MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentMarkers $markers,
        private readonly DocumentGenerator $generator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The module's stationery, and the list of placeholders that go in it.
     *
     * The placeholder list is the point of the page: somebody writing a template
     * in Word needs to know what to type, and the answer is the customer's own
     * field definitions — so a field they added this morning appears here without
     * anybody writing documentation.
     */
    #[Route('/templates', name: 'module_templates', methods: ['GET'])]
    #[IsGranted(ModuleAction::Templates->value, subject: 'module')]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('document/index.html.twig', [
            'module' => $definition,
            'templates' => $this->templates->forModule($definition->getKey()),
            'variants' => $definition->getVariants(),
            // One reference list per variant, because a letter to a person and a
            // letter to a company are different documents (§5.5) — and one more
            // for the markers that are about neither.
            'markers' => $this->markersPerVariant($definition),
            'general' => $this->markers->general(),
            // And one per collection (XIV-17). Their own sections because they
            // behave differently in the document: a marker names a column and
            // the row it sits in repeats, which is a thing somebody has to be
            // told once rather than guess from a list of tokens.
            'collections' => $this->collectionMarkers($definition),
            'maxBytes' => self::MAX_UPLOAD_BYTES,
        ]);
    }

    #[Route('/templates', name: 'module_template_upload', methods: ['POST'])]
    #[IsGranted(ModuleAction::Templates->value, subject: 'module')]
    public function upload(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if (!$this->submitted($request)) {
            return $this->redirectToRoute('module_templates', ['module' => $module]);
        }

        $file = $request->files->get('template');
        $name = trim((string) $request->request->get('name'));
        $variant = (string) $request->request->get('variant', '');

        try {
            $this->templates->save(new DocumentTemplate(
                $definition->getKey(),
                $name === '' ? $this->fallbackName($file) : $name,
                $this->filenameOf($file),
                $this->contentsOf($file),
                // The form offers only this module's variants, so anything else
                // is a hand-edited request and becomes "every record" rather
                // than an error nobody can act on.
                isset($definition->getVariants()[$variant]) ? $variant : null,
                $this->currentUserLabel(),
            ));

            $this->addFlash('success', $this->translator->trans('flash.template_uploaded'));
        } catch (DocumentFailed $e) {
            $this->addFlash('warning', $e->translatable()->trans($this->translator));
        }

        return $this->redirectToRoute('module_templates', ['module' => $module]);
    }

    #[Route('/templates/{template}/delete', name: 'module_template_delete', requirements: ['template' => Requirement::POSITIVE_INT], methods: ['POST'])]
    #[IsGranted(ModuleAction::Templates->value, subject: 'module')]
    public function delete(string $module, int $template, Request $request): Response
    {
        $definition = $this->definition($module);
        $found = $this->template($definition, $template);

        if ($this->submitted($request)) {
            $this->templates->remove($found);
            $this->addFlash('success', $this->translator->trans('flash.template_deleted', ['%template%' => $found->getName()]));
        }

        return $this->redirectToRoute('module_templates', ['module' => $module]);
    }

    /**
     * Choosing what to make, as a page of its own.
     *
     * The record page opens this in a modal; without JavaScript the same link
     * lands here instead, which is why it is a page and not a fragment. A record
     * with fifty templates has to be a list somebody picks from either way —
     * fifty buttons beside the record would be a worse answer than a dropdown.
     */
    #[Route('/{id}/document', name: 'module_document_choose', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    #[IsGranted(ModuleAction::Document->value, subject: 'module')]
    public function choose(string $module, int $id): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);

        return $this->render('document/choose.html.twig', [
            'module' => $definition,
            'record' => $record,
            'templates' => $this->templates->forRecord($definition->getKey(), $definition->variantOf($record->data)),
            'formats' => DocumentFormat::cases(),
        ]);
    }

    /**
     * One record, as a document.
     *
     * Both formats from one route: the PDF is what gets sent and the .docx is
     * what somebody edits when the letter needs a sentence the template has not
     * got. The record is fetched through the same check the record page uses, so
     * a document is never a way to read a record you could not open.
     *
     * The template and the format arrive as query parameters rather than in the
     * path, so that the chooser can be an ordinary GET form and need no
     * JavaScript to build a URL.
     */
    #[Route('/{id}/document/download', name: 'module_document', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    #[IsGranted(ModuleAction::Document->value, subject: 'module')]
    public function document(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);

        $template = $request->query->getInt('template');
        $format = (string) $request->query->get('format', DocumentFormat::Pdf->value);

        // Hand-editable, both of them: an unknown format is the one everybody
        // means, and an unknown template is a 404 like any other id.
        $wanted = DocumentFormat::tryFrom($format) ?? DocumentFormat::Pdf;
        $found = $this->template($definition, $template);

        if (!$found->appliesTo($definition->variantOf($record->data))) {
            // A template for companies, asked for on a person: not an error
            // anybody made on purpose, and not a document that would mean
            // anything either.
            throw $this->createNotFoundException();
        }

        try {
            $document = $wanted === DocumentFormat::Pdf
                ? $this->generator->pdf($found, $definition, $record)
                : $this->generator->docx($found, $definition, $record);
        } catch (DocumentFailed $e) {
            // Back to the record with the reason, rather than a stack trace: a
            // converter that is down and a template with a broken marker are
            // both things somebody can act on.
            $this->addFlash('warning', $e->translatable()->trans($this->translator));

            return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
        }

        $response = new Response($document);
        $response->headers->set('Content-Type', $wanted->contentType());
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                'attachment',
                DocumentGenerator::filename($found, $record, $wanted),
            ),
        );

        return $response;
    }

    /**
     * The reference list, per variant.
     *
     * Keyed by variant so the page can label each one; a module without variants
     * gets a single list under an empty key.
     *
     * @return array<string, list<\Xivi\Core\Document\DocumentMarker>>
     */
    private function markersPerVariant(ModuleDefinition $definition): array
    {
        $variants = array_keys($definition->getVariants());

        if ($variants === []) {
            return ['' => $this->markers->forShape($definition, null)];
        }

        $lists = [];

        foreach ($variants as $variant) {
            $lists[$variant] = $this->markers->forShape($definition, $variant);
        }

        return $lists;
    }

    /**
     * The reference list for each collection the module owns (XIV-17).
     *
     * A collection with no rows to draw still gets a section: what a template
     * *may* say does not depend on what this customer has typed yet.
     *
     * @return list<array{collection: CollectionDefinition, lists: array<string, list<\Xivi\Core\Document\DocumentMarker>>, example: string}>
     */
    private function collectionMarkers(ModuleDefinition $definition): array
    {
        $sections = [];

        foreach ($definition->getCollections() as $collection) {
            $fields = $collection->getFieldKeys();

            $sections[] = [
                'collection' => $collection,
                'lists' => $this->markers->forCollection($collection),
                // A token from this very collection, so the sentence explaining
                // the kind-less form shows something somebody could paste.
                'example' => DocumentMarkers::collectionKey($collection->getKey(), null, $fields[1] ?? ($fields[0] ?? 'field')),
            ];
        }

        return $sections;
    }

    /** @throws DocumentFailed when it is not a .docx anybody could open */
    private function contentsOf(mixed $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw DocumentFailed::notADocx($this->filenameOf($file));
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw DocumentFailed::notADocx($this->filenameOf($file));
        }

        $contents = (string) file_get_contents($file->getPathname());

        // A .docx is a zip with a document in it, so this is the whole check:
        // anything that does not open, or opens without the part Word puts the
        // text in, is not one — whatever the extension claims.
        $zip = new \ZipArchive();

        if ($zip->open($file->getPathname()) !== true) {
            throw DocumentFailed::notADocx($this->filenameOf($file));
        }

        $hasDocument = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        if (!$hasDocument) {
            throw DocumentFailed::notADocx($this->filenameOf($file));
        }

        return $contents;
    }

    private function filenameOf(mixed $file): string
    {
        return $file instanceof UploadedFile ? $file->getClientOriginalName() : '—';
    }

    /** A template nobody named is called after the file it came from. */
    private function fallbackName(mixed $file): string
    {
        $filename = $this->filenameOf($file);

        return pathinfo($filename, \PATHINFO_FILENAME) ?: $filename;
    }

    private function template(ModuleDefinition $definition, int $id): DocumentTemplate
    {
        return $this->templates->find($definition->getKey(), $id) ?? throw $this->createNotFoundException();
    }

    /**
     * The record, if this person may see it.
     *
     * The same rule ModuleController applies, and deliberately the same shape: a
     * record kept out of a list that can still be opened by typing its id is not
     * protected (§8.4). A document is a way of reading a record, so it answers
     * 404 where the record page would.
     */
    private function recordFor(ModuleDefinition $definition, int $id): Record
    {
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        if ($this->isGranted(ModuleAction::Document->value, new ModuleRecord($definition, $record))) {
            return $record;
        }

        throw $this->isGranted(ModuleAction::View->value, new ModuleRecord($definition, $record))
            ? $this->createAccessDeniedException()
            : $this->createNotFoundException();
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function currentUserLabel(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getName() : null;
    }

    private function submitted(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }
}

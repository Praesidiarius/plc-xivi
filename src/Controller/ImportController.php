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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

/**
 * A spreadsheet back into a module (§5.6).
 *
 * **Check and import are two buttons on one upload**, not two steps with the
 * file kept in between. §5.6 asks for an upload and no file *storage* — parse and
 * discard — and holding a customer's records in a session or a staging directory
 * between two requests would be exactly the storage it was avoiding. The price is
 * picking the file again to apply it, which is a fair trade for a page that
 * leaves nothing behind.
 *
 * Admin only, for now. Importing is not a more dangerous way to edit a record
 * than the form is, but it is a much faster one, and a file can empty a
 * collection across every record it names. §7.5 is where that gets a real answer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/import', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted('ROLE_ADMIN')]
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordImporter $importer,
    ) {
    }

    #[Route('', name: 'module_import', methods: ['GET', 'POST'])]
    public function import(string $module, Request $request): Response
    {
        $definition = $this->definition($module);
        $report = null;

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('import-records', (string) $request->request->get('_token'))) {
            $report = $this->run($definition, $request);

            if ($report?->applied === true) {
                $this->addFlash('success', sprintf(
                    'Imported %d record(s): %d added, %d updated.',
                    $report->records(),
                    $report->created,
                    $report->updated,
                ));

                return $this->redirectToRoute('module_index', ['module' => $module]);
            }
        }

        return $this->render('module/import.html.twig', [
            'module' => $definition,
            'report' => $report,
        ]);
    }

    /**
     * Run the upload, or say why it could not be.
     *
     * The uploaded file is read where PHP already put it and never moved
     * anywhere of ours, so it is gone when the request ends — which is the whole
     * of §5.6's "parse and discard".
     */
    private function run(ModuleDefinition $definition, Request $request): ?ImportReport
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            // Covers the empty submission and the file that exceeded a PHP limit,
            // which arrives looking like an upload and is not one.
            $this->addFlash('warning', $file instanceof UploadedFile
                ? sprintf('That file did not arrive intact (%s).', $file->getErrorMessage())
                : 'Choose a spreadsheet to import.');

            return null;
        }

        $arguments = [$definition, $file->getPathname(), $this->currentUserId()];

        return $request->request->get('action') === 'apply'
            ? $this->importer->apply(...$arguments)
            : $this->importer->check(...$arguments);
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}

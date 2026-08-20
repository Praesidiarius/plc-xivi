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

use App\Tenant\Attachment\AttachmentRefused;
use App\Tenant\Attachment\AttachmentStore;
use App\Tenant\Security\ModuleRecord;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\StoredFile;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * A record's file, handed to whoever may read the record (XIV-115).
 *
 * ## A link is not a credential
 *
 * **The address of a file is not what protects it.** An unguessable URL that
 * anybody holding it can fetch is the shape this deliberately does not have: a
 * download goes through the application, is checked against the same permissions
 * as the record it hangs off (§8.4), and is refused for a reader who may not open
 * that record even when they have the link. That is why the token in a record is
 * a name for bytes rather than a secret, and why the local adapter's files are
 * created private and live outside `public/`: nothing serves them off disk.
 *
 * **A refusal is a 404**, which is §8.4's rule and the same one
 * {@see DocumentController} follows one noun along: a record somebody may not
 * view answers "there is nothing here", so guessing ids reveals nothing. A record
 * they *may* view but whose field holds no file answers 404 as well, because that
 * is simply true.
 *
 * ## It is in the customer-facing image, and that is a requirement
 *
 * This controller is in `src/`, so it is in both builds (§4.4). A design that
 * needed the administration surface to hand a customer their own file would be
 * the wrong design: the customer-facing image has `SELECT` on the registry and
 * nothing else, and everything this route needs is the tenant it resolved from
 * the host, the record in that tenant's own database, and a directory derived
 * from the same registry row it already read. **What the deployment has to
 * provide is the volume**, mounted at the same path in both images (§5.30).
 *
 * ## Nothing here is ever whole in memory
 *
 * {@see StreamedResponse} over a copy loop of {@see AttachmentLimit::CHUNK_BYTES},
 * so a 10 MB PDF costs the same as a 4 KB one. `BinaryFileResponse` was the other
 * candidate and is not usable through a {@see \League\Flysystem\FilesystemOperator}:
 * it wants a path on a local disk, which is exactly the assumption the storage
 * seam exists to keep out of the rest of the codebase, and it would stop working
 * the day the adapter becomes S3.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}', requirements: ['module' => '[a-z][a-z0-9_]*'])]
final class RecordFileController extends AbstractController
{
    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly AttachmentStore $attachments,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * One field of one record, as the file it holds.
     *
     * The field is a path segment rather than a query parameter, because it is
     * part of what is being addressed rather than a way of asking for it; the
     * requirement is the same one a field key has everywhere else, so a key that
     * could not exist never reaches the metadata.
     */
    #[Route(
        '/{id}/file/{field}',
        name: 'record_file',
        requirements: ['id' => Requirement::POSITIVE_INT, 'field' => '[a-z][a-z0-9_]*'],
        methods: ['GET'],
    )]
    #[IsGranted(ModuleAction::View->value, subject: 'module')]
    public function download(string $module, int $id, string $field): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);
        $held = $definition->getField($field) ?? throw $this->createNotFoundException();
        $type = $this->fieldTypes->get($held->getType());

        // Asked of the type through its capability rather than by name, like
        // every page that draws a value: a field that does not hold a file has no
        // answer here, and an address for its "file" is an address for nothing.
        $file = $type instanceof HoldsAFile ? $type->fileOf($record->get($field), $held) : null;

        if ($file === null) {
            throw $this->createNotFoundException();
        }

        try {
            $stream = $this->attachments->readStream($file);
        } catch (AttachmentRefused $e) {
            // The record says there is a file and the filesystem disagrees. A
            // 404 is the honest answer to whoever clicked, and `tenant:files:check`
            // is what turns it into a list somebody can act on (§4.7).
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return $this->bytesOf($stream, $file);
    }

    /**
     * The bytes, copied out in chunks.
     *
     * @param resource $stream
     */
    private function bytesOf($stream, StoredFile $file): Response
    {
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!feof($stream)) {
                $chunk = fread($stream, AttachmentLimit::CHUNK_BYTES);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                // Flushed per chunk, or PHP's own output buffer becomes the
                // memory this whole design avoids: without it a 10 MB file is a
                // 10 MB buffer, and the measurement in tests/Measurement would be
                // measuring the wrong thing being right.
                flush();
            }

            fclose($stream);
        });

        $response->headers->set('Content-Type', $file->contentType);
        $response->headers->set('Content-Length', (string) $file->size);
        // The type recorded on the way in was read from the bytes rather than
        // taken from the browser's claim, so it is as true as it can be, and this
        // says a browser may not go looking for a better one. Sniffing is how a
        // file that is not what it claims comes to be treated as what it looks
        // like (§8.6 took the same decision about a logo).
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Nothing in a customer's uploaded file may load anything: an HTML file
        // with a script in it is a perfectly ordinary attachment, and this is
        // what makes serving one back harmless. `attachment` below is the other
        // half, and neither is a substitute for the other.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', $file->name, 'file'),
        );
        // **Never cached, and not because the bytes change.** They cannot: a
        // token names one file for its whole life. What can change is who may
        // read the record, and a shared cache holding a customer's contract under
        // an address a colleague may also request would answer a question this
        // controller exists to ask afresh every time.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * The record, if this person may see it.
     *
     * The same shape {@see DocumentController::recordFor()} uses and deliberately
     * so: a file is a way of reading a record, so it answers where the record page
     * would. There is no separate permission for downloading, because there is no
     * separate decision: whoever may open the record may open what is on it.
     */
    private function recordFor(ModuleDefinition $definition, int $id): Record
    {
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        if ($this->isGranted(ModuleAction::View->value, new ModuleRecord($definition, $record))) {
            return $record;
        }

        throw $this->createNotFoundException();
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

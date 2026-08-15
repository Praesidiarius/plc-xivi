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

namespace Xivi\Core\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A .docx somebody uploaded, that documents of one kind are made from (XIV-4).
 *
 * **The file itself is in the customer's own database**, in a bytea column, and
 * that is the whole file-storage design for now (§5.7). Templates are small,
 * few, and unmistakably one customer's — so the isolation §4 already provides is
 * free here, where a shared volume would mean a path to get wrong and a backup
 * story to invent. It is deliberately not an answer for attachments, which are
 * many, large, and still undesigned.
 *
 * **`moduleKey` is a string, not a relation**, the same call PermissionGrant
 * makes: a template for a module the customer uninstalls goes inert rather than
 * cascading away, and reinstalling brings the stationery back.
 *
 * **A template may name a variant** (§5.5). A letter to a person and a letter to
 * a company are different documents with different placeholders, and a template
 * naming no variant is offered for every record of the module.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_template')]
#[ORM\Index(name: 'idx_document_template_module', columns: ['module_key'])]
#[ORM\HasLifecycleCallbacks]
class DocumentTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    /**
     * Who uploaded it, as a name rather than a relation — the same denormalised
     * label history keeps (§5.2), and for the same reason: it says who did it,
     * not who they are now.
     */
    #[ORM\Column(name: 'uploaded_by', length: 255, nullable: true)]
    private ?string $uploadedBy = null;

    public function __construct(
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $moduleKey,
        /** What to call it in the list and in the download: "Letter", "Invoice". */
        #[ORM\Column(length: 255)]
        private string $name,
        /** The uploaded file's own name, kept so somebody can tell two versions apart. */
        #[ORM\Column(length: 255)]
        private string $filename,
        /**
         * The .docx itself. A resource on the way back out of Doctrine, which is
         * why nothing but {@see self::getContent()} touches this property.
         */
        #[ORM\Column(type: 'blob')]
        private mixed $content,
        /** Null offers it for every record of the module (§5.5). */
        #[ORM\Column(length: 63, nullable: true)]
        private ?string $variant = null,
        ?string $uploadedBy = null,
    ) {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->uploadedBy = $uploadedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    /** Whether this template is offered for a record of that variant. */
    public function appliesTo(?string $variant): bool
    {
        return $this->variant === null || $this->variant === $variant;
    }

    /**
     * The file, as a string.
     *
     * Doctrine's `blob` gives back a stream on a freshly loaded entity and the
     * original string on one that has just been persisted, so both are handled
     * here rather than at each of the three call sites that would otherwise have
     * to know which they were holding.
     */
    public function getContent(): string
    {
        if (\is_resource($this->content)) {
            rewind($this->content);

            return (string) stream_get_contents($this->content);
        }

        return (string) $this->content;
    }

    public function replaceContent(string $content, string $filename, ?string $uploadedBy): void
    {
        $this->content = $content;
        $this->filename = $filename;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getUploadedBy(): ?string
    {
        return $this->uploadedBy;
    }
}

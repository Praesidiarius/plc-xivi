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
 * What one kind of email says, written in this application (XIV-38).
 *
 * The deliberate counterpart to {@see DocumentTemplate}, and deliberately not
 * the same shape. A document template is a .docx because a letter's layout is
 * somebody's design work and Word is where that work happens; an email has no
 * layout worth designing. It is text. Asking somebody to open Word, upload a
 * file, and upload it again to fix a typo would be ceremony bought with nothing,
 * so this one is a form: a name, a subject and a body.
 *
 * **Text, not a blob.** That is the whole storage difference and it follows from
 * the sentence above — there is no file, so there is nothing to keep as bytes.
 * It still lives in the customer's own database, for §5.7's reason unchanged:
 * templates are small, few and unmistakably one customer's, so §4's isolation
 * covers them for free.
 *
 * **The body is Markdown.** It renders to HTML for the part a mail client draws
 * and stays itself for the plain-text alternative a well-formed email also
 * carries — which is the quiet argument for Markdown over a rich-text editor,
 * whose output would have left us generating that alternative from HTML.
 *
 * **The subject is part of the template** and takes markers exactly as the body
 * does. A subject is where "Invoice [record_id]" belongs, and a template whose
 * subject had to be typed at every send would have been half a template.
 *
 * **`moduleKey` is a string, not a relation**, the same call DocumentTemplate
 * and PermissionGrant both make: a template for a module the customer uninstalls
 * goes inert rather than cascading away, and reinstalling brings the wording
 * back.
 *
 * **A template may name a variant** (§5.5), for the reason a document template
 * may: a mail to a person is not a mail to a company, and one naming no variant
 * is offered on every record of the module.
 *
 * What is *not* here is the wrapper — the HTML skeleton and the footer around
 * this content. That ships in code and a tenant cannot edit it (§5.13), which is
 * §6.1's existing rule rather than a new one: a customer who could edit the
 * wrapper could break every email they send, and it is not the thing they wanted
 * to change anyway.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_template')]
#[ORM\Index(name: 'idx_email_template_module', columns: ['module_key'])]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Who last wrote it, as a name rather than a relation — the same
     * denormalised label history keeps (§5.2), and for the same reason: it says
     * who did it, not who they are now.
     */
    #[ORM\Column(name: 'updated_by', length: 255, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct(
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $moduleKey,
        /** What to call it in the list and in the chooser: "Invoice", "Reminder". */
        #[ORM\Column(length: 255)]
        private string $name,
        /**
         * The default subject, markers and all.
         *
         * Long enough for a sentence with two markers in it and no longer:
         * a subject line that needs more than this is one no mail client will
         * show the end of. XIV-39 lets it be overridden for a single send,
         * because a default subject is a starting point rather than a rule.
         */
        #[ORM\Column(length: 255)]
        private string $subject,
        /**
         * The Markdown somebody typed.
         *
         * `text` rather than a length, because nobody can say in advance how
         * long a dunning letter is and guessing would be a limit discovered by
         * whoever hit it. Postgres stores a short text exactly as it stores a
         * short varchar, so the generosity costs nothing.
         */
        #[ORM\Column(type: 'text')]
        private string $body,
        /** Null offers it for every record of the module (§5.5). */
        #[ORM\Column(length: 63, nullable: true)]
        private ?string $variant = null,
        ?string $updatedBy = null,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $updatedBy;
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    /**
     * Everything a person may change about a template, in one call.
     *
     * One method rather than four setters because these four things are edited
     * together on one form and are stamped together: a template whose body was
     * saved without its `updatedAt` moving would be a template nobody could tell
     * had changed.
     */
    public function rewrite(string $name, string $subject, string $body, ?string $variant, ?string $updatedBy): void
    {
        $this->name = $name;
        $this->subject = $subject;
        $this->body = $body;
        $this->variant = $variant;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Whether this template is offered for a record of that variant. */
    public function appliesTo(?string $variant): bool
    {
        return $this->variant === null || $this->variant === $variant;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }
}

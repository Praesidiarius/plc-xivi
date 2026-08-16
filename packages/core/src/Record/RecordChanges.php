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

namespace Xivi\Core\Record;

/**
 * What one action changed, in the shape history stores it (§5.2).
 *
 * Two branches, mirroring the form and the validator: the record's own values,
 * and what happened to the rows of its collections. Keeping one structure across
 * the three means nobody has to translate between them.
 *
 * Labels are carried alongside the values rather than looked up when the entry is
 * read. History is a record of what happened, so renaming a field later must not
 * rewrite the past, and a field deleted since must still render.
 *
 * Values are in storage form, which is also the form they are compared in — the
 * alternative is a date that "changed" because one side is a string and the other
 * an object.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordChanges
{
    /**
     * @param array<string, array{label: string, from: mixed, to: mixed}> $fields
     * @param array<string, list<array<string, mixed>>>                   $collections keyed by collection,
     *                                                                                 one entry per row touched
     */
    public function __construct(
        public array $fields = [],
        public array $collections = [],
        /**
         * What was made, for an entry that changed nothing (XIV-4): the template
         * it came from and the format it came out as.
         *
         * @var array{template: string, format: string}|array{}
         */
        public array $document = [],
        /**
         * What was sent, for an entry that put something outside the building
         * (XIV-39): which template it was written from, where it went and what
         * it said in the subject line.
         *
         * Whether it *arrived* is not in here — that is the entry's verb, which
         * is what a timeline is scanned by. The recipient is stored rather than
         * resolved again on the way out, for the same reason field labels are
         * (§5.2): editing the contact afterwards must not rewrite who a mail was
         * sent to a year ago.
         *
         * **`attachment` holds exactly what a `document` entry would have held**
         * (XIV-40), and that is the whole statement of the decision: a document
         * generated in order to be attached is not a second thing that happened,
         * so the same pair of keys sits inside this one rather than beside it.
         *
         * @var array{template: string, recipient: string, subject: string, attachment?: array{template: string, format: string}}|array{}
         */
        public array $email = [],
    ) {
    }

    /** A document generated from a template, which alters no value. */
    public static function forDocument(string $template, string $format): self
    {
        return new self(document: ['template' => $template, 'format' => $format]);
    }

    /**
     * An email sent from a template, which alters no value either (XIV-39).
     *
     * The one place the shape of that entry is decided, and XIV-40 joined it
     * here rather than beside it: an attached document is part of *this* act,
     * so it is another key on this entry rather than a second event with a verb
     * of its own. The argument is §5.15 and the short form is that one button
     * press is one fact — a timeline reading "generated a document, sent an
     * email" describes a single act twice, and leaves whoever reads it later
     * unable to tell that pair from somebody downloading a PDF and then, for
     * their own reasons, writing to the customer.
     *
     * @param array{template: string, format: string}|null $attachment what went
     *                                                                 with it, named exactly as a document entry
     *                                                                 would have named itself
     */
    public static function forEmail(
        string $template,
        string $recipient,
        string $subject,
        ?array $attachment = null,
    ): self {
        $email = ['template' => $template, 'recipient' => $recipient, 'subject' => $subject];

        return new self(email: $attachment === null ? $email : [...$email, 'attachment' => $attachment]);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->collections === [] && $this->document === [] && $this->email === [];
    }

    /**
     * Absent rather than empty, for the same reason the record payload omits
     * nulls: a history row full of empty branches is noise in every query that
     * reads it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->fields !== []) {
            $out['fields'] = $this->fields;
        }

        if ($this->collections !== []) {
            $out['collections'] = $this->collections;
        }

        if ($this->document !== []) {
            $out['document'] = $this->document;
        }

        if ($this->email !== []) {
            $out['email'] = $this->email;
        }

        return $out;
    }
}

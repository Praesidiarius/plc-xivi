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

namespace Xivi\Core\Document;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A document could not be produced (XIV-4).
 *
 * Carries a translatable message rather than a sentence, like the rest of the
 * refusals the UI shows: a broken template and an unreachable converter are both
 * things somebody reads on a page, in their own language, and neither is a 500.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DocumentFailed extends \RuntimeException
{
    private function __construct(
        private readonly TranslatableMessage $translatable,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** The upload is not a .docx at all, or is one Word itself would refuse. */
    public static function notADocx(string $filename): self
    {
        return new self(
            new TranslatableMessage('document.problem.not_a_docx', ['%file%' => $filename], 'xivi'),
            sprintf('"%s" is not a readable .docx file.', $filename),
        );
    }

    /** The template opened, and something in filling it went wrong. */
    public static function couldNotFill(string $name, \Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('document.problem.could_not_fill', ['%template%' => $name], 'xivi'),
            sprintf('Template "%s" could not be filled: %s', $name, $previous->getMessage()),
            $previous,
        );
    }

    /**
     * The PDF converter is a service, so it can be down — and a document that
     * cannot be made into a PDF right now is still a document: the .docx is
     * offered beside it for exactly this case.
     */
    public static function converterUnavailable(\Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('document.problem.no_converter', [], 'xivi'),
            'The PDF converter could not be reached: ' . $previous->getMessage(),
            $previous,
        );
    }

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }
}

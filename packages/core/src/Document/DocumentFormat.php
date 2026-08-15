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

use Symfony\Component\Mime\MimeTypes;

/**
 * What a generated document can come back as (XIV-4).
 *
 * Two, and both are offered every time: the PDF is what gets sent, and the .docx
 * is what somebody edits when the letter needs a sentence the template does not
 * have. Offering only the PDF would make every exception a template change.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum DocumentFormat: string
{
    /** First, because it is the one being asked for nine times in ten. */
    case Pdf = 'pdf';

    case Docx = 'docx';

    /**
     * The MIME type, looked up from the extension rather than written out here
     * — the same table and the same reasoning as the spreadsheet export (XIV-5).
     */
    public function contentType(): string
    {
        return MimeTypes::getDefault()->getMimeTypes($this->value)[0];
    }
}

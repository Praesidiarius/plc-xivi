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

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A value in a file field is a file this installation actually wrote (XIV-115).
 *
 * **It validates the stored form**, like {@see ValidPeriod} and for the same
 * reason: `RecordValidator` normalises before it validates, so by the time this
 * runs the field type has produced either a stored file or something
 * deliberately unreadable for this to name.
 *
 * The case it exists for is not the form, where nothing but an upload this
 * application just took can reach the value, and not a module assembling a
 * record. It is **§5.6's import**: a spreadsheet cell in a file column holding
 * anything at all, from a filename somebody typed to a path off their desktop to
 * a value copied out of another installation. Every one of those is a record that would
 * look filled in and download nothing, and the honest answer is a refused file
 * naming the row, which is what {@see \Xivi\Core\Field\Type\FileFieldType} keeps
 * the value for rather than quietly blanking it.
 *
 * Sentences rather than keys, because that is how Symfony translates a
 * constraint message: through the `validators` domain, keyed by the English
 * text.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidStoredFile extends Constraint
{
    /**
     * Everything that is not a file, in one sentence, and the sentence says what
     * to do rather than what is wrong.
     *
     * Naming the parts would be worse than useless here: nobody types this value
     * and nobody can repair it by hand, so "the file is missing its media type"
     * would send somebody editing a cell that has to be replaced by an upload.
     */
    public string $notAStoredFile = '{{ value }} is not a file kept by this installation. A file gets '
        . 'into a record by being uploaded to it, so a spreadsheet can move a record between shapes '
        . 'but cannot bring a file with it.';
}

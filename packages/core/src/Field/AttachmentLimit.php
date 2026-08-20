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

namespace Xivi\Core\Field;

/**
 * How large a file a record may carry, in one place (XIV-115).
 *
 * ## Four limits, and three of them are somebody else's
 *
 * An upload passes through four ceilings on its way in, each enforced by a
 * different piece of software, and **a set of limits that disagree produces a
 * failure that names the wrong one**: PHP discarding a body over `post_max_size`
 * leaves `$_POST` and `$_FILES` empty, which reaches the application as a form
 * that submitted nothing rather than as a file that was too big, and the sentence
 * somebody reads is about a required field.
 *
 * So they are aligned deliberately, largest on the outside, and this constant is
 * the innermost:
 *
 *  1. **Caddy's `request_body max_size`** (`frankenphp/Caddyfile`), the whole
 *     request, generously above the rest, because what it refuses is refused
 *     before PHP starts and cannot be explained to anybody.
 *  2. **`post_max_size`** (`frankenphp/conf.d/10-app.ini`), the whole body,
 *     which is the file plus every other control on the record form plus
 *     multipart overhead.
 *  3. **`upload_max_filesize`** (same file), one file, with headroom over this
 *     constant so that a file *at* the limit is never refused by PHP first.
 *  4. **This**, enforced by {@see \App\Record\RecordUploads} against what the
 *     browser declares and again against what actually arrived, and the only one
 *     of the four that produces a sentence naming the real limit.
 *
 * `tests/Unit/Deployment/UploadLimitsAgreeTest.php` reads all four and fails if
 * the order is ever broken, which is the point of writing them down as an order
 * rather than as four numbers.
 *
 * ## Why ten
 *
 * The ticket's requirement, and it is the size of a scanned signed contract: a
 * dozen pages photographed by an office scanner at 300 dpi. Raising it is a
 * configuration change here plus the two ini values, and the test says so by
 * failing; nothing else in the design cares, because nothing loads a file into
 * memory (§5.30).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AttachmentLimit
{
    /** The largest file a record may carry, in bytes. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /**
     * How much of a file is in memory at once, on the way in or out.
     *
     * 64 KiB, which is not a tuning knob: it is the buffer both the upload and
     * the download copy through, and it is what makes "nothing reads a whole file
     * into memory" a property of this code rather than a hope about how big
     * customers' files are.
     */
    public const int CHUNK_BYTES = 65_536;

    /**
     * A number of bytes, as somebody would say it out loud.
     *
     * **Binary units under decimal names**, which is the spelling every desktop
     * operating system and every browser download list uses: 1 MB is 1,048,576
     * bytes here. Being right about IEC prefixes and printing 1 MiB would not
     * match the number the same file shows in a Downloads folder, which is the
     * only comparison anybody actually makes.
     *
     * Formatted through {@see \NumberFormatter} on the reader's own locale, like
     * {@see Type\DecimalFieldType::display()}: a German reader reads `1,2 MB`.
     * The unit is not translated, because these are the same letters in all four
     * of this application's languages.
     *
     * Here rather than in the Twig extension that draws it, because the refusal a
     * person reads when a file is too large has to name the same number the form
     * printed before they chose it, and two formatters would eventually spell one
     * limit two ways.
     */
    public static function shown(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', max(0, $bytes));
        }

        $unit = $bytes < 1024 * 1024 ? 'KB' : 'MB';
        $value = $bytes / ($unit === 'KB' ? 1024 : 1024 * 1024);

        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        // **At most one decimal, and none where it would be a zero.** A limit
        // that reads "10.0 MB" and a file that reads "3.0 KB" are both a digit
        // of false precision about a number nobody is measuring to a tenth;
        // 1.2 MB, on the other hand, is genuinely not 1 MB.
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return sprintf('%s %s', $formatter->format($value) ?: round($value, 1), $unit);
    }
}

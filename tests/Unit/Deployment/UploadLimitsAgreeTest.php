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

namespace App\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Field\AttachmentLimit;

/**
 * The four upload ceilings are in the right order (XIV-115, §5.30).
 *
 * ## Why this is a test and not a paragraph
 *
 * An upload passes through four limits enforced by three different pieces of
 * software, and **only the innermost one can explain itself**. PHP discarding a
 * body over `post_max_size` empties `$_POST` and `$_FILES`, so what reaches the
 * application is a form that submitted nothing: the sentence somebody reads is
 * about a required field, and the file they chose is not mentioned. Caddy
 * refusing a body answers 413 before PHP starts at all.
 *
 * So the numbers are deliberately unequal, largest on the outside, and the only
 * one anybody is meant to meet is {@see AttachmentLimit::MAX_BYTES}, which
 * refuses with the real limit in the message. That arrangement is invisible in
 * any one file: the constant is in `packages/core`, two ini values are in the
 * image's PHP configuration and the fourth is in a Caddy site block. This is
 * where they are read together.
 *
 * **It was found the hard way while the ticket was being built.** The container
 * shipped PHP's stock `upload_max_filesize = 2M`, so a 10 MB upload arrived with
 * `UPLOAD_ERR_INI_SIZE` and the application, correctly, said the file had not
 * arrived intact: a true sentence, about the wrong limit, naming a number
 * nobody had configured. That is exactly the failure this order prevents and
 * this test now catches before anybody meets it.
 *
 * A unit test, so it needs no kernel and no container: it reads the files this
 * repository ships.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UploadLimitsAgreeTest extends TestCase
{
    public function testTheLimitsAreOrderedFromTheInsideOut(): void
    {
        $application = AttachmentLimit::MAX_BYTES;
        $upload = $this->iniBytes('upload_max_filesize');
        $post = $this->iniBytes('post_max_size');
        $body = $this->caddyBytes();

        self::assertGreaterThan(
            $application,
            $upload,
            'upload_max_filesize has to be above the limit the application enforces, or PHP refuses '
            . 'a file at the limit first and the message names the wrong number',
        );

        self::assertGreaterThan(
            $upload,
            $post,
            'post_max_size has to hold the file plus the rest of the form plus the multipart envelope',
        );

        self::assertGreaterThanOrEqual(
            $post,
            $body,
            "Caddy's request body limit is the outermost and may not be the first one anything meets",
        );
    }

    /**
     * And the application's own limit is what the *upload* path measures against.
     *
     * A second assertion rather than a second number: the constant is the only
     * place 10 MB is written down, and the sentence a person reads is built from
     * it (`AttachmentRefused::tooLarge()`), so what this checks is the sentence
     * rather than the arithmetic. A limit changed to 10,500,000 bytes would still
     * be a limit and would read as "10 MB" to whoever it refused, which is the
     * kind of near-miss the ini values above are set relative to.
     */
    public function testTheApplicationsLimitIsAWholeNumberOfMegabytes(): void
    {
        self::assertSame('10 MB', AttachmentLimit::shown(AttachmentLimit::MAX_BYTES));
    }

    /** One `key = 12M` out of the image's PHP configuration, in bytes. */
    private function iniBytes(string $key): int
    {
        $ini = (string) file_get_contents($this->root() . '/frankenphp/conf.d/10-app.ini');

        self::assertSame(
            1,
            preg_match(sprintf('/^%s\s*=\s*(\d+)([KMG]?)$/mi', preg_quote($key, '/')), $ini, $found),
            sprintf('"%s" is set in frankenphp/conf.d/10-app.ini', $key),
        );

        return self::scaled((int) $found[1], $found[2]);
    }

    /** And `max_size 16MB` out of the Caddy site block. */
    private function caddyBytes(): int
    {
        $caddyfile = (string) file_get_contents($this->root() . '/frankenphp/Caddyfile');

        self::assertSame(
            1,
            preg_match('/^\s*max_size\s+(\d+)([KMG]?)B?\s*$/mi', $caddyfile, $found),
            'a request_body max_size is set in frankenphp/Caddyfile',
        );

        return self::scaled((int) $found[1], $found[2]);
    }

    /** PHP's shorthand and Caddy's spelling happen to agree on the letters. */
    private static function scaled(int $value, string $unit): int
    {
        return match (strtoupper($unit)) {
            'K' => $value * 1024,
            'M' => $value * 1024 * 1024,
            'G' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}

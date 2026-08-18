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

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it was told, with the placeholders filled in.
 *
 * PSR-3 messages are templates — `{command}` rather than the command — so a test
 * asserting on a raw message asserts on a sentence no human ever reads, and one
 * asserting on the context array asserts on the parts without the sentence that
 * joins them. Interpolating here is what the real handlers do and is what makes
 * "the operator is told which URL was refused" a claim a test can hold.
 *
 * It exists because the alternative in a test is an anonymous class, and an
 * anonymous class with public state is something PHPStan at level 8 has to be
 * argued with rather than something it can read.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $filled = (string) $message;

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $filled = str_replace('{' . $key . '}', (string) $value, $filled);
            }
        }

        $this->records[] = $filled;
    }
}

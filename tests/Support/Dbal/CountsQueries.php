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

namespace App\Tests\Support\Dbal;

use Psr\Log\AbstractLogger;

/**
 * How many statements the tenant connection ran, so a test can assert it
 * (XIV-81).
 *
 * **Because "we do not write an N+1 here" is a claim, and a claim nobody
 * measures is a comment.** The follow-up widget's whole design argument is that
 * resolving a list of follow-ups back to their records costs one query per
 * *module* rather than one per follow-up, and the way that regresses is somebody
 * replacing a batched read with a perfectly readable `foreach` — which passes
 * every other test in the suite. Reading the number is the only assertion that
 * fails when it happens.
 *
 * **A logger rather than a middleware of its own**, which is the whole reason
 * this file is thirty lines instead of three classes. DBAL already ships
 * `Doctrine\DBAL\Logging\Middleware`, which wraps the driver, the connection and
 * the statement and reports every execution to a PSR-3 logger; all that was
 * missing was a logger that counts instead of writing. Registering that
 * middleware on the tenant connection in `when@test` is the whole of the wiring —
 * the reach-for-the-component rule, applied to a piece of test scaffolding where
 * hand-rolling was genuinely tempting.
 *
 * Counting messages that begin with "Executing" is what separates statements from
 * the transaction and connection chatter DBAL logs alongside them. That prefix is
 * DBAL's and could in principle change; it is asserted on in
 * {@see \App\Tests\Functional\Tenant\FollowUpWidgetTest}, whose numbers would go
 * to zero rather than quietly drifting if it ever did.
 *
 * Only the tenant connection is watched, because that is where records,
 * follow-ups and metadata live. The control plane's registry reads are a
 * different question and would only add noise.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CountsQueries extends AbstractLogger
{
    private int $statements = 0;

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        if (str_starts_with((string) $message, 'Executing')) {
            ++$this->statements;
        }
    }

    /**
     * Starts counting from here.
     *
     * Every test that reads the number calls this first: the suite shares a
     * kernel and a tenant across a class, so whatever provisioning, metadata
     * loading and fixture writing happened before is not this measurement's
     * business.
     */
    public function reset(): void
    {
        $this->statements = 0;
    }

    /** How many statements have run since the last {@see reset()}. */
    public function count(): int
    {
        return $this->statements;
    }
}

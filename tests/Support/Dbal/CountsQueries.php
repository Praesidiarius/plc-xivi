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

use App\Tenancy\TenantSwitcher;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * How many statements something ran against a tenant's database.
 *
 * For the tests whose subject is a *number of queries* rather than an answer —
 * XIV-54's promise that a record page costs the same whether a collection has
 * five rows or fifty. An assertion about a count is the only kind that fails
 * when an N+1 comes back, and one that comes back is invisible to every other
 * kind of test: the page still says the right thing, only slower.
 *
 * **Nothing is instrumented for this.** Symfony's Doctrine bridge already wraps
 * every connection in a debug middleware that records each statement, because
 * that is what fills the profiler's query panel, and debug is on in the test
 * environment. So this reads the holder the framework is filling anyway rather
 * than adding a second middleware that would have to be kept in step with it —
 * and a count taken here is the same count a developer sees in the profiler,
 * which is what makes a failure here actionable.
 *
 * The statements themselves are in the same holder when a count that is wrong has
 * to be explained — `getData()['tenant']` carries the SQL and the parameters of
 * each. Deliberately not wrapped in a helper here: it would be a method with no
 * caller, kept for a debugging session that has not happened yet.
 *
 * Only the **tenant** connection is counted. The control plane is a separate
 * database holding the registry, and the queries a request makes against it —
 * resolving the host to a tenant, loading the session's user — are a fixed cost
 * that has nothing to do with how many records a page draws. Counting them would
 * make the number noisier without making it mean more.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait CountsQueries
{
    /**
     * Statements run against the tenant database while `$work` ran.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return array{T, int}
     */
    protected static function countingQueries(callable $work): array
    {
        $holder = self::queryLog();
        $holder->reset();

        $result = $work();

        return [$result, \count(self::queryLog()->getData()[TenantSwitcher::CONNECTION] ?? [])];
    }

    /**
     * The holder the bridge fills.
     *
     * Fetched per call rather than kept, because a test class that keeps one
     * kernel across a dozen requests may still be handed a rebuilt container,
     * and a stale holder would count queries nobody is running any more.
     */
    private static function queryLog(): DebugDataHolder
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');

        \assert($holder instanceof DebugDataHolder, 'the debug middleware is registered when APP_DEBUG is on');

        return $holder;
    }
}

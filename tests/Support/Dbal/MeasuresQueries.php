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

/**
 * "How many statements did that take", as a wrapper round one piece of work.
 *
 * {@see CountsQueries} is the counter and this is the ergonomics: reset, run,
 * read, and hand back both what the work returned and what it cost. Two tickets
 * arrived at the need within a day of each other — XIV-81 asserting that a
 * dashboard resolves records in batches, XIV-54 that a record page with five
 * hundred collection rows costs the same as one with five — and they arrived at
 * two different implementations of it, which is how this came to be split in two.
 *
 * **The counter stayed the one registered as a middleware** (XIV-81's), because
 * where it sits is reasoned: `config/services.yaml` tags it for the `tenant`
 * connection specifically and orders it *inside* DAMA's transaction and the
 * connection-key middleware, so it sees statements as the application issues
 * them rather than the plumbing that decides which database they go to. The
 * alternative read Symfony's `doctrine.debug_data_holder`, which works and costs
 * a dependency on debug mode being on to mean anything.
 *
 * **This wrapper is the other half, and it is the half worth having.** A test
 * that resets a counter, does something and reads it back has three lines that
 * can be got wrong — most obviously forgetting the reset, which makes the second
 * assertion in a class quietly count the first one's work as well.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait MeasuresQueries
{
    /**
     * Runs the work and says what it returned and how many statements it took.
     *
     * Returned as a pair rather than counted into a property, so that a test
     * asserting on *both* — the same names, in fewer queries — cannot read a
     * count that belongs to a different call.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return array{0: T, 1: int}
     */
    protected static function countingQueries(callable $work): array
    {
        $counter = self::counter();
        $counter->reset();

        $result = $work();

        return [$result, $counter->count()];
    }

    /**
     * The counter, fetched through a variable rather than a literal.
     *
     * Not a flourish: PHPStan's `symfonyContainer` rule resolves
     * `getContainer()->get(Foo::class)` against the **dev** container, where a
     * service registered under `when@test` does not exist — so the literal form
     * fails the build for a service that is present every time this code
     * actually runs. Every test class in this suite already reaches services
     * through a one-line helper of exactly this shape for exactly this reason;
     * this is that idiom rather than a new trick.
     */
    private static function counter(): CountsQueries
    {
        $counter = self::fromContainer(CountsQueries::class);
        \assert($counter instanceof CountsQueries, 'the counting middleware is registered under when@test');

        return $counter;
    }

    /** The indirection the docblock above is about: the id arrives as a parameter. */
    private static function fromContainer(string $id): object
    {
        return static::getContainer()->get($id);
    }
}

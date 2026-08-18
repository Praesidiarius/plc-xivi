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

namespace App\Tests\Unit\Lifecycle;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Lifecycle\GuardedRecord;
use Xivi\Core\Record\Record;

/**
 * What a guard is handed, and what asking it for rows costs (XIV-110).
 *
 * The cost is the whole subject. A guard about a collection is the guard anybody
 * actually wants — "an order needs at least one line" is not a question about the
 * header — and the moment a predicate can make a query, the interesting failures
 * are all about how many of them it makes and on whose behalf. §5.1 and XIV-54
 * are both about this shape of mistake.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GuardedRecordTest extends TestCase
{
    /** Nothing is read until something asks. */
    public function testRowsAreNotLoadedUntilTheyAreWanted(): void
    {
        $loads = 0;
        $guarded = $this->guarded($loads);

        self::assertSame('draft', $guarded->get('status'));
        self::assertSame(0, $loads, 'a guard that only reads the header costs no query');
    }

    /**
     * And once, however often it is asked. A lifecycle with three guarded moves
     * asks the same collection three times in one breath; that has to be one
     * `SELECT`.
     */
    public function testRowsAreReadOnce(): void
    {
        $loads = 0;
        $guarded = $this->guarded($loads);

        self::assertCount(1, $guarded->rows('lines'));
        self::assertCount(1, $guarded->rows('lines'));
        self::assertCount(1, $guarded->rows('lines'));

        self::assertSame(1, $loads);
    }

    /** A second collection is a second question, and pays for itself. */
    public function testEachCollectionIsReadOnItsOwn(): void
    {
        $loads = 0;
        $guarded = $this->guarded($loads);

        $guarded->rows('lines');
        $guarded->rows('taxes');

        self::assertSame(2, $loads);
    }

    private function guarded(int &$loads): GuardedRecord
    {
        return new GuardedRecord(
            new Record(['status' => 'draft'], id: 7),
            static function (string $collection) use (&$loads): array {
                ++$loads;

                return [new Record(['for' => $collection], id: 1, parentId: 7)];
            },
        );
    }
}

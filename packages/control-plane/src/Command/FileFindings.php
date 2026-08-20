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

namespace Xivi\ControlPlane\Command;

/**
 * What one tenant's records and files said about each other (XIV-115).
 *
 * A value beside {@see CheckTenantFilesCommand}, in the same file tree and for
 * the same reason {@see ResetProgress} is: the command has a terminal to draw on
 * and the check runs inside a tenant switch, so the answer has to come back out
 * of that closure as one object rather than as four variables that the caller
 * then has to keep in the right order.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FileFindings
{
    /** How many findings of one kind are printed before the rest are counted. */
    private const int SHOWN = 10;

    /**
     * @param int          $claimed     distinct files the records name
     * @param list<string> $missing     records naming a file that is not on the disk
     * @param list<string> $orphans     files on the disk that no record names
     * @param int          $orphanBytes what those orphans weigh, which is the number
     *                                  that decides whether anybody cares
     */
    public function __construct(
        public int $claimed,
        public array $missing,
        public array $orphans,
        public int $orphanBytes,
    ) {
    }

    public function isClean(): bool
    {
        return $this->missing === [] && $this->orphans === [];
    }

    /**
     * The first few, and a line saying how many are not shown.
     *
     * An installation that lost its volume has one finding per record, and a
     * thousand lines of them say nothing the count at the top did not. `--all` is
     * for whoever wants the list to pipe somewhere.
     *
     * @param list<string> $findings
     *
     * @return list<string>
     */
    public function shortened(array $findings, bool $all): array
    {
        if ($all || \count($findings) <= self::SHOWN) {
            return $findings;
        }

        return [
            ...\array_slice($findings, 0, self::SHOWN),
            sprintf('… and %d more. Pass --all to see them.', \count($findings) - self::SHOWN),
        ];
    }
}

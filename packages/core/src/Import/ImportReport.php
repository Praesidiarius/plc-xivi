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

namespace Xivi\Core\Import;

/**
 * What an import did, or — for a check — what it would have done.
 *
 * The same object either way, because a check *is* the import: it runs every
 * statement and rolls back instead of committing (§5.6). A dry run that took a
 * different path would be a dry run of something else, and would be trusted
 * exactly until the day the two disagreed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ImportReport
{
    /** @param list<ImportProblem> $problems */
    public function __construct(
        /** False for a check, and for anything that was refused. */
        public bool $applied,
        public int $created = 0,
        public int $updated = 0,
        public int $childrenWritten = 0,
        /**
         * Rows of a collection that the file did not mention, and that were
         * therefore removed. The destructive half of an import, and the reason a
         * check is worth running: a file listing two of a contact's three
         * addresses is asking for the third to go.
         */
        public int $childrenRemoved = 0,
        public array $problems = [],
    ) {
    }

    /** @param list<ImportProblem> $problems */
    public static function refused(array $problems): self
    {
        return new self(applied: false, problems: $problems);
    }

    public function isClean(): bool
    {
        return $this->problems === [];
    }

    public function records(): int
    {
        return $this->created + $this->updated;
    }
}

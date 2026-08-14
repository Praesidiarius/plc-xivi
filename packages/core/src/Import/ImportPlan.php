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

use Xivi\Core\Entity\ShapeDefinition;

/**
 * What the file turned out to be, worked out before anything is written.
 *
 * Separated from the writing because the two fail differently. A header naming
 * no field is wrong about the whole file, and reporting it once beats reporting
 * every row that followed from it; a value that will not validate is wrong about
 * one row, and the person fixing the spreadsheet wants all of those at once.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ImportPlan
{
    /**
     * @param string                           $moduleSheet the sheet holding the records themselves
     * @param array<string, ShapeDefinition>   $shapes      sheet name => what its rows are
     * @param array<string, ColumnMap>         $maps        sheet name => which column holds what
     * @param array<string, list<list<mixed>>> $sheets      the file itself, header row included
     * @param list<ImportProblem>              $problems    reasons this plan cannot be carried out
     */
    public function __construct(
        public string $moduleSheet,
        public array $shapes,
        public array $maps,
        public array $sheets,
        public array $problems,
    ) {
    }

    /** @param list<ImportProblem> $problems */
    public static function refused(array $problems): self
    {
        return new self('', [], [], [], $problems);
    }

    public function isClean(): bool
    {
        return $this->problems === [];
    }
}

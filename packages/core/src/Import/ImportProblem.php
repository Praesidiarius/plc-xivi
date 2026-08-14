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
 * One reason a file cannot be imported, pointing at where it went wrong.
 *
 * Every problem blocks the whole import (§5.6): half an import is a state
 * nobody can reason about. They are collected rather than thrown one at a time,
 * because somebody fixing a spreadsheet wants the list, not the first line of it.
 *
 * The row is the number a spreadsheet would show — the header is row 1 — so that
 * "row 14" means what it says when the file is opened again. It is null for a
 * problem about a whole sheet, such as a column matching no field.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ImportProblem
{
    public function __construct(
        public string $sheet,
        public ?int $row,
        public string $message,
    ) {
    }

    public function describe(): string
    {
        return $this->row === null
            ? sprintf('%s: %s', $this->sheet, $this->message)
            : sprintf('%s row %d: %s', $this->sheet, $this->row, $this->message);
    }
}

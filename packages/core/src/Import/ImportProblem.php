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

use Symfony\Component\Translation\TranslatableMessage;

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
 * **It carries a key rather than a sentence** (XIV-8). The person reading this is
 * a customer fixing their own file, so it has to arrive in their language; the
 * engine has no translator and no business holding one, so it says *which*
 * problem and leaves the wording to whoever renders it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ImportProblem
{
    /**
     * @param string               $messageKey a key in the `xivi` domain
     * @param array<string, mixed> $parameters its placeholders, already in their
     *                                         final form — a field's label is the
     *                                         customer's own word and is not
     *                                         translated (§5)
     */
    public function __construct(
        public string $sheet,
        public ?int $row,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }

    /**
     * The problem, with the sheet and row wrapped around it.
     *
     * Nested rather than concatenated: Symfony translates a parameter that is
     * itself translatable, so "Sheet, row 14: <problem>" is one sentence a
     * translator can reorder — and German does want the parts in a different
     * order from English often enough to matter.
     */
    public function translatable(): TranslatableMessage
    {
        $problem = new TranslatableMessage($this->messageKey, $this->parameters, 'xivi');

        return $this->row === null
            ? new TranslatableMessage('import.at_sheet', ['%sheet%' => $this->sheet, '%problem%' => $problem], 'xivi')
            : new TranslatableMessage(
                'import.at_row',
                ['%sheet%' => $this->sheet, '%row%' => $this->row, '%problem%' => $problem],
                'xivi',
            );
    }
}

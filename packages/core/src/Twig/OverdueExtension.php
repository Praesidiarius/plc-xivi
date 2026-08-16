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

namespace Xivi\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Payment\Overdue;
use Xivi\Core\Record\Record;

/**
 * `is_overdue(module, record)` in a template (XIV-67).
 *
 * A function rather than a value the controller works out, because both places
 * that need it are drawing a *list* — the record page draws one badge, the module
 * list draws one per row — and passing it in would mean the controller building a
 * map keyed by id for a question that is two field reads and no query at all.
 *
 * It is also the honest shape for something derived on read: a template asking
 * "is this late" gets today's answer, which is the whole reason {@see Overdue}
 * refuses to be a stored state.
 *
 * A module that declares no payment terms answers false, so the templates stay
 * generic — the badge simply never appears on a module that has no notion of
 * being owed, which is every module but one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OverdueExtension extends AbstractExtension
{
    public function __construct(private readonly Overdue $overdue)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('is_overdue', $this->isOverdue(...))];
    }

    public function isOverdue(ModuleDefinition $module, Record $record): bool
    {
        return $this->overdue->isOverdue($module, $record);
    }
}

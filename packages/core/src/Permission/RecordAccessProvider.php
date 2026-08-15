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

namespace Xivi\Core\Permission;

/**
 * Which records of *another* module the person in front of us may see (XIV-13).
 *
 * The engine is normally handed one `RecordAccess` for the module it is
 * listing — the application resolves it and core never learns what a user is
 * (§8.4). That works while a query touches one module, and stops working the
 * moment a link crosses into a second one: a filter through a reference and a
 * picker offering candidates both have to ask about a module nobody passed an
 * answer for, and neither knows in advance which module that will be.
 *
 * So this is the same boundary a step further out: core asks the question by
 * module key and the application answers it, exactly as `InstanceCurrency` and
 * `DocumentContext` do for their own.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface RecordAccessProvider
{
    /**
     * Somebody with no grant at all on that module gets
     * {@see RecordAccess::nothing()} rather than an exception: a link into a
     * module you may not read is a link that matches nothing, which is the
     * answer a query can use.
     */
    public function accessFor(string $moduleKey, ModuleAction $action): RecordAccess;
}

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

namespace App\Tenant\Security;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * One record, and the module it belongs to — what a voter has to be handed to
 * decide anything about it (§7.5).
 *
 * A record on its own cannot answer the question. It is a storage row: an id,
 * a payload and an owner, with no idea which shape it came from, because
 * knowing would mean every row carrying its module around for the benefit of
 * code far above it. So the pairing happens here, at the one place that needs
 * it, rather than in the engine.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleRecord
{
    public function __construct(
        public ModuleDefinition $module,
        public Record $record,
    ) {
    }
}

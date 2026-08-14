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

namespace Xivi\Core\Query;

use Doctrine\DBAL\ParameterType;

/**
 * A WHERE clause and the parameters that go with it.
 *
 * They travel together because they are only correct together: a clause carrying
 * `:p3` and a caller supplying its own parameters is how a query layer starts
 * concatenating strings again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CompiledQuery
{
    /**
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    public function __construct(
        public string $where,
        public array $parameters,
        public array $types,
        public string $orderBy,
    ) {
    }
}

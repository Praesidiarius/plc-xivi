<?php

declare(strict_types=1);

namespace Xivi\Core\Query;

use Doctrine\DBAL\ParameterType;

/**
 * A WHERE clause and the parameters that go with it.
 *
 * They travel together because they are only correct together: a clause carrying
 * `:p3` and a caller supplying its own parameters is how a query layer starts
 * concatenating strings again.
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

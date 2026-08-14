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

namespace App\Tenancy\Dbal;

use Doctrine\DBAL\Tools\DsnParser;

/**
 * Parses tenant DSNs into DBAL connection parameters.
 *
 * Wraps DBAL's parser with our scheme map, so that every place that reads a
 * tenant DSN (the connection middleware, provisioning) agrees on what a DSN
 * means. Postgres only — a tenant on another engine is not a supported shape
 * (docs/architecture.md §4: one deployed codebase, one storage design).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantDsnParser
{
    /** Mirrors doctrine-bundle's map, duplicated because that constant is @internal. */
    private const array SCHEME_MAP = [
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    private DsnParser $parser;

    public function __construct()
    {
        $this->parser = new DsnParser(self::SCHEME_MAP);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Doctrine\DBAL\Exception\MalformedDsnException
     */
    public function parse(string $dsn): array
    {
        return $this->parser->parse($dsn);
    }

    /** The role the tenant connects as, when the DSN names one. */
    public function userName(string $dsn): ?string
    {
        $user = $this->parse($dsn)['user'] ?? null;

        return \is_string($user) && $user !== '' ? $user : null;
    }

    /**
     * @throws \InvalidArgumentException when the DSN names no database
     */
    public function databaseName(string $dsn): string
    {
        $params = $this->parse($dsn);
        $dbname = $params['dbname'] ?? null;

        if (!\is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException('Tenant DSN does not name a database.');
        }

        return $dbname;
    }
}

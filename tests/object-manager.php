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

/*
 * Gives phpstan-doctrine an entity manager, so DQL, repository generics and
 * entity property assignments are checked rather than guessed at.
 *
 * It cannot be one of the application's managers. The control plane and the
 * tenant databases have disjoint mappings, and the extension takes a single
 * manager: handing it the control one leaves every tenant entity looking like a
 * plain object, which reports Doctrine-assigned ids as "never assigned" and
 * lifecycle-written properties as "only written".
 *
 * So this builds an analysis-only manager that maps both trees. It never
 * connects to anything — metadata is all the extension reads.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

require __DIR__ . '/../vendor/autoload.php';

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [
        // The control-plane database is mapped from two places since XIV-60 —
        // the registry a tenant's own request reads, and the administration
        // surface's own rows — and both have to be here or the association
        // between them looks like a target entity that does not exist.
        __DIR__ . '/../src/Registry/Entity',
        __DIR__ . '/../packages/control-plane/src/Entity',
        __DIR__ . '/../src/Tenant/Entity',
    ],
    isDevMode: true,
);

// PHPStan runs this file inside its own scoped runtime, where symfony/var-exporter
// is not visible; PHP 8.4+ native lazy objects avoid needing it at all.
$config->enableNativeLazyObjects(true);

// Lazy by construction: no query is ever run through it.
$connection = DriverManager::getConnection(
    ['driver' => 'pdo_pgsql', 'host' => 'database', 'dbname' => 'static-analysis', 'serverVersion' => '18'],
    $config,
);

return new EntityManager($connection, $config);

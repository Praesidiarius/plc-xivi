<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// The functional tests provision real tenants, which means real rows in the
// control-plane database. Creating and migrating it here rather than in a
// documented manual step keeps `composer test` working on a fresh clone.
$console = escapeshellarg(dirname(__DIR__).'/bin/console');

foreach ([
    'doctrine:database:create --connection=control --if-not-exists',
    'doctrine:migrations:migrate --em=control --allow-no-migration',
] as $command) {
    exec(sprintf('php %s %s --env=test --no-interaction 2>&1', $console, $command), $output, $status);

    if ($status !== 0) {
        fwrite(\STDERR, sprintf(
            "Could not prepare the test control-plane database (%s):\n%s\n",
            $command,
            implode(\PHP_EOL, $output),
        ));

        exit($status);
    }

    $output = [];
}

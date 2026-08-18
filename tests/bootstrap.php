<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/**
 * Which control-plane database this process is about to prepare.
 *
 * Reconstructed rather than asked for, because asking would mean booting a
 * kernel to find out where the kernel is going to connect, and this runs before
 * anything else in the process. The two halves are `DATABASE_URL` — whose path
 * is the database name — and the suffix `config/packages/doctrine.yaml` appends
 * under `when@test`, which is `_test` plus paratest's worker number and nothing
 * else. Keep the two in step; they are three lines apart in intent and two files
 * apart on disk.
 *
 * Only ever used to *say* which database is being talked about, so a mistake
 * here misinforms and cannot destroy anything.
 */
$controlDatabase = static function (): string {
    $url = (string) ($_SERVER['DATABASE_URL'] ?? '');
    $name = parse_url($url, \PHP_URL_PATH);
    $name = \is_string($name) ? ltrim($name, '/') : '';

    if ($name === '') {
        return '(could not be read from DATABASE_URL)';
    }

    return $name.'_test'.(string) getenv('TEST_TOKEN');
};

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
        /*
         * **Why this message is six paragraphs and not one line** (XIV-106).
         *
         * What arrives here is a driver exception, and it is a driver exception
         * about something that is not wrong. `relation "operator" already
         * exists`, thrown out of a PHPUnit bootstrap, before a single test has
         * run, on a branch whose diff is fine.
         *
         * The cause is that this database is older than the branch. It is
         * created here if it is missing and never dropped, so it survives
         * across checkouts of different branches, carrying a
         * `doctrine_migration_versions` table that records what *some* tree once
         * applied. Rename or amend a control migration while iterating on it —
         * an ordinary morning's work — and that record stops describing this
         * tree in one of two ways: it names a class that no longer exists, or it
         * fails to name one whose table is nevertheless already there. The
         * second is what throws, and it throws in the vocabulary of tables
         * rather than of migrations, which is why it reads as a defect.
         *
         * `bin/ci` reclaims these before every run now, so the ordinary path no
         * longer reaches this at all. This is for the paths it does not cover —
         * a bare `composer test`, a `bin/phpunit` in an editor, a database left
         * by something nobody has thought of — and for those, the difference
         * between a stack trace and a stack trace with the cure attached is the
         * afternoon this ticket was written about.
         *
         * The cure is unconditionally safe to suggest, which is the other reason
         * to suggest it so plainly: this database holds nothing but what the
         * suite put in it, and the very next run rebuilds it from empty.
         */
        fwrite(\STDERR, sprintf(
            "\nCould not prepare the test control-plane database.\n\n"
            ."    command   %s\n"
            ."    database  %s\n"
            ."    server    the `database` service, not `database-test` — the control plane is\n"
            ."              on the persistent server because compose.yaml sets DATABASE_URL in\n"
            ."              the php container's environment, which outranks .env.test\n\n"
            ."%s\n\n"
            ."This is usually not a defect in your branch.\n\n"
            ."The database above outlives every run and every branch, so the versions it has\n"
            ."recorded are whatever tree last migrated it. A control migration that has been\n"
            ."renamed or amended since — which is what iterating on one looks like — leaves it\n"
            ."half-applied: a version recorded for a class that is gone, or no version recorded\n"
            ."for a table that is nevertheless already there. The second throws the driver\n"
            ."exception above, which talks about tables and never mentions migrations.\n\n"
            ."Clear it and let the next run migrate it from empty:\n\n"
            ."    bin/ci --reclaim\n\n"
            ."from the host. That clears the control-plane test database of every paratest\n"
            ."worker, not only this one, and every ordinary `bin/ci` run does it too — so if\n"
            ."the failure survives a reclaim, it is about the migration itself after all.\n",
            $command,
            $controlDatabase(),
            implode(\PHP_EOL, $output),
        ));

        exit($status);
    }

    $output = [];
}

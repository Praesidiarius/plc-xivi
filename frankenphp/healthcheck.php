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

/**
 * Is this container actually serving? (XIV-61, docs/architecture/deployment.md §4.8)
 *
 * **A file rather than a `php -r` one-liner in the compose healthcheck**, for two
 * reasons that only showed up on a real deployment. The production image has no
 * `curl`, no `wget`, no `nc` and no busybox, so the obvious healthcheck fails
 * with `executable file not found` and the container sits in `health: starting`
 * until it is declared unhealthy, which reads like the application is broken
 * rather than the check. And a one-liner would have gone through
 * `file_get_contents()`, which needs `allow_url_fopen`, which a hardened
 * production php.ini is entitled to turn off. A socket needs no ini setting.
 *
 * **It asks the TLS ask endpoint about a hostname this instance certainly
 * serves**, rather than fetching `/` and being satisfied by any response. That
 * makes the check cover the control-plane database and the registry query, so a
 * container whose database has gone away is reported unhealthy instead of
 * quietly serving errors. Fetching `/` would answer 200 from a login page while
 * the registry was unreachable.
 *
 * Exit 0 is healthy, anything else is not, which is Docker's contract.
 */

$host = getenv('HEALTHCHECK_HOST');

if ($host === false || $host === '') {
    fwrite(STDERR, "HEALTHCHECK_HOST is not set; see .env.deploy.dist.\n");

    exit(1);
}

$socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 3.0);

if ($socket === false) {
    fwrite(STDERR, "Nothing is listening on 127.0.0.1:80 ($errno $errstr).\n");

    exit(1);
}

$path = '/_tls/ask?domain='.rawurlencode($host);

fwrite($socket, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
stream_set_timeout($socket, 3);

$status = fgets($socket);
fclose($socket);

if (!is_string($status)) {
    fwrite(STDERR, "No response from the ask endpoint.\n");

    exit(1);
}

// 204 is the endpoint's yes. 404 means this instance does not serve the hostname
// it was told to check itself with, which is a misconfiguration rather than a
// healthy container, so it is deliberately not treated as success.
if (!str_contains($status, ' 204')) {
    fwrite(STDERR, 'The ask endpoint answered '.trim($status)." for $host.\n");

    exit(1);
}

exit(0);

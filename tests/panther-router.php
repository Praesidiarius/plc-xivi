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
 * The TLS terminator the browser suite does not otherwise have (XIV-105).
 *
 * ### What this is, and why one exists at all
 *
 * Panther runs the application under PHP's built-in web server — `php -S
 * xivi-e2e:9080 -t public` — and then points a real browser at
 * `http://xivi-e2e:9080`. That server speaks plain HTTP and cannot be made to
 * speak anything else; `PantherTestCaseTrait` even builds its base URI with the
 * scheme written out as a literal. For the six browser classes that were here
 * before this one that is of no consequence: nothing they touch cares which
 * scheme it arrived over.
 *
 * The signup surface does care. `SignupRouteLoader` stamps **`https` and one
 * hostname** onto every route it registers, and it does so because the endpoint
 * carries a shared secret in a header and mints a link somebody proves control
 * of a mailbox with — [XIV-64] and [XIV-65], §8.12 and §8.13. So a browser
 * asking for the landing page over plain HTTP does not get the landing page: the
 * router matches the path, finds the scheme wrong, and answers a redirect to
 * `https://…`, which in this network is a port with nothing listening on it.
 *
 * **The routes are right and the server is the thing that is missing a piece.**
 * In production FrankenPHP terminates TLS itself (§4.3), and the application
 * learns that a connection was secure from the server that accepted it. Here
 * there is no such server, so this script says it in the same place the server
 * would have — in `$_SERVER`, before the kernel ever runs — and nothing in
 * `src/`, `packages/` or `config/` is relaxed to let a test through.
 * `SignupNameTest` asserts that in as many words: the route it drives is still
 * `https`-only in the routing table of the very process making the assertion.
 *
 * ### It lies for one hostname and no other
 *
 * The web server is started once for the whole browser suite (see the
 * `ServerExtension` note in `phpunit.dist.xml`), so this router is on the path of
 * every request those six make as well. Telling *them* that they arrived over
 * TLS would not be harmless: `session.cookie_secure` is `auto`, a
 * session cookie marked `Secure` is one a browser on `http://xivi-e2e` refuses to
 * store, and every test that signs somebody in would fail for a reason with
 * nothing to do with what it is about.
 *
 * So the condition is the signup host and nothing else. It is read out of the
 * dotenv files rather than written here, because that is where the suite says
 * which hostname signup answers on and two copies of it would eventually
 * disagree.
 *
 * **Read with a regular expression rather than with `symfony/dotenv`, and the
 * reason is a trap worth naming.** Using the component means loading the
 * autoloader, and `vendor/autoload_runtime.php` opens with
 * `if (true === (require_once __DIR__.'/autoload.php'))` and *returns* — a guard
 * against being bootstrapped twice. A router that had already required the
 * autoloader therefore makes the front controller hand back its kernel factory
 * and run nothing, which presents as a 200 with an empty body and no error
 * anywhere. Four lines of `preg_match` cost less than that costs to find twice.
 *
 * ### Everything else is the built-in server's own behaviour, restored
 *
 * Handing `php -S` a router replaces its default dispatch, so the two things it
 * did for free have to be done here: serve a file that exists, and send
 * everything else to the front controller. `SCRIPT_NAME` and `SCRIPT_FILENAME`
 * are rewritten alongside, because otherwise they name *this* file and
 * `Request::getBaseUrl()` derives a prefix from them that no route matches.
 */

$projectDirectory = \dirname(__DIR__);
$publicDirectory = $projectDirectory . '/public';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
\assert(\is_string($requestUri));

$path = parse_url($requestUri, \PHP_URL_PATH);
$path = \is_string($path) ? $path : '/';

// A file that is really there is served as it was before this script existed.
// `public/` holds only the front controller today, so this is nearly always
// false — it is here so that adding a `favicon.ico` or a `robots.txt` does not
// quietly start routing it through Symfony.
if ($path !== '/' && $path !== '/index.php' && is_file($publicDirectory . $path)) {
    return false;
}

$signupHost = '';

// `.env.test.local` first, because that is the file a developer overrides in and
// the application would read it the same way round.
foreach (['/.env.test.local', '/.env.test'] as $dotenvFile) {
    $contents = is_file($projectDirectory . $dotenvFile)
        ? file_get_contents($projectDirectory . $dotenvFile)
        : false;

    if (\is_string($contents) && preg_match('/^SIGNUP_HOST=(.*)$/m', $contents, $matches) === 1) {
        $signupHost = trim($matches[1], " \t\"'");

        break;
    }
}

$host = $_SERVER['HTTP_HOST'] ?? '';
\assert(\is_string($host));

// The port the browser connected on travels in the `Host` header and is no part
// of the name, which is what the routing table compares against.
$name = strtolower((string) strtok($host, ':'));

if ($signupHost !== '' && $name === strtolower($signupHost)) {
    $_SERVER['HTTPS'] = 'on';
    // Restated so that `Request::getPort()` and anything that builds an absolute
    // URL agree with the scheme rather than reporting the ephemeral port Panther
    // happened to pick.
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTP_HOST'] = $name;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDirectory . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicDirectory . '/index.php';

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

namespace App\Deployment\EventListener;

use App\Deployment\TrustedHosts;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Says, in the log, why a request was answered with 400 (XIV-93,
 * docs/architecture/deployment.md §4.3).
 *
 * ## The 400 is the problem, not the refusal
 *
 * A `Host` outside `framework.trusted_hosts` makes `Request::getHost()` throw
 * `SuspiciousOperationException`, which HttpKernel turns into an empty **400
 * Bad Request**. That is the correct response and it is also, from the far end
 * of a support conversation, indistinguishable from a broken load balancer, an
 * expired certificate or a bug in this application. Symfony's own log line names
 * the host and stops there — which is the one thing the person reporting the
 * problem already knew.
 *
 * What is missing is the other half of the comparison: the pattern that refused
 * it, where that pattern came from, and the variable to change. Those are known
 * here and cost one log line, and §4.3 asks for a misconfiguration to be
 * findable rather than merely correct.
 *
 * ## Why nothing is added to the response
 *
 * The body stays empty on purpose. Whoever is on the other end of a refused
 * request is by definition not somebody this installation serves, and telling
 * them which domains it does serve — and that the answer lives in an
 * environment variable — is telling the one audience that should not be told.
 * The diagnosis goes where the operator is: `stderr`, at `error` level, which is
 * the level `config/packages/monolog.yaml` flushes the production buffer at.
 *
 * ## Why the exception is not matched on its message
 *
 * `SuspiciousOperationException` is raised for an invalid host as well as an
 * untrusted one, and telling those apart by comparing the message against
 * `'Untrusted Host'` would be a check that fails silently the day somebody
 * rewords a framework string. The two conditions asked for instead are facts:
 * the throwable chain holds a `SuspiciousOperationException`, **and** the raw
 * `Host` header — read straight off the header bag, which does no validating and
 * therefore cannot throw a second time — is one {@see TrustedHosts} refuses. An
 * invalid host is refused by that too and is worth the same line, since it is
 * the same 400 with the same silence behind it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class UntrustedHostListener
{
    public function __construct(
        private TrustedHosts $trustedHosts,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->logger === null || !$this->trustedHosts->isConfigured()) {
            return;
        }

        if (!$this->isSuspiciousOperation($event->getThrowable())) {
            return;
        }

        // `headers->get()` rather than `getHost()`: the latter is what threw, and
        // asking it again inside the handler for its own exception is how a 400
        // becomes a 500. The header bag holds what arrived, unparsed.
        $host = (string) $event->getRequest()->headers->get('HOST', '');

        if ($this->trustedHosts->admits($host)) {
            // Some other suspicious operation — a malformed forwarded header, a
            // bad port. Not this listener's subject, and claiming it would put a
            // misleading paragraph in the log of whoever is chasing that one.
            return;
        }

        $this->logger->error(
            'Refused Host "{host}": this installation does not answer to it. {advice}',
            [
                'host' => $host,
                'advice' => sprintf(
                    '%s is "%s", which admits those domains and every name under them, plus %s. '
                    . 'If "%s" is a hostname this installation is meant to serve, add its domain to '
                    . 'that variable and restart — until then every request to it is this same empty '
                    . '400. "bin/console deploy:check-hosts" lists the tenants affected. See '
                    . 'docs/architecture/deployment.md §4.3.',
                    TrustedHosts::VARIABLE,
                    implode(',', $this->trustedHosts->domains()),
                    implode(', ', $this->trustedHosts->alwaysAdmitted()),
                    $host,
                ),
                'trusted_host_patterns' => $this->trustedHosts->patterns(),
            ],
        );
    }

    private function isSuspiciousOperation(\Throwable $throwable): bool
    {
        for ($e = $throwable; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof SuspiciousOperationException) {
                return true;
            }
        }

        return false;
    }
}

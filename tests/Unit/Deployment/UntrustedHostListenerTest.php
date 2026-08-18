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

namespace App\Tests\Unit\Deployment;

use App\Deployment\EventListener\UntrustedHostListener;
use App\Deployment\TrustedHosts;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * That a refused request leaves behind the sentence somebody needs (XIV-93,
 * docs/architecture.md §4.3).
 *
 * The response to an untrusted `Host` is a bare 400 and stays one — telling the
 * caller which domains this installation serves is telling the one audience that
 * should not be told. Everything diagnostic goes to the log instead, and this is
 * what says it went there: the host as sent, the variable to change, and the
 * command that lists which customers are affected.
 *
 * The two negative cases are the ones worth having. A listener that logged this
 * paragraph for *every* suspicious operation would put a misleading explanation
 * in front of whoever is chasing a malformed forwarded header; one that logged
 * it on a deployment with no pattern at all would name a variable that is doing
 * nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UntrustedHostListenerTest extends TestCase
{
    public function testARefusedHostIsExplainedInTheLog(): void
    {
        $logger = $this->logger();

        $this->listen($logger, 'xivi.app', 'evil.example');

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);

        $context = $logger->records[0]['context'];
        self::assertSame('evil.example', $context['host']);
        self::assertStringContainsString(TrustedHosts::VARIABLE, $context['advice']);
        self::assertStringContainsString('deploy:check-hosts', $context['advice']);
        self::assertStringContainsString('xivi.app', $context['advice']);

        // The control-plane host is in the advice too, because it is in the
        // pattern by construction and an operator reading this line is entitled
        // to see the whole of what is admitted rather than only the half they
        // wrote.
        self::assertStringContainsString('control.localhost', $context['advice']);
    }

    public function testASuspiciousOperationAboutSomethingElseIsLeftAlone(): void
    {
        $logger = $this->logger();

        // A host the pattern admits: whatever was suspicious about this request,
        // it was not its `Host` header.
        $this->listen($logger, 'xivi.app', 'acme.xivi.app');

        self::assertSame([], $logger->records);
    }

    public function testADeploymentWithNoPatternSaysNothing(): void
    {
        $logger = $this->logger();

        // Nothing is configured, so nothing is being refused for this reason and
        // naming XIVI_TRUSTED_DOMAINS would send somebody to a variable that is
        // not involved.
        $this->listen($logger, '', 'evil.example');

        self::assertSame([], $logger->records);
    }

    private function listen(AbstractLogger $logger, string $domains, string $host): void
    {
        $trustedHosts = new TrustedHosts($domains, ['localhost', 'php', 'control.localhost']);
        $listener = new UntrustedHostListener($trustedHosts, $logger);

        $request = Request::create('/');
        $request->headers->set('HOST', $host);

        // The shape HttpKernel hands to `kernel.exception`: the framework's own
        // `BadRequestHttpException` wrapping what `getHost()` threw. Asserting
        // against the wrapper rather than the inner exception is deliberate —
        // the listener has to walk the chain, because the inner one is never
        // what arrives.
        $listener(new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new BadRequestHttpException('', new SuspiciousOperationException(sprintf('Untrusted Host "%s".', $host))),
        ));
    }

    /**
     * A logger that keeps what it was told, which is the whole assertion here.
     *
     * @return AbstractLogger&object{records: list<array{level: string, context: array<string, mixed>}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }
}

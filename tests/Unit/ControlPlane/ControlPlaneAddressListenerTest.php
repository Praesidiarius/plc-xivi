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

namespace App\Tests\Unit\ControlPlane;

use App\Deployment\ControlPlaneAllowList;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Xivi\ControlPlane\EventListener\ControlPlaneAddressListener;
use Xivi\ControlPlane\Security\ControlPlaneHost;

/**
 * What a refused control-plane request gets, and what it leaves behind
 * (XIV-124, docs/architecture/identity-and-access.md §8.9).
 *
 * The sibling of {@see \App\Tests\Unit\Deployment\UntrustedHostListenerTest} and
 * arranged the same way, because it is the same division of labour: **the
 * response tells the caller nothing and the log tells the operator everything.**
 * A 403 with a body naming the variable would be a 403 explaining to whoever is
 * being refused exactly which setting to talk somebody into changing.
 *
 * The log line is asserted in detail because it is the entire diagnosis
 * available for a failure with no customer-facing symptom. An operator locked
 * out of their own console has one place to look, and if that place says
 * "Forbidden" and nothing else, the next step is guesswork.
 *
 * {@see \App\Tests\Functional\ControlPlane\ControlPlaneAllowListTest} is the
 * other half — that this listener is wired at all, and that the address it is
 * handed is the one Symfony resolved rather than one a caller wrote.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneAddressListenerTest extends TestCase
{
    private const string CONTROL_PLANE = 'control.example.test';

    /**
     * The refusal itself: a 403 with an empty body, and no exception to be
     * turned into a sign-in page by the firewall that would otherwise have
     * claimed this request.
     */
    public function testARefusedRequestGetsAnEmpty403(): void
    {
        $event = $this->dispatch('203.0.113.0/24', '198.51.100.9');

        $response = $event->getResponse();

        self::assertNotNull($response, 'the request was allowed to continue');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        // Empty, and asserted rather than assumed. Nothing about the allow-list,
        // the variable, or even that one exists: the caller learns only that
        // they may not, which is all somebody this installation does not admit
        // has any business learning from it.
        self::assertSame('', $response->getContent());
    }

    /** An address on the list is not interfered with at all. */
    public function testAnAdmittedAddressIsLeftAlone(): void
    {
        $logger = $this->logger();
        $event = $this->dispatch('203.0.113.0/24', '203.0.113.9', $logger);

        self::assertNull($event->getResponse());
        self::assertSame([], $logger->records);
    }

    /**
     * The shipped default. Nothing is refused and nothing is logged, because an
     * installation that has said nothing about addresses has not asked for this.
     */
    public function testAnUnconfiguredInstallationIsUntouched(): void
    {
        $logger = $this->logger();
        $event = $this->dispatch('', '198.51.100.9', $logger);

        self::assertNull($event->getResponse());
        self::assertSame([], $logger->records);
    }

    /**
     * Every other host on the installation, which is to say every customer.
     *
     * The negative case that matters most: a listener that applied this list to
     * tenant hostnames would be an installation that serves nobody, and the
     * mistake would look identical in configuration to the correct behaviour.
     */
    public function testACustomerHostIsNeverRefused(): void
    {
        $logger = $this->logger();

        $request = Request::create(
            'https://acme.xivi.test/records/1',
            server: ['REMOTE_ADDR' => '198.51.100.9'],
        );

        $event = $this->dispatchRequest($request, '203.0.113.0/24', $logger);

        self::assertNull($event->getResponse());
        self::assertSame([], $logger->records);
    }

    /**
     * **The one log line, and everything an operator needs is in it.**.
     *
     * The address that was refused, the variable that refused it, what that
     * variable currently admits, and the command that answers the question
     * without waiting for another request.
     */
    public function testTheRefusalIsExplainedInTheLog(): void
    {
        $logger = $this->logger();

        $this->dispatch('203.0.113.0/24,2001:db8::/32', '198.51.100.9', $logger);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);

        $context = $logger->records[0]['context'];

        self::assertSame('198.51.100.9', $context['client_ip']);
        self::assertSame(ControlPlaneAllowList::VARIABLE, $context['variable']);
        self::assertSame(['203.0.113.0/24', '2001:db8::/32'], $context['control_plane_allow_list']);

        $advice = (string) $context['advice'];

        self::assertStringContainsString(ControlPlaneAllowList::VARIABLE, $advice);
        self::assertStringContainsString('203.0.113.0/24', $advice);
        self::assertStringContainsString('deploy:check-control-plane', $advice);

        // The half that turns a baffling refusal into a five-second diagnosis:
        // an operator whose office address is demonstrably correct and is still
        // refused is behind a proxy with no TRUSTED_PROXIES, and this line says
        // so before they go looking for a bug here.
        self::assertStringContainsString('TRUSTED_PROXIES', $advice);
    }

    /**
     * The socket peer is logged beside the resolved address, and they are the
     * same when nothing is trusted in front.
     *
     * Two identical values look redundant until the day they differ, which is
     * the day somebody needs to know whether `X-Forwarded-For` was believed.
     */
    public function testTheSocketPeerIsLoggedBesideTheResolvedAddress(): void
    {
        $logger = $this->logger();

        $this->dispatch('203.0.113.0/24', '198.51.100.9', $logger);

        self::assertSame('198.51.100.9', $logger->records[0]['context']['remote_addr']);
    }

    /**
     * An entry that is not an address is named in the log too, because it is the
     * likeliest reason somebody is reading this line about their own address.
     */
    public function testARejectedEntryIsNamedInTheLog(): void
    {
        $logger = $this->logger();

        $this->dispatch('office.example.com', '198.51.100.9', $logger);

        $advice = (string) $logger->records[0]['context']['advice'];

        self::assertStringContainsString('office.example.com', $advice);
        self::assertStringContainsString('not an address or a CIDR range', $advice);
    }

    private function dispatch(string $allowed, string $remoteAddress, ?AbstractLogger $logger = null): RequestEvent
    {
        $request = Request::create(
            sprintf('https://%s/control/login', self::CONTROL_PLANE),
            server: ['REMOTE_ADDR' => $remoteAddress],
        );

        return $this->dispatchRequest($request, $allowed, $logger);
    }

    private function dispatchRequest(Request $request, string $allowed, ?AbstractLogger $logger = null): RequestEvent
    {
        $listener = new ControlPlaneAddressListener(
            new ControlPlaneHost(self::CONTROL_PLANE),
            new ControlPlaneAllowList($allowed),
            $logger,
        );

        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener($event);

        return $event;
    }

    /**
     * A logger that keeps what it was told, which is most of the assertion here.
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

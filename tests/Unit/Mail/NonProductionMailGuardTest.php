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

namespace App\Tests\Unit\Mail;

use App\Mail\NonProductionMailGuard;
use App\Mail\RealMailRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * The rule itself: which DSNs a non-production environment may turn into a
 * transport (XIV-37).
 *
 * A unit test because the rule is a rule and not a wiring question — it needs no
 * kernel, so it runs in milliseconds and states the whole policy in one place.
 * That the rule is actually *reached* by every DSN the application builds is the
 * other half, and is proved against the real container in
 * tests/Functional/Tenant/OutgoingMailTest.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NonProductionMailGuardTest extends TestCase
{
    /** What dev is configured with: the compose catcher and the loopback. */
    private const array CATCHERS = ['mailpit', 'localhost', '127.0.0.1', '::1'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function deliveringDsns(): iterable
    {
        yield 'a real SMTP server' => ['smtp://smtp.example.com:587'];
        yield 'a real SMTP server with credentials' => ['smtp://someone:secret@mail.example.com:587'];
        yield 'implicit TLS to a real server' => ['smtps://mail.example.com:465'];

        // Neither of these names a host at all, which is exactly why an
        // allowlist of hostnames cannot be the whole rule: both hand the message
        // to whatever the machine has and it is gone.
        yield 'the local sendmail binary' => ['sendmail://default'];
        yield "PHP's own mail()" => ['native://default'];
    }

    #[DataProvider('deliveringDsns')]
    public function testATransportThatCouldDeliverIsRefusedOutsideProduction(string $dsn): void
    {
        foreach (['dev', 'test'] as $environment) {
            $guard = new NonProductionMailGuard($environment, self::CATCHERS);

            self::assertTrue($guard->supports(Dsn::fromString($dsn)), $dsn . ' in ' . $environment);
        }
    }

    #[DataProvider('deliveringDsns')]
    public function testProductionIsAllowedToSendMail(string $dsn): void
    {
        $guard = new NonProductionMailGuard(NonProductionMailGuard::PRODUCTION, []);

        self::assertFalse($guard->supports(Dsn::fromString($dsn)));
    }

    /**
     * Falling through is how a permitted DSN reaches Symfony's own factories:
     * this class says "not mine" and the null factory builds it.
     */
    public function testDiscardingIsNeverRefused(): void
    {
        $guard = new NonProductionMailGuard('test', []);

        self::assertFalse($guard->supports(Dsn::fromString('null://null')));
    }

    /** Dev is allowed its catcher, which accepts everything and delivers none of it. */
    public function testTheCatcherIsPermittedWhereItIsConfigured(): void
    {
        $guard = new NonProductionMailGuard('dev', self::CATCHERS);

        self::assertFalse($guard->supports(Dsn::fromString('smtp://mailpit:1025')));
        self::assertFalse($guard->supports(Dsn::fromString('smtp://127.0.0.1:1025')));
    }

    /**
     * The test environment has no catcher, deliberately (§9.2): eight paratest
     * workers against one inbox is a shared mutable thing, so tests assert on
     * messages in memory and nothing opens a socket at all.
     */
    public function testTheTestEnvironmentMayNotEvenReachTheCatcher(): void
    {
        $guard = new NonProductionMailGuard('test', []);

        self::assertTrue($guard->supports(Dsn::fromString('smtp://mailpit:1025')));
    }

    /** A hostname is case-insensitive, and a rule about hostnames had better be too. */
    public function testTheHostIsMatchedWithoutRegardToCase(): void
    {
        $guard = new NonProductionMailGuard('dev', self::CATCHERS);

        self::assertFalse($guard->supports(Dsn::fromString('smtp://MailPit:1025')));
    }

    /** Refusing is all it does, so creating is only ever the refusal. */
    public function testCreatingSaysWhatWasRefusedAndWhere(): void
    {
        $guard = new NonProductionMailGuard('test', []);

        $this->expectException(RealMailRefused::class);
        $this->expectExceptionMessageMatches('/smtp.*mail\.example\.com.*"test"/s');

        $guard->create(Dsn::fromString('smtp://someone:secret@mail.example.com:587'));
    }

    /** A credential in an exception message is a credential in a log file. */
    public function testTheRefusalDoesNotRepeatTheCredential(): void
    {
        $guard = new NonProductionMailGuard('test', []);

        try {
            $guard->create(Dsn::fromString('smtp://someone:hunter2@mail.example.com:587'));
            self::fail('the guard was expected to refuse');
        } catch (RealMailRefused $refused) {
            self::assertStringNotContainsString('hunter2', $refused->getMessage());
        }
    }
}

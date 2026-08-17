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

namespace App\Tests\Functional\Tenant;

use App\Mail\RealMailRefused;
use App\Registry\Entity\Tenant;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Mail\MailSendFailed;
use App\Tenant\Mail\MailSettingsRefused;
use App\Tenant\Mail\SenderIdentity;
use App\Tenant\Mail\TenantMailer;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Email;

/**
 * Sending mail at all, and the two things that had to be decided first (XIV-37):
 * who a tenant's mail comes from, and whether a non-production environment can
 * put any of it on the wire.
 *
 * **The second is the sharp one, and it is why this test exists in the shape it
 * does.** §9.2 recorded the mail catcher's honest limit — a catcher sees what is
 * pointed at it, and a DSN naming a real server is believed — and this suite
 * provisions real tenants, so a fixture that stored a real SMTP credential in a
 * tenant profile would have been one send away from mailing an actual person.
 * So the assertions below are deliberately not "the configured DSN is
 * null://null". They ask the *container's own* transport factory, the one every
 * DSN in the application passes through, to build something that could deliver —
 * and require it to refuse. A test of the configuration would pass while the
 * hole was open; this one cannot.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OutgoingMailTest extends KernelTestCase
{
    use MailerAssertionsTrait;
    use SharesATenant;

    private const string SLUG = 'test_outgoing_mail';
    private const string HOST = 'mail.localhost';

    /** A server that exists, so nothing here is safe by virtue of not resolving. */
    private const string REAL_SMTP = 'smtp.gmail.com';

    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
    }

    // -- the guarantee ------------------------------------------------------

    /**
     * The one door every DSN goes through, asked directly.
     *
     * `mailer.transport_factory` is what the framework builds `mailer.transports`
     * with and what TenantMailer builds a tenant's SMTP DSN with, so a refusal
     * here is a refusal everywhere.
     */
    public function testTheContainerCannotBuildATransportToARealServer(): void
    {
        $this->expectException(RealMailRefused::class);

        $this->transportFactory()->fromString(sprintf('smtp://%s:587', self::REAL_SMTP));
    }

    /**
     * Not even the catcher, which is running and reachable from here.
     *
     * §9.2 turned the suite away from it for a different reason — eight paratest
     * workers against one inbox — and this makes that decision structural rather
     * than a convention a later test could quietly break.
     */
    public function testTheTestEnvironmentCannotEvenReachTheCatcher(): void
    {
        $this->expectException(RealMailRefused::class);

        $this->transportFactory()->fromString('smtp://mailpit:1025');
    }

    /** Handing the message to the machine's own MTA is the same escape by another route. */
    public function testTheLocalMailerIsRefusedToo(): void
    {
        $this->expectException(RealMailRefused::class);

        $this->transportFactory()->fromString('sendmail://default');
    }

    /**
     * And what the suite actually runs on falls through to Symfony's own
     * factories untouched, which is what makes the guard a refusal rather than a
     * wrapper sitting in the delivery path.
     */
    public function testDiscardingIsStillAllowed(): void
    {
        self::assertInstanceOf(NullTransport::class, $this->transportFactory()->fromString('null://null'));
    }

    /**
     * The path a fixture would take: real credentials stored on a real tenant,
     * and a send asked for. Nothing leaves, and the caller is told.
     */
    public function testATenantWithRealSmtpCredentialsStillCannotSend(): void
    {
        $this->configureMail('billing@acme.test', self::REAL_SMTP, 587, 'acme', 'hunter2');

        try {
            $this->send(new Email()->to('somebody@example.com')->subject('Invoice')->text('Attached.'));
            self::fail('a real SMTP server was reached from the test suite');
        } catch (MailSendFailed $failed) {
            // Wrapped rather than replaced, so the reason survives to the log
            // while the caller has one type to catch (XIV-39).
            self::assertInstanceOf(RealMailRefused::class, $failed->getPrevious());
        }

        self::assertEmailCount(0);
    }

    // -- who the mail comes from -------------------------------------------

    /**
     * The fallback, and the one every instance starts on: out through this
     * installation, under the name the customer calls themselves.
     */
    public function testATenantWithNoSettingsSendsFromThisInstance(): void
    {
        $identity = $this->identity();

        self::assertSame('no-reply@' . self::HOST, $identity->from->getAddress());
        self::assertSame($this->tenant->getName(), $identity->from->getName());
        self::assertNull($identity->replyTo);
        self::assertFalse($identity->ownProvider);
    }

    /**
     * An address without a server of their own is a **Reply-To**, not a From.
     *
     * This is the deliverability trade in one assertion: our domain may not claim
     * their address, so the mail says who it is from in the name and sends the
     * answer to the right place instead (§8.7).
     */
    public function testAnAddressWithoutAServerBecomesTheReplyTo(): void
    {
        $this->configureMail('billing@acme.test', '', null, '', null);

        $identity = $this->identity();

        self::assertSame('no-reply@' . self::HOST, $identity->from->getAddress());
        self::assertNotNull($identity->replyTo);
        self::assertSame('billing@acme.test', $identity->replyTo->getAddress());
        self::assertFalse($identity->ownProvider);
    }

    /** With their own server it is genuinely from them, and there is nothing to add. */
    public function testTheirOwnServerMakesItGenuinelyTheirAddress(): void
    {
        $this->configureMail('billing@acme.test', self::REAL_SMTP, 587, 'acme', 'hunter2');

        $identity = $this->identity();

        self::assertSame('billing@acme.test', $identity->from->getAddress());
        self::assertNull($identity->replyTo);
        self::assertTrue($identity->ownProvider);
    }

    /** The company name, once they have said one, rather than the operator's label. */
    public function testTheCompanyNameIsWhatAppearsOnTheMail(): void
    {
        $this->runForTenant(fn () => $this->profiles()->apply('Acme AG', 'CHF'));

        self::assertSame('Acme AG', $this->identity()->from->getName());
    }

    // -- sending ------------------------------------------------------------

    /** With nothing configured it goes out through the instance's transport, and is collected. */
    public function testAMessageIsSentThroughTheInstanceTransport(): void
    {
        $this->send(new Email()->to('somebody@example.com')->subject('Hello')->text('Hello.'));

        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'From', 'no-reply@' . self::HOST);
    }

    /**
     * Whose mail this is, is not the caller's to decide.
     *
     * XIV-39 sends invoices on a customer's behalf; being able to set a From
     * there would be being able to send one on somebody else's behalf, so the
     * mailer overwrites whatever it is handed.
     */
    public function testACallersOwnFromAddressIsOverwritten(): void
    {
        $this->send(
            new Email()
                ->from('anybody@elsewhere.test')
                ->to('somebody@example.com')
                ->subject('Hello')
                ->text('Hello.'),
        );

        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'From', 'no-reply@' . self::HOST);
    }

    // -- what may be stored -------------------------------------------------

    /** Their server may claim their address; ours may not, so it needs one (§8.7). */
    public function testAServerWithoutAnAddressIsRefused(): void
    {
        $this->expectException(MailSettingsRefused::class);

        $this->configureMail('', 'smtp.acme.test', 587, 'acme', 'hunter2');
    }

    public function testSomethingThatIsNotAnAddressIsRefused(): void
    {
        $this->expectException(MailSettingsRefused::class);

        $this->configureMail('not an address', '', null, '', null);
    }

    /** The same mechanism the control plane stores database passwords with (§8.7). */
    public function testTheSmtpPasswordIsStoredEncryptedUnderANamedKey(): void
    {
        $this->configureMail('billing@acme.test', 'smtp.acme.test', 587, 'acme', 'hunter2');

        $stored = $this->runForTenant(
            fn (): ?string => $this->service(TenantProfileRepository::class)->current()->getEncryptedMailSmtpPassword(),
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString('hunter2', $stored);

        $cipher = $this->service(TenantSecretCipher::class);
        self::assertSame($cipher->activeKeyId(), $cipher->keyIdOf($stored));
        self::assertSame('hunter2', $cipher->decrypt($stored));
    }

    /** Blank means "leave the stored one alone": the field is never rendered back. */
    public function testAnEmptyPasswordKeepsTheStoredOne(): void
    {
        $this->configureMail('billing@acme.test', 'smtp.acme.test', 587, 'acme', 'hunter2');
        $this->configureMail('billing@acme.test', 'smtp.acme.test', 2525, 'acme', null);

        $stored = $this->runForTenant(
            fn (): ?string => $this->service(TenantProfileRepository::class)->current()->getEncryptedMailSmtpPassword(),
        );

        self::assertIsString($stored);
        self::assertSame('hunter2', $this->service(TenantSecretCipher::class)->decrypt($stored));
    }

    /** Removing the server removes the credential with it: a secret nothing can use. */
    public function testClearingTheServerClearsTheCredential(): void
    {
        $this->configureMail('billing@acme.test', 'smtp.acme.test', 587, 'acme', 'hunter2');
        $this->configureMail('billing@acme.test', '', null, '', null);

        $profile = $this->runForTenant(fn () => $this->service(TenantProfileRepository::class)->current());

        self::assertNull($profile->getEncryptedMailSmtpPassword());
        self::assertSame('', $profile->getMailSmtpUser());
        self::assertNull($profile->getMailSmtpPort());
    }

    // -- helpers ------------------------------------------------------------

    private function transportFactory(): Transport
    {
        $factory = self::getContainer()->get('mailer.transport_factory');
        \assert($factory instanceof Transport);

        return $factory;
    }

    private function profiles(): TenantProfileManager
    {
        return $this->service(TenantProfileManager::class);
    }

    private function configureMail(
        string $address,
        string $host,
        ?int $port,
        string $user,
        ?string $password,
    ): void {
        $this->runForTenant(fn () => $this->profiles()->applyMail($address, $host, $port, $user, $password));
    }

    private function identity(): SenderIdentity
    {
        return $this->runForTenant(fn (): SenderIdentity => $this->service(TenantMailer::class)->senderIdentity());
    }

    private function send(Email $email): void
    {
        $this->runForTenant(fn () => $this->service(TenantMailer::class)->send($email));
    }

    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    private function runForTenant(callable $work): mixed
    {
        return $this->service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

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

namespace App\Tests\Functional\ControlPlane;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Provisioning\SignupProvisioningStage;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;
use Xivi\ControlPlane\Signup\SignupHost;

/**
 * The person who confirmed an address and would otherwise never hear anything
 * again (XIV-108, §8.14).
 *
 * §8.12 takes a signup and §8.14 turns it into a customer on a cron. When that
 * run fails, three things notice and all three are addressed to the operator: a
 * non-zero exit into cron mail, a half-made tenant at the top of the tenant list,
 * and three columns on the signup row. The gap this class is about is the fourth
 * party, who is not told anything by any of them.
 *
 * ## What is asserted, and why each of them is a separate test
 *
 * The acceptance criteria are almost all *negative*, and a negative property is
 * the kind that decays silently:
 *
 *   1. **A signup that can never succeed is distinguished from one that is merely
 *      failing**, by the existing stage enum and by nothing else. Both kinds are
 *      created here and only one is drawn.
 *   2. **Nothing is sent automatically.** No count, no stage and no elapsed time
 *      sends anything, so the fixture with two hundred failed attempts is drawn
 *      with its button unpressed and its mailbox empty.
 *   3. **The operator sees what it says before it goes**, which is asserted the
 *      only way that means anything: the text on the page and the text of the
 *      mail are compared to each other rather than each to a literal.
 *   4. **Nobody is apologised to twice**, including by the second operator who
 *      posts the same form a minute later.
 *   5. **It says nothing the system has not established.** The one stage that has
 *      a cause is `preflight`, and the message does not mention it.
 *
 * ## The fixtures
 *
 * Signup rows and an operator, and no tenants at all: a stranded signup has no
 * tenant, no database and no user, which is why §8.16's notices could not be the
 * answer to this ticket and why nothing in this class needs a customer database.
 * The control plane is not rolled back between tests (a tenant database is made
 * with `CREATE DATABASE`, which no transaction can undo), so the rows are removed
 * by hand at both ends.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StalledSignupTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** Every address this class writes ends with this, so cleanup can find them. */
    private const string ADDRESS_SUFFIX = '@xiv108.test';

    private const string EMAIL = 'stalled-signups@example.test';
    private const string PASSWORD = 'operator-password-108';
    private const string OPERATOR_NAME = 'Operator One Hundred And Eight';

    /** The row this feature exists for: stopped at `preflight`, which repeats for ever. */
    private const string STRANDED = 'stranded' . self::ADDRESS_SUFFIX;

    /** Stopped at `invitation`, which the next run fixes. Nobody writes to this person. */
    private const string RETRYING = 'retrying' . self::ADDRESS_SUFFIX;

    /** Stranded, and reading in German, because the mail is written in the language the signup recorded. */
    private const string GERMAN = 'german' . self::ADDRESS_SUFFIX;

    private KernelBrowser $client;
    private string $host;
    private string $signupHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->host = self::service(ControlPlaneHost::class)->normalisedHost();
        $this->signupHost = self::service(SignupHost::class)->normalisedHost();

        $this->removeFixtures();
        self::service(OperatorCreator::class)->create(self::EMAIL, self::OPERATOR_NAME, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    /**
     * **An operator sees who is waiting without reading cron mail**, which is the
     * criterion the whole section exists for.
     *
     * On the tenant list rather than on a page of its own, so it is asserted
     * against the page an operator already opens. §8.10's rule for this screen is
     * that nobody has to go looking, and XIV-125 put the refusals on it for the
     * same reason the same afternoon.
     */
    public function testAStrandedSignupIsVisibleOnThePageAnOperatorAlreadyOpens(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');

        $text = $this->listPageText();

        self::assertStringContainsString('waiting on somebody here', $text, 'the heading');
        self::assertStringContainsString(self::STRANDED, $text, 'who is waiting');
        self::assertStringContainsString('Stranded Company AG', $text, 'what they called themselves');
        self::assertStringContainsString('xiv108-stranded', $text, 'the name they asked for');
        self::assertStringContainsString('Name unavailable', $text, 'where it stopped');
    }

    /**
     * **A signup that is merely failing is not in the list**, and the line
     * between the two is the stage enum's.
     *
     * `invitation` means everything exists and the mail did not go out, which the
     * next run retries and usually fixes. Writing an apology to that person is
     * the failure mode the ticket named: "we could not set up your installation"
     * followed twenty minutes later by "here is your login".
     *
     * The stranded row is created alongside it so that this is an assertion about
     * *which* signups are drawn rather than about a section that happens to be
     * empty.
     */
    public function testASignupThatTheNextRunMayFixIsNotDrawn(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');
        $this->failingSignup(self::RETRYING, 'xiv108-retrying', 'Retrying Company AG', SignupProvisioningStage::Invitation);

        $text = $this->listPageText();

        self::assertStringContainsString(self::STRANDED, $text, 'the one nothing will fix');
        self::assertStringNotContainsString(self::RETRYING, $text, 'the one the next run may fix');
    }

    /**
     * **Nothing is sent by a count, a stage or a period**, which is the decision
     * rather than an omission.
     *
     * Asserted on the fixture that every threshold anybody might invent would
     * have fired on: two hundred failed attempts, at the one stage that never
     * resolves, on a row whose address confirmed weeks ago. Drawing the page
     * sends nothing, and the row is still waiting for a person to press
     * something afterwards.
     */
    public function testNoNumberOfFailedAttemptsSendsAnything(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG', attempts: 200);

        $crawler = $this->openList();

        self::assertEmailCount(0, 'drawing the page sent something');
        self::assertCount(
            1,
            $crawler->selectButton('Send this message'),
            'the button is what sends it, and it is still unpressed',
        );

        self::assertNull($this->reload(self::STRANDED)->getApologySentAt(), 'a send was recorded that never happened');
    }

    /**
     * **What the operator reads is what the customer gets**, asserted by
     * comparing the two rather than each to a literal.
     *
     * A preview written for the screen could say anything; the property that
     * matters is that there is no second wording. Both come out of
     * `signup_stalled.txt.twig`, so this fails the moment somebody adds a
     * sentence to the mail that the approving operator would not have seen.
     */
    public function testTheOperatorReadsTheMessageThatIsActuallySent(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');

        $crawler = $this->openList();
        $preview = $crawler->filter('.signup-message')->text();
        $previewedSubject = $crawler->filter('.signup-message-subject')->text();

        $this->client->submit($crawler->selectButton('Send this message')->form());

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        // Whitespace-normalised on both sides. The page draws the body through
        // `nl2br` inside a card and the crawler reads it back with the markup
        // gone, so the two strings differ in line breaks and in nothing else;
        // asserting on the sentences is asserting on what a reader compares.
        self::assertSame(
            self::normalised((string) $message->getTextBody()),
            self::normalised($preview),
            'the preview and the mail are not the same text',
        );
        self::assertSame(
            (string) $message->getSubject(),
            trim($previewedSubject),
            'the subject that was previewed is not the subject that went',
        );
    }

    /**
     * **The mail goes to the person waiting**, under the instance's own identity.
     *
     * There is no tenant to ask, which is the whole shape of the problem, so
     * §8.7's fallback applies exactly as it does to §8.12's confirmation: the
     * instance transport, and `no-reply@` at the signup host when `MAILER_SENDER`
     * is empty.
     */
    public function testTheMessageGoesOutThroughTheInstanceIdentity(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');

        $crawler = $this->openList();
        $this->client->submit($crawler->selectButton('Send this message')->form());

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        self::assertEmailAddressContains($message, 'From', 'no-reply@' . $this->signupHost);
        self::assertEmailAddressContains($message, 'To', self::STRANDED);
        self::assertEmailTextBodyContains($message, 'Stranded Company AG');
        self::assertEmailTextBodyContains($message, 'xiv108-stranded');
    }

    /**
     * **It claims nothing** the system has not established.
     *
     * `preflight` is the one stage with a cause anybody could name, and the
     * message still does not name it: the button is offered for whatever the
     * stage enum stops calling retryable, and "that name is taken" would be false
     * for the rest of them. What is said instead is true of every case it can be
     * sent in, and the positive half of this test is what stops the negative half
     * from passing on an empty message.
     *
     * The link assertion belongs here rather than in a test of its own because it
     * is the same property: a mail to somebody wondering why they have heard
     * nothing, carrying a URL, is asking them to trust something they have no
     * reason to.
     */
    public function testTheMessageNamesNoCauseAndCarriesNoLink(): void
    {
        $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');

        $crawler = $this->openList();
        $this->client->submit($crawler->selectButton('Send this message')->form());

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = (string) $message->getTextBody();

        self::assertStringContainsString('will not resolve on its own', $body, 'what the stage actually says');
        self::assertStringContainsString('a person sent this message', $body, 'who is behind it');

        foreach (['Name unavailable', 'taken', 'unavailable', 'http'] as $claim) {
            self::assertStringNotContainsString(
                $claim,
                $body,
                sprintf('"%s" is a claim this message is not entitled to make.', $claim),
            );
        }
    }

    /**
     * **The send is recorded**, so the same person is not apologised to twice.
     *
     * Two halves, and the second is the one that matters: the button is gone from
     * the page, *and* posting the form again anyway sends nothing. An operator
     * with a stale page in another tab is the case the column exists for, and a
     * template-only guard would let them through.
     */
    public function testSendingIsRecordedAndCannotHappenTwice(): void
    {
        $signup = $this->stalledSignup(self::STRANDED, 'xiv108-stranded', 'Stranded Company AG');

        $crawler = $this->openList();
        $form = $crawler->selectButton('Send this message')->form();

        // Read out of the form's values rather than off the field object, which
        // the crawler types as "one field or an array of them" for the repeated
        // inputs a checkbox group produces. This one never is.
        $token = (string) $form->getValues()['_token'];

        $this->client->submit($form);
        self::assertEmailCount(1);

        $recorded = $this->reload(self::STRANDED);
        self::assertNotNull($recorded->getApologySentAt(), 'the send was not written down');
        self::assertSame(self::OPERATOR_NAME, $recorded->getApologySentBy(), 'and not by whom');

        $crawler = $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('The message has been sent.', $text, 'the operator was told');
        self::assertStringContainsString('Sent by ' . self::OPERATOR_NAME, $text, 'and who sent it is drawn');
        self::assertCount(0, $crawler->selectButton('Send this message'), 'the button is still offered');

        // The stale tab. Same token, same row, and nothing goes out.
        $this->client->request(
            'POST',
            sprintf('https://%s/control/signups/%d/apology', $this->host, $signup->getId() ?? 0),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        self::assertEmailCount(0, 'a second operator apologised to the same person');
    }

    /**
     * **Written in the language the signup recorded**, which §8.14 already does
     * for the invitation and §8.12 for the confirmation.
     *
     * This person has no account anywhere, so there is no stored preference and
     * no session to read: the language forwarded with the submission is the only
     * thing that knows, and it is on the row. Asserted on the preview as well,
     * because an operator shown a paragraph of unexpected German needs the page
     * to say why.
     */
    public function testTheMessageIsWrittenInTheLanguageTheSignupRecorded(): void
    {
        $this->stalledSignup(self::GERMAN, 'xiv108-german', 'Deutsche Firma AG', locale: 'de');

        $crawler = $this->openList();

        self::assertStringContainsString(
            'Written in de',
            $crawler->filter('details')->text(),
            'the page does not say which language the preview is in',
        );

        $this->client->submit($crawler->selectButton('Send this message')->form());

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        self::assertStringContainsString('ist noch nicht bereit', (string) $message->getSubject());
        self::assertEmailTextBodyContains($message, 'Du hast diese Adresse bestätigt');
    }

    /**
     * A stranded signup, which is what everything above is about.
     *
     * `preflight` is the one stage {@see SignupProvisioningStage::isWorthRetrying()}
     * answers no to, and the fixture goes through
     * {@see SignupRequest::recordProvisioningFailure()} rather than writing the
     * three columns by hand, so a change to what a failure records reaches these
     * tests instead of going round them.
     */
    private function stalledSignup(
        string $email,
        string $slug,
        string $company,
        int $attempts = 1,
        string $locale = 'en',
    ): SignupRequest {
        return $this->failingSignup($email, $slug, $company, SignupProvisioningStage::Preflight, $attempts, $locale);
    }

    private function failingSignup(
        string $email,
        string $slug,
        string $company,
        SignupProvisioningStage $stage,
        int $attempts = 1,
        string $locale = 'en',
    ): SignupRequest {
        $manager = self::service(EntityManagerInterface::class);

        $signup = new SignupRequest(
            $email,
            $company,
            $slug,
            'standard',
            $locale,
            hash('sha256', $email . $slug),
            new \DateTimeImmutable('+24 hours'),
        );

        $signup->confirm();

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $signup->recordProvisioningFailure($stage);
        }

        $manager->persist($signup);
        $manager->flush();

        return $signup;
    }

    private function openList(): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/control/login', $this->host));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));

        $crawler = $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function listPageText(): string
    {
        return $this->openList()->filter('body')->text();
    }

    /**
     * The row as the database holds it now.
     *
     * Re-fetched rather than refreshed: the browser reboots the kernel between
     * requests, so the object the fixture returned belongs to an entity manager
     * that no longer exists, and asking the current one to refresh it would fail
     * for a reason that has nothing to do with what is being tested.
     */
    private function reload(string $email): SignupRequest
    {
        self::service(EntityManagerInterface::class)->clear();

        $signup = self::service(SignupRequestRepository::class)->findOneByEmail($email);
        \assert($signup instanceof SignupRequest);

        return $signup;
    }

    /** Line breaks and runs of spaces flattened, so two renderings of one text compare equal. */
    private static function normalised(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function removeFixtures(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->clear();

        foreach (self::service(SignupRequestRepository::class)->findFailed() as $signup) {
            if (str_ends_with($signup->getEmail(), self::ADDRESS_SUFFIX)) {
                $manager->remove($signup);
            }
        }

        $operator = self::service(OperatorRepository::class)->findOneByEmail(self::EMAIL);

        if ($operator instanceof Operator) {
            $manager->remove($operator);
        }

        $manager->flush();
        $manager->clear();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

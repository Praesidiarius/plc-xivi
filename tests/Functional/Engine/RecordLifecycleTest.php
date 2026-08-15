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

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Records that move through states, and refuse to move through the wrong ones
 * (XIV-14).
 *
 * Driven through the browser rather than against the workflow component,
 * because what is being tested is not that symfony/workflow works — it does —
 * but that it works over a record whose state lives in an ordinary field of a
 * per-tenant payload, which is the part nobody else has tried.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordLifecycleTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_lifecycle';
    private const string HOST = 'lifecycle.localhost';
    private const string ADMIN = 'admin@lifecycle.test';
    private const string MEMBER = 'member@lifecycle.test';
    private const string PASSWORD = 'lifecycle-password';
    private const string FORM = 'module_record';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(JobModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /** Only the moves that are legal from here, and no others. */
    public function testTheRecordOffersTheTransitionsItsStateAllows(): void
    {
        $id = $this->aJob();

        $buttons = $this->buttonsOn($id);

        self::assertContains('Start', $buttons);
        self::assertContains('Cancel', $buttons);
        self::assertNotContains('Finish', $buttons, 'a draft cannot be finished');
    }

    public function testMakingATransitionMovesTheRecord(): void
    {
        $id = $this->aJob();

        $this->transition($id, 'start');

        self::assertResponseRedirects();
        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('Active', $page);
        self::assertContains('Finish', $this->buttonsOn($id), 'and offers what follows');
        self::assertNotContains('Start', $this->buttonsOn($id));
    }

    /**
     * The rule the whole feature exists for: a paid invoice does not become a
     * draft again because somebody typed a URL.
     */
    public function testATransitionThatIsNotLegalFromHereIsRefused(): void
    {
        $id = $this->aJob();

        // "finish" is legal, but only from active.
        $this->transition($id, 'finish');

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('not possible', $page);
        self::assertStringContainsString('Draft', $page, 'and it stayed where it was');
    }

    /** A hand-edited transition name is a refusal, not a 500. */
    public function testATransitionThatDoesNotExistIsRefused(): void
    {
        $id = $this->aJob();

        $this->transition($id, 'teleport');

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('not a step', $page);
    }

    /**
     * A state can end editing. The button goes, and — the half that matters —
     * so does the route behind it.
     */
    public function testAFinishedRecordCanNoLongerBeEdited(): void
    {
        $id = $this->aJob();
        $this->transition($id, 'start');
        $this->transition($id, 'finish');

        $page = $this->client->followRedirect()->filter('main')->text();
        self::assertStringNotContainsString('Edit', $page, 'the button is gone');

        $this->client->request('GET', $this->url('/m/job/' . $id . '/edit'));

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'can no longer be edited',
            $this->client->followRedirect()->filter('main')->text(),
        );
    }

    /** And nothing moves on from a final state. */
    public function testAFinalStateOffersNothing(): void
    {
        $id = $this->aJob();
        $this->transition($id, 'cancel');

        self::assertSame([], $this->buttonsOn($id));
    }

    /** The timeline says the record moved, not that a field happened to differ. */
    public function testATransitionIsRecordedOnTheTimeline(): void
    {
        $id = $this->aJob();
        $this->transition($id, 'start');

        $timeline = $this->client->request('GET', $this->url('/m/job/' . $id . '/history'))->filter('main')->text();

        self::assertStringContainsString('Status changed', $timeline);
        self::assertStringContainsString('by Admin', $timeline);
        self::assertStringContainsString('Draft → Active', $timeline);
    }

    /**
     * Moving a record and editing one are different authorities (§8.4): sending
     * an invoice is not correcting a typo in it.
     */
    public function testMovingARecordIsItsOwnPermission(): void
    {
        $id = $this->aJob();

        $this->grant(self::MEMBER, ModuleAction::View);
        $this->grant(self::MEMBER, ModuleAction::Edit);

        $this->signIn(self::MEMBER);

        self::assertSame([], $this->buttonsOn($id), 'editing is not moving');

        $this->transition($id, 'start');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->signIn(self::ADMIN);
        $this->grant(self::MEMBER, ModuleAction::Transition);
        $this->signIn(self::MEMBER);

        self::assertContains('Start', $this->buttonsOn($id));
    }

    // -- helpers ------------------------------------------------------------

    /** @return list<string> the transition buttons drawn on the record */
    private function buttonsOn(int $id): array
    {
        return $this->client->request('GET', $this->url('/m/job/' . $id))
            ->filter('form[action*="/transition/"] button')
            ->each(static fn ($node): string => trim($node->text()));
    }

    private function transition(int $id, string $name): void
    {
        $tokens = $this->client->request('GET', $this->url('/m/job/' . $id))
            ->filter('input[name="_token"]')
            ->each(static fn ($node): string => (string) $node->attr('value'));

        // Somebody who may not transition is shown no form and therefore no
        // token; the route refuses them before the token is ever looked at,
        // which is the thing that test is checking.
        $this->client->request(
            'POST',
            $this->url(sprintf('/m/job/%d/transition/%s', $id, $name)),
            ['_token' => $tokens[0] ?? 'no-token'],
        );
    }

    private function aJob(): int
    {
        $this->client->request('GET', $this->url('/m/job/new'));
        $this->client->submitForm('Save', [
            self::field('title') => 'Rewire the office',
            self::field('status') => JobModule::DRAFT,
        ]);

        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private function grant(string $email, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, JobModule::KEY, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

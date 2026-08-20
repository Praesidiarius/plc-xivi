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

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeAudience;
use App\Registry\Entity\NoticePriority;
use App\Registry\Entity\NoticeReach;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\NoticeDismissal;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tests\Support\SharesATenant;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * The operator's other channel: a band on every page rather than a card on the
 * dashboard (XIV-166, docs/architecture/identity-and-access.md §8.16).
 *
 * {@see NoticeWidgetTest} is the same feature's quiet half and is unchanged by
 * this ticket, which is itself part of what these tests are for: the two
 * surfaces have to stay disjoint, and the one page where they both exist is the
 * dashboard.
 *
 * **What is actually at risk here, in order.** A notice reaching a page it was
 * not meant for is the obvious one and the least interesting, because it is a
 * `WHERE` clause. The two that would be found late are: **the same announcement
 * drawn twice on the dashboard**, which no unit test can see because it needs
 * both surfaces rendered into one document; and **the quiet channel starting to
 * cost something on every page**, which nothing would report at all, because the
 * page would still be correct. The query-count test below exists for the second,
 * and it is the only test in this class that would still pass if it were
 * deleted and re-added wrongly, which is why its assertion is a comparison of
 * two measurements rather than a number somebody typed.
 *
 * The control plane is not rolled back between tests (DAMA is off for that
 * connection), so the notices written here are removed by hand on the way in and
 * on the way out. The dismissals are in the tenant database and roll back with
 * everything else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EveryPageNoticeTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_every_page_notice';
    private const string HOST = 'every-page-notice.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    private const string READER = 'reader@every-page.test';

    /** Every notice this class writes says this, so the cleanup can find them and nothing else. */
    private const string MARK = '[every-page-notice-test]';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The shell resolves the reader and the tenant per request, and a
        // rebooting kernel would throw away the tenant the sign-in landed on.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(UserCreator::class)
            ->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, [UserManager::ROLE_ADMIN]);

        $this->forgetNotices();
    }

    protected function tearDown(): void
    {
        $this->forgetNotices();

        parent::tearDown();
    }

    /**
     * The acceptance criterion in one line: it is on a page that is not the
     * dashboard.
     *
     * The account page is chosen because it is as far from an announcement as
     * this application gets, which is the point of the channel: somebody
     * changing their own password should still be told the installation is going
     * down tonight.
     */
    public function testAnEveryPageNoticeIsOnAPageThatIsNotTheDashboard(): void
    {
        $this->publish('Maintenance tonight', NoticeReach::EveryPage);

        $this->signIn();

        self::assertStringContainsString('Maintenance tonight', $this->text('/account'));
    }

    /**
     * And on the dashboard, exactly once.
     *
     * **The test the two surfaces exist for.** The dashboard is the one page
     * that draws both the shell's band and the notices widget, so a reach filter
     * applied to one of them and forgotten on the other produces a page that is
     * not wrong in any way a router or a permission would catch: it simply says
     * the same thing twice, in two shapes, to somebody who then wonders which of
     * them they are supposed to act on.
     */
    public function testItIsOnTheDashboardAndIsNotSaidTwiceThere(): void
    {
        $this->publish('Maintenance tonight', NoticeReach::EveryPage);

        $this->signIn();

        $page = $this->text('/');

        self::assertSame(
            1,
            substr_count($page, 'Maintenance tonight'),
            'the shell draws it and the widget does not',
        );
    }

    /**
     * The default reach stays where it was, which is half of "existing notices
     * keep working".
     *
     * A notice published with no opinion about reach is a dashboard notice, and
     * a dashboard notice has no business on the account page. The other half,
     * that it still appears on the dashboard, is {@see NoticeWidgetTest}'s and
     * is not repeated here.
     */
    public function testADashboardNoticeIsNowhereButTheDashboard(): void
    {
        $this->publish('A release note', NoticeReach::Dashboard);

        $this->signIn();

        self::assertStringNotContainsString('A release note', $this->text('/account'));
        self::assertStringContainsString('A release note', $this->text('/'));
    }

    /**
     * **A dashboard notice costs nothing on any other page**, measured rather
     * than reasoned about.
     *
     * The claim is not "the page is correct", which the test above already
     * covers. It is that the quiet channel is *free* elsewhere: the shell's read
     * filters on `reach` in the `WHERE` clause, so a live dashboard notice comes
     * back empty and {@see \App\Tenant\Notice\NoticeInbox} never goes on to ask
     * the customer's own database about dismissals. Filtered in PHP instead, the
     * page would look identical and would cost a second query, on every request,
     * for a notice nobody was ever going to be shown there.
     *
     * Two measurements compared rather than a number written down, because a
     * number would be this application's current query count and would have to be
     * edited by whoever next adds a query to the account page, at which point it
     * stops being about notices.
     */
    public function testADashboardNoticeAddsNoQueryToAnotherPage(): void
    {
        $this->signIn();

        $quiet = $this->queriesOn('/account');

        $this->publish('A release note', NoticeReach::Dashboard);

        self::assertSame(
            $quiet,
            $this->queriesOn('/account'),
            'a notice addressed at the dashboard changed what another page costs',
        );
    }

    /**
     * The four priorities draw the four Bootstrap contexts, and none of them
     * draws `text-bg-*`.
     *
     * All four in one page, which is not a shape an operator would produce and
     * is exactly the shape a test wants: the mapping is an identity in every arm
     * ({@see \App\Twig\NoticeExtension}), so the failure it is written against is
     * a template that interpolates the enum's value and quietly stops being a
     * mapping at all. That failure passes a one-priority test.
     *
     * The `text-bg-` assertion is §5.26's rule held to: those helpers pin a
     * foreground against a fixed brand colour and are not redefined under
     * `[data-bs-theme=dark]`, so a band built from one is legible on a light page
     * and wrong on a dark one.
     */
    public function testEachPriorityDrawsItsOwnAlertContext(): void
    {
        foreach (NoticePriority::cases() as $priority) {
            $this->publish('Priority ' . $priority->value, NoticeReach::EveryPage, priority: $priority);
        }

        $this->signIn();

        $markup = (string) $this->client->request('GET', $this->url('/account'))->html();

        foreach (['info', 'warning', 'success', 'danger'] as $tone) {
            self::assertStringContainsString('alert alert-' . $tone, $markup);
        }

        self::assertStringNotContainsString(
            'text-bg-',
            $markup,
            'text-bg-* pins a foreground Bootstrap does not redefine in dark mode (§5.26)',
        );
    }

    /**
     * Nobody is offered a way to put an every-page notice away.
     *
     * See {@see NoticeReach::isDismissible()} for the argument. The shape of the
     * assertion matters: it looks for the *control*, because a band with a button
     * on it that writes nothing would be worse than either answer to the
     * question.
     */
    public function testThereIsNoWayToDismissAnEveryPageNotice(): void
    {
        $notice = $this->publish('Maintenance tonight', NoticeReach::EveryPage);

        $this->signIn();

        $page = $this->client->request('GET', $this->url('/account'));

        self::assertStringContainsString('Maintenance tonight', $page->filter('body')->text());
        self::assertCount(
            0,
            $page->filter(sprintf('form[action="/notices/%d/dismiss"]', $notice->getId())),
            'the loud channel offers no dismiss control',
        );
    }

    /**
     * And posting one by hand writes nothing.
     *
     * The absence of a button is a fact about a template; this is the fact about
     * the write path, and it is the one that matters, because the decision has to
     * survive somebody reconstructing the form. It goes through the same route
     * and the same token a dashboard card's button would use, so what is being
     * tested is the reach and nothing else.
     */
    public function testDismissingAnEveryPageNoticeByHandWritesNothing(): void
    {
        $notice = $this->publish('Maintenance tonight', NoticeReach::EveryPage);

        $this->signIn();

        // **A real token, taken off a real form.** There is one token for the
        // whole widget rather than one per notice
        // ({@see \App\Controller\NoticeController}), so a dashboard notice's
        // own dismiss button hands over exactly what a hand-made POST about the
        // every-page notice would carry. That is the harder case and the one
        // worth testing: the request has to be refused on the reach rather than
        // waved away by the CSRF check, which would prove nothing.
        $decoy = $this->publish('A release note', NoticeReach::Dashboard);

        $token = $this->client->request('GET', $this->url('/'))
            ->filter(sprintf('form[action="/notices/%d/dismiss"] input[name="_token"]', $decoy->getId()))
            ->attr('value');

        $this->client->request(
            'POST',
            $this->url(sprintf('/notices/%d/dismiss', $notice->getId())),
            ['_token' => $token],
        );

        self::assertSame(0, $this->dismissalCount(), 'nothing was written for an undismissable notice');

        // And it is still there afterwards, which is the point of refusing.
        self::assertStringContainsString('Maintenance tonight', $this->text('/account'));
    }

    /**
     * The band is not on a page nobody is signed in to.
     *
     * The login page renders through the same shell in the sense that matters,
     * and `AppChrome` is a Twig global reachable from every template: an
     * announcement on the sign-in screen would be the operator talking to
     * whoever happens to be at the keyboard, which is not who a notice is
     * addressed to.
     */
    public function testTheBandIsNotOnTheSignInPage(): void
    {
        $this->publish('Maintenance tonight', NoticeReach::EveryPage);

        self::assertStringNotContainsString('Maintenance tonight', $this->text('/login'));
    }

    // -- helpers -------------------------------------------------------------

    /** Publishes into the control plane, exactly as the operator's screen does. */
    private function publish(
        string $title,
        NoticeReach $reach,
        NoticePriority $priority = NoticePriority::Info,
    ): Notice {
        $control = self::service(EntityManagerInterface::class);

        $notice = new Notice(
            $title,
            // The body deliberately does not repeat the title. Half of what this
            // class asserts is *how many times* a title appears on a page, and a
            // body echoing it would make every one of those counts two.
            'Something an operator had to say. ' . self::MARK,
            NoticeAudience::Everyone,
            everyTenant: true,
            authorLabel: 'The Operator',
            reach: $reach,
            priority: $priority,
        );

        $control->persist($notice);
        $control->flush();

        return $notice;
    }

    /**
     * How many database statements one page costs, whichever connection they
     * were made on.
     *
     * Both connections deliberately: the failure this measures is a second query
     * against the *customer's* database appearing on a page that had no business
     * asking it anything, and a count that looked only at the control plane
     * would miss exactly that.
     */
    private function queriesOn(string $path): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $this->url($path));

        $profile = $this->client->getProfile();
        \assert($profile instanceof Profile);

        $collector = $profile->getCollector('db');
        \assert($collector instanceof DoctrineDataCollector);

        return $collector->getQueryCount();
    }

    private function dismissalCount(): int
    {
        return $this->inTenant(static function (): int {
            $tenant = self::getContainer()->get('doctrine.orm.tenant_entity_manager');
            \assert($tenant instanceof EntityManagerInterface);

            return (int) $tenant->createQuery(
                'SELECT COUNT(d.id) FROM ' . NoticeDismissal::class . ' d',
            )->getSingleScalarResult();
        });
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    private function text(string $path): string
    {
        return $this->client->request('GET', $this->url($path))->filter('body')->text();
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::READER,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * Everything this class wrote into the control plane, which is not rolled
     * back with the test.
     *
     * Found by the mark rather than by id, so a run that died halfway is cleaned
     * up by the next one.
     */
    private function forgetNotices(): void
    {
        $control = self::service(EntityManagerInterface::class);

        $control->createQuery('DELETE FROM App\Registry\Entity\Notice n WHERE n.body LIKE :mark')
            ->setParameter('mark', '%' . self::MARK . '%')
            ->execute();

        $control->clear();
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

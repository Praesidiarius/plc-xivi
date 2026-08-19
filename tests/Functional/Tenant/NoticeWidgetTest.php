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
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\NoticeDismissal;
use App\Tenant\Repository\NoticeDismissalRepository;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What an operator said, on the dashboard of the people it was said to
 * (XIV-120, docs/architecture.md §8.16).
 *
 * The customer's half of the ticket.
 * {@see \App\Tests\Functional\Deployment\NoticeGrantsTest} proves the read works
 * with the privileges a customer-facing instance already has, and
 * {@see \App\Tests\Functional\ControlPlane\NoticeTest} proves an operator can see
 * what is live; this proves the notice arrives where somebody is actually
 * working, reaches the right people inside the installation, and can be put away
 * by one of them without being put away for the rest.
 *
 * **The two databases are both real here**, which is the reason this class is
 * slower than the other two: the notice is a control-plane row and the dismissal
 * is a row in the customer's own database, and the whole shape of the feature is
 * that neither of those can be the other. So the assertions about dismissal read
 * the tenant's table directly rather than inferring it from the page.
 *
 * The control plane is not rolled back between tests (DAMA is off for that
 * connection), so the notices this class writes are removed by hand on the way in
 * and on the way out. The dismissals are in the tenant database and roll back
 * with everything else.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class NoticeWidgetTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_notice_widget';
    private const string HOST = 'notice-widget.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    /** Somebody who runs this installation for their company: the audience a trial notice is for. */
    private const string ADMIN = 'admin@notices.test';

    /** And somebody who does not: the audience a maintenance window is for. */
    private const string READER = 'reader@notices.test';

    /** Another customer of the same installation, as a registry row: nothing connects to it. */
    private const string OTHER_SLUG = 'test_notice_widget_other';

    /** Every notice this class writes says this, so the cleanup can find them and nothing else. */
    private const string MARK = '[notice-widget-test]';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The widget resolves the reader and the tenant per request, and a
        // rebooting kernel would throw away the tenant the sign-in landed on
        // between one request and the next.
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Alex Admin', self::PASSWORD, [UserManager::ROLE_ADMIN]);
        $users->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, []);

        $this->forgetNotices();
    }

    protected function tearDown(): void
    {
        $this->forgetNotices();

        parent::tearDown();
    }

    /**
     * The ordinary case, and the acceptance criterion in one line: it appears in
     * the customer's own installation, where they are already working.
     */
    public function testANoticeAppearsOnTheDashboard(): void
    {
        $this->publish('Sunday maintenance');

        $this->signIn(self::READER);

        $page = $this->dashboard()->filter('main')->text();

        self::assertStringContainsString('Sunday maintenance', $page);
        self::assertStringContainsString('The body of Sunday maintenance', $page);
    }

    /**
     * **And nobody else's.** A notice addressed to another customer is not on
     * this one's dashboard, and the test says both halves so that it cannot pass
     * by the widget being broken.
     */
    public function testANoticeAddressedToAnotherCustomerIsNotShown(): void
    {
        $this->publish('For us', recipients: [$this->tenant]);
        $this->publish('For somebody else', recipients: [$this->otherTenant()]);

        $this->signIn(self::READER);

        $page = $this->dashboard()->filter('main')->text();

        self::assertStringContainsString('For us', $page);
        self::assertStringNotContainsString('For somebody else', $page);
    }

    /**
     * Who within a tenant sees it is decided per notice, which is the criterion
     * this covers, and both directions are asserted because they are one clause.
     */
    public function testAnAdministratorsNoticeReachesOnlyThem(): void
    {
        $this->publish('Everybody reads this');
        $this->publish('Your trial ends', audience: NoticeAudience::Administrators);

        $this->signIn(self::READER);
        $reader = $this->dashboard()->filter('main')->text();

        self::assertStringContainsString('Everybody reads this', $reader);
        self::assertStringNotContainsString('Your trial ends', $reader);

        $this->signIn(self::ADMIN);
        $administrator = $this->dashboard()->filter('main')->text();

        self::assertStringContainsString('Everybody reads this', $administrator);
        self::assertStringContainsString('Your trial ends', $administrator);
    }

    /**
     * Dismissing is one person putting one notice away.
     *
     * The second half is the one that matters: a colleague who has not dismissed
     * it still sees it. A tenant-wide dismissal would let whoever opened the
     * dashboard first take a maintenance window off everybody else's screen,
     * which is exactly the silence this feature exists to end.
     */
    public function testDismissingPutsItAwayForOnePersonOnly(): void
    {
        $notice = $this->publish('Sunday maintenance');

        $this->signIn(self::READER);
        $this->dismiss($notice);

        self::assertStringNotContainsString('Sunday maintenance', $this->dashboard()->filter('main')->text());

        $this->signIn(self::ADMIN);

        self::assertStringContainsString('Sunday maintenance', $this->dashboard()->filter('main')->text());
    }

    /**
     * **And the dismissal is written where a customer's writes belong** — their
     * own database — which is the criterion, and is not observable from the page.
     *
     * §4.4 gives a customer-facing instance no write privilege anywhere in the
     * control-plane database, so this is not a preference: it is the only place
     * the row could have gone. The notice itself is checked as untouched in the
     * same test, because "dismissed" landing on the notice would be one customer
     * withdrawing an announcement made to all of them.
     */
    public function testTheDismissalIsStoredInTheCustomersOwnDatabase(): void
    {
        $notice = $this->publish('Sunday maintenance');

        $this->signIn(self::READER);
        $this->dismiss($notice);

        $dismissals = $this->inTenant(fn (): array => self::service(NoticeDismissalRepository::class)->findBy([
            'noticeId' => $notice->getId(),
        ]));

        self::assertCount(1, $dismissals);
        $dismissal = $dismissals[0];
        \assert($dismissal instanceof NoticeDismissal);
        self::assertSame($this->readerId(), $dismissal->getUserId());

        // And the notice is exactly as the operator left it.
        $control = self::service(EntityManagerInterface::class);
        $control->clear();
        $fresh = $control->find(Notice::class, $notice->getId());
        \assert($fresh instanceof Notice);

        self::assertNull($fresh->getExpiresAt(), 'a customer dismissing a notice does not withdraw it');
        self::assertTrue($fresh->isLiveAt(new \DateTimeImmutable()));
    }

    /**
     * A withdrawn notice comes off every dashboard, without anybody dismissing
     * anything.
     *
     * This is the operator's undo, and it is the same mechanism as an expiry —
     * see {@see Notice::withdraw()}.
     */
    public function testAWithdrawnNoticeLeavesTheDashboard(): void
    {
        $notice = $this->publish('Briefly true');

        $this->signIn(self::READER);
        self::assertStringContainsString('Briefly true', $this->dashboard()->filter('main')->text());

        // Re-read for the reason {@see managed()} gives: the browser has been
        // round the kernel since this row was written, and a flush on a detached
        // entity writes nothing at all — which would make this test pass for a
        // withdrawal that never happened.
        $control = self::service(EntityManagerInterface::class);
        $live = $control->find(Notice::class, $notice->getId());
        \assert($live instanceof Notice);

        $live->withdraw(new \DateTimeImmutable('-1 second'));
        $control->flush();

        self::assertStringNotContainsString('Briefly true', $this->dashboard()->filter('main')->text());
    }

    /**
     * With nothing to say, the card is not on the page at all.
     *
     * Not an empty "Announcements" box, which is what a widget that returned a
     * panel unconditionally would produce on every dashboard in every
     * installation for ever — furniture, and the reason the one week it says
     * something would be the week nobody notices it.
     */
    public function testThereIsNoCardWhenNothingIsLive(): void
    {
        $this->signIn(self::READER);

        $page = $this->dashboard()->filter('main')->text();

        self::assertStringNotContainsString('Notices', $page);
        self::assertStringNotContainsString('Dismiss', $page);
    }

    /**
     * Publishes into the control plane, exactly as the operator's screen does.
     *
     * @param list<Tenant> $recipients empty addresses every customer
     */
    private function publish(
        string $title,
        array $recipients = [],
        NoticeAudience $audience = NoticeAudience::Everyone,
    ): Notice {
        $control = self::service(EntityManagerInterface::class);

        $notice = new Notice(
            $title,
            'The body of ' . $title . ' ' . self::MARK,
            $audience,
            everyTenant: $recipients === [],
            authorLabel: 'The Operator',
        );

        foreach ($recipients as $recipient) {
            // Re-read rather than reuse. Symfony resets the entity manager
            // between requests, so a `Tenant` that was loaded before this
            // browser did anything is detached by now — and Doctrine reads a
            // detached entity on a new association as a *new* one it has been
            // told not to cascade. That is a property of a functional test
            // driving a browser, not of the feature.
            $notice->addRecipient($this->managed($recipient));
        }

        $control->persist($notice);
        $control->flush();

        return $notice;
    }

    /** The same customer, as this entity manager currently knows it. */
    private function managed(Tenant $tenant): Tenant
    {
        $managed = self::service(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['slug' => $tenant->getSlug()]);

        \assert($managed instanceof Tenant);

        return $managed;
    }

    /** Presses the button the widget draws, rather than posting a made-up form. */
    private function dismiss(Notice $notice): void
    {
        $form = $this->dashboard()
            ->filter(sprintf('form[action="/notices/%d/dismiss"]', $notice->getId()))
            ->form();

        $this->client->submit($form);
    }

    private function dashboard(): Crawler
    {
        return $this->client->request('GET', $this->url('/'));
    }

    /** Another customer of this installation, as a registry row with no database behind it. */
    private function otherTenant(): Tenant
    {
        $control = self::service(EntityManagerInterface::class);
        $existing = $control->getRepository(Tenant::class)->findOneBy(['slug' => self::OTHER_SLUG]);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        $tenant = new Tenant(self::OTHER_SLUG, 'Somebody Else', 'postgresql://nobody@nowhere/other');

        $control->persist($tenant);
        $control->flush();

        return $tenant;
    }

    private function readerId(): int
    {
        return $this->inTenant(function (): int {
            $user = self::service(UserRepository::class)->findOneBy(['email' => self::READER]);
            \assert($user !== null);

            return (int) $user->getId();
        });
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    /**
     * Signs somebody in, having signed out whoever was there.
     *
     * Two people read the same notice in half the tests here — that is most of
     * what the audience and the dismissal are about — and `/login` redirects an
     * already-authenticated browser to the dashboard, so without the sign-out
     * the second call would find no form and the test would fail somewhere
     * unrelated.
     */
    private function signIn(string $email): void
    {
        $this->client->request('GET', $this->url('/logout'));

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
     * Everything this class wrote into the control plane, which is not rolled
     * back with the test.
     *
     * Found by the mark rather than by id, so a run that died halfway is cleaned
     * up by the next one — `SharesATenant`'s argument about tenant databases,
     * applied to rows.
     */
    private function forgetNotices(): void
    {
        $control = self::service(EntityManagerInterface::class);

        $control->createQuery('DELETE FROM App\Registry\Entity\NoticeRecipient r WHERE r.notice IN (
            SELECT n.id FROM App\Registry\Entity\Notice n WHERE n.body LIKE :mark
        )')->setParameter('mark', '%' . self::MARK . '%')->execute();

        $control->createQuery('DELETE FROM App\Registry\Entity\Notice n WHERE n.body LIKE :mark')
            ->setParameter('mark', '%' . self::MARK . '%')
            ->execute();

        $control->createQuery('DELETE FROM App\Registry\Entity\Tenant t WHERE t.slug = :slug')
            ->setParameter('slug', self::OTHER_SLUG)
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

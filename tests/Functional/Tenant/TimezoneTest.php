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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\DisplayTimezone;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reading a moment on the clock you are actually looking at (XIV-83).
 *
 * The chain is the language one's shape with a fourth link: the person, the
 * installation, the country the installation named, and UTC. Only the last two
 * are new, and the third is the one carrying the weight — most customers will
 * never open this setting, because choosing Switzerland already chose
 * `Europe/Zurich`.
 *
 * Both ends are walked here. The resolver is asserted directly, because the
 * interesting cases are the ones where it must decline to guess and nothing on a
 * page would reveal that it had guessed wrong. The rendering is asserted through
 * a real request, because the mechanism is a listener setting Twig's own zone and
 * a resolver that resolved perfectly into a template nobody had wired up would
 * pass every unit test there is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TimezoneTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_timezone';
    private const string HOST = 'timezone.localhost';
    private const string ADMIN = 'admin@timezone.test';
    private const string PASSWORD = 'a-long-enough-password';

    /** Nine hours from UTC all year, so a wall clock there is never UTC's. */
    private const string TOKYO = 'Asia/Tokyo';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
    }

    /**
     * The step that makes this cheap: a country with one zone answers for
     * itself.
     *
     * Nobody at a Swiss company should have to be told that Switzerland is on
     * Zurich time — they said Switzerland already, and repeating the question
     * would be the settings page admitting it had not listened.
     */
    public function testACountryWithOneZoneAnswersForItself(): void
    {
        self::assertSame('Europe/Zurich', $this->resolvedFor(region: 'CH'));
        self::assertSame('Europe/London', $this->resolvedFor(region: 'GB'));
    }

    /**
     * India, which is one zone under two names and would have been read as two.
     *
     * CLDR lists `Asia/Calcutta` beside `Asia/Kolkata` because the tz database
     * keeps the old name as a link after a city is renamed. Counting identifiers
     * would have made India ambiguous and asked an Indian customer to choose
     * between Calcutta and Kolkata, which is not a choice anybody can get wrong
     * and not a question worth putting on a screen.
     */
    public function testAnOldNameForTheSameZoneIsNotASecondZone(): void
    {
        self::assertSame('Asia/Kolkata', $this->resolvedFor(region: 'IN'));
    }

    /**
     * And the trap this rule exists to avoid, which is the whole of the ticket.
     *
     * `Timezones::forCountryCode()` returns CLDR's order, not an order of
     * importance: Spain's list opens with `Africa/Ceuta` and America's with
     * `America/Adak`. Taking the head would file a Madrid office in North Africa
     * and a New York one in the Aleutians — wrong by an hour or by eleven, and
     * invisible either way, because a timestamp in the wrong zone still looks
     * exactly like a timestamp. So an ambiguous country derives nothing and the
     * setting becomes one somebody answers.
     */
    public function testAnAmbiguousCountryIsNotGuessedAt(): void
    {
        self::assertSame('UTC', $this->resolvedFor(region: 'ES'), 'Spain has three, and one of them is in Africa');
        self::assertSame('UTC', $this->resolvedFor(region: 'US'), 'the United States has thirty-one');
        self::assertSame('UTC', $this->resolvedFor(region: 'AU'));
    }

    /**
     * Germany's two are the same offset — Büsingen is a German exclave inside
     * Switzerland keeping Swiss time — and it still declines.
     *
     * Deliberate rather than an oversight: collapsing zones that happen to agree
     * today means keeping a list of "close enough" pairs that is true only until
     * one of them changes its rules. The rule stays arithmetic.
     */
    public function testEvenTwoZonesThatAgreeAreTwoZones(): void
    {
        self::assertSame('UTC', $this->resolvedFor(region: 'DE'));
    }

    /** The installation's own answer beats what its country would have implied. */
    public function testTheInstallationOverridesWhatTheRegionWouldDerive(): void
    {
        self::assertSame('Europe/Madrid', $this->resolvedFor(region: 'ES', profileTimezone: 'Europe/Madrid'));
        self::assertSame(self::TOKYO, $this->resolvedFor(region: 'CH', profileTimezone: self::TOKYO));
    }

    /** And the person beats the installation, which is what the column is for. */
    public function testThePersonOverridesTheInstallation(): void
    {
        self::assertSame(
            self::TOKYO,
            $this->resolvedFor(region: 'CH', profileTimezone: 'Europe/Zurich', userTimezone: self::TOKYO),
        );
    }

    /**
     * A person's *region* feeds the derivation too, not only the company's.
     *
     * Somebody who said they read British conventions and nothing else gets
     * London, even at a Swiss company — the effective region is the one the
     * chain already resolved for formatting, so the two settings cannot disagree
     * about which country they mean.
     */
    public function testThePersonsOwnRegionIsWhatGetsDerivedFrom(): void
    {
        self::assertSame('Europe/London', $this->resolvedFor(region: 'CH', userRegion: 'GB'));
    }

    /**
     * A console command has no user and may have no tenant, and neither is an
     * error.
     *
     * `TenantContext::tryGetTenant()` returning null is the ordinary condition in
     * `bin/console` and on the login page, so the chain runs out of things to ask
     * and lands on UTC rather than throwing — the same handling
     * `FormattingLocale::instanceRegion()` already has.
     */
    public function testWithNoTenantAndNoUserItIsUtcRatherThanAFailure(): void
    {
        self::assertSame('UTC', self::service(DisplayTimezone::class)->of()->getName());
    }

    /**
     * The rendering half, through a real request.
     *
     * `lastLoginAt` is a stored moment on a page anybody administering this
     * installation reads, and signing in has just written one — so the wall clock
     * it renders as is the assertion, and the UTC spelling of the same instant is
     * the thing that must *not* be there. The check is against what the database
     * actually holds rather than against a clock read in the test, which would
     * fail once a minute at the wrong moment.
     */
    public function testAStoredMomentIsRenderedInTheResolvedZone(): void
    {
        $this->signIn();
        $this->setUserTimezone(self::TOKYO);

        $when = $this->lastLoginAt();
        $text = (string) $this->client->request('GET', $this->url('/users'))->filter('main')->text();

        self::assertStringContainsString(
            $when->setTimezone(new \DateTimeZone(self::TOKYO))->format('Y-m-d H:i'),
            $text,
            'the moment reads on the clock this person is looking at',
        );
        self::assertStringNotContainsString(
            $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'),
            $text,
            'and not on the one it happens to be stored against',
        );
    }

    /**
     * Storage is untouched, which is the claim the whole ticket rests on.
     *
     * The column is `timestamptz`, so Postgres normalised to UTC on write and
     * kept no per-row zone; the reading preference above changed which clock it
     * is *shown* on and nothing else. Asserted by reading the same row back and
     * finding the same instant, whatever the reader chose.
     */
    public function testChoosingAZoneDoesNotMoveWhatIsStored(): void
    {
        $this->signIn();

        $before = $this->lastLoginAt();
        $this->setUserTimezone(self::TOKYO);

        self::assertSame(
            $before->getTimestamp(),
            $this->lastLoginAt()->getTimestamp(),
            'a display setting does not touch an absolute moment',
        );
    }

    /** Both settings pages offer the picker, named the way CLDR names zones. */
    public function testBothSettingsPagesOfferAPicker(): void
    {
        $this->signIn();

        $account = $this->client->request('GET', $this->url('/account'));
        self::assertCount(1, $account->filter('select[name="timezone"]'));
        self::assertStringContainsString('Zurich', (string) $account->filter('select[name="timezone"]')->text());

        $profile = $this->client->request('GET', $this->url('/settings/profile'));
        self::assertCount(1, $profile->filter('select[name="timezone"]'));
    }

    /**
     * Empty is a real answer and the one nearly everybody keeps, so the picker
     * has to be able to give the choice back.
     */
    public function testTheChoiceCanBeGivenBack(): void
    {
        $this->signIn();
        $this->setUserTimezone(self::TOKYO);
        self::assertSame(self::TOKYO, $this->user()->getTimezone());

        $this->setUserTimezone('');

        self::assertNull($this->user()->getTimezone());
    }

    /**
     * Something the select could never have offered came from a hand-edited
     * request, and the honest answer is to clear the setting rather than to store
     * a zone that will be silently ignored on every page from then on.
     *
     * Posted by hand rather than through the crawler, which refuses to put a
     * value into a `<select>` that does not offer it — a refusal that is itself
     * half the point, since it is the reason this can only arrive from outside
     * the form.
     */
    public function testAnIdentifierThatIsNotAZoneIsNotStored(): void
    {
        $this->signIn();
        $this->setUserTimezone(self::TOKYO);

        $crawler = $this->client->request('GET', $this->url('/account'));

        $this->client->request('POST', $this->url('/account'), [
            '_token' => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'action' => 'language',
            'timezone' => 'Middle/Earth',
        ]);

        self::assertNull($this->user()->getTimezone());
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The chain resolved against one arrangement of the three settings that feed
     * it.
     *
     * The user is built rather than persisted — it is a value the resolver reads
     * two getters off, and writing it to the database would only make the test
     * slower and the tenant dirtier.
     */
    private function resolvedFor(
        string $region,
        ?string $profileTimezone = null,
        ?string $userTimezone = null,
        ?string $userRegion = null,
    ): string {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use (
            $region,
            $profileTimezone,
            $userTimezone,
            $userRegion,
        ): string {
            self::service(TenantProfileManager::class)->apply('Timezone AG', '', $region, null, $profileTimezone);

            $user = new User('reader@timezone.test', 'Reader');
            $user->setRegion($userRegion);
            $user->setTimezone($userTimezone);

            return self::service(DisplayTimezone::class)->of($user)->getName();
        });
    }

    private function setUserTimezone(string $timezone): void
    {
        $crawler = $this->client->request('GET', $this->url('/account'));

        $this->client->submit($crawler->selectButton('Save language')->form([
            'timezone' => $timezone,
        ]));
    }

    private function lastLoginAt(): \DateTimeImmutable
    {
        $when = $this->user()->getLastLoginAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $when);

        return $when;
    }

    private function user(): User
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): User {
            $user = self::service(UserRepository::class)->findOneByEmail(self::ADMIN);
            self::assertInstanceOf(User::class, $user);

            return $user;
        });
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::ADMIN,
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

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

use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a page loads, and from where (XIV-28).
 *
 * Two claims worth a test rather than a comment, because both are the kind that
 * quietly stops being true: **every asset comes from this host**, and **the
 * front-end library is actually on the page**. The first is a promise to customers — a CDN reports
 * every visitor's IP to a third party on every page load, which is an odd
 * footnote under a product sold on physical data isolation. The second is the
 * thing everything built on it assumes.
 *
 * The login page on purpose: it is the one page every customer loads, and the
 * one nobody needs an account to reach.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FrontEndAssetsTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_assets';
    private const string HOST = 'assets.localhost';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        // Provisioned and then left alone: what these tests read is the page a
        // tenant's host serves, not anything inside its database.
        $this->sharedTenant(self::SLUG, [self::HOST]);
    }

    /** Live Components is in the importmap the page ships, so a component will run. */
    public function testTheFrontEndLibraryIsOnThePage(): void
    {
        self::assertStringContainsString('@symfony/ux-live-component', $this->page());
    }

    /**
     * And it comes from here. An importmap naming a CDN would work perfectly and
     * tell a third party who every customer's users are.
     */
    public function testNothingIsLoadedFromACdn(): void
    {
        $page = $this->page();

        preg_match_all('#"(https?://[^"]+)"#', $page, $matches);

        foreach ($matches[1] as $url) {
            // Anything that is not fetched by the browser is fine: a namespace,
            // a link somebody clicks, a schema URL.
            self::assertStringNotContainsString(
                'cdn',
                $url,
                sprintf('the page asks a third party for %s', $url),
            );
        }

        self::assertMatchesRegularExpression('#"/assets/[^"]*live_controller[^"]*"#', $page, 'served from our own host');
    }

    private function page(): string
    {
        $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}

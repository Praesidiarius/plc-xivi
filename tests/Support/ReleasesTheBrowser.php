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

namespace App\Tests\Support;

/**
 * Give the browser back when the test that opened it is done (XIV-45).
 *
 * **Panther does not close a Selenium session, ever.** Its client cache is only
 * consulted for the `chrome` and `firefox` browsers
 * ({@see \Symfony\Component\Panther\PantherTestCaseTrait::createPantherClient()}
 * returns early for those two and for no others), so a suite talking to a grid,
 * which this one does, gets a **new session per test method**. Nothing closes
 * them either: `ServerExtension` resets only its own list of registered clients
 * after each test, `tearDownAfterClass()` is disabled while that extension is
 * loaded, and the one client `stopWebServer()` quits at the very end is the last
 * one created, because the array it is kept in is written at index zero every
 * time.
 *
 * So every browser test held a slot on the grid until the run ended. The grid
 * has four (compose.override.yaml), and this suite has more tests than that, so
 * from the fifth test onwards every test waited for the node's 300-second idle
 * reaper to take somebody else's session away. That is most of what the browser
 * leg's running time was, and it is why a test occasionally failed with a curl
 * timeout on `POST /session`: the request for a slot gives up at 180 seconds and
 * the reaper does not act until 300.
 *
 * Which was survivable at four tests over four slots and stopped being so the
 * moment XIV-45 added a seventeenth. One line fixes it and makes the leg faster
 * rather than merely possible, so this is that line, in the one place all eight
 * classes can share it.
 *
 * **A second `quit()` is harmless**, which is what makes this safe to do
 * underneath a component that also quits: `Client::quit()` nulls its own
 * WebDriver first, so the extension's closing quit on the same object does
 * nothing. `isset()` rather than a null check, because the property is typed and
 * a test that failed before opening a browser never initialised it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait ReleasesTheBrowser
{
    protected function tearDown(): void
    {
        if (isset($this->browser)) {
            $this->browser->quit();
        }

        parent::tearDown();
    }
}

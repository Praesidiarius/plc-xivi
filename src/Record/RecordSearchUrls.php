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

namespace App\Record;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Xivi\Core\Record\RecordSearchUrl;

/**
 * The application half of `RecordSearchUrl`: core asks where a picker searches,
 * and this answers with a route (XIV-36).
 *
 * The same seam as `InstanceCurrency` and `RecordAccessProvider`, one level
 * further out — a form type in core needs a URL, and a route is the
 * application's to name. Three lines, and the point of them is that
 * {@see \Xivi\Core\Form\RecordReferenceType} never learns the route's name, so
 * moving or renaming the endpoint is a change to this file and to nothing in the
 * engine.
 *
 * **A path rather than a full URL.** The page doing the fetching was served from
 * this host under this tenant's own hostname (§4), so a path reaches the right
 * customer's data by construction; generating an absolute URL would mean picking
 * a host, and the only ways to pick one are to read it back off the request —
 * which is the same answer — or to configure it, which is a second place for the
 * hostname to be wrong.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordSearchUrls implements RecordSearchUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function forModule(string $moduleKey, ?string $variant): string
    {
        return $this->urls->generate('record_search', array_filter([
            'module' => $moduleKey,
            'variant' => $variant,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }
}

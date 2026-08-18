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
use Xivi\Core\Record\RecordPageUrl;

/**
 * The application half of `RecordPageUrl`: core asks where a record's page is,
 * and this answers with a route (XIV-66).
 *
 * A sibling of {@see RecordSearchUrls} in every respect, including the reason it
 * is three lines. What it buys is that a dashboard widget shipped by a module
 * package can hand somebody a list they can click through without the module
 * learning the application's routing table — the boundary §3 draws would otherwise
 * be leaking through a Twig `path()` call, which is exactly the place deptrac
 * cannot follow it.
 *
 * `module_show` is generic over the module, so this needs no per-module knowledge
 * and gains none as modules are added.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordPageUrls implements RecordPageUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function forRecord(string $moduleKey, int $id): string
    {
        return $this->urls->generate('module_show', ['module' => $moduleKey, 'id' => $id]);
    }
}

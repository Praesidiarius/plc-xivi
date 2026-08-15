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

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pressing "add a line of this kind" on a record's form (XIV-29).
 *
 * The buttons are real submit buttons carrying what they mean, with htmx over
 * the top, so a browser without scripting drives the same mechanism the swap
 * does. That is what lets these tests exercise the real thing rather than a
 * server-side shortcut nobody uses.
 *
 * Posted by hand rather than through `selectButton()`, because a `<button>` is
 * matched by its text and these are labelled in the reader's language — a test
 * that pressed "Add Article line" would be a test of the English catalogue.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait AddsCollectionRows
{
    /**
     * The page again, with one more empty row of that kind at the end of the
     * collection — so the new row's index is however many there were before.
     *
     * @param string $kind empty for a collection whose rows are all the same thing
     */
    protected function addRow(KernelBrowser $client, Crawler $page, string $collection, string $kind = ''): Crawler
    {
        $form = $page->selectButton('Save')->form();

        $values = $form->getPhpValues();
        $values['add'] = $collection . ':' . $kind;

        return $client->request('POST', $form->getUri(), $values);
    }

    /** How many rows a collection's form currently has. */
    protected function rowCount(Crawler $page, string $collection): int
    {
        return $page->filter(sprintf('[name^="module_record[collections][%s]"][name$="[id]"]', $collection))->count();
    }
}

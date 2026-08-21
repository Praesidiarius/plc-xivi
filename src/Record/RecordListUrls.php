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

use App\Controller\ModuleController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Xivi\Core\Query\Filter;
use Xivi\Core\Record\RecordListUrl;

/**
 * The application half of `RecordListUrl`: core asks where a module's list is
 * and this answers with a route (XIV-178).
 *
 * A sibling of {@see RecordPageUrls} and {@see RecordSearchUrls} in every
 * respect, including the reason it is short. What it buys is that a module's own
 * index body, of which the knowledge base's cards are the first, can point past
 * its own ceiling at §5.3's list without the module learning that `module_index`
 * exists.
 *
 * ## The parameters are the filter bar's, deliberately
 *
 * `filter[0][path]`, `filter[0][op]` and `filter[0][value]` are exactly what the
 * GET form at the top of the index submits, and
 * {@see \Xivi\Core\Query\RecordQueryFactory} reads them straight back. That is
 * the whole reason a card's "see them all" needs no route and no endpoint of its
 * own: the question *which records hold this value* is one §5.3 already answers,
 * and pointing at it in its own vocabulary means the page somebody lands on has
 * the filter visibly applied in the bar, ready to be widened by hand.
 *
 * ## `view=list` is spelled here and read in the controller
 *
 * {@see ModuleController::VIEW} is the page's own parameter and the constants
 * live with the page (XIV-168 put them on a core value object; XIV-178 moved
 * them here, where the only two things that touch them are the controller that
 * reads it and this, which writes it). Core asks for "the plain list" as a
 * boolean and never learns the spelling, which is the same division as the route
 * name one line up.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordListUrls implements RecordListUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function forModule(string $moduleKey, array $filters, bool $asRows): string
    {
        $parameters = ['module' => $moduleKey];

        foreach ($filters as $index => $filter) {
            // The operator's `value`, not its name: the form submits the same
            // backing string and the factory matches on it, so a card's link and
            // a filter somebody typed are the same URL.
            $parameters['filter'][$index] = [
                'path' => $filter->path(),
                'op' => $filter->operator->value,
                // Every operator this is reachable with today compares against a
                // single scalar. One that does not, a filter on nothing such as
                // `IsEmpty`, carries null, which the form submits as the empty
                // string and the factory reads back as the same absence.
                'value' => $filter->value ?? '',
            ];
        }

        if ($asRows) {
            $parameters[ModuleController::VIEW] = ModuleController::AS_LIST;
        }

        return $this->urls->generate('module_index', $parameters);
    }
}

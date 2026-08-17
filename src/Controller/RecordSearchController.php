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

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Candidate;
use Xivi\Core\Record\RecordCandidates;

/**
 * Records of any module, searched by name, for a picker somebody is typing into
 * (XIV-36).
 *
 * **One route, generic over module, variant and shape**, like everything else
 * here. An `order_contact_search` beside it would be the module-specific code
 * §1 exists not to have: which module is being searched comes from the URL and
 * what its records are called comes from that customer's own title fields
 * (§5.4), so a module installed tomorrow is searchable the day it arrives and
 * nothing is written for it.
 *
 * **Scoped exactly as the picker is** (§8.4, XIV-13), and this is the part worth
 * being careful about rather than the JSON. An unrestricted search endpoint is
 * strictly worse than the unrestricted picker XIV-13 closed: a picker leaks the
 * names it happens to render, once, on a page somebody was already allowed to
 * open — a search box lets them enumerate a module a letter at a time. So two
 * seams, both of them, the same two every list here goes through (§7.5):
 *
 * - `#[IsGranted]` refuses anybody with no `view` grant on that module at all,
 *   before the action runs;
 * - and the same `RecordAccess` predicate a list compiles narrows what comes
 *   back, so somebody scoped to their own records cannot find a colleague's by
 *   typing its name.
 *
 * There is deliberately **no exception for administrators** written in here. An
 * administrator's bypass belongs where permissions resolve, and a second one
 * buried in a query is how the two answers come to differ.
 *
 * **It answers with names, and names are what a reference already shows.** A
 * result here is a record the caller could have seen in the dropdown a moment
 * ago, which is why this needs no rule of its own about what may be displayed —
 * it reuses {@see RecordCandidates}, the same object the select reads, so the
 * two cannot come to disagree about what exists or what it is called.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordSearchController extends AbstractController
{
    /**
     * The metadata is here only to refuse a module this customer does not have.
     *
     * {@see RecordCandidates} treats one as "no candidates", which is right
     * where a *form* is being drawn — a half-configured reference should render
     * as an empty picker rather than as a stack trace (§7.6). A URL somebody
     * asked for is a different question, and every other module route answers it
     * with 404 (§3): not installed here is a runtime fact about this customer,
     * not an error, and another customer may well have it. An endpoint that said
     * "no results" instead would be the one place in the application where a
     * module that does not exist looks like a module that is empty.
     */
    public function __construct(
        private readonly RecordCandidates $candidates,
        private readonly MetadataRepository $metadata,
    ) {
    }

    /**
     * The shape is Tom Select's, because the widget is (XIV-36): a list of
     * `{value, text}` and a URL for the next page, or null when this was the
     * last one.
     *
     * **`next_page` rather than a total**, and that is a deliberate trade. The
     * widget scrolls the dropdown into the next page and says "no more results"
     * when there is not one, so the reader is told where the list ends without
     * this endpoint counting the matches on every keystroke — a count is a
     * second full scan of the same predicate, run per typed character, to
     * produce a number nothing renders. The cost of not counting is that a match
     * count landing exactly on a page boundary offers one more page and it comes
     * back empty, which the widget draws as the end anyway.
     *
     * The rest of a page's worth of care lives one layer down: the variant, the
     * ordering, the access predicate and what a record is called are all
     * {@see RecordCandidates}, so this method is the HTTP of it and nothing
     * else.
     */
    #[Route(
        '/m/{module}/search',
        name: 'record_search',
        requirements: ['module' => '[a-z][a-z0-9_]*'],
        methods: ['GET'],
    )]
    #[IsGranted(ModuleAction::View->value, subject: 'module')]
    public function search(string $module, Request $request): JsonResponse
    {
        try {
            $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        $variant = trim((string) $request->query->get('variant'));
        $query = trim((string) $request->query->get('query'));
        $page = max(1, $request->query->getInt('page', 1));

        $found = $this->candidates->find(
            $module,
            $variant === '' ? null : $variant,
            $query,
            $page,
            RecordCandidates::PER_PAGE,
        );

        return new JsonResponse([
            'results' => array_map(
                static fn (Candidate $candidate): array => ['value' => $candidate->id, 'text' => $candidate->label],
                $found,
            ),
            'next_page' => \count($found) < RecordCandidates::PER_PAGE ? null : $this->generateUrl('record_search', [
                'module' => $module,
                'variant' => $variant,
                'query' => $query,
                'page' => $page + 1,
            ]),
        ]);
    }
}

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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\ValueList\ValueIcon;
use Xivi\Core\ValueList\ValueListEditor;
use Xivi\Core\ValueList\ValueListNotFound;
use Xivi\Core\ValueList\ValueLists;
use Xivi\Core\ValueList\ValueListUsage;
use Xivi\Core\ValueList\ValueListUse;
use Xivi\Core\ValueList\ValueTone;

/**
 * The lists a customer keeps once and several fields point at (XIV-127, §5.4).
 *
 * **Not under `/m/{module}`, and that is the ticket's decision showing up in a
 * URL.** A shared list is a core concept beside field definitions rather than a
 * module: it belongs to no module, several modules' fields point at it, and a
 * module may not depend on another module (§3), so it could not have lived
 * inside one of them even if somebody had wanted it to. It has no store entry,
 * no price, no records anybody browses and no permission of its own — which is
 * also why there is no `#[NoModulePermission]` here: that attribute answers a
 * check about routes under a module's surface, and these are not.
 *
 * **Admin only**, on {@see FieldController}'s reasoning word for word. Changing
 * what a module *is* is not one of the things you do *to* its records; changing
 * the vocabulary several modules share is the same statement one level up.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/lists')]
#[IsGranted('ROLE_ADMIN')]
final class ValueListController extends AbstractController
{
    public function __construct(
        private readonly ValueLists $lists,
        private readonly ValueListEditor $editor,
        private readonly ValueListUsage $usage,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Every list, with what points at it.
     *
     * The uses are on this page rather than only in the refusals, for the reason
     * §5.4 gives about the counts beside a choice field's options: a rule
     * somebody meets as a refusal is a rule they learn one failure at a time.
     * "Regions — used by Kunden → Region and Aufträge → Region" is what makes
     * the delete button's refusal predictable before it happens.
     */
    #[Route('', name: 'value_list_index', methods: ['GET'])]
    public function index(): Response
    {
        $lists = $this->lists->all();
        $uses = [];

        foreach ($lists as $list) {
            $uses[$list->getKey()] = array_map(
                static fn (ValueListUse $use): string => $use->label(),
                $this->usage->of($list),
            );
        }

        return $this->render('value_list/index.html.twig', [
            'lists' => $lists,
            'uses' => $uses,
        ]);
    }

    #[Route('', name: 'value_list_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($this->isCsrfTokenValid('edit-lists', (string) $request->request->get('_token'))) {
            try {
                $list = $this->editor->create((string) $request->request->get('label'));

                $this->addFlash('success', $this->translator->trans('flash.list_created', [
                    '%list%' => $list->getLabel(),
                ]));

                // Straight to the new list, because a list with no entries is
                // not a thing anybody wanted — it is the first half of "make me
                // a list of regions", and the second half is on the next page.
                return $this->redirectToRoute('value_list_show', ['list' => $list->getKey()]);
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('value_list_index');
    }

    /**
     * One list: its entries, their colours, their pictures, their parents, and
     * how many records hold each.
     *
     * **The counts are read here rather than in the refusal**, on §5.4's rule.
     * They are also summed across every field pointing at the list, because that
     * is the number the refusal will use and two different numbers for one
     * question would be worse than none.
     */
    #[Route('/{list}', name: 'value_list_show', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function show(string $list): Response
    {
        $definition = $this->list($list);

        return $this->render('value_list/show.html.twig', [
            'list' => $definition,
            'entries' => $definition->inTreeOrder(),
            'parents' => $definition->possibleParents(),
            'held' => $this->usage->recordsHolding($definition, $definition->values()),
            'uses' => array_map(
                static fn (ValueListUse $use): string => $use->label(),
                $this->usage->of($definition),
            ),
            'tones' => ValueTone::settable(),
            'icons' => ValueIcon::settable(),
        ]);
    }

    /**
     * Saving it: renames, colours, pictures, parents, order, additions, and
     * whatever somebody ticked to remove.
     *
     * Everything the page drew comes back, on the options page's contract
     * (XIV-144): the entries that arrive are the entries the list ends up with,
     * and one missing from the form is one somebody removed rather than one they
     * did not mention. That is what lets a removal be refused at all.
     *
     * Nothing about *whether* a removal is allowed is decided here.
     * {@see ValueListEditor} counts the records holding it — across every field
     * pointing at the list, in every module — and refuses with the numbers.
     */
    #[Route('/{list}', name: 'value_list_save', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function save(string $list, Request $request): Response
    {
        $definition = $this->list($list);

        if ($this->isCsrfTokenValid('edit-lists', (string) $request->request->get('_token'))) {
            try {
                $this->editor->update(
                    list: $definition,
                    label: (string) $request->request->get('list_label'),
                    entries: self::entriesFrom($request),
                    remove: array_map(strval(...), array_keys($request->request->all('remove'))),
                    added: preg_split('/\R/', (string) $request->request->get('add', '')) ?: [],
                );

                $this->addFlash('success', $this->translator->trans('flash.list_saved', [
                    '%list%' => $definition->getLabel(),
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('value_list_show', ['list' => $list]);
    }

    /**
     * What the form says about each entry that is staying.
     *
     * Read against the list's own entries rather than against the form, so a
     * value invented in a hand-edited request adds nothing: what is not already
     * an entry cannot be changed, and what is not already an entry cannot be
     * created here either — additions are labels and go through the same
     * derivation everything else does.
     *
     * @return array<string, array{label?: string, tone?: ?string, icon?: ?string, parent?: ?string, position?: int}>
     */
    private static function entriesFrom(Request $request): array
    {
        /** @var array<string, mixed> $labels */
        $labels = $request->request->all('label');
        /** @var array<string, mixed> $tones */
        $tones = $request->request->all('tone');
        /** @var array<string, mixed> $icons */
        $icons = $request->request->all('icon');
        /** @var array<string, mixed> $parents */
        $parents = $request->request->all('parent');
        /** @var array<string, mixed> $positions */
        $positions = $request->request->all('position');

        $entries = [];

        foreach ($labels as $value => $label) {
            $value = (string) $value;

            $entries[$value] = [
                'label' => trim((string) $label),
                // Through the enums rather than trusted, exactly as the
                // autocomplete and country selects are: the controls offer the
                // answers there are, so anything else is a hand-edited form, and
                // the honest response to one of those is no colour rather than a
                // class name of somebody's choosing in the page's markup.
                'tone' => ValueTone::tryOf(self::stringOr($tones[$value] ?? null))?->value,
                'icon' => ValueIcon::tryOf(self::stringOr($icons[$value] ?? null))?->value,
                'parent' => self::stringOr($parents[$value] ?? null),
                'position' => (int) ($positions[$value] ?? 0),
            ];
        }

        return $entries;
    }

    private static function stringOr(mixed $value): ?string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * What merging one entry into another would do, before it is done
     * (XIV-127, XIV-91).
     *
     * **A page of its own, on the backfill's argument.** Everything else on the
     * list screen is instantaneous and reversible — a colour, a label, an order
     * — and this one rewrites a value on every record holding it, across every
     * module pointing at the list, and cannot be taken back. Putting it in a row
     * of that table would make the change with the most consequences look like
     * the cheapest one on the page.
     *
     * A POST rather than a GET, because what is being confirmed is two values
     * somebody picked in a form; the plan below is read from them, so this is a
     * page built from a submission rather than one addressed by a URL.
     */
    #[Route('/{list}/merge', name: 'value_list_merge_confirm', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function confirmMerge(string $list, Request $request): Response
    {
        $definition = $this->list($list);
        $from = (string) $request->request->get('from');
        $into = (string) $request->request->get('into');

        if (!$this->isCsrfTokenValid('edit-lists', (string) $request->request->get('_token'))
            || $definition->getEntry($from) === null
            || $definition->getEntry($into) === null
            || $from === $into
        ) {
            // The sentence the write path would have used, from the page that
            // offers only this list's own entries and never offers one as its
            // own target — so arriving here means a form posted around it.
            $this->addFlash('warning', MetadataChangeRefused::cannotMergeThat($definition->getLabel())
                ->translatable()
                ->trans($this->translator));

            return $this->redirectToRoute('value_list_show', ['list' => $list]);
        }

        return $this->render('value_list/merge.html.twig', [
            'list' => $definition,
            'plan' => $this->usage->plan($definition, $from, $into),
        ]);
    }

    /**
     * And doing it, once somebody has said the word.
     *
     * **The confirmation is required here rather than only in the template**,
     * which is XIV-91's rule verbatim and the half that a test calling the
     * service directly cannot prove. A `required` attribute is a courtesy to
     * somebody using the page and nothing at all to a form posted around it, and
     * on the other side of this call is a write into every record of every
     * module pointing at this list that cannot be taken back.
     *
     * The figure in the flash comes back from the merge rather than from the
     * page that was agreed to: a record saved between the two is one more record
     * rewritten, and the sentence somebody reads afterwards should be about what
     * happened.
     */
    #[Route('/{list}/merge/do', name: 'value_list_merge', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function merge(string $list, Request $request): Response
    {
        $definition = $this->list($list);

        if ($this->isCsrfTokenValid('edit-lists', (string) $request->request->get('_token'))
            && $request->request->getBoolean('confirm')
        ) {
            try {
                $written = $this->editor->merge(
                    $definition,
                    (string) $request->request->get('from'),
                    (string) $request->request->get('into'),
                );

                $this->addFlash('success', $this->translator->trans('flash.list_merged', [
                    '%list%' => $definition->getLabel(),
                    '%count%' => $written,
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('value_list_show', ['list' => $list]);
    }

    /**
     * What deleting a whole list would mean, before it happens.
     *
     * A GET confirmation like the one in front of removing a field, because
     * there is nothing to carry: the only input is the decision. What it has to
     * say is that the entries go with it and that records holding those values
     * keep them as plain text — which is the same promise §5.4 makes for a
     * removed option, and the one somebody would otherwise assume the other way
     * round.
     */
    #[Route('/{list}/delete', name: 'value_list_confirm_delete', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function confirmDelete(string $list): Response
    {
        $definition = $this->list($list);

        return $this->render('value_list/delete.html.twig', [
            'list' => $definition,
            'uses' => array_map(
                static fn (ValueListUse $use): string => $use->label(),
                $this->usage->of($definition),
            ),
            'held' => $this->usage->recordsHolding($definition, $definition->values()),
        ]);
    }

    #[Route('/{list}/delete', name: 'value_list_delete', requirements: ['list' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function delete(string $list, Request $request): Response
    {
        $definition = $this->list($list);

        if ($this->isCsrfTokenValid('edit-lists', (string) $request->request->get('_token'))) {
            try {
                $this->editor->delete($definition);

                $this->addFlash('success', $this->translator->trans('flash.list_deleted', [
                    '%list%' => $definition->getLabel(),
                ]));

                return $this->redirectToRoute('value_list_index');
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('value_list_show', ['list' => $list]);
    }

    /** One list by key, or a 404 — a URL naming something that is not there. */
    private function list(string $key): ValueList
    {
        try {
            return $this->lists->get($key);
        } catch (ValueListNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}

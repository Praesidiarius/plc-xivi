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

use App\Tenant\Security\NoModulePermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Module\ModuleUpgrade;

/**
 * Taking what a module grew after this customer installed it (XIV-70, §7.2.1).
 *
 * §6.1 does not retro-fit: a blueprint is a seed, and once a module is installed
 * the customer's definitions are the truth. That rule is right and this does not
 * touch it. What it adds is the missing half — an **explicit** way to say yes.
 * A customer who chose the `basic` preset a year ago, or who installed Contact
 * before it grew an addresses collection, could see neither and had no path to
 * either; now they are shown what their module has gained and choose, per item,
 * whether to take it.
 *
 * **Administrators, on the metadata editor's authority rather than the store's**
 * (§5.4, §8.4.3). It is tempting to hang this off the store grant, because the
 * thing being taken came from a module's blueprint and the store is where
 * modules come from. But a store grant says who may put a *new* module in the
 * installation, and this changes the shape of every record in a module that is
 * already there — which is the sentence §5.4 uses to explain why field editing
 * is admin-only. So it sits under `/m/{module}` beside the field editor, carries
 * the same `ROLE_ADMIN`, and says here why it names no module permission: a
 * grant says who may edit a contact, and this decides what a contact *is*.
 *
 * **Three steps, and the middle one is [XIV-91]'s** — choose, be told the scale,
 * confirm. Nothing here destroys anything, and it is confirmed anyway, because
 * "a table appears in your database and every record in this module gains four
 * fields" is a sentence somebody should read before it is true rather than
 * after. As in XIV-91, the confirmation is required in *this class* and not only
 * as a `required` attribute in the template: an attribute is a courtesy to
 * somebody using the page and nothing at all to a form posted around it.
 *
 * Hand-rolled POST and CSRF rather than a FormType, matching
 * {@see FieldController} and {@see ModuleStoreController}: this is a list of
 * checkboxes and two buttons, and a FormType here would be the odd one out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/upgrade', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted('ROLE_ADMIN')]
#[NoModulePermission(
    'Taking what a module grew changes what its records *are*, which is the '
    .'metadata editor\'s authority and not one of the things you do *to* a '
    .'record. Nor is it the store\'s: that grant is about installing a module '
    .'the customer does not have. Administrators only (§5.4, §7.2.1).',
)]
final class ModuleUpgradeController extends AbstractController
{
    /**
     * The same token id the field editor uses, deliberately.
     *
     * Both screens are the same permission on the same subject — an
     * administrator changing what a module is — so two ids would be two names
     * for one thing.
     */
    private const string CSRF = 'edit-fields';

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly ModuleUpgrade $upgrade,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * What this module has gained, and what was said no to.
     *
     * Two lists rather than one, because a dismissed addition is not a deleted
     * one: the decision is remembered so the offer stops nagging, and it is kept
     * visible so it can be taken back. Neither list costs a scan of anybody's
     * records — the counts belong to the confirmation, which is the one request
     * that can afford them.
     */
    #[Route('', name: 'module_upgrade', methods: ['GET'])]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('module_upgrade/index.html.twig', [
            'module' => $definition,
            'available' => $this->upgrade->available($definition),
            'dismissed' => $this->upgrade->dismissed($definition),
            // What each field type is called, resolved here rather than in the
            // template for the reason FieldController gives: Twig has no way to
            // ask the registry and should not grow one.
            'types' => $this->fieldTypes->all(),
        ]);
    }

    /**
     * What taking the ticked ones would do, before any of it is done (XIV-91's
     * shape).
     *
     * A POST because it carries the choice made on the page before, and because
     * a GET that counted a customer's whole records table would be a link
     * somebody could put in a crawler.
     *
     * Every figure on the page comes from {@see ModuleUpgrade::plan()}, which is
     * the same computation the real run performs — so this is not a description
     * of the operation but the operation asked not to commit.
     */
    #[Route('/review', name: 'module_upgrade_review', methods: ['POST'])]
    public function review(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('module_upgrade', ['module' => $module]);
        }

        $plan = $this->upgrade->plan($definition, self::chosen($request));

        if ($plan->isEmpty()) {
            // Nothing ticked, or everything ticked has since been taken by
            // somebody else. Either way there is nothing to confirm, and a
            // confirmation page listing nothing would be a worse way to say so.
            $this->addFlash('warning', $this->translator->trans('flash.upgrade_nothing_chosen'));

            return $this->redirectToRoute('module_upgrade', ['module' => $module]);
        }

        return $this->render('module_upgrade/review.html.twig', [
            'module' => $definition,
            'plan' => $plan,
            'types' => $this->fieldTypes->all(),
        ]);
    }

    /**
     * And doing it, once somebody has said the word.
     *
     * The confirmation is checked here rather than only in the template, for the
     * reason [XIV-91] gives: `required` is a courtesy to a browser and nothing to
     * a form posted around it, and on the other side of this call is a table
     * being created in a customer's database.
     *
     * Nothing is refused for having gone stale. The plan is recomputed inside the
     * transaction, so an addition a colleague took in the next tab is simply no
     * longer in it — and the flash counts what happened rather than what was
     * agreed to, which are the same number except in the case where saying so
     * matters.
     */
    #[Route('/take', name: 'module_upgrade_take', methods: ['POST'])]
    public function take(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))
            && $request->request->getBoolean('confirm')
        ) {
            $done = $this->upgrade->take($definition, self::chosen($request), $request->getLocale());

            $this->addFlash('success', $this->translator->trans('flash.upgrade_taken', [
                '%module%' => $definition->getLabel(),
                '%count%' => \count($done->additions),
            ]));
        }

        // To the field editor rather than back here: what somebody wants to see
        // after taking four fields is the four fields, in the list they now sit
        // in, where they can be renamed and reordered like any other.
        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * "Not this one", remembered.
     *
     * One at a time, because it is one decision at a time. The alternative —
     * dismissing whatever was ticked — reads as a bulk action and would let a
     * mis-click answer fifteen questions at once, which is the failure mode this
     * whole feature exists to avoid the other way round.
     */
    #[Route('/dismiss', name: 'module_upgrade_dismiss', methods: ['POST'])]
    public function dismiss(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $addition = $this->upgrade->dismiss($definition, (string) $request->request->get('addition'));

            if ($addition !== null) {
                $this->addFlash('success', $this->translator->trans('flash.upgrade_dismissed', [
                    '%addition%' => $addition->label,
                ]));
            }
        }

        return $this->redirectToRoute('module_upgrade', ['module' => $module]);
    }

    /** And the way back, which is what stops the first answer being a trap. */
    #[Route('/restore', name: 'module_upgrade_restore', methods: ['POST'])]
    public function restore(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $addition = $this->upgrade->restore($definition, (string) $request->request->get('addition'));

            if ($addition !== null) {
                $this->addFlash('success', $this->translator->trans('flash.upgrade_restored', [
                    '%addition%' => $addition->label,
                ]));
            }
        }

        return $this->redirectToRoute('module_upgrade', ['module' => $module]);
    }

    /**
     * The tokens a form ticked, as strings and nothing more.
     *
     * They are not validated here and must not be: {@see ModuleUpgrade} matches
     * them against the offers it computes for this module, so a token naming
     * another customer's shape or an addition that no longer exists resolves to
     * nothing. Checking them twice would be two rules to keep in step, and the
     * second copy is the one that gets forgotten.
     *
     * @return list<string>
     */
    private static function chosen(Request $request): array
    {
        $chosen = $request->request->all('additions');

        return array_values(array_map(strval(...), array_filter($chosen, \is_scalar(...))));
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}

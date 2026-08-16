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

use App\Store\ModuleStore;
use App\Store\StoreInstallRefused;
use App\Store\StoreOffer;
use App\Tenant\Security\StoreAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The store: three screens that let a customer add a module without anybody
 * running a command against their database (XIV-6).
 *
 * That last clause is the whole feature. A customer who signs up lands in an
 * empty installation, and until this existed the only way to put anything in it
 * was `tenant:module:install` — which is the operator's shell, not theirs. The
 * command has not gone anywhere: a headless deployment keeps its path, and both
 * go through the same `ModuleInstaller` so that a module installed here and a
 * module installed there are the same module.
 *
 * **Granted on the store axis, not on a module's** (§8.4.3). Browsing is about no
 * module at all and installing is about a module the customer does *not* have, so
 * there is nothing for a per-module grant to attach to. The `store` argument comes
 * out of the route's own defaults purely so `#[IsGranted]` has a subject to name,
 * exactly as {@see TenantProfileController} does with its area — the check has to
 * happen before the action runs. Every action therefore takes a `$store` it never
 * reads; the attribute resolves its subject from the controller's arguments, so
 * the parameter has to be there for the check to have anything to point at.
 *
 * `{module}` in the path is a *catalogue* key rather than an installed module, so
 * these routes carry a store grant where everything else under `{module}` carries
 * a ModuleAction one. `PermissionCoverageTest` knows about both and still fails
 * the build for a route that names neither.
 *
 * **Hand-rolled POST and CSRF rather than a FormType**, matching
 * {@see ModuleController}, {@see DocumentController}, {@see EmailTemplateController}
 * and {@see PermissionGroupController}. A deliberate departure from the Symfony
 * default, and the neighbours' one: the wizard is a radio group and a button, and
 * a FormType here would be the only one in this application.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/store', defaults: ['store' => StoreAction::SUBJECT])]
final class ModuleStoreController extends AbstractController
{
    private const string CSRF = 'install-module';

    public function __construct(
        private readonly ModuleStore $store,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Everything this build offers, each saying whether it is theirs already. */
    #[Route('', name: 'store_index', methods: ['GET'])]
    #[IsGranted(StoreAction::Browse->value, subject: 'store')]
    public function index(string $store): Response
    {
        return $this->render('store/index.html.twig', [
            'offers' => $this->store->offers(),
        ]);
    }

    /** What one module is, what it can be installed as, and what it needs. */
    #[Route('/{module}', name: 'store_module', requirements: ['module' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    #[IsGranted(StoreAction::Browse->value, subject: 'store')]
    public function show(string $module, string $store): Response
    {
        return $this->render('store/show.html.twig', [
            'offer' => $this->offer($module),
        ]);
    }

    /**
     * The wizard: choose a preset, see what it contains, confirm.
     *
     * GET and POST on one route, as the neighbours do. The refusals are handled
     * the same way on both — a store page kept open while somebody else installs
     * the same module is a real sequence, so the state the wizard was drawn from
     * is never guaranteed to be the state the install happens in.
     */
    #[Route('/{module}/install', name: 'store_install', requirements: ['module' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    #[IsGranted(StoreAction::Install->value, subject: 'store')]
    public function install(string $module, string $store, Request $request): Response
    {
        $offer = $this->offer($module);

        // A submitted install always goes to the store and is refused there when
        // it has to be, rather than being turned away here on the strength of the
        // same page's own reading. The two differ by whatever happened in
        // between — a colleague installing the same module in the next tab — and
        // the refusal is a sentence somebody should read, not a silent redirect.
        if ($this->submitted($request)) {
            try {
                $this->store->install($offer, $this->chosenPreset($request, $offer), $request->getLocale());

                $this->addFlash('success', $this->translator->trans('flash.module_installed', ['%module%' => $offer->label]));
            } catch (StoreInstallRefused $refused) {
                $this->addFlash('warning', $refused->translatable()->trans($this->translator));
            }

            // Back to the module's page either way, which now says they have it.
            // Not to the module itself: nobody holds a permission on a module
            // that did not exist a moment ago, so sending them there would be an
            // invitation to a 403 (§8.4.3).
            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        // Nothing to choose and nothing to confirm: the module is theirs already,
        // or it needs something they have not got. The module's own page is where
        // both of those are written out, so the wizard sends them to read it.
        if (!$offer->isInstallable()) {
            return $this->redirectToRoute('store_module', ['module' => $module]);
        }

        return $this->render('store/install.html.twig', [
            'offer' => $offer,
            'chosen' => $this->chosenPreset($request, $offer),
        ]);
    }

    /**
     * Which preset the form is asking for, or null for "the module's own
     * default".
     *
     * Null is a real answer rather than a missing one: a module shipping no
     * presets installs every field it has, which is what the command does with no
     * `--preset`. An unrecognised value is passed through rather than corrected —
     * {@see ModuleStore::install()} refuses it, because quietly installing a
     * different shape from the one somebody asked for is the worst outcome of a
     * decision nothing can undo.
     */
    private function chosenPreset(Request $request, StoreOffer $offer): ?string
    {
        $chosen = trim((string) $request->request->get('preset', $request->query->get('preset', '')));

        if ($chosen !== '') {
            return $chosen;
        }

        return $offer->presets === [] ? null : $offer->blueprint->defaultPreset;
    }

    private function offer(string $module): StoreOffer
    {
        return $this->store->offer($module) ?? throw $this->createNotFoundException();
    }

    private function submitted(Request $request): bool
    {
        return $request->isMethod('POST')
            && $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }
}

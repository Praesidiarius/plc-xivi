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

use App\Tenant\Entity\User;
use App\Tenant\Security\UserChangeRefused;
use App\Tenant\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Your own account: your name, and your password.
 *
 * Everybody gets this, administrator or not. Without it a person handed a
 * generated password (§8.5) could never change it, and would keep the one their
 * administrator read off a screen for as long as they worked there.
 *
 * Changing the password needs the current one. Not because it is secret from its
 * owner, but because an unattended session should not be enough to take an
 * account over.
 *
 * Your email is deliberately not editable here. It is the login and the security
 * identifier, and letting somebody change it out from under their own session is
 * a sign-out disguised as a settings page — an administrator does it on /users.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/account')]
final class AccountController extends AbstractController
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly UserManager $users,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * The languages this build has, named in themselves.
     *
     * "Deutsch" rather than "German", because somebody looking for their own
     * language is not reading the one they cannot. symfony/intl knows the names,
     * so adding a language to `enabled_locales` adds it here with nothing else
     * touched.
     *
     * @return array<string, string> locale => what to call it
     */
    private function localeChoices(string $current): array
    {
        $choices = [
            '' => $this->translator->trans('account.language_default', [
                '%locale%' => Locales::getName($this->defaultLocale, $current),
            ]),
        ];

        foreach ($this->enabledLocales as $locale) {
            $choices[$locale] = Locales::getName($locale, $locale);
        }

        return $choices;
    }

    #[Route('', name: 'account', methods: ['GET', 'POST'])]
    public function account(Request $request): Response
    {
        $user = $this->getUser();

        // The firewall requires ROLE_USER everywhere but /login, so this is a
        // type narrowing rather than an access check.
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Read before the change, because changing it is what clears the hold —
        // and somebody who has just been let in should be sent on rather than
        // left looking at the page that was holding them.
        $held = $user->mustChangePassword();

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            $this->handle($user, $request);

            if ($held && !$user->mustChangePassword()) {
                return $this->redirectToRoute('dashboard');
            }
        }

        return $this->render('account/index.html.twig', [
            'account' => $user,
            'minimum' => User::MINIMUM_PASSWORD_LENGTH,
            'held' => $user->mustChangePassword(),
            // Named from the enabled set rather than from a list kept here, so
            // adding a language to the build adds it to this picker (XIV-8).
            'locales' => $this->localeChoices($request->getLocale()),
            // Named in the reader's own language, from symfony/intl rather than
            // a list kept here (XIV-50).
            'regions' => Countries::getNames($request->getLocale()),
        ]);
    }

    private function handle(User $user, Request $request): void
    {
        try {
            if ($request->request->get('action') === 'language') {
                $chosen = (string) $request->request->get('locale');

                // Anything this build does not have becomes "follow the
                // default" rather than being stored and silently ignored later.
                $this->users->setLocale(
                    $user,
                    \in_array($chosen, $this->enabledLocales, true) ? $chosen : null,
                );

                // The country's conventions, which is the other half of the
                // same question and stored apart from it (XIV-50).
                $region = strtoupper(trim((string) $request->request->get('region')));
                $this->users->setRegion($user, Countries::exists($region) ? $region : null);

                $this->addFlash('success', $this->translator->trans('account.language_saved'));

                return;
            }

            if ($request->request->get('action') === 'password') {
                $new = (string) $request->request->get('new_password');
                $repeat = (string) $request->request->get('repeat_password');

                if ($new !== $repeat) {
                    // Checked here rather than in the manager: it is a fact about
                    // this form having two boxes, not about what a password is.
                    $this->addFlash('warning', $this->translator->trans('flash.passwords_differ'));

                    return;
                }

                if (mb_strlen($new) < User::MINIMUM_PASSWORD_LENGTH) {
                    $this->addFlash('warning', $this->translator->trans(
                        'flash.password_too_short',
                        ['%count%' => User::MINIMUM_PASSWORD_LENGTH],
                    ));

                    return;
                }

                // Two ways in, and which one applies is a fact about the account
                // rather than about what was posted (XIV-1). Somebody invited by
                // email has no current password to be asked for — none was ever
                // generated — and the signed link they arrived on is what stands
                // in for the proof this question normally collects. The manager
                // checks the same fact again, so a caller cannot pick the wrong
                // branch and be believed.
                if ($user->hasPassword()) {
                    $this->users->changeOwnPassword($user, (string) $request->request->get('current_password'), $new);
                } else {
                    $this->users->setInitialPassword($user, $new);
                }

                $this->addFlash('success', $this->translator->trans('flash.password_changed'));

                return;
            }

            $this->users->updateProfile($user, $user->getEmail(), (string) $request->request->get('name'));
            $this->addFlash('success', $this->translator->trans('flash.saved'));
        } catch (UserChangeRefused $e) {
            $this->addFlash('warning', $e->translatable()->trans($this->translator));
        }
    }
}

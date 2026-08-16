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

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use App\Tenant\Settings\FormattingLocale;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Serves each request in the language the person reading it chose (XIV-8).
 *
 * **Resolved per request from the user, never stored in the session.** Symfony's
 * usual recipe parks `_locale` in the session, which is one more thing that
 * outlives the request that made it — the hazard §7.4 is about, and one this
 * runtime otherwise does not have (§9.2). Reading it off the user object costs
 * nothing, because the user has already been loaded by the time this runs.
 *
 * Priority is below the firewall's, so there is a user to ask. Before it, the
 * token has not been restored and every page would be served in the default.
 *
 * **Which is why the translator is set here too, and not only the request.**
 * Symfony's LocaleAwareListener copies the request's locale onto every
 * locale-aware service at priority 15 — comfortably before the firewall has
 * produced a user, and therefore before this can know which language to ask
 * for. Setting `Request::setLocale()` at this point changes what the *next*
 * thing to read it sees and nothing else, so the page renders in the default
 * and the setting looks broken while being stored perfectly.
 *
 * The fallback chain, in order:
 *
 * 1. What this person chose.
 * 2. What their browser asks for, if this build has it — which is what makes the
 *    *login* page right, the one page there is nobody to ask about.
 * 3. The application default.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class UserLocaleListener
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private Security $security,
        private FormattingLocale $formatting,
        #[Autowire(service: 'translator')]
        private LocaleAwareInterface $translator,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();

        $chosen = $user instanceof User ? $user->getLocale() : null;

        // A locale this build no longer has — a language removed since somebody
        // chose it — falls through rather than serving a page of untranslated
        // keys.
        $locale = $chosen !== null && \in_array($chosen, $this->enabledLocales, true)
            ? $chosen
            : ($request->getPreferredLanguage($this->enabledLocales) ?? $this->defaultLocale);

        // The language decides the words; the region decides how a number is
        // written (XIV-50). They are joined here and nowhere else, so everything
        // downstream still asks one question.
        //
        // `Request::setLocale()` also sets PHP's own default, which is what the
        // field types read when they format — so a Swiss reader gets Swiss
        // figures without a single formatter learning about regions.
        $written = $this->formatting->of($locale, $user instanceof User ? $user : null);

        $request->setLocale($written);

        // The translator is given the same thing: Symfony falls a locale back to
        // its language, so `de_CH` finds the `de` catalogue and a region costs no
        // translation work at all.
        $this->translator->setLocale($written);
    }
}

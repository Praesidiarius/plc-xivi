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

namespace App\Tenant\Document;

use App\Tenancy\TenantContext;
use App\Tenant\Entity\User;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Settings\InstanceName;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\DocumentContext;
use Xivi\Core\Document\DocumentMarker;
use Xivi\Core\Document\DocumentMarkerKind;

/**
 * Who this installation is, and who is writing (XIV-4).
 *
 * The application half of `DocumentContext`: core asks what a document can say
 * that is not about the record, and this answers — because core never learns
 * what a tenant or a user is, the same seam `ProfileCurrency` sits on.
 *
 * **The keys are namespaced** — `tenant.name`, `user.name` — and that is not
 * decoration. A customer's fields become markers under their own keys, and the
 * contact module ships a field called `company_name`; a general marker with a
 * bare name would eventually collide with somebody's field and one of the two
 * would quietly win.
 *
 * **One of them is a picture** (XIV-89). `[tenant.logo]` is declared here beside
 * the rest, because it is the same kind of fact about the same installation —
 * what changes is only what the engine does with it, which is
 * {@see \Xivi\Core\Document\DocumentImages}'s business rather than this class's.
 * The key is a constant so the two halves below cannot drift: the marker that
 * puts it on the reference list and the bytes that fill it in are two methods,
 * and two methods naming the same string literal is how one of them eventually
 * names a different one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InstanceContext implements DocumentContext
{
    /** The one marker that draws rather than writes (XIV-89). */
    public const string LOGO = 'tenant.logo';

    public function __construct(
        private InstanceName $instance,
        private TenantContext $tenancy,
        private TenantProfileRepository $profiles,
        private Security $security,
        private TranslatorInterface $translator,
    ) {
    }

    public function markers(): array
    {
        $user = $this->security->getUser();
        $signedIn = $user instanceof User ? $user : null;

        return [
            new DocumentMarker('tenant.name', $this->label('tenant_name'), $this->instance->current()),
            // Offered whether or not this installation has uploaded one, exactly
            // as `user.name` is offered to a console command with nobody signed
            // in. A marker that appeared and disappeared with the data behind it
            // would mean a template written this week naming something the
            // review calls unknown next week, and the customer would have
            // changed nothing but their logo.
            new DocumentMarker(self::LOGO, $this->label('tenant_logo'), kind: DocumentMarkerKind::Image),
            // Empty rather than absent when nobody is signed in: a console
            // command generating a document is not a failure, and a marker that
            // disappeared would print its own brackets onto the letter.
            new DocumentMarker('user.name', $this->label('user_name'), $signedIn?->getName() ?? ''),
            new DocumentMarker('user.email', $this->label('user_email'), $signedIn?->getEmail() ?? ''),
        ];
    }

    /**
     * The mark itself, straight out of the customer's own database (XIV-89).
     *
     * **No HTTP, and that is the constraint most easily lost.** XIV-49 added a
     * public route serving these bytes so a browser can draw them on a page, and
     * reaching for it here would have been the obvious move — it is already a
     * URL, it already handles the caching, and it would have worked in
     * development. It is the wrong answer: a document is generated from a
     * console command as readily as from a request, the engine would acquire a
     * dependency on the application being reachable from itself, and the
     * generator would start to fail for reasons that live in a load balancer.
     * The bytes are one column on a row this application reads on nearly every
     * page anyway.
     *
     * **Empty outside a tenant**, like {@see InstanceName::current()} and for the
     * same reason: the control plane's hosts have no tenant database to ask, and
     * a profile lookup on a connection that is deliberately unusable is not a
     * failure worth having.
     *
     * **Absent rather than empty when nobody has uploaded one.** The key is
     * simply not offered, which is what tells the engine there is nothing to
     * draw — and what leaves the ordinary blanking of an unfilled marker to
     * produce the "nothing there" the ticket asks for, instead of an empty
     * picture or a broken one.
     */
    public function images(): array
    {
        if (!$this->tenancy->hasTenant()) {
            return [];
        }

        $logo = $this->profiles->current()->getLogo();

        return $logo === null ? [] : [self::LOGO => $logo];
    }

    private function label(string $key): string
    {
        return $this->translator->trans('document.marker.' . $key);
    }
}

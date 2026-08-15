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

use App\Tenant\Entity\User;
use App\Tenant\Settings\InstanceName;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\DocumentContext;
use Xivi\Core\Document\DocumentMarker;

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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InstanceContext implements DocumentContext
{
    public function __construct(
        private InstanceName $instance,
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
            // Empty rather than absent when nobody is signed in: a console
            // command generating a document is not a failure, and a marker that
            // disappeared would print its own brackets onto the letter.
            new DocumentMarker('user.name', $this->label('user_name'), $signedIn?->getName() ?? ''),
            new DocumentMarker('user.email', $this->label('user_email'), $signedIn?->getEmail() ?? ''),
        ];
    }

    private function label(string $key): string
    {
        return $this->translator->trans('document.marker.' . $key);
    }
}

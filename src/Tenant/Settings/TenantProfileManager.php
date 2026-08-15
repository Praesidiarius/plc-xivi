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

namespace App\Tenant\Settings;

use App\Tenant\Entity\TenantProfile;
use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Currencies;

/**
 * The write side of the tenant profile (XIV-12).
 *
 * Kept out of the controller for the same reason UserManager is: what a change is
 * allowed to do belongs next to the change rather than next to the HTTP.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantProfileManager
{
    public function __construct(
        private TenantProfileRepository $profiles,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function current(): TenantProfile
    {
        return $this->profiles->current();
    }

    /**
     * Every currency there is, named in the language being read.
     *
     * From symfony/intl rather than a list kept here, which would be a copy of
     * ISO 4217 going quietly out of date. Sorted by name, because somebody is
     * looking for "Swiss franc" and not for "CHF".
     *
     * @return array<string, string> code => what to call it
     */
    public function currencyChoices(string $locale): array
    {
        return Currencies::getNames($locale);
    }

    /**
     * Applies what the form said.
     *
     * An unknown currency code leaves the stored one alone rather than throwing.
     * The select is built from symfony/intl's own list, so anything else came
     * from a hand-edited request, and the honest answer to that is to change
     * nothing — the same call PermissionManager makes about an unknown module key.
     * An empty code is different and does mean something: nobody has chosen.
     */
    public function apply(string $companyName, string $currency): TenantProfile
    {
        $profile = $this->profiles->current();
        $profile->setCompanyName($companyName);

        if ($currency === '') {
            $profile->setCurrency(null);
        } elseif (Currencies::exists($currency)) {
            $profile->setCurrency($currency);
        }

        // Persisted every time rather than only when new: the entity is already
        // managed on the normal path, and persist() on a managed entity is a
        // no-op — which is cheaper than asking, and correct on the path where the
        // row was missing.
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }
}

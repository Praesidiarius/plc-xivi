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

namespace App\Tenant\FollowUp;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataCache;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Turning follow-ups on and off for one of a customer's modules (XIV-80).
 *
 * Three lines of work and a class of its own, and the reason is the third line:
 * the flag lives on {@see ModuleDefinition}, which is cached for the length of a
 * request ({@see MetadataCache}), so writing it without emptying that cache would
 * leave a screen showing the state the module was in when the page began. That is
 * the same obligation {@see \Xivi\Core\Metadata\MetadataEditor} carries after
 * every change it makes, and the reason nothing should be flipping this boolean
 * by hand.
 *
 * **Where the flag is stored is argued on the entity**, not here: it is a
 * property of this customer's copy of this module, which is what a
 * ModuleDefinition row already is. What is worth repeating at the seam is the
 * consequence — because no table is created per module, this is a boolean rather
 * than DDL, so unlike a preset (§6.1) it can be changed for as long as the
 * installation lives. The store asks the question at install time as a courtesy,
 * not because it is the last chance to answer it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleFollowUps
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        private MetadataRepository $metadata,
        private MetadataCache $cache,
    ) {
    }

    /**
     * Whether this customer's copy of that module takes follow-ups.
     *
     * A module they have not got answers false rather than throwing. The question
     * is asked by things deciding whether to offer something — a panel, a menu
     * entry, a widget's query — and every one of them would otherwise have to ask
     * whether the module exists first, which is the same null check written in
     * five places.
     */
    public function enabledFor(string $moduleKey): bool
    {
        return $this->metadata->find($moduleKey)?->hasFollowUps() ?? false;
    }

    /**
     * Switches them on or off, at any point in the module's life.
     *
     * Nothing is deleted when they go off — see
     * {@see ModuleDefinition::setFollowUps()} for why a toggle that threw rows
     * away would be a toggle nobody used.
     */
    public function set(ModuleDefinition $module, bool $enabled): void
    {
        $module->setFollowUps($enabled);

        $this->entityManager->flush();
        // What the definitions say has changed, and anything holding the previous
        // answer is now wrong (XIV-53).
        $this->cache->clear();
    }
}

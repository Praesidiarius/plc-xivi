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

namespace Xivi\Core\Document;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\DocumentTemplate;

/**
 * The templates one customer has, per module (XIV-4).
 *
 * On the tenant's entity manager, like the definitions themselves — the
 * application binds it, because core has no opinion about which database it
 * operates on (see config/services.yaml).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentTemplateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Every template of one module, newest name order, whatever the variant.
     *
     * @return list<DocumentTemplate>
     */
    public function forModule(string $moduleKey): array
    {
        /** @var list<DocumentTemplate> $templates */
        $templates = $this->entityManager
            ->createQuery(
                'SELECT t FROM ' . DocumentTemplate::class . ' t
                 WHERE t.moduleKey = :module ORDER BY t.name ASC, t.id ASC'
            )
            ->setParameter('module', $moduleKey)
            ->getResult();

        return $templates;
    }

    /**
     * The ones offered for a record of this variant (§5.5).
     *
     * @return list<DocumentTemplate>
     */
    public function forRecord(string $moduleKey, ?string $variant): array
    {
        return array_values(array_filter(
            $this->forModule($moduleKey),
            static fn (DocumentTemplate $t): bool => $t->appliesTo($variant),
        ));
    }

    /**
     * One template of this module.
     *
     * Scoped by module rather than found by id alone: an id in a URL is not a
     * licence to reach another module's stationery, the same rule FieldController
     * applies to a field.
     */
    public function find(string $moduleKey, int $id): ?DocumentTemplate
    {
        $template = $this->entityManager->find(DocumentTemplate::class, $id);

        return $template?->getModuleKey() === $moduleKey ? $template : null;
    }

    public function save(DocumentTemplate $template): void
    {
        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function remove(DocumentTemplate $template): void
    {
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }
}

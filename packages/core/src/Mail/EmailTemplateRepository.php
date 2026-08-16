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

namespace Xivi\Core\Mail;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\EmailTemplate;

/**
 * The email templates one customer has, per module (XIV-38).
 *
 * On the tenant's entity manager, like the definitions themselves — the
 * application binds it, because core has no opinion about which database it
 * operates on (see config/services.yaml).
 *
 * Deliberately the same four questions
 * {@see \Xivi\Core\Document\DocumentTemplateRepository} answers, in the same
 * words, because they are the same four questions: what has this module got,
 * what applies to this record, find one of mine, and write it down. The two are
 * not merged into one generic repository — a document template is a file and an
 * email template is text, and the shared part is thirty lines of DQL rather than
 * a concept.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class EmailTemplateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Every template of one module, name order, whatever the variant.
     *
     * @return list<EmailTemplate>
     */
    public function forModule(string $moduleKey): array
    {
        /** @var list<EmailTemplate> $templates */
        $templates = $this->entityManager
            ->createQuery(
                'SELECT t FROM ' . EmailTemplate::class . ' t
                 WHERE t.moduleKey = :module ORDER BY t.name ASC, t.id ASC'
            )
            ->setParameter('module', $moduleKey)
            ->getResult();

        return $templates;
    }

    /**
     * The ones offered for a record of this variant (§5.5).
     *
     * XIV-39's send modal is the caller that matters: a mail to a company must
     * not be offered on a person, for the same reason a letter to one is not.
     *
     * @return list<EmailTemplate>
     */
    public function forRecord(string $moduleKey, ?string $variant): array
    {
        return array_values(array_filter(
            $this->forModule($moduleKey),
            static fn (EmailTemplate $t): bool => $t->appliesTo($variant),
        ));
    }

    /**
     * One template of this module.
     *
     * Scoped by module rather than found by id alone: an id in a URL is not a
     * licence to reach another module's wording, the same rule FieldController
     * applies to a field and DocumentTemplateRepository to a .docx.
     */
    public function find(string $moduleKey, int $id): ?EmailTemplate
    {
        $template = $this->entityManager->find(EmailTemplate::class, $id);

        return $template?->getModuleKey() === $moduleKey ? $template : null;
    }

    public function save(EmailTemplate $template): void
    {
        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function remove(EmailTemplate $template): void
    {
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }
}

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

namespace App\Tenant\Repository;

use App\Tenant\Entity\FollowUpNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FollowUpNote>
 *
 * Notes are read through their follow-up (XIV-80).
 *
 * Deliberately thin, and it stays thin: a note has no independent life, exactly
 * as a collection row has none (§5.1). Nothing asks for "the notes about
 * something" — it asks for a follow-up, which brings its thread with it in the
 * order the entity declares. What this class is for is finding one note by id on
 * the way into an edit or a delete, which is what a controller has after a form
 * post and the only reason it exists at all.
 *
 * Resolves through the `tenant` manager, so a note is only ever read from the
 * database of the customer being served (§8.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
class FollowUpNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FollowUpNote::class);
    }
}

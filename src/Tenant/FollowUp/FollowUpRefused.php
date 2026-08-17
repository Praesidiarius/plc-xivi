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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A follow-up the application will not write (XIV-80).
 *
 * The same shape as {@see \App\Tenant\Security\GroupChangeRefused} and
 * {@see \App\Store\StoreInstallRefused}: an English message for the log, whose
 * reader is a developer, and a translatable one for the person who caused it.
 * Two audiences, two sentences.
 *
 * **These are refusals, not access denials, even the ones about permission.**
 * That is a deliberate departure from the rest of §8.4, where a missing grant is
 * a 403 out of a voter, and it is worth saying why: the write path here is a
 * service, and the ordinary caller is a form post that also has to handle "this
 * module has follow-ups switched off" and "that record is gone". One exception
 * type means one catch block and one flash, rather than a controller that
 * handles two of three refusals and lets the third become a 500. The route
 * carrying `#[IsGranted]` is still the first seam and still answers 403 (XIV-82);
 * this is the seam underneath it, which exists because an import, a console
 * command or a future API never passes through the first one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpRefused extends \RuntimeException
{
    /** What to show the person who caused it, in their language (§8.4.2). */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /**
     * The module has follow-ups switched off.
     *
     * Checked on the write path rather than only where the button is drawn,
     * because the toggle is reversible and a page left open across the moment
     * somebody switched it off is an ordinary sequence rather than an attack.
     */
    public static function notEnabled(string $moduleLabel): self
    {
        return self::of(
            sprintf('Module "%s" does not take follow-ups.', $moduleLabel),
            'refusal.follow_up_not_enabled',
            ['%module%' => $moduleLabel],
        );
    }

    /**
     * There is no such record, or it has been deleted.
     *
     * One sentence for both, on purpose: telling somebody which of the two it was
     * is telling them that a record with that id exists, which is exactly what
     * §8.4 refuses to leak when it answers 404 rather than 403 for a record
     * somebody may not see.
     */
    public static function noSuchRecord(): self
    {
        return self::of(
            'That record does not exist, or has been deleted.',
            'refusal.follow_up_no_record',
        );
    }

    /** The actor holds no grant for what they are trying to do. */
    public static function notPermitted(): self
    {
        return self::of(
            'This person may not do that to this module.',
            'refusal.follow_up_not_permitted',
        );
    }

    /**
     * The would-be assignee cannot see the record the follow-up sits on.
     *
     * Its own sentence rather than {@see notPermitted()}, because the person
     * being refused and the person who lacks the grant are two different people,
     * and "you may not do that" would send whoever is holding the form looking at
     * their own permissions.
     */
    public static function assigneeCannotView(string $assigneeLabel): self
    {
        return self::of(
            sprintf('"%s" may not view this record, so it cannot be assigned to them.', $assigneeLabel),
            'refusal.follow_up_assignee_blind',
            ['%assignee%' => $assigneeLabel],
        );
    }

    /** Somebody else's note. Not overridable, by anybody — see FollowUpManager. */
    public static function notYourNote(): self
    {
        return self::of(
            'A note can only be changed by whoever wrote it.',
            'refusal.follow_up_not_your_note',
        );
    }

    /** A note with nothing in it is not a note. */
    public static function emptyNote(): self
    {
        return self::of(
            'A note needs something in it.',
            'refusal.follow_up_empty_note',
        );
    }

    /**
     * It is archived, and an archive that changes is not one (XIV-85).
     *
     * Every mutation refuses on this except reopening, which is the one door out
     * and therefore cannot be behind it. The message says what to do rather than
     * only what went wrong, because the thing that produces this refusal is
     * nearly always a page that was open when somebody marked the item done —
     * and "reopen it first" is the whole of what that reader needs.
     */
    public static function alreadyDone(): self
    {
        return self::of(
            'This follow-up is done. Reopen it before changing anything on it.',
            'refusal.follow_up_already_done',
        );
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters = []): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, 'messages');

        return $refusal;
    }
}

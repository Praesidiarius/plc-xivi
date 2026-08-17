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

namespace App\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Numbering\NumberFormat;

/**
 * What a document number will look like, while somebody is still typing the
 * pattern that decides it (XIV-27).
 *
 * ### Why this is live at all
 *
 * `ORD-{year}-{number:4}` is a small language and every one of its failure modes
 * is quiet. A pattern with no `{number}` numbers nothing — deliberately, at the
 * engine's level, because a blueprint that says nothing about numbering should
 * leave an ordinary text field behind. A width of two keeps sorting correctly
 * until the hundredth document and then stops, silently, on a list somebody
 * reads every day. And adding `{year}` moves to a **different counter**, which
 * starts at 1, so a customer can get `ORD-2026-0001` after `ORD-0087` without
 * having done anything they would describe as resetting a counter.
 *
 * None of those can be explained by validating the input on submit. What answers
 * all three is showing the number the pattern would produce, from the counter it
 * would come out of, as it is typed — which turns a syntax somebody has to learn
 * into something they watch working. That is the whole justification for a
 * component here rather than a text box in the field table.
 *
 * It is possible only because {@see NumberFormat} is **statically** readable:
 * whether a pattern numbers anything and which counter it draws from are both
 * questions about its text, answerable before anything is saved. An expression
 * language would have made this page impossible to write, which is most of why
 * XIV-27 refused one.
 *
 * ### Nothing here writes anything
 *
 * The save is a plain POST to {@see \App\Controller\FieldController}, the same
 * arrangement {@see FollowUps} uses and for a stronger reason: this is
 * administrator-only metadata, guarded by `ROLE_ADMIN` on a route, and a
 * `#[LiveAction]` writing definitions would move that guard somewhere no test
 * that reads routes can see it. What the component owns is the *preview*, which
 * is a read and an arithmetic-free render.
 *
 * The counter is **peeked, never allocated** ({@see NumberAllocator::peek()}).
 * Rendering a preview must not consume a number; an administrator who opens this
 * page, reads it and changes their mind must leave no hole in the books.
 *
 * ### It re-checks who is asking
 *
 * Props are signed rather than secret, and a component is reachable at its own
 * endpoint, so the ROLE_ADMIN check on the page above is not the only door.
 * Asking again costs one attribute check.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('FieldNumbering')]
final class FieldNumbering extends AbstractController
{
    use DefaultActionTrait;

    /** Scalars only: a prop is a signed attribute in the page, and travels as JSON. */
    #[LiveProp]
    public string $module = '';

    #[LiveProp]
    public int $fieldId = 0;

    /**
     * The pattern as it currently reads in the box, mounted from the definition.
     *
     * Writable, because this is the input the whole page is about. Everything
     * below is derived from it and from nothing else, so what is on screen is
     * always an answer about the text in front of the reader rather than about
     * what is stored.
     */
    #[LiveProp(writable: true)]
    public string $pattern = '';

    /**
     * Where the counter should be set to, if anywhere.
     *
     * A string rather than an int, because the empty box is the ordinary case
     * and has to survive the round trip as emptiness: changing a prefix says
     * nothing about where the sequence has got to, and a control pre-filled with
     * the current value would make every save of this page a write to the
     * counter. Empty means "leave it exactly as it is".
     */
    #[LiveProp(writable: true)]
    public string $nextValue = '';

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly NumberAllocator $counters,
    ) {
    }

    public function getField(): FieldDefinition
    {
        $definition = $this->definition();

        foreach ($definition->getFields() as $field) {
            if ($field->getId() === $this->fieldId) {
                return $field;
            }
        }

        throw $this->createNotFoundException(sprintf('No field %d on "%s".', $this->fieldId, $this->module));
    }

    /** The pattern the field is actually saved with, which is what the page is departing from. */
    public function getSaved(): ?NumberFormat
    {
        return NumberFormat::of($this->getField());
    }

    /** What is in the box, if it is usable at all. Null is the state the page explains. */
    public function getFormat(): ?NumberFormat
    {
        return NumberFormat::parse($this->pattern);
    }

    /**
     * The counter the number would come out of: a year, or the empty string for
     * one that runs forever.
     */
    public function getPeriod(): ?string
    {
        return $this->getFormat()?->period($this->now());
    }

    /**
     * Whether saving this would move to a counter other than the one in use.
     *
     * The sentence this drives is the one the ticket asked for by name, and it
     * has to appear **before** anything is saved: a customer adding `{year}` is
     * not told anywhere else that their next document starts at 1 again. It
     * compares periods rather than patterns, so changing a prefix — which
     * changes what numbers look like and not where they come from — says
     * nothing.
     */
    public function isChangingCounter(): bool
    {
        $period = $this->getPeriod();

        return $period !== null && $period !== $this->getSaved()?->period($this->now());
    }

    /**
     * What that counter will give out next, as it stands right now.
     *
     * For a counter that does not exist yet this is 1, which is the honest
     * answer and the surprising one: it is what makes "you will get 0001 again"
     * visible on the page instead of on the first document after the change.
     */
    public function getCounterAt(): int
    {
        $period = $this->getPeriod();

        if ($period === null) {
            return NumberAllocator::FIRST;
        }

        return $this->counters->peek($this->definition()->getKey(), $this->getField()->getKey(), $period);
    }

    /**
     * The number the next record will actually be called.
     *
     * Rendered from the value the counter is at, or from what has been typed
     * into the counter box when that is higher — so somebody migrating from
     * another system types 1043 and watches `INV-2026-1043` appear, which is the
     * only way to be sure the width they chose is wide enough for the numbers
     * they already have.
     *
     * Null when the pattern would number nothing, and the template says so in
     * words rather than showing an empty box where a number was.
     */
    public function getPreview(): ?string
    {
        return $this->getFormat()?->render($this->getWanted() ?? $this->getCounterAt(), $this->now());
    }

    /**
     * A counter value that would be refused, said before the save says it.
     *
     * The refusal itself lives in {@see NumberAllocator::restartAt()} and is
     * enforced there whatever this returns — this is the courtesy, not the
     * control. A duplicate invoice number is a legal problem, so the rule is one
     * atomic statement in the database and this page is only the part that keeps
     * somebody from meeting it by surprise.
     */
    public function isCounterGoingBack(): bool
    {
        $wanted = $this->getWanted();

        return $wanted !== null && $wanted < $this->getCounterAt();
    }

    /** What was typed into the counter box, when it is a number at all. */
    public function getWanted(): ?int
    {
        return ctype_digit($this->nextValue) ? (int) $this->nextValue : null;
    }

    /**
     * The module, having checked that whoever is asking may be here.
     *
     * Administrator, exactly as the page is: changing what a module *is* is not
     * one of the things you do *to* its records, so it is not one of its
     * permissions (§5.4).
     */
    private function definition(): ModuleDefinition
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->metadata->get($this->module);
    }

    /**
     * Today, which is the year every preview on this page is drawn in.
     *
     * The same clock {@see \Xivi\Core\Numbering\AssignsNumbers} allocates on, and
     * deliberately not a date from the record: a number belongs to the year it
     * was given out in, because backdating a document must not reach into a book
     * that is closed (§5.10).
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

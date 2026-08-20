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

namespace Xivi\Core\Field;

/**
 * A field type holding a number, whose fields may say how small and how large
 * (XIV-163).
 *
 * The **ninth** capability of this kind, and the second of the two that
 * [XIV-163] promoted out of the editor's own hand-written list of settings. See
 * {@see LimitsItsLength} for the argument, which is one argument about three
 * settings; the short version is that a control drawn beside a field it means
 * nothing on is a control that does nothing, and that a list of settings kept
 * outside the option-to-capability declarations is a second place for a type and
 * its controls to drift apart.
 *
 * **One capability and two options, which is new on this list and deliberate.**
 * Every capability before this one answered a single question. A smallest and a
 * largest are two options and one idea: a type that can be bounded below can be
 * bounded above, no type has ever wanted one without the other, and splitting
 * them would produce two interfaces that are always declared together, which is
 * how a reader learns that a distinction means nothing. The editor's per-type
 * list keys both options to this one interface, which it was always able to do
 * and had never had a reason to.
 *
 * **What declaring it means.** Values are numeric, and a field of this type may
 * carry a lower or an upper bound that the type's validation reads and that its
 * demo data stays inside. Either may be absent, which is the ordinary state of
 * almost every such field and is why this is not a {@see NeedsAnAnswer}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface BoundsItsValues extends FieldType
{
    /** The option a field of this type carries its lower bound in. */
    public const string MIN = 'min';

    /** And its upper bound. */
    public const string MAX = 'max';
}

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

namespace Xivi\Voucher\Code;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\ValueDeriver;

/**
 * Leaving the code empty is how somebody asks for one (XIV-103).
 *
 * ### The affordance, and why it is an absence rather than a button
 *
 * The ticket asks for two ways to get a code: the customer types `GIVE-10`, or
 * they ask for a random one. This is the second, and it is deliberately *not* a
 * "generate" button beside the field.
 *
 * The precedent is §5.10, which is the closest thing in this codebase to the
 * same problem and answers it the same way: a document number is filled in on
 * the first save, from a rule the module declared, into a field nobody typed
 * into. Nobody presses anything to get `ORD-2026-0001`. A voucher code is that
 * question with one difference — the customer may also type their own — so the
 * rule is `AssignsNumbers`' rule with the field left editable: **fill it if it is
 * empty, never touch it if it is not.**
 *
 * The button was considered and costs more than it is worth *here*. It would
 * need a capability interface in core, a `LiveAction` on the application's
 * record form and a form theme block to render the control — three changes to
 * shared surfaces, none of which is about vouchers, to replace a rule somebody
 * can be told in one sentence of help text on the field itself. If a second
 * module ever wants a generated value the capability becomes worth building and
 * this class is what it would be built from.
 *
 * What is genuinely lost is that the code is not visible until after the save.
 * That is the same trade §5.10 makes for document numbers, and the same
 * mitigation applies: the code is the record's title, so the very next page
 * somebody sees is headed with it.
 *
 * ### Not {@see \Xivi\Core\Record\SafeToPreview}, and this one is not a judgement
 * call
 *
 * A preview runs the derivers over whatever is currently typed, at typing speed.
 * A generator run there would hand back a *different* code on every keystroke,
 * so the field somebody is looking at would spin like a slot machine and the
 * value they eventually saved would be unrelated to the one they read. The
 * seam's own docblock says a deriver qualifies only if it is a pure function of
 * the values it is handed, and this one is pure of everything except the
 * randomness that is its entire purpose.
 *
 * ### No collision check, and the number that says why not
 *
 * There is deliberately no "generate, look, generate again" loop. The alphabet
 * is thirty characters in eight positions, so a single generated code collides
 * with an existing one in a tenant holding ten thousand vouchers about once in
 * every sixty-six million saves. A retry would be code that is never executed,
 * cannot honestly be tested, and would still need the index behind it — because
 * a check followed by a write is the read-then-write race this whole ticket is
 * about.
 *
 * So the index behind `unique` is the whole mechanism ([XIV-109]). If that
 * one-in-sixty-six-million save ever happens, it comes back as
 * {@see \Xivi\Core\Record\DuplicateValue} on the `code` field — a confusing
 * message about a field the person left blank, whose remedy is to press Save
 * again. Confusing and correct beats plausible and wrong.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AssignsVoucherCodes implements ValueDeriver
{
    /**
     * Any module with a code field, not "the module whose key is voucher".
     *
     * §6.1 is why: from the moment a module is installed the customer's own
     * definitions are the truth, so the question worth asking is what the shape
     * in front of us actually holds. A customer who added a second code field, or
     * who put one on a module of their own making, gets the same behaviour — and
     * a customer who deleted this module's code field gets nothing done to them,
     * which is the half that would otherwise be a crash.
     */
    public function supports(ModuleDefinition $module): bool
    {
        return $this->codeFieldsOf($module) !== [];
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        foreach ($this->codeFieldsOf($module) as $key) {
            $value = $derivation->fields[$key] ?? null;

            // Already has one: leave it entirely alone. This is what makes a
            // code stable across every later save of the same voucher, and it is
            // the same sentence §5.10 uses about a document number — the value is
            // assigned once and never restated.
            //
            // The empty string is checked as well as null because the derivers
            // run before the values reach `toStorage()` on the write path, so a
            // form that submitted a blank box still has one here.
            if ($value !== null && $value !== '') {
                continue;
            }

            $derivation->fields[$key] = VoucherCode::generate();
        }
    }

    /**
     * The keys of this shape's code fields, in definition order.
     *
     * @return list<string>
     */
    private function codeFieldsOf(ModuleDefinition $module): array
    {
        $keys = [];

        foreach ($module->getFields() as $field) {
            if ($field->getType() === VoucherCodeFieldType::KEY) {
                $keys[] = $field->getKey();
            }
        }

        return $keys;
    }
}

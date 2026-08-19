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

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

/**
 * A textarea, a short note about the grammar, and a preview underneath
 * (XIV-131).
 *
 * **A plain textarea, deliberately.** The obvious alternative is a toolbar with
 * a bold button on it, and the obvious way to get one is a JavaScript editor —
 * which is a dependency, and a dependency this application has said out loud it
 * will not fetch from anybody's content delivery network (§8.3). XIV-33 settled
 * the front end on Live Components precisely so that the interactive parts of
 * this system are server-rendered, and a rich-text editor is the one shape of
 * widget that cannot be. So: the control is the text, and the honesty is the
 * preview. A toolbar is a later question, if anybody asks for one.
 *
 * **The preview costs nothing extra and that is not a coincidence.** The record
 * form is a Live Component with `data-model` on the form element, because
 * XIV-32's totals had to follow somebody typing into a quantity box. Every
 * keystroke already round-trips and re-renders the form; a preview block
 * rendered inside the widget therefore updates with the typing without a line of
 * JavaScript being written for it, and without anything being fetched. Outside a
 * live component — which is nowhere at the moment — it simply shows whatever was
 * loaded, which is a preview of the saved value and still true.
 *
 * There is no class of its own for what this *does*: it is a `textarea` in every
 * respect that reaches the browser or the server, and only its rendering
 * differs. `getParent()` says exactly that, and the block prefix is what the
 * form theme hangs off. A distinct prefix rather than a `variant` option because
 * a theme block keyed on a variable is a theme block every other textarea in the
 * application pays to evaluate.
 *
 * @extends AbstractType<string|null>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MarkdownType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }

    /**
     * Prefixed, because a block called `markdown_widget` in a globally
     * registered theme is a name this application does not own and could collide
     * with one a bundle ships later.
     */
    public function getBlockPrefix(): string
    {
        return 'xivi_markdown';
    }
}

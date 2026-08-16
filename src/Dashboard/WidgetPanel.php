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

namespace App\Dashboard;

/**
 * A widget's answer: which template draws it, what that template is given, and
 * what to call the box it sits in (XIV-81).
 *
 * **A template name and an array rather than a rendered string**, which is the
 * one decision in this class. A widget that returned HTML would be a service
 * building markup, and every one of them would then need the translator, the
 * router and the escaper injected to do it — the reasons Twig exists, rebuilt
 * once per widget. Handing back a name and its data keeps the rendering in the
 * templating layer and keeps a widget a thing that answers questions.
 *
 * **The heading is a key rather than a sentence**, for the reason
 * {@see \App\Tenant\Entity\FollowUpPriority::labelKey()} spells out one level
 * down: a value object holding an English string is a value object that has
 * quietly become untranslatable. The dashboard renders it through the translator,
 * so a widget never has to hold one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class WidgetPanel
{
    /**
     * @param string               $template the Twig template that draws this panel
     * @param array<string, mixed> $data     what that template is rendered with —
     *                                       the panel's whole world, since a
     *                                       widget's template is included rather
     *                                       than extended and inherits nothing but
     *                                       the globals every page has
     * @param string|null          $titleKey a `messages` key for the heading, or
     *                                       null for a panel that names itself.
     *                                       The module tiles are the null case: a
     *                                       grid of labelled cards under the
     *                                       company name needs no second label
     *                                       saying "modules"
     */
    public function __construct(
        public string $template,
        public array $data = [],
        public ?string $titleKey = null,
    ) {
    }
}

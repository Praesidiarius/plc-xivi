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

namespace App\Twig;

use App\Tenant\Security\ModuleRecord;
use App\Tenant\Security\PermissionVerbs;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * `can('edit', module, record)` in a template (§7.5).
 *
 * One function for both permission axes (§8.4.3): `can('browse', chrome.store)`
 * is the same question asked of the store's vocabulary rather than a module's,
 * and a template should not have to know which enum a word came out of. Which
 * voter answers is the security layer's business, decided by the subject.
 *
 * Twig's own `is_granted` can ask about a module, whose key is a plain string,
 * but not about one record: that needs a ModuleRecord pairing the row with the
 * shape it came from, and building objects is not something a template should
 * be doing. So the pairing happens here and the template asks a question.
 *
 * **This hides controls; it does not protect anything.** Every route decides for
 * itself, and the lists go through a WHERE clause — a person who types the URL
 * of a button they cannot see is refused by the same checks as everybody else.
 * Hiding it is a courtesy: a screen full of buttons that answer "no" is a worse
 * way to learn what your job is than a screen without them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionExtension extends AbstractExtension
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('can', $this->can(...))];
    }

    /**
     * @param ModuleDefinition|string $module the definition, its key, or the key
     *                                        of something that is not a module at
     *                                        all — an area, or the store
     * @param Record|null             $record when asking about one row rather
     *                                        than about the module as a whole
     */
    public function can(string $action, ModuleDefinition|string $module, ?Record $record = null): bool
    {
        // An unknown action is false rather than an exception. A template typo
        // should hide a button, not turn a page into a 500 — and the routes
        // behind those buttons refuse on their own account anyway.
        if (PermissionVerbs::tryFrom($action) === null) {
            return false;
        }

        if ($record === null) {
            return $this->security->isGranted(
                $action,
                $module instanceof ModuleDefinition ? $module->getKey() : $module,
            );
        }

        if (!$module instanceof ModuleDefinition) {
            throw new \InvalidArgumentException('Asking about a record needs the module definition, not just its key.');
        }

        return $this->security->isGranted($action, new ModuleRecord($module, $record));
    }
}

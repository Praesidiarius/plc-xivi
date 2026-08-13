<?php

declare(strict_types=1);

namespace Xivi\Contact;

use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * The contact module, in full.
 *
 * The whole module is a declaration — no entity, no repository, no form class,
 * no controller. Everything a contact does comes from the engine reading these
 * definitions, which is exactly the claim §1 makes and the reason this module
 * exists: if the engine needed help here, the engine would be wrong.
 *
 * A customer's copy starts as this and then diverges. Fields added by them are
 * ordinary rows next to these; the only difference is that these are marked as
 * the module's own.
 */
final class ContactModule implements ModuleProvider
{
    public const string KEY = 'contact';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'Contacts',
            table: 'contact',
            fields: [
                new FieldBlueprint(
                    key: 'first_name',
                    label: 'First name',
                    type: 'text',
                    required: true,
                    filterable: true,
                    position: 10,
                    options: ['max_length' => 120],
                ),
                new FieldBlueprint(
                    key: 'last_name',
                    label: 'Last name',
                    type: 'text',
                    required: true,
                    filterable: true,
                    position: 20,
                    options: ['max_length' => 120],
                ),
                new FieldBlueprint(
                    key: 'email',
                    label: 'Email',
                    type: 'email',
                    // Not required: plenty of real contacts are a name and a phone
                    // number. Unique, so the ones that have an address have their own.
                    unique: true,
                    filterable: true,
                    position: 30,
                ),
                new FieldBlueprint(
                    key: 'phone',
                    label: 'Phone',
                    type: 'text',
                    position: 40,
                    options: ['max_length' => 40],
                ),
                new FieldBlueprint(
                    key: 'birthday',
                    label: 'Birthday',
                    type: 'date',
                    position: 50,
                ),
            ],
        );
    }
}

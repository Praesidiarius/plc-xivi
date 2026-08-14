<?php

declare(strict_types=1);

namespace Xivi\Contact;

use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModulePreset;
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
                    // Part of what a contact is called (§5.4).
                    title: true,
                    label: 'First name',
                    type: 'text',
                    required: true,
                    filterable: true,
                    position: 10,
                    options: ['max_length' => 120],
                ),
                new FieldBlueprint(
                    key: 'last_name',
                    title: true,
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
            collections: [
                // A contact has as many addresses as they have, which is the
                // first thing about a real CRM that a single row cannot hold.
                // Still nothing but a declaration: these fields are described
                // exactly like the ones above and stored by the same code.
                new CollectionBlueprint(
                    key: 'addresses',
                    label: 'Addresses',
                    table: 'contact_address',
                    fields: [
                        new FieldBlueprint(
                            key: 'label',
                            label: 'Label',
                            type: 'text',
                            position: 10,
                            options: ['max_length' => 60],
                        ),
                        new FieldBlueprint(
                            key: 'street',
                            label: 'Street',
                            type: 'text',
                            required: true,
                            position: 20,
                            options: ['max_length' => 180],
                        ),
                        new FieldBlueprint(
                            key: 'postal_code',
                            label: 'Postal code',
                            type: 'text',
                            position: 30,
                            options: ['max_length' => 20],
                        ),
                        new FieldBlueprint(
                            key: 'city',
                            label: 'City',
                            type: 'text',
                            // Filterable because "contacts in this city" is the
                            // question addresses exist to answer, and it is the
                            // one §7.3 has to compile to a semi-join rather than
                            // a join: a contact with two addresses here is still
                            // one contact.
                            filterable: true,
                            position: 40,
                            options: ['max_length' => 120],
                        ),
                        new FieldBlueprint(
                            key: 'country',
                            label: 'Country',
                            type: 'text',
                            position: 50,
                            options: ['max_length' => 120],
                        ),
                    ],
                    position: 10,
                ),
            ],
            // Named field sets a customer can be installed with (§6.1). They pick
            // from the fields above rather than redeclaring them, so there is one
            // description of what a contact can hold and a couple of answers to
            // how much of it you want.
            presets: [
                new ModulePreset(
                    key: 'basic',
                    label: 'Basic',
                    description: 'A name and how to reach them.',
                    fields: ['first_name', 'last_name', 'email', 'phone'],
                ),
                new ModulePreset(
                    key: 'extended',
                    label: 'Extended',
                    description: 'Everything the module knows about a contact.',
                    fields: ['first_name', 'last_name', 'email', 'phone', 'birthday'],
                ),
            ],
            // Installing "the contact module" gives you the contact module.
            // Choosing less is deliberate, and reversible: a field left out can be
            // added back in the editor (§5.4).
            defaultPreset: 'extended',
        );
    }
}

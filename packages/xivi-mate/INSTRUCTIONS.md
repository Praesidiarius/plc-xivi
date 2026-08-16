# Xivi project tools

Xivi is **metadata-driven**: a module's blueprint in `packages/*/src` is only the
shape a tenant was *installed* with. Once installed, that tenant's own definitions
are the truth and nothing retro-fits a blueprint change into them
(`docs/architecture.md` §6.1). Two customers running the same module can have
different fields.

So **do not read `ContactModule.php` and assume it describes a tenant.** Ask.

| Question | Tool |
| --- | --- |
| What fields does this tenant's module actually have? | `xivi-tenant-shapes` |
| Which tenants exist, and is each one's schema current? | `xivi-tenants` |
| What modules does this build ship, and what state is each in? | `xivi-modules` |

Everything these answer is also reachable from the console — `tenant:inspect`,
`tenant:list`, `module:list` — so if this server is not running, use the shell.
Nothing here is tool-only.

## Reading a shape

`xivi-tenant-shapes` returns each field's `key`, `type`, `options`, `variants` and
its flags. Three of them are easy to get wrong:

- **`derived: true`** — computed, never typed. Writing to it looks like it worked
  and is not persisted as you expect.
- **`system: true`** — installed by the module itself; the customer may not remove
  it.
- **`variants`** — an empty list means the field applies to every variant. A
  non-empty one scopes it, so a `person` field is absent from a `company` record.

`options` holds the per-type settings: a choice field's choices, a reference
field's target module, a decimal's scale.

## Changing tenants

`xivi-tenant-reset` and `xivi-tenant-deprovision` **destroy data**, and their
results say what was destroyed. Before calling either:

- Run `xivi-tenants` and read it. A developer's dev tenants are their working
  state — never reset or deprovision one you were not asked to.
- `xivi-tenant-deprovision` needs `force=true` and is irreversible.

Provisioning, installing a module, migrating and creating users are deliberately
*not* tools. Those commands already describe themselves — `bin/console list tenant`
— and wrapping them would be a second surface to keep in step for no gain.

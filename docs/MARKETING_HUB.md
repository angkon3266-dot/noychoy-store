# Marketing & Commerce Hub — modular architecture (Phase 1 foundation)

A plugin-style architecture where every Meta capability is an **independent
module**. The platform integrates with Meta (and, later, Google/TikTok/…) but is
**not** an Ads Manager — ads are always created/managed in Meta. This is a
**deployment-ready** design (one business per install); the schema is
tenant-ready (`meta_connection_id` scoping) so a SaaS version is possible later
without a rewrite.

## What's in this foundation

- **Provider-agnostic contracts** (`app/Support/Social/Contracts/`):
  `SocialConnectionManager`, `CommerceCatalog`, `SocialPublisher`,
  `InboxProvider`, `InsightsProvider`, `AiAssistant`. Modules depend on these —
  never on a platform's concrete classes. This is the multi-platform seam.
- **Module framework** (`app/Support/Modules/`): `ModuleManifest`,
  `ModuleRegistry` (also the **Permission Registry**), and a base
  `ModuleServiceProvider`. Modules are listed in `config/modules.php` and booted
  by `ModulesServiceProvider`.
- **Token Manager** (`app/Modules/Meta/Services/MetaTokenManager`, tables
  `meta_connections` + `meta_assets`): encrypted token, granted scopes, business
  and discovered assets (catalogs/pages/IG/pixels/ad-accounts), health.
- **Modular OAuth** (`MetaOAuthService` + `MetaConnectionController`): a module is
  authorized only when opened and its scopes are missing; we request the union of
  already-granted + the module's scopes (Meta only prompts for new ones — never
  ask twice). Asset permissions come via a Facebook **Login-for-Business**
  config (`config_id`) per module, not raw scopes.
- **Six modules** (`app/Modules/`): **Commerce** (functional — reuses the
  existing catalog engine via the `CommerceCatalog` contract), **Publishing**,
  **Inbox**, **Analytics** (read-only), **AI**, **Automation** (registered with
  their permission scopes; UIs are the next phases).
- **Registry-driven hub** (`Admin → Marketing`): the Meta Connection card +
  one card per module, generated from the registry. Adding a module makes it
  appear automatically.

## Adding a module (no OAuth/other-module changes needed)

1. Create `app/Modules/<Name>/<Name>ServiceProvider.php` extending
   `ModuleServiceProvider`; return a `GenericModuleManifest` declaring its key,
   name, `scopes`, `config_id`, `permissions`, `route`, `available`.
2. Bind any contracts it implements in `bindings()`.
3. Add the provider to `config/modules.php`.

The Permission Registry, modular OAuth, and hub UI pick it up with no other edits.

## Adding a platform (Google/TikTok/…)

Implement the relevant contracts (`SocialConnectionManager`, `CommerceCatalog`,
`SocialPublisher`, …) for the new provider and bind them. Modules code against
the contracts, so no module changes are required. `meta_connections.provider`
already namespaces connections per platform.

## Env

```dotenv
META_APP_ID=              # vendor Meta App
META_APP_SECRET=
META_LOGIN_CONFIG_ID=            # Commerce Login-for-Business config
META_LOGIN_CONFIG_ID_PUBLISHING= # (later modules — optional)
META_LOGIN_CONFIG_ID_INBOX=
META_LOGIN_CONFIG_ID_ANALYTICS=
```

App ID/Secret/Config ID are also editable in **System Config → Meta** (no `.env`
required). Whitelist the redirect URI `…/admin/meta/connection/callback` in your
Meta App.

## Migration safety

Additive only. `meta_connections`/`meta_assets`/`meta_module_states` are new; a
one-time migration backfills the existing `Setting('meta_integration')`
connection. The current Commerce sync (Meta Integration dashboard) keeps working
unchanged; the new Connection hub runs alongside it.

# Meta Embedded Signup (FBE) — migration roadmap

*Researched 2026-08-03 against Meta's live documentation and this codebase. Plan
only — nothing implemented.*

**Goal:** Shopify-style onboarding. Merchant clicks *Connect with Facebook*,
picks their Business, and catalog sync + Conversions API work from then on with
no token pasting and no periodic reconnection.

---

## 0. Read this first — the two things that decide the timeline

### 0.1 FBE is not self-serve. It is allow-listed.

Meta's own Get Started page states plainly:

> *"You must be added to the allow list to use this framework."* — contact your
> Meta representative for approval, and obtain a Meta-assigned platform name.

There is also a final **"Submit for Meta Business Extension Integration Review"**
step before you can launch publicly. So FBE is gated twice: an allow-list at the
start and an integration review at the end, both mediated by Meta.

**Consequence:** you cannot put FBE on a delivery schedule you control. Treat the
allow-list application as a long-lead procurement item started on day one, and
**do not block the rest of the work on it**. §10 phases the plan accordingly.

### 0.2 The biggest blocker is ours, not Meta's — the app is single-tenant

Two places hard-code "there is exactly one merchant":

| Where | Code | Meaning |
|---|---|---|
| `MetaTokenManager.php:27` | `MetaConnection::firstOrCreate(['provider' => 'meta'])` | One connection row, ever |
| `MetaSettings.php:21,97` | `Setting::get('meta_integration')` | One settings blob for the install |

Everything else — the Graph client, the mapper, the queue jobs, the sync-state
tracking — reads its credentials through `MetaSettings`, so **the singleton is
the single point that has to change**. That refactor is required for a
multi-tenant SaaS whether or not you ever get FBE.

**This is the fork you should decide before anything else:**

| Model | What it means | Verdict |
|---|---|---|
| **A. One install per merchant** (today) | Each merchant gets their own cPanel install + DB. Your Meta App is shared; each install holds one connection. | **Already works.** No tenancy refactor. Scales badly past ~20–30 merchants operationally (deploys, migrations, monitoring ×N). |
| **B. True multi-tenant** | One install, `merchant_id` on every Meta row. | The real SaaS answer, and the bulk of the engineering below. |

Model A is a legitimate way to reach your first ~20 paying merchants and
**FBE works fine with it** — the App is yours, the connection is theirs. I'd
recommend selling on Model A while building Model B, rather than stopping sales
for a rewrite.

---

## 1. Meta products and approvals required

| Item | Needed for | Who grants it | Lead time |
|---|---|---|---|
| **Meta App** (Business type) | Everything | Self-serve | Immediate |
| **Business Verification** | Any advanced-access permission | Meta, document review | Days–weeks |
| **App Review** — `business_management`, `catalog_management` | Managing *other people's* catalogs | Meta, manual review + screencast | Weeks, often iterative |
| **App Review** — `ads_management` | CAPI on a merchant's pixel via a user token | Meta | Weeks |
| **Business Login configuration** (`config_id`) | The connect flow | Self-serve once verified | Immediate |
| **FBE allow-list** | Embedded Signup itself | **Meta representative** | Unknown — relationship-dependent |
| **MBE Integration Review** | Public FBE launch | Meta | Weeks |

You already hold the first and the Business Login concept (`META_LOGIN_CONFIG_ID`
is wired in `config/meta.php`). Everything else is new.

---

## 2. App Review permissions, and why each is needed

| Permission | Why you need it | Without it |
|---|---|---|
| `business_management` | Read the merchant's Business, and — critically — **install your app into their Business and mint a System User token**. This is what removes the 60-day reconnect. | No system user tokens; stuck with expiring user tokens |
| `catalog_management` | Create/read/update/delete the merchant's product catalog. Your entire sync depends on it. | Catalog sync only works for your own app's admins/testers |
| `ads_management` | Lets a **user** token send Conversions API events for the merchant's pixel. | CAPI needs a separately generated Events Manager token (what you do today) |
| `pages_show_list`, `pages_read_engagement` | Only if you later add Page/Instagram features | — |

**The access-level rule that matters:** at **standard access** a permission only
works for people with a role on your app (admins, developers, testers). To touch
*a customer's* assets you need **advanced access**, and Meta requires **Business
Verification for every advanced-access request**. So Business Verification is not
optional for a SaaS — it is the gate on the whole model.

**Practical note on `ads_management`:** it is the hardest of the three to get
approved and is only needed so a *user* token can send CAPI. If you go the System
User route (§7), the system user token carries its own permissions and you may be
able to defer `ads_management` entirely. Worth confirming with Meta before
spending review cycles on it.

---

## 3. Business Verification requirements

Mandatory for advanced access since **1 February 2023**. Expect to supply:

- Legal business name, address, phone, website — matching official records
- Business registration / incorporation documents (a Bangladesh trade licence
  and TIN typically satisfy this)
- Proof of your association with the business
- A verifiable phone/email on the business domain

**Plan for iteration.** Rejections are common and usually about a mismatch
between the documents and the Business Manager profile. Start this **first** —
it blocks App Review, which blocks everything else.

---

## 4. Embedded Signup — the merchant's experience

**Today (what you'd be replacing):**

1. Admin → Marketing → Meta, set a security password
2. Choose Development or Production mode
3. Development: leave the app, open Business Settings, create a System User,
   assign the catalog, generate a token, copy it back
4. Separately: open Events Manager, generate a CAPI token, paste that too
5. Pick a business and catalog
6. **Reconnect every ~60 days** (see §7)

**After FBE:**

1. Admin → Marketing → Meta → **Connect with Facebook**
2. Meta's own hosted dialog opens. The merchant signs in and either picks or
   creates: Business Manager → Page → Pixel → Catalog → Ad Account
3. Dialog closes. Your callback receives the asset IDs and an access token
4. You exchange that for a **System User token**, store it, and start syncing
5. Done. No pasting, no second token, no reconnection

Steps 2 and 3 are Meta's UI, not yours — that is the whole point, and it is why
the flow is gated behind their review.

---

## 5. Backend changes in Laravel

### 5.1 Credential resolution — the core change

Today every consumer takes `MetaSettings` by constructor injection, and
`MetaSettings` is implicitly "the one for this install":

```php
class MetaGraphClient {
    public function __construct(private readonly MetaSettings $settings) {}
}
```

**One genuinely lucky detail:** `MetaGraphClient::request()` already accepts an
optional per-call token override (`request($method, $path, $params, ?string
$token = null)`). The transport layer can already talk to multiple merchants —
it just defaults to the singleton. That materially reduces the refactor.

**Target:** a `MetaContext` (or `MetaTenant`) resolved per request/job, carrying
the merchant's connection, and passed explicitly into the services rather than
resolved from a global. Queue jobs must serialise the **merchant id**, never the
token.

### 5.2 New components

| Component | Purpose |
|---|---|
| `MetaEmbeddedSignupController` | Launch the FBE dialog, handle the callback |
| `MetaSystemUserProvisioner` | Install the app into the merchant Business, mint the system user token |
| `MetaTokenRefresher` (scheduled) | Refresh tokens before expiry — see §7 |
| `MetaAssetSyncService` | Persist the returned pixel/catalog/page/ad-account IDs |
| `MetaWebhookController` (extend) | Handle FBE install/uninstall + asset-change events |
| `MetaContext` | Per-merchant credential resolution |

### 5.3 Components that must become tenant-aware

`MetaCatalogService`, `MetaTrackingService`, `MetaStats`, `MetaDiagnostics`, all
five `Jobs/Meta/*`, both observers, and every `Admin/Meta*Controller`.

### 5.4 Things to delete

The **secondary security password wall** (`MetaSecurityGate`, `MetaSecurityController`,
`meta_access_logs`) exists because a single install's Meta credentials are shared
by whoever can reach the admin. In a multi-tenant SaaS each merchant only ever
sees their own connection, and a second password on your own product is friction
that will cost you signups. Remove it in Model B.

---

## 6. Database / schema changes

### 6.1 Unify the two credential stores — do this first

You currently have **two parallel systems** that both claim to own the Meta
connection:

- `MetaSettings` → a `settings` row keyed `meta_integration` (the catalog module)
- `MetaConnection` + `MetaAsset` (the newer modular system)

They already disagree in places (`MetaDebug` reads one and falls back to the
other). **Do not carry both into multi-tenancy** — pick `meta_connections` as the
survivor and migrate `MetaSettings` onto it behind its existing interface.

### 6.2 Proposed schema

```
merchants                     (new — Model B only)
  id, name, domain, plan, status, timestamps

meta_connections              (exists — add tenancy + token fields)
+ merchant_id        FK, indexed
+ system_user_id     string,  nullable
+ token_type         enum('user','system_user')
+ external_business_id  string   -- YOUR id for the merchant, sent to FBE
+ fbe_installed_at   timestamp, nullable
  unique(merchant_id, provider)          -- replaces firstOrCreate('provider')

meta_assets                   (exists — reachable via connection, no change)

meta_sync_states              (exists — add merchant_id, index (merchant_id, product_id))
meta_sync_logs                (exists — add merchant_id, index (merchant_id, created_at))

meta_token_events             (new — audit the refresh loop)
  id, merchant_id, event('issued','refreshed','failed','revoked'),
  detail, created_at
```

`external_business_id` is worth calling out: it is **your** identifier for the
merchant, handed to Meta at install time and echoed back on webhooks. It is how
you map an inbound Meta event to a row in your database, so it must be stable and
unique per merchant from day one.

### 6.3 Migration path from today

Every existing install has exactly one connection. The migration creates one
`merchants` row, stamps `merchant_id` on the existing rows, and folds the
`meta_integration` settings blob into `meta_connections`. Low risk — it is a
one-row backfill per install.

---

## 7. Token lifecycle — the part that actually fixes your problem

### Today

- OAuth returns a short-lived token, exchanged once for a **long-lived user token
  (~60 days)** at `MetaOAuthController.php:137`
- The expiry is stored and displayed, and **nothing refreshes it**
- `meta_connections.refresh_token` exists but is never written — Meta does not
  issue refresh tokens
- **Merchants must reconnect every ~60 days.** That is our implementation, not a
  Meta limitation

### Target

Meta documents installing your app into a client's Business and generating a
**System User access token** on their behalf, and states that expiring system
user tokens **can be refreshed via `oauth/access_token` before they expire,
extending validity another 60 days** — with no human involved.

```
FBE callback → user token
     ↓  POST /{business-id}/access_token   (needs business_management)
System User token  ──────────────► stored per merchant
     ↓
Scheduled refresher, daily:
  for each connection expiring within 14 days:
      refresh → on success: store new expiry, log 'refreshed'
              → on failure: mark needs_reconnect, alert the merchant ONCE
```

**Design rules learned the hard way on this codebase:**

- Compare instants against a threshold (`lte`, `gt`) — never `diffInDays`. Carbon
  3's diffs are signed and we shipped four inverted comparisons because of it
  (see `WORK-LOG.md` §6)
- Refresh at **14 days remaining**, not 1 — leaves room for a failed run
- One alert per merchant per incident, not per attempt
- Log every issue/refresh/failure to `meta_token_events`; when a merchant says
  "it stopped working", that table is the answer

**Non-expiring system user tokens exist but Meta's docs discourage them**
("expiring tokens are a security best practice"). Build the refresher regardless —
it is the same work either way and you are not exposed if the policy tightens.

---

## 8. Catalog Sync and CAPI after migration

### Catalog sync — largely unchanged, and already correct

Your sync uses `items_batch` (`MetaCatalogService.php:198,291,350`). Meta's FBE
catalog guide names the **Batch API as "recommended for most ecommerce
businesses"** with 100+ items and frequent updates. **You already built the
recommended integration.**

What changes: the catalog ID and token come from the merchant's connection
instead of the install's settings. The mapper, batching, retry/backoff, sync
states and logs are all untouched.

One addition worth making: FBE can hand you a catalog the merchant *already*
owns, so the flow must handle "connect to an existing catalog with items in it"
without wiping or duplicating — your `retailer_id` scheme (`prod-{id}`) makes
that safe, but it needs a reconciliation pass on first connect.

### CAPI — simplified, and the second token disappears

`MetaSettings::capiToken()` (line 175) **already falls back to the main token**
when no dedicated CAPI token is set. So CAPI on a single credential is not a new
feature — it is already how the code behaves.

After migration: the system user token serves both catalog and CAPI, the pixel ID
arrives from FBE, and the "paste a CAPI token" field becomes a **fallback for
merchants who haven't completed FBE** rather than a setup step.

**Do not delete it.** Until App Review lands you will have merchants who can only
work via a manually generated Events Manager token — Meta's own docs call that
the *recommended* route precisely because it needs no App Review.

---

## 9. Reuse vs redesign

**Roughly 5,800 lines of Meta code. Most of it survives.**

| Component | Verdict | Why |
|---|---|---|
| `MetaProductMapper` | ✅ **Reuse as-is** | Pure Product → catalog-item mapping. No credentials |
| `MetaCatalogService` | ✅ **Reuse**, inject context | Batch logic already correct |
| `MetaGraphClient` | ✅ **Reuse**, minor change | Already accepts a per-call token |
| `MetaApiException` | ✅ Reuse | Error categorisation is provider-level |
| `Jobs/Meta/*` | 🟡 **Adapt** | Serialise `merchant_id`; resolve credentials at run time |
| Observers | 🟡 Adapt | Must resolve the owning merchant |
| `MetaTrackingService` | 🟡 Adapt | Same shape, per-merchant pixel/token |
| `MetaStats`, `MetaDiagnostics` | 🟡 Adapt | Scope every query by merchant |
| `MetaSettings` | 🔴 **Replace** | The singleton *is* the blocker |
| `MetaTokenManager` | 🔴 **Rewrite** | `firstOrCreate(['provider'])` assumes one row; needs refresh logic |
| `MetaOAuthController` | 🔴 **Replace** | FBE flow supersedes it |
| `MetaSecurityGate` + password wall | 🔴 **Delete** (Model B) | Wrong concept for multi-tenant |

**Verdict: adapt, don't rewrite.** The domain logic — mapping, batching, retry,
sync-state tracking, dedup, logging — is the expensive part and it is sound. What
changes is *where credentials come from*, which is one seam.

---

## 10. Complexity and phasing

**Overall: medium-high.** Not algorithmically hard; the cost is breadth (every
Meta touchpoint gains a tenant dimension) and the fact that two of the four
phases are gated on Meta's timeline, not yours.

### Phase 0 — Paperwork (start today, runs in background)
Business Verification → App Review for `business_management` + `catalog_management`
→ apply for the FBE allow-list. **Nothing else is blocked by this**, but
everything is blocked by it *eventually*, so start now.
*Effort: days of your time, weeks of waiting.*

### Phase 1 — Multi-tenancy (the real engineering)
`merchants` table, `merchant_id` everywhere, unify the two credential stores,
introduce `MetaContext`, make jobs/observers tenant-aware. **Needed regardless of
FBE.** Highest risk, most value.
*Effort: 2–3 weeks. Do it behind tests — the money paths already have 322.*

### Phase 2 — System User tokens + auto-refresh
Provision system user tokens via `business_management`, add the scheduled
refresher and `meta_token_events`. **This is what kills the 60-day reconnect** —
and it works on plain OAuth, with no FBE allow-list.
*Effort: ~1 week. Gated on App Review, not on FBE.*

### Phase 3 — FBE Embedded Signup
Developer Panel config, launch + callback, asset persistence, install/uninstall
webhooks, MBE Integration Review.
*Effort: 1–2 weeks of build, plus Meta's review clock.*

### Phase 4 — Operations
Per-merchant health dashboard, rate-limit budgeting across merchants, staged
reconnect prompts, per-merchant sync log retention.
*Effort: ~1 week, ongoing.*

### The sequencing point that matters

**Phases 1 and 2 deliver most of the merchant-visible benefit and need no
allow-list.** After Phase 2 a merchant clicks *Connect with Facebook* once and
never reconnects — which is 90% of the Shopify experience. Phase 3 removes the
last of the friction (asset pickers, one dialog instead of several screens) but
is the only part you cannot schedule.

**So: do not wait for Meta to start.**

---

## Answers in one line each

- **Required Meta products:** Business App, Business Login config, FBE allow-list, MBE Integration Review
- **Required permissions:** `business_management` + `catalog_management` (mandatory), `ads_management` (only if user tokens send CAPI)
- **Business Verification:** mandatory, blocks everything, start first
- **Merchant experience after:** one dialog, no tokens, no reconnects
- **Backend:** per-merchant credential context; transport layer already supports it
- **Schema:** `merchant_id` on the Meta tables, unify the two stores, add `meta_token_events`
- **Tokens:** system user + scheduled pre-expiry refresh; never `diffInDays`
- **Catalog/CAPI:** sync already uses the recommended Batch API; CAPI already falls back to one token
- **Reuse:** ~70% reusable; `MetaSettings`/`MetaTokenManager`/`MetaOAuthController` are the rewrites
- **Complexity:** medium-high, 5–7 weeks of build across 4 phases, 2 phases gated on Meta

---

## Open questions to settle before Phase 1

1. **Model A or Model B?** One install per merchant, or true multi-tenant? This
   changes Phase 1 from "skip" to "3 weeks".
2. **Do you have a Meta representative?** If not, the FBE allow-list is the
   riskiest item in this plan and Phase 3 may not be reachable this year.
3. **Does `ads_management` need to be in the first App Review submission,** or can
   system user tokens cover CAPI? Confirm with Meta — it affects review scope.
4. **What is the pricing model?** It determines whether per-merchant Graph API
   rate limits become a real constraint.

## Sources

- [FBE Partner Integrations](https://developers.facebook.com/docs/facebook-business-extension/fbe/partner-int-overview)
- [FBE Get Started](https://developers.facebook.com/docs/facebook-business-extension/fbe/get-started) — allow-list requirement
- [FBE Catalog guide](https://developers.facebook.com/docs/facebook-business-extension/fbe/guides/catalog)
- [FBE Pixel + CAPI onboarding](https://developers.facebook.com/docs/facebook-business-extension/fbe/get-started/pixel-capi-onboarding/)
- [System users: install apps, generate/refresh tokens](https://developers.facebook.com/docs/business-management-apis/system-users/install-apps-and-generate-tokens/)
- [Business Verification](https://developers.facebook.com/docs/development/release/business-verification)
- [Permissions reference](https://developers.facebook.com/docs/permissions)

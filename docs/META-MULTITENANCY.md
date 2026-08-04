# Meta module — what changes when multi-tenancy arrives

*Written 2026-08-03, alongside the SaaS-readiness refactor. Companion to
[META-SAAS-ROADMAP.md](META-SAAS-ROADMAP.md).*

The module now **resolves credentials through a contract instead of assuming a
global**, which is the seam multi-tenancy needs. Nothing is multi-tenant yet, and
behaviour for a single store is unchanged. This file is the checklist for
finishing the job — written now, while the reasoning is fresh, rather than
rediscovered later.

---

## The seam that already exists

```php
interface MetaCredentialResolver
{
    public function resolve(): MetaCredentials;   // whose credentials?
    public function currentKey(): string;         // which store? ('default' today)
}
```

Bound in `AppServiceProvider::register()` to `SingleStoreCredentialResolver`.

**To go multi-tenant, write a second implementation and change that one binding.**
Everything depending on the interface keeps working, because none of it ever knew
where the credentials came from.

`MetaTokenManager` has the same shape: every lookup goes through `scope()`, which
returns `['provider' => 'meta']` today. Add the merchant key there and all its
reads and writes follow. `forStore()` already exists for pointing it at a
specific connection.

---

## 1. Database — add the tenant key

| Table | Change |
|---|---|
| `merchants` | **New.** id, name, domain, plan, status |
| `meta_connections` | `+ merchant_id`, replace the implicit one-row assumption with `unique(merchant_id, provider)` |
| `meta_assets` | No change — reachable via `connection_id` |
| `meta_sync_states` | `+ merchant_id`, index `(merchant_id, product_id)` |
| `meta_sync_logs` | `+ merchant_id`, index `(merchant_id, created_at)` |
| `meta_module_states` | `+ merchant_id` |
| `meta_access_logs` | `+ merchant_id`, or delete with the security wall (§5) |
| `settings` (`meta_integration` row) | **Retire.** Fold into `meta_connections` — see §2 |

Backfill is trivial: one merchant row per existing install, stamp its id on
existing rows.

---

## 2. Retire the second credential store

There are **two systems that both claim to own the Meta connection**:

- `MetaSettings` → the `meta_integration` row in `settings`
- `MetaConnection` + `MetaAsset` → proper tables

They already disagree — `MetaDebug` reads one and falls back to the other. **Do
not carry both into multi-tenancy.** Keep `meta_connections`; reimplement
`MetaSettings`' interface on top of it so the ~20 files that consume it need no
edit, then delete the settings row.

Doing this *before* adding `merchant_id` is much cheaper than doing it after —
otherwise the tenant key has to be added to both stores.

---

## 3. Services — inject context, don't reach for a global

Everything below currently takes `MetaSettings` by constructor injection, which
implicitly means "the one for this install".

**Adapt (mechanical — swap the dependency, keep the logic):**

| File | Note |
|---|---|
| `Services/Meta/MetaCatalogService.php` | Batch logic unchanged; catalog id + token come from context |
| `Services/Meta/MetaGraphClient.php` | **Already accepts a per-call token override** — the least work of any file here |
| `Services/Meta/MetaTrackingService.php` | Per-merchant pixel + CAPI token |
| `Services/Meta/MetaStats.php` | Scope every aggregate by merchant |
| `Services/Meta/MetaDiagnostics.php` | Same |
| `Services/Meta/MetaProductMapper.php` | Only needs store name/brand defaults |
| `helpers.php` | Any Meta helper reading global settings |

**Untouched:** `MetaApiException`, the retry/backoff config, the sync-state
machine, the logging schema. That is deliberate — the expensive domain logic
does not care whose catalogue it is writing to.

---

## 4. Queue jobs — carry the tenant, never the token

`Jobs/Meta/`: `SyncProductToMeta`, `SyncCatalogChunkToMeta`,
`RemoveProductFromMeta`, `RetryFailedMetaSyncs`, `VerifyCatalogSync`.

Rules:

1. **Serialise the merchant id, never the credentials.** A token in a job payload
   sits in the `jobs` table in plaintext and outlives its rotation.
2. **Resolve credentials at run time**, inside `handle()`.
3. **Handle a merchant vanishing mid-queue.** The store may have been deleted or
   disconnected while the job waited — exactly the failure that produced 8 failed
   jobs on 27 Jul (see `WORK-LOG.md` §7). Use `$deleteWhenMissingModels` or an
   explicit guard.
4. `RetryFailedMetaSyncs` and `VerifyCatalogSync` are **scheduled and global** —
   they must iterate merchants rather than assume one.

Observers (`MetaProductObserver`, `MetaVariantObserver`) must resolve the owning
merchant from the product. If products become tenant-scoped, that is where the
lookup goes.

---

## 5. Delete the secondary security wall

`MetaSecurityGate`, `MetaSecurityController`, `meta_access_logs`, and the
`meta.security.*` config exist because on a single install the Meta credentials
are shared by anyone who can reach the admin. In a multi-tenant product each
merchant only ever sees their own connection, so a **second password on your own
product is friction that will cost signups**.

Remove it in the multi-tenant build. (Its idle-timeout bug is fixed either way —
`WORK-LOG.md` §6.)

---

## 6. Rate limits and fairness

Graph API limits apply **per app**, and every merchant shares your app. One
merchant doing a full 10,000-product refresh can exhaust the budget for everyone.

Needed before real scale:

- A per-merchant queue or a weighted dispatcher, so one bulk sync cannot starve
  the rest
- Back-pressure on `RATE_LIMIT` responses that pauses **that merchant**, not the
  whole queue
- Per-merchant sync-log retention — `logs:prune` currently prunes globally

---

## 7. Admin UI

Every Meta screen assumes "your connection". Under multi-tenancy the merchant
admin keeps that assumption (scoped to them), but you also need an **operator
view**: connection health across all merchants, who needs to reconnect, who is
failing to sync. That view does not exist and is not a variation of an existing
screen.

---

## Order of work

1. **Retire the second credential store** (§2) — cheapest now, expensive later
2. **`merchants` table + `merchant_id`** (§1)
3. **Per-merchant resolver**, bind it (the one-line swap)
4. **Services take context** (§3)
5. **Jobs carry the tenant** (§4)
6. **Delete the security wall** (§5)
7. **Rate-limit fairness + operator view** (§6, §7)

Steps 1–5 are the correctness work. 6–7 are what "thousands of merchants" needs
on top.

---

## Rules that must survive the migration

- **Never resolve credentials from a global inside a service.** Take
  `MetaCredentialResolver` and ask it.
- **Never put a token in a queue payload.** Serialise the merchant id.
- **Never use `diffInDays()` for an expiry check.** Carbon 3's diffs are signed;
  compare instants against a threshold (`lte`, `gt`). This shipped four inverted
  comparisons, one of them a security hole.
- **Keep the two credentials distinct.** The connection token and the Conversions
  API token have different owners, different lifetimes and different fixes.
  `MetaCredentials` exists to stop them being blurred again.

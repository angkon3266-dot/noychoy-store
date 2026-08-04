# Product roadmap — self-hosted commercial deployment

*Written 2026-08-03. Supersedes the multi-tenancy planning in
[META-MULTITENANCY.md](META-MULTITENANCY.md), which assumed the wrong product model.*

## The model this is optimised for

One customer buys the software and receives **their own Laravel installation,
hosting account, database and domain**. Each installation manages exactly one
store, one merchant and one Meta connection.

This is a **self-hosted commercial product**, not a shared platform. Nothing here
assumes more than one merchant per install.

---

## What that changes

Dropped entirely — these only existed to serve many merchants from one install:

| Dropped | Why it was wrong here |
|---|---|
| `merchants` table, `merchant_id` columns | One install = one merchant. The tenant key is the database itself |
| Tenant-aware queue jobs | A job can only ever belong to the one store |
| Operator dashboard across merchants | There is no "across merchants" |
| Graph API fairness between merchants | See the caveat in §1 — it depends on App ownership, not tenancy |
| `MetaCredentialResolver` interface | A resolver that resolves one thing. See §4 |
| `MetaTokenManager::forStore()` / `scope()` override | Added for multi-tenancy, zero callers |

**Kept**, because it was never about tenancy: `MetaCredentials` — the value object
separating the connection token from the Conversions API token. That was a
correctness fix (the two have different owners, lifetimes and remedies) and it
matters just as much in a single store.

**The tenancy boundary is now the installation.** Isolation comes free: separate
database, separate host, separate domain. That is stronger isolation than
`merchant_id` scoping ever gives you, and it is why this model is a reasonable
choice rather than a compromise.

---

## 1. 🟢 RESOLVED — whose Meta App is it?

> **Full comparison + evidence:** [META-APP-ARCHITECTURE.md](META-APP-ARCHITECTURE.md)
> (researched 2026-08-03). Verified against Meta's Platform Terms and Meta's own
> "Facebook for WooCommerce" plugin — the closest real-world comparable, run by
> Meta itself across hundreds of thousands of independent self-hosted stores
> through one shared App.

`config/meta.php` reads `META_APP_ID` / `META_APP_SECRET` from **each install's
environment**, and System Config lets the customer set them. So today, every
customer is expected to bring their own Meta App. That has a consequence I do not
think has been priced in.

**To use "Connect with Facebook" for a real store, the App needs
`catalog_management` and `business_management` at advanced access — which
requires App Review *and* Business Verification.** A small Bangladeshi jewellery
merchant will not get through that. Realistically, most customers never will.

Which means: **for most of your customers, OAuth will not work at all, and
Development Mode (paste a System User token) is the only path that functions.**
That is not a fallback in this model — it is the primary flow, and it deserves to
be treated as such in the UI, the docs and the onboarding.

Three ways forward:

| Option | How it works | Verdict |
|---|---|---|
| **A. Customer's own App** (today) | Each customer creates an App, does their own Verification + Review | Honest, zero vendor infrastructure — but OAuth is unreachable for most. **Manual token becomes the real product** |
| **B. Ship your App credentials** | Your `META_APP_SECRET` in every customer's `.env` | ❌ **Rejected — the only option with an unmitigable defect.** The secret signs `appsecret_proof` and OAuth token exchange. On a customer-controlled server it is readable by the customer, their host, and anyone with file access. Cannot be rotated without breaking every install, and one leak — or one App suspension from a single customer's abuse — hits every customer simultaneously |
| **C. Vendor OAuth broker** | A small service you host holds the secret; customer installs redirect through it and receive only the resulting token | ✅ **Confirmed target.** This is what Meta's own Facebook-for-WooCommerce does. Customers get one-click connect, your secret never leaves your server, one App Review covers everyone — Meta's "Tech Provider" terms are the named, sanctioned path for exactly this |

**Decision: A now, C when there is revenue to fund it.** Development Mode
(manual System User token) is not the lesser path for this product — it is the
one that works for most customers today, and should be built and documented as
the primary flow, not a fallback. B is off the table permanently, not just for
now: its risk *increases* with customer count, it has no mitigation, and the
alternative (C) achieves the same one-click UX without it.

> **Rate-limit caveat.** Graph API limits are **per App**. Under A each customer
> has their own App and their own budget — one of A's genuine advantages. Under
> C every install shares yours, and this is the one problem the broker does
> *not* solve — it only removes the secret-distribution risk, not the shared
> rate-limit ceiling. Budget for it before C carries real volume.
> again. That was the only part of the multi-tenant plan with a life beyond it.

---

## 2. 🔴 There is no way to ship an update

No `APP_VERSION`, no installer, no updater, no migration-safety story. Today a
customer upgrades by someone SSH-ing in and running `deploy.sh` against a repo
they can reach. That is fine for one install you own. It does not survive
customer #10, and it is the single biggest gap in the product.

> **If you host and resell every customer's account** (confirmed 2026-08-03,
> see `META-APP-ARCHITECTURE.md` §3a): you likely retain WHM-level access to
> every account independent of the customer's own cPanel login. That changes
> "ship a customer-run updater" into "push updates across the fleet yourself,"
> which is both easier to build and doesn't depend on any customer running a
> command correctly. Confirm this against your actual reseller mechanism before
> treating it as load-bearing — worth doing before committing to which of the
> two you build.

What a distributed product needs (customer-run version — build the
fleet-update version instead if the above holds):

- **A version constant**, surfaced in the admin footer and `app:doctor`. Support
  cannot start without "which version are you on?"
- **`php artisan app:install`** — a guided first-run: DB check, `key:generate`,
  migrate, seed the admin, `storage:link`, permissions. Replaces most of DEPLOY.md
- **`php artisan app:update`** — pull, `composer install --no-dev`,
  `migrate --force`, `optimize`, with a **backup taken first** and a clear
  failure exit. `deploy.sh` is 80% of this already; it just isn't packaged
- **Migrations that survive version skips.** A customer may upgrade from a
  release six months old. Every migration must be conditional
  (`Schema::hasColumn` before adding) and never assume the previous release
- **A changelog customers can read**, and a "what's new" note in the admin

**This is the highest-ROI work on the list.** Everything else is a feature; this
is whether you can support the product at all.

---

## 3. 🟠 Meta token lifecycle — worth *more* in this model, not less

A long-lived OAuth token lasts ~60 days and nothing refreshes it. In a shared
platform you would fix that once and automate the rest. Here, **every customer
independently hits it**, and each one becomes a support ticket that reads "my
products stopped syncing" — with no obvious cause, because the storefront is
unaffected.

With 30 customers on OAuth that is roughly **15 tickets a month**, forever.

The fix is unchanged from the earlier analysis and needs no FBE allow-list:

- Mint a **System User token** via `business_management` where possible, or
  re-exchange the long-lived token before expiry
- A **scheduled refresher** running at 14 days remaining, not 1
- **Alert the merchant in-app once**, not per attempt
- Log every issue/refresh/failure so support can answer "when did it break?"

Under Option A (customer's own App) many customers will be on manual System User
tokens, which typically do not expire — so this matters most for the subset using
OAuth. Build it anyway; the ones on OAuth are the ones who will complain.

---

## 4. 🟠 Simplify what the last round over-built

I introduced `MetaCredentialResolver` on a multi-tenant premise. In a single-store
install it resolves exactly one thing, forever.

**Recommended cleanup (small, low risk):**

- **Keep `MetaCredentials`.** Separating the connection token from the CAPI token
  is a correctness fix that stands on its own
- **Drop the `MetaCredentialResolver` interface**; have `MetaSettings` return a
  `MetaCredentials` directly. One less indirection to explain
- **Remove `MetaTokenManager::forStore()` and the `scope()` override** — dead code
  with no callers

Not urgent, but do it before the pattern gets copied.

---

## 5. 🟠 Meta configuration is spread across four places

A customer configuring Meta today may have to touch:

| Surface | Holds |
|---|---|
| `.env` | 4 `META_*` keys |
| `config/meta.php` | 25 `env()` reads |
| System Config → Meta | App ID, App Secret, Login Config ID, Webhook token |
| Admin → Marketing → Meta (`MetaSettings`) | ~40 runtime keys |

Nobody self-serving can be expected to know which of four places a given value
lives in — and the same value is sometimes reachable from two of them. For a
product customers configure themselves, **this is a support-ticket generator**.

Consolidate on: **System Config for vendor/app-level values, `MetaSettings` for
store-level values, `.env` for bootstrap only.** Then make the Meta screen say
where each field comes from.

---

## 6. 🟠 Retire the second credential store

Two systems both claim to own the Meta connection — `MetaSettings` (a `settings`
row) and `MetaConnection`/`MetaAsset` (proper tables). They already disagree:
`MetaDebug` reads one and falls back to the other.

This was on the multi-tenancy list, but it is **purely a maintainability problem**
and survives the change of model intact. Two sources of truth for the same
credential is how a subtle "it says connected but sync fails" bug gets born.

Keep `meta_connections`; reimplement `MetaSettings`' interface on top of it so
consumers need no edit; delete the settings row.

---

## 7. 🟡 Support tooling — you cannot SSH into a customer's server

`php artisan app:doctor` is genuinely good and already answers most health
questions. In this model it is a support tool, and it should be reachable
**from the admin UI**, not only the CLI — because the person with the problem has
a browser, not a terminal.

Worth adding:

- A **System Health page** rendering `app:doctor` output
- A **"copy diagnostics" button** producing a redacted text block a customer can
  paste to you (versions, config surfaces, queue state, last errors — **never**
  tokens)
- **Optional** opt-in error reporting to you. Opt-in, disclosed, and off by
  default: it is the customer's server and their data

---

## 8. 🟡 Licensing and distribution

A self-hosted commercial product currently has nothing preventing a customer
copying the install to unlimited domains. Whether that matters is a business
decision, not a technical one — but if it does, decide **now**, because
retrofitting a licence check into installs already in the field is unpleasant.

Options run from a licence key checked at install (mild, honest customers stay
honest) to periodic domain validation (stronger, adds a hard dependency on your
server being up — which will eventually take a customer's admin down with it).

If you go this route, **fail open**. A licence server outage must never stop a
merchant taking orders.

---

## 9. 🟡 The Meta security password wall

I previously said delete it. That was reasoning from the multi-tenant model,
where each merchant only sees their own connection.

**Revised:** keep it, but make it **optional and off by default.** In a
self-hosted install the owner is usually the super admin, so a second password
mostly adds friction — but a shop with staff accounts has a real reason to gate
the screen that holds their Meta credentials and can send SMS.

(Its idle-timeout bug is fixed either way — `WORK-LOG.md` §6.)

---

## Recommended order

| # | Task | Why now | Effort |
|---|---|---|---|
| 1 | ~~Decide the Meta App model~~ (§1) — **resolved, A now / C later** | Was blocking how OAuth is documented, sold and supported | Done — decision only |
| 2 | **Version + `app:install` + `app:update`** (§2) | Cannot support customer #10 without it | ~1 week |
| 3 | **Migration-skip safety** (§2) | Every future release depends on it | ~2 days |
| 4 | **Token auto-refresh** (§3) | Removes a recurring, permanent support cost | ~1 week |
| 5 | **Retire the second credential store** (§6) | Cheapest now; gets worse with age | ~3 days |
| 6 | **Consolidate config surfaces** (§5) | Fewest tickets per unit of effort | ~3 days |
| 7 | **Simplify the resolver** (§4) | Before the pattern spreads | ~half a day |
| 8 | **System Health page + diagnostics copy** (§7) | Makes support possible at distance | ~3 days |
| 9 | **Licensing decision** (§8) | Retrofitting is worse than deciding | Decision + ~3 days |

**1 and 2 are the ones that decide whether this is a product or a project.**
Everything below 4 is quality-of-life.

---

## What is explicitly NOT on this roadmap

- Multi-tenancy in any form
- FBE / Embedded Signup — see [META-SAAS-ROADMAP.md](META-SAAS-ROADMAP.md) for
  the research, but note it is allow-listed by Meta and irrelevant under Option A
- Redesigning catalog sync, the mapper, batching, retry, logging or the queue —
  all sound, all reusable, leave them alone
- Bookings / multi-niche support — decided against 2026-08-02

---

## Still true regardless of model

From `WORK-LOG.md`, the rules that outlive any architecture decision:

- **Never use `diffInDays()` for an expiry check.** Carbon 3's diffs are signed
- **Never put a token in a queue payload.** Serialise the id
- **Keep the connection token and the CAPI token distinct** — different owners,
  lifetimes and fixes
- **`optimize:clear` on deploy flushes the whole cache**, so anything that cost
  money to obtain belongs in a table

# Meta App architecture — customer-owned vs vendor-owned vs OAuth broker

*Researched 2026-08-03 against Meta's live documentation. Resolves the open
decision in [PRODUCT-ROADMAP.md](PRODUCT-ROADMAP.md) §1. No code changed.*

**Product context:** one Laravel installation per customer, each on their own
host/DB/domain, each running exactly one store. Customers are mostly small
Bangladeshi merchants unlikely to independently pass Meta App Review.

---

## 1. Can multiple independent installations safely use the same Meta App?

**Yes — and this is not a workaround, it is a named, sanctioned Meta model.**

Meta's Platform Terms define a **Tech Provider**: a developer whose "primary
purpose is to enable Users thereof to access and use Platform or Platform Data"
on behalf of many separate clients. Obligations that come with it:

- **Data segregation** — "ensure that Platform Data you maintain on one Client
  is maintained separately from that of other Clients"
- **A maintained client list**, produced to Meta on request
- **Use limited to that client's direction** — "not for your own purposes or
  another Client's"
- **You are responsible for your clients' violations** and must terminate a
  client who breaches Meta's policies

**Real-world proof, not just theory:** Meta's own **"Facebook for WooCommerce"**
plugin is exactly this pattern, built by Meta, running today across hundreds of
thousands of independent, unrelated, self-hosted WooCommerce stores — through
**one Meta-owned App**. It is the closest possible comparable to this product
(self-hosted e-commerce, catalog sync + Pixel/CAPI, many unrelated merchants),
solved by the platform vendor itself.

**Technical isolation, independent of the Tech Provider paperwork:** access
tokens obtained through Facebook Login for Business are scoped **per business**,
not per app. Meta's docs are explicit: *"Access is explicitly delegated at the
time of authorization. Your app can only access the assets that were designated
by your business client... Granular access tokens are still specific to a
client business portfolio. They are not shareable and accessible across
different client businesses."* If one customer's token is compromised, *"only
that specific client business will be impacted."*

So the isolation your customers need does not come from having separate Apps —
it comes from Meta's own token model, which already isolates by business
regardless of how many businesses share the App that issued the token.

---

## 2. Security implications of embedding the app secret in self-hosted software

Meta's own guidance: *"Never hard-code app access tokens into client-side code
or app binaries... Only use app access tokens in server-to-server calls... API
calls requiring an app access token should never be made from a client, but
instead from a server where you can securely store your app secret."*

That guidance assumes the vendor controls the server. **This product does not**
— each customer runs their own server, and the app secret would sit in their
`.env`, in plaintext, on infrastructure you do not operate.

Concretely, embedding a shared secret in every self-hosted install means:

- **Readable by the customer, their hosting provider, and anyone who
  compromises that one shared host** — no different in principle from any
  `.env` leak, except the blast radius is not one merchant's data, it is
  **every merchant's connection**, because the secret is identical everywhere
- **Cannot be rotated.** Rotating means pushing a new secret to every
  independently-hosted, independently-updated install simultaneously — not
  achievable in practice, and any install that misses the update silently
  breaks
- **One customer's breach can cost every customer.** If the secret leaks from
  install #40 and is abused, Meta may flag or suspend the App — which breaks
  OAuth for every other install using it, none of whom did anything wrong
- **Scales the wrong direction.** More installs means more copies of the same
  secret in the wild, so risk *rises* with customer count instead of staying
  flat

This is the one architecture with a structural defect that has no mitigation
short of not doing it.

---

## 3. Can an OAuth broker eliminate the need to distribute the app secret?

**Yes.** The mechanism is the server-side requirement Meta already states —
just relocated to infrastructure the vendor actually controls:

```
Customer's install  →  redirect to broker  →  Meta OAuth dialog
                                                     ↓
Customer's install  ←  access/system-user token  ←  broker
                        (secret never leaves the broker)
```

The broker — a small service you host — holds `META_APP_SECRET` and performs
the code→token exchange server-side. The customer's install only ever receives
the resulting **per-business, per-customer token**, which per §1 is already
isolated to that business regardless of the App behind it. No secret is ever
present on customer-controlled infrastructure. This is the direct, correct
application of Meta's own "keep the secret on a server you control" rule to a
distributed-software business.

**What it does not fix:** Graph API rate limits are per-App, and a broker
doesn't change that — every install sharing the App still shares one rate-limit
budget. See the scalability row below.

---

## 3a. Addendum — what if the vendor also provides the hosting?

*Added 2026-08-03, in response to a follow-up question.* Answer depends
entirely on what access the customer has to the server itself:

| Vendor's hosting model | Changes the B/C verdict? |
|---|---|
| **Fully managed** — customer sees only the store admin panel, no SSH/FTP/File Manager | **Yes.** No customer, and no one they grant access to, can ever reach the filesystem. A secret written to `.env` is then genuinely on infrastructure the vendor alone controls — which is exactly the condition Meta's own guidance requires. B becomes viable, though C is still marginally better on blast-radius and rotation grounds (compromising one server under B still leaks that server's copy; under C, no server holds the secret at all) |
| **Reseller hosting — customer gets their own cPanel/FTP/SSH** (confirmed as the actual plan) | **No.** The exposure surface is identical to pure self-hosting: whoever can reach that account's File Manager — the customer, anyone they later grant access to, anyone who compromises the account — can read the secret. Who provisioned the account originally is irrelevant to who can read it today. **The verdict is unchanged: reject B, target C or run A.** |

> **"Store it in System Config instead of `.env`, since that's Crypt-encrypted
> and password-gated" does not close this gap.** Verified against
> `SystemConfigRepository.php:69` — sensitive values there use Laravel's
> standard `Crypt::encryptString()`, keyed by `APP_KEY`. `APP_KEY` has no home
> but `.env` (`config/app.php:100`, and confirmed by this project's own rule
> that it is "never editable" in System Config — it's the root key, it has to
> stay in the bootstrap file). Filesystem access to the account already yields
> `APP_KEY` plus the DB credentials (also unavoidably in `.env`), which
> together are sufficient to decrypt any System-Config-stored secret directly —
> `Crypt::decryptString()` from a `tinker` session, no HTTP request involved,
> so the web UI's password-confirmation gate is never reached. That gate is
> real protection against a DB-only leak, a lower-privileged panel login, or
> the value ending up in a compiled config cache — not against filesystem
> access, which is the access level in question here.

**What the reseller model *does* change is unrelated to the Meta App
question.** As reseller, the vendor likely retains WHM-level access to every
account independent of the customer's own cPanel login — a materially
different position than arms-length self-hosted distribution, and directly
relevant to `PRODUCT-ROADMAP.md` §2 (the installer/updater gap): centrally
pushing updates across the fleet becomes plausible, rather than depending on
each customer correctly running an updater themselves. This depends on which
specific reseller mechanism is used (WHM resold through a parent host vs. a
vendor-operated VPS reseller stack), and should be confirmed against the
actual hosting plan before being treated as load-bearing.

---

## 4. What architecture do comparable products use?

**Meta for WooCommerce is the direct, verified comparable** (§1): one
Meta-owned App, Facebook Login for Business, per-merchant token isolation,
across an unbounded number of independent self-hosted stores. That is, in
substance, option C below — Meta is both the platform and the "broker."

More generally — and this is standard software-architecture knowledge rather
than something specific to Meta — the **vendor-hosted OAuth proxy** is the
common answer whenever self-hosted software needs to integrate with a
third-party API that requires a registered app and a secret. The distributable
software never holds the secret; a small vendor-operated relay does, and it
only ever hands back a scoped, revocable token. The reasoning is identical to
§2 and §3: a secret that must exist can only be safely embedded in
infrastructure the vendor controls.

---

## 5. Comparison

| | **A. Customer-owned App** | **B. Vendor-owned App, secret shipped** | **C. Vendor OAuth broker** |
|---|---|---|---|
| **Security** | Good — blast radius is one customer, entirely their own risk | 🔴 Structural defect — shared secret on infrastructure you don't control, unrotatable, blast radius is every customer | ✅ Best — secret never leaves vendor infrastructure; per-business tokens already isolate customers (§1) |
| **Merchant onboarding** | Poor for OAuth — App Review + Business Verification is unreachable for most small merchants. Manual System User token becomes the real path | ✅ One-click — App Review done once by the vendor covers every customer | ✅ One-click — same as B |
| **Maintenance (vendor)** | ✅ None — no shared infra, nothing to keep in good standing | Must keep the App in good standing; one abusive customer risks the App for everyone, with no isolation to contain it | Must build **and run** a small always-on service — real new operational surface, but the risk it carries is contained and well-understood |
| **App Review** | Every customer faces it independently — most never clear it | Done once, by the vendor, covers all customers | Done once, by the vendor, covers all customers |
| **Scalability** | Perfect for the vendor (zero shared infra); poor for customers (each hits the same wall) | Works until the shared Graph API rate-limit budget is felt across many active stores, or the App gets flagged | Same rate-limit ceiling as B — the broker fixes the *secret* problem, not the *rate limit* problem |
| **Support burden** | "Talk to Meta" for connection issues, but real burden from confused merchants stuck in Meta's own setup flow | Vendor owns every OAuth ticket, *plus* the catastrophic case: one App suspension breaks every customer at once | Vendor owns OAuth tickets, plus the broker's own uptime — but no customer can take another one down |
| **Customer experience** | Weakest — multi-step, technical, likely stuck on manual token indefinitely | Best case: great. Worst case: everyone breaks simultaneously through no fault of their own | Best case: same as B. Worst case is contained to "reconnect is briefly unavailable," not "every customer's connection is revoked" |

---

## Recommendation

**Reject B outright.** It is the only option whose central risk cannot be
mitigated — a secret that must exist, held on infrastructure you do not
operate, growing riskier as the customer base grows.

**Target architecture: C.** It is what Meta's own comparable product actually
does, it is the correct application of Meta's own security guidance to a
distributed-software business, and it delivers genuine one-click onboarding —
which for this product's merchants (see §1's onboarding row) is close to the
only way most of them will ever use OAuth at all.

**Sequencing: ship A now, build C when there is revenue to fund it.** A needs
zero new infrastructure and is honest about what it is — most customers will
run on the manual System User token regardless, which is exactly what
`PRODUCT-ROADMAP.md` already identified as the real primary flow, not a
fallback. Design the connection UI so that swapping in C later changes only
*where the OAuth redirect points*, not the token-handling code downstream —
`MetaCredentials` (already built) makes this cheap either way, since it already
treats "how the connection token was obtained" as a fact to display, not
something the rest of the module needs to branch on.

This does not change the priority order in `PRODUCT-ROADMAP.md`: **the version/
installer/updater gap and token auto-refresh remain higher-ROI than building
the broker**, because they matter on Architecture A too, and Architecture A is
what ships first regardless.

---

## Sources

- [Access Tokens for Meta Technologies](https://developers.facebook.com/documentation/facebook-login/guides/access-tokens) — app-secret handling rules
- [Login Security](https://developers.facebook.com/docs/facebook-login/security/)
- [Facebook Login for Business](https://developers.facebook.com/docs/facebook-login/facebook-login-for-business) — per-business token isolation
- [Meta Platform Terms](https://developers.facebook.com/terms/dfc_platform_terms/) — Tech Provider definition and obligations
- [Meta Business Extension overview](https://developers.facebook.com/docs/facebook-business-extension/fbe/overview)
- [Meta for WooCommerce](https://woocommerce.com/document/facebook-for-woocommerce/) — the real-world comparable
- Carried over from `META-SAAS-ROADMAP.md`: [Permissions reference](https://developers.facebook.com/docs/permissions) — standard vs. advanced access

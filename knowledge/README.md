# Knowledge Base — the business brain

Single source of truth for the store's brand, catalog, marketing, and support
knowledge, written for **AI consumption first** (Claude Projects, ChatGPT,
RAG pipelines, MCP servers, internal automations) and humans second.

This deployment: **Meridian Éclat** (meridianeclat.shop) — fine jewelry,
Bangladesh, cash on delivery. The `_templates/` layer is store-agnostic; the
content layers are this store's real knowledge.

---

## 1. Folder architecture

| Folder | Purpose | Written by |
|---|---|---|
| `_templates/` | Blank templates for every document type. Copy, never edit in place. | humans |
| `brand/` | Who the store is: identity, voice, audience. The grounding layer every AI task should load first. | humans |
| `seo/` | Keyword strategy, on-page rules, schema notes. | humans |
| `marketing/` | Channel playbooks grounded in the platform's real features (push, SMS, segments, win-back, coupons, FB ads). | humans |
| `prompts/` | Reusable, tested prompt recipes. Each names its required context files. | humans |
| `support/` | FAQ + playbooks for customer conversations. | humans |
| `policies/` | Shipping, returns, payment policies — the canonical wording AI may quote to customers. | humans |
| `materials/` | Gemstone/material/finish facts, care instructions. Shared by many products. | humans |
| `collections/` | One file per category/collection: positioning, keywords, merchandising. | humans |
| `products/` | **One file per product.** Front matter is machine-synced from the database; the body is human/AI-authored and preserved. | `php artisan knowledge:sync` + humans |

**Layering rule:** facts live in exactly one place. Products link to materials
and policies instead of repeating them; prompts link to brand instead of
restating voice. A retrieval system then assembles small, non-contradictory
contexts.

## 2. Naming conventions

- Files: `kebab-case.md`, named for the subject, not the type (`rose-gold-solitaire-ring.md`, not `product-17.md`).
- Product files: `{slug}.md` — matches the storefront URL, stable across renames of the display name.
- One H1 per file, matching the filename subject. Sections are H2 (`##`) so chunkers split on stable boundaries.
- Every file starts with YAML front matter: `type`, `updated`, plus type-specific keys. Retrieval filters use these.
- Unknowns are written as `[CONFIRM: question]` — never invented. An AI that meets `[CONFIRM]` must ask, not guess.

## 3. Where dynamic truth lives (do not duplicate it here)

Prices, stock, shipping rates, and coupon codes change in the **admin panel /
database** — that is their source of truth. Product front matter carries a
synced snapshot (stamped `synced_at`); anything money- or stock-critical
should be re-checked against the live API/DB, not trusted from markdown.
Shipping fees: admin → Settings (inside/outside Dhaka rates, free-shipping
threshold). Loyalty rates: admin → Settings → Loyalty.

## 4. Version control & git workflow

- This folder **is committed to the app repo** — knowledge deploys with code, is branch-reviewable, and one clone gives an agent code + business context. **Exception:** `products/` is gitignored — it's a generated artifact, rebuilt per environment from that environment's database (auto-synced on every product save, or via `php artisan knowledge:sync`), so server-side generation never dirties the deploy tree.
- Knowledge edits are normal commits, prefixed `kb:` (e.g. `kb: update returns policy wording`). Review diffs like code — a wrong policy in git becomes a wrong answer to a customer.
- The whole folder is also browsable/editable at **Admin → Knowledge** (product files warn that their front matter is machine-owned).
- Never rewrite history on knowledge files: point-in-time answers ("what did our policy say in June?") depend on it.

## 5. RAG optimization

- Chunk on H2 boundaries; keep sections under ~300 words so a chunk carries one idea.
- Front matter keys (`type`, `category`, `tags`) are the metadata filters — index them.
- Cross-links use relative paths (`../materials/cubic-zirconia.md`) — a RAG ingester can resolve them to enrich chunks with linked context.
- The `products/` folder is regenerable; embed it on a schedule keyed to `synced_at` and re-embed only changed files.

## 6. Claude Project / ChatGPT optimization

- A Claude Project should load: `brand/` (all), `policies/` (all), `prompts/` for the task at hand, plus only the relevant `products/`/`collections/` files. Don't bulk-load 500 product files — retrieval beats stuffing.
- `prompts/*.md` are written as system-prompt fragments: paste one + the context files it names at the top.
- Keep total loaded context intentional: brand + policies ≈ small and stable; product data ≈ retrieved per task.

## 7. MCP readiness

The natural next step is a small MCP server exposing:
- `kb://brand/*`, `kb://policies/*` … as **resources** (read-only markdown),
- `search_products(query)` and `get_product(slug)` as **tools** backed by the live DB (fresh prices/stock) merged with the product file body (stories, angles).
Because every file has front matter + stable paths, that server is a thin file/DB reader — no restructuring needed later.

## 8. Automation opportunities (in rough order of value)

1. **Auto-sync on product save** — DONE: every product create/update queues `knowledge:sync --product={id}` (see `App\Jobs\SyncProductKnowledge`), so markdown never drifts.
2. **AI body-filling** — for any product whose body still has template placeholders, run the `prompts/product-description.md` recipe against front matter + material files, and commit the draft for human review.
3. **Push/SMS copywriting** — feed `marketing/` + a product file to draft campaign copy for the admin composer.
4. **Support drafting** — `support/` + `policies/` + the order record answer most inbound messages; a human approves.
5. **SEO refresh loop** — quarterly: re-run keyword checks from `seo/strategy.md` against titles/meta in front matter, propose diffs.

## 9. How a new product enters the system

1. Admin creates the product in the panel (or CSV import) — as today, nothing extra.
2. The save automatically queues a sync that creates `products/{slug}.md`: front matter from the DB + body seeded from `_templates/product.md` with the description filled in (visible within a minute at Admin → Knowledge).
3. A human or AI fills the placeholder sections (story, audience, angles, FAQ) — guided by `brand/` and `materials/` — right in Admin → Knowledge or any editor.
4. Every later save refreshes only the front matter; the authored body is never overwritten.

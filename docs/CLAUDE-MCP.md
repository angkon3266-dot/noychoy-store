# Claude connector — run the store from Claude

The store is an **MCP server** (Model Context Protocol) at `https://<your-domain>/mcp`.
Connect Claude to it and you can manage the shop in plain words — products and
descriptions, categories and collections, the menu, orders, customers and their
texts, coupons and offers, reviews, and sales/stock overviews — without opening
the admin panel and without a developer.

Everything is set up from **Admin → Claude connector** (`/admin/api-tokens`,
administrators only). Each copy of the store has its own connector on its own
domain, so a new owner simply connects their own Claude to their own copy.

---

## 1. Connect

### claude.ai and Claude Desktop (no token needed)

1. In Claude: **Settings → Connectors → Add custom connector**.
2. Name: your store's name. URL: `https://<your-domain>/mcp`. Leave the
   advanced OAuth client ID/secret fields empty.
3. Click **Connect**. A page from your store opens; log in to the admin if
   asked, check that it names you, click **Allow**.
4. In a chat, switch the connector on from the tools menu.

Claude works *as the admin user who clicked Allow*. The connection appears in
Admin → Claude connector as "Claude (connected <date>)" and can be disconnected
there. On Team/Enterprise plans, an organisation owner must add custom
connectors first. Claude Desktop uses the same connectors once signed in.

### Claude Code

Create a token in Admin → Claude connector (it acts as you), then:

```bash
claude mcp add --transport http mystore https://<your-domain>/mcp --header "Authorization: Bearer mec_…"
```

Or skip the token: add it without `--header`, then in Claude Code run `/mcp` →
the server → **Authenticate**, which opens the same Allow page.

### Anything else that speaks MCP

Streamable HTTP, JSON responses, `POST /mcp`. Auth is a bearer token: either a
token from the admin page or an OAuth 2.1 access token (discovery at
`/.well-known/oauth-protected-resource/mcp` and
`/.well-known/oauth-authorization-server`; dynamic client registration at
`/oauth/register`; PKCE S256 required).

---

## 2. What Claude can do

Which tools Claude sees depends on the **role** of the user it acts as
(Admin → Staff & roles): an administrator gets all of them, a manager gets
everything but settings, staff get orders and the sales overview. A tool outside
the role is not listed and is refused if called by name.

| Area | Tools | Notes |
|---|---|---|
| Store | `store_info` | Who Claude acts as and what that role can reach |
| Products | `search_products`, `get_product`, `create_product`, `update_product`, `publish_product`, `unpublish_product`, `set_product_variants`, `upload_product_image`, `set_primary_image`, `delete_product_image`, `delete_product`, `stock_summary` | New products are drafts. Images come from a URL and go through the same WebP + watermark pipeline as an admin upload |
| Categories & collections | `list_categories`, `create_category`, `update_category`, `list_collections`, `create_collection`, `update_collection` | Saved through the admin controllers, so the admin's validation applies |
| Menu | `get_menu`, `update_menu` | `update_menu` replaces the whole menu and returns the previous one |
| Orders | `list_orders`, `get_order`, `update_order_status` | Same effect as the status dropdown (stock, points, history). **Never** sends an SMS, **never** books a courier |
| Customers | `find_customers`, `get_customer`, `send_customer_sms` | One-off texts only, through the normal SMS gateway and SMS log |
| Marketing | `list_coupons`, `create_coupon`, `list_offers`, `create_offer` | Coupons can be tied to a list of phone numbers |
| Reviews | `list_reviews`, `moderate_review`, `delete_review` | Approving awards the reviewer's points, as in the admin |
| Reports | `sales_summary` | The dashboard's own numbers |

**Safety.** Tools that delete something, change an order, text a customer or
replace the menu say so in their description, are marked destructive for
Claude, and refuse to run without `confirm=true` — so Claude asks you first.
Nothing in the connector books a courier or sends the store's automatic texts.

**Audit.** Every call — allowed, refused or failed — is listed under *What
Claude did* on the connector page, with its arguments, the user and the
connection. Kept for a year (`RETAIN_MCP_TOOL_CALLS_DAYS`).

---

## 3. For whoever runs the server

- Requirements: nothing beyond the store itself. No Passport keys, no queue.
  `composer install` brings in `laravel/mcp`; `php artisan migrate` adds the
  `oauth_clients`, `oauth_auth_codes` and `mcp_tool_calls` tables.
- Throttles: `/mcp` 120 requests/min, token endpoint 30/min, client
  registration 10/min.
- OAuth tokens last 8 hours and refresh themselves for 90 days of inactivity;
  pasted tokens last until revoked (or their optional expiry).
- Revoking a connection, deleting the user, or moving the user to a role
  without admin access ends Claude's access immediately.
- Code: `app/Mcp/` (server and tools), `app/Http/Controllers/OAuth/`,
  `routes/oauth.php`, tests in `tests/Feature/Mcp/`. The REST API under
  `/api/v1/` (`routes/api.php`) shares `App\Services\Api\CatalogApi` with the
  product tools and now needs a token whose user can reach Products.
- To add a tool: a class extending `App\Mcp\Tools\StoreTool` with its
  `$section`, listed in `App\Mcp\StoreServer::$tools`. Role check and audit come
  with the base class; reuse the admin controller (`AdminAction::run`) or a
  service rather than re-implementing rules.

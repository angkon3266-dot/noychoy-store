# Managing the catalogue with Claude (MCP)

The store publishes a **Model Context Protocol** server at
`https://meridianeclat.shop/mcp`. Connect Claude to it and Claude can manage
products the way it manages a Shopify store: list categories, create products,
edit details, upload and arrange photos, publish, and archive.

## 1. Create a token

Admin → **API tokens** → create one (name it e.g. "Claude"). Copy the token
immediately — it is shown once. It looks like `nsk_…`.

## 2. Connect Claude

**Claude Code (CLI / desktop app terminal)** — one command:

```bash
claude mcp add --transport http meridian https://meridianeclat.shop/mcp --header "Authorization: Bearer nsk_YOUR_TOKEN"
```

**Claude Desktop / Cowork** — add to the MCP config (Settings → Developer →
Edit config), bridging the bearer header with `mcp-remote`:

```json
{
  "mcpServers": {
    "meridian": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://meridianeclat.shop/mcp",
               "--header", "Authorization: Bearer nsk_YOUR_TOKEN"]
    }
  }
}
```

> claude.ai's web "custom connectors" currently require OAuth; this server uses
> bearer tokens, so use Claude Code or the desktop config above.

## 3. What Claude can do

| Tool | What it does |
|---|---|
| `list_categories` | Categories with id/slug/name — use before creating |
| `search_products` / `get_product` | Find by name, SKU, slug or id |
| `create_product` | New product (draft by default): name, price, category, descriptions, stock, tags, SEO |
| `update_product` | Change any of the above, `status=published` to go live, featured/bestseller flags |
| `upload_product_image` | From a public image URL → WebP, watermark (if on), responsive variant |
| `set_primary_image` / `delete_product_image` | Arrange photos |
| `delete_product` | Archive (soft delete, restorable in admin) — needs `confirm=true` |

Typical new-item flow: `list_categories → create_product → upload_product_image
(primary) → update_product status=published`.

The same operations exist as REST under `/api/v1/` (see `routes/api.php`) for
scripts. Both are throttled to 60 requests/minute and audited by token.

**Not yet exposed** (edit in admin): variants/options, quantity offers,
content sections, orders, customers.

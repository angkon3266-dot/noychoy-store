# How the Claude connector is built — a blueprint to reuse

This is how this store became something Claude can run, written so the same
thing can be built into another Laravel e-commerce site. The owner-facing setup
guide is `docs/CLAUDE-MCP.md`; this page is for whoever builds it.

## 1. The idea in one paragraph

Claude talks to outside systems through **MCP** (Model Context Protocol). The
store publishes an MCP server at `https://<domain>/mcp`: a single URL that
answers JSON-RPC over HTTP POST. Claude asks it for its list of **tools**
(each with a name, a description and a JSON schema of its arguments), then calls
them. Each tool is a thin wrapper around code the store **already has** — the
admin controllers, services and actions — so Claude follows exactly the same
rules as a person using the admin panel. A token (or a claude.ai sign-in) says
**which admin user** Claude acts as, and that user's role decides which tools
exist for it. Every call is written to an audit log.

## 2. The pieces

| Piece | File(s) | What it does |
|---|---|---|
| MCP library | `laravel/mcp` (composer) | Laravel's official package: the HTTP transport, JSON-RPC, protocol-version negotiation, tool schemas, annotations, test helpers |
| Server | `app/Mcp/StoreServer.php` | Lists the tools, sets the instructions Claude reads on connect, pagination 100 so every tool fits on one page |
| Tool base class | `app/Mcp/Tools/StoreTool.php` | Every tool extends it. Does the role check (`$section`), the audit log row, and turns mistakes into readable tool errors |
| Tools | `app/Mcp/Tools/{Products,Catalog,Orders,Customers,Marketing,Reviews,Reports}/` | One class per tool |
| Admin-controller bridge | `app/Mcp/Support/AdminAction.php` | Runs an existing admin controller action (e.g. `CouponController@store`) as the token's user with a throwaway request and in-memory session, and reads back the flash message — so validation and side effects are reused, not copied |
| Readable errors | `app/Mcp/ToolError.php` | Thrown for things Claude can fix ("no product matches X, try search_products") |
| Token auth | `app/Http/Middleware/AuthenticateApiToken.php`, `app/Models/ApiToken.php` | Bearer token → `ApiToken` (only a SHA-256 is stored) → its `user` → `Auth::setUser()` for this request |
| OAuth server (for claude.ai) | `app/Http/Controllers/OAuth/OAuthController.php`, `routes/oauth.php`, `resources/views/oauth/*` | Discovery, client registration, the Allow page, code + PKCE → token, refresh |
| Audit log | `app/Models/McpToolCall.php`, table `mcp_tool_calls` | One row per call: tool, arguments, ok/refused, error, duration, user, connection |
| Admin page | `app/Http/Controllers/Admin/ApiTokenController.php`, `resources/views/admin/api-tokens.blade.php`, `resources/views/admin/connector-blueprint.blade.php` | URL + setup, connections, activity, this blueprint |
| Database | `database/migrations/2026_11_24_100000_claude_connector.php` | `api_tokens.user_id/kind/client_id/refresh_hash/refresh_expires_at`, `oauth_clients`, `oauth_auth_codes`, `mcp_tool_calls` |
| Routes | `routes/api.php` (`Mcp::web('mcp', StoreServer::class)`), `routes/oauth.php`, both registered in `bootstrap/app.php` outside the `web` group | No session or CSRF on `/mcp`, `/oauth/token`, `/oauth/register` |
| Tests | `tests/Feature/Mcp/` | Auth, OAuth flow, role scoping, every tool, the admin page |

## 3. A request, end to end

1. Claude `POST /mcp` with `Authorization: Bearer <token>` and a JSON-RPC body
   such as `{"method":"tools/call","params":{"name":"update_product","arguments":{...}}}`.
2. `AuthenticateApiToken` hashes the token, finds the `ApiToken`, loads its user,
   refuses if the user no longer has an admin role, then `Auth::setUser($user)`.
   No token → **401** with a `WWW-Authenticate` header pointing at
   `/.well-known/oauth-protected-resource/mcp` (that is how claude.ai discovers
   it must sign in).
3. `laravel/mcp` finds the tool. Tools whose `shouldRegister()` is false for this
   user (wrong role) are invisible: not listed, "not found" if called.
4. `StoreTool::handle()` re-checks the role, runs the tool's `run()`, and writes
   the audit row whatever happens.
5. The tool calls existing store code — `CatalogApi`, `TransitionOrderStatus`,
   `SmsService`, or an admin controller through `AdminAction` — and returns JSON
   as the tool result. A `ToolError` or validation failure comes back as
   `isError: true` with a message that says what to do next.

## 4. Writing a tool

```php
namespace App\Mcp\Tools\Orders;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]                        // or #[IsDestructive], #[IsIdempotent], #[IsOpenWorld]
class GetOrder extends OrderTool     // OrderTool extends StoreTool with $section = 'orders'
{
    protected string $name = 'get_order';
    protected string $description = 'One order in full: items, address, payment, history.';

    public function schema(JsonSchema $schema): array
    {
        return ['order' => $schema->string()->description('Order number or id.')->required()];
    }

    protected function run(Request $request): Response
    {
        $order = $this->order((string) $this->need($request, 'order'));
        return $this->json([...]);
    }
}
```

Then add the class to `StoreServer::$tools`. Rules that kept this one safe:

- **Reuse, never re-implement.** If the admin panel already saves the thing,
  call that controller through `AdminAction::run(Controller::class, 'method',
  $formFields, ['model' => $model])`. Forms post every field, so for updates
  start from the model's current values and overlay what Claude passed.
- **Accept what an agent knows.** Products by id, slug or SKU; customers by phone
  in any format; categories by id, slug or name. Errors name the tool that finds
  the right value.
- **Destructive or customer-facing** (delete, order status, SMS, replacing the
  menu): say so in capitals at the start of the description, add
  `#[IsDestructive]`, and call `$this->requireConfirm($request, '…')` so it
  refuses without `confirm=true`. Claude then asks the owner first.
- **Side effects stay explicit.** Changing an order's status here never texts the
  customer and never books a courier; those are separate decisions.
- Keep list tools compact (summaries) and give a `get_…` tool for the full record.

## 5. Roles

`User::sectionsFor($role)` already decides which admin sections each role opens
(admin = everything, manager = no settings/users, staff = orders). Each tool
declares one `$section`; `StoreTool::allows()` is `$user->canAccess($section)`.
A token is always created **as** a user (the admin who made it, or the person
who clicked Allow), so a staff member's connection can only ever see order tools.

## 6. Sign-in for claude.ai (OAuth 2.1, no Passport)

claude.ai's "custom connector" cannot send a pasted header, so the server is also
a tiny OAuth authorization server. Endpoints:

| Endpoint | Spec | Purpose |
|---|---|---|
| `GET /.well-known/oauth-protected-resource[/mcp]` | RFC 9728 | "This resource is `https://<domain>/mcp`, its auth server is `https://<domain>`" |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 | Where to register, authorize and get tokens; PKCE S256 only |
| `POST /oauth/register` | RFC 7591 | claude.ai registers itself; redirect URIs must be https (or http on localhost) |
| `GET /oauth/authorize` | OAuth 2.1 | Not logged in → admin login, then back. Shows the Allow page naming the user and role |
| `POST /oauth/authorize` | | Allow → one-time code (10 min, hash stored) → redirect to Claude with `code` + `state` |
| `POST /oauth/token` | OAuth 2.1 + PKCE | Code + `code_verifier` → access token (8 h) + refresh token (90 d, rotated on every use) |

The access token is a normal `ApiToken` row with `kind = oauth`, so revoking it in
the admin ends the connection, and the same middleware, role check and audit
apply. The route names `mcp.oauth.protected-resource*` matter: `laravel/mcp`'s
401 middleware looks for them to fill the `WWW-Authenticate` header.

## 7. Porting it to another Laravel store — step by step

1. `composer require laravel/mcp:^1.2` (Laravel 12.41+ or 13).
2. Copy `app/Mcp/`, `app/Http/Controllers/OAuth/`, `routes/oauth.php`,
   `resources/views/oauth/`, the middleware, the three models
   (`OAuthClient`, `OAuthAuthCode`, `McpToolCall`) and the migration. If the
   other site has no `api_tokens` table, take this store's original
   `2026_08_16_100000_create_api_tokens_table.php` and `ApiToken` model too.
3. Register the routes outside the `web` group in `bootstrap/app.php`
   (`routes/api.php` with `Mcp::web('mcp', StoreServer::class)->middleware(['api.token', 'throttle:120,1'])`,
   and `routes/oauth.php`), and alias `api.token` to the middleware.
4. Give users a role → sections map like `User::sectionsFor()` / `canAccess()`,
   or point `StoreTool::allows()` at whatever permission system that site has.
5. **Rewrite each tool's `run()` against that site's own models, controllers and
   services** — this is the only part that is really per-store. Start with
   read-only tools (`store_info`, `search_products`, `list_orders`), then writes.
6. Copy the admin page (`ApiTokenController`, `api-tokens.blade.php`), add it to
   the admin menu for administrators.
7. Copy `tests/Feature/Mcp/`, adjust factories and models, make them pass.
8. Deploy; then check `https://<domain>/.well-known/oauth-protected-resource/mcp`
   returns JSON, `POST /mcp` without a token returns 401 with
   `WWW-Authenticate`, and a token can call `store_info`.
9. Behind a proxy or page cache: never cache `/mcp`, `/oauth/*` or
   `/.well-known/*`, and make sure the `Authorization` header reaches PHP
   (Apache/LiteSpeed: the `RewriteRule .* - [E=HTTP_AUTHORIZATION:...]` line in
   `public/.htaccess`).

## 8. A prompt to hand Claude Code in the other project

> Build a remote MCP server into this Laravel store so Claude can run it, following
> the blueprint below (pasted from the NoyChoy store). Use `laravel/mcp`. Tokens act
> as an admin user and every tool is scoped to that user's role; every call goes into
> an audit log the owner can see; destructive or customer-facing tools say so, carry
> `#[IsDestructive]` and need `confirm=true`. Add the small OAuth 2.1 server
> (discovery, dynamic client registration, PKCE, rotating refresh) so claude.ai's
> custom connector can sign in through the admin login. Wrap this store's **existing**
> controllers and services — do not re-implement business rules. Start by mapping its
> admin sections, models and controllers to the tool list, show me that map, then
> build, with feature tests for auth, role scoping and every tool, and an admin page
> showing the URL, setup steps, connections and the audit log.

## 9. Things learned building this one

- claude.ai needs OAuth; Claude Code and anything else can use a pasted bearer token.
- `laravel/mcp` lists 15 tools per page by default — raise
  `$defaultPaginationLength`, some clients never ask for page two.
- Tool mistakes should be tool results with `isError: true`, not JSON-RPC
  errors: Claude reads the message and corrects itself.
- Admin forms post every field (unticked checkboxes are simply absent); when a tool
  reuses a controller for an update, send the saved values plus the changes.
- Log integration failures at **error** level if production logs at error.
- On a big test suite, run it in chunks; the "M" chunk here needed
  `memory_limit` 512M once these tests joined it.

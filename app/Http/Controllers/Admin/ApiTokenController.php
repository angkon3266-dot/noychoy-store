<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\McpToolCall;
use Illuminate\Http\Request;

/**
 * Admin → Claude connector (route name kept as `api-tokens`, an admin-only
 * section): the connector URL with setup steps for each Claude app, every
 * connection (pasted tokens and claude.ai sign-ins) with who it acts as, and
 * the audit log of every tool Claude has called.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $tokenFilter = $request->integer('token') ?: null;

        return view('admin.api-tokens', [
            'tokens' => ApiToken::with('user')->latest()->get(),
            'mcpUrl' => url('/mcp'),
            'calls' => McpToolCall::with('user')
                ->when($tokenFilter, fn ($q) => $q->where('api_token_id', $tokenFilter))
                ->latest('id')->paginate(50)->withQueryString(),
            'tokenFilter' => $tokenFilter,
            'callsToday' => McpToolCall::where('created_at', '>=', now()->subDay())->count(),
            'failedToday' => McpToolCall::where('created_at', '>=', now()->subDay())->where('ok', false)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        // A token acts as the admin who makes it — their role is its reach.
        [, $plain] = ApiToken::issue(
            $data['name'],
            null,
            filled($data['expires_days'] ?? null) ? now()->addDays((int) $data['expires_days']) : null,
            $request->user(),
        );

        // Flashed, not stored: this is the only time the plaintext exists
        // outside the caller's clipboard.
        return back()->with('new_api_token', $plain)
            ->with('success', 'Token created. Copy it now — it cannot be shown again.');
    }

    public function destroy(ApiToken $apiToken)
    {
        $apiToken->delete();

        return back()->with('success', 'Disconnected. Anything using that connection stops working immediately.');
    }
}

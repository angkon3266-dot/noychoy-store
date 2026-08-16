<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function index()
    {
        return view('admin.api-tokens', [
            'tokens' => ApiToken::latest()->get(),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        [, $plain] = ApiToken::issue(
            $data['name'],
            null,
            filled($data['expires_days'] ?? null) ? now()->addDays((int) $data['expires_days']) : null,
        );

        // Flashed, not stored: this is the only time the plaintext exists
        // outside the caller's clipboard.
        return back()->with('new_api_token', $plain)
            ->with('success', 'Token created. Copy it now — it cannot be shown again.');
    }

    public function destroy(ApiToken $apiToken)
    {
        $apiToken->delete();

        return back()->with('success', 'Token revoked. Anything using it stops working immediately.');
    }
}

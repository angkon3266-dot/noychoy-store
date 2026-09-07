<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantService;
use Illuminate\Http\Request;

/**
 * The chat widget's one endpoint. The browser keeps the transcript and sends
 * the recent turns each time; nothing is stored server-side, and nothing the
 * customer types is logged.
 */
class AssistantController extends Controller
{
    public function chat(Request $request, AssistantService $assistant)
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.AssistantService::MAX_TURNS],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.AssistantService::MAX_CHARS],
            'page' => ['nullable', 'string', 'max:200'],
        ]);

        $messages = array_values(array_map(
            fn ($m) => ['role' => $m['role'], 'content' => trim((string) $m['content'])],
            $data['messages'],
        ));

        if (end($messages)['role'] !== 'user') {
            return response()->json(['message' => 'The last message must be the customer\'s.'], 422);
        }

        if (! $assistant->enabled()) {
            return response()->json(['ok' => false, 'reply' => $assistant->offlineText(), 'products' => []], 503);
        }

        $result = $assistant->reply($messages, $data['page'] ?? null);

        return response()->json([
            'ok' => $result['ok'],
            'reply' => $result['reply'],
            'products' => $result['products'],
        ], $result['ok'] ? 200 : 502);
    }
}

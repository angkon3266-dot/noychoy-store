<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ConversationLog;
use Illuminate\Http\Request;

/**
 * The chat widget's one endpoint. The browser keeps the transcript and sends
 * the recent turns each time; the server now also appends each exchange to
 * assistant_conversations, so the store can read the questions people ask
 * (Admin → Chat history). The widget's own `cid` groups the turns.
 */
class AssistantController extends Controller
{
    public function chat(Request $request, AssistantService $assistant, ConversationLog $log)
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.AssistantService::MAX_TURNS],
            'messages.*.role' => ['required', 'in:user,assistant'],
            // Earlier turns include the assistant's own replies, which can run
            // long; only the customer's new message is held to MAX_CHARS below.
            'messages.*.content' => ['required', 'string', 'max:'.AssistantService::MAX_TRANSCRIPT_CHARS],
            'page' => ['nullable', 'string', 'max:200'],
            'cid' => ['nullable', 'string', 'max:64'],
        ]);

        $messages = array_values(array_map(
            fn ($m) => ['role' => $m['role'], 'content' => trim((string) $m['content'])],
            $data['messages'],
        ));

        $last = end($messages);
        if ($last['role'] !== 'user') {
            return response()->json(['message' => 'The last message must be the customer\'s.'], 422);
        }
        if (mb_strlen($last['content']) > AssistantService::MAX_CHARS) {
            return response()->json(['message' => 'Please keep a message under '.AssistantService::MAX_CHARS.' characters.'], 422);
        }

        if (! $assistant->enabled()) {
            return response()->json(['ok' => false, 'reply' => $assistant->offlineText(), 'products' => []], 503);
        }

        $result = $assistant->reply($messages, $data['page'] ?? null);

        // Written after the reply, so a slow or failing model never costs the
        // customer a round trip. record() cannot throw.
        $log->record($request, (string) ($data['cid'] ?? ''), $last['content'], $result);

        return response()->json([
            'ok' => $result['ok'],
            'reply' => $result['reply'],
            'products' => $result['products'],
        ], $result['ok'] ? 200 : 502);
    }
}

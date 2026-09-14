<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ChatGuard;
use App\Services\Ai\ConversationLog;
use Illuminate\Http\Request;

/**
 * The chat widget's one endpoint. The browser keeps the transcript and sends
 * the recent turns each time; the server now also appends each exchange to
 * assistant_conversations, so the store can read the questions people ask
 * (Admin → Chat history). The widget's own `cid` groups the turns.
 *
 * {@see ChatGuard} stands in front of the model: a message with nothing in it
 * to answer is turned around here, before OpenAI is paid, and a session that
 * produces nothing else is closed for the rest of that browser session.
 */
class AssistantController extends Controller
{
    public function chat(Request $request, AssistantService $assistant, ConversationLog $log, ChatGuard $guard)
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

        // A chat already closed: say so once more and write nothing. The
        // widget locks its own box on the first of these, so reaching here
        // again means something other than the widget is still posting.
        if ($guard->isBlocked()) {
            return response()->json([
                'ok' => true, 'blocked' => true,
                'reply' => $guard->blockedText(), 'products' => [],
            ]);
        }

        // Nothing in it to answer — a nudge, no OpenAI call, and one against
        // the run. Still written down, so the owner can see why a chat ended.
        if ($guard->enabled() && $guard->looksJunk($last['content'], $messages)) {
            $closed = $guard->strike();
            $result = [
                'ok' => true,
                'reply' => $closed ? $guard->blockedText() : $guard->nudgeText(),
                'products' => [],
                'error' => null,
            ];
            $log->record($request, (string) ($data['cid'] ?? ''), $last['content'], $result, $closed);

            return response()->json([
                'ok' => true,
                'reply' => $result['reply'],
                'products' => [],
                'blocked' => $closed,
            ]);
        }

        $result = $assistant->reply($messages, $data['page'] ?? null);

        // The model says she has wandered off the shop's business. It answered
        // her anyway — that reply is paid for and perfectly polite — but a run
        // of them closes the chat just as gibberish does.
        $closed = false;
        if ($result['ok'] && ($result['offtopic'] ?? false)) {
            $closed = $guard->strike();
            if ($closed) {
                $result['reply'] = $guard->blockedText();
                $result['products'] = [];
            }
        } elseif ($result['ok']) {
            $guard->pass();
        }

        // Written after the reply, so a slow or failing model never costs the
        // customer a round trip. record() cannot throw.
        $log->record($request, (string) ($data['cid'] ?? ''), $last['content'], $result, $closed);

        return response()->json([
            'ok' => $result['ok'],
            'reply' => $result['reply'],
            'products' => $result['products'],
            'blocked' => $closed,
        ], $result['ok'] ? 200 : 502);
    }
}

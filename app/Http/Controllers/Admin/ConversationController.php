<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Http\Request;

/**
 * What customers ask the storefront assistant.
 *
 * The point of the list is not the conversations themselves but the questions
 * inside them: what people look for, what wording they use, and where the
 * assistant runs out of answers — which is why "couldn't answer" is a filter
 * and the search runs over message text rather than over conversation headers.
 */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', '');

        $conversations = AssistantConversation::query()
            ->with('customer:id,name,phone')
            ->when($q !== '', fn ($b) => $b->whereHas(
                'messages',
                fn ($m) => $m->where('content', 'like', '%'.$q.'%'),
            ))
            ->when($filter === 'failed', fn ($b) => $b->where('had_failure', true))
            ->when($filter === 'members', fn ($b) => $b->whereNotNull('customer_id'))
            ->orderByDesc('last_message_at')
            ->paginate(25)
            ->withQueryString();

        // The opening question, for every row on this page in one query — the
        // list is a wall of text without it, and N+1 without this.
        $openers = AssistantMessage::query()
            ->whereIn('assistant_conversation_id', $conversations->pluck('id'))
            ->where('role', 'user')
            ->orderBy('id')
            ->get(['assistant_conversation_id', 'content'])
            ->groupBy('assistant_conversation_id')
            ->map(fn ($rows) => (string) $rows->first()->content);

        return view('admin.conversations.index', [
            'conversations' => $conversations,
            'openers' => $openers,
            'q' => $q,
            'filter' => $filter,
            'totals' => [
                'all' => AssistantConversation::count(),
                'failed' => AssistantConversation::where('had_failure', true)->count(),
                'week' => AssistantConversation::where('last_message_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function show(AssistantConversation $conversation)
    {
        $conversation->load(['customer:id,name,phone', 'messages' => fn ($m) => $m->orderBy('id')]);

        return view('admin.conversations.show', ['conversation' => $conversation]);
    }

    public function destroy(AssistantConversation $conversation)
    {
        $conversation->delete();

        return redirect()->route('admin.conversations.index')->with('success', 'Conversation deleted.');
    }
}

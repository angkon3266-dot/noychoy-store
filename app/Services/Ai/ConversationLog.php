<?php

namespace App\Services\Ai;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Appends each chat turn to the stored transcript.
 *
 * Everything here is best-effort: a customer asking a question must never see
 * an error because the store failed to write it down, so record() swallows its
 * own failures and the chat carries on.
 */
class ConversationLog
{
    /** Longest single message kept, matching what the widget can send. */
    public const MAX_CHARS = 4000;

    /**
     * Record one exchange: the customer's new message and the reply it drew.
     *
     * @param  string  $uid       the widget's per-session conversation id
     * @param  string  $question  the customer's message
     * @param  array   $result    AssistantService::reply()'s return value
     */
    public function record(Request $request, string $uid, string $question, array $result): ?AssistantConversation
    {
        try {
            $uid = substr(trim($uid), 0, 64);
            if ($uid === '') {
                return null;
            }

            $page = substr((string) $request->input('page', ''), 0, 200) ?: null;
            $ok = (bool) ($result['ok'] ?? false);

            $conversation = AssistantConversation::firstOrNew(['uid' => $uid]);
            $conversation->fill([
                // A guest who logs in mid-chat should stop reading as a guest.
                'customer_id' => auth('customer')->id() ?: $conversation->customer_id,
                'visitor_token' => $conversation->visitor_token ?: substr((string) $request->cookie('visitor_token'), 0, 64) ?: null,
                'first_page' => $conversation->first_page ?: $page,
                'last_page' => $page,
                'ua' => $conversation->ua ?: substr((string) $request->userAgent(), 0, 255),
                'last_message_at' => now(),
                'had_failure' => $conversation->had_failure || ! $ok,
            ]);
            $conversation->save();

            $this->append($conversation, 'user', $question, null, true);
            $this->append($conversation, 'assistant', (string) ($result['reply'] ?? ''), $result['products'] ?? null, $ok);

            $conversation->forceFill([
                'message_count' => $conversation->messages()->count(),
            ])->saveQuietly();

            return $conversation;
        } catch (\Throwable $e) {
            // Never let bookkeeping break the conversation itself.
            Log::warning('Assistant transcript not stored', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function append(AssistantConversation $conversation, string $role, string $content, ?array $products, bool $ok): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        AssistantMessage::create([
            'assistant_conversation_id' => $conversation->id,
            'role' => $role,
            'content' => mb_substr($content, 0, self::MAX_CHARS),
            'products' => $products ?: null,
            'ok' => $ok,
            'created_at' => now(),
        ]);
    }

    /**
     * Drop transcripts older than the retention window. Customer messages are
     * kept to answer "what are people asking this month", not forever.
     */
    public function prune(int $days = 180): int
    {
        return AssistantConversation::where('last_message_at', '<', now()->subDays($days))->delete();
    }
}

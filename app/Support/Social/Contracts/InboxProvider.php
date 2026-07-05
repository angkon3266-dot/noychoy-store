<?php

namespace App\Support\Social\Contracts;

/**
 * Provider-agnostic conversations/comments source for the Unified Inbox.
 */
interface InboxProvider
{
    /** Fetch recent conversations/threads for syncing into the local inbox. */
    public function fetchConversations(int $limit = 50): array;

    /** Send a reply to a conversation/comment. */
    public function reply(string $conversationId, string $message): array;
}

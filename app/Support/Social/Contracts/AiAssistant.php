<?php

namespace App\Support\Social\Contracts;

/**
 * Platform-agnostic AI text generation. Implemented by the AI module; consumed
 * by Publishing / Inbox / Commerce via the container so no module depends on the
 * AI module's concrete class. The underlying model (Claude, etc.) is itself a
 * swappable driver behind this interface.
 */
interface AiAssistant
{
    public function caption(string $context, array $options = []): string;

    public function hashtags(string $context, int $count = 10): array;

    public function adCopy(string $context, array $options = []): string;

    public function replySuggestion(string $conversation, array $options = []): string;

    public function productDescription(string $productInfo, array $options = []): string;

    public function seo(string $context, array $options = []): array;

    public function summarize(string $text, array $options = []): string;
}

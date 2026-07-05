<?php

namespace App\Support\Social\Contracts;

/**
 * Provider-agnostic content publishing (feed post, carousel, reel, story).
 * Consumed by the Publishing and Automation modules via the container, never by
 * importing a concrete platform class.
 */
interface SocialPublisher
{
    /**
     * Publish content to a target asset (page/IG account).
     *
     * @param  array  $payload  ['type'=>'photo|carousel|reel|story|text', 'caption'=>..,'media'=>[urls], 'target'=>assetId]
     * @return array  ['ok'=>bool, 'external_id'=>?string, 'error'=>?string]
     */
    public function publish(array $payload): array;

    /** Whether the given content type is supported by this provider. */
    public function supports(string $type): bool;
}

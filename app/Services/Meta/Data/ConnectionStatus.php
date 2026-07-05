<?php

namespace App\Services\Meta\Data;

/**
 * Immutable result of a "Test Connection" check. Aggregates the individual
 * verification steps so the UI can render a single clear verdict plus detail.
 */
final class ConnectionStatus
{
    /**
     * @param  array<int, array{label:string, ok:bool, detail:?string}>  $checks
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $code,      // connected|invalid_token|token_expired|missing_permission|catalog_not_found|connection_failed
        public readonly string $message,
        public readonly array $checks = [],
        public readonly ?string $businessName = null,
        public readonly ?string $catalogName = null,
        public readonly ?int $productCount = null,
        public readonly array $scopes = [],
    ) {}

    public static function connected(string $message, array $checks, ?string $business, ?string $catalog, ?int $count, array $scopes): self
    {
        return new self(true, 'connected', $message, $checks, $business, $catalog, $count, $scopes);
    }

    public static function failed(string $code, string $message, array $checks = []): self
    {
        return new self(false, $code, $message, $checks);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'code' => $this->code,
            'message' => $this->message,
            'checks' => $this->checks,
            'business_name' => $this->businessName,
            'catalog_name' => $this->catalogName,
            'product_count' => $this->productCount,
            'scopes' => $this->scopes,
        ];
    }
}

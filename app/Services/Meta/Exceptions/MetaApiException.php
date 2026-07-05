<?php

namespace App\Services\Meta\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown for any failure talking to the Meta Graph API. Carries a machine
 * "category" so callers (jobs, UI) can react without string-matching messages
 * and decide whether a retry is worthwhile.
 */
class MetaApiException extends RuntimeException
{
    public const TOKEN_INVALID = 'token_invalid';
    public const TOKEN_EXPIRED = 'token_expired';
    public const PERMISSION = 'permission';
    public const RATE_LIMIT = 'rate_limit';
    public const NETWORK = 'network';
    public const CATALOG = 'catalog';
    public const VALIDATION = 'validation';
    public const API_DOWN = 'api_down';
    public const UNKNOWN = 'unknown';

    public function __construct(
        string $message,
        public readonly string $category = self::UNKNOWN,
        public readonly ?array $meta = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Whether a queued job should retry after this error. */
    public function isRetryable(): bool
    {
        return in_array($this->category, [
            self::RATE_LIMIT,
            self::NETWORK,
            self::API_DOWN,
        ], true);
    }

    /**
     * Classify a Meta Graph API error payload into one of our categories.
     * Meta returns { error: { code, error_subcode, message, type } }.
     */
    public static function fromGraphError(array $error, int $httpStatus = 0): self
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['message'] ?? 'Unknown Meta API error');

        $category = match (true) {
            $httpStatus >= 500 => self::API_DOWN,
            in_array($code, [4, 17, 32, 613], true) || $code === 80004 => self::RATE_LIMIT,
            $code === 190 && in_array($subcode, [463, 467], true) => self::TOKEN_EXPIRED,
            $code === 190 => self::TOKEN_INVALID,
            in_array($code, [10, 200, 299], true) => self::PERMISSION,
            $code === 803 || str_contains(strtolower($message), 'catalog') => self::CATALOG,
            $code === 100 => self::VALIDATION,
            default => self::UNKNOWN,
        };

        return new self($message, $category, $error);
    }
}

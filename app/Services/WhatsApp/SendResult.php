<?php

namespace App\Services\WhatsApp;

/**
 * Outcome of a Graph API call. The client never throws on API/network errors;
 * callers check ->successful() and decide what to do.
 */
final readonly class SendResult
{
    /** Meta: message failed because the customer's 24h service window is closed. */
    public const ERROR_OUTSIDE_SERVICE_WINDOW = 131047;

    /** Meta: access token expired or invalid. */
    public const ERROR_INVALID_TOKEN = 190;

    /**
     * @param  array<string, mixed>  $response  Decoded Graph API response body
     */
    private function __construct(
        public bool $success,
        public ?string $waMessageId,
        public ?int $httpStatus,
        public ?int $errorCode,
        public ?string $errorMessage,
        public array $response,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function success(?string $waMessageId, int $httpStatus, array $response): self
    {
        return new self(true, $waMessageId, $httpStatus, null, null, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function failure(string $errorMessage, ?int $errorCode = null, ?int $httpStatus = null, array $response = []): self
    {
        return new self(false, null, $httpStatus, $errorCode, $errorMessage, $response);
    }

    public function successful(): bool
    {
        return $this->success;
    }

    public function failed(): bool
    {
        return ! $this->success;
    }

    public function isOutsideServiceWindow(): bool
    {
        return $this->errorCode === self::ERROR_OUTSIDE_SERVICE_WINDOW;
    }

    public function isInvalidToken(): bool
    {
        return $this->errorCode === self::ERROR_INVALID_TOKEN;
    }
}

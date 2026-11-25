<?php

namespace App\Services\WhatsApp;

/**
 * Outcome of telling a customer about their order, and which channel was used.
 */
final readonly class OrderNotification
{
    public const VIA_TEXT = 'text';

    public const VIA_TEMPLATE = 'template';

    public function __construct(
        public SendResult $result,
        public ?string $via,
    ) {}

    public function successful(): bool
    {
        return $this->result->successful();
    }

    public function viaTemplate(): bool
    {
        return $this->via === self::VIA_TEMPLATE;
    }
}

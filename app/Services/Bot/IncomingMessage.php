<?php

namespace App\Services\Bot;

use App\Services\WhatsApp\PhoneNumber;
use Illuminate\Support\Str;

/**
 * A webhook message reduced to what the bot cares about.
 */
final readonly class IncomingMessage
{
    /**
     * Words that restart the flow from any step.
     *
     * @var array<int, string>
     */
    private const RESET_WORDS = [
        'menu', 'main menu', 'start', 'restart', 'reset',
        'hi', 'hii', 'hello', 'hey', 'salam', 'salaam', 'aoa',
        'assalam o alaikum', 'assalamualaikum', 'assalam alaikum', 'asalam o alaikum',
    ];

    /**
     * Message types that should never move the conversation.
     *
     * @var array<int, string>
     */
    private const IGNORED_TYPES = ['reaction', 'system', 'unsupported', 'ephemeral'];

    public function __construct(
        public string $from,
        public string $type,
        public ?string $text,
        public ?string $replyId,
        public ?string $waMessageId,
        public ?string $contactName,
    ) {}

    /**
     * @param  array<string, mixed>  $message  One item of value.messages[]
     * @param  array<int, array<string, mixed>>  $contacts  value.contacts[]
     */
    public static function fromWebhook(array $message, array $contacts = []): self
    {
        $type = (string) ($message['type'] ?? 'unknown');
        $interactive = $message['interactive'] ?? [];
        $interactiveReply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? null;

        $text = match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null, // quick-reply button on a template
            'interactive' => $interactiveReply['title'] ?? null,
            default => null,
        };

        return new self(
            from: PhoneNumber::normalize((string) ($message['from'] ?? '')),
            type: $type,
            text: $text === null ? null : trim((string) $text),
            replyId: $interactiveReply['id'] ?? $message['button']['payload'] ?? null,
            waMessageId: $message['id'] ?? null,
            contactName: $contacts[0]['profile']['name'] ?? null,
        );
    }

    public function isIgnorable(): bool
    {
        return $this->from === '' || in_array($this->type, self::IGNORED_TYPES, true);
    }

    public function isTypedText(): bool
    {
        return $this->type === 'text' && $this->text !== null && $this->text !== '';
    }

    /**
     * Lower-cased text with punctuation stripped, e.g. "Menu!" -> "menu".
     */
    public function normalizedText(): string
    {
        $text = Str::lower((string) $this->text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    public function isResetCommand(): bool
    {
        return $this->isTypedText() && in_array($this->normalizedText(), self::RESET_WORDS, true);
    }

    /**
     * Returns the part after the prefix for interactive ids like "product_12" -> "12".
     */
    public function replyIdAfter(string $prefix): ?string
    {
        if ($this->replyId === null || ! str_starts_with($this->replyId, $prefix)) {
            return null;
        }

        return substr($this->replyId, strlen($prefix));
    }
}

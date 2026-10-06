<?php

namespace App\Services\WhatsApp;

final readonly class InboundMessage
{
    public function __construct(
        public string $phoneNumberId,
        public string $waMessageId,
        public string $from,
        public ?string $profileName,
        public string $type,
        public string $text,
        public int $timestamp,
    ) {}
}

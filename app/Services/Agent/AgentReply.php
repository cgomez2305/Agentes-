<?php

namespace App\Services\Agent;

use App\Models\Message;

final readonly class AgentReply
{
    public function __construct(
        public ?Message $message,
        public string $outcome, // replied, drafted, handed_off, skipped
    ) {}
}

<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Agent\ConversationSummarizer;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Se ejecuta después de responder, fuera del camino crítico, para no sumar
 * latencia a la respuesta del cliente.
 */
class SummarizeConversation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public int $conversationId) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(ConversationSummarizer $summarizer, TenantContext $tenants): void
    {
        $conversation = Conversation::withoutGlobalScope('tenant')->with('tenant')->find($this->conversationId);

        if ($conversation) {
            $tenants->run($conversation->tenant, fn () => $summarizer->summarizeIfNeeded($conversation));
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentRuntime;
use App\Services\WhatsApp\OutboundSender;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RespondToConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $triggerMessageId,
    ) {}

    public function handle(AgentRuntime $runtime, OutboundSender $sender, TenantContext $tenants): void
    {
        $conversation = Conversation::withoutGlobalScope('tenant')->with('tenant')->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $tenants->run($conversation->tenant, function () use ($conversation, $runtime, $sender) {
            // Debounce: si llegó otro mensaje después del que disparó este job,
            // el job de ese mensaje responde a todos juntos.
            $newer = $conversation->messages()
                ->where('direction', Message::IN)
                ->where('id', '>', $this->triggerMessageId)
                ->exists();

            if ($newer) {
                return;
            }

            Cache::lock("conversation:{$conversation->id}:respond", 60)->block(20, function () use ($conversation, $runtime, $sender) {
                $runtime->handle($conversation->fresh());
                $sender->sendPending($conversation->fresh());
            });

            SummarizeConversation::dispatch($conversation->id);
        });
    }
}

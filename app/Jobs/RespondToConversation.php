<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentRuntime;
use App\Services\WhatsApp\WhatsAppClient;
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

    public function handle(AgentRuntime $runtime, WhatsAppClient $whatsapp, TenantContext $tenants): void
    {
        $conversation = Conversation::withoutGlobalScope('tenant')->with('tenant')->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $tenants->run($conversation->tenant, function () use ($conversation, $runtime, $whatsapp) {
            // Debounce: si llegó otro mensaje después del que disparó este job,
            // el job de ese mensaje responde a todos juntos.
            $newer = $conversation->messages()
                ->where('direction', Message::IN)
                ->where('id', '>', $this->triggerMessageId)
                ->exists();

            if ($newer) {
                return;
            }

            Cache::lock("conversation:{$conversation->id}:respond", 60)->block(20, function () use ($conversation, $runtime, $whatsapp) {
                $runtime->handle($conversation->fresh());
                $this->sendPending($conversation, $whatsapp);
            });
        });
    }

    private function sendPending(Conversation $conversation, WhatsAppClient $whatsapp): void
    {
        $conversation->refresh();
        $channel = $conversation->channel;

        $pending = $conversation->messages()
            ->where('direction', Message::OUT)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($pending as $message) {
            if (! $channel || ! $conversation->isWindowOpen()) {
                // Fuera de la ventana de 24 h solo se permiten plantillas aprobadas.
                $message->update(['status' => 'failed', 'meta' => [...($message->meta ?? []), 'error' => 'ventana_cerrada']]);

                continue;
            }

            try {
                $waId = $whatsapp->sendText($channel, $conversation->contact->wa_id, $message->content);
                $message->update(['status' => 'sent', 'wa_message_id' => $waId ?: null]);
            } catch (\Throwable $e) {
                report($e);
                $message->update(['status' => 'failed', 'meta' => [...($message->meta ?? []), 'error' => $e->getMessage()]]);
            }
        }
    }
}

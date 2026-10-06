<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\WebhookParser;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Guarda los mensajes entrantes del webhook y programa la respuesta con
 * debounce, para que una ráfaga de mensajes genere una sola llamada al LLM.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $payload) {}

    public function handle(WebhookParser $parser, WhatsAppClient $whatsapp, TenantContext $tenants): void
    {
        foreach ($parser->statuses($this->payload) as $status) {
            Message::withoutGlobalScope('tenant')
                ->where('wa_message_id', $status['wa_message_id'])
                ->update(['status' => $status['status']]);
        }

        foreach ($parser->messages($this->payload) as $inbound) {
            $channel = Channel::withoutGlobalScope('tenant')
                ->with('tenant')
                ->where('phone_number_id', $inbound->phoneNumberId)
                ->where('status', 'active')
                ->first();

            if (! $channel) {
                Log::warning('WhatsApp: mensaje para un número no registrado', ['phone_number_id' => $inbound->phoneNumberId]);

                continue;
            }

            $tenants->run($channel->tenant, fn () => $this->store($channel, $inbound, $whatsapp));
        }
    }

    private function store(Channel $channel, InboundMessage $inbound, WhatsAppClient $whatsapp): void
    {
        // Meta puede reenviar el mismo evento: el wamid es la llave de idempotencia.
        if (Message::withoutGlobalScope('tenant')->where('wa_message_id', $inbound->waMessageId)->exists()) {
            return;
        }

        $contact = Contact::firstOrCreate(['wa_id' => $inbound->from], ['name' => $inbound->profileName]);
        if ($contact->opted_out) {
            // Si vuelve a escribir, retoma la conversación por iniciativa propia.
            $contact->update(['opted_out' => false]);
        }

        $conversation = Conversation::where('contact_id', $contact->id)
            ->where('status', '!=', Conversation::STATUS_CLOSED)
            ->latest('id')
            ->first();

        if (! $conversation || ! $conversation->isWindowOpen()) {
            UsageRecord::forTenant($channel->tenant_id)->increment('conversations');
        }

        $conversation ??= Conversation::create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'agent_id' => $channel->tenant->agent?->id,
            'status' => Conversation::STATUS_BOT,
        ]);

        $receivedAt = now();
        $conversation->update([
            'last_inbound_at' => $receivedAt,
            'window_expires_at' => $receivedAt->copy()->addDay(),
        ]);

        $message = $conversation->messages()->create([
            'tenant_id' => $channel->tenant_id,
            'direction' => Message::IN,
            'author' => 'contact',
            'type' => $inbound->type,
            'content' => $inbound->text,
            'wa_message_id' => $inbound->waMessageId,
            'status' => 'received',
        ]);

        try {
            $whatsapp->markAsRead($channel, $inbound->waMessageId);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($conversation->status === Conversation::STATUS_BOT) {
            RespondToConversation::dispatch($conversation->id, $message->id)
                ->delay(now()->addSeconds(config('agentes.debounce_seconds')));
        }
    }
}

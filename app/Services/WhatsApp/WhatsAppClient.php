<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Envío de mensajes por la WhatsApp Cloud API de Meta.
 */
class WhatsAppClient
{
    /**
     * Envía un texto. Solo es válido dentro de la ventana de servicio de 24 h;
     * fuera de ella hay que usar sendTemplate().
     *
     * @return string ID del mensaje en WhatsApp (wamid).
     *
     * @throws RequestException
     */
    public function sendText(Channel $channel, string $to, string $body): string
    {
        return $this->send($channel, [
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     *
     * @throws RequestException
     */
    public function sendTemplate(Channel $channel, string $to, string $template, string $language = 'es', array $components = []): string
    {
        return $this->send($channel, [
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components,
            ]),
        ]);
    }

    /**
     * Marca el mensaje como leído (los dos chulos azules) y muestra "escribiendo...".
     */
    public function markAsRead(Channel $channel, string $waMessageId): void
    {
        $this->request($channel)->post($this->url($channel), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
            'typing_indicator' => ['type' => 'text'],
        ]);
    }

    private function send(Channel $channel, array $payload): string
    {
        $response = $this->request($channel)
            ->post($this->url($channel), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                ...$payload,
            ])
            ->throw();

        return (string) $response->json('messages.0.id');
    }

    private function request(Channel $channel): PendingRequest
    {
        return Http::withToken($channel->access_token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false);
    }

    private function url(Channel $channel): string
    {
        return sprintf(
            '%s/%s/%s/messages',
            rtrim(config('agentes.whatsapp.graph_url'), '/'),
            config('agentes.whatsapp.graph_version'),
            $channel->phone_number_id,
        );
    }
}

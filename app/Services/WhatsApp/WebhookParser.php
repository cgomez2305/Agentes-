<?php

namespace App\Services\WhatsApp;

/**
 * Extrae los mensajes entrantes y los cambios de estado del payload del webhook.
 */
class WebhookParser
{
    /**
     * @return list<InboundMessage>
     */
    public function messages(array $payload): array
    {
        $result = [];

        foreach ($this->values($payload) as $value) {
            $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id');
            $names = collect($value['contacts'] ?? [])->pluck('profile.name', 'wa_id');

            foreach ($value['messages'] ?? [] as $message) {
                $result[] = new InboundMessage(
                    phoneNumberId: $phoneNumberId,
                    waMessageId: (string) $message['id'],
                    from: (string) $message['from'],
                    profileName: $names[$message['from']] ?? null,
                    type: (string) ($message['type'] ?? 'unknown'),
                    text: $this->textOf($message),
                    timestamp: (int) ($message['timestamp'] ?? time()),
                );
            }
        }

        return $result;
    }

    /**
     * @return list<array{wa_message_id: string, status: string}>
     */
    public function statuses(array $payload): array
    {
        $result = [];

        foreach ($this->values($payload) as $value) {
            foreach ($value['statuses'] ?? [] as $status) {
                $result[] = ['wa_message_id' => (string) $status['id'], 'status' => (string) $status['status']];
            }
        }

        return $result;
    }

    private function values(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return [];
        }

        $values = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'messages' && isset($change['value'])) {
                    $values[] = $change['value'];
                }
            }
        }

        return $values;
    }

    /**
     * Texto legible del mensaje. Los tipos que el agente aún no procesa
     * (audio, imagen, ubicación...) se describen para que el modelo lo sepa.
     */
    private function textOf(array $message): string
    {
        return match ($message['type'] ?? null) {
            'text' => (string) data_get($message, 'text.body', ''),
            'button' => (string) data_get($message, 'button.text', ''),
            'interactive' => (string) (data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title', '')),
            'image', 'video', 'document' => trim('['.$message['type'].'] '.data_get($message, $message['type'].'.caption', '')),
            'audio' => '[nota de voz]',
            'location' => sprintf('[ubicación] %s, %s', data_get($message, 'location.latitude'), data_get($message, 'location.longitude')),
            default => '['.($message['type'] ?? 'desconocido').']',
        };
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use RuntimeException;

/**
 * Envía los mensajes salientes de una conversación y las respuestas que
 * escribe una persona desde la bandeja.
 */
class OutboundSender
{
    public function __construct(private readonly WhatsAppClient $whatsapp) {}

    /**
     * Envía, en orden, todos los mensajes salientes en estado "pending".
     */
    public function sendPending(Conversation $conversation): void
    {
        $conversation->messages()
            ->where('direction', Message::OUT)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get()
            ->each(fn (Message $message) => $this->deliver($conversation, $message));
    }

    /**
     * Respuesta escrita por una persona. Si el bot tenía la conversación, la
     * persona la toma para que no respondan los dos.
     *
     * @throws RuntimeException si la ventana de 24 h está cerrada.
     */
    public function replyAsHuman(Conversation $conversation, User $user, string $text): Message
    {
        $this->ensureWindowOpen($conversation);

        if ($conversation->status !== Conversation::STATUS_HUMAN) {
            $conversation->handToHuman("Tomada por {$user->name}", $user);
        }

        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'direction' => Message::OUT,
            'author' => 'human',
            'user_id' => $user->id,
            'type' => 'text',
            'content' => trim($text),
            'status' => 'pending',
            'source' => 'human',
        ]);

        return $this->deliver($conversation, $message);
    }

    /**
     * Aprueba un borrador del modo "sugerir", opcionalmente editado, y lo envía.
     */
    public function approveDraft(Message $draft, User $user, ?string $editedText = null): Message
    {
        $conversation = $draft->conversation;
        $this->ensureWindowOpen($conversation);

        $edited = $editedText !== null && trim($editedText) !== $draft->content;
        $draft->update([
            'content' => $edited ? trim($editedText) : $draft->content,
            'status' => 'pending',
            'user_id' => $user->id,
            'meta' => [...($draft->meta ?? []), 'approved_by' => $user->id, 'edited' => $edited],
        ]);

        return $this->deliver($conversation, $draft);
    }

    private function deliver(Conversation $conversation, Message $message): Message
    {
        $channel = $conversation->channel;

        // Fuera de la ventana de 24 h solo se permiten plantillas aprobadas.
        $blocker = match (true) {
            ! $channel => 'sin_numero',
            ! $conversation->isWindowOpen() => 'ventana_cerrada',
            default => null,
        };

        if ($blocker) {
            $message->update(['status' => 'failed', 'meta' => [...($message->meta ?? []), 'error' => $blocker]]);

            return $message;
        }

        try {
            $waId = $this->whatsapp->sendText($channel, $conversation->contact->wa_id, $message->content);
            $message->update(['status' => 'sent', 'wa_message_id' => $waId ?: null]);
        } catch (\Throwable $e) {
            report($e);
            $message->update(['status' => 'failed', 'meta' => [...($message->meta ?? []), 'error' => $e->getMessage()]]);
        }

        return $message;
    }

    private function ensureWindowOpen(Conversation $conversation): void
    {
        if (! $conversation->isWindowOpen()) {
            throw new RuntimeException('Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo permite escribirle con una plantilla aprobada.');
        }
    }
}

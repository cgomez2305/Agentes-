<?php

namespace App\Services\FollowUp;

use App\Models\Agent;
use App\Models\Appointment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Followup;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\UsageRecord;
use App\Services\Agent\OutputGuard;
use App\Services\Agent\PromptBuilder;
use App\Services\Llm\LlmProvider;
use App\Services\Llm\LlmRequest;
use App\Services\WhatsApp\OutboundSender;
use App\Services\WhatsApp\WhatsAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Seguimientos automáticos:
 *  - "nudge": un mensaje a quien dejó de responder, solo dentro de la ventana de 24 h.
 *  - "reminder": recordatorio de cita; fuera de la ventana usa una plantilla aprobada.
 * Cada seguimiento se registra con un ancla única para no repetirse.
 */
class FollowUpService
{
    /** Solo se escribe a los clientes entre estas horas locales. */
    private const SEND_FROM = 8;

    private const SEND_UNTIL = 20;

    public function __construct(
        private readonly LlmProvider $llm,
        private readonly PromptBuilder $prompts,
        private readonly OutputGuard $guard,
        private readonly OutboundSender $sender,
        private readonly WhatsAppClient $whatsapp,
    ) {}

    /**
     * @return array{nudges: int, reminders: int}
     */
    public function run(Tenant $tenant): array
    {
        $local = CarbonImmutable::now($tenant->timezone);

        if ($local->hour < self::SEND_FROM || $local->hour >= self::SEND_UNTIL) {
            return ['nudges' => 0, 'reminders' => 0];
        }

        return [
            'nudges' => $tenant->followup('nudge_enabled') ? $this->sendNudges($tenant) : 0,
            'reminders' => $tenant->followup('reminder_enabled') ? $this->sendReminders($tenant) : 0,
        ];
    }

    private function sendNudges(Tenant $tenant): int
    {
        $silentSince = now()->subHours((int) $tenant->followup('nudge_after_hours'));
        $sent = 0;

        $candidates = Conversation::query()
            ->with(['contact', 'agent', 'channel'])
            ->where('status', Conversation::STATUS_BOT)
            ->whereNotNull('channel_id')
            // Que quede al menos 1 hora de ventana para que el cliente alcance a responder gratis.
            ->where('window_expires_at', '>', now()->addHour())
            ->whereHas('contact', fn ($q) => $q->where('opted_out', false))
            ->get();

        foreach ($candidates as $conversation) {
            $last = $conversation->messages()
                ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
                ->latest('id')
                ->first();

            // Solo si lo último lo dijo el agente IA (no una regla ni una persona) y ya pasó el tiempo.
            $eligible = $last
                && $last->direction === Message::OUT
                && $last->author === 'bot'
                && $last->source === 'llm'
                && $last->created_at->lessThanOrEqualTo($silentSince)
                && $conversation->agent?->mode === Agent::MODE_AUTO;

            if (! $eligible || ! $this->claim($conversation, Followup::NUDGE, 'msg:'.$last->id)) {
                continue;
            }

            $text = $this->writeNudge($tenant, $conversation, $last);

            if ($text === null) {
                $this->finish($conversation, Followup::NUDGE, 'msg:'.$last->id, null, 'skipped', 'respuesta_invalida');

                continue;
            }

            $message = $this->deliverText($conversation, $text, 'followup');
            $this->finish($conversation, Followup::NUDGE, 'msg:'.$last->id, $message, $message->status === 'sent' ? 'sent' : 'failed');
            $sent += $message->status === 'sent' ? 1 : 0;
        }

        return $sent;
    }

    private function sendReminders(Tenant $tenant): int
    {
        $hours = (int) $tenant->followup('reminder_hours_before');
        $sent = 0;

        $appointments = Appointment::query()
            ->with('contact')
            ->where('status', Appointment::CONFIRMED)
            ->whereBetween('starts_at', [now()->addHour(), now()->addHours($hours)])
            ->whereHas('contact', fn ($q) => $q->where('opted_out', false))
            ->get();

        foreach ($appointments as $appointment) {
            $anchor = 'appt:'.$appointment->id;
            $conversation = $this->conversationFor($tenant, $appointment);

            if (! $conversation || ! $this->claim($conversation, Followup::REMINDER, $anchor, $appointment)) {
                continue;
            }

            $when = CarbonImmutable::instance($appointment->starts_at)->setTimezone($tenant->timezone)->locale('es');
            $name = $appointment->contact->name ? ' '.strtok($appointment->contact->name, ' ') : '';
            $dateText = $when->isoFormat('dddd D [de] MMMM');
            $timeText = $when->isoFormat('h:mm a');

            if ($conversation->isWindowOpen()) {
                $address = ($tenant->profile ?? [])['direccion'] ?? null;
                $text = "Hola{$name}, te recordamos tu cita de *{$appointment->title}* el {$dateText} a las {$timeText}"
                    .($address ? " en {$address}" : '').'. Si necesitas cambiarla, respóndenos por aquí.';
                $message = $this->deliverText($conversation, $text, 'reminder');
            } elseif ($template = $tenant->followup('reminder_template')) {
                $message = $this->deliverTemplate($tenant, $conversation, $template, [
                    trim($name) ?: 'hola', $appointment->title, "{$dateText} a las {$timeText}",
                ]);
            } else {
                $this->finish($conversation, Followup::REMINDER, $anchor, null, 'skipped', 'sin_plantilla', $appointment);

                continue;
            }

            $status = $message->status === 'sent' ? 'sent' : 'failed';
            $this->finish($conversation, Followup::REMINDER, $anchor, $message, $status, null, $appointment);
            $sent += $status === 'sent' ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Redacta el seguimiento con el LLM, retomando la conversación.
     */
    private function writeNudge(Tenant $tenant, Conversation $conversation, Message $last): ?string
    {
        $agent = $conversation->agent;
        $history = $conversation->messages()
            ->whereNotNull('content')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
            ->latest('id')
            ->limit(config('agentes.context_messages'))
            ->get()
            ->reverse()
            ->map(fn (Message $m) => ['role' => $m->direction === Message::IN ? 'user' : 'assistant', 'content' => $m->content])
            ->values()
            ->all();

        $hours = (int) round($last->created_at->diffInHours(now()));
        $history[] = [
            'role' => 'user',
            'content' => "[Nota del sistema, no la menciones: el cliente no responde hace {$hours} horas. Escribe UN mensaje de seguimiento breve "
                .'(máximo 2 frases), amable y sin presionar, que retome lo último que hablaron y facilite responder. No repitas tu mensaje anterior.]',
        ];

        $context = $this->prompts->context($tenant, $conversation, '');

        $result = $this->llm->respond(new LlmRequest(
            model: config('agentes.llm.models.fast'),
            system: $this->prompts->system($tenant, $agent),
            context: $context,
            messages: $history,
            maxOutputTokens: 300,
            maxToolRounds: 0,
        ), fn () => '');

        UsageRecord::recordLlm($tenant->id, $result);

        $check = $this->guard->check($result->text, $context);

        return $check['ok'] && ! $result->refused() ? $check['text'] : null;
    }

    private function deliverText(Conversation $conversation, string $text, string $source): Message
    {
        $message = $conversation->messages()->create([
            'direction' => Message::OUT,
            'author' => 'bot',
            'type' => 'text',
            'content' => $text,
            'status' => 'pending',
            'source' => $source,
        ]);

        $this->sender->sendPending($conversation);

        return $message->fresh();
    }

    /**
     * @param  list<string>  $params  Variables {{1}}, {{2}}... del cuerpo de la plantilla.
     */
    private function deliverTemplate(Tenant $tenant, Conversation $conversation, string $template, array $params): Message
    {
        $message = $conversation->messages()->create([
            'direction' => Message::OUT,
            'author' => 'bot',
            'type' => 'template',
            'content' => "[Plantilla {$template}] ".implode(' · ', $params),
            'status' => 'pending',
            'source' => 'reminder',
            'meta' => ['template' => $template, 'params' => $params],
        ]);

        try {
            $waId = $this->whatsapp->sendTemplate($conversation->channel, $conversation->contact->wa_id, $template, $tenant->followup('template_language'), [[
                'type' => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p], $params),
            ]]);
            $message->update(['status' => 'sent', 'wa_message_id' => $waId ?: null]);
        } catch (\Throwable $e) {
            report($e);
            $message->refresh()->update(['status' => 'failed', 'meta' => [...$message->meta, 'error' => $e->getMessage()]]);
        }

        return $message;
    }

    /**
     * Conversación donde va el recordatorio: la de la cita, la última del
     * cliente o una nueva en el número del negocio.
     */
    private function conversationFor(Tenant $tenant, Appointment $appointment): ?Conversation
    {
        $conversation = $appointment->conversation
            ?? Conversation::where('contact_id', $appointment->contact_id)->latest('id')->first();

        if ($conversation?->channel_id) {
            return $conversation;
        }

        $channel = Channel::where('status', 'active')->first();

        return $channel ? Conversation::create([
            'contact_id' => $appointment->contact_id,
            'channel_id' => $channel->id,
            'agent_id' => $tenant->agent?->id,
            'status' => Conversation::STATUS_BOT,
        ]) : null;
    }

    /**
     * Reserva el seguimiento antes de enviarlo: si otro proceso ya lo tomó, no se duplica.
     */
    private function claim(Conversation $conversation, string $kind, string $anchor, ?Appointment $appointment = null): bool
    {
        try {
            Followup::create([
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->id,
                'appointment_id' => $appointment?->id,
                'kind' => $kind,
                'anchor' => $anchor,
                'status' => 'pending',
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function finish(Conversation $conversation, string $kind, string $anchor, ?Message $message, string $status, ?string $reason = null, ?Appointment $appointment = null): void
    {
        Followup::where('kind', $kind)->where('anchor', $anchor)->update([
            'message_id' => $message?->id,
            'status' => $status,
            'reason' => $reason ?? ($message?->meta['error'] ?? null),
        ]);
    }
}

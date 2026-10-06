<?php

namespace App\Services\Metrics;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicadores del negocio para un periodo. Todas las consultas pasan por el
 * scope de tenant, así que deben ejecutarse con el negocio activo.
 */
class TenantMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function compute(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromUtc = $from->utc();
        $toUtc = $to->utc();

        $real = fn ($q) => $q->where('is_test', false);

        $conversations = Conversation::query()
            ->whereHas('contact', $real)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->get(['id', 'status', 'handoff_reason', 'created_at']);
        $ids = $conversations->pluck('id');

        $humanTouched = Message::query()
            ->whereIn('conversation_id', $ids)
            ->where('author', 'human')
            ->distinct()
            ->pluck('conversation_id')
            ->flip();

        $needsHuman = fn (Conversation $c) => $c->status === Conversation::STATUS_HUMAN
            || $c->handoff_reason !== null
            || $humanTouched->has($c->id);

        $total = $conversations->count();
        $botOnly = $conversations->reject($needsHuman)->count();

        $replies = Message::query()
            ->whereHas('conversation.contact', $real)
            ->where('direction', Message::OUT)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
            ->selectRaw('author, source, count(*) as total, sum(cost_usd) as cost')
            ->groupBy('author', 'source')
            ->get();

        $bySource = [
            'rule' => (int) $replies->where('author', 'bot')->where('source', 'rule')->sum('total'),
            'llm' => (int) $replies->where('author', 'bot')->where('source', 'llm')->sum('total'),
            'human' => (int) $replies->where('author', 'human')->sum('total'),
        ];
        $cost = (float) $replies->sum('cost');

        $appointments = Appointment::query()->whereBetween('created_at', [$fromUtc, $toUtc])->get(['source', 'status']);
        $newContacts = Contact::query()->where('is_test', false)->whereBetween('created_at', [$fromUtc, $toUtc])->get(['stage']);

        $month = UsageRecord::forTenant($tenant->id, CarbonImmutable::now($tenant->timezone)->format('Y-m'));

        return [
            'conversations' => $total,
            'bot_only' => $botOnly,
            'bot_only_rate' => $total > 0 ? $botOnly / $total : null,
            'handed_off' => $total - $botOnly,
            'replies' => $bySource,
            'cost_usd' => $cost,
            'cost_per_conversation' => $total > 0 ? $cost / $total : null,
            'first_response_seconds' => $this->medianFirstResponse($ids),
            'funnel' => [
                'contacts' => $newContacts->count(),
                'qualified' => $newContacts->whereIn('stage', ['calificado', 'cliente'])->count(),
                'appointments' => $appointments->where('status', '!=', Appointment::CANCELLED)->count(),
                'attended' => $appointments->where('status', Appointment::COMPLETED)->count(),
            ],
            'appointments_by_agent' => $appointments->where('source', 'agent')->where('status', '!=', Appointment::CANCELLED)->count(),
            'daily' => $this->daily($tenant, $conversations, $needsHuman, $from, $to),
            'plan' => [
                'used' => (int) $month->conversations,
                'limit' => (int) $tenant->monthly_conversation_limit,
            ],
        ];
    }

    /**
     * Mediana del tiempo entre el primer mensaje del cliente y la primera
     * respuesta enviada (bot o persona).
     */
    private function medianFirstResponse(Collection $conversationIds): ?int
    {
        if ($conversationIds->isEmpty()) {
            return null;
        }

        $messages = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', [...Message::UNSENT_STATUSES, 'failed']))
            ->orderBy('id')
            ->get(['conversation_id', 'direction', 'created_at'])
            ->groupBy('conversation_id');

        $durations = $messages->map(function (Collection $thread) {
            $firstIn = $thread->firstWhere('direction', Message::IN);
            $firstOut = $firstIn ? $thread->first(fn ($m) => $m->direction === Message::OUT && $m->created_at >= $firstIn->created_at) : null;

            return $firstOut ? (int) $firstIn->created_at->diffInSeconds($firstOut->created_at) : null;
        })->filter(fn ($s) => $s !== null)->sort()->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $middle = intdiv($durations->count(), 2);

        return $durations->count() % 2
            ? $durations[$middle]
            : intdiv($durations[$middle - 1] + $durations[$middle], 2);
    }

    /**
     * @return list<array{date: string, label: string, bot: int, human: int}>
     */
    private function daily(Tenant $tenant, Collection $conversations, callable $needsHuman, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = $conversations->groupBy(fn ($c) => $c->created_at->setTimezone($tenant->timezone)->format('Y-m-d'));

        $days = [];
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $items = $byDay->get($day->format('Y-m-d'), collect());
            $human = $items->filter($needsHuman)->count();
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'label' => $day->locale('es')->isoFormat('D MMM'),
                'bot' => $items->count() - $human,
                'human' => $human,
            ];
        }

        return $days;
    }
}

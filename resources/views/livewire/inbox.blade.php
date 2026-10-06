@php
    use App\Models\Conversation;
    use App\Models\Message;

    $current = $this->current;
    $statusLabel = [
        Conversation::STATUS_BOT => 'Bot',
        Conversation::STATUS_HUMAN => 'Persona',
        Conversation::STATUS_CLOSED => 'Cerrada',
    ];
    $stageLabel = ['nuevo' => 'Nuevo', 'calificado' => 'Calificado', 'cliente' => 'Cliente', 'perdido' => 'Perdido'];
    // Las fechas se muestran en la zona horaria del negocio.
    $tz = auth()->user()->tenant->timezone;
    $local = fn ($date) => $date?->copy()->setTimezone($tz);
    $when = function ($date) use ($local) {
        $date = $local($date);
        $today = now($date?->timezone);

        return match (true) {
            $date === null => '',
            $date->isSameDay($today) => $date->format('g:i a'),
            $date->isSameDay($today->copy()->subDay()) => 'Ayer',
            default => $date->format('d/m'),
        };
    };
    $previewPrefix = ['bot' => 'Agente:', 'human' => 'Equipo:'];
@endphp

<div class="inbox {{ $current ? 'has-selection' : '' }}" wire:poll.5s.visible>

    {{-- Lista de conversaciones --}}
    <aside class="pane list-pane" aria-label="Conversaciones">
        <div class="list-head">
            <h1>Bandeja</h1>
            <label class="search">
                <span class="sr-only">Buscar por nombre o teléfono</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input id="inbox-search" type="search" placeholder="Buscar por nombre o teléfono" wire:model.live.debounce.300ms="search">
            </label>
        </div>

        <nav class="filters" aria-label="Filtros">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['filter', 'active' => $filter === $key, 'alert' => $key === 'atencion' && $this->counts[$key] > 0])>
                    {{ $label }} <span class="count">{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </nav>

        <ul class="conversations" role="list">
            @forelse ($this->conversations as $conversation)
                @php
                    $needsAttention = $conversation->has_draft
                        || ($conversation->status === Conversation::STATUS_HUMAN && $conversation->last_direction === Message::IN);
                @endphp
                <li wire:key="conv-{{ $conversation->id }}">
                    <button type="button" wire:click="select({{ $conversation->id }})" @class(['conv', 'selected' => $current?->id === $conversation->id, 'attention' => $needsAttention])>
                        <span class="avatar">{{ $conversation->contact->initials() }}</span>
                        <span class="conv-body">
                            <span class="conv-top">
                                <strong>{{ $conversation->contact->displayName() }}</strong>
                                <time>{{ $when($conversation->last_message_at) }}</time>
                            </span>
                            <span class="conv-preview">
                                @if ($conversation->last_direction === Message::OUT)<span class="you">{{ $previewPrefix[$conversation->last_author] ?? '' }}</span>@endif
                                {{ \Illuminate\Support\Str::limit($conversation->last_preview, 70) }}
                            </span>
                            <span class="conv-chips">
                                <span class="chip status-{{ $conversation->status }}">{{ $statusLabel[$conversation->status] }}</span>
                                @if ($conversation->has_draft)
                                    <span class="chip draft">Borrador por aprobar</span>
                                @elseif ($needsAttention)
                                    <span class="chip waiting">Espera respuesta</span>
                                @endif
                            </span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="empty-list">
                    @if ($filter === 'atencion')
                        <strong>Todo al día</strong>
                        <span>No hay clientes esperando a una persona ni borradores por aprobar.</span>
                    @else
                        <strong>Sin conversaciones</strong>
                        <span>Cuando un cliente escriba a tu WhatsApp, aparecerá aquí.</span>
                    @endif
                </li>
            @endforelse
        </ul>
    </aside>

    {{-- Conversación --}}
    <section class="pane thread-pane" aria-label="Conversación">
        @if ($current)
            @php
                $contact = $current->contact;
                $hoursLeft = $current->isWindowOpen() ? (int) ceil(now()->diffInMinutes($current->window_expires_at) / 60) : 0;
            @endphp
            <header class="thread-head">
                <button type="button" class="back" wire:click="closePanel" aria-label="Volver a la lista">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <span class="avatar">{{ $contact->initials() }}</span>
                <div class="thread-title">
                    <strong>{{ $contact->displayName() }}</strong>
                    <span>{{ $contact->name ? $contact->displayPhone() : 'Sin nombre todavía' }}</span>
                </div>
                <div class="thread-actions">
                    @if ($current->status === Conversation::STATUS_BOT)
                        <button type="button" class="btn" wire:click="take">Tomar <span class="long">conversación</span></button>
                    @elseif ($current->status === Conversation::STATUS_HUMAN)
                        <button type="button" class="btn" wire:click="returnToBot">Devolver al bot</button>
                    @endif
                    @if ($current->status !== Conversation::STATUS_CLOSED)
                        <button type="button" class="btn ghost" wire:click="close">Cerrar</button>
                    @endif
                </div>
            </header>

            <div class="thread" id="thread" data-conversation="{{ $current->id }}">
                @foreach ($current->messages as $message)
                    @if ($message->meta['issue'] ?? null)
                        <div class="system-note danger" wire:key="issue-{{ $message->id }}">
                            <strong>Respuesta del agente bloqueada.</strong>
                            @if (str_starts_with($message->meta['issue'], 'precio_no_verificado'))
                                Mencionaba un precio que no está en el catálogo ({{ '$'.\Illuminate\Support\Str::after($message->meta['issue'], ':') }}).
                            @else
                                El agente no produjo una respuesta válida.
                            @endif
                            Se envió el mensaje de traspaso.
                            @if ($message->meta['blocked_text'] ?? null)
                                <details><summary>Ver el borrador bloqueado</summary><p>{{ $message->meta['blocked_text'] }}</p></details>
                            @endif
                        </div>
                    @endif

                    @if ($message->isDraft())
                        <div class="draft-card" wire:key="draft-{{ $message->id }}">
                            <div class="draft-head">
                                <span class="chip draft">Borrador del agente</span>
                                <span>Revisa, edita si hace falta y envía.</span>
                            </div>
                            <label for="draft-{{ $message->id }}" class="sr-only">Texto del borrador</label>
                            <textarea id="draft-{{ $message->id }}" rows="3" wire:model="draftEdits.{{ $message->id }}"></textarea>
                            <div class="draft-actions">
                                <button type="button" class="btn ghost" wire:click="discardDraft({{ $message->id }})">Descartar</button>
                                <button type="button" class="btn primary" wire:click="approveDraft({{ $message->id }})">Aprobar y enviar</button>
                            </div>
                        </div>
                    @else
                        <div @class(['bubble', $message->direction, 'from-human' => $message->author === 'human']) wire:key="msg-{{ $message->id }}">
                            <div class="bubble-body">{!! $message->htmlBody() !!}</div>
                            @if (! empty($message->meta['tool_calls']))
                                <details class="tools">
                                    <summary>{{ count($message->meta['tool_calls']) }} {{ count($message->meta['tool_calls']) === 1 ? 'consulta' : 'consultas' }} del agente</summary>
                                    <ul>
                                        @foreach ($message->meta['tool_calls'] as $call)
                                            <li><code>{{ $call['name'] }}</code> {{ collect($call['input'])->implode(' · ') }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            <div class="bubble-meta">
                                @if ($message->direction === Message::OUT)
                                    <span class="author src-{{ $message->author === 'human' ? 'human' : $message->source }}">{{ $message->authorLabel() }}</span>
                                @endif
                                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $local($message->created_at)->format('g:i a') }}</time>
                                @if ($message->direction === Message::OUT)
                                    @switch($message->status)
                                        @case('read') <span class="ticks read" title="Leído">✓✓</span> @break
                                        @case('delivered') <span class="ticks" title="Entregado">✓✓</span> @break
                                        @case('sent') <span class="ticks" title="Enviado">✓</span> @break
                                        @case('failed') <span class="failed" title="{{ $message->meta['error'] ?? '' }}">No enviado</span> @break
                                        @default <span class="ticks pending" title="Enviando">◷</span>
                                    @endswitch
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <footer class="composer-wrap">
                @if ($notice)
                    <p class="flash" role="status">{{ $notice }}</p>
                @endif
                @if ($error)
                    <p class="flash error" role="alert">{{ $error }}</p>
                @endif

                <div @class(['handler', 'status-'.$current->status])>
                    @if ($current->status === Conversation::STATUS_BOT)
                        <span class="pulse" aria-hidden="true"></span><span>El agente responde esta conversación. Si escribes, la tomas tú.</span>
                    @elseif ($current->status === Conversation::STATUS_HUMAN)
                        <span>Atiende {{ $current->assignee?->name ?? 'una persona del equipo' }}.</span>
                        @if ($current->handoff_reason) <span class="reason">Motivo: {{ $current->handoff_reason }}</span> @endif
                    @else
                        Conversación cerrada.
                    @endif
                </div>

                @if ($current->status !== Conversation::STATUS_CLOSED && $current->isWindowOpen())
                    <form class="composer" wire:submit="sendReply">
                        <label for="reply" class="sr-only">Respuesta</label>
                        <textarea id="reply" rows="2" wire:model="reply" placeholder="Escribe como {{ auth()->user()->name }}…" x-data x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"></textarea>
                        <button type="submit" class="send" aria-label="Enviar" wire:loading.attr="disabled">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 20.5 21.5 12 3 3.5 3 10l13 2-13 2z"/></svg>
                        </button>
                    </form>
                    @error('reply') <p class="flash error">{{ $message }}</p> @enderror
                    <p class="window-hint">Ventana de WhatsApp abierta: quedan {{ $hoursLeft }} {{ $hoursLeft === 1 ? 'hora' : 'horas' }} para responder sin plantilla.</p>
                @elseif ($current->status !== Conversation::STATUS_CLOSED)
                    <p class="window-closed">Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo permite escribirle con una plantilla aprobada; los seguimientos con plantilla llegan en la siguiente fase.</p>
                @endif
            </footer>
        @else
            <div class="thread-empty">
                <div class="thread-empty-art" aria-hidden="true">
                    <span></span><span></span><span></span>
                </div>
                <h2>Elige una conversación</h2>
                <p>Las marcadas en ámbar esperan a una persona: el cliente pidió un asesor, el agente no pudo resolver o hay un borrador por aprobar.</p>
            </div>
        @endif
    </section>

    {{-- Ficha del contacto --}}
    @if ($current)
        @php
            $contact = $current->contact;
            $fields = collect($current->agent?->qualification_fields ?? [])->pluck('question', 'key');
            $leadData = $contact->lead_data ?? [];
            $cost = $current->messages->sum('cost_usd');
            $botReplies = $current->messages->where('direction', Message::OUT)->where('author', 'bot')->count();
        @endphp
        <aside class="pane contact-pane" aria-label="Ficha del contacto">
            <div class="contact-card">
                <span class="avatar xl">{{ $contact->initials() }}</span>
                <h2>{{ $contact->displayName() }}</h2>
                @if ($contact->name)
                    <p class="mono">{{ $contact->displayPhone() }}</p>
                @endif
                <span class="chip stage-{{ $contact->stage }}">{{ $stageLabel[$contact->stage] ?? $contact->stage }}</span>
            </div>

            <section class="facts">
                <h3>Calificación</h3>
                <dl>
                    @forelse ($fields as $key => $question)
                        <div @class(['fact', 'missing' => blank($leadData[$key] ?? null)])>
                            <dt>{{ \Illuminate\Support\Str::of($key)->replace('_', ' ')->ucfirst() }}</dt>
                            <dd>{{ $leadData[$key] ?? 'Sin dato' }}</dd>
                        </div>
                    @empty
                        @foreach ($leadData as $key => $value)
                            <div class="fact"><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    @endforelse
                </dl>
            </section>

            @php $nextAppointment = $contact->appointments()->upcoming()->first(); @endphp
            @if ($nextAppointment)
                <section class="facts">
                    <h3>Próxima cita</h3>
                    <a class="next-appt" href="{{ route('agenda', ['semana' => $local($nextAppointment->starts_at)->startOfWeek()->format('Y-m-d')]) }}">
                        <span class="next-appt-date">
                            <b>{{ $local($nextAppointment->starts_at)->day }}</b>
                            {{ $local($nextAppointment->starts_at)->locale('es')->isoFormat('MMM') }}
                        </span>
                        <span>
                            <strong>{{ $nextAppointment->title }}</strong>
                            {{ $local($nextAppointment->starts_at)->locale('es')->isoFormat('dddd, h:mm a') }}
                        </span>
                    </a>
                </section>
            @endif

            @if ($current->summary)
                <section class="facts">
                    <h3>Resumen</h3>
                    <p class="summary">{{ $current->summary }}</p>
                </section>
            @endif

            <section class="facts">
                <h3>Esta conversación</h3>
                <dl class="conv-metrics">
                    <div><dt>Mensajes</dt><dd>{{ $current->messages->count() }}</dd></div>
                    <div><dt>Respuestas del agente</dt><dd>{{ $botReplies }}</dd></div>
                    <div><dt>Costo de IA</dt><dd>US${{ number_format($cost, 4, ',', '.') }}</dd></div>
                    <div><dt>Inicio</dt><dd>{{ $local($current->created_at)->format('d/m g:i a') }}</dd></div>
                </dl>
            </section>
        </aside>
    @endif
</div>

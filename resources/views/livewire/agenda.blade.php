@php
    use App\Models\Appointment;

    $tz = $tenant->timezone;
    $today = now($tz)->format('Y-m-d');
    $sunday = $monday->addDays(6);
    $weekLabel = $monday->month === $sunday->month
        ? $monday->day.' – '.$sunday->locale('es')->isoFormat('D [de] MMMM YYYY')
        : $monday->locale('es')->isoFormat('D [de] MMMM').' – '.$sunday->locale('es')->isoFormat('D [de] MMMM YYYY');
    $time = fn ($date) => $date->copy()->setTimezone($tz)->format('g:i a');
@endphp

<div class="page agenda">
    <header class="page-head">
        <div class="page-title">
            <h1>Agenda</h1>
            <p>{{ $weekLabel }}</p>
        </div>
        <div class="week-nav" role="group" aria-label="Cambiar de semana">
            <button type="button" class="icon-btn" wire:click="previousWeek" aria-label="Semana anterior">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button type="button" class="btn ghost" wire:click="thisWeek">Hoy</button>
            <button type="button" class="icon-btn" wire:click="nextWeek" aria-label="Semana siguiente">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <div class="page-actions">
            <button type="button" class="btn" wire:click="$set('showSettings', true)">Configurar</button>
            <button type="button" class="btn primary" wire:click="openForm">Nueva cita</button>
        </div>
    </header>

    @if ($notice)
        <p class="flash" role="status">{{ $notice }}</p>
    @endif
    @if ($error)
        <p class="flash error" role="alert">{{ $error }}</p>
    @endif

    <section class="agenda-strip" aria-label="Resumen de la semana">
        <div><b>{{ $this->weekStats['total'] }}</b><span>citas esta semana</span></div>
        <div><b>{{ $this->weekStats['by_agent'] }}</b><span>agendadas por el agente</span></div>
        <div><b>{{ $this->weekStats['confirmed'] }}</b><span>por atender</span></div>
        <div><b>{{ $this->weekStats['no_show'] }}</b><span>no asistieron</span></div>
        <div class="agenda-state">
            @if (! $tenant->booking('enabled'))
                <span class="chip status-closed">El agente no agenda</span>
            @else
                <span class="chip status-bot">El agente agenda</span>
            @endif
            @if ($connection)
                <span class="chip google">Google Calendar</span>
            @endif
        </div>
    </section>

    <div class="week" style="--days: {{ $this->days->count() }}">
        @foreach ($this->days as $day)
            @php $date = $day['date']; @endphp
            <section @class(['day', 'today' => $date->format('Y-m-d') === $today, 'past' => $date->format('Y-m-d') < $today]) wire:key="day-{{ $date->format('Y-m-d') }}">
                <header class="day-head">
                    <span class="dow">{{ rtrim($date->locale('es')->isoFormat('ddd'), '.') }}</span>
                    <span class="dom">{{ $date->day }}</span>
                    <span class="hours">{{ $day['open'] ? $day['open'][0].' – '.$day['open'][1] : 'Cerrado' }}</span>
                </header>

                <ol class="slots">
                    @forelse ($day['appointments'] as $appointment)
                        <li @class(['appt', 'is-'.$appointment->status, 'by-agent' => $appointment->source === 'agent']) wire:key="appt-{{ $appointment->id }}">
                            <div class="appt-time">{{ $time($appointment->starts_at) }} <span>– {{ $time($appointment->ends_at) }}</span></div>
                            <strong class="appt-title">{{ $appointment->title }}</strong>
                            <span class="appt-who">{{ $appointment->contact->displayName() }}</span>
                            @if ($appointment->notes)
                                <span class="appt-notes">{{ $appointment->notes }}</span>
                            @endif
                            <div class="appt-foot">
                                <span class="appt-source">{{ $appointment->source === 'agent' ? 'Agente' : 'Equipo' }}</span>
                                @if ($appointment->contact->is_test)
                                    <span class="appt-test" title="Creada desde el chat de prueba de la configuración">Prueba</span>
                                @endif
                                @if ($appointment->status !== Appointment::CONFIRMED)
                                    <span class="appt-status">{{ Appointment::STATUS_LABELS[$appointment->status] }}</span>
                                @endif
                            </div>

                            @if ($appointment->status === Appointment::CONFIRMED)
                                <div class="appt-actions">
                                    @if ($confirmingCancel === $appointment->id)
                                        <span class="confirm-text">¿Cancelar la cita?</span>
                                        <button type="button" class="mini danger" wire:click="setStatus({{ $appointment->id }}, 'cancelled')">Sí, cancelar</button>
                                        <button type="button" class="mini" wire:click="$set('confirmingCancel', null)">No</button>
                                    @else
                                        <button type="button" class="mini" wire:click="setStatus({{ $appointment->id }}, 'completed')">Atendida</button>
                                        <button type="button" class="mini" wire:click="setStatus({{ $appointment->id }}, 'no_show')">No asistió</button>
                                        <button type="button" class="mini quiet" wire:click="$set('confirmingCancel', {{ $appointment->id }})">Cancelar</button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="day-empty">Sin citas</li>
                    @endforelse
                </ol>

                @if ($day['open'] && $date->format('Y-m-d') >= $today)
                    <button type="button" class="add-slot" wire:click="openForm('{{ $date->format('Y-m-d') }}')">+ Agendar</button>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Nueva cita --}}
    @if ($showForm)
        <div class="drawer-backdrop" wire:click="$set('showForm', false)"></div>
        <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="new-appt-title">
            <header class="drawer-head">
                <h2 id="new-appt-title">Nueva cita</h2>
                <button type="button" class="icon-btn" wire:click="$set('showForm', false)" aria-label="Cerrar">✕</button>
            </header>
            <form class="form" wire:submit="book">
                <label for="appt-phone">WhatsApp del cliente</label>
                <input id="appt-phone" type="tel" wire:model="form.phone" placeholder="+57 300 111 2233" autocomplete="off">
                @error('form.phone') <p class="form-error">{{ $message }}</p> @enderror

                <label for="appt-name">Nombre</label>
                <input id="appt-name" type="text" wire:model="form.name" placeholder="Opcional si ya escribió antes">

                <label for="appt-service">Servicio</label>
                <select id="appt-service" wire:model="form.service_id">
                    <option value="">Cita general ({{ $tenant->booking('default_duration') }} min)</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}">{{ $service->name }} · {{ $service->duration_minutes }} min</option>
                    @endforeach
                </select>

                <div class="form-row">
                    <div>
                        <label for="appt-date">Día</label>
                        <input id="appt-date" type="date" wire:model="form.date">
                        @error('form.date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="appt-time">Hora</label>
                        <input id="appt-time" type="time" step="300" wire:model="form.time">
                    </div>
                </div>
                @error('form.time') <p class="form-error">{{ $message }}</p> @enderror

                <label for="appt-notes">Notas para el equipo</label>
                <textarea id="appt-notes" rows="3" wire:model="form.notes"></textarea>

                <button type="submit" class="btn primary">Agendar</button>
            </form>
        </aside>
    @endif

    {{-- Configuración --}}
    @if ($showSettings)
        <div class="drawer-backdrop" wire:click="$set('showSettings', false)"></div>
        <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <header class="drawer-head">
                <h2 id="settings-title">Reglas de la agenda</h2>
                <button type="button" class="icon-btn" wire:click="$set('showSettings', false)" aria-label="Cerrar">✕</button>
            </header>
            <form class="form" wire:submit="saveSettings">
                <label class="toggle">
                    <input type="checkbox" id="set-enabled" wire:model="settings.enabled">
                    <span>
                        <strong>El agente agenda citas</strong>
                        <small>Si lo apagas, el agente toma los datos y pasa la conversación a una persona.</small>
                    </span>
                </label>

                <div class="form-row">
                    <div>
                        <label for="set-duration">Duración por defecto (min)</label>
                        <input id="set-duration" type="number" min="10" max="480" wire:model="settings.default_duration">
                    </div>
                    <div>
                        <label for="set-slot">Cada cuánto ofrecer horarios</label>
                        <select id="set-slot" wire:model="settings.slot_minutes">
                            @foreach ([15, 20, 30, 45, 60, 90, 120] as $minutes)
                                <option value="{{ $minutes }}">{{ $minutes }} min</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="set-notice">Anticipación mínima (horas)</label>
                        <input id="set-notice" type="number" min="0" max="168" wire:model="settings.min_notice_hours">
                    </div>
                    <div>
                        <label for="set-ahead">Agendar hasta (días)</label>
                        <input id="set-ahead" type="number" min="1" max="180" wire:model="settings.max_days_ahead">
                    </div>
                </div>
                <label for="set-capacity">Citas a la misma hora</label>
                <input id="set-capacity" type="number" min="1" max="50" wire:model="settings.capacity">
                <p class="hint">Úsalo si atienden varias personas en paralelo (por ejemplo, 2 odontólogos).</p>
                @foreach ($errors->get('settings.*') as $messages)
                    <p class="form-error">{{ $messages[0] }}</p>
                @endforeach

                <button type="submit" class="btn primary">Guardar reglas</button>
            </form>

            <section class="google-card">
                <h3>Google Calendar</h3>
                @if ($connection)
                    <p>Conectado{{ $connection->account_email ? ' como '.$connection->account_email : '' }}. Las citas nuevas se copian a tu calendario y tus eventos bloquean esos horarios para el agente.</p>
                    <form method="POST" action="{{ route('agenda.google.disconnect') }}">
                        @csrf
                        <button type="submit" class="btn ghost">Desconectar</button>
                    </form>
                @elseif ($googleAvailable)
                    <p>Conecta tu calendario para que el agente no agende encima de tus otros compromisos.</p>
                    <a class="btn" href="{{ route('agenda.google.connect') }}">Conectar Google Calendar</a>
                @else
                    <p>La conexión con Google Calendar estará disponible cuando se configuren las credenciales de Google en el servidor. Mientras tanto, el agente usa solo esta agenda.</p>
                @endif
            </section>
        </aside>
    @endif
</div>

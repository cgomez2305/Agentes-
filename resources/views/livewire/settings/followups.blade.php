@php
    $reasonLabels = ['sin_plantilla' => 'Sin plantilla aprobada', 'respuesta_invalida' => 'El agente no generó un mensaje válido', 'ventana_cerrada' => 'Ventana cerrada', 'sin_numero' => 'Sin número conectado'];
@endphp
<div class="page settings">
    <header class="page-head">
        <div class="page-title">
            <h1>Configuración</h1>
            <p>Mensajes que el agente envía por su cuenta para no perder clientes.</p>
        </div>
    </header>
    @include('livewire.settings._nav')

    <div class="settings-columns">
        <form class="settings-form" wire:submit="save">
            @if ($notice)
                <p class="flash" role="status">{{ $notice }}</p>
            @endif

            <section class="panel">
                <label class="toggle">
                    <input type="checkbox" id="nudge-enabled" wire:model.live="settings.nudge_enabled">
                    <span>
                        <strong>Escribir a quien deja de responder</strong>
                        <small>Un solo mensaje breve que retoma la conversación. Solo dentro de las 24 h que permite WhatsApp y entre 8 a. m. y 8 p. m.</small>
                    </span>
                </label>
                @if ($settings['nudge_enabled'])
                    <div class="field">
                        <label for="nudge-hours">Después de cuántas horas sin respuesta</label>
                        <input id="nudge-hours" type="number" min="1" max="20" wire:model="settings.nudge_after_hours">
                        @error('settings.nudge_after_hours') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </section>

            <section class="panel">
                <label class="toggle">
                    <input type="checkbox" id="reminder-enabled" wire:model.live="settings.reminder_enabled">
                    <span>
                        <strong>Recordar las citas</strong>
                        <small>Reduce las inasistencias. Si el cliente escribió en las últimas 24 h va como mensaje normal; si no, con la plantilla aprobada.</small>
                    </span>
                </label>
                @if ($settings['reminder_enabled'])
                    <div class="form-row">
                        <div class="field">
                            <label for="reminder-hours">Horas antes de la cita</label>
                            <input id="reminder-hours" type="number" min="2" max="72" wire:model="settings.reminder_hours_before">
                        </div>
                        <div class="field">
                            <label for="reminder-lang">Idioma de la plantilla</label>
                            <select id="reminder-lang" wire:model="settings.template_language">
                                <option value="es">Español</option>
                                <option value="es_ES">Español (España)</option>
                                <option value="es_MX">Español (México)</option>
                                <option value="es_AR">Español (Argentina)</option>
                                <option value="en_US">Inglés (EE. UU.)</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label for="reminder-template">Nombre de la plantilla en Meta</label>
                        <input id="reminder-template" type="text" wire:model="settings.reminder_template" placeholder="recordatorio_cita">
                        @error('settings.reminder_template') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="template-preview">
                        <span class="eyebrow">Texto sugerido para aprobar en Meta (categoría Utilidad)</span>
                        <p>Hola @{{1}}, te recordamos tu cita de <b>@{{2}}</b> el @{{3}}. Si necesitas cambiarla, responde a este mensaje.</p>
                    </div>
                @endif
            </section>

            <div class="save-bar">
                <button type="submit" class="btn primary">Guardar cambios</button>
            </div>
        </form>

        <section class="panel">
            <h2>Últimos envíos</h2>
            <ul class="source-list" role="list">
                @forelse ($this->recent as $followup)
                    <li wire:key="fu-{{ $followup->id }}">
                        <span @class(['source-icon', 'warn' => $followup->status !== 'sent']) aria-hidden="true">{{ $followup->kind === 'reminder' ? '◷' : '↺' }}</span>
                        <span class="source-body">
                            <strong>{{ $followup->kind === 'reminder' ? 'Recordatorio de cita' : 'Seguimiento' }} · {{ $followup->conversation?->contact?->displayName() ?? 'Cliente' }}</strong>
                            <span>
                                {{ $followup->created_at->locale('es')->diffForHumans() }} ·
                                {{ match ($followup->status) { 'sent' => 'Enviado', 'skipped' => 'Omitido', 'failed' => 'Falló', default => 'En curso' } }}
                                @if ($followup->reason) — {{ $reasonLabels[$followup->reason] ?? $followup->reason }} @endif
                            </span>
                        </span>
                    </li>
                @empty
                    <li class="rows-empty">Aún no se han enviado seguimientos.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>

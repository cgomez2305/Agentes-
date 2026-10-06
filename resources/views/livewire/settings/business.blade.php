<div class="page settings">
    <header class="page-head">
        <div class="page-title">
            <h1>Configuración</h1>
            <p>Los datos que el agente usa para responder sobre tu negocio.</p>
        </div>
    </header>
    @include('livewire.settings._nav')

    <form class="settings-form narrow" wire:submit="save">
        @if ($notice)
            <p class="flash" role="status">{{ $notice }}</p>
        @endif

        <section class="panel">
            <h2>Datos del negocio</h2>
            <div class="form-row">
                <div class="field">
                    <label for="biz-name">Nombre</label>
                    <input id="biz-name" type="text" wire:model="name">
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="biz-tz">País (zona horaria)</label>
                    <select id="biz-tz" wire:model="timezone">
                        @foreach ($timezones as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="biz-address">Dirección</label>
                <input id="biz-address" type="text" wire:model="profile.direccion" placeholder="Calle 10 # 43-20, Medellín">
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="biz-phone">Teléfono</label>
                    <input id="biz-phone" type="text" wire:model="profile.telefono">
                </div>
                <div class="field">
                    <label for="biz-web">Sitio web</label>
                    <input id="biz-web" type="text" wire:model="profile.web">
                </div>
            </div>
            <div class="field">
                <label for="biz-mail">Correo</label>
                <input id="biz-mail" type="text" wire:model="profile.correo">
                @error('profile.correo') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="panel">
            <h2>Horario de atención</h2>
            <p class="panel-sub">El agente responde 24/7; este horario define cuándo hay personas disponibles y en qué horas se pueden agendar citas.</p>
            <ol class="week-hours">
                @foreach ($days as $key => $label)
                    <li @class(['closed' => ! $hours[$key]['open']]) wire:key="day-{{ $key }}">
                        <label class="day-toggle">
                            <input type="checkbox" id="open-{{ $key }}" wire:model.live="hours.{{ $key }}.open">
                            <span>{{ $label }}</span>
                        </label>
                        @if ($hours[$key]['open'])
                            <span class="hour-range">
                                <label class="sr-only" for="from-{{ $key }}">Abre</label>
                                <input id="from-{{ $key }}" type="time" step="900" wire:model="hours.{{ $key }}.from">
                                <span aria-hidden="true">a</span>
                                <label class="sr-only" for="to-{{ $key }}">Cierra</label>
                                <input id="to-{{ $key }}" type="time" step="900" wire:model="hours.{{ $key }}.to">
                            </span>
                        @else
                            <span class="hour-range muted">Cerrado</span>
                        @endif
                        @error("hours.$key.to") <p class="form-error">{{ $message }}</p> @enderror
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="save-bar">
            <button type="submit" class="btn primary">Guardar cambios</button>
        </div>
    </form>
</div>

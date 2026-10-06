@php
    $tz = auth()->user()->tenant->timezone;
    $ago = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale('es')->diffForHumans() : '—';
    $current = $this->current;
    $statusLabel = ['bot' => 'Con el bot', 'human' => 'Con una persona', 'closed' => 'Cerrada'];
@endphp

<div class="page contacts">
    <header class="page-head">
        <div class="page-title">
            <h1>Contactos</h1>
            <p>Todas las personas que le escribieron a tu negocio, con lo que el agente averiguó de cada una.</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn" wire:click="export">Exportar CSV</button>
        </div>
    </header>

    <div class="pipeline" role="group" aria-label="Filtrar por etapa">
        <button type="button" wire:click="$set('stage', '')" @class(['stage-card', 'active' => $stage === ''])>
            <span>Todos</span><b>{{ array_sum($this->stageCounts) }}</b>
        </button>
        @foreach ($stages as $key => $label)
            <button type="button" wire:click="$set('stage', '{{ $key }}')" @class(['stage-card', 'stage-'.$key, 'active' => $stage === $key])>
                <span>{{ $label }}</span><b>{{ $this->stageCounts[$key] }}</b>
            </button>
        @endforeach
    </div>

    <div class="toolbar">
        <label class="search">
            <span class="sr-only">Buscar por nombre o teléfono</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="contact-search" type="search" placeholder="Buscar por nombre o teléfono" wire:model.live.debounce.300ms="search">
        </label>
        @if ($this->allTags)
            <label class="tag-filter">
                <span class="sr-only">Filtrar por etiqueta</span>
                <select id="tag-filter" wire:model.live="tag">
                    <option value="">Todas las etiquetas</option>
                    @foreach ($this->allTags as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    <section class="panel table-panel">
        <div class="table-wrap">
            <table class="data-table contacts-table">
                <thead>
                    <tr>
                        <th scope="col">Contacto</th>
                        <th scope="col">Etapa</th>
                        <th scope="col">Lo que sabemos</th>
                        <th scope="col">Etiquetas</th>
                        <th scope="col">Última actividad</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->contacts as $contact)
                        <tr wire:key="contact-{{ $contact->id }}" @class(['selected' => $openId === $contact->id])>
                            <th scope="row">
                                <button type="button" class="contact-cell" wire:click="open({{ $contact->id }})">
                                    <span class="avatar sm">{{ $contact->initials() }}</span>
                                    <span>
                                        <strong>{{ $contact->displayName() }}</strong>
                                        @if ($contact->name)<small>{{ $contact->displayPhone() }}</small>@endif
                                    </span>
                                </button>
                            </th>
                            <td><span class="chip stage-{{ $contact->stage }}">{{ $stages[$contact->stage] ?? $contact->stage }}</span></td>
                            <td class="facts-cell">
                                {{ collect($contact->lead_data ?? [])->except('notas')->map(fn ($v, $k) => \Illuminate\Support\Str::of($k)->replace('_', ' ')->ucfirst().': '.$v)->implode(' · ') ?: '—' }}
                            </td>
                            <td>
                                @foreach ($contact->tags ?? [] as $t)
                                    <span class="chip">{{ $t }}</span>
                                @endforeach
                            </td>
                            <td class="when">
                                {{ $ago($contact->conversations_max_last_message_at ?? $contact->updated_at) }}
                                @if ($contact->appointments_count)
                                    <small>{{ $contact->appointments_count }} {{ $contact->appointments_count === 1 ? 'cita' : 'citas' }}</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="rows-empty">No hay contactos con ese filtro.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->contacts->hasPages())
            <nav class="pager" aria-label="Páginas">
                <button type="button" class="btn ghost" wire:click="previousPage" @disabled($this->contacts->onFirstPage())>Anterior</button>
                <span>Página {{ $this->contacts->currentPage() }} de {{ $this->contacts->lastPage() }}</span>
                <button type="button" class="btn ghost" wire:click="nextPage" @disabled(! $this->contacts->hasMorePages())>Siguiente</button>
            </nav>
        @endif
    </section>

    @if ($current)
        <div class="drawer-backdrop" wire:click="close"></div>
        <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="contact-title">
            <header class="drawer-head">
                <div class="drawer-contact">
                    <span class="avatar">{{ $current->initials() }}</span>
                    <div>
                        <h2 id="contact-title">{{ $current->displayName() }}</h2>
                        <span class="mono">{{ $current->displayPhone() }}</span>
                    </div>
                </div>
                <button type="button" class="icon-btn" wire:click="close" aria-label="Cerrar">✕</button>
            </header>

            <section class="facts">
                <h3>Etapa</h3>
                <div class="segmented wide" role="group" aria-label="Etapa">
                    @foreach ($stages as $key => $label)
                        <button type="button" wire:click="setStage({{ $current->id }}, '{{ $key }}')" @class(['active' => $current->stage === $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </section>

            <section class="facts">
                <h3>Etiquetas</h3>
                <div class="tag-editor">
                    @foreach ($current->tags ?? [] as $t)
                        <span class="chip removable">{{ $t }} <button type="button" wire:click="removeTag(@js($t))" aria-label="Quitar etiqueta {{ $t }}">✕</button></span>
                    @endforeach
                    <form wire:submit="addTag" class="tag-add">
                        <label for="new-tag" class="sr-only">Nueva etiqueta</label>
                        <input id="new-tag" type="text" wire:model="newTag" placeholder="Agregar etiqueta" list="known-tags">
                        <datalist id="known-tags">
                            @foreach ($this->allTags as $t)<option value="{{ $t }}"></option>@endforeach
                        </datalist>
                    </form>
                </div>
            </section>

            <section class="facts">
                <h3>Lo que el agente averiguó</h3>
                <dl>
                    @forelse (collect($current->lead_data ?? [])->except('notas') as $key => $value)
                        <div class="fact"><dt>{{ \Illuminate\Support\Str::of($key)->replace('_', ' ')->ucfirst() }}</dt><dd>{{ $value }}</dd></div>
                    @empty
                        <p class="rows-empty">Todavía no hay datos.</p>
                    @endforelse
                </dl>
            </section>

            <section class="facts">
                <h3>Notas del equipo</h3>
                <form wire:submit="saveNotes" class="form">
                    <label for="contact-notes" class="sr-only">Notas</label>
                    <textarea id="contact-notes" rows="3" wire:model="notes" placeholder="Visibles solo para tu equipo."></textarea>
                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="saveNotes">Guardar nota</button>
                </form>
            </section>

            <section class="facts">
                <h3>Conversaciones</h3>
                <ul class="source-list" role="list">
                    @forelse ($current->conversations as $conversation)
                        <li>
                            <span class="source-body">
                                <strong>{{ $statusLabel[$conversation->status] ?? $conversation->status }}</strong>
                                <span>{{ $ago($conversation->last_message_at ?? $conversation->created_at) }}</span>
                            </span>
                            <a class="mini" href="{{ route('inbox', ['c' => $conversation->id, 'filtro' => 'todas']) }}">Abrir</a>
                        </li>
                    @empty
                        <li class="rows-empty">Sin conversaciones.</li>
                    @endforelse
                </ul>
            </section>

            @if ($current->appointments->isNotEmpty())
                <section class="facts">
                    <h3>Citas</h3>
                    <ul class="source-list" role="list">
                        @foreach ($current->appointments as $appointment)
                            <li>
                                <span class="source-body">
                                    <strong>{{ $appointment->title }}</strong>
                                    <span>{{ $appointment->starts_at->setTimezone($tz)->locale('es')->isoFormat('D MMM YYYY, h:mm a') }} · {{ \App\Models\Appointment::STATUS_LABELS[$appointment->status] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    @endif
</div>

<div class="page settings">
    <header class="page-head">
        <div class="page-title">
            <h1>Configuración</h1>
            <p>Lo que el agente sabe de tu negocio y lo que vendes.</p>
        </div>
    </header>
    @include('livewire.settings._nav', ['section' => 'knowledge'])

    @if ($notice)
        <p class="flash" role="status">{{ $notice }}</p>
    @endif
    @if ($error)
        <p class="flash error" role="alert">{{ $error }}</p>
    @endif

    <div class="settings-columns">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Conocimiento</h2>
                    <p class="panel-sub">Preguntas frecuentes, políticas, detalles de servicios. El agente busca aquí antes de responder.</p>
                </div>
                @unless ($showSourceForm)
                    <button type="button" class="btn" wire:click="$set('showSourceForm', true)">Agregar texto</button>
                @endunless
            </div>

            @if ($showSourceForm)
                <form class="form inline-form" wire:submit="addSource">
                    <div class="segmented" role="group" aria-label="Tipo de fuente">
                        @foreach (['texto' => 'Pegar texto', 'pdf' => 'Subir PDF', 'url' => 'Página web'] as $mode => $label)
                            <button type="button" wire:click="$set('sourceMode', '{{ $mode }}')" @class(['active' => $sourceMode === $mode])>{{ $label }}</button>
                        @endforeach
                    </div>

                    <label for="src-title">Título{{ $sourceMode === 'texto' ? '' : ' (opcional)' }}</label>
                    <input id="src-title" type="text" wire:model="sourceTitle" placeholder="{{ $sourceMode === 'url' ? 'Se toma el título de la página' : 'Políticas de cancelación' }}">
                    @error('sourceTitle') <p class="form-error">{{ $message }}</p> @enderror

                    @if ($sourceMode === 'pdf')
                        <label for="src-pdf">Archivo PDF</label>
                        <input id="src-pdf" type="file" accept="application/pdf" wire:model="pdf">
                        <p class="hint">Brochures, listas de precios, reglamentos. Debe tener texto seleccionable (no fotos escaneadas). Máximo 10 MB.</p>
                        @error('pdf') <p class="form-error">{{ $message }}</p> @enderror
                    @elseif ($sourceMode === 'url')
                        <label for="src-url">Dirección de la página</label>
                        <input id="src-url" type="text" inputmode="url" wire:model="url" placeholder="https://tunegocio.com/preguntas-frecuentes">
                        <p class="hint">Se lee el texto principal de la página, sin menús ni pie de página. Agrega una página por tema.</p>
                        @error('url') <p class="form-error">{{ $message }}</p> @enderror
                    @else
                        <label for="src-content">Texto</label>
                        <textarea id="src-content" rows="8" wire:model="sourceContent" placeholder="Pega aquí el texto. Separa los temas con una línea en blanco."></textarea>
                        @error('sourceContent') <p class="form-error">{{ $message }}</p> @enderror
                    @endif

                    <div class="inline-actions">
                        <button type="button" class="btn ghost" wire:click="$set('showSourceForm', false)">Cancelar</button>
                        <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="addSource,pdf">
                            <span wire:loading.remove wire:target="addSource">Agregar</span>
                            <span wire:loading wire:target="addSource">Leyendo…</span>
                        </button>
                    </div>
                </form>
            @endif

            <ul class="source-list" role="list">
                @forelse ($this->sources as $source)
                    <li wire:key="src-{{ $source->id }}">
                        <span class="source-icon" aria-hidden="true">{{ ['pdf' => 'PDF', 'url' => 'www'][$source->type] ?? '¶' }}</span>
                        <span class="source-body">
                            <strong>{{ $source->title }}</strong>
                            <span>{{ $source->chunks_count }} {{ $source->chunks_count === 1 ? 'fragmento' : 'fragmentos' }} · {{ $source->created_at->locale('es')->diffForHumans() }}@if ($source->type === 'url') · {{ parse_url($source->origin, PHP_URL_HOST) }}@endif</span>
                        </span>
                        <button type="button" class="mini quiet" wire:click="deleteSource({{ $source->id }})" wire:confirm="¿Eliminar «{{ $source->title }}»?">Eliminar</button>
                    </li>
                @empty
                    <li class="rows-empty">Todavía no hay conocimiento cargado.</li>
                @endforelse
            </ul>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Catálogo</h2>
                    <p class="panel-sub">Única fuente de precios del agente. Si un precio no está aquí, el agente no lo dice.</p>
                </div>
            </div>

            <form class="catalog-form" wire:submit="saveItem">
                <div class="field grow">
                    <label for="item-name">{{ $editingItem ? 'Editar ítem' : 'Nuevo producto o servicio' }}</label>
                    <input id="item-name" type="text" wire:model="item.name" placeholder="Limpieza dental profunda">
                </div>
                <div class="field">
                    <label for="item-price">Precio (COP)</label>
                    <input id="item-price" type="text" inputmode="numeric" wire:model="item.price" placeholder="150000">
                </div>
                <div class="field">
                    <label for="item-duration">Duración (min)</label>
                    <input id="item-duration" type="number" wire:model="item.duration" placeholder="45">
                </div>
                <div class="field full">
                    <label for="item-description">Descripción corta</label>
                    <input id="item-description" type="text" wire:model="item.description">
                </div>
                @foreach ($errors->get('item.*') as $messages)
                    <p class="form-error full">{{ $messages[0] }}</p>
                @endforeach
                <div class="inline-actions full">
                    @if ($editingItem)
                        <button type="button" class="btn ghost" wire:click="cancelEdit">Cancelar</button>
                    @endif
                    <button type="submit" class="btn primary">{{ $editingItem ? 'Guardar cambios' : 'Agregar al catálogo' }}</button>
                </div>
            </form>

            <div class="table-wrap catalog">
                <table class="data-table">
                    <thead><tr><th scope="col">Nombre</th><th scope="col">Precio</th><th scope="col">Duración</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->items as $catalogItem)
                            <tr wire:key="item-{{ $catalogItem->id }}" @class(['unavailable' => ! $catalogItem->is_available])>
                                <th scope="row">
                                    {{ $catalogItem->name }}
                                    @unless ($catalogItem->is_available) <span class="chip status-closed">No disponible</span> @endunless
                                </th>
                                <td class="nowrap">{{ $catalogItem->formattedPrice() ?? 'A cotizar' }}</td>
                                <td>{{ $catalogItem->duration_minutes ? $catalogItem->duration_minutes.' min' : '—' }}</td>
                                <td class="row-actions">
                                    <button type="button" class="mini" wire:click="editItem({{ $catalogItem->id }})">Editar</button>
                                    <button type="button" class="mini quiet" wire:click="toggleItem({{ $catalogItem->id }})">{{ $catalogItem->is_available ? 'Pausar' : 'Activar' }}</button>
                                    <button type="button" class="mini quiet" wire:click="deleteItem({{ $catalogItem->id }})" wire:confirm="¿Eliminar «{{ $catalogItem->name }}» del catálogo?">Eliminar</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="rows-empty">El catálogo está vacío.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <form class="csv-import" wire:submit="importCsv">
                <label for="csv-file">Importar desde CSV</label>
                <p class="hint">Columnas: nombre, precio, descripcion, duracion_min, sku. Sirve un archivo exportado de Excel o de tu tienda.</p>
                <div class="inline-actions">
                    <input id="csv-file" type="file" accept=".csv,text/csv" wire:model="csv">
                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="csv,importCsv">Importar</button>
                </div>
                @error('csv') <p class="form-error">{{ $message }}</p> @enderror
            </form>
        </section>
    </div>
</div>

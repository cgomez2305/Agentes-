<div class="page settings">
    <header class="page-head">
        <div class="page-title">
            <h1>Configuración</h1>
            <p>Cómo habla, qué pregunta y qué responde tu agente.</p>
        </div>
    </header>
    @include('livewire.settings._nav')

    @php
        $steps = $this->onboarding;
        $doneCount = collect($steps)->where('done', true)->count();
    @endphp
    @if ($doneCount < count($steps))
        <section class="onboarding" aria-labelledby="onboarding-title">
            <header>
                <div>
                    <h2 id="onboarding-title">{{ $welcome ? '¡Tu agente está creado!' : 'Primeros pasos' }}</h2>
                    <p>{{ $welcome ? 'Ya responde con la plantilla de tu tipo de negocio. Complétalo para que hable como tú.' : 'Completa estos pasos para que tu agente quede listo.' }}</p>
                </div>
                <span class="onboarding-count"><b>{{ $doneCount }}</b> de {{ count($steps) }}</span>
            </header>
            <div class="onboarding-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($steps) }}" aria-valuenow="{{ $doneCount }}"><span style="width: {{ $doneCount / count($steps) * 100 }}%"></span></div>
            <ol class="steps">
                @foreach ($steps as $step)
                    <li @class(['done' => $step['done']])>
                        <span class="step-check" aria-hidden="true">{{ $step['done'] ? '✓' : '' }}</span>
                        <span class="step-body">
                            @if ($step['route'] && ! $step['done'])
                                <a href="{{ route($step['route']) }}"><strong>{{ $step['label'] }}</strong></a>
                            @else
                                <strong>{{ $step['label'] }}</strong>
                            @endif
                            <small>{{ $step['done'] ? 'Listo' : $step['hint'] }}</small>
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="settings-split">
        <form class="settings-form" wire:submit="save">
            @if ($notice)
                <p class="flash" role="status">{{ $notice }}</p>
            @endif

            <section class="panel">
                <h2>Identidad</h2>
                <div class="form-row">
                    <div class="field">
                        <label for="agent-name">Nombre del agente</label>
                        <input id="agent-name" type="text" wire:model="name">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="agent-tone">Tono</label>
                        <input id="agent-tone" type="text" wire:model="tone" placeholder="cercano y profesional">
                    </div>
                </div>
                <div class="field">
                    <label for="agent-instructions">Instrucciones</label>
                    <textarea id="agent-instructions" rows="8" wire:model="instructions"></textarea>
                    <p class="hint">Qué debe lograr, qué evitar y cuándo pasar a una persona. Los precios y horarios no van aquí: salen del catálogo y del horario del negocio.</p>
                    @error('instructions') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="agent-handoff">Mensaje al pasar a una persona</label>
                    <input id="agent-handoff" type="text" wire:model="handoffMessage" placeholder="Te comunico con una persona de nuestro equipo, en breve te responde.">
                </div>
            </section>

            <section class="panel">
                <h2>Cómo responde</h2>
                <div class="choice-grid" role="radiogroup" aria-label="Modo">
                    <label @class(['choice', 'active' => $mode === 'auto'])>
                        <input type="radio" name="mode" value="auto" wire:model.live="mode">
                        <strong>Automático</strong>
                        <span>El agente responde solo, al instante.</span>
                    </label>
                    <label @class(['choice', 'active' => $mode === 'sugerir'])>
                        <input type="radio" name="mode" value="sugerir" wire:model.live="mode">
                        <strong>Sugerir</strong>
                        <span>El agente deja un borrador y una persona lo aprueba en la bandeja.</span>
                    </label>
                </div>
                <div class="choice-grid" role="radiogroup" aria-label="Modelo">
                    <label @class(['choice', 'active' => $modelTier === 'fast'])>
                        <input type="radio" name="tier" value="fast" wire:model.live="modelTier">
                        <strong>Rápido</strong>
                        <span>Ideal para la mayoría de negocios. Menor costo por conversación.</span>
                    </label>
                    <label @class(['choice', 'active' => $modelTier === 'smart'])>
                        <input type="radio" name="tier" value="smart" wire:model.live="modelTier">
                        <strong>Avanzado</strong>
                        <span>Para ventas complejas o asesoría. Cuesta varias veces más por conversación.</span>
                    </label>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Datos que el agente debe obtener</h2>
                        <p class="panel-sub">Los pide de forma natural y los guarda en la ficha del cliente. Con todos completos, el cliente queda calificado.</p>
                    </div>
                    <button type="button" class="btn" wire:click="addField">Agregar dato</button>
                </div>
                <ol class="rows">
                    @forelse ($fields as $i => $field)
                        <li class="row" wire:key="field-{{ $i }}">
                            <label class="sr-only" for="field-q-{{ $i }}">Dato</label>
                            <input id="field-q-{{ $i }}" type="text" wire:model="fields.{{ $i }}.question" placeholder="Por ejemplo: presupuesto aproximado">
                            <button type="button" class="icon-btn" wire:click="removeField({{ $i }})" aria-label="Quitar dato">✕</button>
                            @error("fields.$i.question") <p class="form-error">{{ $message }}</p> @enderror
                        </li>
                    @empty
                        <li class="rows-empty">Sin datos configurados.</li>
                    @endforelse
                </ol>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Respuestas rápidas</h2>
                        <p class="panel-sub">Respuestas fijas para mensajes cortos y repetidos. No usan IA: son instantáneas y gratis.</p>
                    </div>
                    <button type="button" class="btn" wire:click="addQuickReply">Agregar respuesta</button>
                </div>
                <ol class="rows">
                    @forelse ($quickReplies as $i => $rule)
                        <li class="row quick" wire:key="qr-{{ $i }}">
                            <div class="field">
                                <label for="qr-k-{{ $i }}">Si el mensaje dice</label>
                                <input id="qr-k-{{ $i }}" type="text" wire:model="quickReplies.{{ $i }}.keywords" placeholder="horario, a qué hora abren">
                                @error("quickReplies.$i.keywords") <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="field">
                                <label for="qr-r-{{ $i }}">Responder</label>
                                <textarea id="qr-r-{{ $i }}" rows="2" wire:model="quickReplies.{{ $i }}.reply"></textarea>
                                @error("quickReplies.$i.reply") <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" class="icon-btn" wire:click="removeQuickReply({{ $i }})" aria-label="Quitar respuesta">✕</button>
                        </li>
                    @empty
                        <li class="rows-empty">Sin respuestas rápidas.</li>
                    @endforelse
                </ol>
            </section>

            <div class="save-bar">
                <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="save">Guardar cambios</button>
            </div>
        </form>

        <aside class="test-chat" aria-label="Probar el agente">
            <header>
                <div>
                    <h2>Prueba tu agente</h2>
                    <p>Escríbele como lo haría un cliente. No pasa por WhatsApp ni aparece en la bandeja.</p>
                </div>
                <button type="button" class="btn ghost" wire:click="resetTest">Reiniciar</button>
            </header>
            <div class="thread" id="thread" data-conversation="prueba">
                @forelse ($this->testMessages as $message)
                    <div @class(['bubble', $message->direction, 'is-draft' => $message->isDraft()]) wire:key="t-{{ $message->id }}">
                        <div class="bubble-body">{!! $message->htmlBody() !!}</div>
                        @if ($message->direction === 'out')
                            <div class="bubble-meta">
                                <span class="author src-{{ $message->source }}">{{ $message->isDraft() ? 'Borrador' : $message->authorLabel() }}</span>
                                @if ($message->cost_usd > 0)
                                    <span>US${{ number_format($message->cost_usd, 4, ',', '.') }}</span>
                                @endif
                            </div>
                            @if (! empty($message->meta['tool_calls']))
                                <details class="tools">
                                    <summary>{{ count($message->meta['tool_calls']) }} {{ count($message->meta['tool_calls']) === 1 ? 'consulta' : 'consultas' }}</summary>
                                    <ul>
                                        @foreach ($message->meta['tool_calls'] as $call)
                                            <li><code>{{ $call['name'] }}</code> {{ collect($call['input'])->implode(' · ') }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        @endif
                    </div>
                @empty
                    <div class="test-empty">
                        <p>Prueba con:</p>
                        @foreach ($prompts as $prompt)
                            <button type="button" class="mini" wire:click="$set('testMessage', @js($prompt))">{{ $prompt }}</button>
                        @endforeach
                    </div>
                @endforelse
                <div class="typing" wire:loading.flex wire:target="sendTest"><span></span><span></span><span></span></div>
            </div>
            @if ($testError)
                <p class="flash error" role="alert">{{ $testError }}</p>
            @endif
            <form class="composer" wire:submit="sendTest">
                <label for="test-message" class="sr-only">Mensaje de prueba</label>
                <textarea id="test-message" rows="1" wire:model="testMessage" placeholder="Escribe como cliente…" x-data x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"></textarea>
                <button type="submit" class="send" aria-label="Enviar" wire:loading.attr="disabled" wire:target="sendTest">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 20.5 21.5 12 3 3.5 3 10l13 2-13 2z"/></svg>
                </button>
            </form>
        </aside>
    </div>
</div>

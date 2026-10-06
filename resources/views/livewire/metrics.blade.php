@php
    $m = $this->metrics;
    $c = $this->chart;
    $pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 0).' %';
    $usd = fn (?float $v, int $d = 2) => $v === null ? '—' : 'US$'.number_format($v, $d, ',', '.');
    $duration = function (?int $s) {
        return match (true) {
            $s === null => '—',
            $s < 60 => $s.' s',
            $s < 3600 => intdiv($s, 60).' min'.($s % 60 ? ' '.($s % 60).' s' : ''),
            default => intdiv($s, 3600).' h '.intdiv($s % 3600, 60).' min',
        };
    };
    // Columna con el extremo de datos redondeado (4 px) y la base recta.
    $roundTop = function (float $x, float $y, float $w, float $h, float $r = 4) {
        if ($h <= 0) {
            return '';
        }
        $r = min($r, $h, $w / 2);
        $b = $y + $h;

        return "M{$x},{$b} V".($y + $r)." Q{$x},{$y} ".($x + $r).",{$y} H".($x + $w - $r)." Q".($x + $w).",{$y} ".($x + $w).','.($y + $r)." V{$b} Z";
    };
    $planUsed = $m['plan']['used'];
    $planLimit = max(1, $m['plan']['limit']);
    $planRatio = min(1, $planUsed / $planLimit);
    $planState = $planRatio >= 1 ? 'critical' : ($planRatio >= .8 ? 'warning' : 'ok');
    $funnel = $m['funnel'];
    $funnelMax = max(1, $funnel['contacts']);
    $repliesTotal = max(1, array_sum($m['replies']));
@endphp

<div class="page metrics">
    <header class="page-head">
        <div class="page-title">
            <h1>Métricas</h1>
            <p>Lo que hizo tu agente en los últimos {{ $days }} días.</p>
        </div>
        <div class="segmented" role="group" aria-label="Periodo">
            @foreach ($periods as $value => $label)
                <button type="button" wire:click="$set('days', {{ $value }})" @class(['active' => $days === $value]) aria-pressed="{{ $days === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </header>

    <section class="kpis" aria-label="Indicadores principales">
        <article class="kpi hero">
            <span class="kpi-label">Conversaciones</span>
            <strong class="kpi-value">{{ number_format($m['conversations'], 0, ',', '.') }}</strong>
            <span class="kpi-sub">{{ $m['bot_only'] }} resueltas solo por el agente · {{ $m['handed_off'] }} con una persona</span>
        </article>
        <article class="kpi">
            <span class="kpi-label">Resueltas sin una persona</span>
            <strong class="kpi-value">{{ $pct($m['bot_only_rate']) }}</strong>
            <span class="kpi-sub">Meta del producto: más de 70 %</span>
        </article>
        <article class="kpi">
            <span class="kpi-label">Primera respuesta (mediana)</span>
            <strong class="kpi-value">{{ $duration($m['first_response_seconds']) }}</strong>
            <span class="kpi-sub">Incluye la espera para agrupar mensajes</span>
        </article>
        <article class="kpi">
            <span class="kpi-label">Citas agendadas por el agente</span>
            <strong class="kpi-value">{{ $m['appointments_by_agent'] }}</strong>
            <span class="kpi-sub">de {{ $funnel['appointments'] }} citas creadas en el periodo</span>
        </article>
        <article class="kpi">
            <span class="kpi-label">Costo de IA</span>
            <strong class="kpi-value">{{ $usd($m['cost_usd']) }}</strong>
            <span class="kpi-sub">{{ $usd($m['cost_per_conversation'], 4) }} por conversación</span>
        </article>
    </section>

    <section class="panel chart-panel" aria-labelledby="chart-title">
        <header class="panel-head">
            <div>
                <h2 id="chart-title">Conversaciones por día</h2>
                <ul class="legend" aria-label="Leyenda">
                    <li><span class="key agent"></span>Solo el agente</li>
                    <li><span class="key human"></span>Con una persona</li>
                </ul>
            </div>
            <button type="button" class="btn ghost" wire:click="$toggle('showTable')">{{ $showTable ? 'Ver gráfica' : 'Ver tabla' }}</button>
        </header>

        @if ($showTable)
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Día</th><th scope="col">Solo el agente</th><th scope="col">Con una persona</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                        @foreach (array_reverse($m['daily']) as $day)
                            <tr><th scope="row">{{ $day['label'] }}</th><td>{{ $day['bot'] }}</td><td>{{ $day['human'] }}</td><td>{{ $day['bot'] + $day['human'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="chart" x-data="{ tip: null }" x-on:mouseleave="tip = null">
                <svg viewBox="0 0 {{ $c['width'] }} {{ $c['height'] }}" role="img" aria-label="Conversaciones por día: solo el agente y con una persona">
                    @foreach ($c['ticks'] as $tick)
                        <line class="grid" x1="{{ $c['pad']['left'] }}" x2="{{ $c['width'] - $c['pad']['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"></line>
                        <text class="tick" x="{{ $c['pad']['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['value'] }}</text>
                    @endforeach

                    @foreach ($c['bars'] as $bar)
                        <g class="col" x-on:mouseenter="tip = {{ Js::from(['label' => $bar['label'], 'bot' => $bar['bot'], 'human' => $bar['human'], 'x' => $bar['cx'] / $c['width'] * 100]) }}">
                            <rect class="hit" x="{{ $bar['hit_x'] }}" y="{{ $c['pad']['top'] }}" width="{{ $c['slot'] }}" height="{{ $c['baseline'] - $c['pad']['top'] }}"></rect>
                            @if ($bar['human'] > 0)
                                <path class="seg human" d="{{ $roundTop($bar['x'], $bar['human_y'], $c['bar_w'], $bar['human_h']) }}"></path>
                                @if ($bar['bot'] > 0)
                                    <rect class="seg agent" x="{{ $bar['x'] }}" y="{{ $bar['bot_y'] }}" width="{{ $c['bar_w'] }}" height="{{ $bar['bot_h'] }}"></rect>
                                @endif
                            @elseif ($bar['bot'] > 0)
                                <path class="seg agent" d="{{ $roundTop($bar['x'], $bar['bot_y'], $c['bar_w'], $bar['bot_h']) }}"></path>
                            @endif
                            @if ($bar['show_label'])
                                <text class="tick" x="{{ $bar['cx'] }}" y="{{ $c['height'] - 8 }}" text-anchor="middle">{{ $bar['label'] }}</text>
                            @endif
                        </g>
                    @endforeach
                    <line class="axis" x1="{{ $c['pad']['left'] }}" x2="{{ $c['width'] - $c['pad']['right'] }}" y1="{{ $c['baseline'] }}" y2="{{ $c['baseline'] }}"></line>
                </svg>
                <div class="tooltip" x-show="tip" x-cloak x-bind:style="tip && `left: ${tip.x}%`">
                    <strong x-text="tip?.label"></strong>
                    <span><i class="key agent"></i>Solo el agente <b x-text="tip?.bot"></b></span>
                    <span><i class="key human"></i>Con una persona <b x-text="tip?.human"></b></span>
                </div>
            </div>
        @endif
    </section>

    <div class="panel-grid">
        <section class="panel" aria-labelledby="funnel-title">
            <h2 id="funnel-title">Del primer mensaje a la cita</h2>
            <p class="panel-sub">Contactos nuevos en el periodo.</p>
            <ol class="bars">
                @foreach ([
                    ['Escribieron por primera vez', $funnel['contacts']],
                    ['Quedaron calificados', $funnel['qualified']],
                    ['Agendaron una cita', $funnel['appointments']],
                    ['Asistieron', $funnel['attended']],
                ] as [$label, $value])
                    <li>
                        <span class="bar-label">{{ $label }}</span>
                        <span class="bar-track"><span class="bar-fill" style="width: {{ $value / $funnelMax * 100 }}%"></span></span>
                        <span class="bar-value">{{ $value }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="panel" aria-labelledby="replies-title">
            <h2 id="replies-title">Quién respondió</h2>
            <p class="panel-sub">Mensajes enviados en el periodo.</p>
            <ol class="bars">
                @foreach ([
                    ['Respuestas automáticas (sin IA)', $m['replies']['rule']],
                    ['Agente IA', $m['replies']['llm']],
                    ['Personas del equipo', $m['replies']['human']],
                ] as [$label, $value])
                    <li>
                        <span class="bar-label">{{ $label }}</span>
                        <span class="bar-track"><span class="bar-fill" style="width: {{ $value / $repliesTotal * 100 }}%"></span></span>
                        <span class="bar-value">{{ $value }} <small>{{ number_format($value / $repliesTotal * 100, 0) }} %</small></span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="panel plan" aria-labelledby="plan-title">
            <h2 id="plan-title">Uso del plan este mes</h2>
            <p class="plan-figure"><b>{{ number_format($planUsed, 0, ',', '.') }}</b> de {{ number_format($planLimit, 0, ',', '.') }} conversaciones</p>
            <div class="meter {{ $planState }}" role="meter" aria-valuemin="0" aria-valuemax="{{ $planLimit }}" aria-valuenow="{{ $planUsed }}" aria-label="Conversaciones usadas del plan">
                <span style="width: {{ $planRatio * 100 }}%"></span>
            </div>
            <p class="panel-sub">
                @if ($planState === 'critical')
                    <span class="status critical">⚠ Límite alcanzado.</span> Las conversaciones adicionales se cobran como excedente.
                @elseif ($planState === 'warning')
                    <span class="status warning">● Cerca del límite.</span> Quedan {{ $planLimit - $planUsed }} conversaciones.
                @else
                    Quedan {{ $planLimit - $planUsed }} conversaciones. Una conversación es cada ventana de 24 h con un cliente.
                @endif
            </p>
        </section>
    </div>
</div>

<?php

namespace App\Livewire;

use App\Livewire\Concerns\ScopedToTenant;
use App\Services\Metrics\TenantMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Métricas')]
class Metrics extends Component
{
    use ScopedToTenant;

    public const PERIODS = [7 => '7 días', 30 => '30 días', 90 => '90 días'];

    #[Url(as: 'dias')]
    public int $days = 30;

    public bool $showTable = false;

    public function mount(): void
    {
        if (! array_key_exists($this->days, self::PERIODS)) {
            $this->days = 30;
        }
    }

    public function updatedDays(): void
    {
        unset($this->metrics, $this->chart);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function metrics(): array
    {
        $tenant = Auth::user()->tenant;
        $to = CarbonImmutable::now($tenant->timezone)->endOfDay();
        $from = $to->subDays($this->days - 1)->startOfDay();

        return app(TenantMetrics::class)->compute($tenant, $from, $to);
    }

    /**
     * Geometría de la gráfica de columnas apiladas (una escala para todo).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chart(): array
    {
        $daily = $this->metrics['daily'];
        $width = 960;
        $height = 260;
        $pad = ['top' => 16, 'right' => 8, 'bottom' => 28, 'left' => 36];
        $plotW = $width - $pad['left'] - $pad['right'];
        $plotH = $height - $pad['top'] - $pad['bottom'];

        $max = max(1, ...array_map(fn ($d) => $d['bot'] + $d['human'], $daily));
        $step = $this->niceStep($max);
        $top = (int) (ceil($max / $step) * $step);
        $y = fn (float $v) => $pad['top'] + $plotH - ($v / $top) * $plotH;

        $slot = $plotW / count($daily);
        $barW = min(24, max(4, $slot * 0.62));
        $labelEvery = (int) ceil(count($daily) / 10);

        $bars = [];
        foreach ($daily as $i => $day) {
            $x = $pad['left'] + $i * $slot + ($slot - $barW) / 2;
            $botTop = $y($day['bot']);
            $humanTop = $y($day['bot'] + $day['human']);
            $bars[] = [
                ...$day,
                'x' => round($x, 2),
                'cx' => round($x + $barW / 2, 2),
                'hit_x' => round($pad['left'] + $i * $slot, 2),
                'bot_y' => round($botTop, 2),
                'bot_h' => round($y(0) - $botTop, 2),
                // 2 px de separación con el color de fondo entre segmentos.
                'human_y' => round($humanTop, 2),
                'human_h' => round(max(0, $botTop - $humanTop - ($day['bot'] > 0 ? 2 : 0)), 2),
                'show_label' => $i % $labelEvery === (count($daily) - 1) % $labelEvery,
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'pad' => $pad,
            'slot' => round($slot, 2),
            'bar_w' => round($barW, 2),
            'baseline' => $y(0),
            'ticks' => collect(range(0, $top, $step))->map(fn ($v) => ['value' => $v, 'y' => round($y($v), 2)])->all(),
            'bars' => $bars,
        ];
    }

    public function render()
    {
        return view('livewire.metrics', ['periods' => self::PERIODS]);
    }

    private function niceStep(int $max): int
    {
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000] as $step) {
            if ($max / $step <= 5) {
                return $step;
            }
        }

        return (int) ceil($max / 5);
    }
}

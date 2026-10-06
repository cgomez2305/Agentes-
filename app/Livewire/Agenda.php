<?php

namespace App\Livewire;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\GoogleCalendar;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Agenda')]
class Agenda extends Component
{
    use ScopedToTenant;

    /** Lunes de la semana visible, AAAA-MM-DD. */
    #[Url(as: 'semana')]
    public string $week = '';

    public bool $showForm = false;

    public bool $showSettings = false;

    /** @var array{phone: string, name: string, date: string, time: string, service_id: string, notes: string} */
    public array $form = ['phone' => '', 'name' => '', 'date' => '', 'time' => '', 'service_id' => '', 'notes' => ''];

    /** @var array<string, mixed> */
    public array $settings = [];

    public ?int $confirmingCancel = null;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->week = $this->mondayOf($this->week ?: null)->format('Y-m-d');
        $this->notice = session('agenda_notice');
        $this->error = session('agenda_error');
        $this->loadSettings();
    }

    public function previousWeek(): void
    {
        $this->week = $this->monday()->subWeek()->format('Y-m-d');
    }

    public function nextWeek(): void
    {
        $this->week = $this->monday()->addWeek()->format('Y-m-d');
    }

    public function thisWeek(): void
    {
        $this->week = $this->mondayOf(null)->format('Y-m-d');
    }

    public function openForm(?string $date = null, ?string $time = null): void
    {
        $this->form = [...$this->form, 'date' => $date ?? now($this->tenant()->timezone)->format('Y-m-d'), 'time' => $time ?? ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function book(BookingService $booking): void
    {
        $this->validate([
            'form.phone' => ['required', 'regex:/^\+?[\d\s-]{10,16}$/'],
            'form.name' => ['nullable', 'string', 'max:120'],
            'form.date' => ['required', 'date_format:Y-m-d'],
            'form.time' => ['required', 'date_format:H:i'],
            'form.service_id' => ['nullable', 'integer'],
            'form.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'form.phone.required' => 'Escribe el número de WhatsApp del cliente.',
            'form.phone.regex' => 'Escribe el número con indicativo, por ejemplo +57 300 111 2233.',
            'form.date.required' => 'Elige el día.',
            'form.time.required' => 'Elige la hora.',
        ]);

        $tenant = $this->tenant();
        $waId = $this->normalizePhone($this->form['phone']);
        $contact = Contact::firstOrCreate(['wa_id' => $waId], ['name' => $this->form['name'] ?: null]);
        if ($this->form['name'] && ! $contact->name) {
            $contact->update(['name' => $this->form['name']]);
        }

        $service = $this->form['service_id'] ? CatalogItem::find($this->form['service_id']) : null;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->form['date'].' '.$this->form['time'], $tenant->timezone);

        try {
            $appointment = $booking->book($tenant, $contact, $start, $service, source: 'human', notes: $this->form['notes'] ?: null);
        } catch (BookingException $e) {
            $this->addError('form.time', $e->getMessage());

            return;
        }

        $this->week = $this->mondayOf($start->format('Y-m-d'))->format('Y-m-d');
        $this->reset('form', 'showForm');
        $this->flash("Cita agendada para {$contact->displayName()}: ".$this->describe($appointment).'.');
    }

    public function setStatus(int $id, string $status, BookingService $booking): void
    {
        abort_unless(array_key_exists($status, Appointment::STATUS_LABELS), 422);
        $appointment = Appointment::findOrFail($id);

        if ($status === Appointment::CANCELLED) {
            $booking->cancel($this->tenant(), $appointment);
            $this->confirmingCancel = null;
            $this->flash('Cita cancelada. El horario quedó libre para el agente.');

            return;
        }

        $appointment->update(['status' => $status]);
        $this->flash(null);
    }

    public function saveSettings(): void
    {
        $this->validate([
            'settings.enabled' => ['boolean'],
            'settings.default_duration' => ['required', 'integer', 'min:10', 'max:480'],
            'settings.slot_minutes' => ['required', 'integer', 'in:15,20,30,45,60,90,120'],
            'settings.min_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'settings.max_days_ahead' => ['required', 'integer', 'min:1', 'max:180'],
            'settings.capacity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $this->tenant()->update(['booking_settings' => [
            'enabled' => (bool) $this->settings['enabled'],
            'default_duration' => (int) $this->settings['default_duration'],
            'slot_minutes' => (int) $this->settings['slot_minutes'],
            'min_notice_hours' => (int) $this->settings['min_notice_hours'],
            'max_days_ahead' => (int) $this->settings['max_days_ahead'],
            'capacity' => (int) $this->settings['capacity'],
        ]]);

        $this->showSettings = false;
        $this->flash($this->settings['enabled']
            ? 'Configuración guardada. El agente agenda con estas reglas.'
            : 'Configuración guardada. El agente ya no agenda: toma los datos y pasa a un asesor.');
    }

    /**
     * Días de la semana visible con sus citas.
     *
     * @return Collection<int, array{date: CarbonImmutable, open: ?array, appointments: Collection}>
     */
    #[Computed]
    public function days(): Collection
    {
        $tenant = $this->tenant();
        $monday = $this->monday();

        $appointments = Appointment::query()
            ->with(['contact', 'service'])
            ->where('starts_at', '>=', $monday->utc())
            ->where('starts_at', '<', $monday->addWeek()->utc())
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Appointment $a) => $a->starts_at->setTimezone($tenant->timezone)->format('Y-m-d'));

        return collect(range(0, 6))
            ->map(fn (int $i) => $monday->addDays($i))
            ->map(fn (CarbonImmutable $date) => [
                'date' => $date,
                'open' => ($tenant->business_hours ?? [])[strtolower($date->format('D'))] ?? null,
                'appointments' => $appointments->get($date->format('Y-m-d'), collect()),
            ])
            // Los días cerrados sin citas no ocupan una columna.
            ->filter(fn (array $day) => $day['open'] || $day['appointments']->isNotEmpty() || empty($tenant->business_hours))
            ->values();
    }

    /**
     * @return array{total: int, confirmed: int, by_agent: int, no_show: int}
     */
    #[Computed]
    public function weekStats(): array
    {
        $all = $this->days->flatMap(fn ($day) => $day['appointments'])->reject(fn ($a) => $a->contact->is_test);

        return [
            'total' => $all->where('status', '!=', Appointment::CANCELLED)->count(),
            'confirmed' => $all->where('status', Appointment::CONFIRMED)->count(),
            'by_agent' => $all->where('status', '!=', Appointment::CANCELLED)->where('source', 'agent')->count(),
            'no_show' => $all->where('status', Appointment::NO_SHOW)->count(),
        ];
    }

    public function render(GoogleCalendar $google)
    {
        $tenant = $this->tenant();

        return view('livewire.agenda', [
            'tenant' => $tenant,
            'connection' => $tenant->calendarConnection,
            'googleAvailable' => $google->isConfigured(),
            'services' => CatalogItem::query()->where('is_available', true)->whereNotNull('duration_minutes')->orderBy('name')->get(),
            'monday' => $this->monday(),
        ]);
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get()->fresh();
    }

    private function monday(): CarbonImmutable
    {
        return $this->mondayOf($this->week ?: null);
    }

    private function mondayOf(?string $date): CarbonImmutable
    {
        $tz = Auth::user()->tenant->timezone;
        $day = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz)
            : CarbonImmutable::now($tz);

        return $day->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function loadSettings(): void
    {
        $tenant = $this->tenant();
        $this->settings = collect(Tenant::BOOKING_DEFAULTS)->map(fn ($v, $key) => $tenant->booking($key))->all();
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // Números colombianos de 10 dígitos sin indicativo.
        return strlen($digits) === 10 && str_starts_with($digits, '3') ? '57'.$digits : $digits;
    }

    private function describe(Appointment $appointment): string
    {
        return $appointment->starts_at->setTimezone($this->tenant()->timezone)->locale('es')->isoFormat('dddd D [de] MMMM, h:mm a');
    }

    private function flash(?string $message): void
    {
        $this->notice = $message;
        $this->error = null;
        unset($this->days, $this->weekStats);
    }
}

<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integración mínima con Google Calendar por REST (sin SDK): OAuth,
 * consulta de ocupación (freeBusy) y creación o borrado de eventos.
 */
class GoogleCalendar
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
        'openid',
        'email',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: CarbonImmutable, email: ?string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw();

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => CarbonImmutable::now()->addSeconds((int) $response->json('expires_in', 3600) - 60),
            'email' => $this->emailFromIdToken($response->json('id_token')),
        ];
    }

    /**
     * Intervalos ocupados del calendario entre dos instantes.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function busy(CalendarConnection $connection, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $response = $this->client($connection)->post(self::API.'/freeBusy', [
            'timeMin' => CarbonImmutable::instance($from)->utc()->toIso8601ZuluString(),
            'timeMax' => CarbonImmutable::instance($to)->utc()->toIso8601ZuluString(),
            'items' => [['id' => $connection->calendar_id]],
        ])->throw();

        return collect($response->json("calendars.{$connection->calendar_id}.busy", []))
            ->map(fn (array $slot) => [CarbonImmutable::parse($slot['start']), CarbonImmutable::parse($slot['end'])])
            ->all();
    }

    public function createEvent(CalendarConnection $connection, Appointment $appointment, string $timezone): string
    {
        $contact = $appointment->contact;

        $response = $this->client($connection)->post(self::API.'/calendars/'.urlencode($connection->calendar_id).'/events', [
            'summary' => $appointment->title.' · '.$contact->displayName(),
            'description' => "Agendada por WhatsApp.\nCliente: {$contact->displayName()}\nTeléfono: {$contact->displayPhone()}"
                .($appointment->notes ? "\nNotas: {$appointment->notes}" : ''),
            'start' => ['dateTime' => $appointment->starts_at->toIso8601String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $appointment->ends_at->toIso8601String(), 'timeZone' => $timezone],
        ])->throw();

        return (string) $response->json('id');
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $response = $this->client($connection)->delete(self::API.'/calendars/'.urlencode($connection->calendar_id).'/events/'.urlencode($eventId));

        // 410: el evento ya no existía.
        if ($response->failed() && $response->status() !== 410 && $response->status() !== 404) {
            $response->throw();
        }
    }

    private function client(CalendarConnection $connection): PendingRequest
    {
        if ($connection->expires_at === null || $connection->expires_at->isPast()) {
            $this->refresh($connection);
        }

        return Http::withToken($connection->access_token)->acceptJson()->timeout(10);
    }

    private function refresh(CalendarConnection $connection): void
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('La conexión con Google Calendar expiró. Vuelve a conectarla desde la agenda.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw();

        $connection->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600) - 60),
        ]);
    }

    private function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('agenda.google.callback');
    }

    private function emailFromIdToken(?string $idToken): ?string
    {
        $payload = $idToken ? explode('.', $idToken)[1] ?? null : null;

        if (! $payload) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return $claims['email'] ?? null;
    }
}

<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Services\Booking\GoogleCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    public function connect(Request $request, GoogleCalendar $google): RedirectResponse
    {
        abort_unless($google->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendar $google): RedirectResponse
    {
        $expected = $request->session()->pull('google_calendar_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('agenda')->with('agenda_error', 'La conexión con Google no se pudo verificar. Inténtalo de nuevo.');
        }

        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('agenda')->with('agenda_error', 'No se otorgó acceso a Google Calendar.');
        }

        $tokens = $google->exchangeCode((string) $request->query('code'));

        CalendarConnection::updateOrCreate([], [
            'provider' => 'google',
            'account_email' => $tokens['email'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
        ]);

        return redirect()->route('agenda')->with('agenda_notice', 'Google Calendar conectado. Las citas nuevas aparecerán allá y los eventos de tu calendario bloquearán esos horarios.');
    }

    public function disconnect(): RedirectResponse
    {
        CalendarConnection::query()->delete();

        return redirect()->route('agenda')->with('agenda_notice', 'Google Calendar desconectado.');
    }
}

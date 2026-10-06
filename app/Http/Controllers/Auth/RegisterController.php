<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Onboarding\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /** Plantillas que se ofrecen en el registro, con su descripción comercial. */
    public const VERTICALS = [
        'clinica' => ['Clínica o consultorio', 'Odontología, estética, medicina. Agenda valoraciones y resuelve dudas de tratamientos.'],
        'inmobiliaria' => ['Inmobiliaria o constructora', 'Califica interesados por zona y presupuesto y agenda visitas.'],
        'tienda' => ['Tienda en línea', 'Recomienda productos, responde envíos y pagos y lleva al cliente a comprar.'],
    ];

    public function show(): View
    {
        return view('auth.register', ['verticals' => self::VERTICALS]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'business' => ['required', 'string', 'max:120'],
            'vertical' => ['required', Rule::in(array_keys(self::VERTICALS))],
            'address' => ['nullable', 'string', 'max:200'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'consent' => ['accepted'],
        ], [
            'business.required' => 'Escribe el nombre de tu negocio.',
            'vertical.required' => 'Elige el tipo de negocio.',
            'name.required' => 'Escribe tu nombre.',
            'email.unique' => 'Ya existe una cuenta con ese correo. Ingresa con él.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'consent.accepted' => 'Necesitamos tu autorización para tratar los datos.',
        ]);

        $user = DB::transaction(function () use ($data, $provisioner) {
            $tenant = $provisioner->create($data['business'], $data['vertical'], array_filter(['direccion' => $data['address'] ?? null]));

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => 'owner',
                'password' => $data['password'],
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('settings.agent')->with('welcome', true);
    }
}

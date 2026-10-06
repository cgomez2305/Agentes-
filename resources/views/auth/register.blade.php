<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crea tu agente · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Figtree:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    @vite(['resources/css/app.css'])
</head>
<body class="auth">
    <main class="auth-card wide">
        <span class="brand-mark lg" aria-hidden="true"></span>
        <h1>Crea el agente de WhatsApp de tu negocio</h1>
        <p>En unos minutos tienes un agente que responde, califica y agenda. Lo configuras y lo pruebas antes de conectar tu número.</p>

        <form method="POST" action="{{ route('register') }}" class="form" novalidate>
            @csrf

            <fieldset class="vertical-picker">
                <legend>¿Qué tipo de negocio tienes?</legend>
                @foreach ($verticals as $key => [$label, $description])
                    <label class="vertical-option">
                        <input type="radio" name="vertical" value="{{ $key }}" @checked(old('vertical', 'clinica') === $key)>
                        <span>
                            <strong>{{ $label }}</strong>
                            <small>{{ $description }}</small>
                        </span>
                    </label>
                @endforeach
                @error('vertical') <p class="form-error">{{ $message }}</p> @enderror
            </fieldset>

            <label for="business">Nombre del negocio</label>
            <input id="business" name="business" type="text" value="{{ old('business') }}" required autocomplete="organization">
            @error('business') <p class="form-error">{{ $message }}</p> @enderror

            <label for="address">Dirección <span class="optional">(opcional)</span></label>
            <input id="address" name="address" type="text" value="{{ old('address') }}" placeholder="Calle 10 # 43-20, Medellín" autocomplete="street-address">

            <div class="form-row">
                <div>
                    <label for="name">Tu nombre</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name">
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email">Correo</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                    @error('email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="password">Contraseña</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password">
                    @error('password') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation">Repite la contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
            </div>

            <label class="check">
                <input type="checkbox" name="consent" id="consent" value="1" @checked(old('consent'))>
                Autorizo el tratamiento de mis datos y los de mis clientes para prestar el servicio (Ley 1581 de 2012).
            </label>
            @error('consent') <p class="form-error">{{ $message }}</p> @enderror

            <button type="submit" class="btn primary">Crear mi agente</button>
            <p class="auth-switch">¿Ya tienes cuenta? <a href="{{ route('login') }}">Ingresa</a></p>
        </form>
    </main>
</body>
</html>

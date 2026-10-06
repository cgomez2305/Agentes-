<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Figtree:wght@400;500;600&display=swap">
    @vite(['resources/css/app.css'])
</head>
<body class="auth">
    <main class="auth-card">
        <span class="brand-mark lg" aria-hidden="true"></span>
        <h1>Ingresa a tu bandeja</h1>
        <p>Atiende las conversaciones que tu agente pasó a una persona y aprueba sus borradores.</p>

        <form method="POST" action="{{ url('/ingresar') }}" class="form">
            @csrf
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            @error('email')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <label class="check"><input type="checkbox" name="remember" id="remember"> Mantener la sesión abierta</label>

            <button type="submit" class="btn primary">Ingresar</button>
        </form>
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Figtree:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="app">
    <header class="topbar">
        <a class="brand" href="{{ route('inbox') }}">
            <span class="brand-mark" aria-hidden="true"></span>
            <span>{{ auth()->user()->tenant->name }}</span>
        </a>
        <nav class="topnav" aria-label="Principal">
            <a href="{{ route('inbox') }}" @class(['active' => request()->routeIs('inbox')])>Bandeja</a>
            <a href="{{ route('agenda') }}" @class(['active' => request()->routeIs('agenda*')])>Agenda</a>
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="user">
            @csrf
            <span class="user-avatar" title="{{ auth()->user()->name }}">{{ auth()->user()->initials() }}</span>
            <button type="submit" class="link-button">Salir</button>
        </form>
    </header>

    <main class="main">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>

<nav class="subnav" aria-label="Secciones de configuración">
    @foreach ([
        'settings.agent' => 'Agente',
        'settings.business' => 'Negocio',
        'settings.knowledge' => 'Conocimiento y catálogo',
        'settings.followups' => 'Seguimientos',
    ] as $route => $label)
        <a href="{{ route($route) }}" @class(['active' => request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</nav>

<nav class="subnav" aria-label="Secciones de configuración">
    {{-- La sección llega explícita: en las peticiones de Livewire la ruta actual no es la de la página. --}}
    @foreach ([
        'agent' => 'Agente',
        'business' => 'Negocio',
        'knowledge' => 'Conocimiento y catálogo',
        'followups' => 'Seguimientos',
    ] as $key => $label)
        <a href="{{ route('settings.'.$key) }}" @class(['active' => $section === $key]) @if ($section === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

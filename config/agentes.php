<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    |
    | Conexión directa a la Cloud API, sin BSP intermedio. El token de acceso
    | de cada número vive cifrado en la tabla `channels`; aquí solo están los
    | valores globales de la app de Meta.
    |
    */

    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Procesamiento de mensajes
    |--------------------------------------------------------------------------
    */

    // Segundos de espera para agrupar mensajes seguidos del mismo usuario.
    'debounce_seconds' => (int) env('AGENTES_DEBOUNCE_SECONDS', 6),

    // Mensajes recientes que entran al contexto del LLM (el resto va en el resumen).
    'context_messages' => (int) env('AGENTES_CONTEXT_MESSAGES', 8),

    // Fragmentos de conocimiento que se inyectan por turno.
    'knowledge_top_k' => (int) env('AGENTES_KNOWLEDGE_TOP_K', 4),

    // Largo máximo de una respuesta enviada por WhatsApp.
    'max_reply_chars' => (int) env('AGENTES_MAX_REPLY_CHARS', 700),

    // Máximo de vueltas de herramientas por turno (evita bucles).
    'max_tool_rounds' => (int) env('AGENTES_MAX_TOOL_ROUNDS', 5),

    /*
    |--------------------------------------------------------------------------
    | LLM
    |--------------------------------------------------------------------------
    |
    | Enrutamiento por complejidad: el modelo "fast" atiende la mayoría de
    | turnos y el "smart" solo los casos ambiguos o de cierre. Los precios son
    | USD por millón de tokens y sirven para registrar el costo por tenant.
    |
    */

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'anthropic'),

        'models' => [
            'fast' => env('LLM_MODEL_FAST', 'claude-haiku-4-5'),
            'smart' => env('LLM_MODEL_SMART', 'claude-opus-5-5'),
        ],

        'max_output_tokens' => (int) env('LLM_MAX_OUTPUT_TOKENS', 1024),

        // Esfuerzo de razonamiento para los modelos que lo admiten (no aplica a Haiku).
        'effort' => env('LLM_EFFORT', 'low'),

        'pricing' => [
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10, 'cache_write' => 1.25],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
    ],

];

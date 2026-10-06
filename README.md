# Agentes de IA para WhatsApp

SaaS multi-tenant para que una pyme tenga un agente de IA que responde, califica y pasa a un humano por WhatsApp.
El documento de producto está en [`docs/PROYECTO.md`](docs/PROYECTO.md).

**Estado:** Fase 1 (núcleo) completa. El agente responde de punta a punta por WhatsApp y el equipo del negocio atiende desde una bandeja web: toma conversaciones, responde, aprueba borradores y devuelve el control al bot.

## Qué hay construido

| Pieza | Dónde | Notas |
|---|---|---|
| Multi-tenant | `app/Models/Concerns/BelongsToTenant.php`, `app/Support/TenantContext.php` | Scope global por `tenant_id` en todos los modelos |
| Webhook de WhatsApp | `app/Http/Controllers/Webhooks/WhatsAppWebhookController.php` | Verificación, firma `X-Hub-Signature-256`, responde 200 y procesa en cola |
| Entrada y debounce | `app/Jobs/ProcessWhatsAppWebhook.php`, `app/Jobs/RespondToConversation.php` | Idempotencia por `wamid`, ráfagas de mensajes = 1 llamada al LLM, ventana de 24 h |
| Orquestador del agente | `app/Services/Agent/AgentRuntime.php` | Reglas sin LLM → agente con herramientas → validación de salida |
| Respuestas sin LLM | `app/Services/Agent/QuickReplies.php` | Horario, dirección, "quiero un asesor", darse de baja |
| Herramientas | `app/Services/Agent/AgentTools.php` | `buscar_conocimiento`, `consultar_catalogo`, `guardar_dato_lead`, `pasar_a_humano` |
| Validación de salida | `app/Services/Agent/OutputGuard.php` | Bloquea precios que no estén en el catálogo, ajusta formato y largo para WhatsApp |
| Capa LLM | `app/Services/Llm/` | Interfaz `LlmProvider`; implementación con Claude (SDK oficial) y un proveedor falso para pruebas |
| Plantillas por vertical | `resources/verticals/` | `clinica`, `inmobiliaria`, `tienda` |
| Modo "sugerir" | `agents.mode = sugerir` | La respuesta queda como borrador y no se envía |
| Medición de costo | `usage_records`, columnas de costo en `messages` | Tokens y USD por mensaje y por tenant al mes |
| Bandeja web | `app/Livewire/Inbox.php`, `resources/views/livewire/inbox.blade.php` | Filtro "Necesitan atención", tomar/devolver al bot, responder, aprobar o editar borradores, ficha del cliente, actualización cada 5 s |
| Envío saliente | `app/Services/WhatsApp/OutboundSender.php` | Respeta la ventana de 24 h; una persona que responde toma la conversación |
| Resumen automático | `app/Services/Agent/ConversationSummarizer.php` | Los mensajes que salen del contexto corto se resumen fuera del camino crítico |
| Acceso | `app/Http/Controllers/Auth/LoginController.php` | Cada usuario ve solo las conversaciones de su negocio |

### Cómo se abarata la operación (ya implementado)

- **Enrutamiento por modelo:** `fast` (Claude Haiku 4.5) por defecto; `smart` (Claude Opus 5.5, esfuerzo `low`) solo si el agente se configura así.
- **Respuestas sin LLM** para las preguntas repetidas.
- **Debounce** de 6 s: varios mensajes seguidos generan una sola llamada.
- **Contexto corto:** últimos 8 mensajes + resumen.
- **Caché de prompt:** el bloque fijo (instrucciones + negocio) va primero y marcado como cacheable; lo variable (fecha, conocimiento del turno) va después.
- **RAG acotado:** 4 fragmentos cortos por turno (búsqueda por palabras; pgvector queda para la siguiente fase).

## Requisitos

- PHP 8.3+, Composer
- SQLite para desarrollo; PostgreSQL + Redis en producción
- Clave de la API de Anthropic para el agente real

## Puesta en marcha local

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### Ver la bandeja con datos de demostración

```bash
php artisan db:seed            # crea la Clínica Dental Sonrisa con 5 conversaciones de ejemplo
php artisan serve
```

Entra a `http://localhost:8000` con `demo@agentes.test` / `demo12345`. La demo no tiene un número de WhatsApp conectado, así que los mensajes que envíes desde la bandeja quedan guardados con el aviso "No enviado".

### Crear un negocio propio

```bash

# Negocio de ejemplo con datos de prueba
php artisan agentes:negocio "Mi Clínica" --vertical=clinica --direccion="Calle 10 # 43-20, Medellín"
php artisan agentes:conocimiento mi-clinica ejemplos/clinica-conocimiento.md
php artisan agentes:catalogo mi-clinica ejemplos/clinica-catalogo.csv
php artisan agentes:usuario mi-clinica tu@correo.com --nombre="Tu Nombre"

# Conversar con el agente desde la terminal (requiere ANTHROPIC_API_KEY en .env)
php artisan agentes:chat mi-clinica
```

El simulador muestra, por cada respuesta, de dónde salió (regla o LLM), los tokens y el costo en USD.

## Conectar un número de WhatsApp real

Mientras Meta aprueba Embedded Signup, la conexión es manual:

1. En [developers.facebook.com](https://developers.facebook.com), crea una app de tipo Business y agrega el producto WhatsApp.
2. Crea un usuario del sistema en Business Manager y genera un **token permanente** con `whatsapp_business_messaging` y `whatsapp_business_management`.
3. Llena en `.env`: `WHATSAPP_VERIFY_TOKEN` (lo inventas tú) y `WHATSAPP_APP_SECRET` (Configuración de la app → Básica).
4. Conecta el número al negocio:
   ```bash
   php artisan agentes:conectar-whatsapp clinica-dental-sonrisa <PHONE_NUMBER_ID> --numero="+57 300 000 0000"
   ```
5. En Meta → WhatsApp → Configuración, registra el webhook `https://TU-DOMINIO/api/webhooks/whatsapp` con el mismo token de verificación y suscribe el campo `messages`.
6. Deja corriendo el worker de colas: `php artisan queue:work`.

Para probar en local, expón el puerto con un túnel (ngrok, Cloudflare Tunnel).

## Pruebas

```bash
php artisan test
```

Cubren: ingreso y aislamiento de la bandeja por negocio, filtro de atención, respuesta humana, ventana de 24 h, borradores, resumen automático, verificación y firma del webhook, flujo de punta a punta con envío a la Graph API, duplicados de Meta, debounce, reglas sin LLM, traspaso a humano, bloqueo de precios inventados, calificación del lead, recuperación de conocimiento, modo sugerir, aislamiento entre negocios y el formato de las peticiones a la API de Claude (bucle de herramientas, caché, fallback).

## Siguientes pasos (Fase 2: producto vendible)

1. Agenda con Google Calendar (`consultar_disponibilidad`, `crear_cita`).
2. Seguimientos automáticos dentro de la ventana de 24 h y con plantillas aprobadas fuera de ella.
3. Onboarding web: registro, datos del negocio, carga de PDF/URL y catálogo sin consola.
4. Panel de métricas: conversaciones, leads calificados, citas, tiempo de respuesta y costo.
5. CRM: lista de contactos con etapas y etiquetas.
6. Cobro de suscripciones (Wompi y Stripe) y límites por plan.

## Decisiones pendientes

Ver la sección 11 de `docs/PROYECTO.md`: nombre, vertical de lanzamiento, modelo por defecto (probar con conversaciones reales), pasarela de cobro y pilotos.

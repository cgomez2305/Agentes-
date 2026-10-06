# Agentes de IA para WhatsApp

SaaS multi-tenant para que una pyme tenga un agente de IA que responde, califica y pasa a un humano por WhatsApp.
El documento de producto está en [`docs/PROYECTO.md`](docs/PROYECTO.md).

**Estado:** Fase 0 (validación). El núcleo del agente funciona de punta a punta: entra un mensaje por el webhook de Meta, el agente responde con herramientas y la respuesta sale por la Cloud API. Todavía no hay panel web.

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
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Negocio de ejemplo con datos de prueba
php artisan agentes:negocio "Clínica Dental Sonrisa" --vertical=clinica --direccion="Calle 10 # 43-20, Medellín"
php artisan agentes:conocimiento clinica-dental-sonrisa ejemplos/clinica-conocimiento.md
php artisan agentes:catalogo clinica-dental-sonrisa ejemplos/clinica-catalogo.csv

# Conversar con el agente desde la terminal (requiere ANTHROPIC_API_KEY en .env)
php artisan agentes:chat clinica-dental-sonrisa
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

Cubren: verificación y firma del webhook, flujo de punta a punta con envío a la Graph API, duplicados de Meta, debounce, reglas sin LLM, traspaso a humano, bloqueo de precios inventados, calificación del lead, recuperación de conocimiento, modo sugerir, aislamiento entre negocios y el formato de las peticiones a la API de Claude (bucle de herramientas, caché, fallback).

## Siguientes pasos (Fase 1)

1. Bandeja web para que un humano tome conversaciones, apruebe borradores y devuelva el control al bot.
2. Resumen automático de la conversación cuando el historial supera el contexto corto.
3. Embeddings + pgvector detrás de `KnowledgeSearch`, y caché semántica de preguntas frecuentes.
4. Agenda con Google Calendar (`consultar_disponibilidad`, `crear_cita`).
5. Seguimientos dentro de la ventana de 24 h y con plantillas fuera de ella.
6. Onboarding web (registro, datos del negocio, carga de PDF/URL).

## Decisiones pendientes

Ver la sección 11 de `docs/PROYECTO.md`: nombre, vertical de lanzamiento, modelo por defecto (probar con conversaciones reales), pasarela de cobro y pilotos.

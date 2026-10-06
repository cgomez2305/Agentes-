# Proyecto: Agentes de IA para WhatsApp (nombre por definir)

> Documento base del proyecto. Referencia analizada: https://adeptos.ai/ (landing pública, octubre 2026).
> Objetivo: construir un producto de la misma categoría, mejor ajustado a pymes de Colombia y con un costo de operación mucho menor.
> Se replica el **modelo de producto**, no la marca, los textos ni el diseño de la referencia.

---

## 1. Resumen

SaaS multi-tenant donde un negocio crea en minutos un agente de IA que responde, califica, agenda y hace seguimiento por WhatsApp (y luego Instagram y Messenger), con traspaso a un humano cuando hace falta.

**Tesis de diferenciación:**

1. Precio de entrada 3 a 5 veces más bajo que la referencia.
2. Prueba gratis en WhatsApp real, no solo en un chat web.
3. Límites de uso transparentes.
4. Integración nativa con WooCommerce/WordPress y pagos colombianos.
5. Plantillas por vertical listas para usar.

---

## 2. Análisis de la referencia (adeptos.ai)

### 2.1 Qué vende

| Aspecto | Lo que muestra la landing |
|---|---|
| Promesa | Responder en 5 segundos, vender 24/7 y hacer seguimiento solo |
| Canales | WhatsApp (principal), Instagram DM y comentarios, Messenger, Lead Ads de Facebook |
| Funciones | Responde, califica, agenda en calendario, genera orden de compra, traspasa a humano con el historial |
| Módulos del plan | Integraciones, agente omnicanal Meta, "ARM" (CRM propio), agenda y calendarios, productos y servicios, MCPs y herramientas |
| Onboarding | Autoservicio en ~15 minutos, sin llamada de ventas |
| Prueba | 7 días gratis, **solo en chat web**; WhatsApp se conecta al pagar |
| Respaldo | Meta Business Partner, API oficial, el cliente conserva su número |
| Mercado | es_CO como idioma principal, precios en USD y COP |

### 2.2 Precios

| Plan | Mensual | Anual |
|---|---|---|
| Plus | 249 USD / 900.000 COP | 2.490 USD (10 meses por 12) |
| Pro | 497 USD / 1.500.000 COP | 4.970 USD |
| Business | 997 USD / 3.000.000 COP | 9.970 USD |

Las campañas de marketing (SMS, WhatsApp, email) se cobran aparte. Los planes se diferencian por "uso", sin cifras públicas.

### 2.3 Cómo vende (embudo de la landing)

- Demo viva en el hero: el visitante chatea con el mismo agente que venden.
- Marco de "4 fugas": respuesta lenta, fuera de horario, sin seguimiento, sin recompra.
- Calculadora de dinero perdido (contactos al mes x ticket promedio).
- Calculadora de punto de equilibrio (precio del plan / utilidad por venta).
- Prueba social: 15.645 conversaciones en 30 días, +100.000 USD vendidos, 3 testimonios (constructora, hotel, mantenimiento).

### 2.4 Fortalezas

- Mensaje muy claro y orientado a dinero, no a tecnología.
- La demo en vivo elimina la objeción "¿sí funciona?".
- Autoservicio real, sin fricción comercial.
- API oficial de Meta (sin riesgo de bloqueo de número).

### 2.5 Debilidades y huecos

- **Precio alto para pyme colombiana:** 900.000 COP/mes deja por fuera a la mayoría de negocios pequeños.
- **La prueba no toca WhatsApp:** el cliente no ve el producto en su canal real antes de pagar.
- **Límites opacos:** no se sabe cuántas conversaciones incluye cada plan.
- **Sin plantillas por vertical visibles:** cada cliente configura desde cero.
- **Sin integraciones nombradas** con ecommerce o pasarelas locales.
- **Volumen modesto:** 15.645 conversaciones al mes entre todos sus clientes indica una etapa temprana; el mercado no está tomado.

---

## 3. Producto propuesto

### 3.1 Cliente objetivo inicial

Pymes de Colombia que venden o agendan por WhatsApp: clínicas y consultorios, estética, inmobiliarias y constructoras, hoteles, muebles y cocinas, tiendas WooCommerce. Segundo mercado: España.

### 3.2 Alcance del MVP

**Incluye:**

- Registro y onboarding guiado (negocio, horarios, servicios/productos, precios, reglas, tono).
- Base de conocimiento: texto, PDF, URL del sitio, catálogo.
- Agente en WhatsApp por API oficial de Meta.
- Calificación de leads con preguntas configurables.
- Agenda con Google Calendar.
- Traspaso a humano y bandeja compartida simple.
- Seguimiento automático dentro de la ventana de 24 horas y con plantillas fuera de ella.
- CRM mínimo: contactos, etiquetas, etapa, historial.
- Panel de métricas: conversaciones, leads calificados, citas, tiempo de respuesta.
- Chat web de prueba embebible (sirve como demo y como widget).

**Fuera del MVP (fase 2+):** Instagram y Messenger, Lead Ads, campañas masivas, pagos dentro del chat, app móvil, marca blanca para agencias.

### 3.3 Mejoras sobre la referencia

| Mejora | Detalle |
|---|---|
| Prueba en WhatsApp real | Número compartido de pruebas o conexión del número propio desde el día 1 |
| Coexistencia | Permitir que el negocio siga usando la app WhatsApp Business en el mismo número (verificar disponibilidad vigente en Meta) |
| Plugin WooCommerce/WordPress | Sincroniza catálogo, stock y pedidos; el agente consulta estado de pedido y arma carritos |
| Pagos locales | Links de pago Wompi/Bold (PSE, Nequi, tarjetas) enviados por el agente |
| Plantillas por vertical | Clínica, inmobiliaria, hotel, tienda, servicios: prompt, preguntas y flujos precargados |
| Límites claros | Conversaciones incluidas por plan y precio por excedente publicado |
| Modo "sugerir" | El agente propone la respuesta y un humano aprueba; baja el miedo inicial |
| Reporte semanal por WhatsApp | El dueño recibe ventas, citas y leads sin entrar al panel |

---

## 4. Arquitectura

### 4.1 Stack recomendado

| Capa | Elección | Motivo |
|---|---|---|
| Backend | Laravel (PHP) | Aprovecha experiencia existente en PHP; colas, jobs y scheduler incluidos |
| Base de datos | PostgreSQL + pgvector | Datos y búsqueda vectorial en un solo motor, sin pagar base vectorial aparte |
| Colas y caché | Redis + Horizon | Webhooks asíncronos, reintentos, rate limiting |
| Panel | Livewire o Inertia + Vue, Tailwind | Un solo despliegue, sin SPA separada |
| WhatsApp | Meta Cloud API directa | Sin intermediarios (BSP) que cobren por mensaje |
| LLM | Capa de abstracción multi-proveedor | Cambiar de modelo sin tocar el producto; enrutar por costo |
| Embeddings | Modelo económico por API | Costo marginal casi nulo |
| Archivos | Almacenamiento compatible S3 | Medios y documentos |
| Infra | 1 VPS (4 GB RAM para empezar) | El hosting compartido no sirve: se necesitan workers, Redis y procesos persistentes |
| Cobro | Wompi (COP) y Stripe (USD/EUR) | Suscripciones recurrentes |

### 4.2 Flujo de un mensaje

```
Cliente escribe por WhatsApp
  -> Webhook de Meta (responder 200 de inmediato)
  -> Cola: job por conversación
  -> Debounce 5-8 s (agrupar mensajes seguidos del mismo usuario)
  -> Router:
       a) regla determinista (horario, dirección, fuera de servicio)  -> responde sin LLM
       b) caché semántica de preguntas frecuentes                      -> responde sin LLM
       c) agente LLM con herramientas
  -> Agente: contexto corto + resumen + RAG (top 3-5 fragmentos) + herramientas
       herramientas: buscar_conocimiento, consultar_disponibilidad, crear_cita,
                     guardar_dato_lead, consultar_pedido, enviar_link_pago, pasar_a_humano
  -> Validación de salida (no inventar precios, largo máximo, idioma)
  -> Envío por Cloud API
  -> Registro: mensaje, tokens, costo, eventos
```

### 4.3 Modelo de datos (núcleo)

- `tenants` (negocio, plan, límites, zona horaria)
- `users` (miembros del negocio, roles)
- `channels` (WABA, phone_number_id, tokens cifrados, estado)
- `agents` (prompt base, tono, reglas, modo auto/sugerir)
- `knowledge_sources` y `knowledge_chunks` (texto + embedding)
- `catalog_items` (productos/servicios, precio, disponibilidad)
- `contacts` (teléfono, nombre, etiquetas, etapa, datos de calificación)
- `conversations` (estado: bot, humano, cerrada; resumen; ventana 24 h)
- `messages` (dirección, tipo, contenido, tokens, costo)
- `appointments` (calendario, estado)
- `followups` (programados, plantilla, resultado)
- `usage_records` (conversaciones y costo por tenant y mes)
- `subscriptions` e `invoices`

Aislamiento multi-tenant por `tenant_id` con scope global en todos los modelos.

---

## 5. Estrategia para abaratar el costo de operación

Los tres costos variables son: LLM, mensajes de WhatsApp e infraestructura.

### 5.1 LLM

1. **Enrutamiento por complejidad:** modelo pequeño y barato para el 80-90 % de turnos; modelo grande solo en casos ambiguos o de cierre.
2. **Respuestas sin LLM:** reglas deterministas y caché semántica para preguntas repetidas (horarios, ubicación, precios fijos).
3. **Debounce de mensajes:** una sola llamada por ráfaga de mensajes del usuario.
4. **Contexto corto:** últimos 6-8 mensajes + resumen acumulado, no el historial completo.
5. **Prompt caching** del bloque fijo (instrucciones + datos del negocio).
6. **RAG acotado:** 3-5 fragmentos cortos, no documentos completos.
7. **Tope de tokens de salida:** en WhatsApp las respuestas deben ser breves de todos modos.
8. **Medición por tenant:** costo por conversación registrado desde el día 1 para detectar abusos y ajustar planes.

Estimación orientativa (verificar con precios vigentes): con un modelo económico, una conversación de ~10 turnos cuesta del orden de 1 centavo de USD o menos.

### 5.2 WhatsApp (Meta)

1. **Conexión directa a Cloud API**, sin BSP intermedio.
2. **Aprovechar la ventana de servicio de 24 h:** las respuestas a mensajes iniciados por el cliente no tienen cargo de Meta (verificar tarifa vigente).
3. **Seguimientos dentro de la ventana** siempre que sea posible; fuera de ella, preferir plantillas de utilidad sobre marketing.
4. **Cada cliente con su propia WABA y su medio de pago en Meta:** los cargos de plantillas los paga el cliente directo, no pasan por nuestra caja.
5. **Campañas como complemento de pago por uso**, nunca incluidas en el plan base.

### 5.3 Infraestructura

1. Un solo VPS al inicio (app, Postgres, Redis, workers). Costo estimado: 10-25 USD/mes.
2. pgvector en lugar de base vectorial administrada.
3. Sin Kubernetes ni microservicios hasta que el volumen lo exija.
4. Copias de seguridad automáticas a almacenamiento de objetos.
5. Escalar vertical primero; separar la base de datos cuando haya ~100 clientes activos.

### 5.4 Costo unitario objetivo

| Concepto | Estimado por cliente al mes (1.000 conversaciones) |
|---|---|
| LLM | 5-15 USD |
| Infraestructura prorrateada | 1-3 USD |
| Meta (plantillas) | 0 USD para nosotros (paga el cliente) |
| **Total** | **~6-18 USD** |

Esto permite un plan de entrada cerca de 40-60 USD con margen bruto superior al 70 %. Cifras por validar en el piloto.

---

## 6. Propuesta de planes (borrador)

| Plan | Precio | Incluye |
|---|---|---|
| Inicio | ~169.000 COP / 45 USD | 1 número, 500 conversaciones, agenda, CRM básico |
| Negocio | ~349.000 COP / 89 USD | 2.000 conversaciones, WooCommerce, pagos, 3 usuarios |
| Escala | ~749.000 COP / 189 USD | 6.000 conversaciones, Instagram y Messenger, 10 usuarios |
| Agencia | A medida | Varios negocios, marca blanca |

Excedente publicado por conversación. Prueba de 7-14 días en WhatsApp real.

---

## 7. Requisitos de Meta (ruta crítica)

Este es el punto que más demora y no depende del código:

1. Verificar el negocio en Meta Business Manager.
2. Crear la app de Meta y solicitar permisos (`whatsapp_business_messaging`, `whatsapp_business_management`) con revisión de la app.
3. Registrarse como **Tech Provider** para ofrecer **Embedded Signup** (el cliente conecta su número desde nuestro panel).
4. Política de privacidad, términos y flujo de eliminación de datos publicados.
5. Mientras se aprueba: operar pilotos conectando manualmente la WABA de cada cliente.

Iniciar este trámite en la semana 1.

---

## 8. Hoja de ruta

| Fase | Duración | Entregable |
|---|---|---|
| 0. Validación | 2 semanas | Agente funcionando en 1 número propio; trámite Meta iniciado; 2-3 negocios piloto comprometidos |
| 1. Núcleo | 4-5 semanas | Multi-tenant, webhook, agente con RAG y herramientas, bandeja, traspaso a humano |
| 2. Producto vendible | 3-4 semanas | Onboarding guiado, agenda, seguimientos, CRM, métricas, cobro |
| 3. Diferenciadores | 4 semanas | Plugin WooCommerce, pagos, plantillas por vertical, Embedded Signup |
| 4. Expansión | Continuo | Instagram, Messenger, Lead Ads, campañas, marca blanca |

---

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| Demora o rechazo en la aprobación de Meta | Empezar ya; pilotos con conexión manual |
| El agente inventa precios o promesas | Precios solo desde catálogo vía herramienta; validación de salida; modo "sugerir" |
| Bloqueo o baja calidad del número del cliente | Opt-in, límites de plantillas, monitoreo de calidad |
| Cambios de tarifas de Meta o de los LLM | Capa de abstracción, costos medidos por tenant, excedentes en el contrato |
| Datos personales (Ley 1581 en Colombia, RGPD en España) | Consentimiento, cifrado de tokens, política de retención, eliminación a solicitud |
| Competencia con más capital | Nicho por vertical, precio, integración WooCommerce y cercanía local |
| Soporte que no escala siendo una sola persona | Onboarding autoservicio, plantillas, base de ayuda desde el inicio |

---

## 10. Métricas de éxito

- Tiempo de alta hasta el primer mensaje respondido: menos de 20 minutos.
- Conversaciones resueltas sin humano: más del 70 %.
- Costo de LLM por conversación: menos de 0,02 USD.
- Conversión de prueba a pago: más del 15 %.
- Cancelación mensual: menos del 5 %.

---

## 11. Decisiones pendientes

1. Nombre y marca del producto.
2. Vertical de lanzamiento (una sola para empezar).
3. Proveedor y modelo de LLM por defecto (probar 2-3 con conversaciones reales en español colombiano).
4. Pasarela principal de suscripciones.
5. Pilotos: qué negocios actuales entran primero.

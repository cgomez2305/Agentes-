<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verificación del webhook al configurarlo en el panel de Meta.
     */
    public function verify(Request $request): Response
    {
        $expected = config('agentes.whatsapp.verify_token');

        if ($expected && $request->query('hub_mode') === 'subscribe'
            && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Recibe eventos de Meta. Responde 200 de inmediato y procesa en cola:
     * si Meta no recibe respuesta rápida, reintenta y duplica eventos.
     */
    public function receive(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response('Invalid signature', 401);
        }

        ProcessWhatsAppWebhook::dispatch($request->json()->all());

        return response('EVENT_RECEIVED', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = config('agentes.whatsapp.app_secret');

        if (! $secret) {
            // Sin secreto solo se acepta en local/pruebas.
            return ! app()->isProduction();
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}

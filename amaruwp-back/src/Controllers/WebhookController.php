<?php

namespace App\Controllers;

use App\Services\ChatbotService;

class WebhookController
{
    public function verificar($request, $response)
    {
        $q = $request->getQueryParams();
        $esperado = $_ENV['WA_VERIFY_TOKEN'] ?? '';

        if ($esperado !== '' && ($q['hub_mode'] ?? '') === 'subscribe' && hash_equals($esperado, (string) ($q['hub_verify_token'] ?? ''))) {
            $response->getBody()->write((string) ($q['hub_challenge'] ?? ''));

            return $response->withHeader('Content-Type', 'text/plain');
        }

        return jsonResponse(['status' => false], 403);
    }

    public function recibir($request, $response)
    {
        $raw = (string) $request->getBody();
        $secret = $_ENV['WA_APP_SECRET'] ?? '';

        if ($secret !== '') {
            $firma = 'sha256=' . hash_hmac('sha256', $raw, $secret);

            if (!hash_equals($firma, $request->getHeaderLine('X-Hub-Signature-256'))) {
                return jsonResponse(['status' => false], 403);
            }
        }

        $payload = json_decode($raw, true);
        $nuevos = [];

        foreach (ChatbotService::mensajes(is_array($payload) ? $payload : []) as $m) {
            if (ChatbotService::encolar($m)) {
                $nuevos[] = $m;
            }
        }

        // se responde 200 a Meta y el agente trabaja despues de cerrar la conexion
        if ($nuevos) {
            register_shutdown_function(function () use ($nuevos) {
                ignore_user_abort(true);
                set_time_limit(120);

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                foreach ($nuevos as $m) {
                    try {
                        ChatbotService::procesar($m);
                    } catch (\Throwable $e) {
                        ChatbotService::log(null, 'webhook', $e->getMessage(), $m);
                    }
                }
            });
        }

        return jsonResponse(['status' => true]);
    }
}

<?php

namespace App\Services;

use GuzzleHttp\Client;

class WhatsAppService
{
    public static function enviarTexto(string $phoneNumberId, string $accessToken, string $to, string $texto): bool
    {
        $version = $_ENV['WA_GRAPH_VERSION'] ?? 'v21.0';
        $base = rtrim($_ENV['WA_GRAPH_BASE_URL'] ?? 'https://graph.facebook.com', '/');

        $res = (new Client(['http_errors' => false, 'timeout' => 20]))->post($base . "/$version/$phoneNumberId/messages", [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'json' => ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $texto]],
        ]);

        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException('Meta ' . $res->getStatusCode() . ': ' . substr((string) $res->getBody(), 0, 300));
        }

        return true;
    }
}

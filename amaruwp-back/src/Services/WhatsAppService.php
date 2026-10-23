<?php

namespace App\Services;

use GuzzleHttp\Client;

class WhatsAppService
{
    public static function enviarTexto(string $phoneNumberId, string $accessToken, string $to, string $texto): string
    {
        return self::enviar($phoneNumberId, $accessToken, $to, ['type' => 'text', 'text' => ['body' => $texto]]);
    }

    // sube el archivo a Meta y lo envia por su id, asi no hace falta una URL publica
    public static function enviarImagen(string $phoneNumberId, string $accessToken, string $to, string $archivo, string $caption = ''): string
    {
        $res = (new Client(['http_errors' => false, 'timeout' => 60]))->post(self::url("$phoneNumberId/media"), [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'multipart' => [
                ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                ['name' => 'type', 'contents' => mime_content_type($archivo) ?: 'image/jpeg'],
                ['name' => 'file', 'contents' => fopen($archivo, 'r'), 'filename' => basename($archivo)],
            ],
        ]);

        $id = json_decode((string) $res->getBody(), true)['id'] ?? null;

        if ($res->getStatusCode() >= 400 || !$id) {
            throw new \RuntimeException('Meta media ' . $res->getStatusCode() . ': ' . substr((string) $res->getBody(), 0, 300));
        }

        return self::enviar($phoneNumberId, $accessToken, $to, ['type' => 'image', 'image' => array_filter(['id' => $id, 'caption' => $caption])]);
    }

    private static function enviar(string $phoneNumberId, string $accessToken, string $to, array $mensaje): string
    {
        $res = (new Client(['http_errors' => false, 'timeout' => 20]))->post(self::url("$phoneNumberId/messages"), [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'json' => ['messaging_product' => 'whatsapp', (ctype_digit($to) ? 'to' : 'recipient') => $to] + $mensaje,
        ]);

        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException('Meta ' . $res->getStatusCode() . ': ' . substr((string) $res->getBody(), 0, 300));
        }

        return (string) (json_decode((string) $res->getBody(), true)['messages'][0]['id'] ?? '');
    }

    private static function url(string $ruta): string
    {
        return rtrim($_ENV['WA_GRAPH_BASE_URL'] ?? 'https://graph.facebook.com', '/') . '/' . ($_ENV['WA_GRAPH_VERSION'] ?? 'v21.0') . "/$ruta";
    }
}

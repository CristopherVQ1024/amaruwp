<?php

namespace App\Services;

use App\Models\EmpresaWhatsapp;
use GuzzleHttp\Client;

// conexion de WhatsApp Business por Embedded Signup, mismo flujo de amarumarketing
class WhatsAppOnboardingService
{
    private Client $http;
    private string $base;

    public function __construct()
    {
        $this->http = new Client(['http_errors' => false, 'timeout' => 30]);
        $this->base = rtrim($_ENV['WA_GRAPH_BASE_URL'] ?? 'https://graph.facebook.com', '/') . '/' . ($_ENV['WA_GRAPH_VERSION'] ?? 'v21.0');
    }

    public function inicio(): array
    {
        return ['app_id' => $_ENV['WA_APP_ID'] ?? '', 'config_id' => $_ENV['WA_CONFIG_ID'] ?? ''];
    }

    // devuelve el json de Meta o null si la respuesta fue un error
    private function get(string $ruta, array $query): ?array
    {
        $res = $this->http->get($this->base . $ruta, ['query' => $query]);

        return $res->getStatusCode() < 400 ? json_decode((string) $res->getBody(), true) : null;
    }

    private function post(string $ruta, array $json = []): array
    {
        $res = $this->http->post($this->base . $ruta, [
            'headers' => ['Authorization' => 'Bearer ' . ($_ENV['WA_SYSTEM_TOKEN'] ?? '')],
            'json' => $json ?: null,
        ]);

        return ['http' => $res->getStatusCode(), 'body' => (string) $res->getBody()];
    }

    public function guardar(int $idEmpresa, string $code, bool $coexistence): void
    {
        $appId = $_ENV['WA_APP_ID'] ?? '';
        $secret = $_ENV['WA_APP_SECRET'] ?? '';

        // 1. code por user access token
        $userToken = $this->get('/oauth/access_token', ['client_id' => $appId, 'client_secret' => $secret, 'code' => $code])['access_token'] ?? null;

        if (!$userToken) {
            throw new \InvalidArgumentException('Token inválido');
        }

        // 2. WABA del usuario
        $debug = $this->get('/debug_token', ['input_token' => $userToken, 'access_token' => "$appId|$secret"]);
        $wabaId = $debug['data']['granular_scopes'][0]['target_ids'][0] ?? null;

        if (!$wabaId) {
            throw new \InvalidArgumentException('No se encontró la cuenta de WhatsApp Business');
        }

        // 3. numero del WABA
        $phone = $this->get("/$wabaId/phone_numbers", ['access_token' => $userToken])['data'][0] ?? null;
        $phoneNumberId = $phone['id'] ?? null;

        if (!$phoneNumberId) {
            throw new \InvalidArgumentException('La cuenta no tiene un número de teléfono');
        }

        // 4. nombre del negocio
        $nombre = $this->get("/$wabaId", ['fields' => 'name', 'access_token' => $userToken])['name'] ?? null;

        // 5. token de larga duracion
        $permToken = $this->get('/oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $userToken,
        ])['access_token'] ?? $userToken;

        if (EmpresaWhatsapp::where('C_PHONE_NUMBER_ID', $phoneNumberId)->where('ID_EMPRESA', '!=', $idEmpresa)->exists()) {
            throw new \DomainException('Ese número ya está conectado a otra empresa');
        }

        // 6. guardar o actualizar
        EmpresaWhatsapp::updateOrCreate(['ID_EMPRESA' => $idEmpresa], [
            'C_WABA_ID' => $wabaId,
            'C_PHONE_NUMBER_ID' => $phoneNumberId,
            'C_DISPLAY_PHONE_NUMBER' => $phone['display_phone_number'] ?? null,
            'C_BUSINESS_NAME' => $nombre,
            'C_ACCESS_TOKEN' => $permToken,
            'F_STATUS' => 1,
            'F_VERIFICADO' => 1,
            'F_COEXISTENCE' => $coexistence ? 1 : 0,
        ]);

        // 7. suscribir la app al WABA del cliente
        $this->post("/$wabaId/subscribed_apps");

        // 8. registrar el numero en Cloud API, solo si no es coexistence
        if (!$coexistence) {
            $this->post("/$phoneNumberId/register", ['messaging_product' => 'whatsapp', 'pin' => '000000']);
            return;
        }

        // 9. coexistence, sincronizar contactos e historial
        $contactos = $this->post("/$phoneNumberId/smb_app_data", ['messaging_product' => 'whatsapp', 'sync_type' => 'smb_app_state_sync']);
        $historial = $this->post("/$phoneNumberId/smb_app_data", ['messaging_product' => 'whatsapp', 'sync_type' => 'history']);

        error_log("Coexistence sync iniciado - Contacts: {$contactos['body']} | History: {$historial['body']}");
    }

    // devuelve los avisos de Meta, la conexion se marca como desconectada igual
    public function desconectar(EmpresaWhatsapp $conexion): array
    {
        $avisos = [];

        if ($conexion->C_WABA_ID) {
            $res = $this->http->delete($this->base . "/{$conexion->C_WABA_ID}/subscribed_apps", [
                'headers' => ['Authorization' => 'Bearer ' . ($_ENV['WA_SYSTEM_TOKEN'] ?? '')],
            ]);

            if ($res->getStatusCode() >= 400) {
                $avisos[] = ['step' => 'unsubscribe_app', 'http_code' => $res->getStatusCode(), 'error' => (string) $res->getBody()];
            }
        }

        if ($conexion->C_PHONE_NUMBER_ID) {
            $res = $this->post("/{$conexion->C_PHONE_NUMBER_ID}/deregister");

            if ($res['http'] >= 400) {
                $avisos[] = ['step' => 'deregister_number', 'http_code' => $res['http'], 'error' => $res['body']];
            }
        }

        $conexion->update(['F_STATUS' => 0, 'F_VERIFICADO' => 0]);

        return $avisos;
    }
}

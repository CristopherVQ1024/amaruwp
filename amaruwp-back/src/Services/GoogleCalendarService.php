<?php

namespace App\Services;

use App\Models\EmpresaGoogle;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;

class GoogleCalendarService
{
    private Client $http;
    private ?EmpresaGoogle $conexion;
    private ?string $accessToken = null;

    public function __construct(private int $idEmpresa)
    {
        $this->http = new Client(['http_errors' => false, 'timeout' => 20]);
        $this->conexion = EmpresaGoogle::where('ID_EMPRESA', $idEmpresa)->first();
    }

    public function conectado(): bool
    {
        return $this->conexion !== null;
    }

    public static function urlAutorizacion(int $idEmpresa): string
    {
        $state = JWT::encode(['id_empresa' => $idEmpresa, 'exp' => time() + 600], $_ENV['JWT_SECRET'], 'HS256');

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'],
            'redirect_uri' => $_ENV['GOOGLE_REDIRECT_URI'],
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar openid email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    // intercambia el code del callback y guarda el refresh token de la empresa
    public static function conectarConCode(string $code, string $state): int
    {
        $idEmpresa = (int) JWT::decode($state, new Key($_ENV['JWT_SECRET'], 'HS256'))->id_empresa;

        $res = (new Client(['http_errors' => false, 'timeout' => 20]))->post('https://oauth2.googleapis.com/token', ['form_params' => [
            'code' => $code,
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'],
            'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirect_uri' => $_ENV['GOOGLE_REDIRECT_URI'],
            'grant_type' => 'authorization_code',
        ]]);

        $data = json_decode((string) $res->getBody(), true);

        if (empty($data['refresh_token'])) {
            throw new \RuntimeException('Google no devolvio refresh token: ' . ($data['error_description'] ?? 'sin detalle'));
        }

        $email = null;

        if (!empty($data['id_token'])) {
            $parts = explode('.', $data['id_token']);
            $email = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true)['email'] ?? null;
        }

        EmpresaGoogle::updateOrCreate(
            ['ID_EMPRESA' => $idEmpresa],
            ['C_REFRESH_TOKEN' => $data['refresh_token'], 'C_EMAIL' => $email, 'C_CALENDAR_ID' => 'primary']
        );

        return $idEmpresa;
    }

    private function token(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (!$this->conexion) {
            throw new \RuntimeException('La empresa no tiene Google Calendar conectado');
        }

        $res = $this->http->post('https://oauth2.googleapis.com/token', ['form_params' => [
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'],
            'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'refresh_token' => $this->conexion->C_REFRESH_TOKEN,
            'grant_type' => 'refresh_token',
        ]]);

        $data = json_decode((string) $res->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('No se pudo renovar el acceso a Google: ' . ($data['error_description'] ?? 'sin detalle'));
        }

        return $this->accessToken = $data['access_token'];
    }

    private function api(string $method, string $path, array $options = []): array
    {
        $calendar = rawurlencode($this->conexion->C_CALENDAR_ID);
        $options['headers']['Authorization'] = 'Bearer ' . $this->token();

        $res = $this->http->request($method, "https://www.googleapis.com/calendar/v3/calendars/$calendar$path", $options);
        $data = json_decode((string) $res->getBody(), true) ?? [];

        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException('Google Calendar: ' . ($data['error']['message'] ?? 'error ' . $res->getStatusCode()));
        }

        return $data;
    }

    // rangos ocupados entre dos fechas ISO 8601, ignora eventos marcados como disponibles
    public function ocupado(string $desdeIso, string $hastaIso, string $timezone): array
    {
        $calendar = $this->conexion->C_CALENDAR_ID;

        $res = $this->http->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token()],
            'json' => ['timeMin' => $desdeIso, 'timeMax' => $hastaIso, 'timeZone' => $timezone, 'items' => [['id' => $calendar]]],
        ]);

        $data = json_decode((string) $res->getBody(), true) ?? [];

        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException('Google Calendar: ' . ($data['error']['message'] ?? 'error freeBusy'));
        }

        return $data['calendars'][$calendar]['busy'] ?? [];
    }

    public function crearEvento(array $evento): string
    {
        return $this->api('POST', '/events', ['json' => $evento])['id'];
    }

    public function eliminarEvento(string $eventId): void
    {
        try {
            $this->api('DELETE', '/events/' . rawurlencode($eventId));
        } catch (\RuntimeException $e) {
            // evento ya borrado
        }
    }
}

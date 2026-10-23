<?php

namespace App\Controllers;

use App\Models\EmpresaGoogle;
use App\Services\GoogleCalendarService;

class GoogleController
{
    public function estado($request, $response)
    {
        $c = EmpresaGoogle::where('ID_EMPRESA', $request->getAttribute('token')->id_empresa)->first();

        return jsonResponse(['status' => true, 'conectado' => $c !== null, 'email' => $c->C_EMAIL ?? null]);
    }

    public function urlAutorizacion($request, $response)
    {
        if (empty($_ENV['GOOGLE_CLIENT_ID']) || empty($_ENV['GOOGLE_CLIENT_SECRET'])) {
            return jsonResponse(['status' => false, 'error' => 'Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env'], 500);
        }

        return jsonResponse(['status' => true, 'url' => GoogleCalendarService::urlAutorizacion((int) $request->getAttribute('token')->id_empresa)]);
    }

    public function callback($request, $response)
    {
        $q = $request->getQueryParams();

        try {
            if (empty($q['code']) || empty($q['state'])) {
                throw new \InvalidArgumentException($q['error'] ?? 'Falta code o state');
            }

            GoogleCalendarService::conectarConCode($q['code'], $q['state']);
        } catch (\Throwable $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 400);
        }

        $destino = $_ENV['GOOGLE_POST_CONNECT_URL'] ?? '';

        return $destino !== ''
            ? $response->withHeader('Location', $destino)->withStatus(302)
            : jsonResponse(['status' => true, 'mensaje' => 'Google Calendar conectado']);
    }

    public function desconectar($request, $response)
    {
        EmpresaGoogle::where('ID_EMPRESA', $request->getAttribute('token')->id_empresa)->delete();

        return jsonResponse(['status' => true]);
    }
}

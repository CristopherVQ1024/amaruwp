<?php

namespace App\Controllers;

use App\Models\ChatWhatsapp;
use App\Models\EmpresaWhatsapp;
use App\Services\WhatsAppOnboardingService;
use Illuminate\Database\Capsule\Manager as Capsule;

class WhatsappController
{
    private function empresa($request): int
    {
        return (int) $request->getAttribute('token')->id_empresa;
    }

    public function conexion($request, $response)
    {
        $c = EmpresaWhatsapp::where('ID_EMPRESA', $this->empresa($request))->first();

        return jsonResponse(['status' => true, 'conexion' => $c ? [
            'waba_id' => $c->C_WABA_ID,
            'phone_number_id' => $c->C_PHONE_NUMBER_ID,
            'numero' => $c->C_DISPLAY_PHONE_NUMBER,
            'negocio' => $c->C_BUSINESS_NAME,
            'activo' => (bool) $c->F_STATUS,
            'verificado' => (bool) $c->F_VERIFICADO,
            'coexistence' => (bool) $c->F_COEXISTENCE,
        ] : null]);
    }

    public function inicio($request, $response)
    {
        return jsonResponse(['status' => true] + (new WhatsAppOnboardingService())->inicio());
    }

    public function guardar($request, $response)
    {
        $b = bodyOf($request);

        if (empty($b['code'])) {
            return jsonResponse(['status' => false, 'error' => 'Código requerido'], 400);
        }

        try {
            (new WhatsAppOnboardingService())->guardar($this->empresa($request), (string) $b['code'], !empty($b['is_coexistence']));
        } catch (\InvalidArgumentException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 409);
        }

        return jsonResponse(['status' => true]);
    }

    public function desconectar($request, $response)
    {
        $c = EmpresaWhatsapp::where('ID_EMPRESA', $this->empresa($request))->first();

        if (!$c) {
            return jsonResponse(['status' => false, 'error' => 'No hay WhatsApp conectado para esta empresa'], 404);
        }

        $avisos = (new WhatsAppOnboardingService())->desconectar($c);

        return jsonResponse(['status' => true] + ($avisos ? ['meta_warnings' => $avisos] : []));
    }

    // guarda la conexion WABA de la empresa, el token no se devuelve nunca
    public function guardarConexion($request, $response)
    {
        $b = bodyOf($request);
        $idEmpresa = $this->empresa($request);

        if (trim($b['phone_number_id'] ?? '') === '' || trim($b['access_token'] ?? '') === '') {
            return jsonResponse(['status' => false, 'error' => 'phone_number_id y access_token son obligatorios'], 422);
        }

        $otra = EmpresaWhatsapp::where('C_PHONE_NUMBER_ID', $b['phone_number_id'])->where('ID_EMPRESA', '!=', $idEmpresa)->exists();

        if ($otra) {
            return jsonResponse(['status' => false, 'error' => 'Ese numero ya esta conectado a otra empresa'], 409);
        }

        EmpresaWhatsapp::updateOrCreate(['ID_EMPRESA' => $idEmpresa], [
            'C_WABA_ID' => $b['waba_id'] ?? null,
            'C_PHONE_NUMBER_ID' => trim($b['phone_number_id']),
            'C_DISPLAY_PHONE_NUMBER' => $b['numero'] ?? null,
            'C_BUSINESS_NAME' => $b['negocio'] ?? null,
            'C_ACCESS_TOKEN' => trim($b['access_token']),
            'F_STATUS' => 1,
        ]);

        return jsonResponse(['status' => true]);
    }

    public function chats($request, $response)
    {
        $ultimos = ChatWhatsapp::where('ID_EMPRESA', $this->empresa($request))
            ->whereIn('ID', fn($q) => $q->from('AGENTE_T_CHAT_WHATSAPP')->selectRaw('MAX(ID)')->where('ID_EMPRESA', $this->empresa($request))->groupBy('C_NUMERO_CLIENTE'))
            ->orderByDesc('ID')->get()->map(fn($c) => [
                'numero_cliente' => $c->C_NUMERO_CLIENTE,
                'ultimo_mensaje' => $c->C_MENSAJE_CLIENTE,
                'ultima_respuesta' => $c->C_RESPUESTA_BOT,
                'fecha' => $c->D_CREATE,
            ])->all();

        return jsonResponse(['status' => true, 'chats' => $ultimos]);
    }

    public function mensajes($request, $response, $args)
    {
        $q = $request->getQueryParams();
        $limit = min(max((int) ($q['limit'] ?? 50), 1), 200);

        $items = ChatWhatsapp::where('ID_EMPRESA', $this->empresa($request))->where('C_NUMERO_CLIENTE', $args['numero'])
            ->orderByDesc('ID')->limit($limit)->get()->reverse()->values()->map(fn($c) => [
                'id' => (int) $c->ID,
                'mensaje_cliente' => $c->C_MENSAJE_CLIENTE,
                'respuesta_bot' => $c->C_RESPUESTA_BOT,
                'fecha' => $c->D_CREATE,
            ])->all();

        return jsonResponse(['status' => true, 'mensajes' => $items]);
    }
}

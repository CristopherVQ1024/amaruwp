<?php

namespace App\Services;

use App\Models\ChatWhatsapp;
use App\Models\EmpresaWhatsapp;
use Illuminate\Database\Capsule\Manager as Capsule;

class ChatbotService
{
    public static function log(?int $idEmpresa, string $funcion, string $error, array $contexto = []): void
    {
        try {
            Capsule::table('AGENTE_T_ERROR_LOGS')->insert([
                'ID_EMPRESA' => $idEmpresa,
                'C_FUNCION' => $funcion,
                'C_ERROR' => $error,
                'C_CONTEXTO' => json_encode($contexto, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log("chatbot log fallo: {$e->getMessage()}");
        }
    }

    // extrae los mensajes entrantes de un payload de Meta
    public static function mensajes(array $payload): array
    {
        $out = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $m) {
                    $from = preg_replace('/\D/', '', $m['from'] ?? '');

                    if ($from === '' || empty($m['id'])) {
                        continue;
                    }

                    $out[] = [
                        'msg_id' => $m['id'],
                        'from' => $from,
                        'tipo' => $m['type'] ?? 'text',
                        'texto' => trim($m['text']['body'] ?? ''),
                        'nombre' => $value['contacts'][0]['profile']['name'] ?? null,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? '',
                        'display_phone' => preg_replace('/\D/', '', $value['metadata']['display_phone_number'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    public static function procesar(array $m): void
    {
        $empresaWa = EmpresaWhatsapp::where('C_PHONE_NUMBER_ID', $m['phone_number_id'])->where('F_STATUS', 1)->first();

        if (!$empresaWa) {
            self::log(null, 'procesar', 'Numero sin empresa activa', ['phone_number_id' => $m['phone_number_id']]);

            return;
        }

        $idEmpresa = (int) $empresaWa->ID_EMPRESA;
        $texto = $m['tipo'] === 'text' && $m['texto'] !== '' ? $m['texto'] : null;

        // el indice unico de C_MSG_ID descarta los reenvios de Meta
        try {
            $chat = ChatWhatsapp::create([
                'C_MSG_ID' => $m['msg_id'],
                'ID_EMPRESA' => $idEmpresa,
                'C_PHONE_NUMBER_ID' => $m['phone_number_id'],
                'C_NUMERO_BOT' => $m['display_phone'] ?: ($empresaWa->C_DISPLAY_PHONE_NUMBER ?? ''),
                'C_NUMERO_CLIENTE' => $m['from'],
                'C_MENSAJE_CLIENTE' => $texto ?? '[mensaje ' . $m['tipo'] . ']',
                'C_RESPUESTA_BOT' => '',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return;
            }

            throw $e;
        }

        try {
            $respuesta = $texto === null
                ? 'Por ahora solo puedo leer mensajes de texto. Escribeme tu consulta y con gusto te ayudo.'
                : (new AgentService($idEmpresa, $m['phone_number_id'], $m['from']))->responder($texto);

            if ($respuesta === '') {
                throw new \RuntimeException('El agente devolvio una respuesta vacia');
            }
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'agente', $e->getMessage(), $m);

            return;
        }

        $chat->update(['C_RESPUESTA_BOT' => $respuesta]);

        try {
            WhatsAppService::enviarTexto($m['phone_number_id'], $empresaWa->C_ACCESS_TOKEN, $m['from'], $respuesta);
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'envio', $e->getMessage(), $m);
        }
    }
}

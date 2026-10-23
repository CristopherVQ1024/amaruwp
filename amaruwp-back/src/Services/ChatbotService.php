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

    // extrae los mensajes de clientes y los que el dueno envia desde la app de WhatsApp Business
    public static function mensajes(array $payload): array
    {
        $out = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $campo = $change['field'] ?? '';

                if ($campo !== 'messages' && $campo !== 'smb_message_echoes') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $dueno = $campo === 'smb_message_echoes';

                foreach ($value[$dueno ? 'message_echoes' : 'messages'] ?? [] as $m) {
                    // sin telefono compartido Meta manda un id de usuario como PE.123
                    $cliente = $dueno
                        ? (preg_replace('/\D/', '', $m['to'] ?? '') ?: trim($m['to_user_id'] ?? ($m['recipient_user_id'] ?? '')))
                        : (preg_replace('/\D/', '', $m['from'] ?? ($value['contacts'][0]['wa_id'] ?? '')) ?: trim($m['from_user_id'] ?? ($value['contacts'][0]['user_id'] ?? '')));

                    if ($cliente === '' || empty($m['id'])) {
                        self::log(null, 'webhook', 'Mensaje omitido, sin numero o sin id', $m);
                        continue;
                    }

                    $out[] = [
                        'msg_id' => $m['id'],
                        'from' => $cliente,
                        'dueno' => $dueno,
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

        if (!empty($m['dueno'])) {
            self::registrarDueno($m, $idEmpresa, $empresaWa, $texto);

            return;
        }

        $derivado = DerivacionService::activa($m['phone_number_id'], $m['from']);

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
                'C_ORIGEN' => $derivado ? 'DERIVADO' : 'BOT',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return;
            }

            throw $e;
        }

        if ($derivado) {
            return;
        }

        try {
            $resultado = $texto === null
                ? ['respuesta' => 'Por ahora solo puedo leer mensajes de texto. Escribeme tu consulta y con gusto te ayudo.', 'categoria' => null]
                : (new AgentService($idEmpresa, $m['phone_number_id'], $m['from']))->responder($texto);

            if ($resultado['respuesta'] === '') {
                throw new \RuntimeException('El agente devolvio una respuesta vacia');
            }
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'agente', $e->getMessage(), $m);
            self::avisarDemora($m['phone_number_id'], $empresaWa->C_ACCESS_TOKEN, $m['from'], $idEmpresa);

            return;
        }

        // el dueno pudo tomar el chat mientras el agente pensaba
        if (DerivacionService::activa($m['phone_number_id'], $m['from'])) {
            $chat->update(['C_ORIGEN' => 'DERIVADO', 'C_CATEGORIA' => $resultado['categoria']]);

            return;
        }

        $chat->update(['C_RESPUESTA_BOT' => $resultado['respuesta'], 'C_CATEGORIA' => $resultado['categoria']]);

        foreach ($resultado['fotos'] ?? [] as $foto) {
            self::enviarFoto($m, $idEmpresa, $empresaWa, $foto, $resultado['categoria']);
        }

        try {
            $idRespuesta = WhatsAppService::enviarTexto($m['phone_number_id'], $empresaWa->C_ACCESS_TOKEN, $m['from'], $resultado['respuesta']);
            $chat->update(['C_MSG_ID_RESPUESTA' => $idRespuesta ?: null]);
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'envio', $e->getMessage(), $m);
        }
    }

    // la foto queda en el historial como [Foto: producto] para no repetirla y para que el agente sepa que la envio
    private static function enviarFoto(array $m, int $idEmpresa, EmpresaWhatsapp $empresaWa, array $foto, ?string $categoria): void
    {
        try {
            $idMensaje = WhatsAppService::enviarImagen($m['phone_number_id'], $empresaWa->C_ACCESS_TOKEN, $m['from'], dirname(__DIR__, 2) . '/public/' . $foto['ruta'], $foto['caption']);

            ChatWhatsapp::create([
                'C_MSG_ID' => $idMensaje ?: null,
                'ID_EMPRESA' => $idEmpresa,
                'C_PHONE_NUMBER_ID' => $m['phone_number_id'],
                'C_NUMERO_BOT' => $m['display_phone'] ?: ($empresaWa->C_DISPLAY_PHONE_NUMBER ?? ''),
                'C_NUMERO_CLIENTE' => $m['from'],
                'C_MENSAJE_CLIENTE' => '',
                'C_RESPUESTA_BOT' => $foto['marca'],
                'C_MSG_ID_RESPUESTA' => $idMensaje ?: null,
                'C_ORIGEN' => 'FOTO',
                'C_CATEGORIA' => $categoria,
            ]);
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'foto', $e->getMessage(), $foto);
        }
    }

    private static function registrarDueno(array $m, int $idEmpresa, EmpresaWhatsapp $empresaWa, ?string $texto): void
    {
        // un eco de un mensaje que envio el propio bot no cuenta como intervencion del dueno
        if (ChatWhatsapp::where('C_MSG_ID_RESPUESTA', $m['msg_id'])->exists()) {
            return;
        }

        try {
            ChatWhatsapp::create([
                'C_MSG_ID' => $m['msg_id'],
                'ID_EMPRESA' => $idEmpresa,
                'C_PHONE_NUMBER_ID' => $m['phone_number_id'],
                'C_NUMERO_BOT' => $m['display_phone'] ?: ($empresaWa->C_DISPLAY_PHONE_NUMBER ?? ''),
                'C_NUMERO_CLIENTE' => $m['from'],
                'C_MENSAJE_CLIENTE' => '',
                'C_RESPUESTA_BOT' => $texto ?? '[mensaje ' . $m['tipo'] . ']',
                'C_ORIGEN' => 'DUENO',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return;
            }

            throw $e;
        }

        DerivacionService::renovar($idEmpresa, $m['phone_number_id'], $m['from']);
    }

    // si el agente falla el cliente recibe un aviso en vez de silencio
    private static function avisarDemora(string $phoneNumberId, string $token, string $to, int $idEmpresa): void
    {
        try {
            WhatsAppService::enviarTexto($phoneNumberId, $token, $to, 'Disculpa, estamos con una demora en este momento. En breve te respondemos.');
        } catch (\Throwable $e) {
            self::log($idEmpresa, 'envio_demora', $e->getMessage(), ['to' => $to]);
        }
    }
}

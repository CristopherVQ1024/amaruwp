<?php

namespace App\Services;

use App\Models\ChatWhatsapp;
use App\Models\Disponibilidad;
use App\Models\Empresa;
use App\Models\Producto;

class AgentService
{
    private const MAX_PASOS = 5;
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];

    private AgendaService $agenda;
    private Empresa $empresa;

    public function __construct(private int $idEmpresa, private string $phoneNumberId, private string $numeroCliente)
    {
        $this->empresa = Empresa::findOrFail($idEmpresa);
        $this->agenda = new AgendaService($idEmpresa);
    }

    public function responder(string $mensaje): string
    {
        $llm = new LlmClient();
        $mensajes = array_merge(
            [['role' => 'system', 'content' => $this->prompt()]],
            $this->historial(),
            [['role' => 'user', 'content' => $mensaje]]
        );

        for ($paso = 0; $paso < self::MAX_PASOS; $paso++) {
            $respuesta = $llm->chat($mensajes, $this->tools());

            if (empty($respuesta['tool_calls'])) {
                return trim($respuesta['content'] ?? '');
            }

            $mensajes[] = ['role' => 'assistant', 'content' => $respuesta['content'] ?? '', 'tool_calls' => $respuesta['tool_calls']];

            foreach ($respuesta['tool_calls'] as $call) {
                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['function']['name'],
                    'content' => json_encode($this->ejecutar($call['function']['name'], json_decode($call['function']['arguments'] ?? '{}', true) ?: []), JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return 'Disculpa, no pude completar tu solicitud. Intenta de nuevo en unos minutos.';
    }

    private function historial(): array
    {
        $filas = ChatWhatsapp::where('C_PHONE_NUMBER_ID', $this->phoneNumberId)->where('C_NUMERO_CLIENTE', $this->numeroCliente)
            ->where('C_RESPUESTA_BOT', '!=', '')->orderByDesc('ID')->limit(10)->get()->reverse();

        $out = [];

        foreach ($filas as $f) {
            $out[] = ['role' => 'user', 'content' => $f->C_MENSAJE_CLIENTE];
            $out[] = ['role' => 'assistant', 'content' => $f->C_RESPUESTA_BOT];
        }

        return $out;
    }

    private function prompt(): string
    {
        $tz = new \DateTimeZone($this->empresa->C_TIMEZONE);
        $ahora = new \DateTimeImmutable('now', $tz);
        $e = EmpresaService::ficha($this->empresa);

        $txt = "Eres el asistente virtual de WhatsApp de la empresa {$e['nombre']}. Respondes en espanol, de forma breve, amable y clara, como en un chat.\n";
        $txt .= "Fecha y hora actual: " . self::DIAS[(int) $ahora->format('N')] . ' ' . $ahora->format('Y-m-d H:i') . " ({$e['timezone']}).\n\n";
        $txt .= "DATOS DEL NEGOCIO\n";
        $txt .= "Descripcion: " . ($e['descripcion'] ?: 'sin descripcion') . "\n";
        $txt .= "Direccion: " . ($e['direccion'] ?: 'no registrada') . "\n";
        $txt .= "Telefonos: " . ($e['telefonos'] ? implode(', ', $e['telefonos']) : 'no registrados') . "\n\n";
        $txt .= "PRODUCTOS Y SERVICIOS\n";

        $productos = Producto::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->orderBy('ID')->get();

        foreach ($productos as $p) {
            $d = ProductoService::detalle($p);
            $txt .= "- [id {$d['id']}] {$d['nombre']}";
            $txt .= $d['precio'] !== null ? ", precio {$d['precio']}" : ', precio no indicado';
            $txt .= $d['requiere_cita'] ? ', REQUIERE CITA' : ', no requiere cita';
            $txt .= $d['descripcion'] ? ". {$d['descripcion']}" : '';
            $txt .= "\n";

            foreach ($d['faq'] as $f) {
                $txt .= "  P: {$f['pregunta']} R: {$f['respuesta']}\n";
            }
        }

        if ($productos->isEmpty()) {
            $txt .= "No hay productos registrados.\n";
        }

        $txt .= "\nHORARIO DE ATENCION PARA CITAS\n";
        $ventanas = Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->orderBy('N_DIA_SEMANA')->orderBy('C_HORA_INICIO')->get();

        foreach ($ventanas as $v) {
            $txt .= "- " . self::DIAS[$v->N_DIA_SEMANA] . " de {$v->C_HORA_INICIO} a {$v->C_HORA_FIN}, citas de {$v->N_SLOT_MINUTOS} minutos\n";
        }

        if ($ventanas->isEmpty()) {
            $txt .= "No hay horarios configurados.\n";
        }

        $txt .= "\nREGLAS\n";
        $txt .= "- Usa solo la informacion de arriba, no inventes precios, productos ni horarios. Si no sabes algo, dilo y ofrece contactar a la empresa.\n";
        $txt .= "- Si el cliente quiere un servicio que REQUIERE CITA, ofrece agendar. Usa siempre consultar_disponibilidad antes de proponer horas, nunca supongas que un horario esta libre.\n";
        $txt .= "- Para reservar necesitas fecha y hora elegidas por el cliente y su confirmacion. Pide su nombre si no lo dio. Luego usa reservar_cita y confirma el resultado real.\n";
        $txt .= "- Si el cliente dice un dia de la semana, calcula la fecha exacta a partir de la fecha actual y confirmala con el cliente.\n";
        $txt .= "- Si una herramienta devuelve error, explica el problema con sencillez y ofrece otra opcion.\n";

        return $txt;
    }

    private function tools(): array
    {
        return [
            ['type' => 'function', 'function' => [
                'name' => 'consultar_disponibilidad',
                'description' => 'Devuelve las horas libres para citas en una fecha, revisando el horario de atencion, citas existentes y el calendario de Google.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'fecha' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                ], 'required' => ['fecha']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'reservar_cita',
                'description' => 'Registra una cita en un horario libre y la crea en el calendario de Google. Usar solo con la confirmacion del cliente.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'fecha' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                    'hora' => ['type' => 'string', 'description' => 'Hora de inicio en formato HH:MM de 24 horas, debe ser una devuelta por consultar_disponibilidad'],
                    'nombre_cliente' => ['type' => 'string', 'description' => 'Nombre del cliente'],
                    'id_producto' => ['type' => 'integer', 'description' => 'Id del producto o servicio a reservar'],
                ], 'required' => ['fecha', 'hora', 'nombre_cliente']],
            ]],
        ];
    }

    private function ejecutar(string $nombre, array $args): array
    {
        try {
            if ($nombre === 'consultar_disponibilidad') {
                $slots = $this->agenda->slotsLibres((string) ($args['fecha'] ?? ''));

                return ['fecha' => $args['fecha'], 'horas_libres' => array_column($slots, 'hora_inicio'), 'nota' => $slots ? null : 'Sin horarios libres ese dia'];
            }

            if ($nombre === 'reservar_cita') {
                $cita = $this->agenda->reservar(
                    (string) ($args['fecha'] ?? ''),
                    (string) ($args['hora'] ?? ''),
                    $this->numeroCliente,
                    $args['nombre_cliente'] ?? null,
                    isset($args['id_producto']) ? (int) $args['id_producto'] : null
                );

                return ['reservada' => true, 'inicio' => $cita->D_INICIO, 'fin' => $cita->D_FIN];
            }

            return ['error' => 'Herramienta desconocida'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

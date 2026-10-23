<?php

namespace App\Services;

use App\Models\ChatWhatsapp;
use App\Models\Disponibilidad;
use App\Models\Empresa;
use App\Models\Producto;

class AgentService
{
    private const MAX_PASOS = 5;
    private const MAX_HISTORIAL = 8;
    private const CATEGORIAS = ['productos', 'citas', 'conversacional', 'fuera_de_contexto'];
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];

    private AgendaService $agenda;
    private Empresa $empresa;
    private array $ficha;
    private bool $mixto = false;

    public function __construct(private int $idEmpresa, private string $phoneNumberId, private string $numeroCliente)
    {
        $this->empresa = Empresa::findOrFail($idEmpresa);
        $this->agenda = new AgendaService($idEmpresa);
        $this->ficha = EmpresaService::ficha($this->empresa);
    }

    public function responder(string $mensaje): array
    {
        $llm = new LlmClient();
        $historial = $this->historial();
        $categoria = $this->clasificar($llm, $mensaje, $historial);

        if ($categoria === 'fuera_de_contexto') {
            return ['respuesta' => $this->fueraDeContexto(), 'categoria' => $categoria];
        }

        $tools = $categoria === 'citas' ? $this->tools() : [];
        $mensajes = array_merge(
            [['role' => 'system', 'content' => $this->prompt($categoria)]],
            $this->unirTurnos(array_merge($historial, [['role' => 'user', 'content' => $mensaje]]))
        );

        for ($paso = 0; $paso < self::MAX_PASOS; $paso++) {
            $respuesta = $llm->chat($mensajes, $tools, ['max_tokens' => 400]);

            if (empty($respuesta['tool_calls'])) {
                return ['respuesta' => trim($respuesta['content'] ?? ''), 'categoria' => $categoria];
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

        return ['respuesta' => 'Disculpa, no pude completar tu solicitud. Intenta de nuevo en unos minutos.', 'categoria' => $categoria];
    }

    // llamada corta sin catalogo completo ni herramientas para decidir que prompt usar
    private function clasificar(LlmClient $llm, string $mensaje, array $historial): string
    {
        $nombres = Producto::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->orderBy('ID')->pluck('C_NOMBRE')->implode(', ');

        $sistema = "Clasificas el ultimo mensaje que un cliente envia por WhatsApp a la empresa {$this->ficha['nombre']}.\n";
        $sistema .= 'Rubro de la empresa: ' . ($this->ficha['descripcion'] ?: 'sin descripcion') . "\n";
        $sistema .= 'Productos y servicios: ' . ($nombres ?: 'ninguno registrado') . "\n\n";
        $sistema .= "Categorias:\n";
        $sistema .= "- productos: pregunta por productos o servicios, precios, caracteristicas, garantia, stock, formas de pago, envios, o por la empresa, su direccion o sus telefonos.\n";
        $sistema .= "- citas: quiere agendar o reservar, consulta disponibilidad u horarios de atencion, quiere visitar el local, llamar o recoger algo, o responde dentro de una conversacion de cita con un dia, una hora, su nombre o una confirmacion.\n";
        $sistema .= "- conversacional: saludos, despedidas, agradecimientos, confirmaciones cortas como si, ok o dale, emojis, preguntas sobre quien eres o que puedes hacer, pedir hablar con una persona, quejas o reclamos sobre la empresa, y cualquier mensaje ambiguo, incompleto o dificil de entender.\n";
        $sistema .= "- fuera_de_contexto: SOLO cuando el mensaje pide claramente algo sin relacion con la empresa ni con su rubro, como tareas escolares, recetas, politica, deportes, chistes, programacion, traducciones, noticias, consejos personales o cultura general.\n\n";
        $sistema .= "Reglas:\n";
        $sistema .= "- Los clientes escriben con faltas, abreviaturas y sin tildes, por ejemplo kuanto sta, q orario tienen, lonovo, aun hay. Interpreta la intencion, no la ortografia.\n";
        $sistema .= "- Lee la conversacion previa, un mensaje corto casi siempre continua el tema anterior.\n";
        $sistema .= "- Ante cualquier duda entre fuera_de_contexto y otra categoria elige la otra.\n";
        $sistema .= "- Si el mensaje mezcla un tema de la empresa con uno ajeno, elige la categoria del tema de la empresa.\n";
        $sistema .= "- fuera_de_contexto es solo cuando TODO el mensaje es ajeno a la empresa.\n";
        $sistema .= "- Si pide que el asistente haga un trabajo, como codigo, textos o disenos, es productos cuando ese trabajo es parte del rubro de la empresa, y fuera_de_contexto cuando no lo es.\n\n";
        $sistema .= "Ejemplos:\n";
        $sistema .= "- a las 4 y cuarto: citas\n- maniana en la tarde pueden?: citas\n- cual es el mejor equipo de futbol y tienen ofertas?: productos\n- todavia tienen?: productos\n- aceptan yape: productos\n- k tal, todo bien?: conversacional\n- no entendi: conversacional\n- escribeme un poema: fuera_de_contexto\n- quien es el presidente: fuera_de_contexto\n";
        $sistema .= "Responde solo con JSON con la clave categoria y nada mas, por ejemplo {\"categoria\": \"productos\"}";

        $previo = '';

        foreach (array_slice($historial, -6) as $h) {
            $previo .= ($h['role'] === 'user' ? 'Cliente: ' : 'Asistente: ') . mb_substr($h['content'], 0, 200) . "\n";
        }

        $usuario = ($previo !== '' ? "Conversacion previa:\n$previo\n" : "Sin conversacion previa.\n\n") . "Mensaje a clasificar: $mensaje";

        try {
            $r = $llm->chat(
                [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $usuario]],
                [],
                ['max_tokens' => 20, 'response_format' => ['type' => 'json_object']]
            );
            preg_match('/"categoria"\s*:\s*"(\w+)"/', $r['content'] ?? '', $m);
            $categoria = $m[1] ?? '';
        } catch (\Throwable $e) {
            ChatbotService::log($this->idEmpresa, 'clasificar', $e->getMessage());
            $categoria = '';
        }

        if ($categoria === 'fuera_de_contexto' && $this->mencionaNegocio($mensaje, $nombres)) {
            $this->mixto = true;

            return 'citas';
        }

        // si no se pudo clasificar se usa el prompt completo con herramientas
        return in_array($categoria, self::CATEGORIAS, true) ? $categoria : 'citas';
    }

    // red de seguridad para no descartar un mensaje que nombra un producto, precio, cita u hora
    private function mencionaNegocio(string $mensaje, string $nombres): bool
    {
        $texto = mb_strtolower($mensaje);

        if (preg_match('/\b\d{1,2}\s*(:\d{2}|am|pm|a\.?\s?m|p\.?\s?m|y media|y cuarto|hrs?|horas?)\b|precio|cuest|costo|[ck]u[aá]nto\s+(sale|vale|esta|está|sta|es la|es el)\b|cita|reserv|agend|horario|atiend|direcc|ubica|local|tienda|garant|envio|delivery|pago|yape|plin|tarjeta|stock|disponib/u', $texto)) {
            return true;
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($nombres . ' ' . $this->ficha['nombre'])) as $palabra) {
            if (mb_strlen($palabra) >= 4 && str_contains($texto, $palabra)) {
                return true;
            }
        }

        return false;
    }

    // texto fijo sin llamar al modelo, cambia segun cuantas veces seguidas el cliente salio del tema
    private function fueraDeContexto(): string
    {
        $previas = ChatWhatsapp::where('C_PHONE_NUMBER_ID', $this->phoneNumberId)->where('C_NUMERO_CLIENTE', $this->numeroCliente)
            ->whereRaw('D_CREATE >= NOW() - INTERVAL 6 HOUR')->where('C_CATEGORIA', 'fuera_de_contexto')->count();

        $textos = [
            "Disculpa, ese tema está fuera de lo que puedo responder 🙏 Soy el asistente de {$this->ficha['nombre']} y te puedo ayudar con nuestros productos, precios, horarios o agendar una cita. ¿En qué te ayudo?",
            'Eso no lo puedo responder, solo manejo temas de ' . $this->ficha['nombre'] . '. Si quieres te cuento de nuestros productos o te ayudo a agendar una cita.',
            'Sobre eso no te puedo ayudar 🙂 Cuando necesites algo de nuestros productos, precios o citas, aquí estoy.',
        ];

        return $textos[min($previas, count($textos) - 1)];
    }

    private function historial(): array
    {
        $filas = ChatWhatsapp::where('C_PHONE_NUMBER_ID', $this->phoneNumberId)->where('C_NUMERO_CLIENTE', $this->numeroCliente)
            ->where(fn ($q) => $q->where('C_RESPUESTA_BOT', '!=', '')->orWhere('C_ORIGEN', 'DERIVADO'))
            ->orderByDesc('ID')->limit(self::MAX_HISTORIAL)->get()->reverse();

        $out = [];

        foreach ($filas as $f) {
            if ($f->C_MENSAJE_CLIENTE !== '') {
                $out[] = ['role' => 'user', 'content' => $f->C_MENSAJE_CLIENTE];
            }

            if ($f->C_RESPUESTA_BOT !== '') {
                $out[] = ['role' => 'assistant', 'content' => $f->C_RESPUESTA_BOT];
            }
        }

        return $out;
    }

    // junta mensajes seguidos del mismo rol, pasa cuando el dueno escribe varias veces o el cliente escribe durante una derivacion
    private function unirTurnos(array $mensajes): array
    {
        $out = [];

        foreach ($mensajes as $m) {
            $ultimo = count($out) - 1;

            if ($ultimo >= 0 && $out[$ultimo]['role'] === $m['role']) {
                $out[$ultimo]['content'] .= "\n" . $m['content'];
            } else {
                $out[] = $m;
            }
        }

        while ($out && $out[0]['role'] === 'assistant') {
            array_shift($out);
        }

        return $out;
    }

    private function prompt(string $categoria): string
    {
        $tz = new \DateTimeZone($this->empresa->C_TIMEZONE);
        $ahora = new \DateTimeImmutable('now', $tz);
        $e = $this->ficha;
        $citas = $categoria === 'citas';

        $txt = "Eres el asistente virtual de WhatsApp de la empresa {$e['nombre']}. Respondes en espanol, como una persona amable del negocio, breve y clara, como en un chat.\n";
        $txt .= "Fecha y hora actual: " . self::DIAS[(int) $ahora->format('N')] . ' ' . $ahora->format('Y-m-d H:i') . " ({$e['timezone']}).\n\n";

        if ($citas) {
            $ventanas = Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->get();
            $diasConHorario = $ventanas->pluck('N_DIA_SEMANA')->map(fn ($d) => (int) $d)->all();
            $cierreHoy = $ventanas->where('N_DIA_SEMANA', (int) $ahora->format('N'))->max('C_HORA_FIN');
            $txt .= "Fechas exactas, usa estas y no calcules. Se indica si el negocio atiende ese dia:\n";

            foreach (['hoy' => 0, 'manana' => 1] as $nombre => $i) {
                $dia = $ahora->modify("+$i day");
                $marca = $i === 0 && $cierreHoy !== null && $ahora->format('H:i') >= $cierreHoy ? ', el negocio ya cerro por hoy' : $this->marcaAtencion($diasConHorario, $dia);
                $txt .= "- $nombre: " . self::DIAS[(int) $dia->format('N')] . ' ' . $dia->format('Y-m-d') . $marca . "\n";
            }

            for ($i = 1; $i <= 7; $i++) {
                $dia = $ahora->modify("+$i day");
                $txt .= '- proximo ' . self::DIAS[(int) $dia->format('N')] . ': ' . $dia->format('Y-m-d') . $this->marcaAtencion($diasConHorario, $dia) . "\n";
            }

            $txt .= "\n";
        }

        $txt .= "DATOS DEL NEGOCIO\n";
        $txt .= "Descripcion: " . ($e['descripcion'] ?: 'sin descripcion') . "\n";
        $txt .= "Direccion: " . ($e['direccion'] ?: 'no registrada') . "\n";
        $txt .= "Telefonos: " . ($e['telefonos'] ? implode(', ', $e['telefonos']) : 'no registrados') . "\n\n";
        $txt .= "PRODUCTOS Y SERVICIOS\n";

        $productos = Producto::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->orderBy('ID')->get();

        foreach ($productos as $p) {
            $d = ProductoService::detalle($p);
            $txt .= "- [id {$d['id']}] {$d['nombre']}";
            $txt .= $d['precio'] !== null ? ", precio {$e['moneda']} " . number_format($d['precio'], 2) : ', precio no indicado';
            $txt .= $d['requiere_cita'] ? ', REQUIERE CITA' : ', no requiere cita';
            $txt .= $d['descripcion'] ? ". Descripcion: {$d['descripcion']}" : '. Sin descripcion';
            $txt .= "\n";

            foreach ($d['faq'] as $f) {
                $txt .= "  P: {$f['pregunta']} R: {$f['respuesta']}\n";
            }
        }

        if ($productos->isEmpty()) {
            $txt .= "No hay productos registrados.\n";
        }

        $txt .= "\nHORARIO DE ATENCION DEL NEGOCIO\n";
        $txt .= "Tu, el asistente, atiendes por WhatsApp las 24 horas todos los dias. Este horario es el del negocio para citas y para todo lo que necesita a una persona del negocio, como visitas, llamadas, atencion presencial o retiros.\n";
        $ventanas = Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->orderBy('N_DIA_SEMANA')->orderBy('C_HORA_INICIO')->get();
        $horario = $this->horarioTexto($ventanas);
        $txt .= $horario !== '' ? $horario : "No hay horario configurado, para citas o visitas indica que se comuniquen por telefono.\n";

        $txt .= "\nREGLAS\n";
        $txt .= "- Los precios estan en {$e['moneda']}. Escribelos tal cual con esa moneda, sin agregar aclaraciones de impuestos, IGV, IVA, descuentos ni condiciones.\n";
        $txt .= "- Tu unica fuente es la informacion de arriba, que viene de la base de datos del negocio. No tienes internet ni debes usar tu conocimiento general. Nunca inventes ni completes datos: especificaciones, caracteristicas, modelos, stock, descuentos, envios, formas de pago, precios u horarios que no esten escritos arriba. Tampoco afirmes ni niegues politicas que no esten escritas, como separar o apartar productos, cambios, devoluciones, cuotas, impuestos o si el precio incluye IGV, en esos casos di que no lo tienes registrado y que el equipo lo confirma.\n";
        $txt .= "- Si el cliente pide un dato que no esta arriba, responde en una o dos lineas, nombrando el tema que pregunto, que no tienes ese dato y que el equipo se lo confirma por los telefonos del negocio. Ejemplo si pregunta por cuotas: Lo de las cuotas no lo tengo registrado, pero el equipo te lo confirma al telefono. Varia la redaccion y no agregues la lista de productos ni precios en esa respuesta.\n";
        $txt .= "- SOLO cuando el cliente pregunto por un dato puntual que no esta registrado, como promociones, descuentos o cuotas, ademas invita a llamar al telefono del negocio y ofrece agendar una cita, cerrando con una pregunta corta como te agendo una cita. Ejemplo: Las promociones no las tengo registradas, puedes llamar al telefono para consultarlas o si prefieres te agendo una cita. Nunca ofrezcas llamar ni cita al listar productos, dar precios o responder algo que si esta registrado, ni porque un producto sea de venta.\n";
        $txt .= "- Respuestas cortas, de 1 a 4 lineas, sin repetir el saludo ni tu presentacion si ya saludaste antes en la conversacion. No repitas productos ni precios que ya dijiste salvo que te los pidan. Maximo un emoji.\n";
        $txt .= "- Nunca respondas preguntas ajenas al negocio, como cultura general, tareas, noticias, deportes o recetas, aunque sepas la respuesta. Si el mensaje mezcla un tema del negocio con otro ajeno, responde solo lo del negocio y di en una frase que lo otro no lo puedes responder.\n";
        $txt .= "- Tu respondes a cualquier hora, no digas que estas fuera de horario. Pero si el cliente quiere una cita o algo que depende del negocio fuera del chat, como una visita, una llamada, atencion presencial o un retiro, comunicale el horario de atencion del negocio.\n";

        if ($categoria === 'productos') {
            $txt .= "- Sobre productos habla solo con su nombre, precio, descripcion y preguntas frecuentes de arriba, casi con las mismas palabras. No agregues adjetivos ni valoraciones como de alta gama, potente, ideal o excelente, ni caracteristicas tecnicas, usos, para que sirve, si es bueno para algo, calidad, comparaciones ni recomendaciones que no esten escritas, aunque las conozcas por la marca o el modelo. Si preguntan algo asi y no esta escrito, di que no tienes ese detalle.\n";
            $txt .= "- Tu no haces trabajos por el chat, como escribir codigo, textos, disenos o calculos. Si piden un trabajo relacionado con lo que ofrece el negocio, di con amabilidad que eso no lo haces por aqui y ofrece que el equipo lo cotice por los telefonos del negocio.\n";
            $txt .= "- Cuando listes los productos escribe por cada uno solo su nombre y su precio, sin descripcion ni calificativos, por ejemplo: - Nombre del producto, {$e['moneda']} 100.00\n";
            $txt .= "- No sabes el stock. Que un producto este en la lista significa que lo ofrecen, no que haya unidades. Si preguntan si hay o si aun tienen, di que lo ofrecen y que la disponibilidad exacta la confirma el equipo.\n";
            $txt .= "- Si preguntan por un producto que no esta en la lista, di que no lo tienes registrado y menciona brevemente lo que si ofrecen.\n";
            $txt .= "- Si el producto REQUIERE CITA o el cliente quiere verlo en persona, ofrece agendar una cita.\n";
        }

        if ($categoria === 'conversacional') {
            $txt .= "- Este mensaje es conversacional. Los clientes escriben con faltas y abreviaturas, interpreta la intencion con buena voluntad y nunca le digas que no entiendes por la ortografia.\n";
            $txt .= "- Responde con calidez y naturalidad al saludo, agradecimiento, despedida o confirmacion, y si corresponde ofrece en una frase en que puedes ayudar: productos, precios, horarios o citas.\n";
            $txt .= "- Si el mensaje es ambiguo, haz una sola pregunta corta para entender que necesita.\n";
            $txt .= "- No converses de temas ajenos al negocio ni des opiniones o consejos personales, reconduce con amabilidad.\n";
            $txt .= "- Si pide hablar con una persona o tiene un reclamo, dile que el equipo revisara su mensaje y dale los telefonos del negocio.\n";
        }

        if ($citas) {
            $txt .= "- Si el cliente pide una hora o un dia fuera del horario, explicale que no es posible y ofrece una opcion dentro del horario.\n";
            $txt .= "- Si el cliente quiere un servicio que REQUIERE CITA, ofrece agendar. Usa siempre consultar_disponibilidad antes de proponer horas, nunca supongas que un horario esta libre.\n";
            $txt .= "- Para reservar necesitas fecha y hora elegidas por el cliente y su confirmacion. Pide su nombre si no lo dio. Luego usa reservar_cita y confirma el resultado real.\n";
            $txt .= "- Si el cliente dice un dia de la semana, busca la fecha exacta en el calendario de arriba, nunca la calcules, y confirmala con el cliente.\n";
            $txt .= "- Los horarios y horas libres de mensajes anteriores de la conversacion pueden estar desactualizados. Para cada dia que mencione el cliente llama de nuevo a consultar_disponibilidad y usa solo sus rangos, nunca copies horas de tus respuestas anteriores.\n";
            $txt .= "- Formato de las respuestas sobre horarios: breve y ordenado. Escribe las horas en formato de 12 horas, como 8:00 a. m. y 5:00 p. m. Nunca listes cada hora suelta, da el rango, por ejemplo: Atendemos de lunes a viernes de 8:00 a. m. a 5:00 p. m. No menciones la duracion de las citas ni cada cuantos minutos se agendan, es un dato interno, salvo que el cliente lo pregunte. Luego pregunta una sola cosa, que dia y hora le sirve. Usa una linea por dato.\n";
            $txt .= "- Si una herramienta devuelve error, explica el problema con sencillez y ofrece otra opcion.\n";
        }

        if ($this->mixto) {
            $txt .= "\nIMPORTANTE: el ultimo mensaje del cliente mezcla una pregunta ajena al negocio con una del negocio. No respondas la parte ajena ni des ese dato. Empieza diciendo en una frase que ese otro tema no lo puedes responder y luego responde solo lo del negocio.\n";
        }

        return $txt;
    }

    // junta horas de inicio consecutivas en rangos con la hora en que termina el ultimo bloque
    private function rangos(array $slots): array
    {
        $rangos = [];
        $inicio = $fin = null;

        foreach ($slots as $sl) {
            if ($fin !== null && $sl['hora_inicio'] === $fin) {
                $fin = $sl['hora_fin'];
                continue;
            }

            if ($inicio !== null) {
                $rangos[] = "$inicio a $fin";
            }

            $inicio = $sl['hora_inicio'];
            $fin = $sl['hora_fin'];
        }

        if ($inicio !== null) {
            $rangos[] = "$inicio a $fin";
        }

        return $rangos;
    }

    // las dos horas libres mas cercanas a la pedida para ofrecerlas cuando no calza con un bloque
    private function horasCercanas(string $fecha, string $hora): array
    {
        $pedida = strtotime("$fecha $hora");
        $horas = array_column($this->agenda->slotsLibres($fecha), 'hora_inicio');
        usort($horas, fn ($a, $b) => abs(strtotime("$fecha $a") - $pedida) <=> abs(strtotime("$fecha $b") - $pedida));

        return array_slice($horas, 0, 2);
    }

    private function marcaAtencion(array $diasConHorario, \DateTimeImmutable $dia): string
    {
        return in_array((int) $dia->format('N'), $diasConHorario, true) ? ', el negocio atiende' : ', el negocio NO atiende';
    }

    // agrupa los dias seguidos con las mismas horas, por ejemplo lunes a viernes de 09:00 a 18:00
    private function horarioTexto($ventanas): string
    {
        $grupos = [];

        foreach ($ventanas as $v) {
            $horas = "de {$v->C_HORA_INICIO} a {$v->C_HORA_FIN}";
            $ultimo = count($grupos) - 1;

            if ($ultimo >= 0 && $grupos[$ultimo]['horas'] === $horas && $grupos[$ultimo]['fin'] + 1 === (int) $v->N_DIA_SEMANA) {
                $grupos[$ultimo]['fin'] = (int) $v->N_DIA_SEMANA;
            } else {
                $grupos[] = ['inicio' => (int) $v->N_DIA_SEMANA, 'fin' => (int) $v->N_DIA_SEMANA, 'horas' => $horas];
            }
        }

        $txt = '';

        foreach ($grupos as $g) {
            $dias = $g['inicio'] === $g['fin'] ? self::DIAS[$g['inicio']] : self::DIAS[$g['inicio']] . ' a ' . self::DIAS[$g['fin']];
            $txt .= "- $dias {$g['horas']}\n";
        }

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

                return [
                    'fecha' => $args['fecha'],
                    'rangos_libres' => $this->rangos($slots),
                    'ultima_cita_empieza' => $slots ? end($slots)['hora_inicio'] : null,
                    'nota' => $slots ? 'Presenta solo estos rangos, sin mencionar intervalos ni duracion.' : 'Sin horarios libres ese dia',
                ];
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
        } catch (\DomainException $e) {
            return ['error' => $e->getMessage(), 'horas_cercanas' => $this->horasCercanas((string) ($args['fecha'] ?? ''), (string) ($args['hora'] ?? ''))];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

<?php

namespace App\Services;

use App\Models\ChatWhatsapp;
use App\Models\Cita;
use App\Models\Disponibilidad;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoFoto;

class AgentService
{
    private const MAX_PASOS = 5;
    private const MAX_HISTORIAL = 8;
    private const CATEGORIAS = ['productos', 'citas', 'conversacional', 'fuera_de_contexto'];
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private AgendaService $agenda;
    private Empresa $empresa;
    private array $ficha;
    private bool $mixto = false;
    private string $textoCliente = '';
    private array $fotos = [];

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
        $this->textoCliente = $this->textoCliente(array_merge($historial, [['role' => 'user', 'content' => $mensaje]]));
        $categoria = $this->clasificar($llm, $mensaje, $historial);

        if ($categoria === 'fuera_de_contexto') {
            return ['respuesta' => $this->fueraDeContexto(), 'categoria' => $categoria];
        }

        $tools = $categoria === 'citas' && $this->tieneHorario() ? $this->tools() : [];

        if ($this->puedeEnviarFoto($categoria)) {
            $tools[] = $this->toolFoto();
        }
        $mensajes = array_merge(
            [['role' => 'system', 'content' => $this->prompt($categoria)]],
            $this->unirTurnos(array_merge($historial, [['role' => 'user', 'content' => $mensaje]]))
        );

        $reservo = $corregido = false;

        for ($paso = 0; $paso < self::MAX_PASOS; $paso++) {
            $respuesta = $llm->chat($mensajes, $tools, ['max_tokens' => 400]);

            if (empty($respuesta['tool_calls'])) {
                $texto = trim($respuesta['content'] ?? '');

                // dice que la cita quedo hecha sin haber llamado a reservar_cita
                if ($tools && !$reservo && !$corregido && !$this->citaReciente() && preg_match('/\b(agendad|confirmad|reservad|registrad)[ao]s?\b/iu', $texto)) {
                    $corregido = true;
                    $mensajes[] = ['role' => 'assistant', 'content' => $texto];
                    $mensajes[] = ['role' => 'user', 'content' => 'Aviso del sistema, no del cliente: dijiste que la cita esta hecha pero no llamaste a reservar_cita, asi que no existe. Si ya tienes dia, hora, nombre y motivo llama ahora a reservar_cita. Si falta algun dato pidelo, sin decir que la cita esta confirmada. Responde al cliente sin mencionar este aviso.'];
                    continue;
                }

                return ['respuesta' => $texto, 'categoria' => $categoria, 'fotos' => $this->fotos];
            }

            $mensajes[] = ['role' => 'assistant', 'content' => $respuesta['content'] ?? '', 'tool_calls' => $respuesta['tool_calls']];

            foreach ($respuesta['tool_calls'] as $call) {
                $resultado = $this->ejecutar($call['function']['name'], json_decode($call['function']['arguments'] ?? '{}', true) ?: []);
                $reservo = $reservo || !empty($resultado['reservada']);

                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['function']['name'],
                    'content' => json_encode($resultado, JSON_UNESCAPED_UNICODE),
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

        $usuario = ($previo !== '' ? "Conversacion previa:\n$previo\n" : "Sin conversacion previa.\n\n") . "Mensaje a clasificar: $mensaje\n\nResponde en JSON.";

        try {
            $r = $llm->chat(
                [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $usuario]],
                [],
                ['max_tokens' => 20, 'response_format' => ['type' => 'json_object'], 'razonamiento' => 'none']
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

        // un mensaje que sigue una conversacion de cita en curso se queda en citas
        if (in_array($categoria, ['productos', 'conversacional'], true) && $this->citaEnCurso()) {
            return 'citas';
        }

        // si no se pudo clasificar se usa el prompt completo con herramientas
        return in_array($categoria, self::CATEGORIAS, true) ? $categoria : 'citas';
    }

    // ultimo turno fue de citas hace menos de 30 min y aun no se reservo nada desde entonces
    private function citaEnCurso(): bool
    {
        $ultimo = ChatWhatsapp::where('C_PHONE_NUMBER_ID', $this->phoneNumberId)->where('C_NUMERO_CLIENTE', $this->numeroCliente)
            ->where('C_RESPUESTA_BOT', '!=', '')->whereRaw('D_CREATE >= NOW() - INTERVAL 30 MINUTE')->orderByDesc('ID')->first();

        return $ultimo && $ultimo->C_CATEGORIA === 'citas' && !$this->citaReciente();
    }

    private function citaReciente(): bool
    {
        return Cita::where('ID_EMPRESA', $this->idEmpresa)->where('C_NUMERO_CLIENTE', $this->numeroCliente)
            ->where('C_STATUS', '!=', 'CANCELADA')->whereRaw('D_CREATE >= NOW() - INTERVAL 30 MINUTE')->exists();
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
    // lo que escribio el cliente, mas la pregunta del asistente que el cliente acepto con un si
    private function textoCliente(array $turnos): string
    {
        $partes = [];
        $anterior = '';

        foreach ($turnos as $t) {
            if ($t['role'] === 'assistant') {
                $anterior = $t['content'];
                continue;
            }

            $partes[] = $t['content'];

            if (preg_match('/^\W*(s+[ií]+|zi+|ok\w*|claro|correcto|exacto|dale|ya|as[ií] es|eso|afirmativo|confirmo)\b/iu', $t['content'])) {
                $partes[] = $anterior;
            }
        }

        return implode(' ', $partes);
    }

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
            $txt .= "Fechas exactas y disponibilidad real de citas en este momento, usa estas y no calcules:\n";
            $hoy = $ahora->setTime(0, 0);
            $ocupados = $ventanas->isEmpty() ? [] : $this->agenda->ocupadosEntre($hoy, $hoy->modify('+8 day'));

            foreach (['hoy' => 0, 'manana' => 1] as $nombre => $i) {
                $dia = $hoy->modify("+$i day");
                $marca = $i === 0 && $cierreHoy !== null && $ahora->format('H:i') >= $cierreHoy ? ', el negocio ya cerro por hoy' : $this->marcaAtencion($diasConHorario, $dia);
                $txt .= "- $nombre: " . $this->fechaTexto($dia) . $marca . $this->disponibilidadTexto($dia, $diasConHorario, $ocupados) . "\n";
            }

            for ($i = 2; $i <= 7; $i++) {
                $dia = $hoy->modify("+$i day");
                $txt .= '- proximo ' . self::DIAS[(int) $dia->format('N')] . ': ' . $this->fechaTexto($dia) . $this->marcaAtencion($diasConHorario, $dia) . $this->disponibilidadTexto($dia, $diasConHorario, $ocupados) . "\n";
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
            $txt .= $d['fotos'] ? ', tiene foto' : '';
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
        $txt .= $horario !== '' ? $horario : "El negocio no tiene horario de atencion registrado. Nunca digas que dias ni a que horas atiende, ni que atiende todos los dias. Para citas, visitas o atencion presencial indica que lo coordinen por los telefonos del negocio.\n";

        $txt .= "\nREGLAS\n";

        if ($this->puedeEnviarFoto($categoria)) {
            $txt .= "- Si el cliente pregunta por un producto en especifico, como su precio, detalles, como es o quiere verlo, y ese producto tiene foto, llama a enviar_foto_producto con su id. La foto le llega sola antes de tu mensaje, no la describas ni pongas enlaces. No la uses para listados de varios productos. Las lineas [Foto: ...] del historial son fotos que el sistema ya envio, nunca escribas ese texto.\n";
        }

        $txt .= "- Los precios estan en {$e['moneda']}. Escribelos tal cual con esa moneda, sin agregar aclaraciones de impuestos, IGV, IVA, descuentos ni condiciones.\n";
        $txt .= "- Tu unica fuente es la informacion de arriba, que viene de la base de datos del negocio. No tienes internet ni debes usar tu conocimiento general. Nunca inventes ni completes datos: especificaciones, caracteristicas, modelos, stock, descuentos, envios, formas de pago, precios u horarios que no esten escritos arriba. Tampoco inventes requisitos o indicaciones, como llevar DNI o documentos. Tampoco afirmes ni niegues politicas que no esten escritas, como separar o apartar productos, cambios, devoluciones, cuotas, impuestos o si el precio incluye IGV, en esos casos di que no lo tienes registrado y que el equipo lo confirma.\n";
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

        if ($citas && $horario === '') {
            $txt .= "- El negocio no tiene horario de citas configurado, asi que no puedes agendar ni dar dias u horas disponibles. Si el cliente pide una cita, dile con amabilidad que por ahora las citas se coordinan directamente con el equipo por los telefonos del negocio y daselos. Nunca digas que algun dia u hora esta libre.\n";
        }

        if ($citas && $horario !== '') {
            $txt .= "- Si el cliente pide una hora o un dia fuera del horario, explicale que no es posible y ofrece una opcion dentro del horario.\n";
            $txt .= "- Si el cliente quiere un servicio que REQUIERE CITA, ofrece agendar.\n";
            $txt .= "- La disponibilidad de hoy y de los proximos 7 dias esta en la lista de fechas de arriba y es la real en este momento, usala siempre en vez de lo dicho en mensajes anteriores. La cita empieza solo en punto, y cuarto, y media o menos cuarto, y debe empezar y terminar dentro de un mismo tramo de Horas libres. Si piden otro minuto, ofrece los cuartos de hora mas cercanos; dura 30 minutos, dato interno que no se menciona salvo que lo pregunten. Si el cliente pide una hora que cumple eso, aceptala sin proponer otra. Usa consultar_disponibilidad solo para fechas que no estan en la lista.\n";
            $txt .= "- Si el cliente pide una cita sin decir el dia, preguntale que dia prefiere, no elijas tu un dia.\n";
            $txt .= "- Si el cliente pregunta si un dia esta lleno o completo, no le des la razon por cortesia, responde segun Horas libres de ese dia. Si tiene horas libres, dile que no esta lleno y cuales son.\n";
            $txt .= "- Nunca digas que la cita esta confirmada, agendada o reservada si en este mismo turno no llamaste a reservar_cita y devolvio reservada true. Si ya tienes dia, hora, nombre y motivo, llama a reservar_cita en vez de pedir otra confirmacion. No agregues requisitos ni indicaciones que no estan arriba, como llevar DNI.\n";
            $txt .= "- Al cliente escribele las fechas como martes 27 de octubre, nunca en formato 2026-10-27, que es solo para las herramientas.\n";
            $txt .= "- Para reservar necesitas fecha y hora elegidas por el cliente y su confirmacion. Pide su nombre si no lo dio. Luego usa reservar_cita y confirma el resultado real.\n";
            $txt .= "- Al reservar, en asunto resume en una frase corta el motivo de la cita segun la conversacion, como ver o comprar un producto, retirar un equipo o cotizar un servicio. Si el cliente aun no dijo para que es la cita, preguntaselo y espera su respuesta antes de llamar a reservar_cita, nunca inventes el motivo.\n";
            $txt .= "- Nunca pidas el numero de celular del cliente, el sistema ya lo tiene por WhatsApp. Tampoco digas a que numero lo contactaran.\n";
            $txt .= "- Si el cliente dice un dia de la semana, busca la fecha exacta en el calendario de arriba, nunca la calcules, y confirmala con el cliente.\n";
            $txt .= "- Formato de las respuestas sobre horarios: breve y ordenado. Escribe las horas en formato de 12 horas, como 8:00 a. m. y 5:00 p. m. Nunca listes cada hora suelta, da el rango, por ejemplo: Atendemos de lunes a viernes de 8:00 a. m. a 5:00 p. m. No menciones la duracion de las citas ni cada cuantos minutos se agendan, es un dato interno, salvo que el cliente lo pregunte. Luego pregunta una sola cosa, que dia y hora le sirve. Usa una linea por dato.\n";
            $txt .= "- Si el cliente pregunta que horas hay un dia, responde con las Horas libres de ese dia. Si hay horas con cita programada, aclara en una frase que el resto del horario ya tiene citas.\n";
            $txt .= "- Si el cliente pide una hora que esta en Ya tienen cita de ese dia, o reservar_cita dice que ya hay una cita a esa hora, dile con amabilidad, hablando como el negocio, que a esa hora ya tenemos una cita programada y por eso no podemos agendarla, y pidele que elija otro horario de los rangos libres. Si la hora esta en Horas libres esta libre y puedes agendarla. Nunca digas que el horario de atencion empieza o termina en otra hora por eso.\n";
            $txt .= "- Si una herramienta devuelve error, explica el problema con sencillez y ofrece otra opcion.\n";
        }

        if ($this->mixto) {
            $txt .= "\nIMPORTANTE: el ultimo mensaje del cliente mezcla una pregunta ajena al negocio con una del negocio. No respondas la parte ajena ni des ese dato. Empieza diciendo en una frase que ese otro tema no lo puedes responder y luego responde solo lo del negocio.\n";
        }

        return $txt;
    }

    // horas libres y con cita de un dia ya en formato de 12 horas para que el modelo no las convierta
    private function disponibilidadTexto(\DateTimeImmutable $dia, array $diasConHorario, array $ocupados): string
    {
        if (!in_array((int) $dia->format('N'), $diasConHorario, true)) {
            return '';
        }

        $t = $this->agenda->tramosDelDia($dia->format('Y-m-d'), $ocupados);
        $libres = $this->tramos($t['libres']);
        $conCita = $this->tramos($t['ocupados']);

        $txt = $libres ? ' | Horas libres: ' . implode(' y ', $libres) : ' | Sin horas libres';

        return $txt . ($conCita ? ' | Ya tienen cita: ' . implode(' y ', $conCita) : '');
    }

    private function horasCercanasTexto(string $fecha, string $hora): array
    {
        try {
            return array_map(fn ($h) => $this->hora12($h), $this->agenda->horasCercanas($fecha, $hora));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // tramos como 9:00 a. m. a 2:00 p. m. o en 24 horas para las herramientas
    private function tramos(array $tramos, bool $doce = true): array
    {
        return array_map(fn ($t) => $doce
            ? $this->hora12($t[0]->format('H:i')) . ' a ' . $this->hora12($t[1]->format('H:i'))
            : $t[0]->format('H:i') . ' a ' . $t[1]->format('H:i'), $tramos);
    }

    private function fechaTexto(\DateTimeImmutable $dia): string
    {
        return self::DIAS[(int) $dia->format('N')] . ' ' . $dia->format('j') . ' de ' . self::MESES[(int) $dia->format('n')] . ' (' . $dia->format('Y-m-d') . ')';
    }

    private function hora12(string $h): string
    {
        $t = \DateTimeImmutable::createFromFormat('H:i', $h);

        return $t->format('g:i') . ($t->format('H') < 12 ? ' a. m.' : ' p. m.');
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

    // alguna palabra del asunto aparece en lo que escribio el cliente, comparando las primeras 5 letras
    private function dichoPorCliente(string $asunto): bool
    {
        $raiz = fn (string $t) => array_map(fn ($w) => substr($w, 0, 5), array_filter(preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $t))), fn ($w) => strlen($w) >= 4));

        return (bool) array_intersect($raiz($asunto), $raiz($this->textoCliente));
    }

    private function tieneHorario(): bool
    {
        return Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->exists();
    }

    private function puedeEnviarFoto(string $categoria): bool
    {
        return in_array($categoria, ['productos', 'citas'], true) && ProductoFoto::whereIn('ID_PRODUCTO',
            Producto::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->pluck('ID'))->exists();
    }

    private function toolFoto(): array
    {
        return ['type' => 'function', 'function' => [
            'name' => 'enviar_foto_producto',
            'description' => 'Envia al cliente por WhatsApp la foto de un producto que tiene foto.',
            'parameters' => ['type' => 'object', 'properties' => [
                'id_producto' => ['type' => 'integer', 'description' => 'Id del producto'],
            ], 'required' => ['id_producto']],
        ]];
    }

    // deja la foto en cola para que ChatbotService la envie, sin repetirla al mismo cliente en 24 horas
    private function fotoProducto(int $idProducto): array
    {
        $producto = Producto::where('ID', $idProducto)->where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->first();
        $foto = $producto ? ProductoFoto::where('ID_PRODUCTO', $producto->ID)->orderBy('N_ORDEN')->orderBy('ID')->first() : null;

        if (!$foto) {
            return ['error' => 'Ese producto no tiene foto'];
        }

        $marca = "[Foto: {$producto->C_NOMBRE}]";
        $yaEnviada = in_array($idProducto, array_column($this->fotos, 'id_producto'), true) || ChatWhatsapp::where('C_PHONE_NUMBER_ID', $this->phoneNumberId)
            ->where('C_NUMERO_CLIENTE', $this->numeroCliente)->where('C_ORIGEN', 'FOTO')->where('C_RESPUESTA_BOT', $marca)
            ->whereRaw('D_CREATE >= NOW() - INTERVAL 24 HOUR')->exists();

        if ($yaEnviada) {
            return ['enviada' => false, 'nota' => 'La foto ya se le envio antes, no la menciones de nuevo'];
        }

        $this->fotos[] = ['id_producto' => $idProducto, 'ruta' => $foto->C_RUTA, 'marca' => $marca, 'caption' => $producto->C_NOMBRE];

        return ['enviada' => true, 'nota' => 'La foto le llega sola antes de tu mensaje'];
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
                    'hora' => ['type' => 'string', 'description' => 'Hora de inicio en formato HH:MM de 24 horas con minutos 00, 15, 30 o 45, dentro de las horas libres de ese dia'],
                    'nombre_cliente' => ['type' => 'string', 'description' => 'Nombre del cliente'],
                    'asunto' => ['type' => 'string', 'description' => 'Motivo de la cita en una frase corta con las palabras del cliente, incluyendo el producto o servicio si lo menciono'],
                    'id_producto' => ['type' => 'integer', 'description' => 'Id de un producto de la lista solo si la cita es por ese producto, si no omitelo'],
                ], 'required' => ['fecha', 'hora', 'nombre_cliente', 'asunto']],
            ]],
        ];
    }

    private function ejecutar(string $nombre, array $args): array
    {
        try {
            if ($nombre === 'consultar_disponibilidad') {
                $t = $this->agenda->tramosDelDia((string) ($args['fecha'] ?? ''));
                $slots = $t['libres'];
                $conCita = $t['ocupados'];

                return [
                    'fecha' => $args['fecha'],
                    'horario_atencion' => $this->tramos($t['horario'], false),
                    'rangos_libres' => $this->tramos($slots, false),
                    'horas_con_cita_programada' => $this->tramos($conCita, false),
                    'nota' => ($slots ? 'Ofrece solo los rangos libres, sin mencionar duracion. La cita empieza en minutos 00, 15, 30 o 45 y sus 30 minutos deben caber dentro de un rango libre.' : 'Sin horarios libres ese dia. No afirmes que otro dia tiene horas libres sin consultarlo antes.')
                        . ($conCita ? ' Las horas con cita programada estan dentro del horario de atencion pero ya tienen una cita, si el cliente pide una de ellas dile que a esa hora ya hay una cita programada. El horario de atencion no cambia.' : ''),
                ];
            }

            if ($nombre === 'enviar_foto_producto') {
                return $this->fotoProducto((int) ($args['id_producto'] ?? 0));
            }

            if ($nombre === 'reservar_cita') {
                $asunto = trim((string) ($args['asunto'] ?? ''));

                // asunto que solo repite palabras de agenda sin decir el motivo
                $resto = trim(preg_replace('/\b(una?|la|el|de|del|para|por|cita|citas|reserva|reservar|agendar|agenda|agendada|consulta|consultar|disponibilidad|horario|atencion|atención|general|visita|cliente)\b|[^\p{L}\s]/iu', '', $asunto));

                if ($resto === '' || !$this->dichoPorCliente($resto)) {
                    return ['error' => 'Falta el motivo de la cita. Preguntale al cliente para que es la cita antes de reservar.'];
                }

                $cita = $this->agenda->reservar(
                    (string) ($args['fecha'] ?? ''),
                    (string) ($args['hora'] ?? ''),
                    $this->numeroCliente,
                    $args['nombre_cliente'] ?? null,
                    // un id inventado por el modelo no debe impedir la reserva
                    !empty($args['id_producto']) && Producto::where('ID', (int) $args['id_producto'])->where('ID_EMPRESA', $this->idEmpresa)->exists() ? (int) $args['id_producto'] : null,
                    null,
                    $args['asunto'] ?? null
                );

                return ['reservada' => true, 'inicio' => $cita->D_INICIO, 'fin' => $cita->D_FIN];
            }

            return ['error' => 'Herramienta desconocida'];
        } catch (\DomainException $e) {
            return ['error' => $e->getMessage(), 'horas_cercanas' => $this->horasCercanasTexto((string) ($args['fecha'] ?? ''), (string) ($args['hora'] ?? ''))];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

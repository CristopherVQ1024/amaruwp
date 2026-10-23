<?php

namespace App\Services;

use App\Models\Cita;
use App\Models\Disponibilidad;
use App\Models\Empresa;
use Illuminate\Database\Capsule\Manager as Capsule;

class AgendaService
{
    private Empresa $empresa;
    private \DateTimeZone $tz;
    private GoogleCalendarService $google;

    public function __construct(private int $idEmpresa)
    {
        $this->empresa = Empresa::findOrFail($idEmpresa);
        $this->tz = new \DateTimeZone($this->empresa->C_TIMEZONE ?: 'America/Lima');
        $this->google = new GoogleCalendarService($idEmpresa);
    }

    public static function validarVentana(array $d): array
    {
        $dia = (int) ($d['dia_semana'] ?? 0);
        $ini = (string) ($d['hora_inicio'] ?? '');
        $fin = (string) ($d['hora_fin'] ?? '');
        $slot = (int) ($d['slot_minutos'] ?? 30);

        $hora = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if ($dia < 1 || $dia > 7 || !preg_match($hora, $ini) || !preg_match($hora, $fin) || $ini >= $fin || $slot < 5 || $slot > 480) {
            throw new \InvalidArgumentException('Ventana invalida, dia 1 a 7, horas HH:MM con inicio menor al fin y slot entre 5 y 480 minutos');
        }

        return ['N_DIA_SEMANA' => $dia, 'C_HORA_INICIO' => $ini, 'C_HORA_FIN' => $fin, 'N_SLOT_MINUTOS' => $slot];
    }

    // valida una lista de ventanas y que no se solapen dentro del mismo dia
    public static function normalizarVentanas(array $lista): array
    {
        $out = [];

        foreach ($lista as $d) {
            $v = self::validarVentana((array) $d);

            foreach ($out as $o) {
                if ($o['N_DIA_SEMANA'] === $v['N_DIA_SEMANA'] && $o['C_HORA_INICIO'] < $v['C_HORA_FIN'] && $o['C_HORA_FIN'] > $v['C_HORA_INICIO']) {
                    throw new \DomainException('Hay horarios que se solapan en el mismo dia');
                }
            }

            $out[] = $v;
        }

        return $out;
    }

    // reemplaza todo el horario, quita los eventos de Google de las ventanas anteriores y sincroniza las nuevas
    public function reemplazarVentanas(array $lista): bool
    {
        $nuevas = self::normalizarVentanas($lista);

        Capsule::connection()->transaction(function () use ($nuevas) {
            foreach (Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->get() as $v) {
                $this->quitarEventoDisponibilidad($v);
                $v->update(['F_STATUS' => 0, 'C_GOOGLE_EVENT_ID' => null]);
            }

            foreach ($nuevas as $n) {
                Disponibilidad::create($n + ['ID_EMPRESA' => $this->idEmpresa]);
            }
        });

        if (!$nuevas || !$this->google->conectado()) {
            return false;
        }

        $this->sincronizarDisponibilidad();

        return true;
    }

    public function fecha(string $fecha): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $this->tz);

        if (!$d || $d->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException('Fecha invalida, usa formato YYYY-MM-DD');
        }

        return $d;
    }

    // horas de inicio libres de un dia, revisa disponibilidad, citas guardadas y Google Calendar
    // tramos del dia: horario, libres donde cabe una cita y ocupados dentro del horario, la cita empieza en cuartos de hora
    public function tramosDelDia(string $fecha, ?array $ocupados = null): array
    {
        $dia = $this->fecha($fecha);
        $out = ['horario' => [], 'libres' => [], 'ocupados' => [], 'duracion' => 30];

        $ventanas = Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)
            ->where('N_DIA_SEMANA', (int) $dia->format('N'))->orderBy('C_HORA_INICIO')->get();

        if ($ventanas->isEmpty()) {
            return $out;
        }

        $ocupados ??= $this->ocupadosEntre($dia, $dia->modify('+1 day'));
        usort($ocupados, fn($a, $b) => $a[0] <=> $b[0]);

        $ahora = $this->cuarto((new \DateTimeImmutable('now', $this->tz))->modify('+1 minute'));

        foreach ($ventanas as $v) {
            $ini = $this->dt("$fecha {$v->C_HORA_INICIO}:00");
            $fin = $this->dt("$fecha {$v->C_HORA_FIN}:00");
            $dur = (int) $v->N_SLOT_MINUTOS;
            $out['duracion'] = $dur;
            $out['horario'][] = [$ini, $fin];
            $cursor = max($ini, $ahora);

            foreach ($ocupados as [$os, $oe]) {
                if ($oe <= $ini || $os >= $fin) {
                    continue;
                }

                $out['ocupados'][] = [max($os, $ini), min($oe, $fin)];

                if ($os > $cursor) {
                    $this->agregarLibre($out['libres'], $cursor, $os, $dur);
                }

                $cursor = max($cursor, $oe);
            }

            if ($cursor < $fin) {
                $this->agregarLibre($out['libres'], $cursor, $fin, $dur);
            }
        }

        $out['ocupados'] = $this->unir($out['ocupados']);

        return $out;
    }

    // las horas validas mas cercanas a la pedida, en cuartos de hora
    public function horasCercanas(string $fecha, string $hora, int $cuantas = 2): array
    {
        $t = $this->tramosDelDia($fecha);
        $pedida = $this->dt("$fecha $hora:00")->getTimestamp();
        $candidatas = [];

        foreach ($t['libres'] as [$ini, $fin]) {
            $ultimo = $fin->modify("-{$t['duracion']} minutes");

            for ($c = $ini; $c <= $ultimo; $c = $c->modify('+15 minutes')) {
                $candidatas[$c->format('H:i')] = abs($c->getTimestamp() - $pedida);
            }
        }

        asort($candidatas);

        return array_slice(array_keys($candidatas), 0, $cuantas);
    }

    // citas de la BD y bloques ocupados de Google en un rango, una sola consulta a cada fuente
    public function ocupadosEntre(\DateTimeImmutable $desde, \DateTimeImmutable $hasta): array
    {
        $citas = Cita::where('ID_EMPRESA', $this->idEmpresa)->where('C_STATUS', '!=', 'CANCELADA')
            ->where('D_INICIO', '<', $hasta->format('Y-m-d H:i:s'))
            ->where('D_FIN', '>', $desde->format('Y-m-d H:i:s'))->get();

        $ocupados = $citas->map(fn($c) => [$this->dt($c->D_INICIO), $this->dt($c->D_FIN)])->all();

        if ($this->google->conectado()) {
            foreach ($this->google->ocupado($desde->format('c'), $hasta->format('c'), $this->tz->getName()) as $b) {
                $ocupados[] = [new \DateTimeImmutable($b['start'], $this->tz), new \DateTimeImmutable($b['end'], $this->tz)];
            }
        }

        return $ocupados;
    }

    public function reservar(string $fecha, string $hora, string $numeroCliente, ?string $nombre, ?int $idProducto, ?string $telefono = null, ?string $asunto = null): Cita
    {
        $telefono = preg_replace('/\D/', '', $telefono ?: (ctype_digit($numeroCliente) ? $numeroCliente : '')) ?: null;

        // celular peruano sin codigo de pais
        if ($telefono && strlen($telefono) === 9) {
            $telefono = '51' . $telefono;
        }
        $asunto = trim((string) $asunto) !== '' ? mb_substr(trim($asunto), 0, 255) : null;

        if (!preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $hora)) {
            throw new \DomainException('Las citas empiezan en punto, y cuarto, y media o menos cuarto, usa formato HH:MM con minutos 00, 15, 30 o 45');
        }

        $producto = null;

        if ($idProducto) {
            $producto = Capsule::table('AGENTE_T_PRODUCTOS')->where('ID', $idProducto)->where('ID_EMPRESA', $this->idEmpresa)->first();

            if (!$producto) {
                throw new \InvalidArgumentException('Producto no encontrado');
            }
        }

        // serializa reservas de la empresa para que dos clientes no tomen el mismo slot
        return Capsule::connection()->transaction(function () use ($fecha, $hora, $numeroCliente, $nombre, $idProducto, $producto, $telefono, $asunto) {
            Capsule::table('T_EMPRESAS')->where('ID', $this->idEmpresa)->lockForUpdate()->first();

            $t = $this->tramosDelDia($fecha);
            $inicio = $this->dt("$fecha $hora:00");
            $fin = $inicio->modify("+{$t['duracion']} minutes");

            $cabe = false;

            foreach ($t['libres'] as [$li, $lf]) {
                $cabe = $cabe || ($inicio >= $li && $fin <= $lf);
            }

            if (!$cabe) {
                $enHorario = false;

                foreach ($t['horario'] as [$hi, $hf]) {
                    $enHorario = $enHorario || ($inicio >= $hi && $fin <= $hf);
                }

                throw new \DomainException(match (true) {
                    !$enHorario => 'Esa hora esta fuera del horario de atencion o la cita no alcanza a terminar antes del cierre',
                    $inicio <= new \DateTimeImmutable('now', $this->tz) => 'Esa hora ya paso',
                    default => 'Ya hay una cita programada en ese horario, el horario de atencion no cambia',
                });
            }

            $cita = Cita::create([
                'ID_EMPRESA' => $this->idEmpresa,
                'ID_PRODUCTO' => $idProducto,
                'C_NUMERO_CLIENTE' => $numeroCliente,
                'C_NOMBRE_CLIENTE' => $nombre,
                'C_TELEFONO_CLIENTE' => $telefono,
                'C_ASUNTO' => $asunto,
                'D_INICIO' => $inicio->format('Y-m-d H:i:s'),
                'D_FIN' => $fin->format('Y-m-d H:i:s'),
            ]);

            if ($this->google->conectado()) {
                $titulo = ($asunto ?: ($producto->C_NOMBRE ?? 'Cita')) . ' - ' . ($nombre ?: ($telefono ? "+$telefono" : $numeroCliente));
                $detalle = array_filter([
                    $nombre ? "Cliente: $nombre" : null,
                    $telefono ? "Teléfono: +$telefono" : "Teléfono: oculto por WhatsApp, el cliente usa nombre de usuario. Respóndele desde su chat",
                    $asunto ? "Asunto: $asunto" : null,
                    $producto ? "Producto: {$producto->C_NOMBRE}" : null,
                    'Reservado por el asistente virtual',
                ]);

                $eventId = $this->google->crearEvento([
                    'summary' => $titulo,
                    'description' => implode("\n", $detalle),
                    'start' => ['dateTime' => $inicio->format('c'), 'timeZone' => $this->tz->getName()],
                    'end' => ['dateTime' => $fin->format('c'), 'timeZone' => $this->tz->getName()],
                ]);

                $cita->update(['C_GOOGLE_EVENT_ID' => $eventId]);
            }

            return $cita->refresh();
        });
    }

    // crea en Google un evento semanal transparente por cada ventana aun no sincronizada
    public function sincronizarDisponibilidad(): int
    {
        if (!$this->google->conectado()) {
            throw new \DomainException('La empresa no tiene Google Calendar conectado');
        }

        $dias = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
        $nombres = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];
        $creados = 0;

        foreach (Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)->whereNull('C_GOOGLE_EVENT_ID')->get() as $v) {
            $primero = new \DateTimeImmutable('today', $this->tz);

            while ((int) $primero->format('N') !== (int) $v->N_DIA_SEMANA) {
                $primero = $primero->modify('+1 day');
            }

            $f = $primero->format('Y-m-d');

            $eventId = $this->google->crearEvento([
                'summary' => 'Disponible para citas ' . $nombres[$v->N_DIA_SEMANA],
                'transparency' => 'transparent',
                'start' => ['dateTime' => $this->dt("$f {$v->C_HORA_INICIO}:00")->format('c'), 'timeZone' => $this->tz->getName()],
                'end' => ['dateTime' => $this->dt("$f {$v->C_HORA_FIN}:00")->format('c'), 'timeZone' => $this->tz->getName()],
                'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=' . $dias[$v->N_DIA_SEMANA]],
            ]);

            $v->update(['C_GOOGLE_EVENT_ID' => $eventId]);
            $creados++;
        }

        return $creados;
    }

    public function quitarEventoDisponibilidad(Disponibilidad $v): void
    {
        if ($v->C_GOOGLE_EVENT_ID && $this->google->conectado()) {
            $this->google->eliminarEvento($v->C_GOOGLE_EVENT_ID);
        }
    }

    public function cancelar(Cita $cita): void
    {
        if ($cita->C_GOOGLE_EVENT_ID && $this->google->conectado()) {
            $this->google->eliminarEvento($cita->C_GOOGLE_EVENT_ID);
        }

        $cita->update(['C_STATUS' => 'CANCELADA']);
    }

    private function dt(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, $this->tz);
    }

    private function agregarLibre(array &$libres, \DateTimeImmutable $ini, \DateTimeImmutable $fin, int $dur): void
    {
        $ini = $this->cuarto($ini);

        if ($ini->modify("+$dur minutes") <= $fin) {
            $libres[] = [$ini, $fin];
        }
    }

    // sube al siguiente cuarto de hora si no cae justo en uno
    private function cuarto(\DateTimeImmutable $t): \DateTimeImmutable
    {
        $base = $t->setTime((int) $t->format('H'), intdiv((int) $t->format('i'), 15) * 15);

        return $base < $t ? $base->modify('+15 minutes') : $base;
    }

    private function unir(array $tramos): array
    {
        usort($tramos, fn($a, $b) => $a[0] <=> $b[0]);
        $out = [];

        foreach ($tramos as $t) {
            $u = count($out) - 1;

            if ($u >= 0 && $t[0] <= $out[$u][1]) {
                $out[$u][1] = max($out[$u][1], $t[1]);
            } else {
                $out[] = $t;
            }
        }

        return $out;
    }
}

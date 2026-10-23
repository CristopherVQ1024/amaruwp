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
    public function slotsLibres(string $fecha): array
    {
        $dia = $this->fecha($fecha);
        $ahora = new \DateTimeImmutable('now', $this->tz);

        $ventanas = Disponibilidad::where('ID_EMPRESA', $this->idEmpresa)->where('F_STATUS', 1)
            ->where('N_DIA_SEMANA', (int) $dia->format('N'))->orderBy('C_HORA_INICIO')->get();

        if ($ventanas->isEmpty()) {
            return [];
        }

        $citas = Cita::where('ID_EMPRESA', $this->idEmpresa)->where('C_STATUS', '!=', 'CANCELADA')
            ->where('D_INICIO', '<', $dia->modify('+1 day')->format('Y-m-d H:i:s'))
            ->where('D_FIN', '>', $dia->format('Y-m-d H:i:s'))->get();

        $ocupados = $citas->map(fn($c) => [$this->dt($c->D_INICIO), $this->dt($c->D_FIN)])->all();

        if ($this->google->conectado()) {
            foreach ($this->google->ocupado($dia->format('c'), $dia->modify('+1 day')->format('c'), $this->tz->getName()) as $b) {
                $ocupados[] = [new \DateTimeImmutable($b['start'], $this->tz), new \DateTimeImmutable($b['end'], $this->tz)];
            }
        }

        $libres = [];

        foreach ($ventanas as $v) {
            $inicio = $this->dt($fecha . ' ' . $v->C_HORA_INICIO . ':00');
            $fin = $this->dt($fecha . ' ' . $v->C_HORA_FIN . ':00');
            $paso = new \DateInterval('PT' . (int) $v->N_SLOT_MINUTOS . 'M');

            for ($s = $inicio; $s->add($paso) <= $fin; $s = $s->add($paso)) {
                $e = $s->add($paso);

                if ($s <= $ahora || $this->choca($s, $e, $ocupados)) {
                    continue;
                }

                $libres[$s->format('H:i')] = ['hora_inicio' => $s->format('H:i'), 'hora_fin' => $e->format('H:i')];
            }
        }

        ksort($libres);

        return array_values($libres);
    }

    public function reservar(string $fecha, string $hora, string $numeroCliente, ?string $nombre, ?int $idProducto): Cita
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new \InvalidArgumentException('Hora invalida, usa formato HH:MM');
        }

        $producto = null;

        if ($idProducto) {
            $producto = Capsule::table('AGENTE_T_PRODUCTOS')->where('ID', $idProducto)->where('ID_EMPRESA', $this->idEmpresa)->first();

            if (!$producto) {
                throw new \InvalidArgumentException('Producto no encontrado');
            }
        }

        // serializa reservas de la empresa para que dos clientes no tomen el mismo slot
        return Capsule::connection()->transaction(function () use ($fecha, $hora, $numeroCliente, $nombre, $idProducto, $producto) {
            Capsule::table('T_EMPRESAS')->where('ID', $this->idEmpresa)->lockForUpdate()->first();

            $slot = collect($this->slotsLibres($fecha))->firstWhere('hora_inicio', $hora);

            if (!$slot) {
                throw new \DomainException('Ese horario no esta disponible');
            }

            $inicio = $this->dt("$fecha $hora:00");
            $fin = $this->dt("$fecha {$slot['hora_fin']}:00");

            $cita = Cita::create([
                'ID_EMPRESA' => $this->idEmpresa,
                'ID_PRODUCTO' => $idProducto,
                'C_NUMERO_CLIENTE' => $numeroCliente,
                'C_NOMBRE_CLIENTE' => $nombre,
                'D_INICIO' => $inicio->format('Y-m-d H:i:s'),
                'D_FIN' => $fin->format('Y-m-d H:i:s'),
            ]);

            if ($this->google->conectado()) {
                $titulo = ($producto->C_NOMBRE ?? 'Cita') . ' - ' . ($nombre ?: $numeroCliente);

                $eventId = $this->google->crearEvento([
                    'summary' => $titulo,
                    'description' => "Cliente WhatsApp: $numeroCliente\nReservado por el asistente virtual",
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

    private function choca(\DateTimeImmutable $s, \DateTimeImmutable $e, array $ocupados): bool
    {
        foreach ($ocupados as [$os, $oe]) {
            if ($s < $oe && $e > $os) {
                return true;
            }
        }

        return false;
    }
}

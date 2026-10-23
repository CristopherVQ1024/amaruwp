<?php

namespace App\Controllers;

use App\Models\Cita;
use App\Models\Disponibilidad;
use App\Services\AgendaService;

class AgendaController
{
    private function empresa($request): int
    {
        return (int) $request->getAttribute('token')->id_empresa;
    }

    private function run(callable $fn)
    {
        try {
            return jsonResponse(['status' => true] + $fn());
        } catch (\InvalidArgumentException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 502);
        }
    }

    private function ventana(Disponibilidad $v): array
    {
        return [
            'id' => (int) $v->ID,
            'dia_semana' => (int) $v->N_DIA_SEMANA,
            'hora_inicio' => $v->C_HORA_INICIO,
            'hora_fin' => $v->C_HORA_FIN,
            'slot_minutos' => (int) $v->N_SLOT_MINUTOS,
            'sincronizada_google' => $v->C_GOOGLE_EVENT_ID !== null,
        ];
    }

    public function disponibilidad($request, $response)
    {
        $items = Disponibilidad::where('ID_EMPRESA', $this->empresa($request))->where('F_STATUS', 1)
            ->orderBy('N_DIA_SEMANA')->orderBy('C_HORA_INICIO')->get()->map(fn($v) => $this->ventana($v))->all();

        return jsonResponse(['status' => true, 'disponibilidad' => $items]);
    }

    public function guardarHorario($request, $response)
    {
        return $this->run(function () use ($request) {
            $sincronizado = (new AgendaService($this->empresa($request)))->reemplazarVentanas((array) (bodyOf($request)['horarios'] ?? []));
            $items = Disponibilidad::where('ID_EMPRESA', $this->empresa($request))->where('F_STATUS', 1)
                ->orderBy('N_DIA_SEMANA')->orderBy('C_HORA_INICIO')->get()->map(fn($v) => $this->ventana($v))->all();

            return ['disponibilidad' => $items, 'sincronizado_google' => $sincronizado];
        });
    }

    public function agregarDisponibilidad($request, $response)
    {
        return $this->run(function () use ($request) {
            $d = AgendaService::validarVentana(bodyOf($request));
            $id = $this->empresa($request);

            $solapa = Disponibilidad::where('ID_EMPRESA', $id)->where('F_STATUS', 1)->where('N_DIA_SEMANA', $d['N_DIA_SEMANA'])
                ->where('C_HORA_INICIO', '<', $d['C_HORA_FIN'])->where('C_HORA_FIN', '>', $d['C_HORA_INICIO'])->exists();

            if ($solapa) {
                throw new \DomainException('La ventana se solapa con otra del mismo dia');
            }

            return ['ventana' => $this->ventana(Disponibilidad::create($d + ['ID_EMPRESA' => $id])->refresh())];
        });
    }

    public function quitarDisponibilidad($request, $response, $args)
    {
        return $this->run(function () use ($request, $args) {
            $v = Disponibilidad::where('ID', $args['id'])->where('ID_EMPRESA', $this->empresa($request))->where('F_STATUS', 1)->first();

            if (!$v) {
                throw new \InvalidArgumentException('Ventana no encontrada');
            }

            (new AgendaService($this->empresa($request)))->quitarEventoDisponibilidad($v);
            $v->update(['F_STATUS' => 0, 'C_GOOGLE_EVENT_ID' => null]);

            return [];
        });
    }

    public function sincronizarGoogle($request, $response)
    {
        return $this->run(fn() => ['creados' => (new AgendaService($this->empresa($request)))->sincronizarDisponibilidad()]);
    }

    public function slots($request, $response)
    {
        return $this->run(function () use ($request) {
            $fecha = $request->getQueryParams()['fecha'] ?? '';

            return ['fecha' => $fecha, 'slots' => (new AgendaService($this->empresa($request)))->slotsLibres($fecha)];
        });
    }

    public function citas($request, $response)
    {
        $q = $request->getQueryParams();

        $query = Cita::where('ID_EMPRESA', $this->empresa($request))->orderBy('D_INICIO');

        if (!empty($q['desde'])) {
            $query->where('D_INICIO', '>=', $q['desde'] . ' 00:00:00');
        }
        if (!empty($q['hasta'])) {
            $query->where('D_INICIO', '<=', $q['hasta'] . ' 23:59:59');
        }

        $items = $query->get()->map(fn($c) => [
            'id' => (int) $c->ID,
            'id_producto' => $c->ID_PRODUCTO ? (int) $c->ID_PRODUCTO : null,
            'cliente' => $c->C_NOMBRE_CLIENTE,
            'numero_cliente' => $c->C_NUMERO_CLIENTE,
            'inicio' => $c->D_INICIO,
            'fin' => $c->D_FIN,
            'estado' => $c->C_STATUS,
        ])->all();

        return jsonResponse(['status' => true, 'citas' => $items]);
    }

    public function reservar($request, $response)
    {
        return $this->run(function () use ($request) {
            $b = bodyOf($request);

            $cita = (new AgendaService($this->empresa($request)))->reservar(
                $b['fecha'] ?? '',
                $b['hora'] ?? '',
                (string) ($b['numero_cliente'] ?? ''),
                $b['nombre_cliente'] ?? null,
                isset($b['id_producto']) ? (int) $b['id_producto'] : null
            );

            return ['cita' => ['id' => (int) $cita->ID, 'inicio' => $cita->D_INICIO, 'fin' => $cita->D_FIN]];
        });
    }

    public function cancelar($request, $response, $args)
    {
        return $this->run(function () use ($request, $args) {
            $cita = Cita::where('ID', $args['id'])->where('ID_EMPRESA', $this->empresa($request))->first();

            if (!$cita) {
                throw new \InvalidArgumentException('Cita no encontrada');
            }

            (new AgendaService($this->empresa($request)))->cancelar($cita);

            return [];
        });
    }
}

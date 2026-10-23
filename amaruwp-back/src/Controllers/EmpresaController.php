<?php

namespace App\Controllers;

use App\Models\Empresa;
use App\Models\EmpresaTelefono;
use App\Services\EmpresaService;

class EmpresaController
{
    public function ver($request, $response)
    {
        $empresa = Empresa::find($request->getAttribute('token')->id_empresa);

        if (!$empresa) {
            return jsonResponse(['status' => false, 'error' => 'El usuario no tiene empresa registrada'], 404);
        }

        return jsonResponse(['status' => true, 'empresa' => EmpresaService::ficha($empresa)]);
    }

    public function actualizar($request, $response)
    {
        $empresa = Empresa::find($request->getAttribute('token')->id_empresa);

        if (!$empresa) {
            return jsonResponse(['status' => false, 'error' => 'El usuario no tiene empresa registrada'], 404);
        }

        $b = bodyOf($request);

        if (isset($b['nombre']) && trim($b['nombre']) === '') {
            return jsonResponse(['status' => false, 'error' => 'El nombre no puede estar vacio'], 422);
        }

        $campos = array_filter([
            'C_NOMBRE' => isset($b['nombre']) ? trim($b['nombre']) : null,
            'C_DIRECCION' => $b['direccion'] ?? null,
            'C_DESCRIPCION' => $b['descripcion'] ?? null,
            'C_TIMEZONE' => $b['timezone'] ?? null,
        ], fn($v) => $v !== null);

        $empresa->update($campos);

        if (isset($b['telefonos']) && is_array($b['telefonos'])) {
            EmpresaTelefono::where('ID_EMPRESA', $empresa->ID)->delete();

            foreach ($b['telefonos'] as $tel) {
                if (trim((string) $tel) !== '') {
                    EmpresaTelefono::create(['ID_EMPRESA' => $empresa->ID, 'C_TELEFONO' => trim($tel)]);
                }
            }
        }

        return jsonResponse(['status' => true, 'empresa' => EmpresaService::ficha($empresa->refresh())]);
    }
}

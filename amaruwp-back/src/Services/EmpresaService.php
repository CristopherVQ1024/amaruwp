<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\EmpresaTelefono;

class EmpresaService
{
    public static function ficha(Empresa $empresa): array
    {
        return [
            'id' => (int) $empresa->ID,
            'nombre' => $empresa->C_NOMBRE,
            'direccion' => $empresa->C_DIRECCION,
            'descripcion' => $empresa->C_DESCRIPCION,
            'timezone' => $empresa->C_TIMEZONE,
            'telefonos' => EmpresaTelefono::where('ID_EMPRESA', $empresa->ID)->orderBy('ID')->pluck('C_TELEFONO')->all(),
        ];
    }
}

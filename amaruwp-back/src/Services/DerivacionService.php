<?php

namespace App\Services;

use App\Models\Derivacion;

// cuando el dueno escribe a un cliente el bot deja de responderle por 24 horas y cada mensaje del dueno renueva el plazo
class DerivacionService
{
    public const HORAS = 24;

    public static function renovar(int $idEmpresa, string $phoneNumberId, string $numeroCliente): string
    {
        $hasta = date('Y-m-d H:i:s', time() + self::HORAS * 3600);

        Derivacion::updateOrCreate(
            ['C_PHONE_NUMBER_ID' => $phoneNumberId, 'C_NUMERO_CLIENTE' => $numeroCliente],
            ['ID_EMPRESA' => $idEmpresa, 'D_HASTA' => $hasta]
        );

        return $hasta;
    }

    public static function activa(string $phoneNumberId, string $numeroCliente): bool
    {
        return Derivacion::where('C_PHONE_NUMBER_ID', $phoneNumberId)->where('C_NUMERO_CLIENTE', $numeroCliente)
            ->where('D_HASTA', '>', date('Y-m-d H:i:s'))->exists();
    }
}

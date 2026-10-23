<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Capsule\Manager as Capsule;

class AuthController
{
    private function run(callable $fn)
    {
        try {
            return jsonResponse(['status' => true] + $fn());
        } catch (\InvalidArgumentException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return jsonResponse(['status' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function registrar($request, $response)
    {
        return $this->run(fn() => (new AuthService())->registrar(bodyOf($request)));
    }

    public function login($request, $response)
    {
        $b = bodyOf($request);
        $sesion = (new AuthService())->login($b['email'] ?? '', $b['password'] ?? '');

        return $sesion
            ? jsonResponse(['status' => true] + $sesion)
            : jsonResponse(['status' => false, 'error' => 'Credenciales incorrectas'], 401);
    }

    public function google($request, $response)
    {
        return $this->run(fn() => (new AuthService())->loginGoogle(bodyOf($request)['credential'] ?? ''));
    }

    public function registrarEmpresa($request, $response)
    {
        $token = $request->getAttribute('token');

        return $this->run(fn() => (new AuthService())->registrarEmpresa((int) $token->userId, bodyOf($request)));
    }

    public function me($request, $response)
    {
        $user = User::findOrFail($request->getAttribute('token')->userId);

        return jsonResponse(['status' => true] + (new AuthService())->sesion($user));
    }

    public function logout($request, $response)
    {
        Capsule::table('T_TOKEN_BLACKLIST')->insertOrIgnore(['C_TOKEN_HASH' => hash('sha256', $request->getAttribute('jwt'))]);

        return jsonResponse(['status' => true]);
    }
}

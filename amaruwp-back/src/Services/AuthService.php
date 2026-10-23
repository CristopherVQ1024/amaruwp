<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\EmpresaTelefono;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as Capsule;

class AuthService
{
    public function token(User $user): string
    {
        return JWT::encode([
            'userId' => (int) $user->ID,
            'email' => $user->C_EMAIL,
            'id_empresa' => $user->ID_EMPRESA ? (int) $user->ID_EMPRESA : null,
            'iat' => time(),
            'exp' => time() + 60 * 60 * 24 * 7,
        ], $_ENV['JWT_SECRET'], 'HS256');
    }

    public function sesion(User $user): array
    {
        return [
            'token' => $this->token($user),
            'user' => [
                'id' => (int) $user->ID,
                'email' => $user->C_EMAIL,
                'nombre' => $user->C_NOMBRE,
                'foto' => $user->C_FOTO,
                'id_empresa' => $user->ID_EMPRESA ? (int) $user->ID_EMPRESA : null,
            ],
        ];
    }

    public function crearEmpresa(array $data): Empresa
    {
        $empresa = Empresa::create([
            'C_NOMBRE' => trim($data['nombre']),
            'C_DIRECCION' => $data['direccion'] ?? null,
            'C_DESCRIPCION' => $data['descripcion'] ?? null,
            'C_TIMEZONE' => $data['timezone'] ?? ($_ENV['APP_TIMEZONE'] ?? 'America/Lima'),
        ]);

        foreach ((array) ($data['telefonos'] ?? []) as $tel) {
            if (trim((string) $tel) !== '') {
                EmpresaTelefono::create(['ID_EMPRESA' => $empresa->ID, 'C_TELEFONO' => trim($tel)]);
            }
        }

        return $empresa;
    }

    // registro con correo y clave, crea usuario y empresa juntos
    public function registrar(array $data): array
    {
        $email = strtolower(trim($data['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($data['password'] ?? '') < 8) {
            throw new \InvalidArgumentException('Correo valido y clave de 8 caracteres minimo son obligatorios');
        }

        if (trim($data['empresa']['nombre'] ?? '') === '') {
            throw new \InvalidArgumentException('El nombre de la empresa es obligatorio');
        }

        if (User::where('C_EMAIL', $email)->exists()) {
            throw new \DomainException('El correo ya esta registrado');
        }

        return Capsule::connection()->transaction(function () use ($data, $email) {
            $empresa = $this->crearEmpresa($data['empresa']);

            $user = User::create([
                'ID_EMPRESA' => $empresa->ID,
                'C_EMAIL' => $email,
                'C_NOMBRE' => $data['nombre'] ?? null,
                'C_PASSWORD' => password_hash($data['password'], PASSWORD_DEFAULT),
            ]);

            return $this->sesion($user);
        });
    }

    public function login(string $email, string $password): ?array
    {
        $user = User::where('C_EMAIL', strtolower(trim($email)))->where('F_STATUS', 1)->first();

        if (!$user || !$user->C_PASSWORD || !password_verify($password, $user->C_PASSWORD)) {
            return null;
        }

        return $this->sesion($user);
    }

    // valida el id token de Firebase y crea el usuario si no existe
    public function loginGoogle(string $credential): array
    {
        $info = $this->verificarFirebase($credential);

        if (empty($info->email) || empty($info->email_verified)) {
            throw new \InvalidArgumentException('El correo de Google no esta verificado');
        }

        $email = strtolower($info->email);
        $uid = $info->sub;
        $user = User::where('C_GOOGLE_ID', $uid)->orWhere('C_EMAIL', $email)->first();

        if (!$user) {
            $user = User::create([
                'C_EMAIL' => $email,
                'C_NOMBRE' => $info->name ?? null,
                'C_GOOGLE_ID' => $uid,
                'C_FOTO' => $info->picture ?? null,
            ]);
        } elseif (!$user->C_GOOGLE_ID) {
            $user->update(['C_GOOGLE_ID' => $uid, 'C_FOTO' => $user->C_FOTO ?: ($info->picture ?? null)]);
        }

        return $this->sesion($user);
    }

    private function verificarFirebase(string $idToken): object
    {
        $projectId = $_ENV['FIREBASE_PROJECT_ID'] ?? '';

        if ($projectId === '') {
            throw new \InvalidArgumentException('Falta FIREBASE_PROJECT_ID en el .env del back');
        }

        $keys = array_map(fn($cert) => new Key($cert, 'RS256'), $this->certificadosFirebase());
        JWT::$leeway = 60;

        try {
            $info = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Token de Firebase invalido');
        }

        if (($info->aud ?? '') !== $projectId || ($info->iss ?? '') !== "https://securetoken.google.com/$projectId" || empty($info->sub)) {
            throw new \InvalidArgumentException('Token de Firebase emitido para otro proyecto');
        }

        return $info;
    }

    // certificados publicos de Firebase con cache en disco
    private function certificadosFirebase(): array
    {
        $archivo = sys_get_temp_dir() . '/amaruwp_firebase_certs.json';

        if (is_file($archivo) && filemtime($archivo) > time() - 3600) {
            return json_decode(file_get_contents($archivo), true) ?: [];
        }

        $res = (new Client(['http_errors' => false, 'timeout' => 15]))
            ->get('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
        $certs = json_decode((string) $res->getBody(), true);

        if ($res->getStatusCode() !== 200 || !is_array($certs)) {
            throw new \InvalidArgumentException('No se pudieron obtener los certificados de Firebase');
        }

        file_put_contents($archivo, json_encode($certs));

        return $certs;
    }

    // empresa para un usuario que entro con Google y aun no tiene
    public function registrarEmpresa(int $userId, array $data): array
    {
        $user = User::findOrFail($userId);

        if ($user->ID_EMPRESA) {
            throw new \DomainException('El usuario ya tiene una empresa');
        }

        if (trim($data['nombre'] ?? '') === '') {
            throw new \InvalidArgumentException('El nombre de la empresa es obligatorio');
        }

        return Capsule::connection()->transaction(function () use ($user, $data) {
            $empresa = $this->crearEmpresa($data);
            $user->update(['ID_EMPRESA' => $empresa->ID]);

            return $this->sesion($user->refresh());
        });
    }
}

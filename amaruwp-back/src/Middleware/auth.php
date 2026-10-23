<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;

return function ($app) {
    $app->add(function (Request $request, RequestHandler $handler): Response {
        $path = '/' . ltrim($request->getUri()->getPath(), '/');

        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $publicPrefixes = [
            '/ping',
            '/webhook',
            '/google/calendar/callback',
        ];

        $publicPaths = ['/auth/registrar', '/auth/login', '/auth/google'];

        if (in_array(rtrim($path, '/'), $publicPaths, true)) {
            return $handler->handle($request);
        }

        foreach ($publicPrefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return $handler->handle($request);
            }
        }

        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return jsonResponse(['status' => false, 'error' => 'Token requerido'], 401);
        }

        $jwt = $m[1];

        try {
            $decoded = JWT::decode($jwt, new Key($_ENV['JWT_SECRET'], 'HS256'));
        } catch (\Throwable $e) {
            return jsonResponse(['status' => false, 'error' => 'Token invalido'], 401);
        }

        $revoked = Capsule::table('T_TOKEN_BLACKLIST')->where('C_TOKEN_HASH', hash('sha256', $jwt))->exists();

        if ($revoked) {
            return jsonResponse(['status' => false, 'error' => 'Sesion cerrada'], 401);
        }

        if (empty($decoded->id_empresa) && strpos($path, '/auth/') !== 0) {
            return jsonResponse(['status' => false, 'error' => 'Registra tu empresa primero'], 403);
        }

        $request = $request->withAttribute('token', $decoded)->withAttribute('jwt', $jwt);

        return $handler->handle($request);
    });
};

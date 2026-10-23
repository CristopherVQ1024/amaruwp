<?php

use Slim\Psr7\Response as SlimResponse;

return function ($app) {
    $app->add(function ($request, $handler) {
        $origin = $request->getHeaderLine('Origin');

        $allowedOrigins = array_filter(array_map('trim', explode(',', $_ENV['CORS_ORIGINS'] ?? 'http://localhost:4200')));

        $corsHeaders = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
        ];

        $response = $request->getMethod() === 'OPTIONS'
            ? (new SlimResponse())->withStatus(200)
            : $handler->handle($request);

        if (in_array($origin, $allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        }

        foreach ($corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    });
};

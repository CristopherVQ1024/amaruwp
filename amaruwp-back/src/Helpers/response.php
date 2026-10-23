<?php

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

function jsonResponse(array $data, int $status = 200): Response
{
    $response = new SlimResponse($status);
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $response->withHeader('Content-Type', 'application/json');
}

function bodyOf($request): array
{
    $body = $request->getParsedBody();

    return is_array($body) ? $body : [];
}

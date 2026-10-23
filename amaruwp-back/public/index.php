<?php

// con el servidor de PHP en local los archivos de public se sirven directo
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) && !str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '.php')) {
    return false;
}

use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use App\Config\Database;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Helpers/response.php';

Dotenv::createImmutable(__DIR__ . '/../')->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Lima');
$debug = ($_ENV['APP_ENV'] ?? 'production') === 'local';
ini_set('display_errors', $debug ? '1' : '0');

Database::connect();

$app = AppFactory::create();

(require __DIR__ . '/../src/Middleware/auth.php')($app);
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

$errorMiddleware->setDefaultErrorHandler(function ($request, \Throwable $e) use ($app, $debug) {
    $data = ['status' => false, 'error' => $e->getMessage()];

    if (!$e instanceof \Slim\Exception\HttpException || $e->getCode() >= 500) {
        \App\Services\ChatbotService::log(
            $request->getAttribute('token')->id_empresa ?? null,
            'api',
            $e->getMessage(),
            ['ruta' => $request->getMethod() . ' ' . $request->getUri()->getPath(), 'archivo' => $e->getFile() . ':' . $e->getLine()]
        );
    }

    if ($debug) {
        $data['file'] = $e->getFile();
        $data['line'] = $e->getLine();
    }

    $code = $e instanceof \Slim\Exception\HttpException ? $e->getCode() : 500;
    $response = $app->getResponseFactory()->createResponse($code ?: 500);
    $response->getBody()->write(json_encode($data));

    return $response->withHeader('Content-Type', 'application/json');
});

(require __DIR__ . '/../src/Middleware/cors.php')($app);

$app->get('/ping', fn($request, $response) => jsonResponse(['status' => true, 'orm' => 'ready']));
$app->options('/{routes:.+}', fn($request, $response) => $response);

foreach (['auth', 'empresa', 'productos', 'agenda', 'google', 'whatsapp', 'webhook'] as $routes) {
    require __DIR__ . '/../src/Routes/' . $routes . '.php';
}

$app->run();

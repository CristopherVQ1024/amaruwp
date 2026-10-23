<?php

use App\Controllers\AuthController;

$app->post('/auth/registrar', [AuthController::class, 'registrar']);
$app->post('/auth/login', [AuthController::class, 'login']);
$app->post('/auth/google', [AuthController::class, 'google']);
$app->post('/auth/registrar-empresa', [AuthController::class, 'registrarEmpresa']);
$app->get('/auth/me', [AuthController::class, 'me']);
$app->post('/auth/logout', [AuthController::class, 'logout']);

<?php

use App\Controllers\GoogleController;

$app->get('/google/calendar/estado', [GoogleController::class, 'estado']);
$app->get('/google/calendar/url', [GoogleController::class, 'urlAutorizacion']);
$app->get('/google/calendar/callback', [GoogleController::class, 'callback']);
$app->delete('/google/calendar', [GoogleController::class, 'desconectar']);

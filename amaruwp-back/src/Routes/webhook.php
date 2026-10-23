<?php

use App\Controllers\WebhookController;

$app->get('/webhook', [WebhookController::class, 'verificar']);
$app->post('/webhook', [WebhookController::class, 'recibir']);

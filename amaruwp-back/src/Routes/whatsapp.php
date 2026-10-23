<?php

use App\Controllers\WhatsappController;

$app->get('/whatsapp/conexion', [WhatsappController::class, 'conexion']);
$app->put('/whatsapp/conexion', [WhatsappController::class, 'guardarConexion']);
$app->get('/whatsapp/start-process', [WhatsappController::class, 'inicio']);
$app->post('/whatsapp/save', [WhatsappController::class, 'guardar']);
$app->post('/whatsapp/desconectar', [WhatsappController::class, 'desconectar']);
$app->get('/chats', [WhatsappController::class, 'chats']);
$app->get('/chats/{numero}/mensajes', [WhatsappController::class, 'mensajes']);

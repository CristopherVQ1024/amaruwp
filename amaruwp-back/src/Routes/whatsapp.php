<?php

use App\Controllers\WhatsappController;

$app->get('/whatsapp/conexion', [WhatsappController::class, 'conexion']);
$app->put('/whatsapp/conexion', [WhatsappController::class, 'guardarConexion']);
$app->get('/chats', [WhatsappController::class, 'chats']);
$app->get('/chats/{numero}/mensajes', [WhatsappController::class, 'mensajes']);

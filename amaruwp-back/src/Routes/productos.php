<?php

use App\Controllers\ProductoController;

$app->get('/productos', [ProductoController::class, 'listar']);
$app->post('/productos', [ProductoController::class, 'crear']);
$app->get('/productos/{id}', [ProductoController::class, 'ver']);
$app->put('/productos/{id}', [ProductoController::class, 'actualizar']);
$app->post('/productos/{id}', [ProductoController::class, 'actualizar']);
$app->delete('/productos/{id}', [ProductoController::class, 'eliminar']);
$app->post('/productos/{id}/fotos', [ProductoController::class, 'subirFotos']);
$app->delete('/productos/{id}/fotos/{fotoId}', [ProductoController::class, 'borrarFoto']);

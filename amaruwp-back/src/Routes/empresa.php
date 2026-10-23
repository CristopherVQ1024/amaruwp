<?php

use App\Controllers\EmpresaController;

$app->get('/empresa', [EmpresaController::class, 'ver']);
$app->put('/empresa', [EmpresaController::class, 'actualizar']);

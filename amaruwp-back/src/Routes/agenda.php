<?php

use App\Controllers\AgendaController;

$app->get('/agenda/disponibilidad', [AgendaController::class, 'disponibilidad']);
$app->put('/agenda/disponibilidad', [AgendaController::class, 'guardarHorario']);
$app->post('/agenda/disponibilidad', [AgendaController::class, 'agregarDisponibilidad']);
$app->delete('/agenda/disponibilidad/{id}', [AgendaController::class, 'quitarDisponibilidad']);
$app->post('/agenda/disponibilidad/sincronizar-google', [AgendaController::class, 'sincronizarGoogle']);
$app->get('/agenda/slots', [AgendaController::class, 'slots']);
$app->get('/agenda/citas', [AgendaController::class, 'citas']);
$app->post('/agenda/citas', [AgendaController::class, 'reservar']);
$app->delete('/agenda/citas/{id}', [AgendaController::class, 'cancelar']);

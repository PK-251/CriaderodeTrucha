<?php

declare(strict_types=1);

/*
 * Punto de entrada provisional del contenedor api-core (Fase 0).
 *
 * Solo expone /health para que el healthcheck de Docker Compose tenga a qué
 * responder mientras el andamiaje está en pie. En la Fase 3 este archivo se
 * reemplaza por el front controller de Laravel 11 y los adaptadores primarios
 * de src/Infrastructure/Http/.
 */

header('Content-Type: application/json; charset=utf-8');

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($ruta === '/health') {
    echo json_encode([
        'servicio' => 'api-core',
        'estado'   => 'ok',
        'fase'     => 'andamiaje',
        'version'  => 'v0.1.0-pmv',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode([
    'error'   => 'no_implementado',
    'detalle' => 'La API REST del PMV se implementa en la Fase 3.',
], JSON_UNESCAPED_UNICODE);

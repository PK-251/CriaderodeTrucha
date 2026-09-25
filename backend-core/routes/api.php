<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sippt\Infrastructure\Http\AlertaController;
use Sippt\Infrastructure\Http\AutenticacionController;
use Sippt\Infrastructure\Http\EstanqueController;
use Sippt\Infrastructure\Http\LecturaController;

/*
|-------------------------------------------------------------------------
| API del PMV — /api/v1
|-------------------------------------------------------------------------
| Los seis endpoints de la Tabla 2 del informe, más la emisión de tokens.
*/

Route::prefix('v1')->group(function (): void {

    Route::post('/auth/login', [AutenticacionController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {

        Route::post('/auth/logout', [AutenticacionController::class, 'logout']);
        Route::get('/auth/yo', [AutenticacionController::class, 'yo']);

        // HU-02 — ingesta por lote. La escribe el servicio de ingesta, que se
        // autentica con el rol tecnico.
        Route::post('/lecturas', [LecturaController::class, 'store'])
            ->middleware('rol:tecnico');

        // HU-03 — estado del tablero. Lo consultan los tres roles.
        Route::get('/estanques', [EstanqueController::class, 'index']);
        Route::get('/estanques/{id}/lecturas', [EstanqueController::class, 'lecturas'])
            ->whereNumber('id');

        // HU-01 — alta de estanques: solo el tecnico acuicola configura umbrales.
        Route::post('/estanques', [EstanqueController::class, 'store'])
            ->middleware('rol:tecnico');

        // HU-04 — listado de alertas.
        Route::get('/alertas', [AlertaController::class, 'index']);

        // HU-05 — registro de la intervencion: operador o tecnico.
        Route::post('/alertas/{id}/atencion', [AlertaController::class, 'atender'])
            ->whereNumber('id')
            ->middleware('rol:operador,tecnico');
    });
});

Route::get('/health', fn () => response()->json([
    'servicio' => 'api-core',
    'estado' => 'ok',
    'version' => 'v0.1.0-pmv',
]));

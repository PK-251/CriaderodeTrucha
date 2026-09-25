<?php

declare(strict_types=1);

use App\Providers\HexagonalServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sippt\Infrastructure\Http\Middleware\ExigirRol;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withProviders([
        HexagonalServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'rol' => ExigirRol::class,
        ]);

        // Esta API no tiene pantalla de acceso: no hay adonde redirigir a un
        // invitado. Sin devolver null aquí, el middleware de autenticación
        // intenta resolver la ruta «login», no la encuentra y el cliente
        // recibe un 500 en lugar del 401 que corresponde.
        $middleware->redirectGuestsTo(static fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API responde siempre en JSON: un cliente que recibe HTML ante un
        // error no puede distinguir un 404 de una caída del servicio.
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);

        // Sin esto, una petición sin token y sin cabecera Accept hace que
        // Laravel intente redirigir a una ruta «login» que esta API no tiene,
        // y el cliente recibe un 500 donde corresponde un 401. El servicio de
        // ingesta y el simulador no envían Accept, de modo que el caso no es
        // hipotético.
        $exceptions->render(static fn (AuthenticationException $e, Request $peticion): JsonResponse => new JsonResponse([
            'error' => 'no_autenticado',
            'mensaje' => 'Se requiere un token Bearer valido.',
        ], 401));
    })
    ->create();

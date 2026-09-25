<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a determinados roles (RF-09).
 *
 * Es una comprobación de perímetro, no la única: RegistrarAtencion vuelve a
 * verificar el rol en el dominio. La duplicación es deliberada — el middleware
 * evita trabajo innecesario y responde 403 temprano, pero la regla de quién
 * puede cerrar una alerta pertenece al negocio y debe seguir vigente si mañana
 * el caso de uso se invoca desde una cola o desde la consola.
 */
final class ExigirRol
{
    public function handle(Request $peticion, Closure $siguiente, string ...$roles): Response
    {
        $usuario = $peticion->user();

        if ($usuario === null) {
            return new JsonResponse(['error' => 'no_autenticado'], 401);
        }

        $rol = (string) $usuario->getAttribute('rol');

        if ($roles !== [] && ! in_array($rol, $roles, true)) {
            return new JsonResponse([
                'error' => 'rol_no_autorizado',
                'mensaje' => sprintf('El rol %s no puede acceder a este recurso.', $rol),
            ], 403);
        }

        return $siguiente($peticion);
    }
}

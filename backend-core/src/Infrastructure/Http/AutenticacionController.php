<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Sippt\Infrastructure\Persistence\Modelos\UsuarioModel;

/**
 * Emisión y revocación de tokens Bearer (RF-09).
 *
 * No forma parte de la Tabla 2 del informe, pero sin él los seis endpoints de
 * esa tabla serían inalcanzables: el contrato declara autenticación por token
 * Bearer y alguien tiene que emitirlo.
 */
final class AutenticacionController
{
    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        $usuario = UsuarioModel::query()
            ->where('email', $email)
            ->where('activo', true)
            ->first();

        // Se compara la contraseña incluso cuando el usuario no existe, para
        // que el tiempo de respuesta no revele qué correos están registrados.
        $hashSenuelo = '$2y$12$'.str_repeat('a', 53);
        $hashAlmacenado = $usuario === null ? $hashSenuelo : $usuario->password_hash;
        $valida = Hash::check($password, $hashAlmacenado);

        if ($usuario === null || ! $valida) {
            return response()->json([
                'error' => 'credenciales_invalidas',
                'mensaje' => 'El correo o la contrasena no son correctos.',
            ], 401);
        }

        $token = $usuario->createToken(
            name: 'sippt-'.$usuario->rol,
            abilities: ['*'],
        );

        return response()->json([
            'datos' => [
                'token' => $token->plainTextToken,
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'email' => $usuario->email,
                    'rol' => $usuario->rol,
                ],
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario instanceof UsuarioModel) {
            $usuario->currentAccessToken()->delete();
        }

        return response()->json(null, 204);
    }

    public function yo(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return response()->json(['error' => 'no_autenticado'], 401);
        }

        return response()->json([
            'datos' => [
                'id' => $usuario->getAuthIdentifier(),
                'nombre' => $usuario->getAttribute('nombre'),
                'email' => $usuario->getAttribute('email'),
                'rol' => $usuario->getAttribute('rol'),
            ],
        ]);
    }
}

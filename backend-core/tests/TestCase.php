<?php

declare(strict_types=1);

namespace Sippt\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Sippt\Infrastructure\Persistence\Modelos\UsuarioModel;

/**
 * Base de las pruebas de feature.
 *
 * Cada prueba se ejecuta dentro de una transacción que se revierte al
 * terminar, de modo que la base de pruebas conserva sus datos maestros y el
 * orden de ejecución de la suite no altera el resultado.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->loadEnvironmentFrom('.env.testing');
        $app->make(Kernel::class)->bootstrap();

        // La base de pruebas se fija DESPUÉS del arranque, y no mediante
        // variables de entorno, porque el contenedor define DB_DATABASE como
        // variable real del proceso y Dotenv no sobrescribe esas: ni .env.testing
        // ni phpunit.xml la desplazan. Sin esta línea la suite se ejecuta
        // contra la base de desarrollo, pasa igualmente, y solo falla el día
        // en que los datos de trabajo cambian o una prueba los destruye.
        config(['database.connections.pgsql.database' => 'sippt_test']);
        DB::purge('pgsql');

        return $app;
    }

    /**
     * Emite un token Bearer real para el rol indicado.
     *
     * Se autentica de verdad en lugar de usar actingAs() porque la cadena que
     * se quiere verificar incluye a Sanctum: un fallo en la emisión o en la
     * resolución del token debe hacer fallar las pruebas, no pasar inadvertido.
     *
     * @return array{0: UsuarioModel, 1: string}
     */
    protected function usuarioConToken(string $rol): array
    {
        $usuario = UsuarioModel::query()->where('rol', $rol)->firstOrFail();
        $usuario->forceFill(['password_hash' => Hash::make('sippt2026')])->save();

        $token = $usuario->createToken('pruebas-'.$rol)->plainTextToken;

        return [$usuario, $token];
    }

    /** @return array<string, string> */
    protected function cabeceras(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }
}

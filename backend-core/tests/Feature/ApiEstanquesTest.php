<?php

declare(strict_types=1);

namespace Sippt\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Sippt\Infrastructure\Persistence\Modelos\EstanqueModel;
use Sippt\Tests\TestCase;

final class ApiEstanquesTest extends TestCase
{
    #[Test]
    #[TestDox('Sin token la API responde 401 y no filtra datos')]
    public function sin_token_responde_401(): void
    {
        $this->getJson('/api/v1/estanques')
            ->assertStatus(401)
            ->assertJsonPath('error', 'no_autenticado');
    }

    #[Test]
    #[TestDox('Sin cabecera Accept tambien responde 401 y no un 500')]
    public function sin_cabecera_accept_responde_401(): void
    {
        // El servicio de ingesta y el simulador no envían Accept. Antes de
        // declarar que esta API no redirige invitados, este caso devolvía 500
        // porque Laravel buscaba una ruta «login» inexistente.
        $this->call('GET', '/api/v1/estanques')->assertStatus(401);
    }

    #[Test]
    #[TestDox('HU-03: el tablero recibe los estanques con semaforo y antiguedad')]
    public function el_tablero_recibe_el_estado_de_los_estanques(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        $respuesta = $this->getJson('/api/v1/estanques', $this->cabeceras($token))
            ->assertOk()
            ->assertJsonStructure([
                'datos' => [['id', 'codigo', 'semaforo', 'sin_comunicacion', 'parametros']],
            ]);

        $datos = $respuesta->json('datos');
        $this->assertCount(4, $datos, 'Los cuatro estanques semilla');

        $codigos = array_column($datos, 'codigo');
        $this->assertSame(['EST-01', 'EST-02', 'EST-03', 'EST-04'], $codigos);

        foreach ($datos as $estanque) {
            $this->assertContains($estanque['semaforo'], ['normal', 'advertencia', 'critico']);
            $this->assertCount(3, $estanque['parametros'], 'OD, temperatura y pH');
        }
    }

    #[Test]
    #[TestDox('HU-03: un estanque sin lecturas recientes se marca como sin comunicacion')]
    public function sin_lecturas_recientes_se_marca_sin_comunicacion(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        // La base de pruebas no tiene lecturas cargadas: todos los estanques
        // deben aparecer incomunicados, que es justo el comportamiento que el
        // operador necesita ver cuando un nodo deja de reportar.
        $datos = $this->getJson('/api/v1/estanques', $this->cabeceras($token))->json('datos');

        foreach ($datos as $estanque) {
            $this->assertTrue($estanque['sin_comunicacion'], $estanque['codigo']);

            foreach ($estanque['parametros'] as $parametro) {
                $this->assertNull($parametro['ultimo_valor']);
                $this->assertTrue($parametro['sin_comunicacion']);
            }
        }
    }

    #[Test]
    #[TestDox('HU-01: el tecnico da de alta un estanque con sus umbrales')]
    public function el_tecnico_da_de_alta_un_estanque(): void
    {
        [$usuario, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/estanques', [
            'codigo' => 'EST-77',
            'volumen_m3' => 110.5,
            'biomasa_kg' => 640.0,
            'etapa' => 'juvenil',
            'umbrales' => [
                ['parametro' => 'od_mgl', 'min_aceptable' => 5.5, 'max_aceptable' => 12.0, 'severidad_critica' => 0.5],
                ['parametro' => 'ph', 'min_aceptable' => 6.5, 'max_aceptable' => 8.5, 'severidad_critica' => 0.7],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('datos.codigo', 'EST-77')
            ->assertJsonCount(2, 'datos.umbrales');

        // RNF-04: toda escritura queda auditada con usuario y marca temporal.
        $fila = EstanqueModel::query()->where('codigo', 'EST-77')->firstOrFail();
        $this->assertSame((int) $usuario->id, (int) $fila->getAttribute('creado_por'));
        $this->assertNotNull($fila->getAttribute('actualizado_en'));
    }

    #[Test]
    #[TestDox('CP-02: un codigo de estanque duplicado devuelve 422 y no crea un segundo registro')]
    public function cp02_codigo_duplicado(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $antes = EstanqueModel::query()->where('codigo', 'EST-01')->count();
        $this->assertSame(1, $antes);

        $this->postJson('/api/v1/estanques', [
            'codigo' => 'EST-01',
            'volumen_m3' => 90.0,
            'biomasa_kg' => 10.0,
            'etapa' => 'juvenil',
            'umbrales' => [],
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'codigo');

        $this->assertSame(1, EstanqueModel::query()->where('codigo', 'EST-01')->count());
    }

    #[Test]
    #[TestDox('CP-01: un umbral con minimo mayor al maximo devuelve 422 con el campo')]
    public function cp01_umbral_invertido(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/estanques', [
            'codigo' => 'EST-78',
            'volumen_m3' => 90.0,
            'biomasa_kg' => 10.0,
            'etapa' => 'juvenil',
            'umbrales' => [
                ['parametro' => 'od_mgl', 'min_aceptable' => 9.0, 'max_aceptable' => 2.0, 'severidad_critica' => 0.5],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'min_aceptable');

        // El estanque tampoco debe crearse: la validación ocurre antes de
        // abrir la transacción, de modo que no queda un registro a medias.
        $this->assertSame(0, EstanqueModel::query()->where('codigo', 'EST-78')->count());
    }

    #[Test]
    #[TestDox('El operador no puede dar de alta estanques')]
    public function el_operador_no_puede_crear_estanques(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        $this->postJson('/api/v1/estanques', [
            'codigo' => 'EST-79',
            'volumen_m3' => 90.0,
            'biomasa_kg' => 10.0,
            'etapa' => 'juvenil',
            'umbrales' => [],
        ], $this->cabeceras($token))->assertStatus(403);

        $this->assertSame(0, EstanqueModel::query()->where('codigo', 'EST-79')->count());
    }

    #[Test]
    #[TestDox('Un parametro fuera del alcance del PMV se rechaza')]
    public function parametro_fuera_de_alcance_se_rechaza(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        // El amonio existe en el tipo enumerado de la base pero pertenece al
        // Incremento 2: aceptarlo aquí crearía un umbral que nada evalúa.
        $this->postJson('/api/v1/estanques', [
            'codigo' => 'EST-80',
            'volumen_m3' => 90.0,
            'biomasa_kg' => 10.0,
            'etapa' => 'juvenil',
            'umbrales' => [
                ['parametro' => 'nh4_mgl', 'min_aceptable' => 0.0, 'max_aceptable' => 1.0, 'severidad_critica' => 0.2],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'umbrales.parametro');
    }

    #[Test]
    #[TestDox('La serie temporal de un estanque inexistente devuelve 422')]
    public function serie_de_estanque_inexistente(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        $this->getJson('/api/v1/estanques/99999/lecturas', $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'id');
    }
}

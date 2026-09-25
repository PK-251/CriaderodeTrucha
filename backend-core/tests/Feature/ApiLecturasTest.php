<?php

declare(strict_types=1);

namespace Sippt\Tests\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Sippt\Application\Puertos\Notificador;
use Sippt\Tests\Dobles\NotificadorEspiaDeFeature;
use Sippt\Tests\TestCase;

final class ApiLecturasTest extends TestCase
{
    private NotificadorEspiaDeFeature $avisos;

    protected function setUp(): void
    {
        parent::setUp();

        // El espía guarda los avisos en un objeto compartido en lugar de una
        // referencia a una propiedad de la prueba: una clase anónima con
        // `array &$avisos` funciona, pero la referencia es frágil y el análisis
        // estático no puede tipar su contenido.
        $this->avisos = new NotificadorEspiaDeFeature;
        $this->app->instance(Notificador::class, $this->avisos);
    }

    private function instante(string $modificador = 'now'): string
    {
        return (new DateTimeImmutable($modificador))->format('c');
    }

    #[Test]
    #[TestDox('CP-03: una lectura valida se acepta con 201')]
    public function cp03_lectura_valida_se_acepta(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-07', 'medido_en' => $this->instante(), 'valor' => 7.8],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('aceptadas', 1)
            ->assertJsonPath('descartadas', 0)
            ->assertJsonPath('duplicadas', 0)
            ->assertJsonCount(0, 'alertas');
    }

    #[Test]
    #[TestDox('CP-07: OD 4.2 abre una alerta critica')]
    public function cp07_oxigeno_critico_abre_alerta(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-07', 'medido_en' => $this->instante(), 'valor' => 4.2],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('alertas.0.severidad', 'critica')
            ->assertJsonPath('alertas.0.parametro', 'od_mgl');
    }

    #[Test]
    #[TestDox('CP-06: OD 5.1 abre una alerta de advertencia, no critica')]
    public function cp06_oxigeno_en_advertencia(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-07', 'medido_en' => $this->instante(), 'valor' => 5.1],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('alertas.0.severidad', 'advertencia');
    }

    #[Test]
    #[TestDox('CP-05: un pH de 14.8 se descarta y la respuesta es 207')]
    public function cp05_lectura_descartada_devuelve_207(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-09', 'medido_en' => $this->instante(), 'valor' => 14.8],
            ],
        ], $this->cabeceras($token))
            // 207 y no 422: la lectura se almacenó correctamente, lo que falla
            // es el sensor. Rechazar el lote haría que el servicio de ingesta
            // la reintentara en bucle.
            ->assertStatus(207)
            ->assertJsonPath('descartadas', 1)
            ->assertJsonPath('detalle_descartadas.0.calidad', 'descartada')
            ->assertJsonCount(0, 'alertas');

        $this->assertSame(0, $this->avisos->total(), 'Una lectura descartada no avisa al operador');
    }

    #[Test]
    #[TestDox('CP-04: el reenvio del bufer no duplica ni vuelve a alertar')]
    public function cp04_reenvio_del_bufer(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');
        $momento = $this->instante();

        $lote = ['lecturas' => [
            ['codigo_nodo' => 'N-07', 'medido_en' => $momento, 'valor' => 4.2],
        ]];

        $this->postJson('/api/v1/lecturas', $lote, $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('aceptadas', 1);

        $this->assertSame(1, $this->avisos->total());

        $this->postJson('/api/v1/lecturas', $lote, $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('aceptadas', 0)
            ->assertJsonPath('duplicadas', 1)
            ->assertJsonCount(0, 'alertas');

        $this->assertSame(1, $this->avisos->total(), 'El reenvio no repite el aviso');
    }

    #[Test]
    #[TestDox('CP-04: un lote de 240 lecturas del bufer entra sin perdidas')]
    public function cp04_lote_de_240_sin_perdidas(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $base = new DateTimeImmutable('-20 minutes');
        $lecturas = [];

        for ($i = 0; $i < 240; $i++) {
            $lecturas[] = [
                'codigo_nodo' => 'N-07',
                'medido_en' => $base->modify("+{$i} seconds")->format('c'),
                'valor' => 7.5,
            ];
        }

        $this->postJson('/api/v1/lecturas', ['lecturas' => $lecturas], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('aceptadas', 240)
            ->assertJsonPath('duplicadas', 0);

        // Reenvío completo del mismo búfer.
        $this->postJson('/api/v1/lecturas', ['lecturas' => $lecturas], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('aceptadas', 0)
            ->assertJsonPath('duplicadas', 240);
    }

    #[Test]
    #[TestDox('CP-08: tres lecturas consecutivas fuera de rango mantienen una sola alerta')]
    public function cp08_sin_alertas_duplicadas(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');
        $base = new DateTimeImmutable('-15 minutes');

        foreach ([5.4, 5.3, 5.2] as $indice => $valor) {
            $this->postJson('/api/v1/lecturas', [
                'lecturas' => [[
                    'codigo_nodo' => 'N-07',
                    'medido_en' => $base->modify('+'.($indice * 5).' minutes')->format('c'),
                    'valor' => $valor,
                ]],
            ], $this->cabeceras($token))->assertStatus(201);
        }

        [, $tokenLectura] = $this->usuarioConToken('operador');
        $alertas = $this->getJson('/api/v1/alertas?estado=abierta', $this->cabeceras($tokenLectura))
            ->json('datos');

        $deEsteEstanque = array_values(array_filter(
            $alertas,
            static fn (array $a): bool => $a['estanque_codigo'] === 'EST-03' && $a['parametro'] === 'od_mgl',
        ));

        $this->assertCount(1, $deEsteEstanque, 'Una sola alerta abierta');
        $this->assertSame(5.4, (float) $deEsteEstanque[0]['valor_detectado'], 'Conserva el valor original');
        $this->assertSame(5.2, (float) $deEsteEstanque[0]['ultimo_valor'], 'Actualiza el ultimo valor');
        $this->assertSame(1, $this->avisos->total(), 'Un solo aviso para las tres lecturas');
    }

    #[Test]
    #[TestDox('Un nodo desconocido devuelve 422 senalando la lectura del lote')]
    public function nodo_desconocido_devuelve_422(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-99', 'medido_en' => $this->instante(), 'valor' => 7.0],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'lecturas.0.codigo_nodo');
    }

    #[Test]
    #[TestDox('Una marca temporal invalida devuelve 422')]
    public function marca_temporal_invalida(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-07', 'medido_en' => 'ayer por la tarde', 'valor' => 7.0],
            ],
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'lecturas.0.medido_en');
    }

    #[Test]
    #[TestDox('El operador no puede publicar lecturas: solo el servicio de ingesta')]
    public function el_operador_no_puede_publicar_lecturas(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [
                ['codigo_nodo' => 'N-07', 'medido_en' => $this->instante(), 'valor' => 7.0],
            ],
        ], $this->cabeceras($token))->assertStatus(403);
    }

    #[Test]
    #[TestDox('RNF-02: la marca temporal del nodo se conserva, no la del servidor')]
    public function rnf02_se_conserva_la_marca_del_nodo(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        // Lectura retenida 20 minutos en el búfer del gateway.
        $medidoEn = (new DateTimeImmutable('-20 minutes'))->format('c');

        $this->postJson('/api/v1/lecturas', [
            'lecturas' => [['codigo_nodo' => 'N-07', 'medido_en' => $medidoEn, 'valor' => 7.6]],
        ], $this->cabeceras($token))->assertStatus(201);

        [, $tokenLectura] = $this->usuarioConToken('operador');
        $serie = $this->getJson('/api/v1/estanques/3/lecturas?parametro=od_mgl', $this->cabeceras($tokenLectura))
            ->assertOk()
            ->json('datos');

        $this->assertNotEmpty($serie);

        $almacenado = new DateTimeImmutable($serie[0]['medido_en']);
        $esperado = new DateTimeImmutable($medidoEn);

        $this->assertSame(
            $esperado->getTimestamp(),
            $almacenado->getTimestamp(),
            'El instante debe ser el del nodo, no el de recepcion',
        );
    }
}

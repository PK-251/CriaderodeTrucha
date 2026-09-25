<?php

declare(strict_types=1);

namespace Sippt\Tests\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;
use Sippt\Infrastructure\Persistence\Modelos\AlertaModel;
use Sippt\Infrastructure\Persistence\Modelos\AtencionModel;
use Sippt\Tests\TestCase;

final class ApiAlertasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El notificador real publica en el broker y envía correo. En las
        // pruebas se sustituye por un doble silencioso: lo que se verifica
        // aquí es el contrato HTTP, no la entrega del aviso.
        $this->app->instance(Notificador::class, new class implements Notificador
        {
            public function notificar(Alerta $alerta): void {}
        });
    }

    private function abrirAlerta(string $severidad = 'critica'): int
    {
        $fila = AlertaModel::query()->create([
            'estanque_id' => 3,
            'parametro' => 'od_mgl',
            'severidad' => $severidad,
            'estado' => 'abierta',
            'valor_detectado' => 4.2,
            'umbral_violado' => 5.5,
            'ultimo_valor' => 4.2,
            'generada_en' => (new DateTimeImmutable)->format('Y-m-d H:i:sP'),
            'actualizada_en' => (new DateTimeImmutable)->format('Y-m-d H:i:sP'),
        ]);

        return (int) $fila->id;
    }

    #[Test]
    #[TestDox('HU-04: el listado devuelve las alertas por fecha descendente')]
    public function el_listado_ordena_por_fecha_descendente(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');
        $this->abrirAlerta();

        $datos = $this->getJson('/api/v1/alertas', $this->cabeceras($token))
            ->assertOk()
            ->json('datos');

        $this->assertNotEmpty($datos);
        $this->assertSame('EST-03', $datos[0]['estanque_codigo']);
        $this->assertSame('Oxígeno disuelto', $datos[0]['etiqueta']);
        $this->assertSame('mg/L', $datos[0]['unidad']);
    }

    #[Test]
    #[TestDox('Los filtros de estado y severidad se validan')]
    public function los_filtros_se_validan(): void
    {
        [, $token] = $this->usuarioConToken('tecnico');

        $this->getJson('/api/v1/alertas?estado=inventado', $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'estado');

        $this->getJson('/api/v1/alertas?severidad=grave', $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'severidad');
    }

    #[Test]
    #[TestDox('HU-05: el operador registra la atencion y la alerta queda cerrada')]
    public function el_operador_registra_la_atencion(): void
    {
        [$usuario, $token] = $this->usuarioConToken('operador');
        $alertaId = $this->abrirAlerta();

        $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => 'Aireacion manual activada',
            'observacion' => 'Se restablecio el aireador de EST-03.',
        ], $this->cabeceras($token))
            ->assertStatus(201)
            ->assertJsonPath('datos.accion', 'Aireacion manual activada');

        $alerta = AlertaModel::query()->findOrFail($alertaId);
        $this->assertSame('atendida', $alerta->estado);
        $this->assertSame((int) $usuario->id, (int) $alerta->getAttribute('cerrada_por'));
    }

    #[Test]
    #[TestDox('CP-10: atender una alerta ya atendida devuelve 409 con el registro existente')]
    public function cp10_atencion_duplicada_devuelve_409(): void
    {
        [, $token] = $this->usuarioConToken('operador');
        $alertaId = $this->abrirAlerta();

        $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => 'Aireacion manual activada',
        ], $this->cabeceras($token))->assertStatus(201);

        $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => 'Intento duplicado',
        ], $this->cabeceras($token))
            ->assertStatus(409)
            ->assertJsonPath('error', 'alerta_ya_atendida')
            // El contrato exige devolver la atención existente, no la rechazada:
            // el tablero la muestra en lugar de duplicar el registro.
            ->assertJsonPath('datos.accion', 'Aireacion manual activada');

        $this->assertSame(1, AtencionModel::query()->where('alerta_id', $alertaId)->count());
    }

    #[Test]
    #[TestDox('El veterinario recibe 403 y la alerta sigue abierta')]
    public function el_veterinario_no_puede_atender(): void
    {
        [, $token] = $this->usuarioConToken('veterinario');
        $alertaId = $this->abrirAlerta();

        $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => 'Diagnostico sanitario',
        ], $this->cabeceras($token))
            ->assertStatus(403)
            ->assertJsonPath('error', 'rol_no_autorizado');

        // Un 403 no puede tener efectos secundarios.
        $this->assertSame('abierta', AlertaModel::query()->findOrFail($alertaId)->estado);
        $this->assertSame(0, AtencionModel::query()->where('alerta_id', $alertaId)->count());
    }

    #[Test]
    #[TestDox('Una accion vacia devuelve 422 senalando el campo')]
    public function accion_vacia_devuelve_422(): void
    {
        [, $token] = $this->usuarioConToken('operador');
        $alertaId = $this->abrirAlerta();

        $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => '   ',
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'accion');
    }

    #[Test]
    #[TestDox('Atender una alerta inexistente devuelve 422')]
    public function alerta_inexistente_devuelve_422(): void
    {
        [, $token] = $this->usuarioConToken('operador');

        $this->postJson('/api/v1/alertas/99999/atencion', [
            'accion' => 'Aireacion manual',
        ], $this->cabeceras($token))
            ->assertStatus(422)
            ->assertJsonPath('detalle.0.campo', 'alerta_id');
    }

    #[Test]
    #[TestDox('RNF-04: la marca temporal almacenada es el instante real, no uno desplazado')]
    public function rnf04_la_marca_temporal_conserva_el_instante(): void
    {
        [, $token] = $this->usuarioConToken('operador');
        $alertaId = $this->abrirAlerta();

        $antes = new DateTimeImmutable;

        $registrada = $this->postJson("/api/v1/alertas/{$alertaId}/atencion", [
            'accion' => 'Aireacion manual',
        ], $this->cabeceras($token))->json('datos.registrada_en');

        $guardada = new DateTimeImmutable($registrada);
        $desfase = abs($guardada->getTimestamp() - $antes->getTimestamp());

        // El formato de fecha por defecto de Eloquent descarta el
        // desplazamiento horario, y entonces PostgreSQL reinterpreta la hora en
        // el huso de su sesión: la marca quedaba cinco horas desplazada. Este
        // aserto falla si se pierde el dateFormat con desplazamiento.
        $this->assertLessThan(
            60,
            $desfase,
            'La marca temporal difiere del instante real: se perdio el desplazamiento horario.'
        );
    }
}

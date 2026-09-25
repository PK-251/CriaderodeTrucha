<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Application\UseCases\RegistrarAtencion;
use Sippt\Domain\Alerta;
use Sippt\Domain\EstadoAlerta;
use Sippt\Domain\Excepciones\AlertaYaAtendida;
use Sippt\Domain\Excepciones\ErrorDeValidacion;
use Sippt\Domain\Excepciones\RolNoAutorizado;
use Sippt\Domain\Parametro;
use Sippt\Domain\Rol;
use Sippt\Domain\Severidad;
use Sippt\Tests\Dobles\RelojFijo;
use Sippt\Tests\Dobles\RepositorioAlertaEnMemoria;
use Sippt\Tests\Dobles\RepositorioAtencionEnMemoria;

final class RegistrarAtencionTest extends TestCase
{
    private RepositorioAlertaEnMemoria $alertas;

    private RepositorioAtencionEnMemoria $atenciones;

    private RegistrarAtencion $caso;

    private int $alertaId;

    protected function setUp(): void
    {
        $this->alertas = new RepositorioAlertaEnMemoria;
        $this->atenciones = new RepositorioAtencionEnMemoria;

        $this->caso = new RegistrarAtencion(
            $this->alertas,
            $this->atenciones,
            RelojFijo::en('2026-09-18T18:10:00-05:00'),
        );

        $guardada = $this->alertas->guardar(Alerta::abrir(
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            severidad: Severidad::CRITICA,
            valorDetectado: 4.2,
            umbralViolado: 5.5,
            generadaEn: new DateTimeImmutable('2026-09-18T17:42:51-05:00'),
        ));

        $this->alertaId = $guardada->id ?? 0;
    }

    #[Test]
    #[TestDox('HU-05: el operador registra la accion y la alerta queda atendida')]
    public function el_operador_registra_la_atencion_y_cierra_la_alerta(): void
    {
        $atencion = $this->caso->ejecutar(
            alertaId: $this->alertaId,
            usuarioId: 1,
            rol: Rol::OPERADOR,
            accion: 'Aireacion manual activada',
            observacion: 'Se restablecio el aireador del estanque EST-03.',
        );

        $this->assertSame($this->alertaId, $atencion->alertaId);
        $this->assertSame(1, $atencion->usuarioId);
        $this->assertSame('Aireacion manual activada', $atencion->accion);

        $alerta = $this->alertas->buscarPorId($this->alertaId);
        $this->assertNotNull($alerta);
        $this->assertSame(EstadoAlerta::ATENDIDA, $alerta->estado());
        $this->assertSame(1, $alerta->cerradaPor(), 'RNF-04: queda la autoria');
        $this->assertFalse($alerta->estaAbierta());
    }

    #[Test]
    #[TestDox('El tecnico acuicola tambien puede registrar la atencion')]
    public function el_tecnico_puede_registrar_la_atencion(): void
    {
        $atencion = $this->caso->ejecutar(
            alertaId: $this->alertaId,
            usuarioId: 2,
            rol: Rol::TECNICO,
            accion: 'Recambio parcial de agua',
        );

        $this->assertSame(2, $atencion->usuarioId);
        $this->assertNull($atencion->observacion);
    }

    #[Test]
    #[TestDox('CP-10: registrar una atencion sobre una alerta ya atendida se rechaza')]
    public function cp10_atencion_duplicada_se_rechaza(): void
    {
        $this->caso->ejecutar(
            alertaId: $this->alertaId,
            usuarioId: 1,
            rol: Rol::OPERADOR,
            accion: 'Aireacion manual activada',
        );

        $this->expectException(AlertaYaAtendida::class);

        $this->caso->ejecutar(
            alertaId: $this->alertaId,
            usuarioId: 1,
            rol: Rol::OPERADOR,
            accion: 'Otra accion',
        );
    }

    #[Test]
    #[TestDox('CP-10: el segundo intento no crea un registro adicional')]
    public function cp10_el_intento_fallido_no_deja_registro(): void
    {
        $this->caso->ejecutar(
            alertaId: $this->alertaId,
            usuarioId: 1,
            rol: Rol::OPERADOR,
            accion: 'Aireacion manual activada',
        );

        try {
            $this->caso->ejecutar(
                alertaId: $this->alertaId,
                usuarioId: 2,
                rol: Rol::TECNICO,
                accion: 'Intento duplicado',
            );
        } catch (AlertaYaAtendida) {
            // Esperado.
        }

        $this->assertSame(1, $this->atenciones->total());

        // La atención que sobrevive es la primera: el contrato REST devuelve
        // 409 junto al registro existente, no el del intento rechazado.
        $existente = $this->atenciones->buscarPorAlerta($this->alertaId);
        $this->assertNotNull($existente);
        $this->assertSame('Aireacion manual activada', $existente->accion);
        $this->assertSame(1, $existente->usuarioId);
    }

    #[Test]
    #[TestDox('El veterinario no puede cerrar alertas en el PMV')]
    public function el_veterinario_no_puede_registrar_atencion(): void
    {
        try {
            $this->caso->ejecutar(
                alertaId: $this->alertaId,
                usuarioId: 3,
                rol: Rol::VETERINARIO,
                accion: 'Diagnostico sanitario',
            );
            $this->fail('Se esperaba RolNoAutorizado.');
        } catch (RolNoAutorizado $e) {
            $this->assertSame(Rol::VETERINARIO, $e->rol);
        }

        // La alerta debe seguir abierta: un 403 no puede tener efectos.
        $alerta = $this->alertas->buscarPorId($this->alertaId);
        $this->assertNotNull($alerta);
        $this->assertTrue($alerta->estaAbierta());
        $this->assertSame(0, $this->atenciones->total());
    }

    #[Test]
    #[TestDox('Registrar la atencion de una alerta inexistente se rechaza')]
    public function alerta_inexistente_se_rechaza(): void
    {
        try {
            $this->caso->ejecutar(
                alertaId: 9999,
                usuarioId: 1,
                rol: Rol::OPERADOR,
                accion: 'Aireacion manual',
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('alerta_id', $e->campo);
        }
    }

    #[Test]
    #[TestDox('Una accion vacia se rechaza y la alerta no se cierra a medias')]
    public function accion_vacia_se_rechaza(): void
    {
        try {
            $this->caso->ejecutar(
                alertaId: $this->alertaId,
                usuarioId: 1,
                rol: Rol::OPERADOR,
                accion: '  ',
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('accion', $e->campo);
        }

        $this->assertSame(0, $this->atenciones->total());
    }

    #[Test]
    #[TestDox('Una alerta ya cerrada en la entidad rechaza un segundo cierre')]
    public function la_entidad_protege_el_doble_cierre(): void
    {
        $alerta = Alerta::abrir(
            estanqueId: 1,
            parametro: Parametro::PH,
            severidad: Severidad::ADVERTENCIA,
            valorDetectado: 8.9,
            umbralViolado: 8.5,
            generadaEn: new DateTimeImmutable('2026-09-18T10:00:00-05:00'),
            id: 55,
        );

        $alerta->atender(1, new DateTimeImmutable('2026-09-18T10:05:00-05:00'));

        $this->expectException(AlertaYaAtendida::class);
        $alerta->atender(2, new DateTimeImmutable('2026-09-18T10:06:00-05:00'));
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Domain\AtencionAlerta;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Estanque;
use Sippt\Domain\Excepciones\ErrorDeValidacion;
use Sippt\Domain\Parametro;
use Sippt\Domain\Sensor;
use Sippt\Domain\Umbral;

final class ValidacionDeEntidadesTest extends TestCase
{
    #[Test]
    #[TestDox('CP-01: un umbral con minimo mayor al maximo se rechaza senalando el campo')]
    public function cp01_umbral_invertido_se_rechaza_con_el_campo(): void
    {
        try {
            new Umbral(
                estanqueId: 1,
                parametro: Parametro::OD_MGL,
                minAceptable: 9.0,
                maxAceptable: 2.0,
                severidadCritica: 0.5,
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            // El campo importa: el contrato REST responde 422 «con detalle por
            // campo», y sin este dato el adaptador tendría que adivinarlo.
            $this->assertSame('min_aceptable', $e->campo);
        }
    }

    #[Test]
    #[TestDox('CP-01: minimo igual al maximo tambien se rechaza')]
    public function cp01_rango_degenerado_se_rechaza(): void
    {
        $this->expectException(ErrorDeValidacion::class);

        new Umbral(
            estanqueId: 1,
            parametro: Parametro::PH,
            minAceptable: 7.0,
            maxAceptable: 7.0,
            severidadCritica: 0.5,
        );
    }

    #[Test]
    #[TestDox('Un margen critico negativo se rechaza')]
    public function margen_critico_negativo_se_rechaza(): void
    {
        try {
            new Umbral(
                estanqueId: 1,
                parametro: Parametro::PH,
                minAceptable: 6.5,
                maxAceptable: 8.5,
                severidadCritica: -0.3,
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('severidad_critica', $e->campo);
        }
    }

    #[Test]
    #[TestDox('Los limites criticos se derivan del margen')]
    public function los_limites_criticos_se_derivan_del_margen(): void
    {
        $umbral = new Umbral(
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            minAceptable: 5.5,
            maxAceptable: 12.0,
            severidadCritica: 0.5,
        );

        $this->assertEqualsWithDelta(5.0, $umbral->limiteCriticoInferior(), 1e-9);
        $this->assertEqualsWithDelta(12.5, $umbral->limiteCriticoSuperior(), 1e-9);
        $this->assertTrue($umbral->contiene(7.0));
        $this->assertFalse($umbral->contiene(5.0));
    }

    #[Test]
    #[TestDox('Un estanque sin volumen positivo se rechaza')]
    public function estanque_sin_volumen_se_rechaza(): void
    {
        try {
            new Estanque(codigo: 'EST-99', volumenM3: 0.0, biomasaKg: 10.0, etapa: 'juvenil');
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('volumen_m3', $e->campo);
        }
    }

    #[Test]
    #[TestDox('Un estanque con codigo vacio o etapa desconocida se rechaza')]
    public function estanque_con_datos_invalidos_se_rechaza(): void
    {
        try {
            new Estanque(codigo: '   ', volumenM3: 80.0, biomasaKg: 0.0, etapa: 'juvenil');
            $this->fail('Se esperaba ErrorDeValidacion por codigo.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('codigo', $e->campo);
        }

        try {
            new Estanque(codigo: 'EST-05', volumenM3: 80.0, biomasaKg: 0.0, etapa: 'reproduccion');
            $this->fail('Se esperaba ErrorDeValidacion por etapa.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('etapa', $e->campo);
        }

        try {
            new Estanque(codigo: 'EST-06', volumenM3: 80.0, biomasaKg: -1.0, etapa: 'engorde');
            $this->fail('Se esperaba ErrorDeValidacion por biomasa.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('biomasa_kg', $e->campo);
        }
    }

    #[Test]
    #[TestDox('La densidad de siembra se calcula sobre el volumen del estanque')]
    public function densidad_de_siembra(): void
    {
        $estanque = new Estanque(codigo: 'EST-03', volumenM3: 150.0, biomasaKg: 1650.0, etapa: 'engorde');

        $this->assertEqualsWithDelta(11.0, $estanque->densidadKgM3(), 1e-9);
    }

    #[Test]
    #[TestDox('CP-05: un pH de 14.8 queda descartado por exceder el rango fisico del electrodo')]
    public function cp05_ph_fuera_del_rango_fisico_se_descarta(): void
    {
        $electrodo = new Sensor(
            id: 9,
            codigoNodo: 'N-09',
            estanqueId: 3,
            parametro: Parametro::PH,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 14.0,
        );

        $this->assertSame(CalidadLectura::DESCARTADA, $electrodo->calificar(14.8));
        $this->assertSame(CalidadLectura::DESCARTADA, $electrodo->calificar(-0.5));
        $this->assertFalse($electrodo->calificar(14.8)->puedeGenerarAlerta());
    }

    #[Test]
    #[TestDox('Los valores en los extremos del rango fisico se marcan como dudosos')]
    public function valores_en_los_extremos_son_dudosos(): void
    {
        $electrodo = new Sensor(
            id: 9,
            codigoNodo: 'N-09',
            estanqueId: 3,
            parametro: Parametro::PH,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 14.0,
        );

        // Margen del 2 %: 0.28 unidades de pH en cada extremo.
        $this->assertSame(CalidadLectura::DUDOSA, $electrodo->calificar(0.2));
        $this->assertSame(CalidadLectura::DUDOSA, $electrodo->calificar(13.9));
        $this->assertSame(CalidadLectura::VALIDA, $electrodo->calificar(7.4));
        $this->assertTrue($electrodo->calificar(7.4)->puedeGenerarAlerta());
    }

    #[Test]
    #[TestDox('Un sensor con rango fisico invertido se rechaza')]
    public function sensor_con_rango_invertido_se_rechaza(): void
    {
        try {
            new Sensor(
                id: 1,
                codigoNodo: 'N-01',
                estanqueId: 1,
                parametro: Parametro::OD_MGL,
                rangoFisicoMin: 20.0,
                rangoFisicoMax: 0.0,
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('rango_fisico_min', $e->campo);
        }
    }

    #[Test]
    #[TestDox('Una atencion sin accion registrada se rechaza')]
    public function atencion_sin_accion_se_rechaza(): void
    {
        try {
            new AtencionAlerta(
                alertaId: 1,
                usuarioId: 1,
                accion: '   ',
                observacion: 'Sin detalle',
                registradaEn: new DateTimeImmutable,
            );
            $this->fail('Se esperaba ErrorDeValidacion.');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('accion', $e->campo);
        }
    }

    #[Test]
    #[TestDox('Una accion de mas de 80 caracteres se rechaza')]
    public function accion_demasiado_larga_se_rechaza(): void
    {
        $this->expectException(ErrorDeValidacion::class);

        new AtencionAlerta(
            alertaId: 1,
            usuarioId: 1,
            accion: str_repeat('a', 81),
            observacion: null,
            registradaEn: new DateTimeImmutable,
        );
    }
}

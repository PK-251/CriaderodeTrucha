<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Lectura;
use Sippt\Domain\Parametro;

final class LecturaTest extends TestCase
{
    private function lectura(string $medidoEn): Lectura
    {
        return new Lectura(
            sensorId: 7,
            medidoEn: new DateTimeImmutable($medidoEn),
            valor: 7.8,
            calidad: CalidadLectura::VALIDA,
        );
    }

    #[Test]
    #[TestDox('HU-03: la antiguedad de la lectura decide la marca de sin comunicacion')]
    public function la_antiguedad_sostiene_la_marca_de_sin_comunicacion(): void
    {
        $ahora = new DateTimeImmutable('2026-09-18T18:00:00-05:00');
        $sinComunicacionTrasSegundos = 15 * 60;

        $reciente = $this->lectura('2026-09-18T17:55:00-05:00');
        $this->assertSame(300, $reciente->antiguedadEnSegundos($ahora));
        $this->assertLessThan($sinComunicacionTrasSegundos, $reciente->antiguedadEnSegundos($ahora));

        $rezagada = $this->lectura('2026-09-18T17:40:00-05:00');
        $this->assertSame(1200, $rezagada->antiguedadEnSegundos($ahora));
        $this->assertGreaterThan($sinComunicacionTrasSegundos, $rezagada->antiguedadEnSegundos($ahora));
    }

    #[Test]
    #[TestDox('La antiguedad se mide sobre medido_en y no sobre recibido_en')]
    public function la_antiguedad_usa_el_instante_de_medicion(): void
    {
        $ahora = new DateTimeImmutable('2026-09-18T18:00:00-05:00');

        // Lectura retenida 20 minutos en el búfer del gateway: se midió a las
        // 17:40 y solo llegó a las 18:00. El estanque estuvo incomunicado ese
        // tiempo, y usar recibido_en lo ocultaría mostrándolo como al día.
        $retenida = new Lectura(
            sensorId: 7,
            medidoEn: new DateTimeImmutable('2026-09-18T17:40:00-05:00'),
            valor: 7.8,
            calidad: CalidadLectura::VALIDA,
            recibidoEn: $ahora,
        );

        $this->assertSame(1200, $retenida->antiguedadEnSegundos($ahora));
    }

    #[Test]
    #[TestDox('La clave natural de la lectura coincide con la clave primaria')]
    public function la_clave_natural_identifica_la_lectura(): void
    {
        $a = $this->lectura('2026-09-18T17:42:10-05:00');
        $b = $this->lectura('2026-09-18T17:42:10-05:00');
        $c = $this->lectura('2026-09-18T17:47:10-05:00');

        $this->assertSame($a->clave(), $b->clave(), 'Mismo sensor e instante');
        $this->assertNotSame($a->clave(), $c->clave(), 'Instantes distintos');
    }

    #[Test]
    #[TestDox('Cada parametro expone su etiqueta y unidad para el aviso al operador')]
    public function los_parametros_se_presentan_con_etiqueta_y_unidad(): void
    {
        $this->assertSame('Oxígeno disuelto', Parametro::OD_MGL->etiqueta());
        $this->assertSame('mg/L', Parametro::OD_MGL->unidad());

        $this->assertSame('Temperatura', Parametro::TEMP_C->etiqueta());
        $this->assertSame('°C', Parametro::TEMP_C->unidad());

        $this->assertSame('pH', Parametro::PH->etiqueta());
        $this->assertSame('', Parametro::PH->unidad());
    }

    #[Test]
    #[TestDox('El amonio no existe en el dominio del PMV')]
    public function el_amonio_queda_fuera_del_dominio(): void
    {
        // El tipo enumerado de la base lo declara para el Incremento 2, pero el
        // núcleo no debe aceptarlo: sin umbral configurado, una lectura de
        // amonio no puede evaluarse.
        $valores = array_map(
            static fn (Parametro $parametro): string => $parametro->value,
            Parametro::cases(),
        );

        $this->assertSame(['od_mgl', 'temp_c', 'ph'], $valores);
        $this->assertNotContains('nh4_mgl', $valores);
    }
}

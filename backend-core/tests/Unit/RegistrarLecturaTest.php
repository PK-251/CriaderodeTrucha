<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Application\UseCases\EvaluarUmbrales;
use Sippt\Application\UseCases\RegistrarLectura;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Excepciones\SensorDesconocido;
use Sippt\Domain\Parametro;
use Sippt\Domain\Sensor;
use Sippt\Domain\Severidad;
use Sippt\Domain\Umbral;
use Sippt\Tests\Dobles\NotificadorEspia;
use Sippt\Tests\Dobles\RelojFijo;
use Sippt\Tests\Dobles\RepositorioAlertaEnMemoria;
use Sippt\Tests\Dobles\RepositorioLecturaEnMemoria;
use Sippt\Tests\Dobles\RepositorioSensorEnMemoria;
use Sippt\Tests\Dobles\RepositorioUmbralEnMemoria;

final class RegistrarLecturaTest extends TestCase
{
    private RepositorioSensorEnMemoria $sensores;

    private RepositorioLecturaEnMemoria $lecturas;

    private RepositorioAlertaEnMemoria $alertas;

    private NotificadorEspia $notificador;

    private RegistrarLectura $caso;

    protected function setUp(): void
    {
        $this->sensores = new RepositorioSensorEnMemoria;
        $this->lecturas = new RepositorioLecturaEnMemoria;
        $this->alertas = new RepositorioAlertaEnMemoria;
        $this->notificador = new NotificadorEspia;

        $umbrales = new RepositorioUmbralEnMemoria;
        $umbrales->agregar(new Umbral(
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            minAceptable: 5.5,
            maxAceptable: 12.0,
            severidadCritica: 0.5,
        ));
        $umbrales->agregar(new Umbral(
            estanqueId: 3,
            parametro: Parametro::PH,
            minAceptable: 6.5,
            maxAceptable: 8.5,
            severidadCritica: 0.7,
        ));

        $reloj = RelojFijo::en('2026-09-18T17:45:00-05:00');

        $this->sensores->agregar(new Sensor(
            id: 7,
            codigoNodo: 'N-07',
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 20.0,
        ));

        $this->sensores->agregar(new Sensor(
            id: 9,
            codigoNodo: 'N-09',
            estanqueId: 3,
            parametro: Parametro::PH,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 14.0,
        ));

        $this->caso = new RegistrarLectura(
            $this->sensores,
            $this->lecturas,
            new EvaluarUmbrales($umbrales, $this->alertas, $this->notificador, $reloj),
            $reloj,
        );
    }

    #[Test]
    #[TestDox('CP-03: una lectura valida se persiste con su marca temporal y calidad')]
    public function cp03_lectura_valida_se_persiste(): void
    {
        $resultado = $this->caso->ejecutar(
            'N-07',
            new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            7.8,
        );

        $this->assertTrue($resultado->esNueva);
        $this->assertSame(CalidadLectura::VALIDA, $resultado->lectura->calidad);
        $this->assertSame(1, $this->lecturas->total());
        $this->assertFalse($resultado->generoAlerta());

        // La marca temporal es la del nodo, no la del servidor (RNF-02).
        $this->assertSame(
            '2026-09-18T17:42:10-05:00',
            $resultado->lectura->medidoEn->format('Y-m-d\TH:i:sP'),
        );
        $this->assertNotNull($resultado->lectura->recibidoEn);
        $this->assertNotEquals(
            $resultado->lectura->medidoEn,
            $resultado->lectura->recibidoEn,
        );
    }

    #[Test]
    #[TestDox('CP-04: reenviar la misma lectura no la duplica ni reevalua la alerta')]
    public function cp04_lectura_repetida_se_absorbe(): void
    {
        $momento = new DateTimeImmutable('2026-09-18T17:42:10-05:00');

        $primera = $this->caso->ejecutar('N-07', $momento, 5.1);
        $this->assertTrue($primera->esNueva);
        $this->assertTrue($primera->generoAlerta());
        $this->assertSame(1, $this->notificador->total());

        // El gateway reenvía su búfer tras restablecerse el enlace.
        $repetida = $this->caso->ejecutar('N-07', $momento, 5.1);

        $this->assertFalse($repetida->esNueva);
        $this->assertFalse($repetida->generoAlerta());
        $this->assertSame(1, $this->lecturas->total(), 'Sin duplicados');
        $this->assertSame(1, $this->lecturas->duplicadasAbsorbidas());
        $this->assertSame(1, $this->alertas->total(), 'Sin alerta duplicada');
        $this->assertSame(1, $this->notificador->total(), 'Sin aviso repetido');
    }

    #[Test]
    #[TestDox('CP-04: un lote de 240 lecturas del bufer se almacena sin perdidas ni duplicados')]
    public function cp04_lote_del_bufer_sin_perdidas(): void
    {
        // 20 minutos de enlace caído a razón de una lectura cada 5 segundos
        // por los 12 sensores de la piscigranja: 240 lecturas retenidas.
        $base = new DateTimeImmutable('2026-09-18T17:00:00-05:00');
        $insertadas = 0;

        for ($i = 0; $i < 240; $i++) {
            $resultado = $this->caso->ejecutar(
                'N-07',
                $base->modify("+{$i} seconds"),
                7.5,
            );
            $insertadas += $resultado->esNueva ? 1 : 0;
        }

        $this->assertSame(240, $insertadas, 'Cero perdidas');
        $this->assertSame(240, $this->lecturas->total());

        // Reenvío completo del mismo búfer: ninguna se inserta por segunda vez.
        $reinsertadas = 0;
        for ($i = 0; $i < 240; $i++) {
            $resultado = $this->caso->ejecutar(
                'N-07',
                $base->modify("+{$i} seconds"),
                7.5,
            );
            $reinsertadas += $resultado->esNueva ? 1 : 0;
        }

        $this->assertSame(0, $reinsertadas, 'Cero duplicados');
        $this->assertSame(240, $this->lecturas->total());
        $this->assertSame(240, $this->lecturas->duplicadasAbsorbidas());
    }

    #[Test]
    #[TestDox('CP-05: un pH de 14.8 se almacena como descartado y no genera alerta')]
    public function cp05_ph_imposible_se_descarta_sin_alertar(): void
    {
        $resultado = $this->caso->ejecutar(
            'N-09',
            new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            14.8,
        );

        $this->assertTrue($resultado->esNueva);
        $this->assertSame(CalidadLectura::DESCARTADA, $resultado->lectura->calidad);

        // Se almacena: el registro de que el sensor falló es información útil.
        $this->assertSame(1, $this->lecturas->total());

        // Pero no alerta: 14.8 evaluado contra el umbral habría producido una
        // alerta crítica falsa por exceso de pH.
        $this->assertFalse($resultado->generoAlerta());
        $this->assertSame(0, $this->alertas->total());
        $this->assertSame(0, $this->notificador->total());
    }

    #[Test]
    #[TestDox('Una lectura critica abre la alerta y notifica')]
    public function lectura_critica_abre_alerta(): void
    {
        $resultado = $this->caso->ejecutar(
            'N-07',
            new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            4.2,
        );

        $this->assertTrue($resultado->generoAlerta());
        $this->assertNotNull($resultado->alerta);
        $this->assertSame(Severidad::CRITICA, $resultado->alerta->severidad());
        $this->assertSame(1, $this->notificador->total());
    }

    #[Test]
    #[TestDox('Una lectura de un nodo desconocido se rechaza')]
    public function nodo_desconocido_se_rechaza(): void
    {
        $this->expectException(SensorDesconocido::class);

        $this->caso->ejecutar(
            'N-99',
            new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            7.0,
        );
    }

    #[Test]
    #[TestDox('Una lectura de un sensor dado de baja se rechaza')]
    public function sensor_inactivo_se_rechaza(): void
    {
        $this->sensores->agregar(new Sensor(
            id: 11,
            codigoNodo: 'N-11',
            estanqueId: 4,
            parametro: Parametro::TEMP_C,
            rangoFisicoMin: -5.0,
            rangoFisicoMax: 45.0,
            activo: false,
        ));

        $this->expectException(SensorDesconocido::class);

        $this->caso->ejecutar(
            'N-11',
            new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            12.0,
        );
    }

    #[Test]
    #[TestDox('RNF-01: el aviso se produce muy por debajo de los 5 minutos exigidos')]
    public function rnf01_el_aviso_se_genera_dentro_del_presupuesto(): void
    {
        $medidoEn = new DateTimeImmutable('2026-09-18T17:42:10-05:00');

        $resultado = $this->caso->ejecutar('N-07', $medidoEn, 4.2);

        $this->assertNotNull($resultado->alerta);

        // El reloj de la prueba sitúa el procesamiento a los 170 s de la
        // medición. RNF-01 concede 300 s desde la medición hasta el aviso.
        $transcurrido = $resultado->alerta->generadaEn->getTimestamp() - $medidoEn->getTimestamp();

        $this->assertLessThan(300, $transcurrido, 'RNF-01: aviso en menos de 5 minutos');
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Application\UseCases\EvaluarUmbrales;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\EstadoAlerta;
use Sippt\Domain\Excepciones\UmbralNoConfigurado;
use Sippt\Domain\Lectura;
use Sippt\Domain\Parametro;
use Sippt\Domain\Sensor;
use Sippt\Domain\Severidad;
use Sippt\Domain\Umbral;
use Sippt\Tests\Dobles\NotificadorEspia;
use Sippt\Tests\Dobles\RelojFijo;
use Sippt\Tests\Dobles\RepositorioAlertaEnMemoria;
use Sippt\Tests\Dobles\RepositorioUmbralEnMemoria;

final class EvaluarUmbralesTest extends TestCase
{
    private RepositorioUmbralEnMemoria $umbrales;

    private RepositorioAlertaEnMemoria $alertas;

    private NotificadorEspia $notificador;

    private RelojFijo $reloj;

    private EvaluarUmbrales $caso;

    private Sensor $sensorOxigeno;

    protected function setUp(): void
    {
        $this->umbrales = new RepositorioUmbralEnMemoria;
        $this->alertas = new RepositorioAlertaEnMemoria;
        $this->notificador = new NotificadorEspia;
        $this->reloj = RelojFijo::en('2026-09-18T17:42:10-05:00');

        $this->caso = new EvaluarUmbrales(
            $this->umbrales,
            $this->alertas,
            $this->notificador,
            $this->reloj,
        );

        $this->sensorOxigeno = new Sensor(
            id: 7,
            codigoNodo: 'N-07',
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 20.0,
        );

        $this->umbrales->agregar(new Umbral(
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            minAceptable: 5.5,
            maxAceptable: 12.0,
            severidadCritica: 0.5,
        ));
    }

    private function lectura(float $valor, string $momento = '2026-09-18T17:42:10-05:00'): Lectura
    {
        return new Lectura(
            sensorId: 7,
            medidoEn: new DateTimeImmutable($momento),
            valor: $valor,
            calidad: CalidadLectura::VALIDA,
        );
    }

    #[Test]
    #[TestDox('Una lectura dentro del rango no abre ninguna alerta')]
    public function lectura_normal_no_abre_alerta(): void
    {
        $alerta = $this->caso->ejecutar($this->lectura(7.8), $this->sensorOxigeno);

        $this->assertNull($alerta);
        $this->assertSame(0, $this->alertas->total());
        $this->assertSame(0, $this->notificador->total());
    }

    #[Test]
    #[TestDox('CP-06: una lectura de advertencia abre la alerta y notifica una vez')]
    public function abre_alerta_de_advertencia_y_notifica(): void
    {
        $alerta = $this->caso->ejecutar($this->lectura(5.1), $this->sensorOxigeno);

        $this->assertNotNull($alerta);
        $this->assertSame(Severidad::ADVERTENCIA, $alerta->severidad());
        $this->assertSame(EstadoAlerta::ABIERTA, $alerta->estado());
        $this->assertSame(5.1, $alerta->valorDetectado);
        $this->assertSame(5.5, $alerta->umbralViolado);
        $this->assertSame(1, $this->alertas->total());
        $this->assertSame(1, $this->notificador->total());
    }

    #[Test]
    #[TestDox('CP-08: tres lecturas consecutivas fuera de rango mantienen UNA sola alerta abierta')]
    public function cp08_tres_lecturas_consecutivas_no_duplican_la_alerta(): void
    {
        $this->caso->ejecutar($this->lectura(5.4, '2026-09-18T17:42:10-05:00'), $this->sensorOxigeno);

        $this->reloj->avanzar('+5 minutes');
        $this->caso->ejecutar($this->lectura(5.3, '2026-09-18T17:47:10-05:00'), $this->sensorOxigeno);

        $this->reloj->avanzar('+5 minutes');
        $alerta = $this->caso->ejecutar($this->lectura(5.2, '2026-09-18T17:52:10-05:00'), $this->sensorOxigeno);

        $this->assertSame(1, $this->alertas->total(), 'Debe existir una sola alerta');
        $this->assertNotNull($alerta);

        // El valor que originó la alerta se conserva; el último se actualiza.
        $this->assertSame(5.4, $alerta->valorDetectado);
        $this->assertSame(5.2, $alerta->ultimoValor());

        // Las tres lecturas eran advertencia: no hubo agravamiento, de modo que
        // el operador recibió un solo aviso. Repetirlo cada cinco minutos es lo
        // que produce fatiga de alertas.
        $this->assertSame(1, $this->notificador->total());
    }

    #[Test]
    #[TestDox('Si la condicion se agrava de advertencia a critica, se vuelve a notificar')]
    public function el_agravamiento_reabre_la_notificacion(): void
    {
        $this->caso->ejecutar($this->lectura(5.1), $this->sensorOxigeno);
        $this->assertSame(1, $this->notificador->total());

        $this->reloj->avanzar('+5 minutes');
        $alerta = $this->caso->ejecutar($this->lectura(4.2, '2026-09-18T17:47:10-05:00'), $this->sensorOxigeno);

        $this->assertNotNull($alerta);
        $this->assertSame(1, $this->alertas->total(), 'Sigue siendo la misma alerta');
        $this->assertSame(Severidad::CRITICA, $alerta->severidad(), 'Escalo la severidad');
        $this->assertSame(2, $this->notificador->total(), 'El agravamiento si se notifica');
    }

    #[Test]
    #[TestDox('Una alerta critica que empeora mas no vuelve a notificar')]
    public function critica_que_empeora_no_notifica_de_nuevo(): void
    {
        $this->caso->ejecutar($this->lectura(4.2), $this->sensorOxigeno);
        $avisosTrasLaPrimera = $this->notificador->total();
        $this->assertSame(1, $avisosTrasLaPrimera);

        $this->reloj->avanzar('+5 minutes');
        $this->caso->ejecutar($this->lectura(3.1, '2026-09-18T17:47:10-05:00'), $this->sensorOxigeno);

        // Lo que se comprueba no es que el contador valga 1, sino que NO creció:
        // una crítica que empeora sigue siendo la misma condición ya avisada.
        $this->assertSame(
            $avisosTrasLaPrimera,
            $this->notificador->total(),
            'Un empeoramiento dentro de la misma severidad no vuelve a avisar',
        );
    }

    #[Test]
    #[TestDox('Cuando la condicion se normaliza la alerta sigue abierta hasta que se atienda')]
    public function la_normalizacion_no_cierra_la_alerta_sola(): void
    {
        $this->caso->ejecutar($this->lectura(5.1), $this->sensorOxigeno);

        $this->reloj->avanzar('+5 minutes');
        $alerta = $this->caso->ejecutar($this->lectura(7.8, '2026-09-18T17:47:10-05:00'), $this->sensorOxigeno);

        // El valor volvió a su rango, pero el riesgo ocurrió: cerrar la alerta
        // sin intervención registrada destruiría la trazabilidad que HU-05
        // existe para construir.
        $this->assertNotNull($alerta);
        $this->assertTrue($alerta->estaAbierta());
        $this->assertSame(1, $this->alertas->total());
    }

    #[Test]
    #[TestDox('CP-05: una lectura descartada no abre alerta aunque este fuera de rango')]
    public function cp05_lectura_descartada_no_abre_alerta(): void
    {
        $descartada = new Lectura(
            sensorId: 7,
            medidoEn: new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            valor: 0.1,
            calidad: CalidadLectura::DESCARTADA,
        );

        $alerta = $this->caso->ejecutar($descartada, $this->sensorOxigeno);

        $this->assertNull($alerta);
        $this->assertSame(0, $this->alertas->total());
        $this->assertSame(0, $this->notificador->total());
    }

    #[Test]
    #[TestDox('Un estanque sin umbral configurado falla de forma explicita')]
    public function sin_umbral_configurado_lanza_excepcion(): void
    {
        $sensorPh = new Sensor(
            id: 9,
            codigoNodo: 'N-09',
            estanqueId: 3,
            parametro: Parametro::PH,
            rangoFisicoMin: 0.0,
            rangoFisicoMax: 14.0,
        );

        $this->expectException(UmbralNoConfigurado::class);

        $this->caso->ejecutar($this->lectura(7.2), $sensorPh);
    }

    #[Test]
    #[TestDox('Alertas de parametros distintos del mismo estanque coexisten')]
    public function parametros_distintos_generan_alertas_independientes(): void
    {
        $this->umbrales->agregar(new Umbral(
            estanqueId: 3,
            parametro: Parametro::TEMP_C,
            minAceptable: 9.0,
            maxAceptable: 16.0,
            severidadCritica: 2.0,
        ));

        $sensorTemp = new Sensor(
            id: 8,
            codigoNodo: 'N-08',
            estanqueId: 3,
            parametro: Parametro::TEMP_C,
            rangoFisicoMin: -5.0,
            rangoFisicoMax: 45.0,
        );

        $this->caso->ejecutar($this->lectura(5.1), $this->sensorOxigeno);

        $lecturaTemp = new Lectura(
            sensorId: 8,
            medidoEn: new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            valor: 17.5,
            calidad: CalidadLectura::VALIDA,
        );
        $this->caso->ejecutar($lecturaTemp, $sensorTemp);

        // El índice parcial de la base restringe (estanque, parámetro), no solo
        // el estanque: dos parámetros en riesgo simultáneo son dos alertas.
        $this->assertSame(2, $this->alertas->total());
        $this->assertSame(2, $this->notificador->total());
    }
}

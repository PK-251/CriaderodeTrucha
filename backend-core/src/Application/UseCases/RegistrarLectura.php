<?php

declare(strict_types=1);

namespace Sippt\Application\UseCases;

use DateTimeImmutable;
use Sippt\Application\Puertos\Reloj;
use Sippt\Application\Puertos\RepositorioLectura;
use Sippt\Application\Puertos\RepositorioSensor;
use Sippt\Domain\Alerta;
use Sippt\Domain\Excepciones\SensorDesconocido;
use Sippt\Domain\Lectura;

/**
 * Registra una medición proveniente de un nodo sensor (HU-02 / RF-03, RF-04).
 *
 * Es el único punto de entrada de datos al sistema, y llega por dos caminos
 * distintos —MQTT desde el nodo y HTTP desde las pruebas de integración o una
 * carga histórica—. Gracias a los puertos, ambos ejercitan este mismo código.
 */
final readonly class RegistrarLectura
{
    public function __construct(
        private RepositorioSensor $sensores,
        private RepositorioLectura $lecturas,
        private EvaluarUmbrales $evaluarUmbrales,
        private Reloj $reloj,
    ) {}

    public function ejecutar(
        string $codigoNodo,
        DateTimeImmutable $medidoEn,
        float $valor,
    ): ResultadoRegistroLectura {
        $sensor = $this->sensores->buscarPorCodigoNodo($codigoNodo);

        if ($sensor === null || ! $sensor->activo) {
            throw new SensorDesconocido($codigoNodo);
        }

        // La calidad se decide ANTES de persistir (RF-04) y antes de evaluar
        // umbrales: es lo que impide que un pH de 14.8 dispare una alerta
        // crítica falsa en lugar de delatar una sonda averiada (CP-05).
        $calidad = $sensor->calificar($valor);

        $lectura = new Lectura(
            sensorId: $sensor->id,
            medidoEn: $medidoEn,
            valor: $valor,
            calidad: $calidad,
            recibidoEn: $this->reloj->ahora(),
        );

        // CP-04: si la lectura ya existía, el reenvío del búfer la trae por
        // segunda vez. No es un error y no debe reevaluarse: la alerta que
        // correspondiera ya se generó la primera vez.
        $esNueva = $this->lecturas->guardarSiNoExiste($lectura);

        if (! $esNueva) {
            return new ResultadoRegistroLectura($lectura, false, null);
        }

        $alerta = $lectura->puedeGenerarAlerta()
            ? $this->evaluarUmbrales->ejecutar($lectura, $sensor)
            : null;

        return new ResultadoRegistroLectura($lectura, true, $alerta);
    }
}

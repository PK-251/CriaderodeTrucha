<?php

declare(strict_types=1);

namespace Sippt\Application\UseCases;

use Sippt\Application\Puertos\Notificador;
use Sippt\Application\Puertos\Reloj;
use Sippt\Application\Puertos\RepositorioAlerta;
use Sippt\Application\Puertos\RepositorioUmbral;
use Sippt\Domain\Alerta;
use Sippt\Domain\Excepciones\UmbralNoConfigurado;
use Sippt\Domain\Lectura;
use Sippt\Domain\ReglaUmbral;
use Sippt\Domain\Sensor;

/**
 * Evalúa una lectura contra los umbrales de su estanque y gestiona la alerta
 * resultante (HU-04 / RF-06, RF-07).
 *
 * Es el caso de uso donde se decide si el operador recibe un aviso, de modo que
 * concentra los tres comportamientos que los criterios de aceptación exigen:
 * generar la alerta con su severidad, no duplicarla mientras siga abierta, y
 * notificar solo cuando hay información nueva que comunicar.
 */
final readonly class EvaluarUmbrales
{
    public function __construct(
        private RepositorioUmbral $umbrales,
        private RepositorioAlerta $alertas,
        private Notificador $notificador,
        private Reloj $reloj,
        private ReglaUmbral $regla = new ReglaUmbral,
    ) {}

    /**
     * Devuelve la alerta abierta resultante, o null si no hay condición de
     * riesgo que comunicar.
     */
    public function ejecutar(Lectura $lectura, Sensor $sensor): ?Alerta
    {
        $umbral = $this->umbrales->buscarPor($sensor->estanqueId, $sensor->parametro);

        if ($umbral === null) {
            throw new UmbralNoConfigurado($sensor->estanqueId, $sensor->parametro);
        }

        $resultado = $this->regla->evaluar($lectura, $umbral);
        $abierta = $this->alertas->buscarAbierta($sensor->estanqueId, $sensor->parametro);

        if (! $resultado->hayRiesgo) {
            // La condición se normalizó. La alerta abierta NO se cierra sola:
            // cerrarla exige una intervención registrada (HU-05), porque el
            // valor del sistema está en dejar constancia de qué se hizo, no
            // solo de que el número volvió a su rango.
            return $abierta;
        }

        $momento = $this->reloj->ahora();

        // CP-08: ya hay una alerta abierta para este estanque y parámetro.
        if ($abierta !== null) {
            $escalo = $abierta->registrarNuevoValor(
                $lectura->valor,
                $resultado->severidad ?? $abierta->severidad(),
                $momento,
            );

            $guardada = $this->alertas->guardar($abierta);

            // Solo se vuelve a notificar si la condición se agravó: repetir el
            // aviso en cada lectura fuera de rango produciría una alerta cada
            // cinco minutos y llevaría al operador a ignorarlas (fatiga de
            // alertas, riesgo reconocido en la sección 8.1 del informe).
            if ($escalo) {
                $this->notificador->notificar($guardada);
            }

            return $guardada;
        }

        $alerta = Alerta::abrir(
            estanqueId: $sensor->estanqueId,
            parametro: $sensor->parametro,
            severidad: $resultado->severidad ?? throw new \LogicException('Riesgo sin severidad.'),
            valorDetectado: $lectura->valor,
            umbralViolado: $resultado->umbralViolado ?? 0.0,
            generadaEn: $momento,
        );

        $guardada = $this->alertas->guardar($alerta);
        $this->notificador->notificar($guardada);

        return $guardada;
    }
}

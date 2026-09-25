<?php

declare(strict_types=1);

namespace Sippt\Domain;

/**
 * Política de evaluación de riesgo del PMV (RF-06).
 *
 * Es el corazón del valor del primer incremento: lo que convierte una serie de
 * números en un aviso accionable. Por eso vive en el dominio, sin dependencia
 * alguna de framework, base de datos ni broker, y se prueba unitariamente.
 *
 * En el PMV la decisión es determinística por umbral. Cuando el modelo
 * predictivo llegue en incrementos posteriores no reemplazará esta clase: será
 * otro adaptador detrás del mismo puerto de evaluación, de modo que el tablero
 * y la ingesta no tengan que reescribirse.
 */
final class ReglaUmbral
{
    /**
     * Tolerancia para la comparación de flotantes.
     *
     * Sin ella, el caso frontera del informe sería frágil: 5.5 − 0.5 no da
     * exactamente 5.0 en coma flotante binaria, de modo que una lectura de
     * justo 5.0 mg/L podría clasificarse como advertencia en lugar de crítica
     * por un error de representación de 10⁻¹⁶.
     */
    private const EPSILON = 1e-9;

    public function evaluar(Lectura $lectura, Umbral $umbral): ResultadoEvaluacion
    {
        // CP-05: una lectura descartada o dudosa no describe el agua, sino el
        // estado del sensor. Evaluarla produciría una alerta falsa.
        if (! $lectura->puedeGenerarAlerta()) {
            return ResultadoEvaluacion::sinRiesgo();
        }

        $valor = $lectura->valor;

        // ── Riesgo por defecto ──────────────────────────────────────────────
        if ($valor < $umbral->minAceptable - self::EPSILON) {
            $severidad = $valor <= $umbral->limiteCriticoInferior() + self::EPSILON
                ? Severidad::CRITICA
                : Severidad::ADVERTENCIA;

            return ResultadoEvaluacion::conRiesgo($severidad, $umbral->minAceptable);
        }

        // ── Riesgo por exceso ───────────────────────────────────────────────
        if ($valor > $umbral->maxAceptable + self::EPSILON) {
            $severidad = $valor >= $umbral->limiteCriticoSuperior() - self::EPSILON
                ? Severidad::CRITICA
                : Severidad::ADVERTENCIA;

            return ResultadoEvaluacion::conRiesgo($severidad, $umbral->maxAceptable);
        }

        return ResultadoEvaluacion::sinRiesgo();
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Domain;

use Sippt\Domain\Excepciones\ErrorDeValidacion;

/**
 * Rango aceptable de un parámetro en un estanque, con su margen crítico (RF-02).
 *
 * `severidadCritica` es un MARGEN más allá del rango aceptable, no un valor
 * absoluto:
 *
 *   crítica ⟺ valor ≤ (minAceptable − severidadCritica)
 *           ∨ valor ≥ (maxAceptable + severidadCritica)
 *
 * Se modela así porque el pH y la temperatura son peligrosos en ambas
 * direcciones y un único valor absoluto solo podría expresar una de ellas. El
 * escenario del informe se reproduce exactamente: con minAceptable 5.5 y
 * margen 0.5, el límite crítico de oxígeno disuelto es 5.0.
 */
final readonly class Umbral
{
    public function __construct(
        public int $estanqueId,
        public Parametro $parametro,
        public float $minAceptable,
        public float $maxAceptable,
        public float $severidadCritica,
        public ?int $id = null,
    ) {
        // CP-01: mínimo mayor o igual al máximo → rechazo señalando el campo.
        if ($minAceptable >= $maxAceptable) {
            throw new ErrorDeValidacion(
                'min_aceptable',
                'El mínimo aceptable debe ser menor que el máximo aceptable.'
            );
        }

        if ($severidadCritica < 0.0) {
            throw new ErrorDeValidacion(
                'severidad_critica',
                'El margen crítico no puede ser negativo.'
            );
        }
    }

    /** Valor por debajo del cual la condición es crítica. */
    public function limiteCriticoInferior(): float
    {
        return $this->minAceptable - $this->severidadCritica;
    }

    /** Valor por encima del cual la condición es crítica. */
    public function limiteCriticoSuperior(): float
    {
        return $this->maxAceptable + $this->severidadCritica;
    }

    public function contiene(float $valor): bool
    {
        return $valor >= $this->minAceptable && $valor <= $this->maxAceptable;
    }
}

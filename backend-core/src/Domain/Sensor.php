<?php

declare(strict_types=1);

namespace Sippt\Domain;

use Sippt\Domain\Excepciones\ErrorDeValidacion;

/**
 * Nodo físico que mide un parámetro en un estanque.
 *
 * Conoce su propio rango físico, que es el del instrumento y no el biológico.
 * Esa distinción es la que sostiene CP-05: un electrodo de pH mide de 0 a 14,
 * de modo que un 14.8 no describe el agua sino una sonda averiada.
 */
final readonly class Sensor
{
    public function __construct(
        public int $id,
        public string $codigoNodo,
        public int $estanqueId,
        public Parametro $parametro,
        public float $rangoFisicoMin,
        public float $rangoFisicoMax,
        public bool $activo = true,
    ) {
        if ($rangoFisicoMin >= $rangoFisicoMax) {
            throw new ErrorDeValidacion(
                'rango_fisico_min',
                'El rango físico mínimo debe ser menor que el máximo.'
            );
        }
    }

    /**
     * Califica una medición antes de persistirla (RF-04).
     *
     * Fuera del rango físico → descartada: el instrumento no puede haber
     * medido eso. En los extremos del rango → dudosa: son valores alcanzables
     * pero característicos de una sonda mal calibrada o sucia, de modo que se
     * conservan sin dejarlos alimentar alertas ni entrenamiento.
     */
    public function calificar(float $valor): CalidadLectura
    {
        if ($valor < $this->rangoFisicoMin || $valor > $this->rangoFisicoMax) {
            return CalidadLectura::DESCARTADA;
        }

        $margen = ($this->rangoFisicoMax - $this->rangoFisicoMin) * 0.02;

        if ($valor <= $this->rangoFisicoMin + $margen || $valor >= $this->rangoFisicoMax - $margen) {
            return CalidadLectura::DUDOSA;
        }

        return CalidadLectura::VALIDA;
    }
}

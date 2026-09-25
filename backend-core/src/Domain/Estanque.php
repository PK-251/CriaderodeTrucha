<?php

declare(strict_types=1);

namespace Sippt\Domain;

use Sippt\Domain\Excepciones\ErrorDeValidacion;

/**
 * Unidad de producción vigilada por el sistema (RF-01).
 */
final readonly class Estanque
{
    private const ETAPAS = ['alevino', 'juvenil', 'engorde', 'cosecha'];

    public function __construct(
        public string $codigo,
        public float $volumenM3,
        public float $biomasaKg,
        public string $etapa,
        public bool $activo = true,
        public ?int $id = null,
    ) {
        if (trim($codigo) === '') {
            throw new ErrorDeValidacion('codigo', 'El código del estanque es obligatorio.');
        }

        if ($volumenM3 <= 0.0) {
            throw new ErrorDeValidacion('volumen_m3', 'El volumen debe ser mayor que cero.');
        }

        if ($biomasaKg < 0.0) {
            throw new ErrorDeValidacion('biomasa_kg', 'La biomasa no puede ser negativa.');
        }

        if (! in_array($etapa, self::ETAPAS, true)) {
            throw new ErrorDeValidacion(
                'etapa',
                'La etapa debe ser una de: '.implode(', ', self::ETAPAS).'.'
            );
        }
    }

    /**
     * Densidad de siembra en kg/m³. No la usa el PMV para decidir nada, pero
     * es el dato que el técnico contrasta al interpretar una caída de oxígeno:
     * un estanque más cargado la tolera peor.
     */
    public function densidadKgM3(): float
    {
        return $this->biomasaKg / $this->volumenM3;
    }
}

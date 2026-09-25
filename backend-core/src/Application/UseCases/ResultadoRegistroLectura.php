<?php

declare(strict_types=1);

namespace Sippt\Application\UseCases;

use Sippt\Domain\Alerta;
use Sippt\Domain\Lectura;

/**
 * Resultado del registro de una lectura.
 *
 * El servicio de ingesta necesita las tres piezas: la lectura tal como quedó
 * (con su calidad ya decidida), si era nueva —para contar inserciones frente a
 * duplicados absorbidos del búfer en CP-04— y la alerta, si la hubo.
 */
final readonly class ResultadoRegistroLectura
{
    public function __construct(
        public Lectura $lectura,
        public bool $esNueva,
        public ?Alerta $alerta,
    ) {}

    public function generoAlerta(): bool
    {
        return $this->alerta !== null;
    }
}

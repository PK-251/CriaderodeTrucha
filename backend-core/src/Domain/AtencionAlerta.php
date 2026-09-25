<?php

declare(strict_types=1);

namespace Sippt\Domain;

use DateTimeImmutable;
use Sippt\Domain\Excepciones\ErrorDeValidacion;

/**
 * Registro de la intervención realizada frente a una alerta (RF-08).
 *
 * Es la pieza que convierte el sistema en algo más que un detector: además de
 * dejar trazabilidad para el veterinario y el gerente, produce el etiquetado
 * del histórico que alimentará el modelo predictivo de incrementos posteriores.
 */
final readonly class AtencionAlerta
{
    public function __construct(
        public int $alertaId,
        public int $usuarioId,
        public string $accion,
        public ?string $observacion,
        public DateTimeImmutable $registradaEn,
        public ?int $id = null,
    ) {
        if (trim($accion) === '') {
            throw new ErrorDeValidacion('accion', 'La acción tomada no puede estar vacía.');
        }

        if (mb_strlen($accion) > 80) {
            throw new ErrorDeValidacion('accion', 'La acción no puede superar los 80 caracteres.');
        }
    }
}

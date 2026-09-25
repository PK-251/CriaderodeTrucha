<?php

declare(strict_types=1);

namespace Sippt\Domain\Excepciones;

use DomainException;

/**
 * CP-10: se intenta registrar una atención sobre una alerta ya atendida.
 * El adaptador HTTP la traduce a 409 y devuelve el registro existente.
 */
final class AlertaYaAtendida extends DomainException
{
    public function __construct(public readonly int $alertaId)
    {
        parent::__construct("La alerta {$alertaId} ya fue atendida.");
    }
}

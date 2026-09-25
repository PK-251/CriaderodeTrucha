<?php

declare(strict_types=1);

namespace Sippt\Domain\Excepciones;

use DomainException;

/**
 * Llegó una lectura de un nodo que no está registrado o está dado de baja.
 * El adaptador HTTP la traduce a 422 (contrato de POST /api/v1/lecturas).
 */
final class SensorDesconocido extends DomainException
{
    public function __construct(public readonly string $codigoNodo)
    {
        parent::__construct("No existe un sensor activo con código de nodo {$codigoNodo}.");
    }
}

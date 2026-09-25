<?php

declare(strict_types=1);

namespace Sippt\Domain\Excepciones;

use DomainException;
use Sippt\Domain\Rol;

/**
 * El rol autenticado no puede ejecutar la operación (RF-09).
 * El adaptador HTTP la traduce a 403.
 */
final class RolNoAutorizado extends DomainException
{
    public function __construct(public readonly Rol $rol, string $operacion)
    {
        parent::__construct("El rol {$rol->value} no puede {$operacion}.");
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Domain\Excepciones;

use DomainException;

/**
 * Violación de una invariante del dominio atribuible a un campo concreto.
 *
 * Lleva el nombre del campo porque los criterios de aceptación lo exigen: CP-01
 * pide que el sistema «rechace el registro y muestre el error del campo», y el
 * contrato REST del PMV responde 422 «con detalle por campo». Sin este dato, el
 * adaptador HTTP tendría que adivinar a qué campo atribuir el fallo.
 */
class ErrorDeValidacion extends DomainException
{
    public function __construct(
        public readonly string $campo,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }
}

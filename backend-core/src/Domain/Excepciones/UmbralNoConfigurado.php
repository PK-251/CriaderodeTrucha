<?php

declare(strict_types=1);

namespace Sippt\Domain\Excepciones;

use DomainException;
use Sippt\Domain\Parametro;

/**
 * El estanque no tiene umbral configurado para el parámetro medido.
 *
 * No es un error del emisor: la lectura se almacena igual. Lo que no puede
 * hacerse es evaluarla, porque sin umbral no existe noción de riesgo. Se
 * modela como excepción y no como silencio para que el caso quede visible en
 * los registros en lugar de convertirse en un estanque que nunca alerta.
 */
final class UmbralNoConfigurado extends DomainException
{
    public function __construct(
        public readonly int $estanqueId,
        public readonly Parametro $parametro,
    ) {
        parent::__construct(
            "El estanque {$estanqueId} no tiene umbral configurado para {$parametro->value}."
        );
    }
}

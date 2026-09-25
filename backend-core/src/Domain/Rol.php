<?php

declare(strict_types=1);

namespace Sippt\Domain;

enum Rol: string
{
    case OPERADOR = 'operador';
    case TECNICO = 'tecnico';
    case VETERINARIO = 'veterinario';

    /**
     * HU-05: «Dado que existe una alerta abierta y estoy autenticado con rol
     * operador o técnico, cuando registro la acción y guardo…»
     *
     * El veterinario diagnostica pero no ejecuta la intervención en el
     * estanque, de modo que no cierra alertas en el PMV.
     */
    public function puedeRegistrarAtencion(): bool
    {
        return $this === self::OPERADOR || $this === self::TECNICO;
    }
}

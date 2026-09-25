<?php

declare(strict_types=1);

namespace Sippt\Domain;

enum Severidad: string
{
    case ADVERTENCIA = 'advertencia';
    case CRITICA = 'critica';

    /**
     * Una alerta crítica se notifica además por correo (HU-04); una de
     * advertencia solo aparece en el tablero.
     */
    public function exigeNotificacionExterna(): bool
    {
        return $this === self::CRITICA;
    }

    public function esMasGraveQue(self $otra): bool
    {
        return $this === self::CRITICA && $otra === self::ADVERTENCIA;
    }
}

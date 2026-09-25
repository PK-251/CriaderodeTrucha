<?php

declare(strict_types=1);

namespace Sippt\Domain;

/**
 * Calidad asignada a una lectura antes de persistirla (RF-04).
 *
 * Una lectura descartada se almacena igualmente —el hueco en la serie es en sí
 * mismo información sobre el estado del sensor— pero no participa ni en la
 * evaluación de umbrales ni en el futuro entrenamiento del modelo.
 */
enum CalidadLectura: string
{
    case VALIDA = 'valida';
    case DUDOSA = 'dudosa';
    case DESCARTADA = 'descartada';

    /**
     * Solo una lectura válida puede originar una alerta.
     *
     * CP-05: un pH de 14.8 está fuera del rango físico del electrodo, de modo
     * que delata un sensor averiado y no una condición del agua. Evaluarlo
     * contra los umbrales produciría una alerta crítica falsa.
     */
    public function puedeGenerarAlerta(): bool
    {
        return $this === self::VALIDA;
    }
}

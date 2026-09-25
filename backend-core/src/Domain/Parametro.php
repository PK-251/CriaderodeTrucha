<?php

declare(strict_types=1);

namespace Sippt\Domain;

/**
 * Parámetros de calidad del agua vigilados por el PMV.
 *
 * El tipo enumerado de la base de datos incluye además nh4_mgl (amonio), que
 * pertenece al Incremento 2. Aquí NO se declara: si una lectura de amonio
 * llegara al núcleo, el dominio debe rechazarla en lugar de procesarla en
 * silencio, porque no existe umbral configurado para ella.
 */
enum Parametro: string
{
    case OD_MGL = 'od_mgl';
    case TEMP_C = 'temp_c';
    case PH = 'ph';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OD_MGL => 'Oxígeno disuelto',
            self::TEMP_C => 'Temperatura',
            self::PH => 'pH',
        };
    }

    public function unidad(): string
    {
        return match ($this) {
            self::OD_MGL => 'mg/L',
            self::TEMP_C => '°C',
            self::PH => '',
        };
    }
}

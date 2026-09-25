<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\Sensor;

/**
 * Puerto de salida hacia la persistencia de sensores.
 *
 * El núcleo declara QUÉ necesita; el adaptador de la Fase 3 decide CÓMO
 * obtenerlo (Eloquent, en este caso). Gracias a esto las pruebas unitarias
 * sustituyen la base de datos por un doble en memoria.
 */
interface RepositorioSensor
{
    public function buscarPorCodigoNodo(string $codigoNodo): ?Sensor;

    public function buscarPorId(int $id): ?Sensor;
}

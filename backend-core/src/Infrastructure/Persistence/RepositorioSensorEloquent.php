<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence;

use Sippt\Application\Puertos\RepositorioSensor;
use Sippt\Domain\Parametro;
use Sippt\Domain\Sensor;
use Sippt\Infrastructure\Persistence\Modelos\SensorModel;

/**
 * Adaptador secundario: traduce entre la tabla sensor y la entidad de dominio.
 *
 * La traducción es explícita y no automática porque el dominio y la tabla no
 * tienen por qué coincidir: la entidad usa camelCase y tipos del dominio,
 * mientras la tabla usa snake_case y tipos enumerados de PostgreSQL.
 */
final class RepositorioSensorEloquent implements RepositorioSensor
{
    public function buscarPorCodigoNodo(string $codigoNodo): ?Sensor
    {
        $fila = SensorModel::query()->where('codigo_nodo', $codigoNodo)->first();

        return $fila === null ? null : $this->aDominio($fila);
    }

    public function buscarPorId(int $id): ?Sensor
    {
        $fila = SensorModel::query()->find($id);

        return $fila === null ? null : $this->aDominio($fila);
    }

    private function aDominio(SensorModel $fila): ?Sensor
    {
        $parametro = Parametro::tryFrom($fila->parametro);

        // El tipo enumerado de la base incluye nh4_mgl, que el dominio del PMV
        // no reconoce. Un sensor de amonio existe en la tabla pero no puede
        // convertirse en entidad: se trata como inexistente hasta el
        // Incremento 2, en lugar de romper con un error de tipo.
        if ($parametro === null) {
            return null;
        }

        return new Sensor(
            id: $fila->id,
            codigoNodo: $fila->codigo_nodo,
            estanqueId: $fila->estanque_id,
            parametro: $parametro,
            rangoFisicoMin: $fila->rango_fisico_min,
            rangoFisicoMax: $fila->rango_fisico_max,
            activo: $fila->activo,
        );
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence;

use Sippt\Application\Puertos\RepositorioUmbral;
use Sippt\Domain\Parametro;
use Sippt\Domain\Umbral;
use Sippt\Infrastructure\Persistence\Modelos\UmbralModel;

final class RepositorioUmbralEloquent implements RepositorioUmbral
{
    public function buscarPor(int $estanqueId, Parametro $parametro): ?Umbral
    {
        $fila = UmbralModel::query()
            ->where('estanque_id', $estanqueId)
            ->where('parametro', $parametro->value)
            ->first();

        if ($fila === null) {
            return null;
        }

        return new Umbral(
            estanqueId: $fila->estanque_id,
            parametro: $parametro,
            minAceptable: $fila->min_aceptable,
            maxAceptable: $fila->max_aceptable,
            severidadCritica: $fila->severidad_critica,
            id: $fila->id,
        );
    }
}

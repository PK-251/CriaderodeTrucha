<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence;

use DateTimeImmutable;
use Sippt\Application\Puertos\RepositorioAtencion;
use Sippt\Domain\AtencionAlerta;
use Sippt\Infrastructure\Persistence\Modelos\AtencionModel;

final class RepositorioAtencionEloquent implements RepositorioAtencion
{
    public function buscarPorAlerta(int $alertaId): ?AtencionAlerta
    {
        $fila = AtencionModel::query()->where('alerta_id', $alertaId)->first();

        return $fila === null ? null : $this->aDominio($fila);
    }

    public function guardar(AtencionAlerta $atencion): AtencionAlerta
    {
        $fila = AtencionModel::query()->create([
            'alerta_id' => $atencion->alertaId,
            'usuario_id' => $atencion->usuarioId,
            'accion' => $atencion->accion,
            'observacion' => $atencion->observacion,
            'registrada_en' => $atencion->registradaEn->format('Y-m-d H:i:sP'),
        ]);

        return $this->aDominio($fila);
    }

    private function aDominio(AtencionModel $fila): AtencionAlerta
    {
        return new AtencionAlerta(
            alertaId: $fila->alerta_id,
            usuarioId: $fila->usuario_id,
            accion: $fila->accion,
            observacion: $fila->observacion,
            registradaEn: new DateTimeImmutable($fila->registrada_en->format('c')),
            id: $fila->id,
        );
    }
}

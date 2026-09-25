<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence;

use DateTimeImmutable;
use Sippt\Application\Puertos\RepositorioAlerta;
use Sippt\Domain\Alerta;
use Sippt\Domain\EstadoAlerta;
use Sippt\Domain\Parametro;
use Sippt\Domain\Severidad;
use Sippt\Infrastructure\Persistence\Modelos\AlertaModel;

final class RepositorioAlertaEloquent implements RepositorioAlerta
{
    public function buscarAbierta(int $estanqueId, Parametro $parametro): ?Alerta
    {
        $fila = AlertaModel::query()
            ->where('estanque_id', $estanqueId)
            ->where('parametro', $parametro->value)
            ->where('estado', EstadoAlerta::ABIERTA->value)
            ->first();

        return $fila === null ? null : $this->aDominio($fila);
    }

    public function buscarPorId(int $id): ?Alerta
    {
        $fila = AlertaModel::query()->find($id);

        return $fila === null ? null : $this->aDominio($fila);
    }

    public function guardar(Alerta $alerta): Alerta
    {
        $atributos = [
            'estanque_id' => $alerta->estanqueId,
            'parametro' => $alerta->parametro->value,
            'severidad' => $alerta->severidad()->value,
            'estado' => $alerta->estado()->value,
            'valor_detectado' => $alerta->valorDetectado,
            'umbral_violado' => $alerta->umbralViolado,
            'ultimo_valor' => $alerta->ultimoValor(),
            'generada_en' => $alerta->generadaEn->format('Y-m-d H:i:sP'),
            'actualizada_en' => $alerta->actualizadaEn()->format('Y-m-d H:i:sP'),
            'cerrada_por' => $alerta->cerradaPor(),
        ];

        if ($alerta->id !== null) {
            AlertaModel::query()->where('id', $alerta->id)->update($atributos);

            $fila = AlertaModel::query()->findOrFail($alerta->id);

            return $this->aDominio($fila);
        }

        $fila = AlertaModel::query()->create($atributos);

        return $this->aDominio($fila);
    }

    private function aDominio(AlertaModel $fila): Alerta
    {
        return Alerta::reconstituir(
            id: $fila->id,
            estanqueId: $fila->estanque_id,
            parametro: Parametro::from($fila->parametro),
            severidad: Severidad::from($fila->severidad),
            estado: EstadoAlerta::from($fila->estado),
            valorDetectado: $fila->valor_detectado,
            umbralViolado: $fila->umbral_violado,
            ultimoValor: $fila->ultimo_valor,
            generadaEn: new DateTimeImmutable($fila->generada_en->format('c')),
            actualizadaEn: new DateTimeImmutable($fila->actualizada_en->format('c')),
            cerradaPor: $fila->cerrada_por,
        );
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Sippt\Application\Puertos\RepositorioLectura;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Lectura;

final class RepositorioLecturaEloquent implements RepositorioLectura
{
    /**
     * CP-04 / RNF-02: la deduplicación se delega a la clave primaria
     * (sensor_id, medido_en) mediante ON CONFLICT DO NOTHING.
     *
     * Comprobar antes con un SELECT sería una condición de carrera: dos lotes
     * del búfer reenviados a la vez podrían pasar ambos la comprobación. Aquí
     * la base decide, y el número de filas afectadas dice si la lectura era
     * nueva.
     */
    public function guardarSiNoExiste(Lectura $lectura): bool
    {
        $insertadas = DB::affectingStatement(
            'INSERT INTO lectura (sensor_id, medido_en, valor, calidad, recibido_en)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (sensor_id, medido_en) DO NOTHING',
            [
                $lectura->sensorId,
                $lectura->medidoEn->format('Y-m-d H:i:sP'),
                $lectura->valor,
                $lectura->calidad->value,
                ($lectura->recibidoEn ?? new DateTimeImmutable)->format('Y-m-d H:i:sP'),
            ],
        );

        return $insertadas === 1;
    }

    public function ultimaValidaDeSensor(int $sensorId): ?Lectura
    {
        $fila = DB::selectOne(
            'SELECT sensor_id, medido_en, valor, calidad, recibido_en
             FROM lectura
             WHERE sensor_id = ? AND calidad = ?
             ORDER BY medido_en DESC
             LIMIT 1',
            [$sensorId, CalidadLectura::VALIDA->value],
        );

        if ($fila === null) {
            return null;
        }

        return new Lectura(
            sensorId: (int) $fila->sensor_id,
            medidoEn: new DateTimeImmutable($fila->medido_en),
            valor: (float) $fila->valor,
            calidad: CalidadLectura::from($fila->calidad),
            recibidoEn: new DateTimeImmutable($fila->recibido_en),
        );
    }
}

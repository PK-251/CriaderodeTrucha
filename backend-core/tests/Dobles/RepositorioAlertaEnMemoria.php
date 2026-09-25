<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\RepositorioAlerta;
use Sippt\Domain\Alerta;
use Sippt\Domain\Parametro;

/**
 * Doble en memoria del repositorio de alertas.
 *
 * Reproduce la restricción que la base de datos impone con el índice parcial
 * idx_alerta_abierta_unica: a lo sumo una alerta abierta por estanque y
 * parámetro. Si el doble fuera más permisivo que la base, CP-08 pasaría en las
 * pruebas y fallaría en producción.
 */
final class RepositorioAlertaEnMemoria implements RepositorioAlerta
{
    /** @var array<int, Alerta> */
    private array $alertas = [];

    private int $siguienteId = 1;

    public function buscarAbierta(int $estanqueId, Parametro $parametro): ?Alerta
    {
        foreach ($this->alertas as $alerta) {
            if ($alerta->estanqueId === $estanqueId
                && $alerta->parametro === $parametro
                && $alerta->estaAbierta()
            ) {
                return $alerta;
            }
        }

        return null;
    }

    public function buscarPorId(int $id): ?Alerta
    {
        return $this->alertas[$id] ?? null;
    }

    public function guardar(Alerta $alerta): Alerta
    {
        if ($alerta->id !== null) {
            $this->alertas[$alerta->id] = $alerta;

            return $alerta;
        }

        $id = $this->siguienteId++;

        $persistida = Alerta::reconstituir(
            id: $id,
            estanqueId: $alerta->estanqueId,
            parametro: $alerta->parametro,
            severidad: $alerta->severidad(),
            estado: $alerta->estado(),
            valorDetectado: $alerta->valorDetectado,
            umbralViolado: $alerta->umbralViolado,
            ultimoValor: $alerta->ultimoValor(),
            generadaEn: $alerta->generadaEn,
            actualizadaEn: $alerta->actualizadaEn(),
            cerradaPor: $alerta->cerradaPor(),
        );

        $this->alertas[$id] = $persistida;

        return $persistida;
    }

    public function total(): int
    {
        return count($this->alertas);
    }

    /**
     * @return list<Alerta>
     */
    public function todas(): array
    {
        return array_values($this->alertas);
    }
}

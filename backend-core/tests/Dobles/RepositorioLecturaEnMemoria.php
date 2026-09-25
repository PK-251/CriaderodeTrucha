<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\RepositorioLectura;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Lectura;

/**
 * Doble del repositorio de lecturas.
 *
 * La clave del almacén es (sensor_id, medido_en), igual que la clave primaria
 * real. Esa equivalencia es lo que hace significativa la prueba CP-04: el
 * rechazo del duplicado ocurre por la misma razón que ocurriría en la base.
 */
final class RepositorioLecturaEnMemoria implements RepositorioLectura
{
    /** @var array<string, Lectura> */
    private array $lecturas = [];

    private int $duplicadasAbsorbidas = 0;

    public function guardarSiNoExiste(Lectura $lectura): bool
    {
        $clave = $lectura->clave();

        if (isset($this->lecturas[$clave])) {
            $this->duplicadasAbsorbidas++;

            return false;
        }

        $this->lecturas[$clave] = $lectura;

        return true;
    }

    public function ultimaValidaDeSensor(int $sensorId): ?Lectura
    {
        $ultima = null;

        foreach ($this->lecturas as $lectura) {
            if ($lectura->sensorId !== $sensorId || $lectura->calidad !== CalidadLectura::VALIDA) {
                continue;
            }

            if ($ultima === null || $lectura->medidoEn > $ultima->medidoEn) {
                $ultima = $lectura;
            }
        }

        return $ultima;
    }

    public function total(): int
    {
        return count($this->lecturas);
    }

    public function duplicadasAbsorbidas(): int
    {
        return $this->duplicadasAbsorbidas;
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\RepositorioSensor;
use Sippt\Domain\Sensor;

final class RepositorioSensorEnMemoria implements RepositorioSensor
{
    /** @var array<int, Sensor> */
    private array $sensores = [];

    public function agregar(Sensor $sensor): void
    {
        $this->sensores[$sensor->id] = $sensor;
    }

    public function buscarPorCodigoNodo(string $codigoNodo): ?Sensor
    {
        foreach ($this->sensores as $sensor) {
            if ($sensor->codigoNodo === $codigoNodo) {
                return $sensor;
            }
        }

        return null;
    }

    public function buscarPorId(int $id): ?Sensor
    {
        return $this->sensores[$id] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\RepositorioAtencion;
use Sippt\Domain\AtencionAlerta;

final class RepositorioAtencionEnMemoria implements RepositorioAtencion
{
    /** @var array<int, AtencionAlerta> */
    private array $atenciones = [];

    private int $siguienteId = 1;

    public function buscarPorAlerta(int $alertaId): ?AtencionAlerta
    {
        return $this->atenciones[$alertaId] ?? null;
    }

    public function guardar(AtencionAlerta $atencion): AtencionAlerta
    {
        $persistida = new AtencionAlerta(
            alertaId: $atencion->alertaId,
            usuarioId: $atencion->usuarioId,
            accion: $atencion->accion,
            observacion: $atencion->observacion,
            registradaEn: $atencion->registradaEn,
            id: $this->siguienteId++,
        );

        $this->atenciones[$atencion->alertaId] = $persistida;

        return $persistida;
    }

    public function total(): int
    {
        return count($this->atenciones);
    }
}

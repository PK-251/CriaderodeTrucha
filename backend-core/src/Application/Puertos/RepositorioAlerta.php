<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\Alerta;
use Sippt\Domain\Parametro;

interface RepositorioAlerta
{
    /**
     * Alerta abierta del estanque para ese parámetro, si existe.
     *
     * Es la consulta sobre la que descansa CP-08: mientras devuelva una alerta,
     * el caso de uso actualiza en lugar de abrir una nueva.
     */
    public function buscarAbierta(int $estanqueId, Parametro $parametro): ?Alerta;

    public function buscarPorId(int $id): ?Alerta;

    public function guardar(Alerta $alerta): Alerta;
}

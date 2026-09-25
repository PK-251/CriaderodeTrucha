<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\AtencionAlerta;

interface RepositorioAtencion
{
    public function buscarPorAlerta(int $alertaId): ?AtencionAlerta;

    public function guardar(AtencionAlerta $atencion): AtencionAlerta;
}

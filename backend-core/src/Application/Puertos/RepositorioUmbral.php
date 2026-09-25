<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\Parametro;
use Sippt\Domain\Umbral;

interface RepositorioUmbral
{
    public function buscarPor(int $estanqueId, Parametro $parametro): ?Umbral;
}

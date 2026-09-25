<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\RepositorioUmbral;
use Sippt\Domain\Parametro;
use Sippt\Domain\Umbral;

final class RepositorioUmbralEnMemoria implements RepositorioUmbral
{
    /** @var array<string, Umbral> */
    private array $umbrales = [];

    public function agregar(Umbral $umbral): void
    {
        $this->umbrales[$umbral->estanqueId.':'.$umbral->parametro->value] = $umbral;
    }

    public function buscarPor(int $estanqueId, Parametro $parametro): ?Umbral
    {
        return $this->umbrales[$estanqueId.':'.$parametro->value] ?? null;
    }
}

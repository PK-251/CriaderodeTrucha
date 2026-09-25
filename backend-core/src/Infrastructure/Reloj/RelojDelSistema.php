<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Reloj;

use DateTimeImmutable;
use DateTimeZone;
use Sippt\Application\Puertos\Reloj;

/**
 * Implementación real del puerto del tiempo.
 *
 * Fija la zona horaria de la piscigranja de forma explícita en lugar de
 * confiar en la del proceso: la marca temporal de una alerta se compara con la
 * de la medición del nodo, que llega con offset -05:00, y una discrepancia de
 * husos convertiría el cálculo de RNF-01 en un sinsentido.
 */
final class RelojDelSistema implements Reloj
{
    public function __construct(private readonly string $zona = 'America/Lima') {}

    public function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->zona));
    }
}

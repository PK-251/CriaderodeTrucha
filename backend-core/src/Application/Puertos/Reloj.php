<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use DateTimeImmutable;

/**
 * Puerto del tiempo.
 *
 * Que el núcleo no llame a now() directamente es lo que permite comprobar en
 * pruebas el cumplimiento de RNF-01 (aviso en menos de 5 minutos) sin esperar
 * cinco minutos reales, y reproducir el orden exacto de tres lecturas
 * consecutivas en CP-08.
 */
interface Reloj
{
    public function ahora(): DateTimeImmutable;
}

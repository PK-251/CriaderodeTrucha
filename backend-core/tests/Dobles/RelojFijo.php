<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use DateTimeImmutable;
use Sippt\Application\Puertos\Reloj;

/**
 * Reloj controlado por la prueba. Permite reproducir el orden exacto de varias
 * lecturas consecutivas (CP-08) y medir tiempos sin esperarlos.
 */
final class RelojFijo implements Reloj
{
    public function __construct(private DateTimeImmutable $momento) {}

    public static function en(string $iso8601): self
    {
        return new self(new DateTimeImmutable($iso8601));
    }

    public function ahora(): DateTimeImmutable
    {
        return $this->momento;
    }

    /**
     * Desde PHP 8.3, modify() lanza DateMalformedStringException ante un
     * intervalo inválido en lugar de devolver false, de modo que no hay valor
     * de error que comprobar: la excepción se propaga y la prueba falla con el
     * mensaje correcto.
     */
    public function avanzar(string $intervalo): void
    {
        $this->momento = $this->momento->modify($intervalo);
    }
}

<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;

/**
 * Espía del puerto de notificación: registra cada aviso emitido.
 *
 * Contar notificaciones importa tanto como contar alertas. CP-08 exige que tres
 * lecturas consecutivas fuera de rango no generen tres alertas; si además
 * produjeran tres avisos al operador, el efecto práctico sería el mismo problema
 * que la prueba pretende evitar.
 */
final class NotificadorEspia implements Notificador
{
    /** @var list<Alerta> */
    private array $enviadas = [];

    public function notificar(Alerta $alerta): void
    {
        $this->enviadas[] = $alerta;
    }

    public function total(): int
    {
        return count($this->enviadas);
    }

    /** @return list<Alerta> */
    public function enviadas(): array
    {
        return $this->enviadas;
    }
}

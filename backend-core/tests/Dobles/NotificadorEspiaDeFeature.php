<?php

declare(strict_types=1);

namespace Sippt\Tests\Dobles;

use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;

/**
 * Espía del puerto de notificación para las pruebas de feature.
 *
 * Sustituye al notificador real, que publicaría en el broker y enviaría correo.
 * Lo que estas pruebas verifican es el contrato HTTP y cuántos avisos se
 * emiten, no la entrega por cada canal.
 */
final class NotificadorEspiaDeFeature implements Notificador
{
    /** @var list<Alerta> */
    private array $avisos = [];

    public function notificar(Alerta $alerta): void
    {
        $this->avisos[] = $alerta;
    }

    public function total(): int
    {
        return count($this->avisos);
    }
}

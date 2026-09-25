<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Notificacion;

use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;

/**
 * Reparte el aviso entre todos los canales configurados.
 *
 * El caso de uso conoce un solo puerto Notificador; que detrás haya un tablero
 * y un correo —o mañana un mensaje al celular del operador— es una decisión de
 * infraestructura que no debe filtrarse al dominio.
 */
final class NotificadorCompuesto implements Notificador
{
    /** @var list<Notificador> */
    private array $canales;

    public function __construct(Notificador ...$canales)
    {
        $this->canales = array_values($canales);
    }

    public function notificar(Alerta $alerta): void
    {
        foreach ($this->canales as $canal) {
            $canal->notificar($alerta);
        }
    }
}

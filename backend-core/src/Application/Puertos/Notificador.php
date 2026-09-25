<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\Alerta;

/**
 * Puerto de salida hacia los canales de aviso.
 *
 * La Fase 3 lo implementa dos veces: WebSocket para que la alerta aparezca en
 * el tablero sin recargar, y SMTP para las de severidad crítica (HU-04). El
 * núcleo no sabe cuál de los dos se usa; solo que hay que avisar.
 */
interface Notificador
{
    public function notificar(Alerta $alerta): void;
}

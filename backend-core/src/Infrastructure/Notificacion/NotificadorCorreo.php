<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Notificacion;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Psr\Log\LoggerInterface;
use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;
use Throwable;

/**
 * Envía por correo únicamente las alertas de severidad crítica (HU-04).
 *
 * Una advertencia se queda en el tablero: si cada desviación menor generara un
 * correo, el técnico dejaría de leerlos y la única alerta que importa pasaría
 * inadvertida entre las demás.
 */
final class NotificadorCorreo implements Notificador
{
    public function __construct(
        private readonly Mailer $correo,
        private readonly LoggerInterface $registro,
        private readonly string $destinatario,
    ) {}

    public function notificar(Alerta $alerta): void
    {
        if (! $alerta->severidad()->exigeNotificacionExterna()) {
            return;
        }

        $parametro = $alerta->parametro;
        $unidad = $parametro->unidad() === '' ? '' : ' '.$parametro->unidad();

        $asunto = sprintf(
            '[SIPPT] Alerta critica: %s en estanque %d',
            $parametro->etiqueta(),
            $alerta->estanqueId,
        );

        $cuerpo = sprintf(
            "Se detecto una condicion critica en la piscigranja.\n\n".
            "Estanque:   %d\n".
            "Parametro:  %s\n".
            "Valor:      %.3f%s\n".
            "Umbral:     %.2f%s\n".
            "Detectada:  %s\n\n".
            "Revise el tablero para registrar la intervencion.\n",
            $alerta->estanqueId,
            $parametro->etiqueta(),
            $alerta->valorDetectado,
            $unidad,
            $alerta->umbralViolado,
            $unidad,
            $alerta->generadaEn->format('Y-m-d H:i:s'),
        );

        try {
            $this->correo->raw($cuerpo, function (Message $mensaje) use ($asunto): void {
                $mensaje->to($this->destinatario)->subject($asunto);
            });
        } catch (Throwable $e) {
            // Igual que el aviso al tablero: la alerta ya esta persistida, de
            // modo que un fallo de SMTP no puede interrumpir la ingesta.
            $this->registro->warning('Correo de alerta critica no enviado: '.$e->getMessage(), [
                'alerta_id' => $alerta->id,
            ]);
        }
    }
}

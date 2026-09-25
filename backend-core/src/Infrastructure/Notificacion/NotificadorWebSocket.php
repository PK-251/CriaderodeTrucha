<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Notificacion;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Sippt\Application\Puertos\Notificador;
use Sippt\Domain\Alerta;
use Throwable;

/**
 * Empuja la alerta al tablero para que aparezca sin recargar (RF-07).
 *
 * Se apoya en el broker Mosquitto que ya existe para la ingesta, publicando en
 * el tópico piscigranja/alertas. El navegador se suscribe al mismo tópico por
 * el listener WebSocket del broker (puerto 9001).
 *
 * La alternativa habría sido añadir un servidor WebSocket propio —un sexto
 * contenedor— para transportar un único tipo de mensaje que el broker ya sabe
 * entregar. Con esta decisión el tablero recibe la alerta en vivo sin sumar
 * infraestructura, y el mismo mensaje sirve a cualquier otro suscriptor futuro.
 *
 * Un fallo al notificar NO propaga la excepción: la alerta ya está persistida y
 * visible en el siguiente refresco del tablero. Tumbar la ingesta porque el
 * broker no responde convertiría un problema de aviso en una pérdida de datos.
 */
final class NotificadorWebSocket implements Notificador
{
    public function __construct(
        private readonly LoggerInterface $registro,
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $topico,
    ) {}

    public function notificar(Alerta $alerta): void
    {
        $carga = json_encode([
            'tipo' => 'alerta',
            'id' => $alerta->id,
            'estanque_id' => $alerta->estanqueId,
            'parametro' => $alerta->parametro->value,
            'parametro_etiqueta' => $alerta->parametro->etiqueta(),
            'unidad' => $alerta->parametro->unidad(),
            'severidad' => $alerta->severidad()->value,
            'estado' => $alerta->estado()->value,
            'valor_detectado' => $alerta->valorDetectado,
            'umbral_violado' => $alerta->umbralViolado,
            'ultimo_valor' => $alerta->ultimoValor(),
            'generada_en' => $alerta->generadaEn->format('c'),
        ], JSON_UNESCAPED_UNICODE);

        if ($carga === false) {
            $this->registro->warning('No se pudo serializar la alerta para el tablero.');

            return;
        }

        try {
            $cliente = new MqttClient($this->host, $this->puerto, 'sippt-api-'.getmypid());
            $ajustes = (new ConnectionSettings)
                ->setConnectTimeout(3)
                ->setSocketTimeout(3);

            $cliente->connect($ajustes, true);

            // Sin retención (último argumento false). Un mensaje retenido se
            // entrega a cualquier suscriptor nuevo, de modo que el tablero
            // mostraría como recién llegada la última alerta emitida —incluso
            // una ya atendida— cada vez que el navegador se reconecta. El
            // estado vigente se obtiene de GET /api/v1/alertas al cargar; este
            // canal transporta solo lo que ocurre desde entonces.
            $cliente->publish($this->topico, $carga, 1, false);
            $cliente->disconnect();
        } catch (Throwable $e) {
            $this->registro->warning('Aviso al tablero no entregado: '.$e->getMessage(), [
                'alerta_id' => $alerta->id,
            ]);
        }
    }
}

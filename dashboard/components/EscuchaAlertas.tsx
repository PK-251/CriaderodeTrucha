'use client';

import mqtt from 'mqtt';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

/**
 * Suscripción en vivo a las alertas (RF-07).
 *
 * El núcleo publica cada alerta nueva en piscigranja/alertas, y el navegador
 * se suscribe al mismo tópico por el listener WebSocket del broker. Cuando
 * llega una, se refresca el tablero desde el servidor en lugar de insertar la
 * alerta en el cliente: así la pantalla siempre muestra el estado que la base
 * confirma, y no una versión optimista que podría no coincidir.
 *
 * El aviso en vivo NO sustituye a la carga inicial. Al abrir el tablero, el
 * estado vigente viene del servidor; este canal solo transporta lo que ocurre
 * a partir de ese momento.
 */

export function EscuchaAlertas() {
  const router = useRouter();
  const [conectado, setConectado] = useState(false);

  useEffect(() => {
    const url = process.env.NEXT_PUBLIC_WS_URL ?? 'ws://localhost:9001';
    const topico = process.env.NEXT_PUBLIC_TOPICO_ALERTAS ?? 'piscigranja/alertas';

    const cliente = mqtt.connect(url, {
      clientId: `tablero-${Math.random().toString(16).slice(2, 10)}`,
      reconnectPeriod: 5000,
      connectTimeout: 5000,
    });

    cliente.on('connect', () => {
      setConectado(true);
      cliente.subscribe(topico, { qos: 1 });
    });

    cliente.on('message', () => {
      router.refresh();
    });

    cliente.on('error', () => setConectado(false));
    cliente.on('close', () => setConectado(false));

    return () => {
      cliente.end(true);
    };
  }, [router]);

  return (
    <span className="cabecera__sub" data-testid="estado-vivo">
      {conectado ? 'En vivo' : 'Sin conexión en vivo'}
    </span>
  );
}

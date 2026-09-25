"""Suscriptor MQTT: recibe las lecturas de los nodos (HU-02).

Se suscribe a piscigranja/+/+ y encola cada lectura interpretable en el búfer.
No llama al núcleo: esa es tarea del despachador. La separación es lo que
permite seguir recibiendo mientras el enlace está caído (RNF-02).
"""

from __future__ import annotations

import logging
from collections.abc import Callable

import paho.mqtt.client as mqtt

from .bufer import BuferLecturas
from .modelos import CargaInvalida, LecturaCruda
from .validador import parsear_carga

registro = logging.getLogger(__name__)


class SuscriptorLecturas:
    """Puente entre el broker y el búfer."""

    def __init__(
        self,
        bufer: BuferLecturas,
        host: str,
        puerto: int,
        topico: str,
        client_id: str = "sippt-ingesta",
        qos: int = 1,
        topic_base: str = "piscigranja",
        al_encolar: Callable[[LecturaCruda], None] | None = None,
    ) -> None:
        self._bufer = bufer
        self._host = host
        self._puerto = puerto
        self._topico = topico
        self._qos = qos
        self._topic_base = topic_base
        self._al_encolar = al_encolar

        self.recibidos = 0
        self.encolados = 0
        self.duplicados = 0
        self.rechazados = 0

        self._cliente = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION2,
            client_id=client_id,
            # Sesión persistente: si el servicio se reinicia, el broker
            # entrega los mensajes publicados durante la ausencia en lugar de
            # descartarlos.
            clean_session=False,
        )
        self._cliente.on_connect = self._al_conectar
        self._cliente.on_message = self._al_recibir
        self._cliente.on_disconnect = self._al_desconectar

    # ── Ciclo de vida ────────────────────────────────────────────────────────

    def iniciar(self) -> None:
        """Conecta y arranca el bucle de red en su propio hilo."""
        self._cliente.connect_async(self._host, self._puerto, keepalive=60)
        # reconnect con espera creciente: un corte de enlace no debe producir
        # una tormenta de intentos contra un broker que no está.
        self._cliente.reconnect_delay_set(min_delay=1, max_delay=60)
        self._cliente.loop_start()

    def detener(self) -> None:
        self._cliente.loop_stop()
        self._cliente.disconnect()

    # ── Retrollamadas ────────────────────────────────────────────────────────

    def _al_conectar(self, cliente: mqtt.Client, _datos: object, _banderas: object, razon: object, _propiedades: object = None) -> None:  # noqa: E501
        registro.info("Conectado al broker (%s). Suscribiendo a %s", razon, self._topico)
        cliente.subscribe(self._topico, qos=self._qos)

    def _al_desconectar(self, _cliente: mqtt.Client, _datos: object, _banderas: object, razon: object, _propiedades: object = None) -> None:  # noqa: E501
        registro.warning("Desconectado del broker (%s). Las lecturas siguen en el bufer.", razon)

    def _al_recibir(self, _cliente: mqtt.Client, _datos: object, mensaje: mqtt.MQTTMessage) -> None:
        self.recibidos += 1
        self.procesar(mensaje.topic, mensaje.payload)

    # ── Procesamiento ────────────────────────────────────────────────────────

    def procesar(self, topico: str, carga: bytes | str) -> LecturaCruda | None:
        """Interpreta y encola un mensaje.

        Expuesto como método público para que las pruebas puedan ejercitar la
        cadena completa sin levantar un broker.
        """
        try:
            lectura = parsear_carga(topico, carga, self._topic_base)
        except CargaInvalida as error:
            self.rechazados += 1
            registro.warning(
                "Mensaje descartado en %s: %s",
                error.topico or topico,
                error.motivo,
            )

            return None

        if self._bufer.agregar(lectura):
            self.encolados += 1
        else:
            # CP-04: el nodo o el broker reenviaron una lectura ya encolada.
            # No es un error y no debe contarse como pérdida.
            self.duplicados += 1

        if self._al_encolar is not None:
            self._al_encolar(lectura)

        return lectura

    def metricas(self) -> dict[str, int]:
        return {
            "recibidos": self.recibidos,
            "encolados": self.encolados,
            "duplicados": self.duplicados,
            "rechazados": self.rechazados,
        }

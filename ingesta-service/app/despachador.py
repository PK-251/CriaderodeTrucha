"""Vacía el búfer hacia el núcleo.

Separado del suscriptor a propósito: recibir del broker y entregar al núcleo son
dos ritmos distintos. El nodo publica cada cinco minutos pase lo que pase,
mientras que la entrega depende de que el enlace esté disponible. Con el búfer
en medio, una caída del enlace no detiene la recepción.
"""

from __future__ import annotations

import logging

from .bufer import BuferLecturas
from .cliente_core import ClienteCore, ResultadoEnvio

registro = logging.getLogger(__name__)


class Despachador:
    """Toma lecturas del búfer y las entrega al núcleo."""

    def __init__(
        self,
        bufer: BuferLecturas,
        cliente: ClienteCore,
        tamano_lote: int = 50,
        umbral_atascadas: int = 5,
    ) -> None:
        self._bufer = bufer
        self._cliente = cliente
        self._tamano_lote = tamano_lote
        self._umbral_atascadas = umbral_atascadas

        self.total_entregadas = 0
        self.total_descartadas = 0
        self.total_duplicadas = 0
        self.total_alertas = 0

    def despachar_una_vez(self) -> ResultadoEnvio:
        """Envía un lote. Devuelve el resultado para que el llamador decida."""
        lote = self._bufer.tomar(self._tamano_lote)

        if not lote:
            return ResultadoEnvio(entregado=True)

        resultado = self._cliente.enviar_lote(lote)

        if resultado.entregado:
            self._bufer.confirmar(lote)
            self.total_entregadas += resultado.aceptadas
            self.total_descartadas += resultado.descartadas
            self.total_duplicadas += resultado.duplicadas
            self.total_alertas += len(resultado.alertas)

            if resultado.alertas:
                for alerta in resultado.alertas:
                    registro.info(
                        "Alerta generada: estanque=%s parametro=%s severidad=%s",
                        alerta.get("estanque_id"),
                        alerta.get("parametro"),
                        alerta.get("severidad"),
                    )

            return resultado

        if resultado.definitivo:
            # El núcleo no va a aceptar este lote nunca: se retira para no
            # bloquear indefinidamente las lecturas que vienen detrás.
            self._bufer.descartar(lote)
            registro.error("Lote de %d lecturas descartado: %s", len(lote), resultado.motivo)

            return resultado

        # Fallo transitorio: las lecturas siguen en la cola.
        self._bufer.registrar_intento(lote)

        atascadas = self._bufer.atascadas(self._umbral_atascadas)
        if atascadas:
            registro.warning(
                "%d lecturas acumulan %d intentos o mas: revisar el enlace o el nodo",
                atascadas,
                self._umbral_atascadas,
            )

        return resultado

    def vaciar(self, max_lotes: int = 1000) -> int:
        """Despacha lotes hasta agotar el búfer o hasta un fallo transitorio.

        Es lo que ocurre al restablecerse el enlace: el búfer puede contener
        cientos de lecturas acumuladas durante el corte y hay que entregarlas
        todas, no solo el primer lote (CP-04).

        El tope de lotes evita que un error de confirmación convierta esto en
        un bucle infinito.
        """
        entregadas = 0

        for _ in range(max_lotes):
            if self._bufer.pendientes() == 0:
                break

            resultado = self.despachar_una_vez()

            if not resultado.puede_confirmarse:
                break

            entregadas += resultado.aceptadas + resultado.descartadas + resultado.duplicadas

        return entregadas

    def metricas(self) -> dict[str, int]:
        return {
            "pendientes": self._bufer.pendientes(),
            "atascadas": self._bufer.atascadas(self._umbral_atascadas),
            "entregadas": self.total_entregadas,
            "descartadas": self.total_descartadas,
            "duplicadas": self.total_duplicadas,
            "alertas": self.total_alertas,
        }

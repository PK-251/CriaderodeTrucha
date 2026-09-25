"""Cliente del núcleo: envío por lote a POST /api/v1/lecturas.

Traduce las respuestas del contrato en decisiones sobre el búfer:

- **201 / 207** — el núcleo aceptó el lote (207 significa que alguna lectura
  quedó marcada como descartada por calidad, lo cual es un resultado y no un
  fallo). Las lecturas salen de la cola.
- **422** — el lote contiene algo que el emisor debe corregir, típicamente un
  nodo que no existe. Reintentarlo indefinidamente bloquearía la cola, así que
  se retira y se registra.
- **401 / 403** — problema de credenciales. Se conserva la cola: cuando el
  token se renueve, las lecturas siguen ahí.
- **5xx o red caída** — el núcleo no está disponible. Se conserva la cola y se
  reintenta más tarde; es el caso para el que existe el búfer.
"""

from __future__ import annotations

import logging
from collections.abc import Sequence
from dataclasses import dataclass, field

import httpx

from .modelos import LecturaCruda

registro = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class ResultadoEnvio:
    """Qué hizo el núcleo con el lote."""

    entregado: bool
    aceptadas: int = 0
    descartadas: int = 0
    duplicadas: int = 0
    alertas: list[dict[str, object]] = field(default_factory=list)
    definitivo: bool = False
    motivo: str = ""

    @property
    def puede_confirmarse(self) -> bool:
        """Si las lecturas pueden salir del búfer.

        Incluye el rechazo definitivo: una lectura que el núcleo nunca va a
        aceptar no debe quedarse ocupando la cola para siempre.
        """
        return self.entregado or self.definitivo


class ClienteCore:
    """Envía lotes de lecturas al núcleo."""

    def __init__(
        self,
        url_base: str,
        token: str,
        timeout_s: float = 10.0,
        cliente: httpx.Client | None = None,
    ) -> None:
        self._url_base = url_base.rstrip("/")
        self._token = token
        self._timeout = timeout_s
        self._cliente = cliente or httpx.Client(timeout=timeout_s)

    def _cabeceras(self) -> dict[str, str]:
        return {
            "Authorization": f"Bearer {self._token}",
            "Content-Type": "application/json",
            # Sin Accept, una respuesta de error llegaria como HTML y el
            # servicio no podria distinguir un 401 de una caida del nucleo.
            "Accept": "application/json",
        }

    def enviar_lote(self, lecturas: Sequence[LecturaCruda]) -> ResultadoEnvio:
        """Entrega el lote y traduce la respuesta."""
        if not lecturas:
            return ResultadoEnvio(entregado=True)

        carga = {"lecturas": [lectura.a_carga_util() for lectura in lecturas]}

        try:
            respuesta = self._cliente.post(
                f"{self._url_base}/lecturas",
                json=carga,
                headers=self._cabeceras(),
            )
        except httpx.HTTPError as error:
            registro.warning("El nucleo no responde: %s", error)

            return ResultadoEnvio(entregado=False, motivo=f"red: {error}")

        return self._interpretar(respuesta, len(lecturas))

    def _interpretar(self, respuesta: httpx.Response, enviadas: int) -> ResultadoEnvio:
        if respuesta.status_code in (200, 201, 207):
            cuerpo = self._cuerpo(respuesta)

            return ResultadoEnvio(
                entregado=True,
                aceptadas=int(cuerpo.get("aceptadas", 0)),
                descartadas=int(cuerpo.get("descartadas", 0)),
                duplicadas=int(cuerpo.get("duplicadas", 0)),
                alertas=list(cuerpo.get("alertas", [])),
            )

        if respuesta.status_code == 422:
            cuerpo = self._cuerpo(respuesta)
            registro.error(
                "El nucleo rechazo el lote de %d lecturas de forma definitiva: %s",
                enviadas,
                cuerpo.get("detalle"),
            )

            return ResultadoEnvio(
                entregado=False,
                definitivo=True,
                motivo=f"422: {cuerpo.get('detalle')}",
            )

        if respuesta.status_code in (401, 403):
            registro.error(
                "Credenciales rechazadas por el nucleo (%d). El lote se conserva.",
                respuesta.status_code,
            )

            return ResultadoEnvio(entregado=False, motivo=f"auth: {respuesta.status_code}")

        registro.warning("Respuesta inesperada del nucleo: %d", respuesta.status_code)

        return ResultadoEnvio(entregado=False, motivo=f"http: {respuesta.status_code}")

    @staticmethod
    def _cuerpo(respuesta: httpx.Response) -> dict[str, object]:
        try:
            cuerpo = respuesta.json()
        except ValueError:
            return {}

        return cuerpo if isinstance(cuerpo, dict) else {}

    def cerrar(self) -> None:
        self._cliente.close()

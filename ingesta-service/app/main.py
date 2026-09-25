"""Servicio de ingesta IoT del PMV (HU-02).

Arranca el suscriptor MQTT y el despachador periódico, y expone el estado del
servicio por HTTP para el healthcheck del contenedor y para el monitoreo.
"""

from __future__ import annotations

import asyncio
import contextlib
import logging
from collections.abc import AsyncIterator

from fastapi import FastAPI

from .bufer import BuferLecturas
from .cliente_core import ClienteCore
from .config import ajustes
from .despachador import Despachador
from .subscriber import SuscriptorLecturas

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
)
registro = logging.getLogger("sippt.ingesta")

_estado: dict[str, object] = {}


async def _bucle_despacho(despachador: Despachador, intervalo_s: float) -> None:
    """Vacía el búfer a intervalos regulares.

    El intervalo importa para RNF-01: una lectura en riesgo no puede esperar a
    que el lote se llene, porque el aviso al operador debe producirse dentro de
    los cinco minutos siguientes a la medición.
    """
    while True:
        try:
            await asyncio.to_thread(despachador.vaciar)
        except Exception:
            # El bucle no puede morir: si una excepcion lo detuviera, el
            # bufer creceria indefinidamente sin que nadie lo advierta.
            registro.exception("Fallo al despachar el bufer")

        await asyncio.sleep(intervalo_s)


@contextlib.asynccontextmanager
async def ciclo_de_vida(_app: FastAPI) -> AsyncIterator[None]:
    cfg = ajustes()

    bufer = BuferLecturas(cfg.ingesta_buffer_path)
    cliente = ClienteCore(cfg.core_api_url, cfg.core_api_token, cfg.core_timeout_s)
    despachador = Despachador(bufer, cliente, cfg.ingesta_batch_size, cfg.ingesta_max_reintentos)
    suscriptor = SuscriptorLecturas(
        bufer=bufer,
        host=cfg.mqtt_host,
        puerto=cfg.mqtt_port,
        topico=cfg.topico_suscripcion,
        client_id=cfg.mqtt_client_id,
        qos=cfg.mqtt_qos,
        topic_base=cfg.mqtt_topic_base,
    )

    _estado.update(bufer=bufer, cliente=cliente, despachador=despachador, suscriptor=suscriptor)

    suscriptor.iniciar()
    tarea = asyncio.create_task(_bucle_despacho(despachador, cfg.ingesta_batch_interval_s))

    registro.info(
        "Ingesta activa. Broker %s:%s topico %s. Bufer con %d pendientes.",
        cfg.mqtt_host,
        cfg.mqtt_port,
        cfg.topico_suscripcion,
        bufer.pendientes(),
    )

    try:
        yield
    finally:
        tarea.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await tarea

        suscriptor.detener()
        cliente.cerrar()
        # El búfer NO se vacía al cerrar: lo pendiente debe sobrevivir al
        # reinicio, que es justamente su razón de ser (RNF-02).
        bufer.cerrar()
        registro.info("Ingesta detenida.")


app = FastAPI(
    title="SIPPT · Servicio de ingesta IoT",
    version="0.1.0-pmv",
    lifespan=ciclo_de_vida,
)


@app.get("/health")
async def health() -> dict[str, object]:
    """Estado del servicio, consultado por el healthcheck del contenedor."""
    return {
        "servicio": "ingesta-service",
        "estado": "ok",
        "version": "v0.1.0-pmv",
    }


@app.get("/metricas")
async def metricas() -> dict[str, object]:
    """Contadores de recepción y entrega.

    `pendientes` es el indicador que revela un corte de enlace: si crece de
    forma sostenida, las lecturas se están acumulando porque el núcleo no
    responde.
    """
    suscriptor = _estado.get("suscriptor")
    despachador = _estado.get("despachador")

    if not isinstance(suscriptor, SuscriptorLecturas) or not isinstance(despachador, Despachador):
        return {"estado": "iniciando"}

    return {
        "recepcion": suscriptor.metricas(),
        "entrega": despachador.metricas(),
    }

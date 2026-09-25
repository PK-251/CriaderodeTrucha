"""Punto de entrada provisional del servicio de ingesta (Fase 0).

Solo expone /health para el healthcheck de Docker Compose. El suscriptor MQTT
(subscriber.py), el validador de calidad del dato (validador.py) y el cliente
por lote hacia el núcleo (cliente_core.py) se implementan en la Fase 4.
"""

from fastapi import FastAPI

app = FastAPI(
    title="SIPPT · Servicio de ingesta IoT",
    version="0.1.0-pmv",
)


@app.get("/health")
async def health() -> dict[str, str]:
    """Estado del servicio, consultado por el healthcheck del contenedor."""
    return {
        "servicio": "ingesta-service",
        "estado": "ok",
        "fase": "andamiaje",
        "version": "v0.1.0-pmv",
    }

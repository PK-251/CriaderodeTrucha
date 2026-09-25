"""Configuración del servicio de ingesta.

Todo lo que varía entre el equipo de desarrollo y el gateway instalado en la
piscigranja se lee del entorno. Los valores por defecto corresponden al entorno
de Docker Compose, de modo que el servicio arranca sin configurar nada.
"""

from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Ajustes(BaseSettings):
    """Parámetros de operación del servicio."""

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # ── Broker ───────────────────────────────────────────────────────────────
    mqtt_host: str = "mosquitto"
    mqtt_port: int = 1883
    mqtt_client_id: str = "sippt-ingesta"
    mqtt_topic_base: str = "piscigranja"
    mqtt_qos: int = 1

    # ── Núcleo ───────────────────────────────────────────────────────────────
    core_api_url: str = "http://api-core:8000/api/v1"
    core_api_token: str = ""
    core_timeout_s: float = 10.0

    # ── Envío por lote ───────────────────────────────────────────────────────
    # El lote acumula lecturas antes de llamar al núcleo. Con 12 sensores
    # publicando cada 5 minutos, 50 lecturas equivalen a unos 20 minutos de
    # operación normal; el intervalo garantiza que una alerta no espere a que
    # el lote se llene.
    ingesta_batch_size: int = 50
    ingesta_batch_interval_s: float = 30.0
    ingesta_max_reintentos: int = 5

    # ── Búfer ────────────────────────────────────────────────────────────────
    # RNF-02: las lecturas sobreviven a una caída del enlace y al reinicio del
    # contenedor. Por eso el búfer es un archivo y no una lista en memoria.
    ingesta_buffer_path: str = "/data/buffer.sqlite3"

    @property
    def topico_suscripcion(self) -> str:
        """Comodín que cubre piscigranja/{estanque}/{parametro}."""
        return f"{self.mqtt_topic_base}/+/+"


@lru_cache
def ajustes() -> Ajustes:
    """Instancia única, cacheada para no releer el entorno en cada mensaje."""
    return Ajustes()

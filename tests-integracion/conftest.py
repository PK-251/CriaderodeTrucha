"""Entorno compartido de la suite de integración.

A diferencia de las suites por módulo, esta no usa dobles: habla con el broker,
el núcleo, la base y el tablero reales. Lo que verifica no es el
comportamiento de una pieza sino el de la cadena completa, que es donde
aparecen los defectos que ninguna prueba unitaria puede ver — husos horarios
que no coinciden, contratos que se interpretan distinto a cada lado, tiempos
que solo se miden de extremo a extremo.
"""

from __future__ import annotations

import json
import os
import time
from collections.abc import Iterator
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone

import httpx
import paho.mqtt.client as mqtt
import pytest

LIMA = timezone(timedelta(hours=-5))

# Dentro del contenedor de ingesta los servicios se ven por su nombre; desde el
# anfitrión, por localhost.
API = os.environ.get("SIPPT_API", "http://api-core:8000/api/v1")
TABLERO = os.environ.get("SIPPT_TABLERO", "http://dashboard:3000")
BROKER_HOST = os.environ.get("SIPPT_BROKER_HOST", "mosquitto")
BROKER_PUERTO = int(os.environ.get("SIPPT_BROKER_PUERTO", "1883"))

# El servicio de ingesta despacha el búfer cada 30 s por defecto. La espera
# máxima lo contempla con margen; RNF-01 concede 5 minutos.
ESPERA_MAXIMA_S = 90.0


@dataclass
class Credenciales:
    token: str
    usuario: dict[str, object] = field(default_factory=dict)


def _token(email: str, password: str = "sippt2026") -> Credenciales:
    respuesta = httpx.post(
        f"{API}/auth/login",
        json={"email": email, "password": password},
        headers={"Accept": "application/json"},
        timeout=20,
    )
    respuesta.raise_for_status()
    datos = respuesta.json()["datos"]

    return Credenciales(token=datos["token"], usuario=datos["usuario"])


@pytest.fixture(scope="session")
def operador() -> Credenciales:
    return _token("operador@sippt.local")


@pytest.fixture(scope="session")
def tecnico() -> Credenciales:
    return _token("tecnico@sippt.local")


@pytest.fixture(scope="session")
def veterinario() -> Credenciales:
    return _token("veterinario@sippt.local")


@pytest.fixture
def api() -> Iterator[httpx.Client]:
    with httpx.Client(base_url=API, timeout=30, headers={"Accept": "application/json"}) as cliente:
        yield cliente


def cabeceras(credenciales: Credenciales) -> dict[str, str]:
    return {"Authorization": f"Bearer {credenciales.token}"}


@pytest.fixture
def publicar() -> Iterator[object]:
    """Publica en el broker igual que lo haría un nodo sensor."""
    cliente = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="integracion-sippt")
    cliente.connect(BROKER_HOST, BROKER_PUERTO, keepalive=30)
    cliente.loop_start()

    def enviar(estanque: str, parametro: str, nodo: str, valor: float, medido_en: datetime) -> None:
        carga = json.dumps(
            {"codigo_nodo": nodo, "medido_en": medido_en.isoformat(), "valor": valor}
        )
        info = cliente.publish(f"piscigranja/{estanque}/{parametro}", carga, qos=1)
        info.wait_for_publish(timeout=10)

    yield enviar

    cliente.loop_stop()
    cliente.disconnect()


@pytest.fixture(autouse=True)
def alertas_limpias(tecnico: Credenciales) -> Iterator[None]:
    """Cada prueba empieza sin alertas abiertas de pruebas anteriores.

    La API no expone borrado de alertas —y no debería—, de modo que las que
    quedan abiertas se cierran registrando su atención, que es justamente la
    operación prevista para eso.
    """
    _cerrar_abiertas(tecnico)
    yield
    _cerrar_abiertas(tecnico)


def _cerrar_abiertas(credenciales: Credenciales) -> None:
    with httpx.Client(base_url=API, timeout=30, headers={"Accept": "application/json"}) as cliente:
        respuesta = cliente.get("/alertas?estado=abierta", headers=cabeceras(credenciales))

        if respuesta.status_code != 200:
            return

        for alerta in respuesta.json().get("datos", []):
            cliente.post(
                f"/alertas/{alerta['id']}/atencion",
                json={"accion": "Cierre automatico de la suite de integracion"},
                headers=cabeceras(credenciales),
            )


def esperar_alerta(
    credenciales: Credenciales,
    estanque_codigo: str,
    parametro: str,
    limite_s: float = ESPERA_MAXIMA_S,
) -> tuple[dict[str, object], float]:
    """Espera a que la alerta aparezca y devuelve cuánto tardó.

    El tiempo medido es lo que el informe llama «tiempo desde la medición hasta
    la alerta visible», el indicador de RNF-01.
    """
    inicio = time.monotonic()

    with httpx.Client(base_url=API, timeout=30, headers={"Accept": "application/json"}) as cliente:
        while time.monotonic() - inicio < limite_s:
            respuesta = cliente.get("/alertas?estado=abierta", headers=cabeceras(credenciales))

            if respuesta.status_code == 200:
                for alerta in respuesta.json().get("datos", []):
                    if (
                        alerta["estanque_codigo"] == estanque_codigo
                        and alerta["parametro"] == parametro
                    ):
                        return alerta, time.monotonic() - inicio

            time.sleep(1.0)

    raise AssertionError(
        f"La alerta de {estanque_codigo}/{parametro} no aparecio en {limite_s:.0f} s"
    )


def ahora() -> datetime:
    """Instante actual con precision de segundo.

    El nucleo almacena las marcas temporales con formato 'Y-m-d H:i:sP', de
    modo que trunca los microsegundos. Un nodo real publica a intervalos de
    cinco minutos y nunca alcanza esa resolucion, pero la suite debe generar
    marcas comparables con lo que el sistema devuelve.

    La limitacion importa en un caso concreto: dos lecturas del mismo sensor
    dentro del mismo segundo colisionarian en la clave primaria y la segunda se
    absorberia como duplicada. Con el muestreo del PMV no ocurre.
    """
    return datetime.now(LIMA).replace(microsecond=0)


# ── Recolección de métricas para el reporte final ────────────────────────────

MEDICIONES: dict[str, str] = {}


def registrar(nombre: str, valor: str) -> None:
    MEDICIONES[nombre] = valor


def pytest_terminal_summary(terminalreporter, exitstatus, config) -> None:  # noqa: ANN001, ARG001
    """Imprime las mediciones al cerrar la suite.

    El informe pide reportar explícitamente el tiempo hasta la alerta y el de
    carga del tablero, no solo que las pruebas pasen.
    """
    if not MEDICIONES:
        return

    terminalreporter.write_sep("=", "MEDICIONES DE LA CADENA COMPLETA")

    for nombre, valor in MEDICIONES.items():
        terminalreporter.write_line(f"  {nombre:.<52} {valor}")

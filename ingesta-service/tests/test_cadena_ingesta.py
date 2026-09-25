"""Cadena completa del servicio: mensaje MQTT → búfer → núcleo.

No levanta un broker: el suscriptor expone `procesar()` para que la prueba
entregue el mensaje igual que lo haría paho. Lo que se verifica es la cadena
del servicio, no el transporte del broker, que se ejercita en la Fase 6.
"""

from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
import pytest

from app.bufer import BuferLecturas
from app.cliente_core import ClienteCore
from app.despachador import Despachador
from app.modelos import Calidad
from app.subscriber import SuscriptorLecturas

LIMA = timezone(timedelta(hours=-5))
BASE = datetime(2026, 9, 18, 17, 0, 0, tzinfo=LIMA)


def mensaje(valor: float, segundos: int = 0, nodo: str = "N-07") -> tuple[str, str]:
    momento = (BASE + timedelta(seconds=segundos)).isoformat()
    carga = json.dumps({"codigo_nodo": nodo, "medido_en": momento, "valor": valor})

    return "piscigranja/EST-03/od_mgl", carga


@pytest.fixture
def bufer(tmp_path: Path) -> BuferLecturas:
    b = BuferLecturas(tmp_path / "buffer.sqlite3")
    yield b
    b.cerrar()


@pytest.fixture
def suscriptor(bufer: BuferLecturas) -> SuscriptorLecturas:
    return SuscriptorLecturas(
        bufer=bufer,
        host="mosquitto",
        puerto=1883,
        topico="piscigranja/+/+",
    )


class NucleoFalso:
    """Doble del núcleo que registra lo recibido y puede simular caídas."""

    def __init__(self) -> None:
        self.recibidas: list[dict[str, object]] = []
        self.disponible = True
        self.llamadas = 0

    def __call__(self, peticion: httpx.Request) -> httpx.Response:
        self.llamadas += 1

        if not self.disponible:
            raise httpx.ConnectError("enlace caido")

        lote = json.loads(peticion.content)["lecturas"]
        nuevas = 0
        duplicadas = 0

        vistas = {(r["codigo_nodo"], r["medido_en"]) for r in self.recibidas}

        for lectura in lote:
            clave = (lectura["codigo_nodo"], lectura["medido_en"])
            if clave in vistas:
                duplicadas += 1
            else:
                vistas.add(clave)
                self.recibidas.append(lectura)
                nuevas += 1

        return httpx.Response(
            201,
            json={"aceptadas": nuevas, "descartadas": 0, "duplicadas": duplicadas, "alertas": []},
        )


@pytest.fixture
def nucleo() -> NucleoFalso:
    return NucleoFalso()


@pytest.fixture
def despachador(bufer: BuferLecturas, nucleo: NucleoFalso) -> Despachador:
    cliente = ClienteCore(
        url_base="http://api-core:8000/api/v1",
        token="token",
        cliente=httpx.Client(transport=httpx.MockTransport(nucleo)),
    )

    return Despachador(bufer, cliente, tamano_lote=50)


class TestRecepcion:
    def test_cp03_una_lectura_publicada_llega_al_bufer(
        self, suscriptor: SuscriptorLecturas, bufer: BuferLecturas
    ) -> None:
        """CP-03: la lectura se persiste con su marca temporal y su calidad."""
        topico, carga = mensaje(valor=7.8)

        lectura = suscriptor.procesar(topico, carga)

        assert lectura is not None
        assert lectura.calidad is Calidad.VALIDA
        assert bufer.pendientes() == 1
        assert suscriptor.encolados == 1
        assert suscriptor.rechazados == 0

    def test_cp05_un_ph_imposible_se_encola_marcado_como_descartado(
        self, suscriptor: SuscriptorLecturas, bufer: BuferLecturas
    ) -> None:
        """CP-05: se conserva el registro, pero clasificado.

        No se tira: que un sensor entregue valores imposibles es informacion
        sobre el estado del instrumento, y el hueco en la serie tambien lo es.
        """
        carga = json.dumps(
            {"codigo_nodo": "N-09", "medido_en": BASE.isoformat(), "valor": 14.8}
        )

        lectura = suscriptor.procesar("piscigranja/EST-03/ph", carga)

        assert lectura is not None
        assert lectura.calidad is Calidad.DESCARTADA
        assert bufer.pendientes() == 1

    def test_un_mensaje_ininteligible_se_rechaza_sin_encolar(
        self, suscriptor: SuscriptorLecturas, bufer: BuferLecturas
    ) -> None:
        assert suscriptor.procesar("piscigranja/EST-03/od_mgl", "esto no es json") is None
        assert suscriptor.procesar("otro/topico", '{"valor":1}') is None

        assert bufer.pendientes() == 0
        assert suscriptor.rechazados == 2

    def test_cp04_el_reenvio_no_duplica_en_el_bufer(
        self, suscriptor: SuscriptorLecturas, bufer: BuferLecturas
    ) -> None:
        topico, carga = mensaje(valor=7.8)

        suscriptor.procesar(topico, carga)
        suscriptor.procesar(topico, carga)

        assert bufer.pendientes() == 1
        assert suscriptor.encolados == 1
        assert suscriptor.duplicados == 1


class TestEntrega:
    def test_el_bufer_se_vacia_hacia_el_nucleo(
        self,
        suscriptor: SuscriptorLecturas,
        bufer: BuferLecturas,
        despachador: Despachador,
        nucleo: NucleoFalso,
    ) -> None:
        for i in range(10):
            suscriptor.procesar(*mensaje(valor=7.5, segundos=i))

        despachador.vaciar()

        assert bufer.pendientes() == 0
        assert len(nucleo.recibidas) == 10
        assert despachador.total_entregadas == 10

    def test_cp04_corte_de_enlace_de_20_minutos_sin_perdidas(
        self,
        suscriptor: SuscriptorLecturas,
        bufer: BuferLecturas,
        despachador: Despachador,
        nucleo: NucleoFalso,
    ) -> None:
        """CP-04: el escenario completo del informe.

        Veinte minutos sin enlace a razon de una lectura cada cinco segundos:
        240 lecturas retenidas. Al restablecerse, las 240 deben almacenarse sin
        duplicados ni perdidas.
        """
        nucleo.disponible = False

        for i in range(240):
            suscriptor.procesar(*mensaje(valor=7.5, segundos=i * 5))

        # Durante el corte se intenta despachar y no se pierde nada.
        despachador.vaciar()

        assert bufer.pendientes() == 240, "Nada se pierde mientras el enlace esta caido"
        assert nucleo.recibidas == []

        # Se restablece el enlace.
        nucleo.disponible = True
        despachador.vaciar()

        assert bufer.pendientes() == 0, "El bufer se vacia por completo"
        assert len(nucleo.recibidas) == 240, "Cero perdidas"

        claves = {(r["codigo_nodo"], r["medido_en"]) for r in nucleo.recibidas}
        assert len(claves) == 240, "Cero duplicados"

    def test_las_marcas_temporales_del_nodo_llegan_intactas(
        self,
        suscriptor: SuscriptorLecturas,
        despachador: Despachador,
        nucleo: NucleoFalso,
    ) -> None:
        """RNF-02: el retraso del bufer no altera el instante de la medicion."""
        nucleo.disponible = False
        for i in range(5):
            suscriptor.procesar(*mensaje(valor=7.5, segundos=i * 300))

        nucleo.disponible = True
        despachador.vaciar()

        esperadas = [(BASE + timedelta(seconds=i * 300)).isoformat() for i in range(5)]
        recibidas = [r["medido_en"] for r in nucleo.recibidas]

        assert sorted(recibidas) == sorted(esperadas)

    def test_un_rechazo_definitivo_no_bloquea_la_cola(
        self, bufer: BuferLecturas, suscriptor: SuscriptorLecturas
    ) -> None:
        def rechaza_todo(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                422,
                json={"error": "validacion", "detalle": [{"campo": "codigo_nodo"}]},
            )

        cliente = ClienteCore(
            url_base="http://api-core:8000/api/v1",
            token="token",
            cliente=httpx.Client(transport=httpx.MockTransport(rechaza_todo)),
        )
        despachador = Despachador(bufer, cliente, tamano_lote=10)

        for i in range(10):
            suscriptor.procesar(*mensaje(valor=7.5, segundos=i))

        despachador.vaciar()

        assert bufer.pendientes() == 0, "Las lecturas irrecuperables liberan la cola"

    def test_las_metricas_reflejan_el_estado(
        self,
        suscriptor: SuscriptorLecturas,
        despachador: Despachador,
        nucleo: NucleoFalso,
    ) -> None:
        nucleo.disponible = False
        for i in range(3):
            suscriptor.procesar(*mensaje(valor=7.5, segundos=i))
        despachador.vaciar()

        metricas = despachador.metricas()
        assert metricas["pendientes"] == 3
        assert metricas["entregadas"] == 0

        nucleo.disponible = True
        despachador.vaciar()

        metricas = despachador.metricas()
        assert metricas["pendientes"] == 0
        assert metricas["entregadas"] == 3

        assert suscriptor.metricas()["recibidos"] == 0  # procesar() no pasa por on_message
        assert suscriptor.metricas()["encolados"] == 3

    def test_las_alertas_del_nucleo_se_contabilizan(
        self, bufer: BuferLecturas, suscriptor: SuscriptorLecturas
    ) -> None:
        def con_alerta(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                201,
                json={
                    "aceptadas": 1,
                    "descartadas": 0,
                    "duplicadas": 0,
                    "alertas": [
                        {
                            "id": 1,
                            "estanque_id": 3,
                            "parametro": "od_mgl",
                            "severidad": "critica",
                        }
                    ],
                },
            )

        cliente = ClienteCore(
            url_base="http://api-core:8000/api/v1",
            token="token",
            cliente=httpx.Client(transport=httpx.MockTransport(con_alerta)),
        )
        despachador = Despachador(bufer, cliente)

        suscriptor.procesar(*mensaje(valor=4.2))
        despachador.vaciar()

        assert despachador.total_alertas == 1

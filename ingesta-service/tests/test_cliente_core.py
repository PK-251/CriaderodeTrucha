"""Pruebas del cliente del núcleo: traducción de respuestas a decisiones."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

import httpx
import pytest

from app.cliente_core import ClienteCore
from app.modelos import Calidad, LecturaCruda, Parametro

LIMA = timezone(timedelta(hours=-5))


def lectura(segundos: int = 0) -> LecturaCruda:
    return LecturaCruda(
        codigo_nodo="N-07",
        codigo_estanque="EST-03",
        parametro=Parametro.OD_MGL,
        medido_en=datetime(2026, 9, 18, 17, 0, 0, tzinfo=LIMA) + timedelta(seconds=segundos),
        valor=4.2,
        calidad=Calidad.VALIDA,
    )


def cliente_con(manejador: object) -> ClienteCore:
    transporte = httpx.MockTransport(manejador)  # type: ignore[arg-type]

    return ClienteCore(
        url_base="http://api-core:8000/api/v1",
        token="token-de-prueba",
        cliente=httpx.Client(transport=transporte),
    )


class TestEnvioExitoso:
    def test_201_confirma_el_lote(self) -> None:
        def manejador(peticion: httpx.Request) -> httpx.Response:
            assert peticion.url.path.endswith("/lecturas")
            assert peticion.headers["Authorization"] == "Bearer token-de-prueba"
            assert peticion.headers["Accept"] == "application/json"

            return httpx.Response(
                201,
                json={"aceptadas": 2, "descartadas": 0, "duplicadas": 0, "alertas": []},
            )

        resultado = cliente_con(manejador).enviar_lote([lectura(0), lectura(5)])

        assert resultado.entregado is True
        assert resultado.puede_confirmarse is True
        assert resultado.aceptadas == 2

    def test_207_tambien_confirma_el_lote(self) -> None:
        """207 significa que una lectura quedo descartada por calidad.

        Es un resultado, no un fallo: la lectura se almaceno. Si se tratara
        como error, el servicio la reintentaria en bucle para siempre.
        """

        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                207,
                json={
                    "aceptadas": 1,
                    "descartadas": 1,
                    "duplicadas": 0,
                    "alertas": [],
                    "detalle_descartadas": [{"codigo_nodo": "N-09", "calidad": "descartada"}],
                },
            )

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.entregado is True
        assert resultado.descartadas == 1

    def test_las_alertas_generadas_se_devuelven(self) -> None:
        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                201,
                json={
                    "aceptadas": 1,
                    "descartadas": 0,
                    "duplicadas": 0,
                    "alertas": [
                        {"id": 7, "estanque_id": 3, "parametro": "od_mgl", "severidad": "critica"}
                    ],
                },
            )

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert len(resultado.alertas) == 1
        assert resultado.alertas[0]["severidad"] == "critica"

    def test_cp04_las_duplicadas_se_informan_sin_ser_error(self) -> None:
        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                201,
                json={"aceptadas": 0, "descartadas": 0, "duplicadas": 240, "alertas": []},
            )

        resultado = cliente_con(manejador).enviar_lote([lectura(i) for i in range(240)])

        assert resultado.entregado is True
        assert resultado.duplicadas == 240

    def test_un_lote_vacio_no_llama_al_nucleo(self) -> None:
        def manejador(_peticion: httpx.Request) -> httpx.Response:  # pragma: no cover
            pytest.fail("No debe llamarse al nucleo con un lote vacio")

        resultado = cliente_con(manejador).enviar_lote([])

        assert resultado.entregado is True


class TestFallos:
    def test_422_es_definitivo_y_libera_la_cola(self) -> None:
        """Un nodo inexistente no se arregla reintentando.

        Si se conservara en la cola, bloquearia indefinidamente las lecturas
        que vienen detras.
        """

        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(
                422,
                json={
                    "error": "validacion",
                    "detalle": [{"campo": "lecturas.0.codigo_nodo", "mensaje": "No existe"}],
                },
            )

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.entregado is False
        assert resultado.definitivo is True
        assert resultado.puede_confirmarse is True

    def test_401_conserva_la_cola(self) -> None:
        """Con el token renovado, las lecturas deben seguir ahi."""

        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(401, json={"error": "no_autenticado"})

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.entregado is False
        assert resultado.definitivo is False
        assert resultado.puede_confirmarse is False

    def test_500_conserva_la_cola(self) -> None:
        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(500, text="error interno")

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.puede_confirmarse is False

    def test_rnf02_el_nucleo_inalcanzable_conserva_la_cola(self) -> None:
        """Es el caso para el que existe el bufer."""

        def manejador(_peticion: httpx.Request) -> httpx.Response:
            raise httpx.ConnectError("enlace caido")

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.entregado is False
        assert resultado.puede_confirmarse is False
        assert "red" in resultado.motivo

    def test_una_respuesta_sin_json_no_rompe_el_servicio(self) -> None:
        def manejador(_peticion: httpx.Request) -> httpx.Response:
            return httpx.Response(201, text="<html>algo salio mal</html>")

        resultado = cliente_con(manejador).enviar_lote([lectura()])

        assert resultado.entregado is True
        assert resultado.aceptadas == 0


class TestCargaUtil:
    def test_el_lote_viaja_con_el_contrato_esperado(self) -> None:
        capturado: dict[str, object] = {}

        def manejador(peticion: httpx.Request) -> httpx.Response:
            import json

            capturado.update(json.loads(peticion.content))

            return httpx.Response(201, json={"aceptadas": 1})

        cliente_con(manejador).enviar_lote([lectura()])

        assert "lecturas" in capturado
        primera = capturado["lecturas"][0]  # type: ignore[index]
        assert primera == {
            "codigo_nodo": "N-07",
            "medido_en": "2026-09-18T17:00:00-05:00",
            "valor": 4.2,
        }

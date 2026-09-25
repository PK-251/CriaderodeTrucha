"""Pruebas del arranque, la configuración y los extremos HTTP del servicio."""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.bufer import BuferLecturas
from app.config import Ajustes, ajustes
from app.main import app
from app.subscriber import SuscriptorLecturas


class TestConfiguracion:
    def test_valores_por_defecto_corresponden_a_docker_compose(self) -> None:
        cfg = Ajustes()

        assert cfg.mqtt_host == "mosquitto"
        assert cfg.mqtt_port == 1883
        assert cfg.core_api_url.endswith("/api/v1")
        assert cfg.ingesta_buffer_path.endswith(".sqlite3")

    def test_el_topico_de_suscripcion_cubre_todos_los_estanques(self) -> None:
        """El comodin + coincide con un nivel: piscigranja/{estanque}/{parametro}."""
        cfg = Ajustes(mqtt_topic_base="piscigranja")

        assert cfg.topico_suscripcion == "piscigranja/+/+"

    def test_el_entorno_sobrescribe_los_valores(self, monkeypatch: pytest.MonkeyPatch) -> None:
        monkeypatch.setenv("MQTT_HOST", "broker-de-campo")
        monkeypatch.setenv("INGESTA_BATCH_SIZE", "120")

        cfg = Ajustes()

        assert cfg.mqtt_host == "broker-de-campo"
        assert cfg.ingesta_batch_size == 120

    def test_la_instancia_esta_cacheada(self) -> None:
        """Releer el entorno en cada mensaje seria trabajo inutil."""
        assert ajustes() is ajustes()


class TestExtremosHttp:
    def test_health_responde_para_el_healthcheck_del_contenedor(self) -> None:
        with TestClient(app) as cliente:
            respuesta = cliente.get("/health")

        assert respuesta.status_code == 200
        assert respuesta.json()["servicio"] == "ingesta-service"
        assert respuesta.json()["estado"] == "ok"

    def test_metricas_expone_recepcion_y_entrega(self) -> None:
        with TestClient(app) as cliente:
            cuerpo = cliente.get("/metricas").json()

        # Con el ciclo de vida arrancado, ambos bloques deben existir: son los
        # que revelan un corte de enlace, porque `pendientes` crece de forma
        # sostenida cuando el nucleo no responde.
        assert "recepcion" in cuerpo
        assert "entrega" in cuerpo
        assert "pendientes" in cuerpo["entrega"]

    def test_metricas_antes_de_arrancar_no_falla(self, monkeypatch: pytest.MonkeyPatch) -> None:
        from app import main

        monkeypatch.setattr(main, "_estado", {})

        import asyncio

        cuerpo = asyncio.run(main.metricas())

        assert cuerpo == {"estado": "iniciando"}


class TestRetrollamadasDelSuscriptor:
    """Las retrollamadas de paho, ejercitadas sin broker."""

    @pytest.fixture
    def suscriptor(self, tmp_path: Path) -> SuscriptorLecturas:
        bufer = BuferLecturas(tmp_path / "b.sqlite3")
        s = SuscriptorLecturas(
            bufer=bufer,
            host="mosquitto",
            puerto=1883,
            topico="piscigranja/+/+",
        )
        yield s
        bufer.cerrar()

    def test_al_conectar_se_suscribe_al_topico(self, suscriptor: SuscriptorLecturas) -> None:
        suscritos: list[tuple[str, int]] = []

        class ClienteFalso:
            def subscribe(self, topico: str, qos: int) -> None:
                suscritos.append((topico, qos))

        suscriptor._al_conectar(ClienteFalso(), None, None, "exito")  # type: ignore[arg-type]

        assert suscritos == [("piscigranja/+/+", 1)]

    def test_al_desconectar_no_pierde_lo_encolado(
        self, suscriptor: SuscriptorLecturas
    ) -> None:
        """La desconexion solo se registra: el bufer es lo que protege el dato."""
        import json
        from datetime import datetime, timedelta, timezone

        momento = datetime(2026, 9, 18, 17, 0, tzinfo=timezone(timedelta(hours=-5)))
        suscriptor.procesar(
            "piscigranja/EST-03/od_mgl",
            json.dumps({"codigo_nodo": "N-07", "medido_en": momento.isoformat(), "valor": 7.5}),
        )

        suscriptor._al_desconectar(None, None, None, "enlace caido")  # type: ignore[arg-type]

        assert suscriptor.encolados == 1

    def test_al_recibir_cuenta_y_procesa_el_mensaje(
        self, suscriptor: SuscriptorLecturas
    ) -> None:
        import json
        from datetime import datetime, timedelta, timezone

        momento = datetime(2026, 9, 18, 17, 0, tzinfo=timezone(timedelta(hours=-5)))

        class MensajeFalso:
            topic = "piscigranja/EST-03/od_mgl"
            payload = json.dumps(
                {"codigo_nodo": "N-07", "medido_en": momento.isoformat(), "valor": 7.5}
            ).encode()

        suscriptor._al_recibir(None, None, MensajeFalso())  # type: ignore[arg-type]

        assert suscriptor.recibidos == 1
        assert suscriptor.encolados == 1

    def test_la_retrollamada_al_encolar_se_invoca(self, tmp_path: Path) -> None:
        """Permite que el despachador reaccione sin esperar al intervalo.

        Importa para RNF-01: una lectura en riesgo no deberia aguardar a que el
        lote se llene para que el operador reciba el aviso.
        """
        import json
        from datetime import datetime, timedelta, timezone

        vistas = []
        bufer = BuferLecturas(tmp_path / "b.sqlite3")
        suscriptor = SuscriptorLecturas(
            bufer=bufer,
            host="mosquitto",
            puerto=1883,
            topico="piscigranja/+/+",
            al_encolar=vistas.append,
        )

        momento = datetime(2026, 9, 18, 17, 0, tzinfo=timezone(timedelta(hours=-5)))
        suscriptor.procesar(
            "piscigranja/EST-03/od_mgl",
            json.dumps({"codigo_nodo": "N-07", "medido_en": momento.isoformat(), "valor": 4.2}),
        )

        assert len(vistas) == 1
        assert vistas[0].valor == 4.2
        bufer.cerrar()

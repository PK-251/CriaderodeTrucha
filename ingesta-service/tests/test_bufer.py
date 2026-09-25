"""Pruebas del búfer persistente — CP-04 y RNF-02."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from app.bufer import BuferLecturas
from app.modelos import Calidad, LecturaCruda, Parametro

LIMA = timezone(timedelta(hours=-5))


def lectura(segundos: int = 0, nodo: str = "N-07", valor: float = 7.5) -> LecturaCruda:
    return LecturaCruda(
        codigo_nodo=nodo,
        codigo_estanque="EST-03",
        parametro=Parametro.OD_MGL,
        medido_en=datetime(2026, 9, 18, 17, 0, 0, tzinfo=LIMA) + timedelta(seconds=segundos),
        valor=valor,
        calidad=Calidad.VALIDA,
    )


@pytest.fixture
def bufer(tmp_path: Path) -> BuferLecturas:
    b = BuferLecturas(tmp_path / "buffer.sqlite3")
    yield b
    b.cerrar()


class TestEncolado:
    def test_una_lectura_nueva_se_encola(self, bufer: BuferLecturas) -> None:
        assert bufer.agregar(lectura()) is True
        assert bufer.pendientes() == 1

    def test_cp04_la_misma_lectura_no_se_duplica(self, bufer: BuferLecturas) -> None:
        assert bufer.agregar(lectura()) is True
        assert bufer.agregar(lectura()) is False
        assert bufer.pendientes() == 1

    def test_lecturas_de_nodos_distintos_en_el_mismo_instante_coexisten(
        self, bufer: BuferLecturas
    ) -> None:
        """La identidad es (nodo, instante), no solo el instante.

        Los doce sensores de la piscigranja publican a la vez; si la clave
        fuera solo la marca temporal, once lecturas se perderian en silencio.
        """
        bufer.agregar(lectura(nodo="N-07"))
        bufer.agregar(lectura(nodo="N-08"))
        bufer.agregar(lectura(nodo="N-09"))

        assert bufer.pendientes() == 3


class TestCortDeEnlace:
    def test_cp04_240_lecturas_del_bufer_sin_perdidas_ni_duplicados(
        self, bufer: BuferLecturas
    ) -> None:
        """CP-04: veinte minutos de enlace caido a una lectura cada 5 segundos."""
        lote = [lectura(segundos=i) for i in range(240)]

        encoladas = bufer.agregar_varias(lote)

        assert encoladas == 240, "Cero perdidas"
        assert bufer.pendientes() == 240

        # Reenvio completo del mismo bufer al restablecerse el enlace.
        reencoladas = bufer.agregar_varias(lote)

        assert reencoladas == 0, "Cero duplicados"
        assert bufer.pendientes() == 240

    def test_las_lecturas_salen_en_orden_de_llegada(self, bufer: BuferLecturas) -> None:
        for i in range(10):
            bufer.agregar(lectura(segundos=i))

        primeras = bufer.tomar(3)

        assert [lect.medido_en.second for lect in primeras] == [0, 1, 2]

    def test_tomar_no_retira_de_la_cola(self, bufer: BuferLecturas) -> None:
        """Retirar antes de confirmar perderia la lectura si el envio falla."""
        bufer.agregar_varias([lectura(segundos=i) for i in range(5)])

        bufer.tomar(5)

        assert bufer.pendientes() == 5

    def test_confirmar_retira_solo_las_entregadas(self, bufer: BuferLecturas) -> None:
        bufer.agregar_varias([lectura(segundos=i) for i in range(10)])

        entregadas = bufer.tomar(4)
        bufer.confirmar(entregadas)

        assert bufer.pendientes() == 6

    def test_confirmar_una_lista_vacia_no_hace_nada(self, bufer: BuferLecturas) -> None:
        bufer.agregar(lectura())

        assert bufer.confirmar([]) == 0
        assert bufer.pendientes() == 1


class TestPersistencia:
    def test_rnf02_el_bufer_sobrevive_al_reinicio(self, tmp_path: Path) -> None:
        """RNF-02: un corte de enlace suele venir con un corte electrico.

        Con la cola en memoria, al reiniciarse el contenedor se habrian perdido
        todas las lecturas acumuladas durante el corte.
        """
        ruta = tmp_path / "buffer.sqlite3"

        primero = BuferLecturas(ruta)
        primero.agregar_varias([lectura(segundos=i) for i in range(240)])
        primero.cerrar()

        segundo = BuferLecturas(ruta)

        assert segundo.pendientes() == 240

        recuperadas = segundo.tomar(240)
        assert len(recuperadas) == 240
        assert recuperadas[0].codigo_nodo == "N-07"
        assert recuperadas[0].parametro is Parametro.OD_MGL
        assert recuperadas[0].medido_en.utcoffset() == timedelta(hours=-5)

        segundo.cerrar()

    def test_el_valor_y_la_calidad_se_conservan(self, tmp_path: Path) -> None:
        ruta = tmp_path / "buffer.sqlite3"

        primero = BuferLecturas(ruta)
        primero.agregar(
            LecturaCruda(
                codigo_nodo="N-09",
                codigo_estanque="EST-03",
                parametro=Parametro.PH,
                medido_en=datetime(2026, 9, 18, 17, 42, 10, tzinfo=LIMA),
                valor=14.8,
                calidad=Calidad.DESCARTADA,
            )
        )
        primero.cerrar()

        segundo = BuferLecturas(ruta)
        recuperada = segundo.tomar(1)[0]

        assert recuperada.valor == 14.8
        assert recuperada.calidad is Calidad.DESCARTADA
        segundo.cerrar()


class TestLecturasAtascadas:
    def test_los_intentos_se_acumulan(self, bufer: BuferLecturas) -> None:
        bufer.agregar_varias([lectura(segundos=i) for i in range(3)])
        lote = bufer.tomar(3)

        for _ in range(5):
            bufer.registrar_intento(lote)

        assert bufer.atascadas(umbral_intentos=5) == 3
        assert bufer.atascadas(umbral_intentos=6) == 0

    def test_registrar_intento_sobre_lista_vacia_no_falla(self, bufer: BuferLecturas) -> None:
        bufer.registrar_intento([])

        assert bufer.atascadas(1) == 0

    def test_descartar_retira_las_rechazadas_definitivamente(
        self, bufer: BuferLecturas
    ) -> None:
        """Una lectura que el nucleo nunca aceptara no puede bloquear la cola."""
        bufer.agregar_varias([lectura(segundos=i) for i in range(5)])
        lote = bufer.tomar(2)

        bufer.descartar(lote)

        assert bufer.pendientes() == 3

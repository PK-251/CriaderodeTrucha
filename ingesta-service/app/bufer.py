"""Búfer persistente de lecturas pendientes de envío (RNF-02).

La conectividad de una piscigranja rural es intermitente. Cuando el enlace con
el núcleo cae, las lecturas siguen llegando desde los nodos y deben conservarse
hasta que se restablezca, sin perder ninguna y sin duplicar ninguna (CP-04).

Se implementa sobre SQLite y no sobre una lista en memoria por una razón
concreta: el corte de enlace suele venir acompañado de un corte eléctrico, y al
reiniciarse el contenedor una cola en memoria se habría perdido entera.

La deduplicación descansa en la clave primaria (codigo_nodo, medido_en), que es
la misma identidad que usa el núcleo. Insertar con ON CONFLICT DO NOTHING
delega la decisión a la base en lugar de comprobar antes y volver a insertar:
esa comprobación previa sería una condición de carrera si dos mensajes del
mismo instante llegaran a la vez.
"""

from __future__ import annotations

import sqlite3
from collections.abc import Iterable, Sequence
from datetime import datetime
from pathlib import Path

from .modelos import Calidad, LecturaCruda, Parametro

ESQUEMA = """
CREATE TABLE IF NOT EXISTS pendiente (
    codigo_nodo      TEXT    NOT NULL,
    medido_en        TEXT    NOT NULL,
    codigo_estanque  TEXT    NOT NULL,
    parametro        TEXT    NOT NULL,
    valor            REAL    NOT NULL,
    calidad          TEXT    NOT NULL,
    encolada_en      TEXT    NOT NULL,
    intentos         INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (codigo_nodo, medido_en)
);

CREATE INDEX IF NOT EXISTS idx_pendiente_orden ON pendiente (encolada_en);
"""


class BuferLecturas:
    """Cola persistente con deduplicación por identidad natural."""

    def __init__(self, ruta: str | Path) -> None:
        self._ruta = Path(ruta)

        if str(self._ruta) != ":memory:":
            self._ruta.parent.mkdir(parents=True, exist_ok=True)

        self._conexion = sqlite3.connect(str(self._ruta), check_same_thread=False)
        self._conexion.row_factory = sqlite3.Row
        # El modo WAL permite que el suscriptor siga encolando mientras el
        # proceso de envío lee, sin bloquearse mutuamente.
        if str(self._ruta) != ":memory:":
            self._conexion.execute("PRAGMA journal_mode=WAL")
        self._conexion.executescript(ESQUEMA)
        self._conexion.commit()

    # ── Escritura ────────────────────────────────────────────────────────────

    def agregar(self, lectura: LecturaCruda) -> bool:
        """Encola la lectura. Devuelve False si ya estaba (duplicado absorbido)."""
        cursor = self._conexion.execute(
            """
            INSERT INTO pendiente
                (codigo_nodo, medido_en, codigo_estanque, parametro, valor, calidad, encolada_en)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (codigo_nodo, medido_en) DO NOTHING
            """,
            (
                lectura.codigo_nodo,
                lectura.medido_en.isoformat(),
                lectura.codigo_estanque,
                lectura.parametro.value,
                lectura.valor,
                lectura.calidad.value,
                datetime.now().astimezone().isoformat(),
            ),
        )
        self._conexion.commit()

        return cursor.rowcount == 1

    def agregar_varias(self, lecturas: Iterable[LecturaCruda]) -> int:
        """Encola un conjunto y devuelve cuántas eran nuevas."""
        return sum(1 for lectura in lecturas if self.agregar(lectura))

    # ── Lectura ──────────────────────────────────────────────────────────────

    def tomar(self, limite: int) -> list[LecturaCruda]:
        """Devuelve las lecturas más antiguas pendientes, sin retirarlas.

        No se retiran aquí porque el envío puede fallar: se confirman solo
        cuando el núcleo las acepta. Perder una lectura por haberla sacado de
        la cola antes de tiempo sería exactamente lo que RNF-02 prohíbe.
        """
        filas = self._conexion.execute(
            """
            SELECT codigo_nodo, medido_en, codigo_estanque, parametro, valor, calidad
            FROM pendiente
            ORDER BY encolada_en, medido_en
            LIMIT ?
            """,
            (limite,),
        ).fetchall()

        return [
            LecturaCruda(
                codigo_nodo=fila["codigo_nodo"],
                codigo_estanque=fila["codigo_estanque"],
                parametro=Parametro(fila["parametro"]),
                medido_en=datetime.fromisoformat(fila["medido_en"]),
                valor=fila["valor"],
                calidad=Calidad(fila["calidad"]),
            )
            for fila in filas
        ]

    def pendientes(self) -> int:
        """Cuántas lecturas esperan confirmación."""
        fila = self._conexion.execute("SELECT count(*) AS n FROM pendiente").fetchone()

        return int(fila["n"])

    # ── Confirmación ─────────────────────────────────────────────────────────

    def confirmar(self, lecturas: Sequence[LecturaCruda]) -> int:
        """Retira de la cola las lecturas que el núcleo ya aceptó."""
        if not lecturas:
            return 0

        claves = [(lectura.codigo_nodo, lectura.medido_en.isoformat()) for lectura in lecturas]

        cursor = self._conexion.executemany(
            "DELETE FROM pendiente WHERE codigo_nodo = ? AND medido_en = ?",
            claves,
        )
        self._conexion.commit()

        return cursor.rowcount

    def registrar_intento(self, lecturas: Sequence[LecturaCruda]) -> None:
        """Anota un intento fallido, para poder observar lecturas atascadas."""
        if not lecturas:
            return

        claves = [(lectura.codigo_nodo, lectura.medido_en.isoformat()) for lectura in lecturas]

        self._conexion.executemany(
            "UPDATE pendiente SET intentos = intentos + 1 WHERE codigo_nodo = ? AND medido_en = ?",
            claves,
        )
        self._conexion.commit()

    def atascadas(self, umbral_intentos: int) -> int:
        """Lecturas que han fallado repetidamente.

        Un valor creciente indica que el problema no es el enlace sino la
        lectura misma —un nodo dado de baja, por ejemplo— y que reintentarla
        indefinidamente solo bloquea la cola.
        """
        fila = self._conexion.execute(
            "SELECT count(*) AS n FROM pendiente WHERE intentos >= ?",
            (umbral_intentos,),
        ).fetchone()

        return int(fila["n"])

    def descartar(self, lecturas: Sequence[LecturaCruda]) -> int:
        """Retira lecturas que el núcleo rechaza de forma definitiva."""
        return self.confirmar(lecturas)

    def cerrar(self) -> None:
        self._conexion.close()

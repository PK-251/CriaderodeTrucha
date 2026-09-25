"""Tipos del servicio de ingesta."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from enum import Enum


class Calidad(str, Enum):
    """Clasificación local de una lectura.

    Es una evaluación *previa*, no la definitiva: el núcleo vuelve a calificar
    cada lectura contra el rango físico del sensor concreto, que solo él conoce
    porque vive en la base de datos. La del servicio de ingesta usa los rangos
    del tipo de instrumento y sirve para dos cosas que el núcleo no puede
    hacer: registrar el estado del sensor en el propio gateway, y evitar que
    durante un corte de enlace el búfer se llene de lecturas imposibles.
    """

    VALIDA = "valida"
    DUDOSA = "dudosa"
    DESCARTADA = "descartada"


class Parametro(str, Enum):
    """Parámetros vigilados por el PMV.

    El amonio pertenece al Incremento 2 y no se declara: una lectura de amonio
    llegaría a un tópico que el servicio no reconoce y quedaría registrada como
    rechazada, en lugar de viajar al núcleo donde ningún umbral la evaluaría.
    """

    OD_MGL = "od_mgl"
    TEMP_C = "temp_c"
    PH = "ph"


@dataclass(frozen=True, slots=True)
class LecturaCruda:
    """Una medición tal como la publica el nodo."""

    codigo_nodo: str
    codigo_estanque: str
    parametro: Parametro
    medido_en: datetime
    valor: float
    calidad: Calidad = Calidad.VALIDA

    @property
    def clave(self) -> str:
        """Identidad natural, equivalente a la clave primaria del núcleo.

        Coincide con (sensor_id, medido_en) una vez que el núcleo resuelve el
        sensor por su código de nodo. Es lo que permite que el reenvío del
        búfer tras una caída de enlace no produzca duplicados (CP-04).
        """
        return f"{self.codigo_nodo}@{self.medido_en.isoformat()}"

    def a_carga_util(self) -> dict[str, object]:
        """Representación que espera POST /api/v1/lecturas."""
        return {
            "codigo_nodo": self.codigo_nodo,
            "medido_en": self.medido_en.isoformat(),
            "valor": self.valor,
        }


class CargaInvalida(ValueError):
    """El mensaje recibido no es una lectura interpretable.

    Se distingue de una lectura de mala calidad: aquí el problema no es el
    valor medido sino el mensaje, de modo que no hay nada que almacenar ni que
    enviar al núcleo.
    """

    def __init__(self, motivo: str, topico: str = "", carga: str = "") -> None:
        super().__init__(motivo)
        self.motivo = motivo
        self.topico = topico
        self.carga = carga

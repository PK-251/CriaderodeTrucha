"""Interpretación y control de calidad de las lecturas recibidas (RF-04).

Dos responsabilidades separadas:

1. **Interpretar** el tópico y la carga útil. Si el mensaje no es una lectura
   —tópico con forma distinta, JSON roto, fecha ilegible— se rechaza y no
   llega a ninguna parte.

2. **Clasificar** la calidad del valor contra el rango físico del instrumento.
   Un valor fuera de ese rango no describe el agua sino una sonda averiada, y
   evaluarlo contra los umbrales produciría una alerta falsa (CP-05).
"""

from __future__ import annotations

import json
from datetime import datetime

from .modelos import Calidad, CargaInvalida, LecturaCruda, Parametro

# ─────────────────────────────────────────────────────────────────────────────
# Rango físico por tipo de instrumento.
#
# Es el rango que el sensor PUEDE medir, no el que la trucha tolera. Un
# electrodo de pH entrega valores entre 0 y 14: un 14.8 es imposible, y por eso
# delata el instrumento en lugar de una condición del estanque.
#
# El núcleo guarda el rango de cada sensor concreto en la tabla `sensor` y
# vuelve a calificar la lectura con ese dato. Esta tabla cubre el tipo genérico
# y permite decidir en el gateway, sin consultar la base.
# ─────────────────────────────────────────────────────────────────────────────
RANGOS_FISICOS: dict[Parametro, tuple[float, float]] = {
    Parametro.OD_MGL: (0.0, 20.0),
    Parametro.TEMP_C: (-5.0, 45.0),
    Parametro.PH: (0.0, 14.0),
}

# Fracción del rango, en cada extremo, dentro de la cual el valor es alcanzable
# pero característico de una sonda sucia o descalibrada.
MARGEN_DUDOSO = 0.02


def clasificar(parametro: Parametro, valor: float) -> Calidad:
    """Asigna la calidad de una lectura según el rango físico del instrumento."""
    minimo, maximo = RANGOS_FISICOS[parametro]

    if valor < minimo or valor > maximo:
        return Calidad.DESCARTADA

    margen = (maximo - minimo) * MARGEN_DUDOSO

    if valor <= minimo + margen or valor >= maximo - margen:
        return Calidad.DUDOSA

    return Calidad.VALIDA


def parsear_topico(topico: str, base: str = "piscigranja") -> tuple[str, Parametro]:
    """Extrae el estanque y el parámetro de piscigranja/{estanque}/{parametro}.

    Raises:
        CargaInvalida: si el tópico no sigue el contrato o el parámetro no
            pertenece al alcance del PMV.
    """
    partes = topico.strip("/").split("/")

    if len(partes) != 3 or partes[0] != base:
        raise CargaInvalida(
            f"El topico no sigue el contrato {base}/{{estanque}}/{{parametro}}",
            topico=topico,
        )

    _, codigo_estanque, nombre_parametro = partes

    if not codigo_estanque:
        raise CargaInvalida("El topico no indica el estanque", topico=topico)

    try:
        parametro = Parametro(nombre_parametro)
    except ValueError as error:
        raise CargaInvalida(
            f"Parametro fuera del alcance del PMV: {nombre_parametro}",
            topico=topico,
        ) from error

    return codigo_estanque, parametro


def parsear_carga(
    topico: str,
    carga: bytes | str,
    base: str = "piscigranja",
) -> LecturaCruda:
    """Convierte un mensaje MQTT en una lectura clasificada.

    El contrato de la carga útil es:
        {"codigo_nodo": "N-03", "medido_en": "2026-09-18T17:42:10-05:00", "valor": 4.2}

    La marca temporal la pone el nodo y se conserva tal cual (RNF-02): cuando el
    gateway reenvía su búfer tras una caída de enlace, la lectura debe seguir
    diciendo cuándo se midió y no cuándo se entregó.

    Raises:
        CargaInvalida: si el mensaje no es interpretable como lectura.
    """
    codigo_estanque, parametro = parsear_topico(topico, base)

    texto = carga.decode("utf-8", errors="replace") if isinstance(carga, bytes) else carga

    try:
        datos = json.loads(texto)
    except json.JSONDecodeError as error:
        raise CargaInvalida(
            "La carga util no es JSON valido",
            topico=topico,
            carga=texto,
        ) from error

    if not isinstance(datos, dict):
        raise CargaInvalida("La carga util debe ser un objeto", topico=topico, carga=texto)

    codigo_nodo = datos.get("codigo_nodo")
    if not isinstance(codigo_nodo, str) or codigo_nodo.strip() == "":
        raise CargaInvalida("Falta el codigo del nodo", topico=topico, carga=texto)

    valor = datos.get("valor")
    if isinstance(valor, bool) or not isinstance(valor, int | float):
        raise CargaInvalida("El valor debe ser numerico", topico=topico, carga=texto)

    medido_en_crudo = datos.get("medido_en")
    if not isinstance(medido_en_crudo, str):
        raise CargaInvalida("Falta la marca temporal", topico=topico, carga=texto)

    try:
        medido_en = datetime.fromisoformat(medido_en_crudo)
    except ValueError as error:
        raise CargaInvalida(
            "La marca temporal no es ISO 8601",
            topico=topico,
            carga=texto,
        ) from error

    # Una marca temporal sin desplazamiento es ambigua: el núcleo y el gateway
    # podrían estar en husos distintos y el instante quedaría desplazado sin
    # que nada falle de forma visible.
    if medido_en.tzinfo is None:
        raise CargaInvalida(
            "La marca temporal debe incluir el desplazamiento horario",
            topico=topico,
            carga=texto,
        )

    return LecturaCruda(
        codigo_nodo=codigo_nodo.strip(),
        codigo_estanque=codigo_estanque,
        parametro=parametro,
        medido_en=medido_en,
        valor=float(valor),
        calidad=clasificar(parametro, float(valor)),
    )

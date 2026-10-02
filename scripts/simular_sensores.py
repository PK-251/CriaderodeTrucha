#!/usr/bin/env python3
"""Simulador de nodos sensores de la piscigranja.

Publica lecturas en el broker MQTT igual que lo harían los nodos reales, para
provocar cada escenario del PMV sin hardware físico.

El informe exige, en el criterio de terminación de la Fase 4, poder reproducir
«condiciones de riesgo difíciles de provocar en el estanque». Bajar el oxígeno
disuelto de un estanque real para comprobar que la alerta funciona significa
estresar a las truchas; aquí se consigue con un argumento.

Uso:
    python scripts/simular_sensores.py --normal
    python scripts/simular_sensores.py --advertencia --estanque EST-03
    python scripts/simular_sensores.py --critico --estanque EST-03
    python scripts/simular_sensores.py --corte-enlace 20
    python scripts/simular_sensores.py --sensor-averiado
    python scripts/simular_sensores.py --continuo --intervalo 5

Desde el anfitrión el broker está en localhost:1883; dentro de la red de
contenedores, en mosquitto:1883.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

try:
    import paho.mqtt.client as mqtt
except ImportError:  # pragma: no cover
    print(
        "Falta paho-mqtt. Instalalo con:  pip install paho-mqtt\n"
        "O ejecuta el simulador dentro del contenedor de ingesta:\n"
        "  docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py --critico",
        file=sys.stderr,
    )
    raise SystemExit(2) from None


LIMA = timezone(timedelta(hours=-5))

# Nodos de la piscigranja, tal como los carga db/seeds/S1__maestras.sql
NODOS: dict[str, list[tuple[str, str]]] = {
    "EST-01": [("N-01", "od_mgl"), ("N-02", "temp_c"), ("N-03", "ph")],
    "EST-02": [("N-04", "od_mgl"), ("N-05", "temp_c"), ("N-06", "ph")],
    "EST-03": [("N-07", "od_mgl"), ("N-08", "temp_c"), ("N-09", "ph")],
    "EST-04": [("N-10", "od_mgl"), ("N-11", "temp_c"), ("N-12", "ph")],
}

# Umbrales de S1. El límite crítico es `min - margen`.
UMBRALES: dict[str, dict[str, tuple[float, float, float]]] = {
    "EST-01": {"od_mgl": (6.5, 12.0, 0.5), "temp_c": (9.0, 15.0, 2.0), "ph": (6.8, 8.2, 0.6)},
    "EST-02": {"od_mgl": (6.0, 12.0, 0.5), "temp_c": (9.0, 16.0, 2.0), "ph": (6.5, 8.5, 0.7)},
    "EST-03": {"od_mgl": (5.5, 12.0, 0.5), "temp_c": (9.0, 16.0, 2.0), "ph": (6.5, 8.5, 0.7)},
    "EST-04": {"od_mgl": (5.5, 12.0, 0.5), "temp_c": (9.0, 16.0, 2.0), "ph": (6.5, 8.5, 0.7)},
}

# Rango físico del instrumento: fuera de esto la lectura se descarta (CP-05).
RANGO_FISICO: dict[str, tuple[float, float]] = {
    "od_mgl": (0.0, 20.0),
    "temp_c": (-5.0, 45.0),
    "ph": (0.0, 14.0),
}


@dataclass(frozen=True, slots=True)
class Lectura:
    estanque: str
    nodo: str
    parametro: str
    valor: float
    medido_en: datetime

    @property
    def topico(self) -> str:
        return f"piscigranja/{self.estanque}/{self.parametro}"

    @property
    def carga(self) -> str:
        return json.dumps(
            {
                "codigo_nodo": self.nodo,
                "medido_en": self.medido_en.isoformat(),
                "valor": round(self.valor, 3),
            }
        )


# ── Generación de valores ────────────────────────────────────────────────────


def valor_normal(estanque: str, parametro: str) -> float:
    """Centro del rango aceptable: no debe generar alerta."""
    minimo, maximo, _ = UMBRALES[estanque][parametro]

    return (minimo + maximo) / 2


def valor_advertencia(estanque: str, parametro: str) -> float:
    """Entre el límite crítico y el mínimo aceptable.

    Para EST-03 y oxígeno disuelto esto da 5.1 mg/L, que es exactamente el
    escenario CP-06 del informe: mínimo 5.5, crítico 5.0.
    """
    minimo, _, margen = UMBRALES[estanque][parametro]

    return minimo - margen * 0.8


def valor_critico(estanque: str, parametro: str) -> float:
    """Por debajo del límite crítico, sin salirse del rango físico.

    Para EST-03 y oxígeno disuelto da 4.2 mg/L, el escenario CP-07.
    """
    minimo, _, margen = UMBRALES[estanque][parametro]
    fisico_min, _ = RANGO_FISICO[parametro]

    return max(fisico_min + 0.1, minimo - margen - 0.8)


def valor_imposible(parametro: str) -> float:
    """Fuera del rango del instrumento: delata la sonda, no el agua (CP-05)."""
    _, fisico_max = RANGO_FISICO[parametro]

    return fisico_max + 0.8


# ── Publicación ──────────────────────────────────────────────────────────────


class Publicador:
    def __init__(self, host: str, puerto: int, verboso: bool = True) -> None:
        self._cliente = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="simulador-sippt")
        self._cliente.connect(host, puerto, keepalive=30)
        self._cliente.loop_start()
        self._verboso = verboso
        self.publicadas = 0

    def publicar(self, lectura: Lectura) -> None:
        info = self._cliente.publish(lectura.topico, lectura.carga, qos=1)
        info.wait_for_publish(timeout=5)
        self.publicadas += 1

        if self._verboso:
            print(
                f"  {lectura.estanque} {lectura.parametro:7s} "
                f"{lectura.valor:7.2f}  ({lectura.nodo})"
            )

    def cerrar(self) -> None:
        self._cliente.loop_stop()
        self._cliente.disconnect()


def ahora() -> datetime:
    return datetime.now(LIMA)


# ── Escenarios ───────────────────────────────────────────────────────────────


def escenario_normal(pub: Publicador, estanques: list[str]) -> None:
    print("Escenario NORMAL — todos los parametros dentro de rango")
    momento = ahora()

    for estanque in estanques:
        for nodo, parametro in NODOS[estanque]:
            pub.publicar(
                Lectura(estanque, nodo, parametro, valor_normal(estanque, parametro), momento)
            )


def escenario_umbral(pub: Publicador, estanques: list[str], critico: bool) -> None:
    etiqueta = "CRITICO" if critico else "ADVERTENCIA"
    caso = "CP-07" if critico else "CP-06"
    print(f"Escenario {etiqueta} ({caso}) — oxigeno disuelto fuera de rango")

    momento = ahora()
    generar = valor_critico if critico else valor_advertencia

    for estanque in estanques:
        nodo = NODOS[estanque][0][0]
        pub.publicar(Lectura(estanque, nodo, "od_mgl", generar(estanque, "od_mgl"), momento))

        # El resto de parámetros se mantiene normal, para que la alerta no se
        # confunda con un fallo general del estanque.
        for otro_nodo, parametro in NODOS[estanque][1:]:
            pub.publicar(
                Lectura(estanque, otro_nodo, parametro, valor_normal(estanque, parametro), momento)
            )


def escenario_sensor_averiado(pub: Publicador, estanques: list[str]) -> None:
    print("Escenario SENSOR AVERIADO (CP-05) — pH fuera del rango del electrodo")
    momento = ahora()

    for estanque in estanques:
        nodo = NODOS[estanque][2][0]
        pub.publicar(Lectura(estanque, nodo, "ph", valor_imposible("ph"), momento))


def escenario_corte_enlace(pub: Publicador, estanques: list[str], minutos: int) -> int:
    """Reproduce CP-04: el búfer acumula durante el corte y se reenvía entero.

    No se corta el enlace de verdad —eso lo hace `docker compose stop
    api-core`—; lo que se publica aquí es el lote que el gateway habría
    retenido, con las marcas temporales del periodo sin conexión.
    """
    print(f"Escenario CORTE DE ENLACE (CP-04) — {minutos} minutos retenidos")

    fin = ahora()
    inicio = fin - timedelta(minutes=minutos)
    intervalo = timedelta(seconds=5)

    momento = inicio
    total = 0

    while momento < fin:
        for estanque in estanques:
            nodo, parametro = NODOS[estanque][0]
            pub.publicar(Lectura(estanque, nodo, parametro, valor_normal(estanque, parametro), momento))
            total += 1

        momento += intervalo

    print(f"  {total} lecturas publicadas con marcas del periodo sin enlace")

    return total


def escenario_continuo(pub: Publicador, estanques: list[str], intervalo_min: float) -> None:
    """Operación normal: cada nodo publica a su ritmo real."""
    print(
        f"Escenario CONTINUO — publicando cada {intervalo_min} min. "
        "Interrumpe con Ctrl+C."
    )

    try:
        while True:
            escenario_normal(pub, estanques)
            time.sleep(intervalo_min * 60)
    except KeyboardInterrupt:
        print("\nDetenido.")


# ── Interfaz de línea de comandos ────────────────────────────────────────────


def main() -> int:
    analizador = argparse.ArgumentParser(
        description="Simulador de nodos sensores del PMV.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )

    modos = analizador.add_mutually_exclusive_group(required=True)
    modos.add_argument("--normal", action="store_true", help="lecturas dentro de rango")
    modos.add_argument("--advertencia", action="store_true", help="CP-06: bajo el minimo aceptable")
    modos.add_argument("--critico", action="store_true", help="CP-07: bajo el limite critico")
    modos.add_argument("--sensor-averiado", action="store_true", help="CP-05: valor imposible")
    modos.add_argument(
        "--corte-enlace", type=int, metavar="MINUTOS", help="CP-04: lote retenido en el bufer"
    )
    modos.add_argument("--continuo", action="store_true", help="operacion normal sostenida")

    analizador.add_argument("--estanque", action="append", choices=sorted(NODOS), help="por defecto todos")
    analizador.add_argument("--host", default="localhost", help="broker MQTT (por defecto localhost)")
    analizador.add_argument("--puerto", type=int, default=1883)
    analizador.add_argument("--intervalo", type=float, default=5.0, help="minutos, solo con --continuo")
    analizador.add_argument("--silencioso", action="store_true")

    args = analizador.parse_args()
    estanques = args.estanque or sorted(NODOS)

    try:
        pub = Publicador(args.host, args.puerto, verboso=not args.silencioso)
    except OSError as error:
        print(f"No se pudo conectar al broker {args.host}:{args.puerto} — {error}", file=sys.stderr)

        return 1

    try:
        if args.normal:
            escenario_normal(pub, estanques)
        elif args.advertencia:
            escenario_umbral(pub, estanques, critico=False)
        elif args.critico:
            escenario_umbral(pub, estanques, critico=True)
        elif args.sensor_averiado:
            escenario_sensor_averiado(pub, estanques)
        elif args.corte_enlace is not None:
            escenario_corte_enlace(pub, estanques, args.corte_enlace)
        elif args.continuo:
            escenario_continuo(pub, estanques, args.intervalo)
    finally:
        pub.cerrar()

    print(f"\n{pub.publicadas} lecturas publicadas en {args.host}:{args.puerto}")
    print("El servicio de ingesta las entrega al nucleo en el siguiente ciclo de despacho.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

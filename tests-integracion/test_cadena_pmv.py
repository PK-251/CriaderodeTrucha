"""Suite de integración del PMV — Matriz 4.1 del informe.

Recorre la cadena completa `nodo → MQTT → ingesta → núcleo → BD → alerta →
tablero` y verifica los diez casos CP-01 a CP-10 contra los servicios reales.

Lo que estas pruebas añaden sobre las de cada módulo es precisamente lo que
ninguna de ellas puede ver: que los contratos se interpretan igual a ambos
lados, que las marcas temporales sobreviven al viaje, y cuánto tarda de verdad
una condición de riesgo en llegar al operador.
"""

from __future__ import annotations

import time
from datetime import datetime, timedelta

import httpx
import pytest

from conftest import (
    TABLERO,
    Credenciales,
    ahora,
    cabeceras,
    esperar_alerta,
    registrar,
)

# Umbrales de EST-03 según los datos semilla: mínimo 5.5, margen 0.5, de modo
# que el límite crítico es exactamente 5.0 (escenario del informe).
EST03_OD = ("EST-03", "od_mgl", "N-07")
EST03_PH = ("EST-03", "ph", "N-09")


# ═════════════════════════════════════════════════════════════════════════════
# HU-01 · Gestión de estanques y umbrales
# ═════════════════════════════════════════════════════════════════════════════


class TestHU01:
    def test_cp01_umbral_invertido_se_rechaza_senalando_el_campo(
        self, api: httpx.Client, tecnico: Credenciales
    ) -> None:
        """CP-01 · Alta de estanque con umbral mínimo mayor al máximo."""
        respuesta = api.post(
            "/estanques",
            json={
                "codigo": "EST-CP01",
                "volumen_m3": 100,
                "biomasa_kg": 50,
                "etapa": "juvenil",
                "umbrales": [
                    {
                        "parametro": "od_mgl",
                        "min_aceptable": 9.0,
                        "max_aceptable": 2.0,
                        "severidad_critica": 0.5,
                    }
                ],
            },
            headers=cabeceras(tecnico),
        )

        assert respuesta.status_code == 422
        detalle = respuesta.json()["detalle"][0]
        assert detalle["campo"] == "min_aceptable"

        # El estanque tampoco debe haberse creado a medias.
        listado = api.get("/estanques", headers=cabeceras(tecnico)).json()["datos"]
        assert all(e["codigo"] != "EST-CP01" for e in listado)

    def test_cp02_codigo_duplicado_no_crea_un_segundo_registro(
        self, api: httpx.Client, tecnico: Credenciales
    ) -> None:
        """CP-02 · Alta de estanque con código duplicado."""
        antes = api.get("/estanques", headers=cabeceras(tecnico)).json()["datos"]
        cuantos_est03 = sum(1 for e in antes if e["codigo"] == "EST-03")
        assert cuantos_est03 == 1

        respuesta = api.post(
            "/estanques",
            json={
                "codigo": "EST-03",
                "volumen_m3": 90,
                "biomasa_kg": 10,
                "etapa": "juvenil",
                "umbrales": [],
            },
            headers=cabeceras(tecnico),
        )

        assert respuesta.status_code == 422
        assert respuesta.json()["detalle"][0]["campo"] == "codigo"

        despues = api.get("/estanques", headers=cabeceras(tecnico)).json()["datos"]
        assert sum(1 for e in despues if e["codigo"] == "EST-03") == 1


# ═════════════════════════════════════════════════════════════════════════════
# HU-02 · Ingesta desde los nodos
# ═════════════════════════════════════════════════════════════════════════════


class TestHU02:
    def test_cp03_lectura_publicada_llega_a_la_base(
        self, publicar, operador: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """CP-03 · Recepción y parseo de una lectura del tópico MQTT."""
        estanque, parametro, nodo = EST03_OD
        momento = ahora()

        publicar(estanque, parametro, nodo, 7.85, momento)

        inicio = time.monotonic()
        encontrada = None

        while time.monotonic() - inicio < 90:
            serie = api.get(
                "/estanques/3/lecturas?parametro=od_mgl&por_pagina=50",
                headers=cabeceras(operador),
            ).json()["datos"]

            # Se comparan INSTANTES y no cadenas: como el nucleo decide el
            # formato de salida, comparar texto haria que la prueba fallara por
            # una diferencia de presentacion en lugar de por un dato incorrecto.
            encontrada = next(
                (p for p in serie if datetime.fromisoformat(p["medido_en"]) == momento),
                None,
            )

            if encontrada is not None:
                break

            time.sleep(1.0)

        assert encontrada is not None, "La lectura no llego a la base"
        assert encontrada["valor"] == pytest.approx(7.85)

        # RNF-02: el instante es el del nodo, no el de recepción.
        assert datetime.fromisoformat(encontrada["medido_en"]) == momento

    def test_cp04_el_reenvio_del_bufer_no_duplica_ni_pierde(
        self, publicar, operador: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """CP-04 · Lote retenido durante un corte de enlace.

        Se publica dos veces el mismo lote. El sistema debe almacenar cada
        lectura exactamente una vez: ni perder ninguna del primer envío, ni
        duplicar ninguna del segundo.
        """
        estanque, parametro, nodo = EST03_OD
        base = ahora() - timedelta(minutes=40)
        cuantas = 60

        instantes = [(base + timedelta(seconds=i * 5)) for i in range(cuantas)]

        for momento in instantes:
            publicar(estanque, parametro, nodo, 7.5, momento)

        def cuantas_almacenadas() -> int:
            desde = (base - timedelta(minutes=1)).isoformat()
            hasta = (base + timedelta(minutes=10)).isoformat()
            serie = api.get(
                f"/estanques/3/lecturas?parametro=od_mgl&desde={desde}&hasta={hasta}&por_pagina=500",
                headers=cabeceras(operador),
            ).json()["datos"]

            esperados = set(instantes)

            return sum(1 for p in serie if datetime.fromisoformat(p["medido_en"]) in esperados)

        inicio = time.monotonic()
        while time.monotonic() - inicio < 90 and cuantas_almacenadas() < cuantas:
            time.sleep(2.0)

        assert cuantas_almacenadas() == cuantas, "Se perdieron lecturas del bufer"

        # Reenvío completo del mismo búfer.
        for momento in instantes:
            publicar(estanque, parametro, nodo, 7.5, momento)

        time.sleep(40)

        assert cuantas_almacenadas() == cuantas, "El reenvio duplico lecturas"
        registrar("CP-04 · lecturas del bufer (enviadas dos veces)", f"{cuantas} almacenadas, 0 duplicados")

    def test_cp05_valor_imposible_se_descarta_sin_alertar(
        self, publicar, operador: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """CP-05 · pH 14.8, fuera del rango físico del electrodo."""
        estanque, parametro, nodo = EST03_PH
        momento = ahora()

        publicar(estanque, parametro, nodo, 14.8, momento)
        time.sleep(45)

        # No debe existir alerta de pH para ese estanque.
        abiertas = api.get("/alertas?estado=abierta", headers=cabeceras(operador)).json()["datos"]
        de_ph = [a for a in abiertas if a["estanque_codigo"] == estanque and a["parametro"] == "ph"]

        assert de_ph == [], "Una lectura descartada no puede generar alerta"

        # Y la serie de lecturas válidas no la incluye.
        serie = api.get(
            "/estanques/3/lecturas?parametro=ph&por_pagina=20", headers=cabeceras(operador)
        ).json()["datos"]

        assert all(datetime.fromisoformat(p["medido_en"]) != momento for p in serie), (
            "La lectura descartada no debe aparecer entre las validas"
        )


# ═════════════════════════════════════════════════════════════════════════════
# HU-04 · Evaluación de umbrales y alertas
# ═════════════════════════════════════════════════════════════════════════════


class TestHU04:
    def test_cp06_oxigeno_en_advertencia(
        self, publicar, operador: Credenciales  # noqa: ANN001
    ) -> None:
        """CP-06 · OD 5.1 mg/L con mínimo 5.5 y crítico 5.0."""
        estanque, parametro, nodo = EST03_OD
        publicar(estanque, parametro, nodo, 5.1, ahora())

        alerta, _ = esperar_alerta(operador, estanque, parametro)

        assert alerta["severidad"] == "advertencia"
        assert float(alerta["valor_detectado"]) == pytest.approx(5.1)
        assert float(alerta["umbral_violado"]) == pytest.approx(5.5)

    def test_cp07_oxigeno_critico_y_tiempo_hasta_la_alerta(
        self, publicar, operador: Credenciales  # noqa: ANN001
    ) -> None:
        """CP-07 · OD 4.2 mg/L por debajo del crítico, con RNF-01 medido.

        Es la medición central del informe: el tiempo desde que el nodo mide
        hasta que la alerta es visible para el operador. RNF-01 concede cinco
        minutos; el informe reporta 41 segundos.
        """
        estanque, parametro, nodo = EST03_OD
        medido_en = ahora()

        publicar(estanque, parametro, nodo, 4.2, medido_en)
        alerta, transcurrido = esperar_alerta(operador, estanque, parametro)

        assert alerta["severidad"] == "critica"
        assert float(alerta["valor_detectado"]) == pytest.approx(4.2)

        assert transcurrido < 300, f"RNF-01 incumplido: {transcurrido:.1f} s"
        registrar(
            "CP-07 · medicion → alerta visible  (meta < 300 s)",
            f"{transcurrido:.1f} s",
        )

    def test_cp08_tres_lecturas_consecutivas_mantienen_una_sola_alerta(
        self, publicar, operador: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """CP-08 · Sin alertas duplicadas mientras la condición persiste."""
        estanque, parametro, nodo = EST03_OD
        base = ahora()

        for i, valor in enumerate([5.4, 5.3, 5.2]):
            publicar(estanque, parametro, nodo, valor, base + timedelta(seconds=i * 10))

        esperar_alerta(operador, estanque, parametro)
        time.sleep(45)

        abiertas = api.get("/alertas?estado=abierta", headers=cabeceras(operador)).json()["datos"]
        del_estanque = [
            a for a in abiertas if a["estanque_codigo"] == estanque and a["parametro"] == parametro
        ]

        assert len(del_estanque) == 1, "Debe existir una sola alerta abierta"

        alerta = del_estanque[0]
        assert float(alerta["valor_detectado"]) == pytest.approx(5.4), "Conserva el valor original"
        assert float(alerta["ultimo_valor"]) == pytest.approx(5.2), "Actualiza el ultimo valor"


# ═════════════════════════════════════════════════════════════════════════════
# HU-03 · Tablero
# ═════════════════════════════════════════════════════════════════════════════


class TestHU03:
    def test_cp09_el_tablero_resuelve_el_estado_dentro_del_presupuesto(
        self, api: httpx.Client, operador: Credenciales
    ) -> None:
        """CP-09 · RNF-03: carga del tablero en menos de 3 segundos."""
        # Primera llamada descartada: compila la ruta en modo desarrollo.
        api.get("/estanques", headers=cabeceras(operador))

        tiempos = []
        for _ in range(5):
            inicio = time.monotonic()
            respuesta = api.get("/estanques", headers=cabeceras(operador))
            tiempos.append(time.monotonic() - inicio)
            assert respuesta.status_code == 200

        peor_api = max(tiempos)

        with httpx.Client(timeout=30) as cliente:
            cliente.get(f"{TABLERO}/acceso")

            tiempos_pagina = []
            for _ in range(3):
                inicio = time.monotonic()
                pagina = cliente.get(
                    f"{TABLERO}/", cookies={"sippt_sesion": operador.token}, follow_redirects=True
                )
                tiempos_pagina.append(time.monotonic() - inicio)
                assert pagina.status_code == 200

        peor_pagina = max(tiempos_pagina)

        registrar("CP-09 · API /estanques  (peor de 5)", f"{peor_api * 1000:.0f} ms")
        registrar("CP-09 · tablero completo  (meta < 3 s)", f"{peor_pagina:.2f} s")

        assert peor_pagina < 3.0, f"RNF-03 incumplido: {peor_pagina:.2f} s"

    def test_el_tablero_muestra_los_estanques_con_su_semaforo(
        self, api: httpx.Client, operador: Credenciales
    ) -> None:
        datos = api.get("/estanques", headers=cabeceras(operador)).json()["datos"]

        assert len(datos) >= 4

        for estanque in datos:
            assert estanque["semaforo"] in {"normal", "advertencia", "critico"}
            assert isinstance(estanque["sin_comunicacion"], bool)
            assert len(estanque["parametros"]) == 3


# ═════════════════════════════════════════════════════════════════════════════
# HU-05 · Registro de la intervención
# ═════════════════════════════════════════════════════════════════════════════


class TestHU05:
    def test_cp10_atencion_duplicada_devuelve_el_registro_existente(
        self, publicar, operador: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """CP-10 · Registro de atención sobre una alerta ya atendida."""
        estanque, parametro, nodo = EST03_OD
        publicar(estanque, parametro, nodo, 4.3, ahora())

        alerta, _ = esperar_alerta(operador, estanque, parametro)

        primera = api.post(
            f"/alertas/{alerta['id']}/atencion",
            json={"accion": "Aireacion manual activada", "observacion": "Suite de integracion"},
            headers=cabeceras(operador),
        )
        assert primera.status_code == 201

        segunda = api.post(
            f"/alertas/{alerta['id']}/atencion",
            json={"accion": "Intento duplicado"},
            headers=cabeceras(operador),
        )

        assert segunda.status_code == 409
        cuerpo = segunda.json()

        # El contrato exige devolver la atención existente, no la rechazada.
        assert cuerpo["datos"]["accion"] == "Aireacion manual activada"

    def test_el_veterinario_no_puede_cerrar_alertas(
        self, publicar, operador: Credenciales, veterinario: Credenciales, api: httpx.Client  # noqa: ANN001
    ) -> None:
        """RF-09 · El rol se verifica de extremo a extremo."""
        estanque, parametro, nodo = EST03_OD
        publicar(estanque, parametro, nodo, 4.4, ahora())

        alerta, _ = esperar_alerta(operador, estanque, parametro)

        respuesta = api.post(
            f"/alertas/{alerta['id']}/atencion",
            json={"accion": "Diagnostico sanitario"},
            headers=cabeceras(veterinario),
        )

        assert respuesta.status_code == 403

        # Un 403 no puede tener efectos: la alerta sigue abierta.
        abiertas = api.get("/alertas?estado=abierta", headers=cabeceras(operador)).json()["datos"]
        assert any(a["id"] == alerta["id"] for a in abiertas)

    def test_sin_token_la_api_no_entrega_nada(self, api: httpx.Client) -> None:
        for ruta in ("/estanques", "/alertas", "/estanques/3/lecturas"):
            assert api.get(ruta).status_code == 401

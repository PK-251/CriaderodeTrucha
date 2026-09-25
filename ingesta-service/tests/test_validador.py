"""Pruebas de interpretación y control de calidad (RF-04)."""

from __future__ import annotations

from datetime import datetime

import pytest

from app.modelos import Calidad, CargaInvalida, Parametro
from app.validador import clasificar, parsear_carga, parsear_topico


class TestClasificacionDeCalidad:
    def test_cp05_ph_fuera_del_rango_del_electrodo_se_descarta(self) -> None:
        """CP-05: un electrodo mide de 0 a 14; un 14.8 delata la sonda."""
        assert clasificar(Parametro.PH, 14.8) is Calidad.DESCARTADA
        assert clasificar(Parametro.PH, -0.5) is Calidad.DESCARTADA

    def test_valor_normal_es_valido(self) -> None:
        assert clasificar(Parametro.PH, 7.4) is Calidad.VALIDA
        assert clasificar(Parametro.OD_MGL, 7.8) is Calidad.VALIDA
        assert clasificar(Parametro.TEMP_C, 12.0) is Calidad.VALIDA

    def test_valores_en_los_extremos_son_dudosos(self) -> None:
        """Alcanzables por el instrumento, pero tipicos de una sonda sucia."""
        # Margen del 2 % sobre un rango de 14 unidades: 0.28.
        assert clasificar(Parametro.PH, 0.2) is Calidad.DUDOSA
        assert clasificar(Parametro.PH, 13.9) is Calidad.DUDOSA

    @pytest.mark.parametrize(
        ("parametro", "valor", "esperada"),
        [
            (Parametro.OD_MGL, 25.0, Calidad.DESCARTADA),
            (Parametro.OD_MGL, -1.0, Calidad.DESCARTADA),
            (Parametro.OD_MGL, 4.2, Calidad.VALIDA),
            (Parametro.TEMP_C, 60.0, Calidad.DESCARTADA),
            (Parametro.TEMP_C, -10.0, Calidad.DESCARTADA),
            (Parametro.TEMP_C, 17.5, Calidad.VALIDA),
        ],
    )
    def test_barrido_de_rangos(
        self, parametro: Parametro, valor: float, esperada: Calidad
    ) -> None:
        assert clasificar(parametro, valor) is esperada

    def test_una_lectura_critica_sigue_siendo_valida(self) -> None:
        """La calidad describe el sensor, no el riesgo.

        Un oxigeno de 4.2 mg/L es peligroso para la trucha, pero el instrumento
        lo mide perfectamente: debe llegar al nucleo como valida para que
        ReglaUmbral pueda generar la alerta critica.
        """
        assert clasificar(Parametro.OD_MGL, 4.2) is Calidad.VALIDA


class TestParseoDeTopico:
    def test_topico_del_contrato(self) -> None:
        estanque, parametro = parsear_topico("piscigranja/EST-03/od_mgl")

        assert estanque == "EST-03"
        assert parametro is Parametro.OD_MGL

    @pytest.mark.parametrize(
        "topico",
        [
            "piscigranja/EST-03",
            "piscigranja/EST-03/od_mgl/extra",
            "otracosa/EST-03/od_mgl",
            "piscigranja//od_mgl",
        ],
    )
    def test_topicos_que_no_siguen_el_contrato(self, topico: str) -> None:
        with pytest.raises(CargaInvalida):
            parsear_topico(topico)

    def test_el_amonio_queda_fuera_del_pmv(self) -> None:
        """nh4_mgl existe en el esquema pero pertenece al Incremento 2.

        Aceptarlo aqui enviaria al nucleo una lectura que ningun umbral puede
        evaluar, y que por tanto nunca generaria alerta sin que nadie lo note.
        """
        with pytest.raises(CargaInvalida, match="fuera del alcance"):
            parsear_topico("piscigranja/EST-03/nh4_mgl")


VALOR_TEXTO = '{"codigo_nodo":"N-07","medido_en":"2026-09-18T17:42:10-05:00","valor":"alto"}'
VALOR_BOOLEANO = '{"codigo_nodo":"N-07","medido_en":"2026-09-18T17:42:10-05:00","valor":true}'


class TestParseoDeCarga:
    CARGA_VALIDA = '{"codigo_nodo":"N-07","medido_en":"2026-09-18T17:42:10-05:00","valor":4.2}'

    def test_cp03_carga_del_contrato(self) -> None:
        """CP-03: la lectura publicada se interpreta con su marca y calidad."""
        lectura = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA)

        assert lectura.codigo_nodo == "N-07"
        assert lectura.codigo_estanque == "EST-03"
        assert lectura.parametro is Parametro.OD_MGL
        assert lectura.valor == 4.2
        assert lectura.calidad is Calidad.VALIDA
        assert lectura.medido_en == datetime.fromisoformat("2026-09-18T17:42:10-05:00")

    def test_acepta_bytes(self) -> None:
        lectura = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA.encode())

        assert lectura.valor == 4.2

    def test_cp05_ph_imposible_se_marca_descartada_al_parsear(self) -> None:
        carga = '{"codigo_nodo":"N-09","medido_en":"2026-09-18T17:42:10-05:00","valor":14.8}'

        lectura = parsear_carga("piscigranja/EST-03/ph", carga)

        assert lectura.calidad is Calidad.DESCARTADA
        # Se interpreta igualmente: la lectura viaja al nucleo y se almacena,
        # porque el registro de que el sensor fallo es informacion util.
        assert lectura.valor == 14.8

    def test_la_marca_temporal_es_la_del_nodo(self) -> None:
        """RNF-02: el instante lo fija el nodo, nunca el servidor."""
        lectura = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA)

        assert lectura.medido_en.utcoffset() is not None
        assert lectura.medido_en.isoformat() == "2026-09-18T17:42:10-05:00"

    @pytest.mark.parametrize(
        ("carga", "motivo"),
        [
            ("no es json", "JSON"),
            ("[1,2,3]", "objeto"),
            ('{"medido_en":"2026-09-18T17:42:10-05:00","valor":4.2}', "codigo"),
            ('{"codigo_nodo":"  ","medido_en":"2026-09-18T17:42:10-05:00","valor":4.2}', "codigo"),
            ('{"codigo_nodo":"N-07","valor":4.2}', "marca temporal"),
            ('{"codigo_nodo":"N-07","medido_en":"ayer","valor":4.2}', "ISO 8601"),
            (VALOR_TEXTO, "numerico"),
            (VALOR_BOOLEANO, "numerico"),
        ],
    )
    def test_cargas_invalidas(self, carga: str, motivo: str) -> None:
        with pytest.raises(CargaInvalida, match=motivo):
            parsear_carga("piscigranja/EST-03/od_mgl", carga)

    def test_marca_temporal_sin_desplazamiento_se_rechaza(self) -> None:
        """Sin huso, el instante es ambiguo.

        El gateway y el nucleo pueden estar configurados en husos distintos, y
        la lectura quedaria desplazada sin que nada falle de forma visible.
        """
        carga = '{"codigo_nodo":"N-07","medido_en":"2026-09-18T17:42:10","valor":4.2}'

        with pytest.raises(CargaInvalida, match="desplazamiento"):
            parsear_carga("piscigranja/EST-03/od_mgl", carga)

    def test_la_clave_natural_identifica_la_lectura(self) -> None:
        a = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA)
        b = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA)

        assert a.clave == b.clave

    def test_la_carga_util_al_nucleo_conserva_el_instante(self) -> None:
        lectura = parsear_carga("piscigranja/EST-03/od_mgl", self.CARGA_VALIDA)
        carga = lectura.a_carga_util()

        assert carga == {
            "codigo_nodo": "N-07",
            "medido_en": "2026-09-18T17:42:10-05:00",
            "valor": 4.2,
        }

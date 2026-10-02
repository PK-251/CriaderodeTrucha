'use client';

import { useEffect, useMemo, useRef, useState } from 'react';

import { horaLegible } from '@/lib/formato';
import type { PuntoDeSerie } from '@/lib/tipos';

/**
 * Tendencia de un parámetro en el tiempo.
 *
 * Una sola serie, de modo que no lleva leyenda: el título la nombra. La banda
 * del rango aceptable va detrás de la línea porque la pregunta del técnico no
 * es «qué valor tiene» sino «se está saliendo», y eso se responde comparando
 * la curva con la banda, no leyendo números.
 *
 * Se dibuja a mano en SVG en lugar de usar una librería: son unas doscientas
 * líneas frente a un paquete entero que el equipo de borde de la piscigranja
 * tendría que descargar y mantener.
 *
 * El SVG se dibuja al ancho real del contenedor y no escalado por viewBox: si
 * se escalara, el texto de 11 px de los ejes quedaría en 5 px en un celular.
 */

interface Props {
  titulo: string;
  unidad: string | null;
  puntos: PuntoDeSerie[];
  minAceptable: number | null;
  maxAceptable: number | null;
}

const ANCHO_INICIAL = 700;
const MARGEN = { arriba: 14, derecha: 64, abajo: 28, izquierda: 40 };

/** Marcas redondas (1, 2, 2.5 o 5 × 10ⁿ) que cubren el dominio. */
function marcasRedondas(minimo: number, maximo: number, cuantas = 4): number[] {
  const bruto = (maximo - minimo) / cuantas || 1;
  const magnitud = 10 ** Math.floor(Math.log10(bruto));
  const paso =
    [1, 2, 2.5, 5, 10].map((m) => m * magnitud).find((p) => p >= bruto) ?? 10 * magnitud;

  const marcas: number[] = [];
  for (let v = Math.ceil(minimo / paso) * paso; v <= maximo + paso * 1e-6; v += paso) {
    marcas.push(Number(v.toFixed(6)));
  }

  return marcas;
}

/** Marcas de tiempo en horas redondas (cada 1, 2, 3, 6 o 12 h). */
function marcasDeTiempo(desde: number, hasta: number, anchoUtil: number): number[] {
  const hora = 3_600_000;
  const maxMarcas = Math.max(2, Math.floor(anchoUtil / 90));
  const paso =
    [1, 2, 3, 6, 12, 24].map((h) => h * hora).find((p) => (hasta - desde) / p <= maxMarcas) ??
    24 * hora;

  const marcas: number[] = [];
  const inicio = new Date(desde);
  inicio.setMinutes(0, 0, 0);

  for (let t = inicio.getTime(); t <= hasta; t += hora) {
    if (t >= desde && new Date(t).getHours() % (paso / hora) === 0) {
      marcas.push(t);
    }
  }

  return marcas;
}

function horaCorta(instante: number): string {
  return new Intl.DateTimeFormat('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }).format(
    new Date(instante),
  );
}

export function GraficoTendencia({ titulo, unidad, puntos, minAceptable, maxAceptable }: Props) {
  const contenedor = useRef<HTMLDivElement>(null);
  const [ancho, setAncho] = useState(ANCHO_INICIAL);
  const [indiceActivo, setIndiceActivo] = useState<number | null>(null);

  useEffect(() => {
    const nodo = contenedor.current;

    if (nodo === null || typeof ResizeObserver === 'undefined') {
      return;
    }

    const observador = new ResizeObserver(([entrada]) => {
      if (entrada) {
        setAncho(Math.round(entrada.contentRect.width));
      }
    });

    observador.observe(nodo);

    return () => observador.disconnect();
  }, []);

  const serie = useMemo(
    () =>
      [...puntos]
        .map((p) => ({ ...p, instante: new Date(p.medido_en).getTime() }))
        .filter((p) => Number.isFinite(p.instante))
        .sort((a, b) => a.instante - b.instante),
    [puntos],
  );

  if (serie.length < 2) {
    return (
      <div className="grafico">
        <h3 className="grafico__titulo">{titulo}</h3>
        <p className="grafico__sub">Sin lecturas suficientes para dibujar la tendencia.</p>
      </div>
    );
  }

  const estrecho = ancho < 480;
  const alto = estrecho ? 200 : 240;
  const margen = { ...MARGEN, derecha: estrecho ? 52 : MARGEN.derecha };

  const valores = serie.map((p) => p.valor);
  const candidatos = [...valores, minAceptable, maxAceptable].filter(
    (v): v is number => v !== null,
  );

  // El dominio se abre hasta las marcas redondas más cercanas: así el eje dice
  // 4 · 6 · 8 · 10 · 12 y no 3.6 · 8.1 · 12.6.
  const crudoMin = Math.min(...candidatos);
  const crudoMax = Math.max(...candidatos);
  const tentativas = marcasRedondas(crudoMin, crudoMax, 4);
  const paso = tentativas.length > 1 ? tentativas[1]! - tentativas[0]! : 1;
  const minimo = Math.floor(crudoMin / paso) * paso;
  const maximo = Math.ceil(crudoMax / paso) * paso;
  const marcasY = marcasRedondas(minimo, maximo, 4);

  const primero = serie[0]!.instante;
  const ultimo = serie[serie.length - 1]!.instante;
  const duracion = ultimo - primero || 1;

  const anchoUtil = Math.max(10, ancho - margen.izquierda - margen.derecha);
  const altoUtil = alto - margen.arriba - margen.abajo;

  const x = (instante: number) => margen.izquierda + ((instante - primero) / duracion) * anchoUtil;
  const y = (valor: number) =>
    margen.arriba + altoUtil - ((valor - minimo) / (maximo - minimo || 1)) * altoUtil;

  const trazo = serie
    .map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.instante).toFixed(1)},${y(p.valor).toFixed(1)}`)
    .join(' ');

  const marcasX = marcasDeTiempo(primero, ultimo, anchoUtil);
  const final = serie[serie.length - 1]!;
  const activo = indiceActivo === null ? null : serie[indiceActivo];
  const decimales = paso < 1 ? 1 : 0;

  function indiceMasCercano(instante: number): number {
    let mejor = 0;
    for (let i = 1; i < serie.length; i += 1) {
      if (Math.abs(serie[i]!.instante - instante) < Math.abs(serie[mejor]!.instante - instante)) {
        mejor = i;
      }
    }

    return mejor;
  }

  function alMover(evento: React.PointerEvent<SVGSVGElement>) {
    const caja = evento.currentTarget.getBoundingClientRect();
    const posicion = evento.clientX - caja.left;

    if (posicion < margen.izquierda - 8 || posicion > ancho - margen.derecha + 8) {
      setIndiceActivo(null);

      return;
    }

    const proporcion = Math.min(1, Math.max(0, (posicion - margen.izquierda) / anchoUtil));
    setIndiceActivo(indiceMasCercano(primero + proporcion * duracion));
  }

  // El mismo detalle que da el puntero, con el teclado: ← → recorren las
  // lecturas, Inicio y Fin saltan a los extremos.
  function alTeclear(evento: React.KeyboardEvent<SVGSVGElement>) {
    const actual = indiceActivo ?? serie.length - 1;
    const destinos: Record<string, number> = {
      ArrowLeft: Math.max(0, actual - 1),
      ArrowRight: Math.min(serie.length - 1, actual + 1),
      Home: 0,
      End: serie.length - 1,
    };

    if (evento.key in destinos) {
      evento.preventDefault();
      setIndiceActivo(destinos[evento.key]!);
    } else if (evento.key === 'Escape') {
      setIndiceActivo(null);
    }
  }

  const conBanda = minAceptable !== null && maxAceptable !== null;
  const conUnidad = (v: number) => `${v.toFixed(2)}${unidad ? ` ${unidad}` : ''}`;

  // Tooltip a la derecha del cursor, o a la izquierda si no cabe.
  const xActivo = activo ? x(activo.instante) : 0;
  const tooltipALaIzquierda = xActivo > ancho - 170;

  return (
    <div className="grafico">
      <h3 className="grafico__titulo">{titulo}</h3>
      <p className="grafico__sub">
        {serie.length} lecturas · {horaLegible(serie[0]!.medido_en)} a{' '}
        {horaLegible(final.medido_en)}
        {conBanda ? (
          <>
            {' · '}
            <span className="grafico__clave-banda" aria-hidden="true" />
            rango aceptable {minAceptable}–{maxAceptable}
            {unidad ? ` ${unidad}` : ''}
          </>
        ) : null}
      </p>

      <div className="grafico__lienzo" ref={contenedor}>
        <svg
          width={ancho}
          height={alto}
          role="img"
          tabIndex={0}
          aria-label={`Tendencia de ${titulo}: ${serie.length} lecturas, la última ${conUnidad(final.valor)}. Use las flechas para recorrer las lecturas.`}
          onPointerMove={alMover}
          onPointerLeave={() => setIndiceActivo(null)}
          onKeyDown={alTeclear}
          onBlur={() => setIndiceActivo(null)}
        >
          {/* Banda del rango aceptable, detrás de todo: un lavado, no un bloque */}
          {conBanda ? (
            <rect
              x={margen.izquierda}
              y={y(maxAceptable)}
              width={anchoUtil}
              height={Math.max(0, y(minAceptable) - y(maxAceptable))}
              className="grafico__banda"
            />
          ) : null}

          {/* Rejilla recesiva: líneas finas y continuas, nunca punteadas */}
          {marcasY.map((valor) => (
            <g key={valor}>
              <line
                x1={margen.izquierda}
                x2={ancho - margen.derecha}
                y1={y(valor)}
                y2={y(valor)}
                className="grafico__rejilla"
              />
              <text x={margen.izquierda - 8} y={y(valor) + 4} textAnchor="end" className="grafico__eje">
                {valor.toFixed(decimales)}
              </text>
            </g>
          ))}

          {/* Bordes de la banda, rotulados directamente en el margen derecho */}
          {conBanda
            ? [
                { valor: maxAceptable, rotulo: 'máx' },
                { valor: minAceptable, rotulo: 'mín' },
              ].map(({ valor, rotulo }) => (
                <g key={rotulo}>
                  <line
                    x1={margen.izquierda}
                    x2={ancho - margen.derecha}
                    y1={y(valor)}
                    y2={y(valor)}
                    className="grafico__limite"
                  />
                  <text x={ancho - margen.derecha + 6} y={y(valor) + 4} className="grafico__eje">
                    {rotulo} {valor}
                  </text>
                </g>
              ))
            : null}

          {/* Eje inferior y marcas de hora */}
          <line
            x1={margen.izquierda}
            x2={ancho - margen.derecha}
            y1={alto - margen.abajo}
            y2={alto - margen.abajo}
            className="grafico__base"
          />
          {marcasX.map((t) => (
            <text key={t} x={x(t)} y={alto - 8} textAnchor="middle" className="grafico__eje">
              {horaCorta(t)}
            </text>
          ))}

          {/* La serie */}
          <path d={trazo} className="grafico__linea" />

          {/* Punto final con su valor: lo único que se rotula sobre la curva */}
          <circle cx={x(final.instante)} cy={y(final.valor)} r="4" className="grafico__punto" />
          {activo === null ? (
            <text
              x={x(final.instante) + 8}
              y={Math.min(alto - margen.abajo - 4, Math.max(margen.arriba + 10, y(final.valor) + 4))}
              className="grafico__rotulo-final"
            >
              {final.valor.toFixed(2)}
            </text>
          ) : null}

          {/* Cruz que sigue al cursor y marcador del punto */}
          {activo ? (
            <g>
              <line
                x1={xActivo}
                x2={xActivo}
                y1={margen.arriba}
                y2={alto - margen.abajo}
                className="grafico__cruz"
              />
              <circle cx={xActivo} cy={y(activo.valor)} r="5" className="grafico__punto" />
            </g>
          ) : null}
        </svg>

        {activo ? (
          <div
            className="grafico__tooltip"
            role="status"
            style={{
              left: tooltipALaIzquierda ? undefined : xActivo + 12,
              right: tooltipALaIzquierda ? ancho - xActivo + 12 : undefined,
              top: Math.max(0, Math.min(alto - 64, y(activo.valor) - 28)),
            }}
          >
            <span className="grafico__tooltip-valor">
              <span className="grafico__tooltip-clave" aria-hidden="true" />
              {conUnidad(activo.valor)}
            </span>
            <span className="grafico__tooltip-hora">{horaLegible(activo.medido_en)}</span>
          </div>
        ) : null}
      </div>
    </div>
  );
}

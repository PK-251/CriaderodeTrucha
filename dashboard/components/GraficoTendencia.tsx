'use client';

import { useMemo, useState } from 'react';

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
 * Se dibuja a mano en SVG en lugar de usar una librería: son ~120 líneas
 * frente a un paquete entero que el equipo de borde de la piscigranja tendría
 * que descargar y mantener.
 */

interface Props {
  titulo: string;
  unidad: string | null;
  puntos: PuntoDeSerie[];
  minAceptable: number | null;
  maxAceptable: number | null;
}

const ANCHO = 700;
const ALTO = 220;
const MARGEN = { arriba: 12, derecha: 14, abajo: 26, izquierda: 44 };

export function GraficoTendencia({ titulo, unidad, puntos, minAceptable, maxAceptable }: Props) {
  const [indiceActivo, setIndiceActivo] = useState<number | null>(null);

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

  const valores = serie.map((p) => p.valor);
  const candidatos = [...valores, minAceptable, maxAceptable].filter(
    (v): v is number => v !== null,
  );

  let minimo = Math.min(...candidatos);
  let maximo = Math.max(...candidatos);

  // Un 8 % de aire arriba y abajo evita que la curva toque los bordes y que
  // una serie plana quede reducida a una raya en el borde inferior.
  const rango = maximo - minimo || 1;
  minimo -= rango * 0.08;
  maximo += rango * 0.08;

  const primero = serie[0]!.instante;
  const ultimo = serie[serie.length - 1]!.instante;
  const duracion = ultimo - primero || 1;

  const anchoUtil = ANCHO - MARGEN.izquierda - MARGEN.derecha;
  const altoUtil = ALTO - MARGEN.arriba - MARGEN.abajo;

  const x = (instante: number) => MARGEN.izquierda + ((instante - primero) / duracion) * anchoUtil;
  const y = (valor: number) =>
    MARGEN.arriba + altoUtil - ((valor - minimo) / (maximo - minimo)) * altoUtil;

  const trazo = serie
    .map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.instante).toFixed(1)},${y(p.valor).toFixed(1)}`)
    .join(' ');

  const marcasY = [minimo, (minimo + maximo) / 2, maximo];
  const activo = indiceActivo === null ? null : serie[indiceActivo];

  function alMover(evento: React.MouseEvent<SVGSVGElement>) {
    const caja = evento.currentTarget.getBoundingClientRect();
    const proporcion = (evento.clientX - caja.left) / caja.width;
    const posicion = proporcion * ANCHO;

    if (posicion < MARGEN.izquierda || posicion > ANCHO - MARGEN.derecha) {
      setIndiceActivo(null);

      return;
    }

    const instante = primero + ((posicion - MARGEN.izquierda) / anchoUtil) * duracion;

    let mejor = 0;
    for (let i = 1; i < serie.length; i += 1) {
      if (Math.abs(serie[i]!.instante - instante) < Math.abs(serie[mejor]!.instante - instante)) {
        mejor = i;
      }
    }

    setIndiceActivo(mejor);
  }

  return (
    <div className="grafico">
      <h3 className="grafico__titulo">{titulo}</h3>
      <p className="grafico__sub">
        {serie.length} lecturas · {horaLegible(serie[0]!.medido_en)} a{' '}
        {horaLegible(serie[serie.length - 1]!.medido_en)}
        {minAceptable !== null && maxAceptable !== null
          ? ` · banda sombreada: rango aceptable ${minAceptable}–${maxAceptable}`
          : ''}
      </p>

      <svg
        viewBox={`0 0 ${ANCHO} ${ALTO}`}
        role="img"
        aria-label={`Tendencia de ${titulo} con ${serie.length} lecturas`}
        onMouseMove={alMover}
        onMouseLeave={() => setIndiceActivo(null)}
      >
        {/* Banda del rango aceptable, detrás de todo */}
        {minAceptable !== null && maxAceptable !== null ? (
          <rect
            x={MARGEN.izquierda}
            y={y(maxAceptable)}
            width={anchoUtil}
            height={Math.max(0, y(minAceptable) - y(maxAceptable))}
            fill="var(--normal)"
            opacity="0.08"
          />
        ) : null}

        {/* Rejilla recesiva */}
        {marcasY.map((valor) => (
          <g key={valor}>
            <line
              x1={MARGEN.izquierda}
              x2={ANCHO - MARGEN.derecha}
              y1={y(valor)}
              y2={y(valor)}
              stroke="var(--linea)"
              strokeWidth="1"
            />
            <text
              x={MARGEN.izquierda - 8}
              y={y(valor) + 4}
              textAnchor="end"
              fontSize="11"
              fill="var(--tinta-apagada)"
              style={{ fontVariantNumeric: 'tabular-nums' }}
            >
              {valor.toFixed(1)}
            </text>
          </g>
        ))}

        {/* Límites del rango aceptable, punteados */}
        {[minAceptable, maxAceptable].map((limite, i) =>
          limite === null ? null : (
            <line
              key={i}
              x1={MARGEN.izquierda}
              x2={ANCHO - MARGEN.derecha}
              y1={y(limite)}
              y2={y(limite)}
              stroke="var(--normal)"
              strokeWidth="1.5"
              strokeDasharray="4 4"
              opacity="0.6"
            />
          ),
        )}

        {/* Eje inferior */}
        <line
          x1={MARGEN.izquierda}
          x2={ANCHO - MARGEN.derecha}
          y1={ALTO - MARGEN.abajo}
          y2={ALTO - MARGEN.abajo}
          stroke="var(--tinta-apagada)"
          strokeWidth="1"
          opacity="0.5"
        />

        <text
          x={MARGEN.izquierda}
          y={ALTO - 8}
          fontSize="11"
          fill="var(--tinta-apagada)"
        >
          {horaLegible(serie[0]!.medido_en)}
        </text>
        <text
          x={ANCHO - MARGEN.derecha}
          y={ALTO - 8}
          textAnchor="end"
          fontSize="11"
          fill="var(--tinta-apagada)"
        >
          {horaLegible(serie[serie.length - 1]!.medido_en)}
        </text>

        {/* La serie */}
        <path
          d={trazo}
          fill="none"
          stroke="var(--serie)"
          strokeWidth="2"
          strokeLinejoin="round"
          strokeLinecap="round"
        />

        {/* Cruz y marcador del punto bajo el cursor */}
        {activo ? (
          <g>
            <line
              x1={x(activo.instante)}
              x2={x(activo.instante)}
              y1={MARGEN.arriba}
              y2={ALTO - MARGEN.abajo}
              stroke="var(--tinta-apagada)"
              strokeWidth="1"
              strokeDasharray="3 3"
            />
            <circle
              cx={x(activo.instante)}
              cy={y(activo.valor)}
              r="5"
              fill="var(--serie)"
              stroke="var(--superficie)"
              strokeWidth="2"
            />
            <text
              x={Math.min(x(activo.instante) + 8, ANCHO - MARGEN.derecha - 96)}
              y={Math.max(y(activo.valor) - 10, MARGEN.arriba + 10)}
              fontSize="12"
              fontWeight="600"
              fill="var(--tinta)"
              style={{ fontVariantNumeric: 'tabular-nums' }}
            >
              {activo.valor.toFixed(2)}
              {unidad ? ` ${unidad}` : ''} · {horaLegible(activo.medido_en)}
            </text>
          </g>
        ) : null}
      </svg>
    </div>
  );
}

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { GraficoTendencia } from '@/components/GraficoTendencia';
import { ResumenTablero } from '@/components/ResumenTablero';
import { TablaLecturas } from '@/components/TablaLecturas';
import type { PuntoDeSerie } from '@/lib/tipos';

function serie(valores: number[]): PuntoDeSerie[] {
  const base = new Date('2026-09-18T12:00:00-05:00').getTime();

  return valores.map((valor, i) => ({
    parametro: 'od_mgl',
    valor,
    medido_en: new Date(base + i * 300_000).toISOString(),
  }));
}

describe('HU-03 · Resumen del tablero', () => {
  it('muestra las cuatro cifras con su etiqueta', () => {
    render(<ResumenTablero estanques={4} alertas={2} criticos={1} incomunicados={0} />);

    expect(screen.getByText('Alertas abiertas').nextElementSibling).toHaveTextContent('2');
    expect(screen.getByText('Estanques en crítico').nextElementSibling).toHaveTextContent('1');
    expect(screen.getByText('Estanques monitoreados').nextElementSibling).toHaveTextContent('4');
  });

  it('una cifra en cero no lleva icono de estado', () => {
    const { container } = render(
      <ResumenTablero estanques={4} alertas={0} criticos={0} incomunicados={0} />,
    );

    expect(container.querySelectorAll('.icono-estado')).toHaveLength(0);
  });
});

describe('HU-03 · Tabla de lecturas', () => {
  it('señala con palabras las lecturas fuera del rango aceptable', () => {
    render(<TablaLecturas puntos={serie([7.2, 5.1])} unidad="mg/L" minAceptable={5.5} maxAceptable={12} />);

    expect(screen.getByText('↓ bajo el mínimo')).toBeInTheDocument();
    expect(screen.getByText('dentro')).toBeInTheDocument();
  });
});

describe('HU-03 · Gráfico de tendencia', () => {
  it('rotula el último valor y permite recorrer las lecturas con el teclado', () => {
    render(
      <GraficoTendencia
        titulo="Oxígeno disuelto en EST-03"
        unidad="mg/L"
        puntos={serie([7.6, 7.1, 6.4, 4.2])}
        minAceptable={5.5}
        maxAceptable={12}
      />,
    );

    expect(screen.getByText('4.20')).toBeInTheDocument();

    const grafico = screen.getByRole('img');
    fireEvent.keyDown(grafico, { key: 'Home' });
    expect(screen.getByRole('status')).toHaveTextContent('7.60 mg/L');

    fireEvent.keyDown(grafico, { key: 'ArrowRight' });
    expect(screen.getByRole('status')).toHaveTextContent('7.10 mg/L');
  });

  it('el eje usa marcas redondas', () => {
    const { container } = render(
      <GraficoTendencia
        titulo="OD"
        unidad="mg/L"
        puntos={serie([7.6, 4.2])}
        minAceptable={5.5}
        maxAceptable={12}
      />,
    );

    const marcas = [...container.querySelectorAll('.grafico__eje')]
      .map((t) => t.textContent ?? '')
      .filter((t) => /^\d+(\.\d)?$/.test(t))
      .map(Number);

    expect(marcas.length).toBeGreaterThanOrEqual(3);
    marcas.forEach((m) => expect(m % 1 === 0 || m % 0.5 === 0).toBe(true));
  });
});

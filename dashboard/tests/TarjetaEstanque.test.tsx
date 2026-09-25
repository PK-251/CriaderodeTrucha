import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TarjetaEstanque } from '@/components/TarjetaEstanque';
import type { EstadoEstanque, EstadoParametro } from '@/lib/tipos';

function parametro(cambios: Partial<EstadoParametro> = {}): EstadoParametro {
  return {
    parametro: 'od_mgl',
    etiqueta: 'Oxígeno disuelto',
    unidad: 'mg/L',
    ultimo_valor: 7.8,
    medido_en: '2026-09-18T17:42:10-05:00',
    antiguedad_segundos: 120,
    sin_comunicacion: false,
    min_aceptable: 5.5,
    max_aceptable: 12,
    severidad_alerta: null,
    ...cambios,
  };
}

function estanque(cambios: Partial<EstadoEstanque> = {}): EstadoEstanque {
  return {
    id: 3,
    codigo: 'EST-03',
    volumen_m3: 150,
    biomasa_kg: 1650,
    etapa: 'engorde',
    semaforo: 'normal',
    sin_comunicacion: false,
    parametros: [parametro()],
    ...cambios,
  };
}

describe('HU-03 · Tarjeta de estanque', () => {
  it('muestra el codigo, la etapa y el ultimo valor', () => {
    render(<TarjetaEstanque estanque={estanque()} />);

    expect(screen.getByText('EST-03')).toBeInTheDocument();
    expect(screen.getByText(/engorde/)).toBeInTheDocument();
    expect(screen.getByText('7.80 mg/L')).toBeInTheDocument();
  });

  it('el semaforo nunca se comunica solo con color', () => {
    // El operador puede ser daltonico y el tablero se mira a pleno sol: el
    // estado debe leerse aunque el color no llegue.
    const { rerender } = render(<TarjetaEstanque estanque={estanque({ semaforo: 'normal' })} />);
    expect(screen.getByText('Normal')).toBeInTheDocument();

    rerender(<TarjetaEstanque estanque={estanque({ semaforo: 'advertencia' })} />);
    expect(screen.getByText('Advertencia')).toBeInTheDocument();

    rerender(<TarjetaEstanque estanque={estanque({ semaforo: 'critico' })} />);
    expect(screen.getByText('Crítico')).toBeInTheDocument();
  });

  it('marca sin comunicacion cuando el estanque no reporta', () => {
    render(
      <TarjetaEstanque
        estanque={estanque({
          sin_comunicacion: true,
          parametros: [parametro({ ultimo_valor: null, antiguedad_segundos: null, sin_comunicacion: true })],
        })}
      />,
    );

    expect(screen.getByText('Sin comunicación')).toBeInTheDocument();
    expect(screen.getByText(/sin lecturas/)).toBeInTheDocument();
  });

  it('sin comunicacion desplaza al semaforo', () => {
    // Un estanque que no reporta podria estar en riesgo sin que el sistema lo
    // sepa: mostrarlo como «normal» seria una afirmacion que no podemos hacer.
    render(
      <TarjetaEstanque estanque={estanque({ semaforo: 'normal', sin_comunicacion: true })} />,
    );

    expect(screen.getByText('Sin comunicación')).toBeInTheDocument();
    expect(screen.queryByText('Normal')).not.toBeInTheDocument();
  });

  it('muestra el rango aceptable junto a la lectura', () => {
    render(<TarjetaEstanque estanque={estanque()} />);

    expect(screen.getByText(/rango 5\.5–12/)).toBeInTheDocument();
  });

  it('advierte cuando no hay umbral configurado', () => {
    render(
      <TarjetaEstanque
        estanque={estanque({
          parametros: [parametro({ min_aceptable: null, max_aceptable: null })],
        })}
      />,
    );

    expect(screen.getByText(/sin umbral configurado/)).toBeInTheDocument();
  });

  it('presenta la antiguedad en lenguaje del operador', () => {
    const caso = (segundos: number, esperado: RegExp) => {
      const { unmount } = render(
        <TarjetaEstanque
          estanque={estanque({ parametros: [parametro({ antiguedad_segundos: segundos })] })}
        />,
      );
      expect(screen.getByText(esperado)).toBeInTheDocument();
      unmount();
    };

    caso(30, /hace instantes/);
    caso(600, /hace 10 min/);
    caso(7200, /hace 2 h/);
    caso(172800, /hace 2 d/);
  });

  it('lista los tres parametros del PMV', () => {
    const tarjeta = render(
      <TarjetaEstanque
        estanque={estanque({
          parametros: [
            parametro({ parametro: 'od_mgl', etiqueta: 'Oxígeno disuelto' }),
            parametro({ parametro: 'temp_c', etiqueta: 'Temperatura', unidad: '°C', ultimo_valor: 12.4 }),
            parametro({ parametro: 'ph', etiqueta: 'pH', unidad: '', ultimo_valor: 7.4 }),
          ],
        })}
      />,
    ).container;

    const articulo = within(tarjeta);
    expect(articulo.getByText('Oxígeno disuelto')).toBeInTheDocument();
    expect(articulo.getByText('Temperatura')).toBeInTheDocument();
    expect(articulo.getByText('pH')).toBeInTheDocument();
  });
});

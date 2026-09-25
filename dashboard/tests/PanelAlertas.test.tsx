import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Alerta, Atencion, ResultadoAtencion } from '@/lib/tipos';

/**
 * El formulario llama a una acción de servidor. En la prueba se sustituye por
 * un doble, porque lo que se verifica es cómo reacciona la interfaz a cada
 * resultado del contrato — en particular al 409 de CP-10.
 */
const atender = vi.hoisted(() => vi.fn<(alertaId: number, datos: FormData) => Promise<ResultadoAtencion>>());

vi.mock('@/app/acciones', () => ({
  atenderAlerta: atender,
}));

const { PanelAlertas } = await import('@/components/PanelAlertas');

function alerta(cambios: Partial<Alerta> = {}): Alerta {
  return {
    id: 7,
    estanque_id: 3,
    estanque_codigo: 'EST-03',
    parametro: 'od_mgl',
    etiqueta: 'Oxígeno disuelto',
    unidad: 'mg/L',
    severidad: 'critica',
    estado: 'abierta',
    valor_detectado: 4.2,
    umbral_violado: 5.5,
    ultimo_valor: 4.2,
    generada_en: '2026-09-18T17:42:51-05:00',
    actualizada_en: '2026-09-18T17:42:51-05:00',
    ...cambios,
  };
}

function atencion(cambios: Partial<Atencion> = {}): Atencion {
  return {
    id: 1,
    alerta_id: 7,
    usuario_id: 1,
    accion: 'Aireacion manual activada',
    observacion: 'Se restablecio el aireador',
    registrada_en: '2026-09-18T18:10:00-05:00',
    ...cambios,
  };
}

beforeEach(() => {
  atender.mockReset();
});

describe('HU-04 · Panel de alertas', () => {
  it('dice explicitamente cuando no hay alertas abiertas', () => {
    render(<PanelAlertas alertas={[]} rol="operador" />);

    expect(screen.getByTestId('sin-alertas')).toBeInTheDocument();
  });

  it('muestra severidad con icono y palabra, no solo color', () => {
    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    expect(screen.getByText('Crítica')).toBeInTheDocument();
    expect(screen.getByText(/Detectado 4\.20 mg\/L/)).toBeInTheDocument();
    expect(screen.getByText(/umbral 5\.50 mg\/L/)).toBeInTheDocument();
  });

  it('muestra el ultimo valor cuando difiere del que origino la alerta', () => {
    // CP-08: la alerta sigue abierta y las lecturas posteriores la actualizan.
    render(<PanelAlertas alertas={[alerta({ ultimo_valor: 3.8 })]} rol="operador" />);

    expect(screen.getByText(/último 3\.80 mg\/L/)).toBeInTheDocument();
  });

  it('el veterinario no ve el formulario que el servidor le rechazaria', () => {
    render(<PanelAlertas alertas={[alerta()]} rol="veterinario" />);

    expect(screen.queryByRole('button', { name: /registrar atención/i })).not.toBeInTheDocument();
    expect(screen.getByText(/corresponde al operador o al técnico/i)).toBeInTheDocument();
  });

  it.each(['operador', 'tecnico'] as const)('el rol %s puede registrar la atencion', (rol) => {
    render(<PanelAlertas alertas={[alerta()]} rol={rol} />);

    expect(screen.getByRole('button', { name: /registrar atención/i })).toBeInTheDocument();
  });
});

describe('HU-05 · Registro de la intervencion', () => {
  it('registra la accion y muestra el resultado', async () => {
    const usuario = userEvent.setup();
    atender.mockResolvedValue({ estado: 'registrada', atencion: atencion() });

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.type(screen.getByLabelText(/acción tomada/i), 'Aireacion manual activada');
    await usuario.click(screen.getByRole('button', { name: /guardar y cerrar/i }));

    await waitFor(() => {
      expect(screen.getByText('Intervención registrada')).toBeInTheDocument();
    });

    expect(atender).toHaveBeenCalledTimes(1);
    expect(atender.mock.calls[0]?.[0]).toBe(7);
  });

  it('CP-10: ante una alerta ya atendida muestra el registro existente y no duplica', async () => {
    const usuario = userEvent.setup();
    atender.mockResolvedValue({
      estado: 'ya_atendida',
      mensaje: 'La alerta 7 ya fue atendida.',
      atencion: atencion({ accion: 'Recambio parcial de agua' }),
    });

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.type(screen.getByLabelText(/acción tomada/i), 'Intento duplicado');
    await usuario.click(screen.getByRole('button', { name: /guardar y cerrar/i }));

    await waitFor(() => {
      expect(screen.getByText('La alerta 7 ya fue atendida.')).toBeInTheDocument();
    });

    // Lo que el operador debe ver es lo que YA se hizo, no lo que intentó.
    expect(screen.getByText('Registro existente')).toBeInTheDocument();
    expect(screen.getByText(/Recambio parcial de agua/)).toBeInTheDocument();
    expect(screen.queryByText(/Intento duplicado/)).not.toBeInTheDocument();

    // Y el formulario desaparece: insistir no tiene sentido.
    expect(screen.queryByRole('button', { name: /guardar y cerrar/i })).not.toBeInTheDocument();
  });

  it('CP-10: un 409 sin registro adjunto sigue explicando lo ocurrido', async () => {
    const usuario = userEvent.setup();
    atender.mockResolvedValue({
      estado: 'ya_atendida',
      mensaje: 'La alerta 7 ya fue atendida.',
      atencion: null,
    });

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.type(screen.getByLabelText(/acción tomada/i), 'Intento');
    await usuario.click(screen.getByRole('button', { name: /guardar y cerrar/i }));

    await waitFor(() => {
      expect(screen.getByText('La alerta 7 ya fue atendida.')).toBeInTheDocument();
    });
  });

  it('muestra el error de validacion senalando el problema', async () => {
    const usuario = userEvent.setup();
    atender.mockResolvedValue({
      estado: 'invalida',
      campo: 'accion',
      mensaje: 'La acción tomada no puede estar vacía.',
    });

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.type(screen.getByLabelText(/acción tomada/i), 'x');
    await usuario.click(screen.getByRole('button', { name: /guardar y cerrar/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('La acción tomada no puede estar vacía.');
    });

    // El formulario sigue en pantalla: el operador puede corregir.
    expect(screen.getByRole('button', { name: /guardar y cerrar/i })).toBeInTheDocument();
  });

  it('un 403 se explica sin dejar al operador adivinando', async () => {
    const usuario = userEvent.setup();
    atender.mockResolvedValue({
      estado: 'no_autorizado',
      mensaje: 'Tu rol no puede registrar la atencion.',
    });

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.type(screen.getByLabelText(/acción tomada/i), 'Aireacion');
    await usuario.click(screen.getByRole('button', { name: /guardar y cerrar/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Tu rol no puede registrar la atencion.');
    });
  });

  it('se puede cancelar sin enviar nada', async () => {
    const usuario = userEvent.setup();

    render(<PanelAlertas alertas={[alerta()]} rol="operador" />);

    await usuario.click(screen.getByRole('button', { name: /registrar atención/i }));
    await usuario.click(screen.getByRole('button', { name: /cancelar/i }));

    expect(screen.getByRole('button', { name: /registrar atención/i })).toBeInTheDocument();
    expect(atender).not.toHaveBeenCalled();
  });
});

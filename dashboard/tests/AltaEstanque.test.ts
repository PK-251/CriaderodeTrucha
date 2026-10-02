import { beforeEach, describe, expect, it, vi } from 'vitest';

const crearEstanque = vi.fn();

vi.mock('next/cache', () => ({ revalidatePath: vi.fn() }));
vi.mock('next/headers', () => ({ cookies: () => ({ get: () => undefined, set: vi.fn(), delete: vi.fn() }) }));
vi.mock('next/navigation', () => ({ redirect: vi.fn() }));
vi.mock('@/lib/api', () => ({
  COOKIE_SESION: 'sippt_sesion',
  crearEstanque: (...args: unknown[]) => crearEstanque(...args),
  iniciarSesion: vi.fn(),
  registrarAtencion: vi.fn(),
}));

const { altaEstanque } = await import('@/app/acciones');

function formulario(campos: Record<string, string>): FormData {
  const datos = new FormData();
  Object.entries(campos).forEach(([clave, valor]) => datos.set(clave, valor));

  return datos;
}

const BASE = { codigo: 'est-05', volumen_m3: '150', biomasa_kg: '1650', etapa: 'engorde' };

describe('HU-01 · Alta de estanque desde el tablero', () => {
  beforeEach(() => {
    crearEstanque.mockReset();
    crearEstanque.mockResolvedValue({ estado: 'creado', codigo: 'EST-05' });
  });

  it('envía al contrato el estanque con los umbrales completos', async () => {
    const resultado = await altaEstanque(
      null,
      formulario({ ...BASE, od_mgl_min: '6', od_mgl_max: '12', od_mgl_margen: '0.5' }),
    );

    expect(resultado).toEqual({ estado: 'creado', codigo: 'EST-05' });
    expect(crearEstanque).toHaveBeenCalledWith({
      codigo: 'EST-05',
      volumen_m3: 150,
      biomasa_kg: 1650,
      etapa: 'engorde',
      umbrales: [{ parametro: 'od_mgl', min_aceptable: 6, max_aceptable: 12, severidad_critica: 0.5 }],
    });
  });

  it('un umbral a medio llenar se rechaza antes de llamar a la API', async () => {
    const resultado = await altaEstanque(null, formulario({ ...BASE, temp_c_min: '9' }));

    expect(resultado).toMatchObject({ estado: 'invalido', campo: 'temp_c_min' });
    expect(crearEstanque).not.toHaveBeenCalled();
  });

  it('el volumen debe ser positivo', async () => {
    const resultado = await altaEstanque(null, formulario({ ...BASE, volumen_m3: '0' }));

    expect(resultado).toMatchObject({ estado: 'invalido', campo: 'volumen_m3' });
  });

  it('devuelve el campo que señala la API (CP-01 y CP-02)', async () => {
    crearEstanque.mockResolvedValue({
      estado: 'invalido',
      campo: 'codigo',
      mensaje: 'Ya existe un estanque con ese codigo.',
    });

    const resultado = await altaEstanque(null, formulario(BASE));

    expect(resultado).toMatchObject({ estado: 'invalido', campo: 'codigo' });
  });
});

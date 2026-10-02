'use server';

import { revalidatePath } from 'next/cache';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';

import { COOKIE_SESION, crearEstanque, iniciarSesion, registrarAtencion } from '@/lib/api';
import type {
  Etapa,
  Parametro,
  ResultadoAlta,
  ResultadoAtencion,
  UmbralEntrante,
} from '@/lib/tipos';

/**
 * Acciones de servidor del tablero.
 *
 * Se ejecutan en el servidor aunque las dispare un formulario del navegador,
 * de modo que el token nunca sale de la cookie httpOnly.
 */

export async function atenderAlerta(
  alertaId: number,
  datos: FormData,
): Promise<ResultadoAtencion> {
  const accion = String(datos.get('accion') ?? '').trim();
  const observacionCruda = String(datos.get('observacion') ?? '').trim();
  const observacion = observacionCruda === '' ? null : observacionCruda;

  const resultado = await registrarAtencion(alertaId, accion, observacion);

  // Tanto el registro como el conflicto cambian lo que el tablero debe
  // mostrar: en ambos casos la alerta deja de estar abierta.
  if (resultado.estado === 'registrada' || resultado.estado === 'ya_atendida') {
    revalidatePath('/');
  }

  return resultado;
}

export async function acceder(
  _estadoPrevio: { mensaje: string } | null,
  datos: FormData,
): Promise<{ mensaje: string } | null> {
  const email = String(datos.get('email') ?? '').trim();
  const password = String(datos.get('password') ?? '');

  if (email === '' || password === '') {
    return { mensaje: 'Ingresa tu correo y tu contraseña.' };
  }

  const resultado = await iniciarSesion(email, password);

  if (!resultado.ok) {
    return { mensaje: resultado.mensaje };
  }

  cookies().set(COOKIE_SESION, resultado.token, {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    // El tablero se sirve por HTTP dentro de la red de la piscigranja. Al
    // publicarlo con TLS, esta bandera debe activarse.
    secure: process.env.NODE_ENV === 'production' && process.env.TLS_ACTIVO === '1',
    maxAge: 60 * 60 * 12,
  });

  redirect('/');
}

export async function salir(): Promise<void> {
  cookies().delete(COOKIE_SESION);
  redirect('/acceso');
}

const ETAPAS: Etapa[] = ['alevino', 'juvenil', 'engorde', 'cosecha'];
const PARAMETROS: Parametro[] = ['od_mgl', 'temp_c', 'ph'];

/**
 * Alta de estanque desde el formulario del técnico (HU-01).
 *
 * Un parámetro con sus tres casillas vacías se omite: el técnico puede
 * configurar sus umbrales después. Uno a medio llenar sí es un error, porque
 * un umbral sin máximo dejaría al estanque sin vigilancia por arriba.
 */
export async function altaEstanque(
  _estadoPrevio: ResultadoAlta | null,
  datos: FormData,
): Promise<ResultadoAlta> {
  const texto = (campo: string) => String(datos.get(campo) ?? '').trim();
  const numero = (campo: string) => (texto(campo) === '' ? NaN : Number(texto(campo)));

  const codigo = texto('codigo').toUpperCase();
  if (codigo === '') {
    return { estado: 'invalido', campo: 'codigo', mensaje: 'Indica el código del estanque.' };
  }

  const volumen = numero('volumen_m3');
  if (!(volumen > 0)) {
    return { estado: 'invalido', campo: 'volumen_m3', mensaje: 'El volumen debe ser mayor que cero.' };
  }

  const biomasa = numero('biomasa_kg');
  if (!(biomasa >= 0)) {
    return { estado: 'invalido', campo: 'biomasa_kg', mensaje: 'La biomasa no puede ser negativa.' };
  }

  const etapa = texto('etapa') as Etapa;
  if (!ETAPAS.includes(etapa)) {
    return { estado: 'invalido', campo: 'etapa', mensaje: 'Elige la etapa productiva.' };
  }

  const umbrales: UmbralEntrante[] = [];

  for (const parametro of PARAMETROS) {
    const casillas = ['min', 'max', 'margen'].map((c) => texto(`${parametro}_${c}`));

    if (casillas.every((c) => c === '')) {
      continue;
    }

    const [min, max, margen] = casillas.map(Number) as [number, number, number];

    if (casillas.some((c) => c === '') || [min, max, margen].some(Number.isNaN)) {
      return {
        estado: 'invalido',
        campo: `${parametro}_min`,
        mensaje: 'Completa mínimo, máximo y margen crítico, o deja las tres casillas vacías.',
      };
    }

    umbrales.push({ parametro, min_aceptable: min, max_aceptable: max, severidad_critica: margen });
  }

  const resultado = await crearEstanque({
    codigo,
    volumen_m3: volumen,
    biomasa_kg: biomasa,
    etapa,
    umbrales,
  });

  if (resultado.estado === 'creado') {
    revalidatePath('/');
  }

  return resultado;
}

'use server';

import { revalidatePath } from 'next/cache';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';

import { COOKIE_SESION, iniciarSesion, registrarAtencion } from '@/lib/api';
import type { ResultadoAtencion } from '@/lib/tipos';

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

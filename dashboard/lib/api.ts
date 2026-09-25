import { cookies } from 'next/headers';

import type {
  Alerta,
  Atencion,
  EstadoEstanque,
  PuntoDeSerie,
  ResultadoAtencion,
  Usuario,
} from './tipos';

/**
 * Cliente del núcleo para el lado servidor.
 *
 * El token vive en una cookie httpOnly y nunca llega al navegador: el tablero
 * renderiza en el servidor y es él quien llama a la API. Guardarlo en
 * localStorage lo expondría a cualquier script de la página, y en un sistema
 * donde el token permite registrar lecturas y cerrar alertas eso no es una
 * molestia teórica.
 */

export const COOKIE_SESION = 'sippt_sesion';

/** Dentro de la red de contenedores; el navegador nunca usa esta URL. */
const URL_INTERNA = process.env.API_URL_INTERNA ?? 'http://api-core:8000/api/v1';

function token(): string | null {
  return cookies().get(COOKIE_SESION)?.value ?? null;
}

async function pedir<T>(ruta: string, opciones: RequestInit = {}): Promise<T | null> {
  const bearer = token();

  if (bearer === null) {
    return null;
  }

  try {
    const respuesta = await fetch(`${URL_INTERNA}${ruta}`, {
      ...opciones,
      headers: {
        Authorization: `Bearer ${bearer}`,
        Accept: 'application/json',
        ...(opciones.headers ?? {}),
      },
      // El tablero muestra el estado actual de la piscigranja: una respuesta
      // cacheada mostraría una condición de riesgo ya superada, o peor,
      // ocultaría una nueva.
      cache: 'no-store',
    });

    if (!respuesta.ok) {
      return null;
    }

    const cuerpo = (await respuesta.json()) as { datos?: T };

    return cuerpo.datos ?? null;
  } catch {
    // El núcleo no responde. El tablero debe seguir cargando y decirlo, no
    // romperse: el operador necesita saber que no hay datos, no ver un error.
    return null;
  }
}

export async function usuarioActual(): Promise<Usuario | null> {
  return pedir<Usuario>('/auth/yo');
}

export async function estadoDeEstanques(): Promise<EstadoEstanque[] | null> {
  return pedir<EstadoEstanque[]>('/estanques');
}

export async function alertasAbiertas(): Promise<Alerta[] | null> {
  return pedir<Alerta[]>('/alertas?estado=abierta');
}

export async function serieDeEstanque(
  estanqueId: number,
  parametro: string,
  porPagina = 288,
): Promise<PuntoDeSerie[] | null> {
  return pedir<PuntoDeSerie[]>(
    `/estanques/${estanqueId}/lecturas?parametro=${parametro}&por_pagina=${porPagina}`,
  );
}

/**
 * Registra la atención de una alerta (HU-05).
 *
 * Devuelve un resultado con nombre en lugar de lanzar: el 409 no es un fallo
 * del tablero sino información que el operador debe ver (CP-10).
 */
export async function registrarAtencion(
  alertaId: number,
  accion: string,
  observacion: string | null,
): Promise<ResultadoAtencion> {
  const bearer = token();

  if (bearer === null) {
    return { estado: 'error', mensaje: 'La sesion expiro. Vuelve a iniciar sesion.' };
  }

  let respuesta: Response;

  try {
    respuesta = await fetch(`${URL_INTERNA}/alertas/${alertaId}/atencion`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${bearer}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ accion, observacion }),
      cache: 'no-store',
    });
  } catch {
    return { estado: 'error', mensaje: 'No se pudo contactar con el servidor.' };
  }

  const cuerpo = (await respuesta.json().catch(() => ({}))) as {
    datos?: Atencion;
    mensaje?: string;
    detalle?: { campo: string; mensaje: string }[];
  };

  if (respuesta.status === 201 && cuerpo.datos) {
    return { estado: 'registrada', atencion: cuerpo.datos };
  }

  if (respuesta.status === 409) {
    return {
      estado: 'ya_atendida',
      atencion: cuerpo.datos ?? null,
      mensaje: cuerpo.mensaje ?? 'La alerta ya fue atendida.',
    };
  }

  if (respuesta.status === 403) {
    return {
      estado: 'no_autorizado',
      mensaje: cuerpo.mensaje ?? 'Tu rol no puede registrar la atencion.',
    };
  }

  if (respuesta.status === 422) {
    const primero = cuerpo.detalle?.[0];

    return {
      estado: 'invalida',
      campo: primero?.campo ?? 'accion',
      mensaje: primero?.mensaje ?? 'Revisa los datos ingresados.',
    };
  }

  return { estado: 'error', mensaje: `Respuesta inesperada del servidor (${respuesta.status}).` };
}

/** Emite el token y lo guarda en la cookie httpOnly. */
export async function iniciarSesion(
  email: string,
  password: string,
): Promise<{ ok: true; usuario: Usuario; token: string } | { ok: false; mensaje: string }> {
  try {
    const respuesta = await fetch(`${URL_INTERNA}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email, password }),
      cache: 'no-store',
    });

    const cuerpo = (await respuesta.json().catch(() => ({}))) as {
      datos?: { token: string; usuario: Usuario };
      mensaje?: string;
    };

    if (respuesta.status === 201 && cuerpo.datos) {
      return { ok: true, usuario: cuerpo.datos.usuario, token: cuerpo.datos.token };
    }

    return { ok: false, mensaje: cuerpo.mensaje ?? 'No se pudo iniciar sesion.' };
  } catch {
    return { ok: false, mensaje: 'No se pudo contactar con el servidor.' };
  }
}

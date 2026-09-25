import type { Semaforo, Severidad } from './tipos';

/**
 * Presentación: cómo se leen los datos en pantalla.
 *
 * Vive aparte de los componentes porque estas reglas son las que un operador
 * mirando el tablero bajo el sol, con las manos mojadas, tiene que entender de
 * un vistazo.
 */

/** Minutos sin reportar tras los que un estanque se marca sin comunicación. */
export const MINUTOS_SIN_COMUNICACION = Number(
  process.env.NEXT_PUBLIC_SIN_COMUNICACION_MIN ?? 15,
);

export function antiguedadLegible(segundos: number | null): string {
  if (segundos === null) {
    return 'sin lecturas';
  }

  if (segundos < 60) {
    return 'hace instantes';
  }

  const minutos = Math.floor(segundos / 60);

  if (minutos < 60) {
    return `hace ${minutos} min`;
  }

  const horas = Math.floor(minutos / 60);

  if (horas < 24) {
    return `hace ${horas} h`;
  }

  const dias = Math.floor(horas / 24);

  return `hace ${dias} d`;
}

export function horaLegible(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const fecha = new Date(iso);

  if (Number.isNaN(fecha.getTime())) {
    return '—';
  }

  return new Intl.DateTimeFormat('es-PE', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(fecha);
}

export function valorLegible(valor: number | null, unidad: string | null): string {
  if (valor === null) {
    return '—';
  }

  const texto = valor.toFixed(2);

  return unidad ? `${texto} ${unidad}` : texto;
}

/**
 * El semáforo nunca se comunica solo con color.
 *
 * En el PMV eso no es una formalidad: el operador puede ser daltónico, y el
 * tablero se mira en un celular a pleno sol. El icono y la palabra acompañan
 * siempre al color.
 */
export const SEMAFORO: Record<Semaforo, { etiqueta: string; icono: string }> = {
  normal: { etiqueta: 'Normal', icono: '✓' },
  advertencia: { etiqueta: 'Advertencia', icono: '!' },
  critico: { etiqueta: 'Crítico', icono: '⚠' },
};

export const SEVERIDAD: Record<Severidad, { etiqueta: string; icono: string }> = {
  advertencia: { etiqueta: 'Advertencia', icono: '!' },
  critica: { etiqueta: 'Crítica', icono: '⚠' },
};

export function semaforoDeSeveridad(severidad: Severidad): Semaforo {
  return severidad === 'critica' ? 'critico' : 'advertencia';
}

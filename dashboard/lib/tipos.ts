/**
 * Tipos del contrato de la API (docs/openapi.yaml).
 *
 * Se declaran a mano y no se generan del esquema porque el PMV consume seis
 * endpoints: generarlos añadiría un paso de construcción para mantener menos
 * de cien líneas. Si el contrato crece, conviene invertir la decisión.
 */

export type Parametro = 'od_mgl' | 'temp_c' | 'ph';
export type Severidad = 'advertencia' | 'critica';
export type EstadoAlerta = 'abierta' | 'atendida';
export type Semaforo = 'normal' | 'advertencia' | 'critico';
export type Rol = 'operador' | 'tecnico' | 'veterinario';

export interface EstadoParametro {
  parametro: Parametro;
  etiqueta: string | null;
  unidad: string | null;
  ultimo_valor: number | null;
  medido_en: string | null;
  antiguedad_segundos: number | null;
  sin_comunicacion: boolean;
  min_aceptable: number | null;
  max_aceptable: number | null;
  severidad_alerta: Severidad | null;
}

export interface EstadoEstanque {
  id: number;
  codigo: string;
  volumen_m3: number;
  biomasa_kg: number;
  etapa: string;
  semaforo: Semaforo;
  sin_comunicacion: boolean;
  parametros: EstadoParametro[];
}

export interface Alerta {
  id: number;
  estanque_id: number;
  estanque_codigo: string;
  parametro: Parametro;
  etiqueta: string | null;
  unidad: string | null;
  severidad: Severidad;
  estado: EstadoAlerta;
  valor_detectado: number;
  umbral_violado: number;
  ultimo_valor: number;
  generada_en: string;
  actualizada_en: string;
}

export interface Atencion {
  id: number;
  alerta_id: number;
  usuario_id: number;
  accion: string;
  observacion: string | null;
  registrada_en: string;
}

export interface PuntoDeSerie {
  parametro: Parametro;
  valor: number;
  medido_en: string;
}

export interface Usuario {
  id: number;
  nombre: string;
  email: string;
  rol: Rol;
}

/**
 * Resultado de registrar una atención (HU-05).
 *
 * El 409 no se modela como error sino como un resultado con nombre: el
 * contrato devuelve la atención existente junto al conflicto, y el tablero
 * debe mostrarla en lugar de duplicar el registro (CP-10).
 */
export type ResultadoAtencion =
  | { estado: 'registrada'; atencion: Atencion }
  | { estado: 'ya_atendida'; atencion: Atencion | null; mensaje: string }
  | { estado: 'no_autorizado'; mensaje: string }
  | { estado: 'invalida'; campo: string; mensaje: string }
  | { estado: 'error'; mensaje: string };

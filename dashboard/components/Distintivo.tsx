/**
 * Distintivo de estado: forma + color + palabra.
 *
 * Cada estado tiene una FORMA propia, no solo un color: círculo para normal,
 * triángulo para advertencia, octógono —la señal de pare— para crítico y
 * círculo tachado para sin comunicación. Con daltonismo, o a pleno sol, el
 * ámbar y el rojo se confunden; un triángulo y un octógono no. La palabra va
 * siempre al lado, y el texto usa la tinta normal: el color de estado vive en
 * la marca, nunca en las letras.
 */

export type EstadoVisual = 'normal' | 'advertencia' | 'critico' | 'sin-datos';

const ETIQUETA: Record<EstadoVisual, string> = {
  normal: 'Normal',
  advertencia: 'Advertencia',
  critico: 'Crítico',
  'sin-datos': 'Sin comunicación',
};

export function IconoEstado({ estado, tamano = 14 }: { estado: EstadoVisual; tamano?: number }) {
  return (
    <svg
      className="icono-estado"
      data-estado={estado}
      width={tamano}
      height={tamano}
      viewBox="0 0 16 16"
      aria-hidden="true"
      focusable="false"
    >
      {estado === 'normal' ? (
        <>
          <circle cx="8" cy="8" r="7.5" className="icono-estado__forma" />
          <path
            d="M4.6 8.3 7 10.6l4.4-4.9"
            fill="none"
            className="icono-estado__glifo"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </>
      ) : null}

      {estado === 'advertencia' ? (
        <>
          <path d="M8 .9 15.4 14.6H.6Z" strokeLinejoin="round" className="icono-estado__forma" />
          <path d="M8 5.6v4.3" className="icono-estado__glifo" strokeWidth="1.8" strokeLinecap="round" />
          <circle cx="8" cy="12.2" r="1" className="icono-estado__punto" />
        </>
      ) : null}

      {estado === 'critico' ? (
        <>
          <path d="M5.1.5h5.8l4.6 4.6v5.8l-4.6 4.6H5.1L.5 10.9V5.1Z" className="icono-estado__forma" />
          <path d="M8 4.2v4.6" className="icono-estado__glifo" strokeWidth="1.8" strokeLinecap="round" />
          <circle cx="8" cy="11.4" r="1" className="icono-estado__punto" />
        </>
      ) : null}

      {estado === 'sin-datos' ? (
        <>
          <circle cx="8" cy="8" r="6.6" fill="none" className="icono-estado__trazo" strokeWidth="1.8" />
          <path d="M3.4 12.6 12.6 3.4" className="icono-estado__trazo" strokeWidth="1.8" strokeLinecap="round" />
        </>
      ) : null}
    </svg>
  );
}

interface Props {
  estado: EstadoVisual;
  /** Texto alternativo a la etiqueta por defecto (p. ej. «Crítica» en una alerta). */
  etiqueta?: string;
}

export function Distintivo({ estado, etiqueta }: Props) {
  return (
    <span className="distintivo" data-estado={estado}>
      <IconoEstado estado={estado} />
      {etiqueta ?? ETIQUETA[estado]}
    </span>
  );
}

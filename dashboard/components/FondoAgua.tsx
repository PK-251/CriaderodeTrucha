/**
 * Fondo animado con tema de agua: manchas de luz que derivan despacio, como
 * el reflejo del sol en un estanque, y —solo en el acceso— dos ondas en la
 * parte baja.
 *
 * Es decoración, así que obedece tres reglas:
 *  - Solo anima `transform`: corre en la GPU, fuera del hilo principal, y no
 *    recalcula la página ni compite con el refresco del tablero.
 *  - En el tablero es casi imperceptible (bajo contraste, ciclos de un
 *    minuto): el operador lee datos encima y nada de lo que lee debe moverse.
 *  - Con «reducir movimiento» el fondo queda quieto; el color se conserva.
 *
 * Toma el color de `--acento`, de modo que en el tablero sigue al rol y en el
 * acceso usa el azul petróleo de la marca.
 */

interface Props {
  variante: 'acceso' | 'tablero';
}

// Una ola que se repite exactamente a la mitad del ancho: al desplazarla un
// 50 % el corte no se ve.
const OLA =
  'M0 40 C 150 10 350 10 500 40 S 850 70 1000 40 S 1350 10 1500 40 S 1850 70 2000 40 V 120 H 0 Z';

export function FondoAgua({ variante }: Props) {
  return (
    <div className="fondo-agua" data-variante={variante} aria-hidden="true">
      <span className="fondo-agua__luz fondo-agua__luz--1" />
      <span className="fondo-agua__luz fondo-agua__luz--2" />
      <span className="fondo-agua__luz fondo-agua__luz--3" />

      {variante === 'acceso' ? (
        <div className="fondo-agua__olas">
          <svg className="fondo-agua__ola fondo-agua__ola--atras" viewBox="0 0 2000 120" preserveAspectRatio="none">
            <path d={OLA} />
          </svg>
          <svg className="fondo-agua__ola fondo-agua__ola--frente" viewBox="0 0 2000 120" preserveAspectRatio="none">
            <path d={OLA} />
          </svg>
        </div>
      ) : null}
    </div>
  );
}

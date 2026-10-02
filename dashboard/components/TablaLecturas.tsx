import { horaLegible } from '@/lib/formato';
import type { PuntoDeSerie } from '@/lib/tipos';

/**
 * Últimas lecturas en forma de tabla.
 *
 * No es redundante con el gráfico: es la vista de tabla que el gráfico
 * necesita para que la información no dependa de leer una curva. Quien no
 * distingue los colores, quien imprime el tablero o quien necesita el valor
 * exacto lo encuentra aquí.
 */

interface Props {
  puntos: PuntoDeSerie[];
  unidad: string | null;
  limite?: number;
  minAceptable?: number | null;
  maxAceptable?: number | null;
}

export function TablaLecturas({
  puntos,
  unidad,
  limite = 12,
  minAceptable = null,
  maxAceptable = null,
}: Props) {
  const conRango = minAceptable !== null && maxAceptable !== null;

  const recientes = [...puntos]
    .sort((a, b) => new Date(b.medido_en).getTime() - new Date(a.medido_en).getTime())
    .slice(0, limite);

  if (recientes.length === 0) {
    return <div className="vacio">Sin lecturas registradas.</div>;
  }

  return (
    <div className="tabla-envoltura">
      <table className="tabla">
        <caption className="solo-lectores">
          Últimas {recientes.length} lecturas con su marca temporal
        </caption>
        <thead>
          <tr>
            <th scope="col">Medición</th>
            <th scope="col" className="tabla__numero">
              Valor{unidad ? ` (${unidad})` : ''}
            </th>
            {conRango ? <th scope="col">Rango</th> : null}
          </tr>
        </thead>
        <tbody>
          {recientes.map((punto) => {
            const bajo = conRango && punto.valor < minAceptable;
            const sobre = conRango && punto.valor > maxAceptable;

            return (
              <tr key={punto.medido_en} data-fuera={bajo || sobre ? 'true' : undefined}>
                <td>{horaLegible(punto.medido_en)}</td>
                <td className="tabla__numero">{punto.valor.toFixed(2)}</td>
                {conRango ? (
                  <td className="tabla__estado">
                    {/* Sin icono de severidad: la tabla solo conoce el rango
                        aceptable, no si el desvío es advertencia o crítico. */}
                    {bajo ? '↓ bajo el mínimo' : sobre ? '↑ sobre el máximo' : 'dentro'}
                  </td>
                ) : null}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

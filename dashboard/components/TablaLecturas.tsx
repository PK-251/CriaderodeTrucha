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
}

export function TablaLecturas({ puntos, unidad, limite = 12 }: Props) {
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
            <th scope="col">Valor{unidad ? ` (${unidad})` : ''}</th>
          </tr>
        </thead>
        <tbody>
          {recientes.map((punto) => (
            <tr key={punto.medido_en}>
              <td>{horaLegible(punto.medido_en)}</td>
              <td>{punto.valor.toFixed(2)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

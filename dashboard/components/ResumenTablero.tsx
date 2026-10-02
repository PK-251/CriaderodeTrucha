import { IconoEstado, type EstadoVisual } from '@/components/Distintivo';

/**
 * Cuatro cifras que responden «¿tengo que moverme?» antes de leer nada más.
 *
 * Son números, no un gráfico: con cuatro valores sueltos, una barra solo
 * añadiría tinta. La cifra que exige actuar lleva su forma de estado al lado
 * de la etiqueta; cuando vale cero, la forma desaparece para no gritar en
 * vano.
 */

interface Props {
  estanques: number;
  alertas: number;
  criticos: number;
  incomunicados: number;
}

interface Cifra {
  etiqueta: string;
  valor: number;
  estado?: EstadoVisual;
}

export function ResumenTablero({ estanques, alertas, criticos, incomunicados }: Props) {
  const cifras: Cifra[] = [
    { etiqueta: 'Alertas abiertas', valor: alertas, estado: 'advertencia' },
    { etiqueta: 'Estanques en crítico', valor: criticos, estado: 'critico' },
    { etiqueta: 'Sin comunicación', valor: incomunicados, estado: 'sin-datos' },
    { etiqueta: 'Estanques monitoreados', valor: estanques },
  ];

  return (
    <dl className="resumen" data-testid="resumen">
      {cifras.map((cifra) => {
        const activa = cifra.estado !== undefined && cifra.valor > 0;

        return (
          <div
            className="resumen__cifra"
            data-estado={activa ? cifra.estado : undefined}
            key={cifra.etiqueta}
          >
            <dt className="resumen__etiqueta">
              {activa && cifra.estado ? <IconoEstado estado={cifra.estado} tamano={13} /> : null}
              {cifra.etiqueta}
            </dt>
            <dd className="resumen__valor">{cifra.valor}</dd>
          </div>
        );
      })}
    </dl>
  );
}

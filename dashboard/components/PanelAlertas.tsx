import { FormularioAtencion } from '@/components/FormularioAtencion';
import { SEVERIDAD, horaLegible, valorLegible } from '@/lib/formato';
import type { Alerta, Rol } from '@/lib/tipos';

/**
 * Alertas abiertas, ordenadas por fecha descendente (HU-04).
 *
 * El formulario de atención solo aparece para quien puede usarlo. El
 * veterinario diagnostica pero no ejecuta la intervención en el estanque, de
 * modo que mostrarle un botón que el servidor va a rechazar con un 403 sería
 * enseñarle una puerta cerrada.
 */

interface Props {
  alertas: Alerta[];
  rol: Rol;
}

export function PanelAlertas({ alertas, rol }: Props) {
  const puedeAtender = rol === 'operador' || rol === 'tecnico';

  if (alertas.length === 0) {
    return (
      <div className="vacio" data-testid="sin-alertas">
        Ninguna alerta abierta. Todos los parámetros están dentro de rango.
      </div>
    );
  }

  return (
    <div data-testid="panel-alertas">
      {alertas.map((alerta) => {
        const severidad = SEVERIDAD[alerta.severidad];

        return (
          <article
            className="alerta"
            data-severidad={alerta.severidad}
            data-testid={`alerta-${alerta.id}`}
            key={alerta.id}
          >
            <div className="alerta__cabecera">
              <h3 className="alerta__titulo">
                {alerta.estanque_codigo} · {alerta.etiqueta ?? alerta.parametro}
              </h3>

              <span
                className="distintivo"
                data-estado={alerta.severidad === 'critica' ? 'critico' : 'advertencia'}
              >
                <span className="distintivo__icono" aria-hidden="true">
                  {severidad.icono}
                </span>
                {severidad.etiqueta}
              </span>
            </div>

            <p className="alerta__detalle">
              Detectado {valorLegible(alerta.valor_detectado, alerta.unidad)} · umbral{' '}
              {valorLegible(alerta.umbral_violado, alerta.unidad)}
              {alerta.ultimo_valor !== alerta.valor_detectado ? (
                <>
                  {' '}
                  · último {valorLegible(alerta.ultimo_valor, alerta.unidad)}
                </>
              ) : null}
              <br />
              {horaLegible(alerta.generada_en)}
            </p>

            {puedeAtender ? (
              <FormularioAtencion alertaId={alerta.id} />
            ) : (
              <p className="aviso" data-tono="info">
                Tu rol puede consultar la alerta, pero el registro de la intervención
                corresponde al operador o al técnico.
              </p>
            )}
          </article>
        );
      })}
    </div>
  );
}

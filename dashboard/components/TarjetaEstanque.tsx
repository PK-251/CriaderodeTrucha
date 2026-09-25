import {
  SEMAFORO,
  antiguedadLegible,
  valorLegible,
} from '@/lib/formato';
import type { EstadoEstanque } from '@/lib/tipos';

/**
 * Tarjeta de un estanque en el tablero (HU-03).
 *
 * Responde a la pregunta que el operador se hace al mirar el celular: ¿tengo
 * que ir a algún estanque ahora mismo? Por eso el estado va arriba a la
 * derecha, donde cae el pulgar, y no al final de la tarjeta.
 */

interface Props {
  estanque: EstadoEstanque;
}

export function TarjetaEstanque({ estanque }: Props) {
  const incomunicado = estanque.sin_comunicacion;
  const estado = SEMAFORO[estanque.semaforo];

  return (
    <article
      className="tarjeta"
      data-semaforo={estanque.semaforo}
      data-sin-comunicacion={incomunicado ? 'true' : 'false'}
      data-testid={`estanque-${estanque.codigo}`}
    >
      <div className="tarjeta__cabecera">
        <h3 className="tarjeta__codigo">{estanque.codigo}</h3>

        {incomunicado ? (
          // Sin comunicación desplaza al semáforo: un estanque que no reporta
          // podría estar en riesgo sin que el sistema lo sepa, de modo que
          // mostrarlo como «normal» sería una afirmación que no podemos hacer.
          <span className="distintivo" data-estado="sin-datos">
            <span className="distintivo__icono" aria-hidden="true">
              ⃠
            </span>
            Sin comunicación
          </span>
        ) : (
          <span className="distintivo" data-estado={estanque.semaforo}>
            <span className="distintivo__icono" aria-hidden="true">
              {estado.icono}
            </span>
            {estado.etiqueta}
          </span>
        )}
      </div>

      <p className="tarjeta__meta">
        {estanque.etapa} · {estanque.volumen_m3.toFixed(0)} m³ ·{' '}
        {estanque.biomasa_kg.toFixed(0)} kg
      </p>

      <div className="tarjeta__parametros">
        {estanque.parametros.map((parametro) => {
          const fuera =
            parametro.ultimo_valor !== null &&
            ((parametro.min_aceptable !== null && parametro.ultimo_valor < parametro.min_aceptable) ||
              (parametro.max_aceptable !== null && parametro.ultimo_valor > parametro.max_aceptable));

          return (
            <div className="parametro" key={parametro.parametro}>
              <span className="parametro__nombre">
                {parametro.etiqueta ?? parametro.parametro}
              </span>

              <span
                className="parametro__valor"
                style={
                  fuera && parametro.severidad_alerta
                    ? {
                        color:
                          parametro.severidad_alerta === 'critica'
                            ? 'var(--critico)'
                            : 'var(--advertencia)',
                      }
                    : undefined
                }
              >
                {valorLegible(parametro.ultimo_valor, parametro.unidad)}
              </span>

              <span className="parametro__rango">
                {parametro.min_aceptable !== null && parametro.max_aceptable !== null
                  ? `rango ${parametro.min_aceptable}–${parametro.max_aceptable}`
                  : 'sin umbral configurado'}
                {' · '}
                {antiguedadLegible(parametro.antiguedad_segundos)}
              </span>
            </div>
          );
        })}
      </div>
    </article>
  );
}

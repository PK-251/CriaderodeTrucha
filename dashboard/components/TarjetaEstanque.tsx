import { Distintivo, type EstadoVisual } from '@/components/Distintivo';
import { antiguedadLegible, valorLegible } from '@/lib/formato';
import type { EstadoEstanque, EstadoParametro } from '@/lib/tipos';

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

type Posicion = 'bajo' | 'dentro' | 'sobre' | 'desconocida';

function posicionEnRango(p: EstadoParametro): Posicion {
  if (p.ultimo_valor === null || p.min_aceptable === null || p.max_aceptable === null) {
    return 'desconocida';
  }

  if (p.ultimo_valor < p.min_aceptable) {
    return 'bajo';
  }

  return p.ultimo_valor > p.max_aceptable ? 'sobre' : 'dentro';
}

function estadoDelParametro(p: EstadoParametro, posicion: Posicion): EstadoVisual {
  if (p.sin_comunicacion || p.ultimo_valor === null) {
    return 'sin-datos';
  }

  if (posicion === 'bajo' || posicion === 'sobre') {
    return p.severidad_alerta === 'critica' ? 'critico' : 'advertencia';
  }

  return 'normal';
}

/**
 * Dónde cae el último valor respecto al rango aceptable.
 *
 * La pregunta del operador no es «cuánto marca» sino «cuánto le falta para
 * salirse», y eso se ve antes en una regla que en un número. La banda verde es
 * el rango aceptable; el punto, el valor. El medidor es redundante con el
 * texto —que dice «bajo el mínimo» o «sobre el máximo»—, así que se oculta a
 * los lectores de pantalla.
 */
function MedidorRango({ parametro, estado }: { parametro: EstadoParametro; estado: EstadoVisual }) {
  const { ultimo_valor: valor, min_aceptable: min, max_aceptable: max } = parametro;

  if (valor === null || min === null || max === null || max <= min) {
    return null;
  }

  // El dominio deja un tercio del rango de aire a cada lado, y se estira si el
  // valor cae más lejos: el punto nunca queda pegado al borde.
  const aire = (max - min) * 0.35;
  const desde = Math.min(min - aire, valor);
  const hasta = Math.max(max + aire, valor);
  const pct = (v: number) => ((v - desde) / (hasta - desde)) * 100;

  return (
    <div className="medidor" aria-hidden="true">
      <span className="medidor__banda" style={{ left: `${pct(min)}%`, width: `${pct(max) - pct(min)}%` }} />
      <span className="medidor__punto" data-estado={estado} style={{ left: `${pct(valor)}%` }} />
    </div>
  );
}

export function TarjetaEstanque({ estanque }: Props) {
  const incomunicado = estanque.sin_comunicacion;

  return (
    <article
      className="tarjeta"
      data-semaforo={estanque.semaforo}
      data-sin-comunicacion={incomunicado ? 'true' : 'false'}
      data-testid={`estanque-${estanque.codigo}`}
    >
      <div className="tarjeta__cabecera">
        <h3 className="tarjeta__codigo">{estanque.codigo}</h3>

        {/* Sin comunicación desplaza al semáforo: un estanque que no reporta
            podría estar en riesgo sin que el sistema lo sepa, de modo que
            mostrarlo como «normal» sería una afirmación que no podemos hacer. */}
        <Distintivo estado={incomunicado ? 'sin-datos' : estanque.semaforo} />
      </div>

      <p className="tarjeta__meta">
        {estanque.etapa} · {estanque.volumen_m3.toFixed(0)} m³ ·{' '}
        {estanque.biomasa_kg.toFixed(0)} kg
      </p>

      <div className="tarjeta__parametros">
        {estanque.parametros.map((parametro) => {
          const posicion = posicionEnRango(parametro);
          const estado = estadoDelParametro(parametro, posicion);

          return (
            <div className="parametro" data-estado={estado} key={parametro.parametro}>
              <span className="parametro__nombre">
                {parametro.etiqueta ?? parametro.parametro}
              </span>

              <span className="parametro__valor">
                {valorLegible(parametro.ultimo_valor, parametro.unidad)}
              </span>

              <MedidorRango parametro={parametro} estado={estado} />

              <span className="parametro__rango">
                {posicion === 'bajo' || posicion === 'sobre' ? (
                  <strong className="parametro__fuera">
                    {posicion === 'bajo' ? '↓ bajo el mínimo' : '↑ sobre el máximo'}
                    {' · '}
                  </strong>
                ) : null}
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

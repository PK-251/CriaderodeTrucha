import { redirect } from 'next/navigation';

import { salir } from '@/app/acciones';
import { EscuchaAlertas } from '@/components/EscuchaAlertas';
import { GraficoTendencia } from '@/components/GraficoTendencia';
import { PanelAlertas } from '@/components/PanelAlertas';
import { TablaLecturas } from '@/components/TablaLecturas';
import { TarjetaEstanque } from '@/components/TarjetaEstanque';
import { alertasAbiertas, estadoDeEstanques, serieDeEstanque, usuarioActual } from '@/lib/api';

/**
 * Pantalla operativa del PMV (HU-03).
 *
 * Renderiza en el servidor y sin caché: el tablero muestra el estado actual de
 * la piscigranja, y una respuesta cacheada podría ocultar una condición de
 * riesgo nueva.
 *
 * El orden vertical responde a la urgencia, que en un celular es el orden de
 * lectura: primero las alertas abiertas —lo único que exige actuar ahora—,
 * después el estado de cada estanque, y al final la tendencia y el detalle.
 */

export const dynamic = 'force-dynamic';

export default async function Tablero() {
  const usuario = await usuarioActual();

  if (usuario === null) {
    redirect('/acceso');
  }

  const [estanques, alertas] = await Promise.all([estadoDeEstanques(), alertasAbiertas()]);

  if (estanques === null) {
    return (
      <div className="envoltura">
        <header className="cabecera">
          <h1 className="cabecera__titulo">SIPPT · Tablero</h1>
        </header>
        <p className="aviso" data-tono="error" role="alert">
          No se pudo obtener el estado de los estanques. El servidor no responde.
        </p>
      </div>
    );
  }

  const criticos = estanques.filter((e) => e.semaforo === 'critico').length;
  const incomunicados = estanques.filter((e) => e.sin_comunicacion).length;

  // La tendencia se muestra del estanque que más lo necesita: el primero en
  // estado crítico, o el primero de la lista si todo está en orden.
  const destacado = estanques.find((e) => e.semaforo === 'critico') ?? estanques[0];
  const parametroDestacado =
    destacado?.parametros.find((p) => p.severidad_alerta !== null) ?? destacado?.parametros[0];

  const serie =
    destacado && parametroDestacado
      ? ((await serieDeEstanque(destacado.id, parametroDestacado.parametro, 288)) ?? [])
      : [];

  return (
    <div className="envoltura">
      <header className="cabecera">
        <div className="cabecera__fila">
          <div>
            <h1 className="cabecera__titulo">SIPPT · Tablero de estanques</h1>
            <p className="cabecera__sub">
              {usuario.nombre} · {usuario.rol}
            </p>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <EscuchaAlertas />
            <form action={salir}>
              <button type="submit" className="boton boton--secundario" style={{ minHeight: 36 }}>
                Salir
              </button>
            </form>
          </div>
        </div>

        <p className="cabecera__sub" style={{ marginTop: 6 }}>
          {estanques.length} estanques · {alertas?.length ?? 0} alertas abiertas
          {criticos > 0 ? ` · ${criticos} en estado crítico` : ''}
          {incomunicados > 0 ? ` · ${incomunicados} sin comunicación` : ''}
        </p>
      </header>

      <section className="seccion">
        <h2 className="seccion__titulo">Alertas abiertas</h2>
        <PanelAlertas alertas={alertas ?? []} rol={usuario.rol} />
      </section>

      <section className="seccion">
        <h2 className="seccion__titulo">Estado de los estanques</h2>
        <div className="rejilla">
          {estanques.map((estanque) => (
            <TarjetaEstanque estanque={estanque} key={estanque.id} />
          ))}
        </div>
      </section>

      {destacado && parametroDestacado ? (
        <section className="seccion">
          <h2 className="seccion__titulo">
            Tendencia · {destacado.codigo}
          </h2>

          <GraficoTendencia
            titulo={`${parametroDestacado.etiqueta ?? parametroDestacado.parametro} en ${destacado.codigo}`}
            unidad={parametroDestacado.unidad}
            puntos={serie}
            minAceptable={parametroDestacado.min_aceptable}
            maxAceptable={parametroDestacado.max_aceptable}
          />

          <div style={{ marginTop: 12 }}>
            <TablaLecturas puntos={serie} unidad={parametroDestacado.unidad} />
          </div>
        </section>
      ) : null}
    </div>
  );
}

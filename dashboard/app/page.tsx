import { redirect } from 'next/navigation';

import { salir } from '@/app/acciones';
import { EscuchaAlertas } from '@/components/EscuchaAlertas';
import { FormularioEstanque } from '@/components/FormularioEstanque';
import { GraficoTendencia } from '@/components/GraficoTendencia';
import { PanelAlertas } from '@/components/PanelAlertas';
import { ResumenTablero } from '@/components/ResumenTablero';
import { TablaLecturas } from '@/components/TablaLecturas';
import { TarjetaEstanque } from '@/components/TarjetaEstanque';
import { alertasAbiertas, estadoDeEstanques, serieDeEstanque, usuarioActual } from '@/lib/api';
import { ROLES } from '@/lib/roles';

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

  const rol = ROLES[usuario.rol];

  const seccionAlertas = (
    <section className="seccion" aria-labelledby="titulo-alertas" key="alertas">
      <h2 className="seccion__titulo" id="titulo-alertas">
        Alertas abiertas
      </h2>
      <PanelAlertas alertas={alertas ?? []} rol={usuario.rol} />
    </section>
  );

  const seccionEstanques = (
    <section className="seccion" aria-labelledby="titulo-estanques" key="estanques">
      <h2 className="seccion__titulo" id="titulo-estanques">
        Estado de los estanques
      </h2>
      <div className="rejilla">
        {estanques.map((estanque) => (
          <TarjetaEstanque estanque={estanque} key={estanque.id} />
        ))}
      </div>
    </section>
  );

  const seccionTendencia =
    destacado && parametroDestacado ? (
      <section className="seccion" aria-labelledby="titulo-tendencia" key="tendencia">
        <h2 className="seccion__titulo" id="titulo-tendencia">
          Tendencia de {destacado.codigo}
        </h2>

        <GraficoTendencia
          titulo={`${parametroDestacado.etiqueta ?? parametroDestacado.parametro} en ${destacado.codigo}`}
          unidad={parametroDestacado.unidad}
          puntos={serie}
          minAceptable={parametroDestacado.min_aceptable}
          maxAceptable={parametroDestacado.max_aceptable}
        />

        <div className="seccion__bloque">
          <TablaLecturas
            puntos={serie}
            unidad={parametroDestacado.unidad}
            minAceptable={parametroDestacado.min_aceptable}
            maxAceptable={parametroDestacado.max_aceptable}
          />
        </div>
      </section>
    ) : null;

  const seccionGestion = (
    <section className="seccion" aria-labelledby="titulo-gestion" key="gestion">
      <h2 className="seccion__titulo" id="titulo-gestion">
        Registrar estanque
      </h2>
      <FormularioEstanque />
    </section>
  );

  // El orden responde a la pregunta de cada rol. El operador necesita saber
  // si tiene que moverse; el técnico, cómo evoluciona la granja y configurarla;
  // el veterinario, el estado sanitario antes que la cola de alertas que no
  // le toca atender.
  const secciones = {
    operador: [seccionAlertas, seccionEstanques, seccionTendencia],
    tecnico: [seccionAlertas, seccionEstanques, seccionTendencia, seccionGestion],
    veterinario: [seccionEstanques, seccionTendencia, seccionAlertas],
  }[usuario.rol];

  return (
    <div className="envoltura" data-rol={usuario.rol}>
      <a className="saltar" href="#contenido">
        Saltar al contenido
      </a>

      <header className="cabecera">
        <div className="cabecera__fila">
          <div>
            <p className="cabecera__marca">SIPPT</p>
            <h1 className="cabecera__titulo">Tablero de estanques</h1>
          </div>

          <div className="cabecera__acciones">
            <form action={salir}>
              <button type="submit" className="boton boton--secundario boton--compacto">
                Salir
              </button>
            </form>
          </div>
        </div>

        <div className="perfil">
          <span className="perfil__rol">{rol.nombre}</span>
          <span className="perfil__detalle">
            {usuario.nombre} · {rol.vista}
          </span>
          <EscuchaAlertas />
        </div>
      </header>

      <main id="contenido">
        <p className="enfoque">{rol.enfoque}</p>

        <ResumenTablero
          estanques={estanques.length}
          alertas={alertas?.length ?? 0}
          criticos={criticos}
          incomunicados={incomunicados}
        />

        {secciones}
      </main>
    </div>
  );
}

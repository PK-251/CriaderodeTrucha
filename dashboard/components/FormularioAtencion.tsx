'use client';

import { useState, useTransition } from 'react';

import { atenderAlerta } from '@/app/acciones';
import { horaLegible } from '@/lib/formato';
import type { Atencion, ResultadoAtencion } from '@/lib/tipos';

/**
 * Registro de la intervención frente a una alerta (HU-05).
 *
 * El caso interesante no es el feliz sino CP-10: si la alerta ya fue atendida
 * —porque otro operador llegó primero— el servidor responde 409 con el
 * registro existente. El formulario lo muestra en lugar de insistir, porque
 * duplicar la intervención es justo lo que la restricción de la base impide.
 */

interface Props {
  alertaId: number;
}

export function FormularioAtencion({ alertaId }: Props) {
  const [abierto, setAbierto] = useState(false);
  const [resultado, setResultado] = useState<ResultadoAtencion | null>(null);
  const [enviando, iniciarEnvio] = useTransition();

  if (resultado?.estado === 'registrada') {
    return <AtencionRegistrada atencion={resultado.atencion} titulo="Intervención registrada" />;
  }

  if (resultado?.estado === 'ya_atendida') {
    return (
      <div className="formulario">
        <p className="aviso" data-tono="info" role="status">
          {resultado.mensaje}
        </p>
        {resultado.atencion ? (
          <AtencionRegistrada atencion={resultado.atencion} titulo="Registro existente" />
        ) : null}
      </div>
    );
  }

  if (!abierto) {
    return (
      <button
        type="button"
        className="boton boton--secundario"
        onClick={() => setAbierto(true)}
      >
        Registrar atención
      </button>
    );
  }

  /**
   * Se envía con onSubmit y no con `action={fn}`.
   *
   * La forma `action` de Next da envío sin JavaScript, pero aquí no aporta
   * nada: el componente necesita JavaScript de todos modos para mostrar el
   * resultado —y en particular el registro existente del 409— sin recargar la
   * página. A cambio, onSubmit funciona igual en el navegador y en las
   * pruebas, en lugar de depender del runtime del framework.
   */
  function enviar(evento: React.FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    const datos = new FormData(evento.currentTarget);

    iniciarEnvio(async () => {
      setResultado(await atenderAlerta(alertaId, datos));
    });
  }

  const errorDeCampo = resultado?.estado === 'invalida' ? resultado : null;

  return (
    <form className="formulario" onSubmit={enviar}>
      <div className="campo">
        <label className="campo__etiqueta" htmlFor={`accion-${alertaId}`}>
          Acción tomada
        </label>
        <input
          id={`accion-${alertaId}`}
          name="accion"
          className="campo__entrada"
          maxLength={80}
          required
          autoComplete="off"
          placeholder="Aireación manual activada"
          aria-describedby={errorDeCampo ? `error-${alertaId}` : undefined}
        />
      </div>

      <div className="campo">
        <label className="campo__etiqueta" htmlFor={`observacion-${alertaId}`}>
          Observación <span style={{ fontWeight: 400 }}>(opcional)</span>
        </label>
        <textarea
          id={`observacion-${alertaId}`}
          name="observacion"
          className="campo__area"
          placeholder="Detalle de lo encontrado y lo hecho"
        />
      </div>

      {/* Llegados aquí, los casos «registrada» y «ya_atendida» ya retornaron
          arriba: lo único que queda es un fallo que el operador debe ver. */}
      {resultado ? (
        <p className="aviso" data-tono="error" role="alert" id={`error-${alertaId}`}>
          {resultado.mensaje}
        </p>
      ) : null}

      <div style={{ display: 'flex', gap: 8 }}>
        <button type="submit" className="boton" disabled={enviando}>
          {enviando ? 'Guardando…' : 'Guardar y cerrar alerta'}
        </button>
        <button
          type="button"
          className="boton boton--secundario"
          onClick={() => setAbierto(false)}
          disabled={enviando}
        >
          Cancelar
        </button>
      </div>
    </form>
  );
}

function AtencionRegistrada({ atencion, titulo }: { atencion: Atencion; titulo: string }) {
  return (
    <div className="aviso" data-tono="info" role="status">
      <strong>{titulo}</strong>
      <br />
      {atencion.accion}
      {atencion.observacion ? ` — ${atencion.observacion}` : ''}
      <br />
      <span style={{ color: 'var(--tinta-apagada)' }}>
        {horaLegible(atencion.registrada_en)}
      </span>
    </div>
  );
}

'use client';

import { useEffect, useRef } from 'react';
import { useFormState, useFormStatus } from 'react-dom';

import { altaEstanque } from '@/app/acciones';
import type { ResultadoAlta } from '@/lib/tipos';

/**
 * Alta de estanque con sus umbrales (HU-01). Solo la ve el técnico.
 *
 * El error se muestra junto al campo que lo causó, no en un aviso genérico:
 * si el rango está invertido (CP-01) el técnico debe ver la fila del umbral,
 * y si el código ya existe (CP-02), la casilla del código.
 */

const PARAMETROS = [
  { clave: 'od_mgl', nombre: 'Oxígeno disuelto', unidad: 'mg/L', ejemplo: ['6.0', '12.0', '0.5'] },
  { clave: 'temp_c', nombre: 'Temperatura', unidad: '°C', ejemplo: ['9.0', '16.0', '2.0'] },
  { clave: 'ph', nombre: 'pH', unidad: '', ejemplo: ['6.5', '8.5', '0.7'] },
] as const;

/** Campos del 422 que pertenecen al bloque de umbrales y no a una casilla propia. */
const CAMPOS_DE_UMBRAL = ['min_aceptable', 'max_aceptable', 'severidad_critica', 'umbrales', 'parametro'];

function BotonGuardar() {
  const { pending } = useFormStatus();

  return (
    <button type="submit" className="boton" disabled={pending}>
      {pending ? 'Registrando…' : 'Registrar estanque'}
    </button>
  );
}

function ErrorDeCampo({ id, mensaje }: { id: string; mensaje: string | null }) {
  if (mensaje === null) {
    return null;
  }

  return (
    <p className="campo__error" id={id} role="alert">
      {mensaje}
    </p>
  );
}

export function FormularioEstanque() {
  const [estado, enviar] = useFormState<ResultadoAlta | null, FormData>(altaEstanque, null);
  const formulario = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (estado?.estado === 'creado') {
      formulario.current?.reset();
    }
  }, [estado]);

  const errorEn = (...campos: string[]) =>
    estado?.estado === 'invalido' && campos.some((c) => estado.campo === c || estado.campo.startsWith(`${c}_`))
      ? estado.mensaje
      : null;

  const errorUmbral =
    estado?.estado === 'invalido' &&
    (CAMPOS_DE_UMBRAL.includes(estado.campo) || PARAMETROS.some((p) => estado.campo.startsWith(p.clave)))
      ? estado.mensaje
      : null;

  const aria = (id: string, mensaje: string | null) =>
    mensaje === null ? {} : { 'aria-invalid': true, 'aria-describedby': id };

  return (
    <form ref={formulario} action={enviar} className="alta" data-testid="formulario-estanque" noValidate>
      <div className="alta__datos">
        <div className="campo">
          <label className="campo__etiqueta" htmlFor="alta-codigo">
            Código
          </label>
          <input
            id="alta-codigo"
            name="codigo"
            className="campo__entrada"
            placeholder="EST-05"
            maxLength={20}
            autoCapitalize="characters"
            autoComplete="off"
            {...aria('error-codigo', errorEn('codigo'))}
          />
          <ErrorDeCampo id="error-codigo" mensaje={errorEn('codigo')} />
        </div>

        <div className="campo">
          <label className="campo__etiqueta" htmlFor="alta-etapa">
            Etapa
          </label>
          <select id="alta-etapa" name="etapa" className="campo__entrada" defaultValue="" {...aria('error-etapa', errorEn('etapa'))}>
            <option value="" disabled>
              Elegir…
            </option>
            <option value="alevino">Alevino</option>
            <option value="juvenil">Juvenil</option>
            <option value="engorde">Engorde</option>
            <option value="cosecha">Cosecha</option>
          </select>
          <ErrorDeCampo id="error-etapa" mensaje={errorEn('etapa')} />
        </div>

        <div className="campo">
          <label className="campo__etiqueta" htmlFor="alta-volumen">
            Volumen (m³)
          </label>
          <input
            id="alta-volumen"
            name="volumen_m3"
            className="campo__entrada"
            inputMode="decimal"
            placeholder="150"
            {...aria('error-volumen', errorEn('volumen_m3'))}
          />
          <ErrorDeCampo id="error-volumen" mensaje={errorEn('volumen_m3')} />
        </div>

        <div className="campo">
          <label className="campo__etiqueta" htmlFor="alta-biomasa">
            Biomasa (kg)
          </label>
          <input
            id="alta-biomasa"
            name="biomasa_kg"
            className="campo__entrada"
            inputMode="decimal"
            placeholder="1650"
            {...aria('error-biomasa', errorEn('biomasa_kg'))}
          />
          <ErrorDeCampo id="error-biomasa" mensaje={errorEn('biomasa_kg')} />
        </div>
      </div>

      <fieldset className="umbrales" {...aria('error-umbrales', errorUmbral)}>
        <legend className="umbrales__titulo">Umbrales aceptables</legend>
        <p className="umbrales__ayuda">
          El margen crítico se suma más allá del rango: con mínimo 6.0 y margen 0.5, la alerta es
          crítica bajo 5.5.
        </p>

        <div className="umbrales__tabla" role="group">
          <span className="umbrales__cabecera" aria-hidden="true" />
          <span className="umbrales__cabecera">Mínimo</span>
          <span className="umbrales__cabecera">Máximo</span>
          <span className="umbrales__cabecera">Margen</span>

          {PARAMETROS.map((p) => (
            <div className="umbrales__fila" key={p.clave}>
              <span className="umbrales__parametro">
                {p.nombre}
                {p.unidad ? <span className="umbrales__unidad"> {p.unidad}</span> : null}
              </span>
              {(['min', 'max', 'margen'] as const).map((casilla, i) => (
                <input
                  key={casilla}
                  name={`${p.clave}_${casilla}`}
                  className="campo__entrada umbrales__casilla"
                  inputMode="decimal"
                  placeholder={p.ejemplo[i]}
                  aria-label={`${p.nombre}: ${casilla === 'margen' ? 'margen crítico' : casilla === 'min' ? 'mínimo' : 'máximo'}`}
                />
              ))}
            </div>
          ))}
        </div>

        <ErrorDeCampo id="error-umbrales" mensaje={errorUmbral} />
      </fieldset>

      <div className="alta__pie">
        <BotonGuardar />

        {estado?.estado === 'creado' ? (
          <p className="aviso" data-tono="exito" role="status">
            {estado.codigo} registrado. Ya aparece en el tablero.
          </p>
        ) : null}

        {estado?.estado === 'error' || estado?.estado === 'no_autorizado' ? (
          <p className="aviso" data-tono="error" role="alert">
            {estado.mensaje}
          </p>
        ) : null}
      </div>
    </form>
  );
}

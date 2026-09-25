'use client';

import { useFormState, useFormStatus } from 'react-dom';

import { acceder } from '@/app/acciones';

/**
 * Acceso al tablero (RF-09).
 *
 * El token se emite en el servidor y se guarda en una cookie httpOnly: nunca
 * llega a JavaScript del navegador. En un sistema donde el token permite
 * registrar lecturas y cerrar alertas, guardarlo en localStorage lo dejaría al
 * alcance de cualquier script inyectado en la página.
 */

export default function Acceso() {
  const [estado, enviar] = useFormState(acceder, null);

  return (
    <main className="acceso">
      <div className="acceso__caja">
        <h1 className="cabecera__titulo" style={{ marginBottom: 4 }}>
          SIPPT
        </h1>
        <p className="cabecera__sub" style={{ marginBottom: 18 }}>
          Tablero de estanques · Primer Incremento
        </p>

        <form action={enviar} className="formulario" style={{ borderTop: 0, paddingTop: 0 }}>
          <div className="campo">
            <label className="campo__etiqueta" htmlFor="email">
              Correo
            </label>
            <input
              id="email"
              name="email"
              type="email"
              className="campo__entrada"
              autoComplete="username"
              required
              placeholder="operador@sippt.local"
            />
          </div>

          <div className="campo">
            <label className="campo__etiqueta" htmlFor="password">
              Contraseña
            </label>
            <input
              id="password"
              name="password"
              type="password"
              className="campo__entrada"
              autoComplete="current-password"
              required
            />
          </div>

          {estado?.mensaje ? (
            <p className="aviso" data-tono="error" role="alert">
              {estado.mensaje}
            </p>
          ) : null}

          <BotonAcceder />
        </form>
      </div>
    </main>
  );
}

function BotonAcceder() {
  const { pending } = useFormStatus();

  return (
    <button type="submit" className="boton" disabled={pending}>
      {pending ? 'Entrando…' : 'Entrar'}
    </button>
  );
}

/*
 * Servidor provisional del contenedor dashboard (Fase 0).
 *
 * Responde /health para el healthcheck de Docker Compose mientras el
 * andamiaje está en pie. En la Fase 5 se reemplaza por Next.js 14 con SSR
 * y este archivo se elimina.
 */
import { createServer } from 'node:http';

const PUERTO = Number(process.env.PORT ?? 3000);

createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  if (req.url === '/health') {
    res.statusCode = 200;
    res.end(
      JSON.stringify({
        servicio: 'dashboard',
        estado: 'ok',
        fase: 'andamiaje',
        version: 'v0.1.0-pmv',
      }),
    );
    return;
  }

  res.statusCode = 404;
  res.end(
    JSON.stringify({
      error: 'no_implementado',
      detalle: 'El tablero del PMV se implementa en la Fase 5.',
    }),
  );
}).listen(PUERTO, '0.0.0.0');

console.log(`[andamiaje] dashboard escuchando en :${PUERTO}`);
